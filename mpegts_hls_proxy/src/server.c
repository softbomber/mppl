#include "server.h"

#include "log.h"
#include "proxy.h"

#include <arpa/inet.h>
#include <ctype.h>
#include <errno.h>
#include <fcntl.h>
#include <netdb.h>
#include <netinet/in.h>
#include <netinet/tcp.h>
#include <pthread.h>
#include <signal.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/socket.h>
#include <sys/stat.h>
#include <sys/types.h>
#include <sys/time.h>
#include <unistd.h>

/*
 * Per-channel worker. Each unique channel name encountered by the HTTP
 * listener gets exactly one worker thread running `proxy_run`. We do not
 * try to reap workers on the source EOF — for live streams the source
 * shouldn't EOF, and for the file case the worker will exit naturally
 * once the file is consumed; the entry stays in the list as a sentinel
 * so a second request for the same channel does not re-spawn it (which
 * would race on the segment filenames).
 */
typedef struct channel_worker {
    char                   *name;
    pthread_t               tid;
    volatile sig_atomic_t   stop;
    int                     started;
    proxy_config_t         *cfg;          /* owned: freed on join          */
    struct channel_worker  *next;
} channel_worker_t;

typedef struct {
    const server_config_t   *cfg;
    pthread_mutex_t          lock;
    channel_worker_t        *head;
    volatile sig_atomic_t   *stop_flag;
} dispatcher_t;

static dispatcher_t g_disp = { .lock = PTHREAD_MUTEX_INITIALIZER };

/* -------------------- channel name validation -------------------------- */

/* Allow [A-Za-z0-9._-], 1..64 chars. Anything else is rejected so we never
 * touch the filesystem with attacker-controlled "../" or NUL bytes. */
static int channel_name_ok(const char *s) {
    if (!s || !*s) return 0;
    size_t n = strlen(s);
    if (n > 64) return 0;
    for (size_t i = 0; i < n; i++) {
        char c = s[i];
        int ok = (c >= 'a' && c <= 'z') || (c >= 'A' && c <= 'Z') ||
                 (c >= '0' && c <= '9') || c == '.' || c == '_' || c == '-';
        if (!ok) return 0;
    }
    /* Reject ".", ".." outright. */
    if (strcmp(s, ".") == 0 || strcmp(s, "..") == 0) return 0;
    return 1;
}

/* Extract the first non-empty path segment from a request URI. The URI
 * may include a query string and leading slashes. Returns a malloc'd
 * NUL-terminated string, or NULL on failure. */
static char *path_first_segment(const char *uri) {
    while (*uri == '/') uri++;
    const char *end = uri;
    while (*end && *end != '/' && *end != '?' && *end != '#' && *end != ' ')
        end++;
    size_t n = (size_t)(end - uri);
    if (n == 0 || n > 64) return NULL;
    char *s = malloc(n + 1);
    if (!s) return NULL;
    memcpy(s, uri, n);
    s[n] = '\0';
    return s;
}

/* -------------------- worker thread ----------------------------------- */

static void *worker_thread(void *arg) {
    channel_worker_t *w = arg;
    LOGI("worker[%s] start, input=%s out=%s",
         w->name, w->cfg->input, w->cfg->out_dir);
    int rc = proxy_run(w->cfg, &w->stop, NULL);
    LOGI("worker[%s] exit rc=%d", w->name, rc);
    return NULL;
}

/* Lookup-or-create a channel worker. Caller does NOT need to hold the
 * dispatcher mutex; this function locks internally. */
static int ensure_channel(dispatcher_t *d, const char *name) {
    pthread_mutex_lock(&d->lock);
    for (channel_worker_t *w = d->head; w; w = w->next) {
        if (strcmp(w->name, name) == 0) {
            pthread_mutex_unlock(&d->lock);
            return 0;
        }
    }

    /* Build the per-channel proxy config off the template. */
    proxy_config_t tmpl = d->cfg->proxy_template;

    char input_url[1024];
    
    /* If fixed_input is set, use it as the input URL for all channels.
     * Otherwise, construct the URL from upstream_host/port/path_fmt. */
    if (d->cfg->fixed_input) {
        if (snprintf(input_url, sizeof(input_url), "%s", d->cfg->fixed_input) >= (int)sizeof(input_url)) {
            pthread_mutex_unlock(&d->lock);
            return -1;
        }
    } else {
        /* Substitute %s in upstream_path_fmt with the channel name. */
        const char *fmt = d->cfg->upstream_path_fmt
                        ? d->cfg->upstream_path_fmt
                        : "/%s/playlist.m3u8";
        char path[512];
        if (snprintf(path, sizeof(path), fmt, name) >= (int)sizeof(path)) {
            pthread_mutex_unlock(&d->lock);
            return -1;
        }
        if (snprintf(input_url, sizeof(input_url),
                     "http://%s:%d%s",
                     d->cfg->upstream_host,
                     d->cfg->upstream_port,
                     path) >= (int)sizeof(input_url)) {
            pthread_mutex_unlock(&d->lock);
            return -1;
        }
    }
    char out_dir[1024];
    if (snprintf(out_dir, sizeof(out_dir), "%s/%s",
                 d->cfg->out_root, name) >= (int)sizeof(out_dir)) {
        pthread_mutex_unlock(&d->lock);
        return -1;
    }
    tmpl.input   = input_url;
    tmpl.out_dir = out_dir;

    proxy_config_t *owned = proxy_config_dup(&tmpl);
    if (!owned) {
        pthread_mutex_unlock(&d->lock);
        return -1;
    }
    channel_worker_t *w = calloc(1, sizeof(*w));
    if (!w) {
        proxy_config_free(owned);
        pthread_mutex_unlock(&d->lock);
        return -1;
    }
    w->name = strdup(name);
    w->cfg  = owned;
    w->next = d->head;
    d->head = w;
    pthread_mutex_unlock(&d->lock);

    pthread_attr_t attr;
    pthread_attr_init(&attr);
    pthread_attr_setdetachstate(&attr, PTHREAD_CREATE_DETACHED);
    int prc = pthread_create(&w->tid, &attr, worker_thread, w);
    pthread_attr_destroy(&attr);
    if (prc != 0) {
        LOGE("pthread_create: %s", strerror(prc));
        /* The list entry stays as a tombstone — a retry would race. */
        return -1;
    }
    w->started = 1;
    return 0;
}

/* -------------------- HTTP per-connection handler --------------------- */

typedef struct {
    int                fd;
    struct sockaddr_in peer;
} conn_t;

static int read_request_line(int fd, char *buf, size_t cap) {
    size_t li = 0;
    int last = 0;
    while (li < cap - 1) {
        char c;
        ssize_t n = recv(fd, &c, 1, 0);
        if (n <= 0) return -1;
        if (c == '\n') {
            buf[li] = '\0';
            return (int)li;
        }
        if (c != '\r') buf[li++] = c;
        last = c;
    }
    (void)last;
    return -1;
}

/* Drain remaining headers until blank line. */
static void drain_headers(int fd) {
    int blank = 0;
    int seen_anything = 0;
    int safety = 64 * 1024;
    char c;
    while (safety-- > 0) {
        ssize_t n = recv(fd, &c, 1, 0);
        if (n <= 0) return;
        if (c == '\r') continue;
        if (c == '\n') {
            if (!seen_anything) return; /* blank line right after request line */
            if (blank) return;
            blank = 1;
        } else {
            blank = 0;
            seen_anything = 1;
        }
    }
}

static int write_all(int fd, const void *data, size_t len) {
    const unsigned char *p = data;
    while (len > 0) {
        ssize_t n = send(fd, p, len, 0);
        if (n < 0) {
            if (errno == EINTR) continue;
            return -1;
        }
        p   += n;
        len -= (size_t)n;
    }
    return 0;
}

static void send_simple(int fd, int status, const char *text) {
    char hdr[256];
    int n = snprintf(hdr, sizeof(hdr),
                     "HTTP/1.1 %d %s\r\n"
                     "Server: mpegts_hls_proxy\r\n"
                     "Content-Length: 0\r\n"
                     "Connection: close\r\n\r\n",
                     status, text);
    if (n > 0) write_all(fd, hdr, (size_t)n);
}

/* Send an M3U8 playlist body with a redirect URL inside.
 * This is used when fixed_input is set: we return a valid M3U8
 * that points the client to the upstream server's playlist. */
static void send_m3u8_redirect(int fd, const char *location) {
    char body[2048];
    int n = snprintf(body, sizeof(body),
                     "#EXTM3U\n"
                     "#EXT-X-VERSION:3\n"
                     "#EXT-X-STREAM-INF:BANDWIDTH=20000000\n"
                     "%s\n",
                     location);
    if (n <= 0 || n >= (int)sizeof(body)) return;
    
    char hdr[256];
    int hlen = snprintf(hdr, sizeof(hdr),
                     "HTTP/1.1 200 OK\r\n"
                     "Server: mpegts_hls_proxy\r\n"
                     "Content-Type: application/vnd.apple.mpegurl\r\n"
                     "Content-Length: %d\r\n"
                     "Connection: close\r\n\r\n",
                     n);
    if (hlen > 0) {
        write_all(fd, hdr, (size_t)hlen);
        write_all(fd, body, (size_t)n);
    }
}

static void send_redirect(int fd, const char *location) {
    char hdr[2048];
    int n = snprintf(hdr, sizeof(hdr),
                     "HTTP/1.1 302 Found\r\n"
                     "Server: mpegts_hls_proxy\r\n"
                     "Location: %s\r\n"
                     "Cache-Control: no-store\r\n"
                     "Content-Length: 0\r\n"
                     "Connection: close\r\n\r\n",
                     location);
    if (n > 0 && n < (int)sizeof(hdr)) write_all(fd, hdr, (size_t)n);
}

/* Pull GET request line, dispatch to channel, write response. */
static void handle_connection(conn_t *c, dispatcher_t *d) {
    char line[2048];
    if (read_request_line(c->fd, line, sizeof(line)) < 0) {
        close(c->fd);
        free(c);
        return;
    }
    drain_headers(c->fd);

    /* Parse "METHOD SP URI SP VERSION". */
    char method[16] = {0};
    char uri[1024]  = {0};
    if (sscanf(line, "%15s %1023s", method, uri) != 2) {
        send_simple(c->fd, 400, "Bad Request");
        goto done;
    }
    if (strcmp(method, "GET") != 0 && strcmp(method, "HEAD") != 0) {
        send_simple(c->fd, 405, "Method Not Allowed");
        goto done;
    }

    char *channel = path_first_segment(uri);
    if (!channel || !channel_name_ok(channel)) {
        free(channel);
        send_simple(c->fd, 404, "Not Found");
        goto done;
    }

    char peer_ip[INET_ADDRSTRLEN] = {0};
    inet_ntop(AF_INET, &c->peer.sin_addr, peer_ip, sizeof(peer_ip));
    LOGI("http: %s requested %s -> channel=%s", peer_ip, uri, channel);

    if (ensure_channel(d, channel) != 0) {
        free(channel);
        send_simple(c->fd, 500, "Internal Server Error");
        goto done;
    }

    /* Compose redirect target. */
    char target[2048];
    if (d->cfg->serve_local && d->cfg->local_url_fmt) {
        if (snprintf(target, sizeof(target),
                     d->cfg->local_url_fmt, channel) >= (int)sizeof(target)) {
            send_simple(c->fd, 500, "URL too long");
            free(channel);
            goto done;
        }
    } else if (d->cfg->serve_local) {
        /* No absolute pattern given — emit a same-host relative URL that
         * a reverse proxy (e.g. nginx) can map to <out_root>/<channel>/. */
        if (snprintf(target, sizeof(target),
                     "/%s/%s", channel,
                     d->cfg->proxy_template.playlist_name
                         ? d->cfg->proxy_template.playlist_name
                         : "stream.m3u8") >= (int)sizeof(target)) {
            send_simple(c->fd, 500, "URL too long");
            free(channel);
            goto done;
        }
    } else {
        /* Always redirect to upstream_host:port so the client fetches
         * the playlist from there. The worker will still fetch from
         * fixed_input if it's set, or from upstream_host:port otherwise. */
        const char *fmt = d->cfg->upstream_path_fmt
                        ? d->cfg->upstream_path_fmt
                        : "/%s/playlist.m3u8";
        char path[512];
        if (snprintf(path, sizeof(path), fmt, channel) >= (int)sizeof(path)) {
            send_simple(c->fd, 500, "URL too long");
            free(channel);
            goto done;
        }
        if (snprintf(target, sizeof(target),
                     "http://%s:%d%s",
                     d->cfg->upstream_host, d->cfg->upstream_port,
                     path) >= (int)sizeof(target)) {
            send_simple(c->fd, 500, "URL too long");
            free(channel);
            goto done;
        }
    }
    LOGI("http: redirect %s -> %s", channel, target);
    /* When fixed_input is set, send an M3U8 body with the redirect URL.
     * Otherwise, send a standard HTTP 302 redirect. */
    if (d->cfg->fixed_input) {
        send_m3u8_redirect(c->fd, target);
    } else {
        send_redirect(c->fd, target);
    }
    free(channel);

done:
    /* Linger a moment so the FIN reaches the client cleanly before we
     * tear the socket down. shutdown() is enough — close releases the fd. */
    shutdown(c->fd, SHUT_WR);
    char drain[64];
    while (recv(c->fd, drain, sizeof(drain), 0) > 0) {}
    close(c->fd);
    free(c);
}

static void *conn_thread(void *arg) {
    conn_t *c = arg;
    handle_connection(c, &g_disp);
    return NULL;
}

/* -------------------- listen socket ----------------------------------- */

static int open_listen_socket(const char *host, int port) {
    struct addrinfo hints = {0};
    hints.ai_family   = AF_INET;
    hints.ai_socktype = SOCK_STREAM;
    hints.ai_flags    = AI_PASSIVE;

    char service[16];
    snprintf(service, sizeof(service), "%d", port);

    struct addrinfo *res = NULL;
    int rc = getaddrinfo(host, service, &hints, &res);
    if (rc != 0) {
        LOGE("getaddrinfo(%s:%d): %s", host ? host : "*", port, gai_strerror(rc));
        return -1;
    }

    int fd = socket(res->ai_family, res->ai_socktype, res->ai_protocol);
    if (fd < 0) {
        LOGE("socket: %s", strerror(errno));
        freeaddrinfo(res);
        return -1;
    }
    int one = 1;
    (void)setsockopt(fd, SOL_SOCKET, SO_REUSEADDR, &one, sizeof(one));
    if (bind(fd, res->ai_addr, res->ai_addrlen) != 0) {
        LOGE("bind(%s:%d): %s", host ? host : "*", port, strerror(errno));
        close(fd);
        freeaddrinfo(res);
        return -1;
    }
    if (listen(fd, 64) != 0) {
        LOGE("listen: %s", strerror(errno));
        close(fd);
        freeaddrinfo(res);
        return -1;
    }
    freeaddrinfo(res);
    return fd;
}

int server_run(const server_config_t *cfg, volatile sig_atomic_t *stop_flag) {
    g_disp.cfg       = cfg;
    g_disp.stop_flag = stop_flag;

    /* Make the out_root exists so the workers' mkdir(<root>/<channel>)
     * doesn't fail on a missing parent. */
    if (mkdir(cfg->out_root, 0755) != 0 && errno != EEXIST) {
        LOGW("mkdir(%s): %s", cfg->out_root, strerror(errno));
    }

    int lfd = open_listen_socket(cfg->listen_host ? cfg->listen_host : "0.0.0.0",
                                 cfg->listen_port);
    if (lfd < 0) return 1;

    /* Mark the listen fd close-on-exec for hygiene. */
    int fl = fcntl(lfd, F_GETFD, 0);
    if (fl >= 0) fcntl(lfd, F_SETFD, fl | FD_CLOEXEC);

    LOGI("http listener on %s:%d (upstream %s:%d, out_root %s)",
         cfg->listen_host ? cfg->listen_host : "0.0.0.0",
         cfg->listen_port,
         cfg->upstream_host, cfg->upstream_port, cfg->out_root);

    while (!(stop_flag && *stop_flag)) {
        struct sockaddr_in peer;
        socklen_t peer_len = sizeof(peer);
        int cfd = accept(lfd, (struct sockaddr *)&peer, &peer_len);
        if (cfd < 0) {
            if (errno == EINTR) continue;
            LOGE("accept: %s", strerror(errno));
            break;
        }
        int one = 1;
        (void)setsockopt(cfd, IPPROTO_TCP, TCP_NODELAY, &one, sizeof(one));
        struct timeval rto = { .tv_sec = 5, .tv_usec = 0 };
        (void)setsockopt(cfd, SOL_SOCKET, SO_RCVTIMEO, &rto, sizeof(rto));

        conn_t *c = calloc(1, sizeof(*c));
        if (!c) { close(cfd); continue; }
        c->fd   = cfd;
        c->peer = peer;

        pthread_t tid;
        pthread_attr_t attr;
        pthread_attr_init(&attr);
        pthread_attr_setdetachstate(&attr, PTHREAD_CREATE_DETACHED);
        int prc = pthread_create(&tid, &attr, conn_thread, c);
        pthread_attr_destroy(&attr);
        if (prc != 0) {
            LOGE("pthread_create(conn): %s", strerror(prc));
            close(cfd);
            free(c);
        }
    }

    close(lfd);

    /* Signal all workers to stop. We don't join — they're detached and
     * the process is about to exit. */
    pthread_mutex_lock(&g_disp.lock);
    for (channel_worker_t *w = g_disp.head; w; w = w->next) w->stop = 1;
    pthread_mutex_unlock(&g_disp.lock);

    return 0;
}
