#include "url.h"
#include "log.h"

#include <arpa/inet.h>
#include <ctype.h>
#include <errno.h>
#include <netdb.h>
#include <netinet/in.h>
#include <netinet/tcp.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/socket.h>
#include <sys/types.h>
#include <unistd.h>

struct http_stream {
    int fd;
    /* Bytes already pulled from the socket while parsing the response
     * header but belonging to the body. */
    unsigned char carry[4096];
    size_t carry_len;
    size_t carry_pos;
};

/* Parse "http://host[:port]/path" into its three parts. Returns 0 on
 * success. We only support plain HTTP — TLS would require pulling in
 * OpenSSL/mbedTLS, which is out of scope for a passthrough TS proxy. */
static int parse_url(const char *url, char *host, size_t host_sz,
                     int *port, char *path, size_t path_sz) {
    const char *p = url;
    if (strncmp(p, "http://", 7) != 0) {
        LOGE("only http:// URLs are supported, got: %s", url);
        return -1;
    }
    p += 7;

    const char *slash = strchr(p, '/');
    const char *colon = NULL;
    const char *host_end = slash ? slash : p + strlen(p);
    for (const char *q = p; q < host_end; q++) {
        if (*q == ':') { colon = q; break; }
    }

    size_t host_len;
    if (colon) {
        host_len = (size_t)(colon - p);
        *port = atoi(colon + 1);
        if (*port <= 0 || *port > 65535) {
            LOGE("invalid port in URL: %s", url);
            return -1;
        }
    } else {
        host_len = (size_t)(host_end - p);
        *port = 80;
    }
    if (host_len == 0 || host_len + 1 > host_sz) {
        LOGE("invalid/oversized host in URL: %s", url);
        return -1;
    }
    memcpy(host, p, host_len);
    host[host_len] = '\0';

    if (slash) {
        if (strlen(slash) + 1 > path_sz) return -1;
        strcpy(path, slash);
    } else {
        if (path_sz < 2) return -1;
        strcpy(path, "/");
    }
    return 0;
}

static int tcp_connect(const char *host, int port) {
    char service[16];
    snprintf(service, sizeof(service), "%d", port);

    struct addrinfo hints = {0};
    hints.ai_family = AF_UNSPEC;
    hints.ai_socktype = SOCK_STREAM;

    struct addrinfo *res = NULL;
    int rc = getaddrinfo(host, service, &hints, &res);
    if (rc != 0) {
        LOGE("getaddrinfo(%s:%d): %s", host, port, gai_strerror(rc));
        return -1;
    }

    int fd = -1;
    for (struct addrinfo *ai = res; ai; ai = ai->ai_next) {
        fd = socket(ai->ai_family, ai->ai_socktype, ai->ai_protocol);
        if (fd < 0) continue;
        if (connect(fd, ai->ai_addr, ai->ai_addrlen) == 0) {
            char ipbuf[INET6_ADDRSTRLEN] = {0};
            void *src = NULL;
            if (ai->ai_family == AF_INET)
                src = &((struct sockaddr_in *)ai->ai_addr)->sin_addr;
            else if (ai->ai_family == AF_INET6)
                src = &((struct sockaddr_in6 *)ai->ai_addr)->sin6_addr;
            if (src) inet_ntop(ai->ai_family, src, ipbuf, sizeof(ipbuf));
            LOGI("connected to %s:%d (%s)", host, port, ipbuf);
            /*
             * Tune the socket for first-byte latency: a large receive
             * buffer absorbs a TCP burst without making us return to
             * recv() many times, TCP_NODELAY ensures our outgoing GET
             * leaves immediately, and SO_KEEPALIVE keeps dead-peer
             * detection on for long-running live feeds.
             */
            int rcv = 1 << 20;       /* 1 MiB */
            (void)setsockopt(fd, SOL_SOCKET, SO_RCVBUF, &rcv, sizeof(rcv));
            int one = 1;
            (void)setsockopt(fd, IPPROTO_TCP, TCP_NODELAY, &one, sizeof(one));
            (void)setsockopt(fd, SOL_SOCKET, SO_KEEPALIVE, &one, sizeof(one));
            break;
        }
        close(fd);
        fd = -1;
    }
    freeaddrinfo(res);

    if (fd < 0) {
        LOGE("connect(%s:%d) failed: %s", host, port, strerror(errno));
        return -1;
    }
    return fd;
}

static int write_all(int fd, const void *buf, size_t len) {
    const unsigned char *p = buf;
    while (len > 0) {
        ssize_t n = send(fd, p, len, 0);
        if (n < 0) {
            if (errno == EINTR) continue;
            return -1;
        }
        p += n;
        len -= (size_t)n;
    }
    return 0;
}

/* Read one byte. Used while we are parsing the header line by line. */
static int read_byte(int fd, unsigned char *out) {
    for (;;) {
        ssize_t n = recv(fd, out, 1, 0);
        if (n == 1) return 1;
        if (n == 0) return 0;
        if (errno == EINTR) continue;
        return -1;
    }
}

http_stream_t *http_stream_open(const char *url) {
    char host[256];
    char path[1024];
    int port = 80;

    if (parse_url(url, host, sizeof(host), &port, path, sizeof(path)) != 0)
        return NULL;

    int fd = tcp_connect(host, port);
    if (fd < 0) return NULL;

    /* Compose the request. HTTP/1.0 + "Connection: close" gives us EOF as
     * the end-of-stream marker, which is the semantics we want anyway. */
    char req[2048];
    int n = snprintf(req, sizeof(req),
                     "GET %s HTTP/1.0\r\n"
                     "Host: %s\r\n"
                     "User-Agent: mpegts_hls_proxy/1.0\r\n"
                     "Accept: */*\r\n"
                     "Connection: close\r\n"
                     "\r\n",
                     path, host);
    if (n <= 0 || n >= (int)sizeof(req)) {
        LOGE("request line too long");
        close(fd);
        return NULL;
    }
    if (write_all(fd, req, (size_t)n) != 0) {
        LOGE("send: %s", strerror(errno));
        close(fd);
        return NULL;
    }

    /* Parse status line. */
    char line[2048];
    size_t li = 0;
    for (;;) {
        unsigned char c;
        int r = read_byte(fd, &c);
        if (r <= 0) {
            LOGE("EOF while reading status line");
            close(fd);
            return NULL;
        }
        if (c == '\n') break;
        if (c == '\r') continue;
        if (li < sizeof(line) - 1) line[li++] = (char)c;
    }
    line[li] = '\0';

    int status = 0;
    if (sscanf(line, "HTTP/%*s %d", &status) != 1) {
        LOGE("bad status line: %s", line);
        close(fd);
        return NULL;
    }
    LOGI("HTTP %d %s", status, line);
    if (status < 200 || status >= 300) {
        if (status >= 300 && status < 400)
            LOGE("HTTP redirects are not followed (got %d)", status);
        close(fd);
        return NULL;
    }

    /* Skip the remaining headers. We don't need Content-Length because we
     * read until EOF. */
    int blank = 0;
    for (;;) {
        unsigned char c;
        int r = read_byte(fd, &c);
        if (r <= 0) {
            LOGE("EOF inside headers");
            close(fd);
            return NULL;
        }
        if (c == '\r') continue;
        if (c == '\n') {
            if (blank) break;
            blank = 1;
        } else {
            blank = 0;
        }
    }

    http_stream_t *s = calloc(1, sizeof(*s));
    if (!s) { close(fd); return NULL; }
    s->fd = fd;
    return s;
}

ssize_t http_stream_read(http_stream_t *s, void *buf, size_t len) {
    if (!s || s->fd < 0) return -1;
    if (s->carry_pos < s->carry_len) {
        size_t avail = s->carry_len - s->carry_pos;
        size_t n = avail < len ? avail : len;
        memcpy(buf, s->carry + s->carry_pos, n);
        s->carry_pos += n;
        return (ssize_t)n;
    }
    for (;;) {
        ssize_t n = recv(s->fd, buf, len, 0);
        if (n >= 0) return n;
        if (errno == EINTR) continue;
        return -1;
    }
}

void http_stream_close(http_stream_t *s) {
    if (!s) return;
    if (s->fd >= 0) close(s->fd);
    free(s);
}
