# Analysis of FFmpeg MPEG-TS → HLS Proxying Logic

This document summarises the parts of the FFmpeg codebase
(https://github.com/FFmpeg/FFmpeg) that are relevant for a "copy" / passthrough
proxy that ingests a continuous MPEG-TS feed (file or URL) and re-publishes it
as an HLS presentation (an `.m3u8` playlist + a rolling set of `.ts` segments).
The C implementation in `../src/` follows the same control flow.

## 1. Pipeline overview

In FFmpeg the command

    ffmpeg -i <input.ts|http://...> -c copy -f hls \
           -hls_time 6 -hls_list_size 5 -hls_flags delete_segments \
           out.m3u8

instantiates three components inside libavformat:

1. an **input protocol** (`libavformat/file.c`, `libavformat/http.c`,
   `libavformat/tcp.c`) that delivers a raw byte stream;
2. the **mpegts demuxer** (`libavformat/mpegts.c`) that synchronises on the
   `0x47` byte and parses PSI tables (PAT/PMT) and PES headers;
3. the **hls muxer** (`libavformat/hlsenc.c`) that decides where segment
   boundaries fall, writes each segment with the **mpegts muxer**
   (`libavformat/mpegtsenc.c`) and rewrites the playlist.

When the codec is `copy`, no decoding/encoding occurs: packets travel from the
demuxer to the muxer untouched.

## 2. The input layer (`libavformat/avio.c`, `tcp.c`, `http.c`)

* `avio_open2()` selects a protocol by URL scheme. `tcp.c` performs
  `getaddrinfo()` + `socket()` + `connect()`; `http.c` builds an HTTP/1.1
  request, sends it over the TCP `URLContext`, parses the response status and
  headers, and exposes the body as a sequential read stream.
* For a file source, `file.c` simply wraps `open()/read()`.
* `mpegts.c` then calls `avio_read()` to pull bytes one TS packet at a time
  (188 bytes for ISO/IEC 13818-1, or 192/204 for M2TS/FEC variants).

We replicate this in `src/source.c` and `src/url.c`: a `source_t` abstraction
returns the next `read()`/`recv()` chunk, transparently from a file fd or a
TCP socket opened with `getaddrinfo()` and a hand-written HTTP/1.0 GET.

## 3. Transport stream parsing (`libavformat/mpegts.c`)

Relevant fields (per ISO/IEC 13818-1):

| Offset | Field                          | Notes                                |
|--------|--------------------------------|--------------------------------------|
| 0      | `sync_byte` = 0x47             |                                      |
| 1      | `TEI`, `PUSI`, `priority`, PID | bit layout: `E P R PPPPPPPPPPPPP`    |
| 3      | `TSC`, `AFC`, `CC`             | adaptation_field_control 2 bits      |
| 4..    | adaptation field (optional)    | length + flags + PCR + …             |
| ...    | payload                        |                                      |

FFmpeg's `handle_packet()` (mpegts.c) dispatches by PID:

* PID 0 → `PAT`. We extract every `program_map_PID` and remember the first one.
* PID = PMT PID → `PMT`. For each elementary stream we store PID + stream_type.
  Stream types of interest:
  * `0x1B` H.264, `0x24` HEVC, `0x02` MPEG-2 video → video PID
  * `0x0F` AAC, `0x03/0x04` MPEG audio, `0x81` AC-3 → audio PID
* Video PID: the packet starts a PES when `payload_unit_start_indicator`
  (PUSI) = 1. The first PES with `random_access_indicator` set in the
  adaptation field (or, more strictly, an H.264 IDR / HEVC IRAP NAL inside the
  PES payload) is what `hlsenc.c` treats as a segment boundary candidate.
* Any PID may carry the **PCR** in its adaptation field. The PCR is a 33-bit
  + 9-bit value clocked at 27 MHz; FFmpeg uses the 90 kHz portion (the 33-bit
  base) as a wall-clock for segmenting (`AV_TIME_BASE_Q` conversion).

`src/ts.c` implements the same PAT/PMT walk and exposes a `ts_packet_t` with
the parsed PID, PUSI, RAI and PCR (in 90 kHz units).

## 4. Segmenting policy (`libavformat/hlsenc.c`)

The HLS muxer keeps a `HLSContext` with:

* `time` (target segment duration, `-hls_time`, default 2 s, real default for
  modern profiles is 6 s);
* `max_nb_segments` (`-hls_list_size`, default 5);
* `pl_type` (event / vod / live), `flags` (`delete_segments`,
  `independent_segments`, `append_list`, …);
* a doubly-linked list of `HLSSegment` records.

`hls_write_packet()` is the heart of the muxer. The simplified decision in
`can_split` / `find_segment_by_filename` / `hls_start` is:

```c
if (pkt->stream_index == vs->reference_stream_index &&
    (pkt->flags & AV_PKT_FLAG_KEY) &&
    ((pkt->pts - vs->start_pts) * av_q2d(st->time_base)) >= hls->time) {
    /* close current segment, open new one */
    new_start_pos = avio_tell(vs->out);
    av_write_frame(oc, NULL);   /* flush mpegts muxer */
    hls_window(s, 0, vs);        /* rewrite playlist */
    hls_start(s, vs);            /* open next .ts file */
}
```

Two things matter:

1. **Cut on a key frame.** The mpegts muxer emits a fresh PAT/PMT at the
   start of every segment so each `.ts` is independently decodable.
2. **Cut when the elapsed PTS (or PCR) exceeds the target duration.**
   The actual duration of the closed segment is `last_pts - start_pts`.

For a pure passthrough proxy we do not need libavcodec's notion of
`AV_PKT_FLAG_KEY`: it is sufficient to look at the TS packet's
`random_access_indicator`, which is exactly what the mpegts muxer sets when
re-segmenting a copy stream.  `src/hls.c` follows that strategy.

## 5. Playlist generation and the sliding window (`hls_window()` in hlsenc.c)

For a LIVE / sliding window playlist FFmpeg writes:

```
#EXTM3U
#EXT-X-VERSION:3
#EXT-X-TARGETDURATION:<ceil(max segment duration)>
#EXT-X-MEDIA-SEQUENCE:<seq of first listed segment>
#EXT-X-DISCONTINUITY-SEQUENCE:<...>
#EXTINF:<duration>,
seg00042.ts
#EXTINF:<duration>,
seg00043.ts
...
```

When `hls->nb_segments > hls->max_nb_segments`, FFmpeg pops segments from the
head of the list, increments `media_sequence`, and (with
`HLS_DELETE_SEGMENTS`) `unlink(2)`s the file.  When the muxer is closed
cleanly an `#EXT-X-ENDLIST` is appended (for VOD); for a live proxy that line
is omitted.

The playlist is written **atomically** by FFmpeg: it is first written to
`out.m3u8.tmp` and then `rename(2)`d over `out.m3u8`. We do the same.

## 6. Mapping to this project

| FFmpeg piece                              | This project                |
|------------------------------------------|-----------------------------|
| `file.c`, `tcp.c`, `http.c`              | `src/source.c`, `src/url.c` |
| `mpegts.c` (demuxer, PAT/PMT, PCR)       | `src/ts.c`                  |
| `hlsenc.c` (segmenting, playlist window) | `src/hls.c`                 |
| `mpegtsenc.c` (writing PAT/PMT/payload)  | Not needed — we passthrough |
| `ffmpeg.c` (CLI glue)                    | `src/main.c`                |

We deliberately do not re-multiplex.  FFmpeg's mpegts muxer rebuilds PAT/PMT
at the start of every segment; we instead require that the source already
emits PAT/PMT periodically (every broadcaster does) and we cut the stream
**only on TS packet boundaries**, so that each segment starts with a TS
packet whose PUSI is set on the video PID and whose adaptation field carries
`random_access_indicator = 1`. That matches what FFmpeg writes when it
copies, byte for byte.
