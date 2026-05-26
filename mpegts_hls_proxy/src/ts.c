#include "ts.h"
#include "log.h"

#include <string.h>

/*
 * 188-byte TS packet, ISO/IEC 13818-1:
 *   byte 0      : 0x47 sync
 *   byte 1..2   : TEI(1) PUSI(1) PRI(1) PID(13)
 *   byte 3      : TSC(2) AFC(2) CC(4)
 *   byte 4..    : adaptation field (if AFC & 0x2) / payload (if AFC & 0x1)
 *
 * Adaptation field layout when present:
 *   AF length (1 byte)  -- bytes that follow, max 183
 *   flags (1 byte)      -- discontinuity(1) random_access(1) ES_priority(1)
 *                          PCR(1) OPCR(1) splicing(1) priv_data(1) extension(1)
 *   PCR (6 bytes, if PCR flag): 33-bit base | 6 reserved | 9-bit ext
 */
void ts_packet_parse(const uint8_t *buf, ts_packet_t *out) {
    memset(out, 0, sizeof(*out));
    if (buf[0] != TS_SYNC_BYTE) {
        out->sync_ok = 0;
        return;
    }
    out->sync_ok = 1;
    out->pusi = (buf[1] >> 6) & 0x01;
    out->pid  = ((uint16_t)(buf[1] & 0x1F) << 8) | buf[2];

    uint8_t afc = (buf[3] >> 4) & 0x03;
    out->has_adaptation = (afc & 0x2) != 0;
    out->has_payload    = (afc & 0x1) != 0;

    if (!out->has_adaptation) return;
    uint8_t af_len = buf[4];
    if (af_len == 0) return;
    if (4 + 1 + af_len > TS_PACKET_SIZE) return; /* malformed */

    uint8_t flags = buf[5];
    out->random_access_indicator = (flags >> 6) & 0x01;
    if ((flags & 0x10) && af_len >= 7) {
        /* PCR present (flags bit "PCR_flag" = 0x10). */
        uint64_t base =
            ((uint64_t)buf[6] << 25) |
            ((uint64_t)buf[7] << 17) |
            ((uint64_t)buf[8] << 9)  |
            ((uint64_t)buf[9] << 1)  |
            ((uint64_t)(buf[10] >> 7) & 0x1);
        uint64_t ext =
            (((uint64_t)buf[10] & 0x01) << 8) | buf[11];
        out->pcr_27mhz = base * 300 + ext;
        out->pcr_valid = 1;
    }
}

void ts_program_init(ts_program_t *p) {
    memset(p, 0, sizeof(*p));
}

/* Walk a PAT section. We deliberately only look at "section 0" — the common
 * case for live feeds — and pick the first program. This is what FFmpeg's
 * mpegts.c does when no specific program is requested. */
static int parse_pat(ts_program_t *p, const uint8_t *payload, int len) {
    if (len < 9) return 0;
    /* Skip pointer_field. */
    int ptr = payload[0];
    const uint8_t *sec = payload + 1 + ptr;
    int avail = len - 1 - ptr;
    if (avail < 12) return 0;
    if (sec[0] != 0x00) return 0;            /* table_id PAT = 0x00 */
    int section_len = ((sec[1] & 0x0F) << 8) | sec[2];
    if (section_len < 9 || section_len + 3 > avail) return 0;
    int prog_count = (section_len - 5 - 4) / 4;
    for (int i = 0; i < prog_count; i++) {
        const uint8_t *e = sec + 8 + i * 4;
        uint16_t prog_num = ((uint16_t)e[0] << 8) | e[1];
        uint16_t pid = ((uint16_t)(e[2] & 0x1F) << 8) | e[3];
        if (prog_num == 0) continue;          /* network PID, not a program */
        if (!p->have_pmt_pid) {
            p->have_pmt_pid = 1;
            p->pmt_pid = pid;
            LOGI("PAT: program %u -> PMT pid 0x%04x", prog_num, pid);
            return 1;
        }
    }
    return 0;
}

static int video_priority(uint8_t st) {
    /* Match the order FFmpeg's mpegts.c uses when picking a "best" video
     * elementary stream. */
    switch (st) {
        case ST_VIDEO_HEVC:  return 3;
        case ST_VIDEO_H264:  return 2;
        case ST_VIDEO_MPEG2: return 1;
        default:             return 0;
    }
}

static int parse_pmt(ts_program_t *p, const uint8_t *payload, int len) {
    if (len < 13) return 0;
    int ptr = payload[0];
    const uint8_t *sec = payload + 1 + ptr;
    int avail = len - 1 - ptr;
    if (avail < 16) return 0;
    if (sec[0] != 0x02) return 0;            /* table_id PMT = 0x02 */
    int section_len = ((sec[1] & 0x0F) << 8) | sec[2];
    if (section_len + 3 > avail) return 0;

    uint16_t pcr_pid = ((uint16_t)(sec[8] & 0x1F) << 8) | sec[9];
    int prog_info_len = ((sec[10] & 0x0F) << 8) | sec[11];
    const uint8_t *es = sec + 12 + prog_info_len;
    const uint8_t *end = sec + 3 + section_len - 4;    /* exclude CRC32 */
    if (es > end) return 0;

    int updated = 0;
    if (!p->have_pcr || p->pcr_pid != pcr_pid) {
        p->have_pcr = 1;
        p->pcr_pid = pcr_pid;
        LOGI("PMT: PCR pid 0x%04x", pcr_pid);
        updated = 1;
    }

    int best = p->have_video ? video_priority(p->video_stream_type) : 0;
    while (es + 5 <= end) {
        uint8_t  stream_type = es[0];
        uint16_t epid        = ((uint16_t)(es[1] & 0x1F) << 8) | es[2];
        int      es_info_len = ((es[3] & 0x0F) << 8) | es[4];
        int prio = video_priority(stream_type);
        if (prio > best) {
            best = prio;
            p->have_video = 1;
            p->video_pid = epid;
            p->video_stream_type = stream_type;
            LOGI("PMT: video pid 0x%04x stream_type 0x%02x", epid, stream_type);
            updated = 1;
        }
        es += 5 + es_info_len;
    }
    return updated;
}

int ts_program_feed(ts_program_t *p, const uint8_t *pkt, const ts_packet_t *parsed) {
    if (!parsed->sync_ok || !parsed->has_payload) return 0;
    if (parsed->pid != TS_PID_PAT && !(p->have_pmt_pid && parsed->pid == p->pmt_pid))
        return 0;
    if (!parsed->pusi) return 0;     /* we only handle section starts */

    /* Compute payload offset. */
    int off = 4;
    if (parsed->has_adaptation) {
        int af_len = pkt[4];
        off += 1 + af_len;
    }
    if (off >= TS_PACKET_SIZE) return 0;
    int len = TS_PACKET_SIZE - off;
    const uint8_t *payload = pkt + off;

    if (parsed->pid == TS_PID_PAT)
        return parse_pat(p, payload, len);
    return parse_pmt(p, payload, len);
}
