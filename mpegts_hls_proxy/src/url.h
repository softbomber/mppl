#ifndef MPEGTS_HLS_PROXY_URL_H
#define MPEGTS_HLS_PROXY_URL_H

#include <stddef.h>
#include <sys/types.h>

/*
 * Minimal HTTP/1.0 client used as a stand-in for libavformat's tcp.c +
 * http.c. We deliberately speak HTTP/1.0 with "Connection: close" so we
 * don't need to handle chunked transfer encoding — the response body is
 * read until EOF, exactly mirroring how an MPEG-TS multicast or unicast
 * feed is consumed.
 */

struct http_stream;
typedef struct http_stream http_stream_t;

/* Parse url, resolve host, open TCP socket, send GET, parse status line and
 * skip headers. On success returns a stream ready to be read with
 * http_stream_read(). On error returns NULL and logs a message. */
http_stream_t *http_stream_open(const char *url);

/* Blocking read of up to `len` bytes. Returns:
 *   > 0  number of bytes read
 *   = 0  EOF (server closed the connection cleanly)
 *   < 0  fatal error                                                   */
ssize_t http_stream_read(http_stream_t *s, void *buf, size_t len);

void http_stream_close(http_stream_t *s);

#endif
