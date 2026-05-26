#ifndef MPEGTS_HLS_PROXY_TS_H
#define MPEGTS_HLS_PROXY_TS_H

#include <stdint.h>

#define TS_PACKET_SIZE 188
#define TS_SYNC_BYTE   0x47

#define TS_PID_PAT     0x0000
#define TS_PID_NULL    0x1FFF

/* Stream types we care about for picking the video PID, taken from
 * libavformat/mpegts.c. The first match wins. */
#define ST_VIDEO_MPEG2  0x02
#define ST_VIDEO_H264   0x1B
#define ST_VIDEO_HEVC   0x24

/*
 * Decoded view of a single 188-byte TS packet. `pcr_valid` is set when the
 * adaptation field actually carried a PCR; `pcr_27mhz` is the full 27 MHz
 * count (33-bit base * 300 + 9-bit extension) per ISO/IEC 13818-1 §2.4.3.5,
 * which is the same units libavformat uses internally before converting
 * to AV_TIME_BASE.
 */
typedef struct {
    int       sync_ok;
    int       pusi;                 /* payload_unit_start_indicator   */
    uint16_t  pid;
    int       has_adaptation;
    int       has_payload;
    int       random_access_indicator;
    int       pcr_valid;
    uint64_t  pcr_27mhz;
} ts_packet_t;

/* Decode the 4-byte TS header (and adaptation field if present). The packet
 * must be exactly 188 bytes; on a missing sync byte `sync_ok` is 0 and the
 * caller is expected to re-sync. */
void ts_packet_parse(const uint8_t *buf, ts_packet_t *out);

/*
 * Tracker for PAT/PMT parsing across a feed. Designed to be incrementally
 * fed every packet we pull from the source — matches what mpegts.c does in
 * handle_packet().
 */
typedef struct {
    int       have_pmt_pid;
    uint16_t  pmt_pid;
    int       have_video;
    uint16_t  video_pid;
    uint8_t   video_stream_type;
    int       have_pcr;
    uint16_t  pcr_pid;
} ts_program_t;

void ts_program_init(ts_program_t *p);

/* Feed one packet to the PSI parser. Safe to call for every packet — only
 * PAT/PMT PIDs are inspected. Returns 1 if program info was updated. */
int  ts_program_feed(ts_program_t *p, const uint8_t *pkt, const ts_packet_t *parsed);

#endif
