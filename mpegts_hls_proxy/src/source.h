#ifndef MPEGTS_HLS_PROXY_SOURCE_H
#define MPEGTS_HLS_PROXY_SOURCE_H

#include <stddef.h>
#include <sys/types.h>

/*
 * Uniform read interface over a local file or an HTTP URL — the way
 * libavformat's AVIOContext hides file.c vs. tcp.c+http.c from the demuxer.
 */
typedef struct source source_t;

source_t *source_open(const char *url_or_path);

/* Returns >0 bytes read, 0 on EOF, -1 on fatal error. Short reads are
 * possible — call sites loop. */
ssize_t source_read(source_t *s, void *buf, size_t len);

void source_close(source_t *s);

#endif
