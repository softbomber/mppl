#ifndef MPEGTS_HLS_PROXY_PROXY_H
#define MPEGTS_HLS_PROXY_PROXY_H

#include <signal.h>
#include <time.h>

/*
 * Parameters for one proxy run: ingest from `input`, write HLS into
 * `out_dir/playlist_name` + segment files prefixed `segment_prefix`.
 *
 * The fields mirror the CLI flags in src/main.c. `proxy_config_dup()` /
 * `proxy_config_free()` take ownership of a deep-copy so the original
 * argv strings (or any short-lived buffers) don't need to outlive the
 * proxy.
 */
typedef struct {
    char *input;
    char *out_dir;
    char *playlist_name;
    char *segment_prefix;
    double hls_time;
    double initial_hls_time;
    int    window;
    int    delete_old;
    int    wait_rai;
    int    read_buf_size;
    int    file_buf_size;
} proxy_config_t;

/* Run a single proxy session synchronously in the current thread. The
 * function returns 0 on clean EOF / stop, non-zero on fatal I/O error.
 * `stop_flag`, if non-NULL, is polled and breaks the loop when set.
 * `t_start` is the CLOCK_MONOTONIC baseline the "playlist ready in ..."
 * log line measures from; if NULL the function reads the clock itself. */
int  proxy_run(const proxy_config_t *cfg,
               volatile sig_atomic_t *stop_flag,
               const struct timespec *t_start);

/* Deep-copy / free helpers — useful when the config is built per-channel
 * by the HTTP listener and handed off to a worker thread. */
proxy_config_t *proxy_config_dup(const proxy_config_t *src);
void            proxy_config_free(proxy_config_t *c);

#endif
