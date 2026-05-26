#include "log.h"

#include <stdarg.h>
#include <stdio.h>
#include <time.h>

int g_log_level = LOG_INFO;

void log_print(const char *level, const char *fmt, ...) {
    struct timespec ts;
    clock_gettime(CLOCK_REALTIME, &ts);
    struct tm tm;
    localtime_r(&ts.tv_sec, &tm);
    char buf[32];
    strftime(buf, sizeof(buf), "%H:%M:%S", &tm);
    fprintf(stderr, "%s.%03ld [%s] ", buf, ts.tv_nsec / 1000000, level);
    va_list ap;
    va_start(ap, fmt);
    vfprintf(stderr, fmt, ap);
    va_end(ap);
    fputc('\n', stderr);
}
