/*
 * mpegts_hls_proxy — a passthrough MPEG-TS to HLS proxy.
 *
 * Mirrors the control flow of `ffmpeg -i <src> -c copy -f hls ...`:
 *
 *   1. Open <src> (file or http://) through a uniform read interface.
 *   2. Pull 188-byte TS packets, re-syncing on 0x47 if needed.
 *   3. Walk PAT/PMT to discover the video PID.
 *   4. Track PCR for elapsed-time measurement.
 *   5. On each video PID packet that carries random_access_indicator=1,
 *      cut a new HLS segment once the target duration has been reached.
 *   6. Maintain a sliding-window .m3u8 — last N segments are kept, older
 *      ones are unlink()ed.
 *
 * In addition to the FFmpeg-equivalent behaviour, this build supports:
 *   - Daemon mode (--daemon, --pid-file, --log-file).
 *   - A separate, shorter target for the FIRST segment (--initial-hls-time),
 *     so the playlist becomes playable as quickly as possible.
 *   - Wait-for-RAI startup (--wait-rai / --low-latency): drop packets until
 *     the first random_access_indicator on the video PID, so the very first
 *     segment is independently decodable and no client-side buffering of
 *     undecodable bytes happens.
 *   - Tuned socket buffers (SO_RCVBUF, TCP_NODELAY) and large file I/O
 *     buffers — see src/url.c and src/hls.c.
 *   - A startup timer that logs the wall-clock interval between
 *     `main()` entry and the moment the playlist first contains a
 *     playable segment.
 */

#include "hls.h"
#include "log.h"
#include "source.h"
#include "ts.h"

#include <errno.h>
#include <fcntl.h>
#include <getopt.h>
#include <signal.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/stat.h>
#include <sys/types.h>
#include <time.h>
#include <unistd.h>

static volatile sig_atomic_t g_stop = 0;
static void on_signal(int sig) { (void)sig; g_stop = 1; }

static void usage(const char *argv0) {
    fprintf(stderr,
            "Usage: %s -i <input> -o <out_dir> [options]\n"
            "\n"
            "Source:\n"
            "  -i, --input PATH        .ts file or http:// URL (required)\n"
            "  -o, --out DIR           output directory (required)\n"
            "\n"
            "Playlist / segmenting:\n"
            "  -n, --playlist NAME     playlist filename     (default: stream.m3u8)\n"
            "  -p, --prefix PFX        segment name prefix   (default: seg)\n"
            "  -t, --hls-time SEC      target segment seconds (default: 6.0)\n"
            "      --initial-hls-time SEC  first segment target, defaults to\n"
            "                              max(hls-time/3, 1.0). Smaller value =\n"
            "                              faster time-to-first-frame.\n"
            "  -w, --window N          keep last N segments, 0 = VOD (default: 5)\n"
            "  -d, --delete-old        delete evicted segment files\n"
            "\n"
            "Latency:\n"
            "  -L, --low-latency       shortcut: --wait-rai + small --initial-hls-time\n"
            "      --wait-rai          drop packets until the first video RAI so the\n"
            "                          first segment starts on a keyframe\n"
            "      --read-buf-size N   source read buffer in bytes (default 65536)\n"
            "      --file-buf-size N   segment file I/O buffer in bytes (default 262144)\n"
            "\n"
            "Daemon / lifecycle:\n"
            "  -D, --daemon            detach from terminal\n"
            "      --pid-file PATH     write PID after daemonising\n"
            "      --log-file PATH     redirect stderr to this file\n"
            "\n"
            "  -v, --verbose           increase log level\n"
            "  -h, --help              this message\n",
            argv0);
}

/* Refill so the buffer holds at least one full 188-byte TS packet. */
static ssize_t refill(source_t *src, uint8_t *buf, size_t cap,
                      size_t already, int *eof_flag) {
    while (already < TS_PACKET_SIZE) {
        size_t want = cap - already;
        ssize_t n = source_read(src, buf + already, want);
        if (n < 0) return -1;
        if (n == 0) { *eof_flag = 1; break; }
        already += (size_t)n;
    }
    return (ssize_t)already;
}

static double ts_diff(const struct timespec *a, const struct timespec *b) {
    return (double)(b->tv_sec - a->tv_sec) +
           (double)(b->tv_nsec - a->tv_nsec) / 1e9;
}

/* Double-fork daemonize, à la "Advanced Programming in the UNIX Environment". */
static int daemonize(const char *pid_file, const char *log_file) {
    pid_t pid = fork();
    if (pid < 0) {
        LOGE("fork: %s", strerror(errno));
        return -1;
    }
    if (pid > 0) _exit(0);

    if (setsid() < 0) {
        LOGE("setsid: %s", strerror(errno));
        return -1;
    }
    /* Ignore SIGHUP so the second fork's parent dying does not deliver it. */
    signal(SIGHUP, SIG_IGN);

    pid = fork();
    if (pid < 0) {
        LOGE("fork(2): %s", strerror(errno));
        return -1;
    }
    if (pid > 0) _exit(0);

    umask(022);
    (void)chdir("/");   /* non-fatal: out_dir is given as an absolute path */

    int devnull = open("/dev/null", O_RDWR);
    if (devnull >= 0) {
        dup2(devnull, STDIN_FILENO);
        dup2(devnull, STDOUT_FILENO);
        if (!log_file) dup2(devnull, STDERR_FILENO);
        if (devnull > STDERR_FILENO) close(devnull);
    }

    if (log_file) {
        int lf = open(log_file, O_WRONLY | O_CREAT | O_APPEND, 0644);
        if (lf < 0) {
            /* logs go to /dev/null at this point — write to a fallback. */
            fprintf(stderr, "open(%s): %s\n", log_file, strerror(errno));
        } else {
            dup2(lf, STDERR_FILENO);
            if (lf != STDERR_FILENO) close(lf);
        }
    }

    if (pid_file) {
        FILE *fp = fopen(pid_file, "w");
        if (!fp) {
            LOGE("fopen(%s): %s", pid_file, strerror(errno));
            return -1;
        }
        fprintf(fp, "%d\n", (int)getpid());
        fclose(fp);
    }
    return 0;
}

/* Long-only option indices. */
enum {
    OPT_INITIAL = 1000,
    OPT_WAIT_RAI,
    OPT_READ_BUF,
    OPT_FILE_BUF,
    OPT_PID_FILE,
    OPT_LOG_FILE,
};

int main(int argc, char **argv) {
    struct timespec t_start;
    clock_gettime(CLOCK_MONOTONIC, &t_start);

    const char *input = NULL;
    const char *out_dir = NULL;
    const char *playlist = "stream.m3u8";
    const char *prefix = "seg";
    const char *pid_file = NULL;
    const char *log_file = NULL;
    double hls_time = 6.0;
    double initial_hls_time = -1;     /* -1 => auto                       */
    int window = 5;
    int delete_old = 0;
    int do_daemon = 0;
    int wait_rai = 0;
    int low_latency = 0;
    int read_buf_size = 65536;
    int file_buf_size = 256 * 1024;

    static struct option long_opts[] = {
        {"input",            required_argument, 0, 'i'},
        {"out",              required_argument, 0, 'o'},
        {"playlist",         required_argument, 0, 'n'},
        {"prefix",           required_argument, 0, 'p'},
        {"hls-time",         required_argument, 0, 't'},
        {"initial-hls-time", required_argument, 0, OPT_INITIAL},
        {"window",           required_argument, 0, 'w'},
        {"delete-old",       no_argument,       0, 'd'},
        {"low-latency",      no_argument,       0, 'L'},
        {"wait-rai",         no_argument,       0, OPT_WAIT_RAI},
        {"read-buf-size",    required_argument, 0, OPT_READ_BUF},
        {"file-buf-size",    required_argument, 0, OPT_FILE_BUF},
        {"daemon",           no_argument,       0, 'D'},
        {"pid-file",         required_argument, 0, OPT_PID_FILE},
        {"log-file",         required_argument, 0, OPT_LOG_FILE},
        {"verbose",          no_argument,       0, 'v'},
        {"help",             no_argument,       0, 'h'},
        {0, 0, 0, 0}
    };
    int c;
    while ((c = getopt_long(argc, argv, "i:o:n:p:t:w:dLDvh", long_opts, NULL)) != -1) {
        switch (c) {
            case 'i': input = optarg; break;
            case 'o': out_dir = optarg; break;
            case 'n': playlist = optarg; break;
            case 'p': prefix = optarg; break;
            case 't': hls_time = atof(optarg); break;
            case OPT_INITIAL: initial_hls_time = atof(optarg); break;
            case 'w': window = atoi(optarg); break;
            case 'd': delete_old = 1; break;
            case 'L': low_latency = 1; wait_rai = 1; break;
            case OPT_WAIT_RAI: wait_rai = 1; break;
            case OPT_READ_BUF: read_buf_size = atoi(optarg); break;
            case OPT_FILE_BUF: file_buf_size = atoi(optarg); break;
            case 'D': do_daemon = 1; break;
            case OPT_PID_FILE: pid_file = optarg; break;
            case OPT_LOG_FILE: log_file = optarg; break;
            case 'v': g_log_level = LOG_DEBUG; break;
            case 'h': usage(argv[0]); return 0;
            default:  usage(argv[0]); return 2;
        }
    }
    if (!input || !out_dir) { usage(argv[0]); return 2; }
    if (read_buf_size < (int)TS_PACKET_SIZE * 2) read_buf_size = TS_PACKET_SIZE * 2;
    if (initial_hls_time < 0) {
        initial_hls_time = hls_time / 3.0;
        if (initial_hls_time < 1.0) initial_hls_time = 1.0;
        if (low_latency && initial_hls_time > 2.0) initial_hls_time = 2.0;
    }

    if (do_daemon) {
        if (out_dir[0] != '/') {
            fprintf(stderr,
                    "--daemon requires an absolute --out path (got '%s')\n"
                    "because the daemon chdir(\"/\")s to avoid pinning a "
                    "mount point.\n", out_dir);
            return 2;
        }
        if (pid_file && pid_file[0] != '/') {
            fprintf(stderr,
                    "--pid-file must be an absolute path under --daemon\n");
            return 2;
        }
        if (log_file && log_file[0] != '/') {
            fprintf(stderr,
                    "--log-file must be an absolute path under --daemon\n");
            return 2;
        }
        if (daemonize(pid_file, log_file) != 0) return 1;
        /* Re-anchor t_start to the post-daemon pid: the time-to-playable
         * measurement should describe the running daemon, not the launcher. */
        clock_gettime(CLOCK_MONOTONIC, &t_start);
    } else if (log_file) {
        int lf = open(log_file, O_WRONLY | O_CREAT | O_APPEND, 0644);
        if (lf < 0) {
            LOGE("open(%s): %s", log_file, strerror(errno));
        } else {
            dup2(lf, STDERR_FILENO);
            if (lf != STDERR_FILENO) close(lf);
        }
    }

    signal(SIGINT,  on_signal);
    signal(SIGTERM, on_signal);
    signal(SIGPIPE, SIG_IGN);

    LOGI("mpegts_hls_proxy starting (pid %d)", (int)getpid());
    LOGI("source: %s", input);
    LOGI("output: %s/%s (target %.2fs, first %.2fs, window %d%s%s)",
         out_dir, playlist, hls_time, initial_hls_time, window,
         delete_old ? ", delete_old" : "",
         wait_rai ? ", wait_rai" : "");

    source_t *src = source_open(input);
    if (!src) return 1;

    hls_config_t cfg = {
        .out_dir                = (char *)out_dir,
        .playlist_name          = (char *)playlist,
        .segment_prefix         = (char *)prefix,
        .target_seconds         = hls_time,
        .initial_target_seconds = initial_hls_time,
        .window_size            = window,
        .delete_old             = delete_old,
        .file_buffer_bytes      = file_buf_size,
    };
    hls_writer_t *hw = hls_writer_create(&cfg);
    if (!hw) { source_close(src); return 1; }

    ts_program_t prog;
    ts_program_init(&prog);

    uint8_t *buf = malloc((size_t)read_buf_size);
    if (!buf) { hls_writer_destroy(hw); source_close(src); return 1; }
    size_t   buf_len = 0;
    int      eof = 0;
    uint64_t last_pcr = 0;
    int      have_any_pcr = 0;
    int      saw_first_rai = !wait_rai; /* if not waiting, we accept from byte 0 */
    int      logged_playable = 0;
    uint64_t pkt_counter = 0;


    int rc = 0;
    while (!g_stop) {
        if (buf_len < TS_PACKET_SIZE) {
            ssize_t r = refill(src, buf, (size_t)read_buf_size, buf_len, &eof);
            if (r < 0) { rc = 1; LOGE("source_read failed"); break; }
            buf_len = (size_t)r;
            if (buf_len < TS_PACKET_SIZE) {
                if (eof) {
                    if (buf_len > 0)
                        LOGW("EOF with %zu trailing bytes (discarded)", buf_len);
                    break;
                }
                continue;
            }
        }

        if (buf[0] != TS_SYNC_BYTE) {
            /* Re-sync: scan forward for the next 0x47 confirmed by a sync
             * 188 bytes later. Same heuristic as mpegts.c's resync. */
            int found = -1;
            for (size_t i = 0; i + TS_PACKET_SIZE < buf_len; i++) {
                if (buf[i] == TS_SYNC_BYTE && buf[i + TS_PACKET_SIZE] == TS_SYNC_BYTE) {
                    found = (int)i;
                    break;
                }
            }
            if (found < 0) {
                size_t keep = buf_len > 187 ? 187 : buf_len;
                memmove(buf, buf + buf_len - keep, keep);
                buf_len = keep;
                continue;
            }
            if (found > 0) {
                memmove(buf, buf + found, buf_len - (size_t)found);
                buf_len -= (size_t)found;
                LOGW("re-sync: skipped %d bytes", found);
            }
            continue;
        }

        ts_packet_t parsed;
        ts_packet_parse(buf, &parsed);
        if (!parsed.sync_ok) {
            memmove(buf, buf + 1, buf_len - 1);
            buf_len--;
            continue;
        }

        ts_program_feed(&prog, buf, &parsed);
        if (parsed.pcr_valid) {
            last_pcr = parsed.pcr_27mhz;
            have_any_pcr = 1;
        }

        /* Update PSI cache so the writer can prepend the latest PAT/PMT
         * to every new segment file, matching mpegtsenc.c's behaviour. */
        if (parsed.pid == TS_PID_PAT) {
            hls_writer_cache_psi(hw, buf, NULL);
        } else if (prog.have_pmt_pid && parsed.pid == prog.pmt_pid) {
            hls_writer_cache_psi(hw, NULL, buf);
        }

        /* HLS segmenting decision. */
        int is_video_rai = prog.have_video && parsed.pid == prog.video_pid &&
                           parsed.pusi && parsed.random_access_indicator;

        if (!saw_first_rai) {
            /* Low-latency mode: discard packets until the first IDR/RAI on
             * the video PID so segment 0 is independently decodable. PSI
             * is already cached above so injection happens automatically. */
            if (is_video_rai && have_any_pcr) {
                saw_first_rai = 1;
                LOGI("first RAI seen, opening segment 0");
            } else {
                goto advance;
            }
        }

        if (is_video_rai && have_any_pcr) {
            if (hls_writer_maybe_rotate(hw, last_pcr) != 0) {
                rc = 1; break;
            }
        }

        if (hls_writer_push(hw, buf, have_any_pcr ? last_pcr : 0) != 0) {
            rc = 1; break;
        }
        pkt_counter++;

        if (!logged_playable) {
            struct timespec t_pub;
            if (hls_writer_playable(hw, &t_pub)) {
                logged_playable = 1;
                double elapsed = ts_diff(&t_start, &t_pub);
                LOGI("playlist ready in %.3f s — clients can start playback now",
                     elapsed);
            }
        }

    advance:
        memmove(buf, buf + TS_PACKET_SIZE, buf_len - TS_PACKET_SIZE);
        buf_len -= TS_PACKET_SIZE;

        if (eof && buf_len < TS_PACKET_SIZE) {
            if (buf_len > 0)
                LOGW("EOF with %zu trailing bytes (discarded)", buf_len);
            break;
        }
    }

    struct timespec t_end;
    clock_gettime(CLOCK_MONOTONIC, &t_end);
    LOGI("processed %llu TS packets in %.3f s",
         (unsigned long long)pkt_counter, ts_diff(&t_start, &t_end));

    if (hls_writer_finish(hw) != 0) rc = 1;

    if (!logged_playable) {
        struct timespec t_pub;
        if (hls_writer_playable(hw, &t_pub)) {
            double elapsed = ts_diff(&t_start, &t_pub);
            LOGI("playlist ready in %.3f s (at shutdown)", elapsed);
        } else {
            LOGW("no playable segment was ever produced");
        }
    }

    hls_writer_destroy(hw);
    source_close(src);
    free(buf);

    if (pid_file && do_daemon) unlink(pid_file);

    LOGI("done (exit %d)", rc);
    return rc;
}
