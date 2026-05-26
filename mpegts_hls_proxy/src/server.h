#ifndef MPEGTS_HLS_PROXY_SERVER_H
#define MPEGTS_HLS_PROXY_SERVER_H

#include "proxy.h"
#include <signal.h>

/*
 * HTTP-frontend configuration. The server listens on listen_host:listen_port,
 * extracts the channel name from the first segment of every request URI
 * (e.g. `/mpolhd/index.m3u8` → "mpolhd"), and:
 *
 *   1. spawns a per-channel proxy worker (lazily, deduplicated) running
 *      `proxy_run` with input  = upstream_template substituted with the
 *      channel name (default: `http://<upstream_host>:<upstream_port>/<ch>/playlist.m3u8`),
 *      and out_dir = <out_root>/<ch>;
 *   2. answers the client with HTTP 302 Location: <upstream URL>
 *      (or, with --serve-local, falls through to the locally-generated
 *      playlist URL).
 *
 * The proxy template provides defaults for hls-time, window, etc.; per-channel
 * input/out_dir are filled in by the dispatcher.
 */
typedef struct {
    const char *listen_host;       /* e.g. "0.0.0.0", NULL = "0.0.0.0"      */
    int         listen_port;       /* e.g. 8222                              */

    const char *upstream_host;     /* e.g. "83.136.233.101"                  */
    int         upstream_port;     /* e.g. 8123                              */
    const char *upstream_path_fmt; /* printf fmt with one %s for channel;
                                      default: "/%s/playlist.m3u8"           */

    const char *out_root;          /* e.g. "/var/www"                        */

    int         serve_local;       /* 1 = respond with our local playlist URL
                                          instead of redirecting upstream    */
    const char *local_url_fmt;     /* printf fmt with one %s for channel,
                                      used when serve_local is set; e.g.
                                      "http://my.host/hls/%s/stream.m3u8".
                                      NULL falls back to a relative path.    */

    proxy_config_t proxy_template; /* per-channel defaults; input & out_dir
                                      are filled in by the dispatcher        */
} server_config_t;

int server_run(const server_config_t *cfg, volatile sig_atomic_t *stop_flag);

#endif
