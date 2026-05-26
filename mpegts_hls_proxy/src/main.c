/*
 * mpegts_hls_proxy — a passthrough MPEG-TS → HLS proxy.
 *
 * Two run modes:
 *   1) Direct proxy: `-i <src> -o <dir>` runs a single proxy_run() and
 *      exits when the source EOFs (or on SIGTERM).
 *   2) HTTP front-end: `--listen-port PORT --upstream-host H --upstream-port P
 *      --out-root /var/www` listens for client HLS requests like
 *      `GET /<channel>/index.m3u8 ...`, extracts <channel>, spawns a
 *      per-channel worker thread running proxy_run() with
 *        input   = http://<upstream_host>:<upstream_port>/<channel>/playlist.m3u8
 *        out_dir = <out_root>/<channel>
 *      and replies with `HTTP/1.1 302 Location: <upstream URL>`. CLI flags
 *      that tune the proxy (--hls-time, --window, …) are passed through as
 *      defaults for every channel.
 *
 * The pipeline itself mirrors `ffmpeg -i <src> -c copy -f hls ...`; see
 * docs/ffmpeg_analysis.md for the analysis.
 */

#include "hls.h"
#include "log.h"
#include "proxy.h"
#include "server.h"
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
            "       %s --listen-port P --upstream-host H --upstream-port P2 \\\n"
            "             --out-root DIR [proxy options]\n"
            "\n"
            "Source (single-channel mode):\n"
            "  -i, --input PATH        .ts file or http:// URL\n"
            "  -o, --out DIR           output directory\n"
            "\n"
            "HTTP front-end (multi-channel mode):\n"
            "      --listen-host HOST  bind address (default 0.0.0.0)\n"
            "      --listen-port P     bind port (e.g. 8222)\n"
            "      --upstream-host H   upstream IP / hostname (e.g. 83.136.233.101)\n"
            "      --upstream-port P2  upstream port (e.g. 8123)\n"
            "      --upstream-path FMT printf format with one %%s for channel name,\n"
            "                          default: /%%s/playlist.m3u8\n"
            "      --out-root DIR      per-channel out_dirs go here (e.g. /var/www)\n"
            "      --serve-local       respond with the local playlist URL instead of\n"
            "                          redirecting upstream\n"
            "      --local-url-fmt FMT printf format with one %%s for channel; used\n"
            "                          together with --serve-local\n"
            "\n"
            "Playlist / segmenting:\n"
            "  -n, --playlist NAME     playlist filename (default stream.m3u8)\n"
            "  -p, --prefix PFX        segment name prefix (default seg)\n"
            "  -t, --hls-time SEC      target segment seconds (default 6.0)\n"
            "      --initial-hls-time SEC  first segment target\n"
            "                              (default max(hls-time/3, 1.0))\n"
            "  -w, --window N          sliding window size, 0 = VOD (default 5)\n"
            "  -d, --delete-old        delete evicted segment files\n"
            "\n"
            "Latency / I/O:\n"
            "  -L, --low-latency       enable --wait-rai and shorter first segment\n"
            "      --wait-rai          drop packets until first video RAI\n"
            "      --read-buf-size N   source read buffer in bytes (default 65536)\n"
            "      --file-buf-size N   segment file I/O buffer in bytes (default 262144)\n"
            "\n"
            "Daemon / lifecycle:\n"
            "  -D, --daemon            detach from terminal\n"
            "      --pid-file PATH     write PID after daemonising\n"
            "      --log-file PATH     redirect stderr to this file\n"
            "\n"
            "  -v, --verbose           DEBUG log level\n"
            "  -h, --help              this message\n",
            argv0, argv0);
}

/* Double-fork daemonize. */
static int daemonize(const char *pid_file, const char *log_file) {
    pid_t pid = fork();
    if (pid < 0) { LOGE("fork: %s", strerror(errno)); return -1; }
    if (pid > 0) _exit(0);
    if (setsid() < 0) { LOGE("setsid: %s", strerror(errno)); return -1; }
    signal(SIGHUP, SIG_IGN);
    pid = fork();
    if (pid < 0) { LOGE("fork(2): %s", strerror(errno)); return -1; }
    if (pid > 0) _exit(0);
    umask(022);
    (void)chdir("/");

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
            fprintf(stderr, "open(%s): %s\n", log_file, strerror(errno));
        } else {
            dup2(lf, STDERR_FILENO);
            if (lf != STDERR_FILENO) close(lf);
        }
    }
    if (pid_file) {
        FILE *fp = fopen(pid_file, "w");
        if (!fp) { LOGE("fopen(%s): %s", pid_file, strerror(errno)); return -1; }
        fprintf(fp, "%d\n", (int)getpid());
        fclose(fp);
    }
    return 0;
}

enum {
    OPT_INITIAL = 1000,
    OPT_WAIT_RAI,
    OPT_READ_BUF,
    OPT_FILE_BUF,
    OPT_PID_FILE,
    OPT_LOG_FILE,
    OPT_LISTEN_HOST,
    OPT_LISTEN_PORT,
    OPT_UPSTREAM_HOST,
    OPT_UPSTREAM_PORT,
    OPT_UPSTREAM_PATH,
    OPT_OUT_ROOT,
    OPT_SERVE_LOCAL,
    OPT_LOCAL_URL_FMT,
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
    double initial_hls_time = -1;
    int window = 5;
    int delete_old = 0;
    int do_daemon = 0;
    int wait_rai = 0;
    int low_latency = 0;
    int read_buf_size = 65536;
    int file_buf_size = 256 * 1024;

    const char *listen_host    = NULL;
    int         listen_port    = 0;
    const char *upstream_host  = NULL;
    int         upstream_port  = 0;
    const char *upstream_path  = NULL;
    const char *out_root       = NULL;
    int         serve_local    = 0;
    const char *local_url_fmt  = NULL;
    const char *fixed_input    = NULL;  /* Fixed input URL for all channels in server mode */

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
        {"listen-host",      required_argument, 0, OPT_LISTEN_HOST},
        {"listen-port",      required_argument, 0, OPT_LISTEN_PORT},
        {"upstream-host",    required_argument, 0, OPT_UPSTREAM_HOST},
        {"upstream-port",    required_argument, 0, OPT_UPSTREAM_PORT},
        {"upstream-path",    required_argument, 0, OPT_UPSTREAM_PATH},
        {"out-root",         required_argument, 0, OPT_OUT_ROOT},
        {"serve-local",      no_argument,       0, OPT_SERVE_LOCAL},
        {"local-url-fmt",    required_argument, 0, OPT_LOCAL_URL_FMT},
        {"verbose",          no_argument,       0, 'v'},
        {"help",             no_argument,       0, 'h'},
        {0, 0, 0, 0}
    };
    int c;
    while ((c = getopt_long(argc, argv, "i:o:n:p:t:w:dLDvh", long_opts, NULL)) != -1) {
        switch (c) {
            case 'i': 
                input = optarg; 
                fixed_input = optarg;  /* Also store as fixed_input for server mode */
                break;
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
            case OPT_LISTEN_HOST:   listen_host    = optarg; break;
            case OPT_LISTEN_PORT:   listen_port    = atoi(optarg); break;
            case OPT_UPSTREAM_HOST: upstream_host  = optarg; break;
            case OPT_UPSTREAM_PORT: upstream_port  = atoi(optarg); break;
            case OPT_UPSTREAM_PATH: upstream_path  = optarg; break;
            case OPT_OUT_ROOT:      out_root       = optarg; break;
            case OPT_SERVE_LOCAL:   serve_local    = 1; break;
            case OPT_LOCAL_URL_FMT: local_url_fmt  = optarg; break;
            case 'v': g_log_level = LOG_DEBUG; break;
            case 'h': usage(argv[0]); return 0;
            default:  usage(argv[0]); return 2;
        }
    }

    int server_mode = (listen_port > 0);
    if (server_mode) {
        if (!upstream_host || !upstream_port || !out_root) {
            fprintf(stderr,
                    "server mode requires --upstream-host, --upstream-port "
                    "and --out-root\n");
            return 2;
        }
    } else {
        if (!input || !out_dir) { usage(argv[0]); return 2; }
    }
    if (read_buf_size < (int)TS_PACKET_SIZE * 2) read_buf_size = TS_PACKET_SIZE * 2;
    if (initial_hls_time < 0) {
        initial_hls_time = hls_time / 3.0;
        if (initial_hls_time < 1.0) initial_hls_time = 1.0;
        if (low_latency && initial_hls_time > 2.0) initial_hls_time = 2.0;
    }

    if (do_daemon) {
        const char *check_dir = server_mode ? out_root : out_dir;
        if (check_dir[0] != '/') {
            fprintf(stderr,
                    "--daemon requires an absolute --out / --out-root path (got '%s')\n",
                    check_dir);
            return 2;
        }
        if (pid_file && pid_file[0] != '/') {
            fprintf(stderr, "--pid-file must be an absolute path under --daemon\n");
            return 2;
        }
        if (log_file && log_file[0] != '/') {
            fprintf(stderr, "--log-file must be an absolute path under --daemon\n");
            return 2;
        }
        if (daemonize(pid_file, log_file) != 0) return 1;
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

    LOGI("mpegts_hls_proxy starting (pid %d, mode=%s)",
         (int)getpid(), server_mode ? "server" : "direct");

    int rc;
    if (server_mode) {
        server_config_t scfg = {
            .listen_host       = listen_host,
            .listen_port       = listen_port,
            .upstream_host     = upstream_host,
            .upstream_port     = upstream_port,
            .upstream_path_fmt = upstream_path,
            .out_root          = out_root,
            .serve_local       = serve_local,
            .local_url_fmt     = local_url_fmt,
            .fixed_input       = fixed_input,   /* Fixed input URL for all channels */
            .proxy_template    = {
                .input            = NULL,        /* filled per-channel */
                .out_dir          = NULL,        /* filled per-channel */
                .playlist_name    = (char *)playlist,
                .segment_prefix   = (char *)prefix,
                .hls_time         = hls_time,
                .initial_hls_time = initial_hls_time,
                .window           = window,
                .delete_old       = delete_old,
                .wait_rai         = wait_rai,
                .read_buf_size    = read_buf_size,
                .file_buf_size    = file_buf_size,
            },
        };
        rc = server_run(&scfg, &g_stop);
    } else {
        proxy_config_t pcfg = {
            .input            = (char *)input,
            .out_dir          = (char *)out_dir,
            .playlist_name    = (char *)playlist,
            .segment_prefix   = (char *)prefix,
            .hls_time         = hls_time,
            .initial_hls_time = initial_hls_time,
            .window           = window,
            .delete_old       = delete_old,
            .wait_rai         = wait_rai,
            .read_buf_size    = read_buf_size,
            .file_buf_size    = file_buf_size,
        };
        rc = proxy_run(&pcfg, &g_stop, &t_start);
    }

    if (pid_file && do_daemon) unlink(pid_file);
    LOGI("done (exit %d)", rc);
    return rc;
}
