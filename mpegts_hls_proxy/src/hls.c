#include "hls.h"
#include "log.h"
#include "ts.h"

#include <errno.h>
#include <fcntl.h>
#include <math.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/stat.h>
#include <sys/types.h>
#include <unistd.h>

/* One entry of the sliding-window playlist. */
typedef struct hls_segment {
    char    *name;             /* file name relative to out_dir              */
    double   duration_sec;
    uint64_t seq;              /* media-sequence number                      */
    struct hls_segment *next;
} hls_segment_t;

struct hls_writer {
    hls_config_t cfg;
    FILE        *cur_fp;
    char        *cur_path;
    char        *cur_name;
    uint64_t     cur_first_pcr;     /* PCR (27 MHz) of first packet in cur seg */
    uint64_t     cur_last_pcr;
    int          cur_has_pcr;
    uint64_t     next_seq;          /* next segment's media sequence number   */

    hls_segment_t *head;
    hls_segment_t *tail;
    int            nb_segments;

    double         max_duration;    /* highest duration seen — drives TARGETDURATION */
};

static char *xstrdup(const char *s) {
    if (!s) return NULL;
    size_t n = strlen(s) + 1;
    char *p = malloc(n);
    if (p) memcpy(p, s, n);
    return p;
}

static int ensure_dir(const char *path) {
    struct stat st;
    if (stat(path, &st) == 0) {
        if (!S_ISDIR(st.st_mode)) {
            LOGE("%s exists and is not a directory", path);
            return -1;
        }
        return 0;
    }
    if (mkdir(path, 0755) != 0 && errno != EEXIST) {
        LOGE("mkdir(%s): %s", path, strerror(errno));
        return -1;
    }
    return 0;
}

hls_writer_t *hls_writer_create(const hls_config_t *cfg) {
    hls_writer_t *w = calloc(1, sizeof(*w));
    if (!w) return NULL;
    w->cfg.out_dir        = xstrdup(cfg->out_dir);
    w->cfg.playlist_name  = xstrdup(cfg->playlist_name);
    w->cfg.segment_prefix = xstrdup(cfg->segment_prefix ? cfg->segment_prefix : "seg");
    w->cfg.target_seconds = cfg->target_seconds > 0 ? cfg->target_seconds : 6.0;
    w->cfg.window_size    = cfg->window_size;
    w->cfg.delete_old     = cfg->delete_old;
    if (!w->cfg.out_dir || !w->cfg.playlist_name || !w->cfg.segment_prefix) {
        free(w->cfg.out_dir); free(w->cfg.playlist_name); free(w->cfg.segment_prefix);
        free(w);
        return NULL;
    }
    if (ensure_dir(w->cfg.out_dir) != 0) {
        free(w->cfg.out_dir); free(w->cfg.playlist_name); free(w->cfg.segment_prefix);
        free(w);
        return NULL;
    }
    return w;
}

static double pcr_to_seconds_delta(uint64_t a, uint64_t b) {
    /* 27 MHz clock; handles forward delta only. PCR wraps at 2^33 * 300
     * which is ~26.5 hours, so we ignore wrap for live windows. */
    if (b < a) return 0.0;
    return (double)(b - a) / 27000000.0;
}

static int write_playlist(hls_writer_t *w, int closing) {
    char tmp_path[1024];
    char final_path[1024];
    snprintf(final_path, sizeof(final_path), "%s/%s",
             w->cfg.out_dir, w->cfg.playlist_name);
    snprintf(tmp_path, sizeof(tmp_path), "%s.tmp", final_path);

    FILE *fp = fopen(tmp_path, "w");
    if (!fp) {
        LOGE("fopen(%s): %s", tmp_path, strerror(errno));
        return -1;
    }

    int td = (int)ceil(w->max_duration > 0 ? w->max_duration : w->cfg.target_seconds);
    if (td < 1) td = 1;
    uint64_t first_seq = w->head ? w->head->seq : w->next_seq;

    fprintf(fp, "#EXTM3U\n");
    fprintf(fp, "#EXT-X-VERSION:3\n");
    fprintf(fp, "#EXT-X-TARGETDURATION:%d\n", td);
    fprintf(fp, "#EXT-X-MEDIA-SEQUENCE:%llu\n", (unsigned long long)first_seq);
    if (w->cfg.window_size > 0)
        fprintf(fp, "#EXT-X-ALLOW-CACHE:NO\n");

    for (hls_segment_t *s = w->head; s; s = s->next) {
        fprintf(fp, "#EXTINF:%.3f,\n", s->duration_sec);
        fprintf(fp, "%s\n", s->name);
    }
    if (closing && w->cfg.window_size == 0)
        fprintf(fp, "#EXT-X-ENDLIST\n");

    if (fflush(fp) != 0 || fsync(fileno(fp)) != 0) {
        LOGW("fsync(%s): %s", tmp_path, strerror(errno));
    }
    fclose(fp);

    if (rename(tmp_path, final_path) != 0) {
        LOGE("rename(%s -> %s): %s", tmp_path, final_path, strerror(errno));
        return -1;
    }
    return 0;
}

static void evict_old(hls_writer_t *w) {
    if (w->cfg.window_size <= 0) return;
    while (w->nb_segments > w->cfg.window_size) {
        hls_segment_t *s = w->head;
        w->head = s->next;
        if (!w->head) w->tail = NULL;
        w->nb_segments--;

        if (w->cfg.delete_old) {
            char p[1024];
            snprintf(p, sizeof(p), "%s/%s", w->cfg.out_dir, s->name);
            if (unlink(p) != 0 && errno != ENOENT)
                LOGW("unlink(%s): %s", p, strerror(errno));
        }
        LOGI("evicted segment %s (seq %llu)", s->name,
             (unsigned long long)s->seq);
        free(s->name);
        free(s);
    }
}

static int close_current(hls_writer_t *w) {
    if (!w->cur_fp) return 0;
    fflush(w->cur_fp);
    fclose(w->cur_fp);
    w->cur_fp = NULL;

    double dur = pcr_to_seconds_delta(w->cur_first_pcr, w->cur_last_pcr);
    if (dur <= 0.0) dur = w->cfg.target_seconds;

    hls_segment_t *seg = calloc(1, sizeof(*seg));
    if (!seg) return -1;
    seg->name = w->cur_name;            /* take ownership */
    w->cur_name = NULL;
    seg->duration_sec = dur;
    seg->seq = w->next_seq - 1;

    if (w->tail) w->tail->next = seg; else w->head = seg;
    w->tail = seg;
    w->nb_segments++;
    if (dur > w->max_duration) w->max_duration = dur;

    LOGI("closed segment %s (seq %llu, %.3fs)", seg->name,
         (unsigned long long)seg->seq, dur);

    free(w->cur_path);
    w->cur_path = NULL;
    w->cur_has_pcr = 0;

    evict_old(w);
    return write_playlist(w, 0);
}

static int open_new(hls_writer_t *w) {
    char name[256];
    snprintf(name, sizeof(name), "%s%05llu.ts",
             w->cfg.segment_prefix, (unsigned long long)w->next_seq);
    char path[1024];
    snprintf(path, sizeof(path), "%s/%s", w->cfg.out_dir, name);

    FILE *fp = fopen(path, "wb");
    if (!fp) {
        LOGE("fopen(%s): %s", path, strerror(errno));
        return -1;
    }
    w->cur_fp = fp;
    w->cur_name = xstrdup(name);
    w->cur_path = xstrdup(path);
    w->cur_has_pcr = 0;
    w->next_seq++;
    LOGI("opened segment %s", name);
    return 0;
}

int hls_writer_maybe_rotate(hls_writer_t *w, uint64_t pcr_27mhz) {
    if (!w->cur_fp) return 0;
    if (!w->cur_has_pcr) return 0;
    double elapsed = pcr_to_seconds_delta(w->cur_first_pcr, pcr_27mhz);
    if (elapsed + 1e-6 < w->cfg.target_seconds) return 0;
    if (close_current(w) != 0) return -1;
    return open_new(w);
}

int hls_writer_push(hls_writer_t *w, const uint8_t *pkt, uint64_t pcr_27mhz) {
    if (!w->cur_fp) {
        if (open_new(w) != 0) return -1;
    }
    if (pcr_27mhz != 0) {
        if (!w->cur_has_pcr) {
            w->cur_first_pcr = pcr_27mhz;
            w->cur_has_pcr = 1;
        }
        w->cur_last_pcr = pcr_27mhz;
    }
    if (fwrite(pkt, 1, TS_PACKET_SIZE, w->cur_fp) != TS_PACKET_SIZE) {
        LOGE("fwrite segment: %s", strerror(errno));
        return -1;
    }
    return 0;
}

int hls_writer_finish(hls_writer_t *w) {
    if (close_current(w) != 0) return -1;
    return write_playlist(w, 1);
}

void hls_writer_destroy(hls_writer_t *w) {
    if (!w) return;
    if (w->cur_fp) fclose(w->cur_fp);
    free(w->cur_path);
    free(w->cur_name);
    hls_segment_t *s = w->head;
    while (s) {
        hls_segment_t *n = s->next;
        free(s->name); free(s);
        s = n;
    }
    free(w->cfg.out_dir);
    free(w->cfg.playlist_name);
    free(w->cfg.segment_prefix);
    free(w);
}
