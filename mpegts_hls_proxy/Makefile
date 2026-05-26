CC      ?= cc
CFLAGS  ?= -O2 -std=c99 -Wall -Wextra -Wpedantic -D_POSIX_C_SOURCE=200809L
LDFLAGS ?=
LDLIBS  ?= -lm

SRCS := src/main.c src/source.c src/url.c src/ts.c src/hls.c src/log.c
OBJS := $(SRCS:.c=.o)
BIN  := mpegts_hls_proxy

.PHONY: all clean
all: $(BIN)

$(BIN): $(OBJS)
	$(CC) $(CFLAGS) $(LDFLAGS) -o $@ $(OBJS) $(LDLIBS)

%.o: %.c
	$(CC) $(CFLAGS) -c -o $@ $<

clean:
	rm -f $(OBJS) $(BIN)
