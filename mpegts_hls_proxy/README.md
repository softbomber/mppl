# mpegts_hls_proxy

A small C99 tool that proxies an MPEG-TS feed into an HLS presentation
(`.m3u8` + `.ts` segments). It is a passthrough — packets are not
re-encoded or re-multiplexed — and it follows the same control flow as
FFmpeg's `ffmpeg -i <src> -c copy -f hls ...` pipeline.

## Source-side support

* **Local file** — `--input /path/to/stream.ts`
* **HTTP URL** — `--input http://host[:port]/path`. The HTTP/1.0 client
  is hand-written on top of `getaddrinfo()` / `socket()` / `connect()` /
  `send()` / `recv()`. No libcurl, no other dependencies.

`https://` is intentionally not supported; adding it would require
linking an SSL library and is not part of FFmpeg's `tcp.c` either —
that is what FFmpeg's `tls_*.c` wrappers do.

## Build

```sh
make
```

Produces `./mpegts_hls_proxy`.

## Usage

```sh
./mpegts_hls_proxy \
    -i http://example.com/live/stream.ts \
    -o ./hls_out \
    --hls-time 6 \
    --window 5 \
    --delete-old
```

| Flag                       | Meaning                                                              |
|----------------------------|----------------------------------------------------------------------|
| `-i, --input`              | source: `.ts` file or `http://` URL                                  |
| `-o, --out`                | output directory (created if missing)                                |
| `-n, --playlist`           | playlist filename (default `stream.m3u8`)                            |
| `-p, --prefix`             | segment name prefix (default `seg` → `seg00001.ts`)                  |
| `-t, --hls-time`           | steady-state target segment duration in seconds (default `6`)        |
| `--initial-hls-time`       | target for the **first** segment (default `max(hls-time/3, 1.0)`)    |
| `-w, --window`             | sliding-window size in segments, `0` = VOD (default `5`)             |
| `-d, --delete-old`         | unlink evicted segment files                                         |
| `-L, --low-latency`        | enable `--wait-rai` and shrink `--initial-hls-time` (≤ 2 s)          |
| `--wait-rai`               | drop packets until the first video random-access indicator           |
| `--read-buf-size N`        | source read buffer in bytes (default 65536)                          |
| `--file-buf-size N`        | per-segment file I/O buffer in bytes (default 262144)                |
| `-D, --daemon`             | detach from terminal (double fork, setsid, chdir /)                  |
| `--pid-file PATH`          | write PID to file after daemonising                                  |
| `--log-file PATH`          | redirect stderr to this log file                                     |
| `-v, --verbose`            | enable DEBUG log level                                               |

A `SIGINT`/`SIGTERM` triggers a clean shutdown: the current segment is
closed and the playlist is rewritten with the final list of segments.

### Low-latency tips

For the fastest time-to-first-frame on the client side:

```sh
./mpegts_hls_proxy \
    -i http://host/live.ts \
    -o /var/www/hls -D \
    --low-latency \
    --hls-time 4 \
    --initial-hls-time 1.5 \
    --window 4 --delete-old \
    --pid-file /run/mpegts_hls_proxy.pid \
    --log-file /var/log/mpegts_hls_proxy.log
```

`--low-latency` does three things at once:
1. Waits for the first video RAI before opening segment 0 so the player
   never has to skip undecodable bytes.
2. Caches the most recent PAT and PMT packets and replays them as the
   very first bytes of segment 0, so the player gets PSI without
   waiting for the next PSI cycle.
3. Caps the *first* segment at ~1–2 s independently of `--hls-time`.

In addition to the CLI flags above, the binary always:
* Sets `SO_RCVBUF = 1 MiB` and `TCP_NODELAY` on the upstream socket so
  the GET leaves immediately and TCP bursts arrive in one `recv()`.
* Sets a 256 KB `setvbuf` buffer on each segment file, so 188-byte
  `fwrite()`s coalesce into a few large `write(2)` syscalls.
* Writes the playlist via `tmp + rename(2)`, so clients never observe
  a half-written `.m3u8`.

### Startup timer

A `CLOCK_MONOTONIC` timer starts in `main()` and is logged the instant
the playlist contains its first playable segment, e.g.:

```
12:04:21.137 [I] playlist ready in 1.482 s — clients can start playback now
```

That value is the lower bound on time-to-first-frame: every additional
second is on the client / network side.

## Mapping to FFmpeg's code

See `docs/ffmpeg_analysis.md` for the detailed analysis. Quick mapping:

| Concept                                         | FFmpeg file              | This project    |
|-------------------------------------------------|--------------------------|-----------------|
| Open source / TCP / HTTP                        | `file.c`, `tcp.c`, `http.c` | `src/source.c`, `src/url.c` |
| Sync to 0x47, parse PAT/PMT, track PCR          | `mpegts.c`               | `src/ts.c`      |
| Segment on key-frame at target duration         | `hlsenc.c`               | `src/hls.c`     |
| Sliding window + delete old segments            | `hls_window()`           | `src/hls.c`     |
| CLI                                             | `ffmpeg.c`               | `src/main.c`    |

## Tests / sanity checks

The output can be played by any HLS-capable client:

```sh
ffplay ./hls_out/stream.m3u8
# or
vlc ./hls_out/stream.m3u8
```

Or validated:

```sh
ffmpeg -i ./hls_out/stream.m3u8 -c copy -f null -
```

## Limitations (on purpose)

* HTTPS is not supported (would need OpenSSL/mbedTLS).
* HTTP/1.0 only — no chunked transfer-encoding or keep-alive needed
  because we read until the server closes the connection.
* HTTP redirects are reported as an error rather than followed.
* Only the first program in the PAT and the first H.264/HEVC/MPEG-2
  video PID inside it are tracked.
* Segments are cut on packets that carry `random_access_indicator = 1`
  in the TS adaptation field. Sources whose video is keyframed but
  whose mux does not set RAI need an upstream remuxer first — FFmpeg's
  `mpegts.c` has the same behaviour when copying without
  `-mpegts_flags initial_discontinuity`.
