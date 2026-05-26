#ifndef MPEGTS_HLS_PROXY_LOG_H
#define MPEGTS_HLS_PROXY_LOG_H

#include <stdio.h>
#include <time.h>

enum { LOG_ERROR = 0, LOG_WARN = 1, LOG_INFO = 2, LOG_DEBUG = 3 };

extern int g_log_level;

#define LOGE(...) do { if (g_log_level >= LOG_ERROR) log_print("E", __VA_ARGS__); } while (0)
#define LOGW(...) do { if (g_log_level >= LOG_WARN)  log_print("W", __VA_ARGS__); } while (0)
#define LOGI(...) do { if (g_log_level >= LOG_INFO)  log_print("I", __VA_ARGS__); } while (0)
#define LOGD(...) do { if (g_log_level >= LOG_DEBUG) log_print("D", __VA_ARGS__); } while (0)

void log_print(const char *level, const char *fmt, ...);

#endif
