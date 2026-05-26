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

The binary has **two run modes** picked from the CLI flags:

* **Direct proxy** — `-i <src> -o <dir>` runs a single proxy session against
  one input and exits when the source EOFs (or on `SIGTERM`).
* **HTTP front-end** — `--listen-port <P>` opens a listening socket and
  dispatches every incoming HLS request to a per-channel worker that runs
  the same proxy pipeline under the hood. See
  [HTTP front-end mode](#http-front-end-mode) below.

### Direct proxy

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

## HTTP front-end mode

Instead of being pinned to one input, the proxy can act as a small HTTP
dispatcher: it listens on a port, reads the channel name from the first
segment of every request URI, lazily spawns one worker per channel that
pulls the corresponding upstream stream into `<out_root>/<channel>/`,
and replies to the client with `HTTP/1.1 302 Found` pointing at the
upstream URL (or, with `--serve-local`, at the locally-generated
playlist).

### Example

```sh
./mpegts_hls_proxy \
    --listen-port 8222 \
    --upstream-host 83.136.233.101 \
    --upstream-port 8123 \
    --out-root /var/www \
    --hls-time 4 --initial-hls-time 1.5 \
    --window 4 --delete-old \
    -L
```

A client request:

```
GET /mpolhd/index.m3u8 HTTP/1.1
Host: 83.136.233.101:8222
```

is handled as follows:

1. `mpolhd` is extracted from the path (the first `/`-separated segment).
2. If no worker exists for that channel yet, one is spawned. It runs
   `proxy_run` with
   * `input   = http://83.136.233.101:8123/mpolhd/playlist.m3u8`
   * `out_dir = /var/www/mpolhd`
   …and starts producing `stream.m3u8` + segments there.
3. The client gets back:

   ```
   HTTP/1.1 302 Found
   Location: http://83.136.233.101:8123/mpolhd/playlist.m3u8
   ```

The first request "primes" the channel; subsequent requests for
`/mpolhd/...` find the worker already running and only do the 302
redirect.

With `--serve-local --local-url-fmt "http://my.host/hls/%s/stream.m3u8"`
the response instead points at the playlist that this proxy is writing
into `<out_root>/<channel>/`.

### HTTP front-end flags

| Flag                       | Meaning                                                              |
|----------------------------|----------------------------------------------------------------------|
| `--listen-host HOST`       | bind address (default `0.0.0.0`)                                     |
| `--listen-port PORT`       | bind port — presence of this flag selects server mode                |
| `--upstream-host HOST`     | upstream IP / hostname (required in server mode)                     |
| `--upstream-port PORT`     | upstream port (required in server mode)                              |
| `--upstream-path FMT`      | `printf` format with one `%s` for channel; default `/%s/playlist.m3u8` |
| `--out-root DIR`           | per-channel `out_dir`s go inside this directory (required)           |
| `--serve-local`            | respond with the local playlist URL instead of a 302 upstream         |
| `--local-url-fmt FMT`      | `printf` format with one `%s`, used together with `--serve-local`    |

All of the proxy / latency / daemon flags above (`--hls-time`,
`--window`, `--low-latency`, `-D`, `--pid-file`, …) apply too — they are
used as the defaults for every channel worker.

### Channel-name validation

Channel names are limited to `[A-Za-z0-9._-]`, 1–64 characters, and the
literals `.` / `..` are rejected, so a malicious client cannot escape
`out_root` with a path-traversal URI.

### Concurrency model

Each channel runs in its own detached `pthread`. The dispatcher keeps a
mutex-protected linked list of `{name, thread, stop_flag}` to deduplicate
spawn requests. On `SIGTERM` the listener stops accepting and signals
every worker's stop flag; each `proxy_run` then closes its current
segment cleanly before returning.

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
