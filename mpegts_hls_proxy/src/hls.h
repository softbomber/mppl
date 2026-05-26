#ifndef MPEGTS_HLS_PROXY_HLS_H
#define MPEGTS_HLS_PROXY_HLS_H

#include <stddef.h>
#include <stdint.h>
#include <time.h>

/*
 * HLS segmenter. Mirrors libavformat/hlsenc.c at the level needed for a
 * passthrough TS proxy:
 *   - target segment duration (-hls_time),
 *   - sliding window of N segments (-hls_list_size),
 *   - delete old segment files (-hls_flags delete_segments).
 *
 * The `initial_target_seconds` field lets the *first* segment close
 * faster than subsequent ones — the time-to-first-frame on the client
 * is bounded by this value, and the steady-state target is independent.
 */
typedef struct {
    char    *out_dir;        /* where to write segments + playlist            */
    char    *playlist_name;  /* e.g. "stream.m3u8"                            */
    char    *segment_prefix; /* e.g. "seg" -> seg00001.ts                     */
    double   target_seconds;         /* -hls_time                             */
    double   initial_target_seconds; /* 0 => same as target_seconds           */
    int      window_size;            /* -hls_list_size, 0 = unlimited (VOD)   */
    int      delete_old;             /* -hls_flags delete_segments            */
    int      file_buffer_bytes;      /* setvbuf size on .ts files (0 = default) */
} hls_config_t;

typedef struct hls_writer hls_writer_t;

hls_writer_t *hls_writer_create(const hls_config_t *cfg);
void          hls_writer_destroy(hls_writer_t *w);

/* Cache the most recent PAT / PMT packet so the writer can inject them as
 * the very first 188-byte packets of every new segment — exactly what
 * libavformat/mpegtsenc.c does at segment boundaries.  `pat` / `pmt` may
 * be NULL to leave that table unchanged; the buffers, if non-NULL, must
 * be at least 188 bytes long and the caller retains ownership. */
void hls_writer_cache_psi(hls_writer_t *w, const uint8_t *pat, const uint8_t *pmt);

/*
 * Try to cut a new segment *before* writing this TS packet. Called when
 * the caller has detected that `pkt` is a video PID packet with
 * payload_unit_start_indicator=1 and random_access_indicator=1, and that
 * the elapsed PCR since the current segment start exceeds the target.
 *
 * Returns 0 on success, -1 on fatal I/O error.
 */
int hls_writer_maybe_rotate(hls_writer_t *w, uint64_t pcr_27mhz);

/* Append one 188-byte TS packet to the current segment, opening the first
 * segment on demand. */
int hls_writer_push(hls_writer_t *w, const uint8_t *pkt, uint64_t pcr_27mhz);

/* Flush + close current segment, write final playlist. */
int hls_writer_finish(hls_writer_t *w);

/* Returns 1 once the playlist has been written with at least one
 * playable segment in it (the moment a client can start playback).
 * On the first transition from 0 to 1, `out_when` (if non-NULL) is
 * filled with the CLOCK_MONOTONIC timestamp of the publish. */
int hls_writer_playable(const hls_writer_t *w, struct timespec *out_when);

#endif
