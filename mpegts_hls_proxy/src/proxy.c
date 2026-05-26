#include "proxy.h"

#include "hls.h"
#include "log.h"
#include "source.h"
#include "ts.h"

#include <errno.h>
#include <signal.h>
#include <stdlib.h>
#include <string.h>
#include <sys/types.h>
#include <time.h>
#include <unistd.h>

static char *dup_or_null(const char *s) {
    if (!s) return NULL;
    size_t n = strlen(s) + 1;
    char *p = malloc(n);
    if (p) memcpy(p, s, n);
    return p;
}

proxy_config_t *proxy_config_dup(const proxy_config_t *src) {
    proxy_config_t *c = calloc(1, sizeof(*c));
    if (!c) return NULL;
    c->input          = dup_or_null(src->input);
    c->out_dir        = dup_or_null(src->out_dir);
    c->playlist_name  = dup_or_null(src->playlist_name);
    c->segment_prefix = dup_or_null(src->segment_prefix);
    c->hls_time         = src->hls_time;
    c->initial_hls_time = src->initial_hls_time;
    c->window           = src->window;
    c->delete_old       = src->delete_old;
    c->wait_rai         = src->wait_rai;
    c->read_buf_size    = src->read_buf_size;
    c->file_buf_size    = src->file_buf_size;
    return c;
}

void proxy_config_free(proxy_config_t *c) {
    if (!c) return;
    free(c->input);
    free(c->out_dir);
    free(c->playlist_name);
    free(c->segment_prefix);
    free(c);
}

static double ts_diff(const struct timespec *a, const struct timespec *b) {
    return (double)(b->tv_sec - a->tv_sec) +
           (double)(b->tv_nsec - a->tv_nsec) / 1e9;
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

int proxy_run(const proxy_config_t *cfg,
              volatile sig_atomic_t *stop_flag,
              const struct timespec *t_start_in) {
    struct timespec t_start;
    if (t_start_in) t_start = *t_start_in;
    else clock_gettime(CLOCK_MONOTONIC, &t_start);

    int read_buf_size = cfg->read_buf_size > 0 ? cfg->read_buf_size : 65536;
    if (read_buf_size < (int)TS_PACKET_SIZE * 2) read_buf_size = TS_PACKET_SIZE * 2;
    int file_buf_size = cfg->file_buf_size > 0 ? cfg->file_buf_size : 256 * 1024;

    LOGI("proxy: %s -> %s/%s (target %.2fs, first %.2fs, window %d%s%s)",
         cfg->input, cfg->out_dir,
         cfg->playlist_name ? cfg->playlist_name : "stream.m3u8",
         cfg->hls_time,
         cfg->initial_hls_time > 0 ? cfg->initial_hls_time : cfg->hls_time,
         cfg->window,
         cfg->delete_old ? ", delete_old" : "",
         cfg->wait_rai ? ", wait_rai" : "");

    source_t *src = source_open(cfg->input);
    if (!src) return 1;

    hls_config_t hcfg = {
        .out_dir                = cfg->out_dir,
        .playlist_name          = cfg->playlist_name ? cfg->playlist_name : "stream.m3u8",
        .segment_prefix         = cfg->segment_prefix ? cfg->segment_prefix : "seg",
        .target_seconds         = cfg->hls_time,
        .initial_target_seconds = cfg->initial_hls_time,
        .window_size            = cfg->window,
        .delete_old             = cfg->delete_old,
        .file_buffer_bytes      = file_buf_size,
    };
    hls_writer_t *hw = hls_writer_create(&hcfg);
    if (!hw) { source_close(src); return 1; }

    ts_program_t prog;
    ts_program_init(&prog);

    uint8_t *buf = malloc((size_t)read_buf_size);
    if (!buf) { hls_writer_destroy(hw); source_close(src); return 1; }
    size_t   buf_len = 0;
    int      eof = 0;
    uint64_t last_pcr = 0;
    int      have_any_pcr = 0;
    int      saw_first_rai = !cfg->wait_rai;
    int      logged_playable = 0;
    uint64_t pkt_counter = 0;

    int rc = 0;
    while (!(stop_flag && *stop_flag)) {
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

        if (parsed.pid == TS_PID_PAT) {
            hls_writer_cache_psi(hw, buf, NULL);
        } else if (prog.have_pmt_pid && parsed.pid == prog.pmt_pid) {
            hls_writer_cache_psi(hw, NULL, buf);
        }

        int is_video_rai = prog.have_video && parsed.pid == prog.video_pid &&
                           parsed.pusi && parsed.random_access_indicator;

        if (!saw_first_rai) {
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
    return rc;
}
