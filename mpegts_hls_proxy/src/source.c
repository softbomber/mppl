#include "source.h"
#include "log.h"
#include "url.h"

#include <errno.h>
#include <fcntl.h>
#include <stdlib.h>
#include <string.h>
#include <sys/stat.h>
#include <unistd.h>

enum source_kind { SRC_FILE, SRC_HTTP };

struct source {
    enum source_kind kind;
    int fd;
    http_stream_t *http;
};

static int is_http(const char *p) {
    return strncmp(p, "http://", 7) == 0 || strncmp(p, "https://", 8) == 0;
}

source_t *source_open(const char *url_or_path) {
    source_t *s = calloc(1, sizeof(*s));
    if (!s) return NULL;
    s->fd = -1;

    if (is_http(url_or_path)) {
        s->kind = SRC_HTTP;
        s->http = http_stream_open(url_or_path);
        if (!s->http) { free(s); return NULL; }
        LOGI("source: HTTP %s", url_or_path);
        return s;
    }

    /* file:// prefix is tolerated */
    const char *path = url_or_path;
    if (strncmp(path, "file://", 7) == 0) path += 7;

    s->kind = SRC_FILE;
    s->fd = open(path, O_RDONLY);
    if (s->fd < 0) {
        LOGE("open(%s): %s", path, strerror(errno));
        free(s);
        return NULL;
    }
    LOGI("source: file %s", path);
    return s;
}

ssize_t source_read(source_t *s, void *buf, size_t len) {
    if (!s) return -1;
    if (s->kind == SRC_HTTP) return http_stream_read(s->http, buf, len);

    for (;;) {
        ssize_t n = read(s->fd, buf, len);
        if (n >= 0) return n;
        if (errno == EINTR) continue;
        return -1;
    }
}

void source_close(source_t *s) {
    if (!s) return;
    if (s->kind == SRC_HTTP) http_stream_close(s->http);
    else if (s->fd >= 0) close(s->fd);
    free(s);
}
