/*
 * mpegts_hls_proxy — a passthrough MPEG-TS to HLS proxy.
 *
 * Mirrors the control flow of `ffmpeg -i <src> -c copy -f hls ...`:
 *
 *   1. Open <src> (file or http://) through a uniform read interface.
 *   2. Pull 188-byte TS packets, re-syncing on 0x47 if needed.
 *   3. Walk PAT/PMT to discover the video PID (the "reference stream"
 *      libavformat/hlsenc.c calls vs->reference_stream_index).
 *   4. Track PCR (or PTS-as-PCR fallback) for elapsed-time measurement.
 *   5. On each video PID packet that carries a random_access_indicator,
 *      cut a new HLS segment once the target duration has been reached.
 *   6. Maintain a sliding-window .m3u8 — last N segments are kept, older
 *      ones are unlink()ed.
 */

#include "hls.h"
#include "log.h"
#include "source.h"
#include "ts.h"

#include <errno.h>
#include <getopt.h>
#include <signal.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>

static volatile sig_atomic_t g_stop = 0;
static void on_signal(int sig) { (void)sig; g_stop = 1; }

static void usage(const char *argv0) {
    fprintf(stderr,
            "Usage: %s -i <input> -o <out_dir> [options]\n"
            "\n"
            "Options:\n"
            "  -i, --input PATH        .ts file or http:// URL (required)\n"
            "  -o, --out DIR           output directory (required)\n"
            "  -n, --playlist NAME     playlist filename     (default: stream.m3u8)\n"
            "  -p, --prefix PFX        segment name prefix   (default: seg)\n"
            "  -t, --hls-time SEC      target segment seconds (default: 6.0)\n"
            "  -w, --window N          keep last N segments, 0 = VOD (default: 5)\n"
            "  -d, --delete-old        delete evicted segment files\n"
            "  -v, --verbose           increase log level\n"
            "  -h, --help              this message\n",
            argv0);
}

/* Refill `buf` so it contains at least one full 188-byte TS packet aligned
 * on a sync byte. Returns the new buffered length, 0 on EOF, -1 on error. */
static ssize_t refill(source_t *src, uint8_t *buf, size_t cap,
                      size_t already, int *eof_flag) {
    while (already < TS_PACKET_SIZE) {
        ssize_t n = source_read(src, buf + already, cap - already);
        if (n < 0) return -1;
        if (n == 0) { *eof_flag = 1; break; }
        already += (size_t)n;
    }
    return (ssize_t)already;
}

/* Find next 0x47 sync in `buf[0..len)`. Returns -1 if none. */
static int find_sync(const uint8_t *buf, size_t len) {
    for (size_t i = 0; i < len; i++) if (buf[i] == TS_SYNC_BYTE) return (int)i;
    return -1;
}

int main(int argc, char **argv) {
    const char *input = NULL;
    const char *out_dir = NULL;
    const char *playlist = "stream.m3u8";
    const char *prefix = "seg";
    double hls_time = 6.0;
    int window = 5;
    int delete_old = 0;

    static struct option long_opts[] = {
        {"input",       required_argument, 0, 'i'},
        {"out",         required_argument, 0, 'o'},
        {"playlist",    required_argument, 0, 'n'},
        {"prefix",      required_argument, 0, 'p'},
        {"hls-time",    required_argument, 0, 't'},
        {"window",      required_argument, 0, 'w'},
        {"delete-old",  no_argument,       0, 'd'},
        {"verbose",     no_argument,       0, 'v'},
        {"help",        no_argument,       0, 'h'},
        {0, 0, 0, 0}
    };
    int c;
    while ((c = getopt_long(argc, argv, "i:o:n:p:t:w:dvh", long_opts, NULL)) != -1) {
        switch (c) {
            case 'i': input = optarg; break;
            case 'o': out_dir = optarg; break;
            case 'n': playlist = optarg; break;
            case 'p': prefix = optarg; break;
            case 't': hls_time = atof(optarg); break;
            case 'w': window = atoi(optarg); break;
            case 'd': delete_old = 1; break;
            case 'v': g_log_level = LOG_DEBUG; break;
            case 'h': usage(argv[0]); return 0;
            default:  usage(argv[0]); return 2;
        }
    }
    if (!input || !out_dir) { usage(argv[0]); return 2; }

    signal(SIGINT,  on_signal);
    signal(SIGTERM, on_signal);
    signal(SIGPIPE, SIG_IGN);

    source_t *src = source_open(input);
    if (!src) return 1;

    hls_config_t cfg = {
        .out_dir        = (char *)out_dir,
        .playlist_name  = (char *)playlist,
        .segment_prefix = (char *)prefix,
        .target_seconds = hls_time,
        .window_size    = window,
        .delete_old     = delete_old,
    };
    hls_writer_t *hw = hls_writer_create(&cfg);
    if (!hw) { source_close(src); return 1; }

    ts_program_t prog;
    ts_program_init(&prog);

    uint8_t  buf[TS_PACKET_SIZE * 8];
    size_t   buf_len = 0;
    int      eof = 0;
    uint64_t last_pcr = 0;
    int      have_any_pcr = 0;
    uint64_t pkt_counter = 0;

    LOGI("starting proxy: %s -> %s/%s (target %.1fs, window %d%s)",
         input, out_dir, playlist, hls_time, window,
         delete_old ? ", delete_old" : "");

    int rc = 0;
    while (!g_stop) {
        if (buf_len < TS_PACKET_SIZE) {
            ssize_t r = refill(src, buf, sizeof(buf), buf_len, &eof);
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
            /* Re-sync: scan forward for the next 0x47 that is followed by
             * another 0x47 exactly 188 bytes later. This is the same
             * heuristic mpegts.c uses in mpegts_resync(). */
            int found = -1;
            for (size_t i = 0; i + TS_PACKET_SIZE < buf_len; i++) {
                if (buf[i] == TS_SYNC_BYTE && buf[i + TS_PACKET_SIZE] == TS_SYNC_BYTE) {
                    found = (int)i;
                    break;
                }
            }
            if (found < 0) {
                /* Drop everything except the last 187 bytes and refill. */
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
            /* Treat as desync and fall through to the resync branch on the
             * next loop iteration. */
            memmove(buf, buf + 1, buf_len - 1);
            buf_len--;
            continue;
        }

        ts_program_feed(&prog, buf, &parsed);
        if (parsed.pcr_valid) {
            last_pcr = parsed.pcr_27mhz;
            have_any_pcr = 1;
        }

        /* HLS segmenting decision: cut on key frame at the video PID once
         * the target duration is exceeded. */
        if (prog.have_video && parsed.pid == prog.video_pid &&
            parsed.pusi && parsed.random_access_indicator && have_any_pcr) {
            if (hls_writer_maybe_rotate(hw, last_pcr) != 0) {
                rc = 1; break;
            }
        }

        if (hls_writer_push(hw, buf, have_any_pcr ? last_pcr : 0) != 0) {
            rc = 1; break;
        }
        pkt_counter++;

        /* Advance buffer. */
        memmove(buf, buf + TS_PACKET_SIZE, buf_len - TS_PACKET_SIZE);
        buf_len -= TS_PACKET_SIZE;

        if (eof && buf_len < TS_PACKET_SIZE) {
            if (buf_len > 0)
                LOGW("EOF with %zu trailing bytes (discarded)", buf_len);
            break;
        }
    }

    LOGI("processed %llu TS packets", (unsigned long long)pkt_counter);
    if (hls_writer_finish(hw) != 0) rc = 1;
    hls_writer_destroy(hw);
    source_close(src);
    LOGI("done (exit %d)", rc);
    return rc;
}
