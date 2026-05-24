//новый алго удаления сегментов
#define ARCHIVE 1

// === LOGGING CONFIGURATION ===
// Закомментируйте следующую строку, чтобы отключить запись в общий hls_proxy.log
// (останется только per-channel лог через freopen stdout/stderr)
//#define ENABLE_GLOBAL_LOG_FILE

// Максимальный размер лог-файла перед ротацией (в байтах). По умолчанию 50 MB.
#define LOG_MAX_SIZE (10 * 1024 * 1024)
// Сколько ротированных архивов хранить (log.1.gz, log.2.gz, ... log.N.gz)
#define LOG_MAX_ROTATED_FILES 3
#include <poll.h>
#include <iostream>
#include <string>
#include <vector>
#include <map>
#include <unordered_map>
#include <set>
#include <thread>
#include <mutex>
#include <chrono>
#include <fstream>
#include <sstream>
#include <filesystem>
#include <algorithm>
#include <cmath>
#include <atomic>
#include <future>
#include <queue>
#include <functional>
#include <memory>
#include <stdexcept>
#include <cstdlib>
#include <cstdio>
#include <cstring>
#include <ctime>
#include <csignal>
#include <iomanip>
#include <regex>
#include <random>
#include <shared_mutex>
#include <unordered_set>
#include <openssl/evp.h>
#include <openssl/err.h>

// Network libraries
#include <curl/curl.h>
#include <sys/socket.h>
#include <netinet/in.h>
#include <arpa/inet.h>
#include <unistd.h>
#include <fcntl.h>
#include <ifaddrs.h>
#include <sys/stat.h>
#include <pwd.h>
#include <grp.h>
#include <sys/wait.h>
#include <netinet/tcp.h>
#include <sys/mman.h>       // mmap, munmap, msync for zero-copy writes

// JSON library
#include <jsoncpp/json/json.h>

// Redis library
#include <hiredis/hiredis.h>

#include <netdb.h>      // Для gethostbyname
#include <deque>        // Для очереди сегментов
#include <sys/time.h>   // Для таймаутов

namespace fs = std::filesystem;
using namespace std::chrono_literals;

// === Constants ===
const std::string BASE_DIR = "/var/www";
const int TIMEOUT = 10;
const size_t CHUNK_SIZE = 3 * 512L * 1024L; //1048576;//524288; //262144;//7168000;
const std::string USER_AGENT = "MyCustomUserAgent/1.0";
//const int CLEANUP_INTERVAL = 10;
const int MAX_PROCESSES_PER_IP = 2;
const std::string IPV6_MAPPING_FILE = "/tmp/ipv6_channel_mapping.json";
// === НОВЫЕ КОНСТАНТЫ И ПУТИ ===
const std::string PEERS_TOKEN_FILE = "/tmp/peers_tv_tokens.json";
const std::string PEERS_REFRESH_LOCK = "/tmp/peers_tv_refresh.lock";
static std::string g_peers_tv_cached_token;
static std::shared_mutex g_peers_token_mutex;
static std::atomic<bool> is_first_playlist{true};
static std::mutex first_playlist_mutex;
static const std::regex id_regex(R"(-channel/(\d+))");
static const std::regex fallback_regex(R"((\d+))");

// === НОВЫЕ КОНСТАНТЫ ДЛЯ УПРАВЛЕНИЯ ПОВЕДЕНИЕМ ===
enum class PlaylistWriteMode {
    IMMEDIATE,           // Писать сразу, до загрузки сегментов
    AFTER_FIRST,         // Писать после загрузки первого сегмента
    AFTER_N_SEGMENTS,    // Писать после N сегментов
    VALIDATED            // Писать только проверенные файлы
};

static PlaylistWriteMode g_playlist_write_mode = PlaylistWriteMode::IMMEDIATE;
static int g_min_segments_before_write = 3;
static std::string g_audio_lang = "rus"; // Язык аудио дорожки по умолчанию (rus, eng, "" = без аудио)

// === КЭШ ЗАГРУЖЕННЫХ ФАЙЛОВ В ПАМЯТИ ===
struct SegmentCache {
    std::unordered_set<std::string> downloaded;
    std::shared_mutex mutex;

    void mark_downloaded(const std::string& fname) {
        std::unique_lock lock(mutex);
        downloaded.insert(fname);
    }

    bool is_downloaded(const std::string& fname) {
        std::shared_lock lock(mutex);
        return downloaded.count(fname) > 0;
    }

    void cleanup(const std::set<std::string>& active_files) {
        std::unique_lock lock(mutex);
        auto it = downloaded.begin();
        while (it != downloaded.end()) {
            if (!active_files.count(*it)) {
                it = downloaded.erase(it);
            } else {
                ++it;
            }
        }
    }

    void clear() {
        std::unique_lock lock(mutex);
        downloaded.clear();
    }

    size_t count() {
        std::shared_lock lock(mutex);
        return downloaded.size();
    }
};

static std::unordered_map<std::string, SegmentCache> g_channel_caches;
static std::shared_mutex g_channel_caches_mutex;


// ==========================================
// >>> 1. НОВАЯ СТРУКТУРА СОСТОЯНИЯ КАНАЛА
// ==========================================
struct ChannelState {
    long long local_sequence = 0;       // Наш "исправленный" счетчик
    long long last_upstream_seq = -1;   // То, что прислал провайдер в прошлый раз
    std::string last_first_uri;         // Первый файл в прошлом плейлисте
    size_t last_size = 0;
    bool initialized = false;
    int freeze_counter = 0;
};

static std::mutex g_state_mutex;
static std::unordered_map<std::string, ChannelState> g_channel_states;


// ============================================================================
// OPTIMIZED AES-128-CBC WITH OPENSSL (Hardware Acceleration)
// ============================================================================

class Aes128 {
private:
    EVP_CIPHER_CTX* ctx;
    bool initialized;

public:
    Aes128(const uint8_t* key, const uint8_t* iv) : initialized(false) {
        ctx = EVP_CIPHER_CTX_new();
        if (!ctx) {
            std::cerr << "AES: Failed to create context" << std::endl;
            return;
        }

        if (EVP_DecryptInit_ex(ctx, EVP_aes_128_cbc(), NULL, key, iv) != 1) {
            std::cerr << "AES: Failed to initialize decryption" << std::endl;
            EVP_CIPHER_CTX_free(ctx);
            ctx = nullptr;
            return;
        }

        EVP_CIPHER_CTX_set_padding(ctx, 0);
        initialized = true;
        // AES инициализирован (логирование отключено чтобы не засорять логи)
    }

    void decrypt_cbc(uint8_t* buffer, size_t length) {
        if (!initialized || !ctx) return;
        int len;
        if (EVP_DecryptUpdate(ctx, buffer, &len, buffer, length) != 1) {
            std::cerr << "AES: Decryption failed" << std::endl;
        }
    }

    ~Aes128() {
        if (ctx) EVP_CIPHER_CTX_free(ctx);
    }
};

// === Decryption Structures ===
struct EncState {
    std::string key_uri;
    std::vector<uint8_t> iv;
    bool active = false;
};

// Global cache for AES keys: KeyURI -> Binary Key (16 bytes)
static std::shared_mutex g_key_cache_mutex;
static std::unordered_map<std::string, std::vector<uint8_t>> g_key_cache;

// Helper to convert HEX string to binary
std::vector<uint8_t> hex_to_bin(const std::string& hex) {
    std::vector<uint8_t> bin;
    size_t start = (hex.length() >= 2 && hex[1] == 'x') ? 2 : 0;
    for (size_t i = start; i < hex.length(); i += 2) {
        std::string byteString = hex.substr(i, 2);
        bin.push_back((uint8_t)strtol(byteString.c_str(), nullptr, 16));
    }
    return bin;
}

// Helper to create IV from sequence number (Big Endian, padded to 16 bytes)
std::vector<uint8_t> seq_to_iv(long long seq) {
    std::vector<uint8_t> iv(16, 0);
    for (int i = 15; i >= 8; --i) {
        iv[i] = seq & 0xFF;
        seq >>= 8;
    }
    return iv;
}

// === ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ДЛЯ РАБОТЫ С ТОКЕНАМИ PEERS.TV ===

struct PeersTokens {
    std::string access_token;
    std::string refresh_token;
    std::chrono::system_clock::time_point saved_at;
    
    PeersTokens() : saved_at(std::chrono::system_clock::now()) {}
};

// === SPUTNIK24.TV: кэш в памяти ===
struct SputnikCacheEntry {
    std::string channel_source;
    long expire_date;
    std::chrono::steady_clock::time_point last_check;
};

static std::shared_mutex g_sputnik_cache_mutex;
static std::unordered_map<std::string, SputnikCacheEntry> g_sputnik_cache;  // key = channel_id

// === Global variables ===
/*struct SegmentInfo {
    std::string file_path;
    std::chrono::steady_clock::time_point expire_time;
    double cumulative_duration;

    // Конструктор по умолчанию
    SegmentInfo() : cumulative_duration(0.0) {}

    // Конструктор для (path, cumulative_duration)
    SegmentInfo(const std::string& path, double cum_dur)
        : file_path(path), cumulative_duration(cum_dur) {
        expire_time = std::chrono::steady_clock::now() +
                      std::chrono::duration_cast<std::chrono::steady_clock::duration>(
                          std::chrono::duration<double>(cum_dur + 3.0));
    }

    // НОВЫЙ: конструктор для (path, time_point)
    SegmentInfo(const std::string& path, std::chrono::steady_clock::time_point tp)
        : file_path(path), expire_time(tp), cumulative_duration(0) {}
};*/

// Forward declaration
class HttpRequestSession;

//std::vector<SegmentInfo> downloaded_segments;
//std::mutex segments_mutex;
std::atomic<bool> redis_message_sent{false};
//std::atomic<bool> stop_cleanup_event{false};

// Глобальный флаг
std::atomic<bool> g_keep_running{true};

// === FAST ZAPPING: HOT SWITCH ===
struct SwitchCommand {
    std::string channel;
    std::string source_url;
    std::string save_dir;
    int slot;
    std::string token;
    std::string provider;
    int64_t allocated_at;
};

static std::atomic<bool> g_hot_switch_requested{false};
static std::mutex g_switch_queue_mutex;
static std::queue<SwitchCommand> g_switch_queue;      // token из SWITCH-сообщения
// === ТРИГГЕРЫ ДЛЯ МГНОВЕННОГО ПРЕРЫВАНИЯ СНА ===
static std::mutex g_sleep_mutex;
static std::condition_variable g_sleep_cv;

// === FAST ZAPPING: Текущий канал для subscriber (обновляется при HOT SWITCH) ===
static std::mutex g_subscriber_channel_mutex;
static std::string g_subscriber_channel;
static std::string g_subscriber_provider;
static int g_subscriber_slot = -1;

// === FAST ZAPPING: Global last_access timers (instead of static in function) ===
static std::chrono::steady_clock::time_point g_last_check_time;
static std::chrono::steady_clock::time_point g_monitoring_start_time;
static std::chrono::steady_clock::time_point g_playlist_detected_time;

// === Уровни логирования ===
enum class LogLevel {
    DEBUG = 0,    // Детальная отладка (по умолчанию выключено)
    INFO = 1,     // Важные события (старт/стоп, смена источника)
    STATS = 2,    // Статистика (скорость загрузки, время сегментов)
    WARNING = 3,  // Предупреждения (повторы, таймауты)
    ERROR = 4     // Ошибки (критичные проблемы)
};

// Глобальный уровень логирования (можно менять через аргументы)
static LogLevel g_log_level = LogLevel::INFO;

// === Оптимизированный Logger с ротацией и поддержкой hot switch ===
class Logger {
private:
    std::mutex log_mutex;
    std::ofstream file_stream;
    std::string current_file_path;
    LogLevel min_level;
    size_t bytes_written = 0;

    // Кэш для уменьшения вызовов localtime
    std::chrono::system_clock::time_point last_time_cache;
    char cached_timestamp[32];

    const char* level_to_string(LogLevel level) {
        switch (level) {
            case LogLevel::DEBUG: return "DEBUG";
            case LogLevel::INFO: return "INFO";
            case LogLevel::STATS: return "STATS";
            case LogLevel::WARNING: return "WARN";
            case LogLevel::ERROR: return "ERROR";
            default: return "UNKNOWN";
        }
    }

    // Ротация: текущий лог → .1.gz, .1.gz → .2.gz, ... удаление самого старого
    void rotate_if_needed() {
        if (current_file_path.empty()) return;
        if (bytes_written < LOG_MAX_SIZE) return;

        file_stream.flush();
        file_stream.close();

        // Удаляем самый старый архив
        std::string oldest = current_file_path + "." + std::to_string(LOG_MAX_ROTATED_FILES) + ".gz";
        std::remove(oldest.c_str());

        // Сдвигаем: N-1 → N, N-2 → N-1, ...
        for (int i = LOG_MAX_ROTATED_FILES - 1; i >= 1; --i) {
            std::string src = current_file_path + "." + std::to_string(i) + ".gz";
            std::string dst = current_file_path + "." + std::to_string(i + 1) + ".gz";
            std::rename(src.c_str(), dst.c_str());
        }

        // Сжимаем текущий файл → .1.gz
        std::string gz_path = current_file_path + ".1.gz";
        std::string cmd = "gzip -c " + current_file_path + " > " + gz_path + " 2>/dev/null";
        int rc = system(cmd.c_str());
        if (rc != 0) {
            // gzip не удался — просто переименовываем без сжатия
            std::rename(current_file_path.c_str(), (current_file_path + ".1").c_str());
        }

        // Очищаем текущий файл и переоткрываем
        file_stream.open(current_file_path, std::ios::trunc);
        bytes_written = 0;
    }

    void log_internal(LogLevel level, const std::string& msg, bool force_flush = false) {
        if (level < min_level) return;

        std::lock_guard<std::mutex> lock(log_mutex);
        auto now = std::chrono::system_clock::now();

        // Кэшируем timestamp (обновляем только раз в секунду)
        if (now - last_time_cache > std::chrono::seconds(1)) {
            auto time_t = std::chrono::system_clock::to_time_t(now);
            std::tm tm;
            localtime_r(&time_t, &tm);
            std::strftime(cached_timestamp, sizeof(cached_timestamp), "%Y-%m-%d %H:%M:%S", &tm);
            last_time_cache = now;
        }

        std::string log_msg = std::string(cached_timestamp) + " [" + level_to_string(level) + "] " + msg;

        // Выводим в консоль (stdout/stderr перенаправлены в лог-файл через freopen)
        if (level >= LogLevel::ERROR) {
            std::cerr << log_msg << std::endl;
        } else {
            std::cout << log_msg << std::endl;
        }

#ifdef ENABLE_GLOBAL_LOG_FILE
        // Пишем в файл (отключается через #define ENABLE_GLOBAL_LOG_FILE)
        if (file_stream.is_open()) {
            file_stream << log_msg << "\n";
            bytes_written += log_msg.size() + 1;
            if (force_flush || level >= LogLevel::ERROR) {
                file_stream.flush();
            }
            rotate_if_needed();
        }
#endif
    }

public:
    Logger(const std::string& log_file = "/var/log/cgi/hls_proxy.log", LogLevel level = LogLevel::INFO)
        : min_level(level),
          last_time_cache(std::chrono::system_clock::now()) {
        auto time_t = std::chrono::system_clock::to_time_t(last_time_cache);
        std::tm tm;
        localtime_r(&time_t, &tm);
        std::strftime(cached_timestamp, sizeof(cached_timestamp), "%Y-%m-%d %H:%M:%S", &tm);
#ifdef ENABLE_GLOBAL_LOG_FILE
        current_file_path = log_file;
        file_stream.open(log_file, std::ios::app);
        // Подсчитываем текущий размер файла для корректной ротации
        if (file_stream.is_open()) {
            file_stream.seekp(0, std::ios::end);
            bytes_written = static_cast<size_t>(file_stream.tellp());
        }
#endif
    }

    void set_level(LogLevel level) { min_level = level; }

    // Переоткрытие лог-файла (для hot switch — смена канала)
    void reopen(const std::string& new_path) {
        std::lock_guard<std::mutex> lock(log_mutex);
#ifdef ENABLE_GLOBAL_LOG_FILE
        if (file_stream.is_open()) file_stream.close();
        current_file_path = new_path;
        file_stream.open(new_path, std::ios::app);
        if (file_stream.is_open()) {
            file_stream.seekp(0, std::ios::end);
            bytes_written = static_cast<size_t>(file_stream.tellp());
        } else {
            bytes_written = 0;
        }
#endif
    }

    // Принудительная ротация (можно вызвать из SIGUSR1, например)
    void force_rotate() {
        std::lock_guard<std::mutex> lock(log_mutex);
        bytes_written = LOG_MAX_SIZE; // Триггерим ротацию
        rotate_if_needed();
    }

    // Потокобезопасное перенаправление stdout/stderr в новый файл.
    // Выполняется под log_mutex, чтобы другие потоки не писали в stdout/stderr
    // во время freopen (freopen НЕ потокобезопасен).
    void redirect_output(const std::string& new_log_path) {
        std::lock_guard<std::mutex> lock(log_mutex);
        freopen(new_log_path.c_str(), "a", stdout);
        freopen(new_log_path.c_str(), "a", stderr);
        setvbuf(stdout, nullptr, _IOLBF, 1024);
        setvbuf(stderr, nullptr, _IOLBF, 1024);
    }

    void debug(const std::string& msg) { log_internal(LogLevel::DEBUG, msg); }
    void info(const std::string& msg) { log_internal(LogLevel::INFO, msg); }
    void warning(const std::string& msg) { log_internal(LogLevel::WARNING, msg); }
    void error(const std::string& msg) { log_internal(LogLevel::ERROR, msg, true); }

    void stats_segment(const std::string& filename, double download_time_ms, size_t bytes, bool cached = false) {
        if (min_level > LogLevel::STATS) return;

        double speed_mbps = (bytes * 8.0 / 1000000.0) / (download_time_ms / 1000.0);

        std::ostringstream oss;
        oss << "SEG " << filename
            << " | " << std::fixed << std::setprecision(0) << download_time_ms << "ms"
            << " | " << std::fixed << std::setprecision(2) << (bytes / 1024.0 / 1024.0) << "MB"
            << " | " << std::fixed << std::setprecision(1) << speed_mbps << "Mbps";

        if (cached) oss << " [CACHED]";

        log_internal(LogLevel::STATS, oss.str());
    }

    void stats_summary(int total_segments, double total_time_sec, size_t total_bytes, int errors) {
        if (min_level > LogLevel::STATS) return;

        double avg_speed_mbps = (total_bytes * 8.0 / 1000000.0) / total_time_sec;

        std::ostringstream oss;
        oss << "SUMMARY: " << total_segments << " segments"
            << " | " << std::fixed << std::setprecision(1) << total_time_sec << "s total"
            << " | " << std::fixed << std::setprecision(2) << (total_bytes / 1024.0 / 1024.0) << "MB"
            << " | avg " << std::fixed << std::setprecision(1) << avg_speed_mbps << "Mbps";

        if (errors > 0) oss << " | " << errors << " errors";

        log_internal(LogLevel::INFO, oss.str());
    }

    void event(const std::string& event_type, const std::string& details) {
        log_internal(LogLevel::INFO, event_type + ": " + details);
    }
};

Logger logger("/var/log/cgi/hls_proxy.log", g_log_level);

static std::vector<std::pair<std::string, pid_t>>* g_active_processes = nullptr;
static Logger* g_listener_logger = nullptr;

// === FAST ZAPPING: Reset last_access timers ===
void reset_last_access_check() {
    g_last_check_time = std::chrono::steady_clock::now();
    g_monitoring_start_time = std::chrono::steady_clock::now();
    g_playlist_detected_time = std::chrono::steady_clock::time_point::min();
    logger.info("[FAST_ZAP_DEBUG] Last access timers reset");
}


bool load_peers_tokens(PeersTokens& tokens) {
    if (!fs::exists(PEERS_TOKEN_FILE)) return false;

    std::ifstream file(PEERS_TOKEN_FILE);
    if (!file.is_open()) return false;

    Json::Value root;
    Json::CharReaderBuilder builder;
    std::string errs;
    if (!Json::parseFromStream(builder, file, &root, &errs)) {
        logger.warning("Failed to parse peers.tv tokens file: " + errs);
        return false;
    }

    if (!root.isMember("access_token") || !root.isMember("refresh_token") || !root.isMember("saved_at")) {
        return false;
    }

    tokens.access_token = root["access_token"].asString();
    tokens.refresh_token = root["refresh_token"].asString();
    
    try {
        auto ms = std::stoll(root["saved_at"].asString());
        tokens.saved_at = std::chrono::system_clock::time_point(std::chrono::milliseconds(ms));
    } catch (...) {
        tokens.saved_at = std::chrono::system_clock::now();
    }

    file.close();
    return true;
}

bool save_peers_tokens(const std::string& access, const std::string& refresh) {
    Json::Value root;
    root["access_token"] = access;
    root["refresh_token"] = refresh;
    root["saved_at"] = std::to_string(
        std::chrono::duration_cast<std::chrono::milliseconds>(
            std::chrono::system_clock::now().time_since_epoch()
        ).count()
    );

    std::ofstream file(PEERS_TOKEN_FILE);
    if (!file.is_open()) {
        logger.error("Cannot write peers.tv tokens to " + PEERS_TOKEN_FILE);
        return false;
    }

    Json::StreamWriterBuilder builder;
    builder["indentation"] = "";
    file << Json::writeString(builder, root);
    file.close();
    fs::permissions(PEERS_TOKEN_FILE, fs::perms::owner_read | fs::perms::owner_write);
    logger.debug("Peers.TV tokens updated and saved");
    return true;
}

// Атомарный флаг для блокировки одновременного refresh от разных процессов
std::atomic<bool> peers_refresh_in_progress{false};

bool acquire_refresh_lock() {
    return peers_refresh_in_progress.exchange(true, std::memory_order_acquire) == false;
}

void release_refresh_lock() {
    peers_refresh_in_progress.store(false, std::memory_order_release);
}

std::string perform_peers_auth(bool use_refresh = false, const std::string& refresh_token = "") {
    CURL* curl = curl_easy_init();
    if (!curl) return "";

    std::string response;
    std::string post_data;

    struct curl_slist* headers = nullptr;
    headers = curl_slist_append(headers, "Content-Type: application/x-www-form-urlencoded");
    headers = curl_slist_append(headers, "Host: api.peers.tv");
    headers = curl_slist_append(headers, "Connection: Keep-Alive");
    headers = curl_slist_append(headers, "Accept-Encoding: gzip");
    headers = curl_slist_append(headers, "User-Agent: Peers.TV/7.16.5 Android/15 phone/Xiaomi Redmi.23090RA98G/zircon.zircon.arm64-v8a");
    headers = curl_slist_append(headers, "Accept-Language: ru");

    if (use_refresh) {
        post_data = "grant_type=refresh_token&client_id=29783051&client_secret=b4d4eb438d760da95f0acb5bc6b5c760&signature=pmgiRbTSAPys9NdiLoj60P8yb5k%3D&refresh_token=";
        post_data += curl_easy_escape(curl, refresh_token.c_str(), 0);
    } else {
        post_data = "grant_type=inetra%3Aanonymous&client_id=29783051&client_secret=b4d4eb438d760da95f0acb5bc6b5c760&signature=pmgiRbTSAPys9NdiLoj60P8yb5k%3D";
    }

    curl_easy_setopt(curl, CURLOPT_URL, "http://api.peers.tv/auth/2/token");
    curl_easy_setopt(curl, CURLOPT_POSTFIELDS, post_data.c_str());
    curl_easy_setopt(curl, CURLOPT_HTTPHEADER, headers);
    curl_easy_setopt(curl, CURLOPT_WRITEFUNCTION,
        +[](char* ptr, size_t size, size_t nmemb, void* userdata) -> size_t {
            ((std::string*)userdata)->append(ptr, size * nmemb);
            return size * nmemb;
        });
    curl_easy_setopt(curl, CURLOPT_WRITEDATA, &response);
    curl_easy_setopt(curl, CURLOPT_TIMEOUT, 15L);
    curl_easy_setopt(curl, CURLOPT_USERAGENT, "Peers.TV/7.16.5 Android/15 phone/Xiaomi Redmi.23090RA98G/zircon.zircon.arm64-v8a");

    CURLcode res = curl_easy_perform(curl);
    curl_slist_free_all(headers);
    curl_easy_cleanup(curl);

    if (res != CURLE_OK) {
        logger.error("Peers.TV auth request failed: " + std::string(curl_easy_strerror(res)));
        return "";
    }

    Json::Value root;
    Json::CharReaderBuilder builder;
    std::string errs;
    std::istringstream iss(response);
    if (!Json::parseFromStream(builder, iss, &root, &errs)) {
        logger.error("Failed to parse Peers.TV auth response: " + errs);
        return "";
    }

    std::string access = root["access_token"].asString();
    std::string refresh = root["refresh_token"].asString();

    if (access.empty()) {
        logger.error("Empty access_token from Peers.TV");
        return "";
    }

    save_peers_tokens(access, refresh);
    return access;
}

std::string get_or_refresh_peers_token() {
    PeersTokens tokens;
    bool have_file = load_peers_tokens(tokens);

    auto now = std::chrono::system_clock::now();
    bool is_expired = !have_file || 
                      (now - tokens.saved_at) >= std::chrono::hours(10); // чуть раньше 24ч

    if (!is_expired && !tokens.access_token.empty()) {
        logger.info("Using existing valid Peers.TV access_token (age < 24h)");
        return tokens.access_token;
    }

    // Нужно обновить токен
    if (!acquire_refresh_lock()) {
        logger.info("Another process is refreshing Peers.TV token, waiting...");
        while (peers_refresh_in_progress.load(std::memory_order_acquire)) {
            std::this_thread::sleep_for(500ms);
        }
        // Попробуем снова загрузить — возможно уже обновили
        if (load_peers_tokens(tokens) && !tokens.access_token.empty()) {
            return tokens.access_token;
        }
        return "";
    }

    logger.info("Refreshing Peers.TV token...");
    std::string new_token;

    if (have_file && !tokens.refresh_token.empty()) {
        new_token = perform_peers_auth(true, tokens.refresh_token);
    } else {
        new_token = perform_peers_auth(false);
    }

    release_refresh_lock();
    return new_token;
}

// === ЗАМЕНА ${p_token} В URL ===
std::string replace_peers_token_in_url(const std::string& url) {
    if (url.find("api.peers.tv") == std::string::npos || url.find("${p_token}") == std::string::npos) {
        return url;
    }

    std::string token = get_or_refresh_peers_token();
    if (token.empty()) {
        logger.error("Failed to obtain Peers.TV token for URL replacement");
        return url; // оставляем как есть, пусть упадёт дальше
    }

    {
        std::unique_lock<std::shared_mutex> lock(g_peers_token_mutex);
        g_peers_tv_cached_token = token;
    }
    std::string replacement = "token=" + token;
    std::string result = url;
    size_t pos = result.find("${p_token}");
    while (pos != std::string::npos) {
        result.replace(pos, 10, replacement);  // 10 — длина "${p_token}"
        pos = result.find("${p_token}", pos + replacement.length());
    }

    logger.info("Replaced ${p_token} in URL → valid stream URL ready");
    return result;
}

// === SPUTNIK24.TV: вспомогательные функции ===

inline std::string get_sputnik_sig_file_path(const std::string& channel_id) {
    return "/tmp/sputnik24_sig_" + channel_id + ".json";
}

struct SputnikSig {
    std::string channel_source;
    long expire_date = 0;
};

// Обработчик SIGCHLD — вызывается ядром сразу при завершении дочернего процесса
void sigchld_handler(int sig) {
    int status;
    pid_t pid;
    while ((pid = waitpid(-1, &status, WNOHANG)) > 0) {
        if (g_active_processes && g_listener_logger) {
            auto it = std::find_if(g_active_processes->begin(), g_active_processes->end(),
                [pid](const auto& p) { return p.second == pid; });
            if (it != g_active_processes->end()) {
                std::string exit_info = WIFEXITED(status)
                    ? "exit=" + std::to_string(WEXITSTATUS(status))
                    : "signal=" + std::to_string(WTERMSIG(status));

                g_listener_logger->info("Proxy stopped | channel=" + it->first +
                                       " | PID=" + std::to_string(pid) +
                                       " | " + exit_info);
                g_active_processes->erase(it);
            }
        }
    }
}

// === M3U8 Parser ===
class M3U8Playlist {
public:
    struct StreamInfo {
        std::pair<int, int> resolution;
        int bandwidth;
        std::string codecs;
    };
   
    struct Segment {
        std::string uri;
        double duration;
	long long sequence;
        std::map<std::string, std::string> attributes;
        EncState enc_state;
        std::string raw_tags;
    };
   
    struct Playlist {
        std::string uri;
        StreamInfo stream_info;
    };
   
    bool is_variant = false;
    bool is_event = false;            // <--- Флаг EVENT
    long long media_sequence = 0;     // <--- Начальный sequence number
    long long original_sequence = 0;
    double target_duration = 0;
    std::string global_tags;
    std::vector<Segment> segments;
    std::vector<Playlist> playlists;
    std::vector<std::string> lines;
   
    // ОПТИМИЗАЦИЯ: std::string_view избегает копирования огромной строки плейлиста при передаче
static M3U8Playlist loads(std::string_view content) {
        M3U8Playlist playlist;
        
        // Резервируем память
        playlist.lines.reserve(content.size() / 50); 
        playlist.segments.reserve(content.size() / 100);

        std::string current_stream_info;
        bool expect_segment_uri = false;
        Segment current_segment;
        
        // --- НОВЫЕ ПЕРЕМЕННЫЕ ДЛЯ ШИФРОВАНИЯ ---
        EncState current_enc;
        long long sequence_number = 0;
        std::string current_segment_tags;
        // ----------------------------------------

        size_t pos = 0;
        size_t end;

        while (pos < content.length()) {
            end = content.find('\n', pos);
            if (end == std::string_view::npos) {
                end = content.length();
            }

            std::string_view line_view = content.substr(pos, end - pos);
            pos = end + 1; 

            if (!line_view.empty() && line_view.back() == '\r') {
                line_view.remove_suffix(1);
            }
            
            playlist.lines.emplace_back(line_view);
            
            // 1. Пропускаем заголовок файла
            if (line_view.find("#EXTM3U") != std::string_view::npos) {
                continue;
            } 
            // 2. Обработка Variant Playlist (Master)
            else if (line_view.find("#EXT-X-STREAM-INF:") != std::string_view::npos) {
                playlist.is_variant = true;
                current_stream_info = std::string(line_view);
            } 
            // 3. Целевая длительность
            else if (line_view.find("#EXT-X-TARGETDURATION:") != std::string_view::npos) {
                if (line_view.length() > 22) {
                    std::string_view duration_str = line_view.substr(22);
                    try {
                        playlist.target_duration = std::stod(std::string(duration_str));
                    } catch (...) {
                        playlist.target_duration = 0;
                    }
                }
            } 
            // --- 4. НОВАЯ ЛОГИКА: MEDIA SEQUENCE (Важно для IV) ---
            else if (line_view.find("#EXT-X-MEDIA-SEQUENCE:") != std::string_view::npos) {
                 if (line_view.length() > 22) {
                     try {
                        sequence_number = std::stoll(std::string(line_view.substr(22)));
                     } catch(...) { sequence_number = 0; }
                 }
		playlist.media_sequence = sequence_number;
		playlist.original_sequence = sequence_number;
            }
       // --- НОВАЯ ЛОГИКА: Определение EVENT ---
            else if (line_view.find("#EXT-X-PLAYLIST-TYPE:EVENT") != std::string_view::npos) {
                playlist.is_event = true;
            }
            // --- 5. НОВАЯ ЛОГИКА: EXT-X-KEY (Шифрование) ---
            else if (line_view.find("#EXT-X-KEY:") != std::string_view::npos) {
                std::string line(line_view); // Создаем string для удобного поиска
	
                // --- ДОБАВИТЬ ЛОГ ---
                logger.info("PARSER: Found KEY tag: " + line);
                // --------------------

                if (line.find("METHOD=AES-128") != std::string::npos) {
                    current_enc.active = true;
                    
                    // Извлечение URI
                    size_t uri_pos = line.find("URI=\"");
                    if (uri_pos != std::string::npos) {
                        size_t end_uri = line.find("\"", uri_pos + 5);
                        current_enc.key_uri = line.substr(uri_pos + 5, end_uri - (uri_pos + 5));
                    }
                    
                    // Извлечение IV
                    size_t iv_pos = line.find("IV=");
                    if (iv_pos != std::string::npos) {
                        size_t end_iv = line.find(",", iv_pos); 
                        if (end_iv == std::string::npos) end_iv = line.length();
                        current_enc.iv = hex_to_bin(line.substr(iv_pos + 3, end_iv - (iv_pos + 3)));
                    } else {
                        current_enc.iv.clear(); // Будем использовать Sequence Number
                    }
                } else if (line.find("METHOD=NONE") != std::string::npos) {
                    current_enc.active = false;
                }
            }
            // ----------------------------------------------------
            
            // 6. Информация о сегменте (EXTINF)
            else if (line_view.find("#EXTINF:") != std::string_view::npos) {
                current_segment_tags += std::string(line_view) + "\n";
                if (line_view.length() > 8) {
                    std::string_view duration_part = line_view.substr(8);
                    size_t comma_pos = duration_part.find(',');
                    if (comma_pos != std::string_view::npos) {
                        duration_part = duration_part.substr(0, comma_pos);
                    }
                   
                    current_segment = Segment(); // Сброс сегмента
                    try {
                        current_segment.duration = std::stod(std::string(duration_part));
                    } catch (...) {
                        current_segment.duration = 0;
                    }
                    expect_segment_uri = true;
                }
            } 
            // 7. Быстрый пропуск остальных тегов #EXT-X- 
            // ВАЖНО: Это должно идти ПОСЛЕ проверки KEY и MEDIA-SEQUENCE
           /* else if (line_view.length() >= 7 && line_view.substr(0, 7) == "#EXT-X-") {
                continue;
            }*/

	    // 7. Обработка остальных тегов #EXT-X-
            else if (line_view.length() >= 7 && line_view.substr(0, 7) == "#EXT-X-") {
                std::string line(line_view);
                // Теги, которые относятся к конкретному сегменту (пишем перед сегментом)
                if (line.find("#EXT-X-DISCONTINUITY") == 0 || 
                    line.find("#EXT-X-PROGRAM-DATE-TIME") == 0 ||
                    line.find("#EXT-X-MAP") == 0) {
                    current_segment_tags += line + "\n";
                } 
                // Глобальные теги для всего плейлиста (пишем в шапку)
                else if (line.find("#EXT-X-VERSION") == 0 ||
                         line.find("#EXT-X-INDEPENDENT-SEGMENTS") == 0 ||
                         line.find("#EXT-X-ALLOW-CACHE") == 0 ||
                         line.find("#EXT-X-I-FRAMES-ONLY") == 0) {
                    playlist.global_tags += line + "\n";
                }
                continue;
            }

 
            // 8. Пропуск комментариев
            else if (!line_view.empty() && line_view[0] == '#') {
                continue;
            } 
            // 9. Обработка URI Плейлиста (внутри Master Playlist)
            else if (!line_view.empty() && !current_stream_info.empty()) {
                Playlist pl;
                // ... (тут ваш код парсинга RESOLUTION/BANDWIDTH без изменений) ...
                // Ручной парсинг RESOLUTION=
                size_t res_pos = current_stream_info.find("RESOLUTION=");
                if (res_pos != std::string::npos) {
                    const char* ptr = current_stream_info.c_str() + res_pos + 11;
                    char* end_ptr;
                    long w = std::strtol(ptr, &end_ptr, 10);
                    if (*end_ptr == 'x' || *end_ptr == 'X') {
                        long h = std::strtol(end_ptr + 1, nullptr, 10);
                        pl.stream_info.resolution = { (int)w, (int)h };
                    }
                }    
                // Ручной парсинг BANDWIDTH=
                size_t bw_pos = current_stream_info.find("BANDWIDTH=");
                if (bw_pos != std::string::npos) {
                    try {
                        size_t val_start = bw_pos + 10; 
                        size_t val_end = current_stream_info.find(',', val_start);
                        if (val_end == std::string::npos) val_end = current_stream_info.length();
                        pl.stream_info.bandwidth = std::stoi(current_stream_info.substr(val_start, val_end - val_start));
                    } catch (...) { pl.stream_info.bandwidth = 0; }
                }

                size_t codecs_pos = current_stream_info.find("CODECS=\"");
                if (codecs_pos != std::string::npos) {
                    size_t start_val = codecs_pos + 8;
                    size_t end_val = current_stream_info.find('"', start_val);
                    if (end_val != std::string::npos) {
                        pl.stream_info.codecs = current_stream_info.substr(start_val, end_val - start_val);
                    }
                }
               
                pl.uri = std::string(line_view);
                playlist.playlists.push_back(pl);
                current_stream_info.clear();
            } 
            // 10. Обработка URI Сегмента
            else if (!line_view.empty() && expect_segment_uri) {
                current_segment.uri = std::string(line_view);
		current_segment.sequence = sequence_number;

                current_segment.raw_tags = current_segment_tags;
                current_segment_tags.clear();

                // --- ПРИМЕНЕНИЕ ШИФРОВАНИЯ К СЕГМЕНТУ ---
                if (current_enc.active) {
                    current_segment.enc_state = current_enc;
                    // Если IV не задан в KEY, генерируем из Sequence Number
                    if (current_segment.enc_state.iv.empty()) {
                        current_segment.enc_state.iv = seq_to_iv(sequence_number);
                    }
                }
                // ----------------------------------------

                playlist.segments.push_back(current_segment);
                expect_segment_uri = false;
                sequence_number++; // Увеличиваем счетчик для следующего сегмента
            }
        }
       
        return playlist;
    }
};


// === Redis Reconnect Helper with Exponential Backoff ===
class RedisReconnect {
public:
    // Configuration
    static constexpr int DEFAULT_BASE_DELAY_MS = 1000;    // 1 second base
    static constexpr int DEFAULT_MAX_DELAY_MS = 300000;   // 5 minutes cap
    static constexpr int DEFAULT_MAX_DOWNGRADE_MS = 600000; // 10 minutes before fallback

private:
    redisContext** target_ctx_;          // Pointer to pointer so we can update it
    std::function<bool()> connect_func_; // Callable that performs actual connection

    int base_delay_ms_;
    int max_delay_ms_;
    int max_downgrade_ms_;

    std::atomic<int64_t> disconnected_since_ms_{0};  // 0 = connected
    std::atomic<int> reconnect_attempt_{0};
    std::atomic<bool> enable_fallback_{false};

    Logger& logger_;
    std::string log_prefix_;

    // Random for jitter
    std::mt19937 rng_{std::random_device{}()};

public:
    RedisReconnect(redisContext** target_ctx,
                   std::function<bool()> connect_func,
                   Logger& logger,
                   const std::string& log_prefix = "")
        : target_ctx_(target_ctx)
        , connect_func_(connect_func)
        , logger_(logger)
        , log_prefix_(log_prefix)
        , base_delay_ms_(DEFAULT_BASE_DELAY_MS)
        , max_delay_ms_(DEFAULT_MAX_DELAY_MS)
        , max_downgrade_ms_(DEFAULT_MAX_DOWNGRADE_MS)
    {
    }

    void set_base_delay_ms(int ms) { base_delay_ms_ = ms; }
    void set_max_delay_ms(int ms) { max_delay_ms_ = ms; }
    void set_max_downgrade_ms(int ms) { max_downgrade_ms_ = ms; }

    // Call when connection is lost
    void on_disconnected() {
        auto now = std::chrono::duration_cast<std::chrono::milliseconds>(
            std::chrono::steady_clock::now().time_since_epoch()).count();
        if (disconnected_since_ms_.exchange(now) == 0) {
            reconnect_attempt_.store(0);
            logger_.warning(log_prefix_ + "Redis disconnected at " + std::to_string(now));
        }
    }

    // Call when successfully reconnected
    void on_reconnected() {
        disconnected_since_ms_.store(0);
        reconnect_attempt_.store(0);
        enable_fallback_.store(false);
        logger_.info(log_prefix_ + "Redis reconnected");
    }

    int64_t get_downtime_ms() const {
        int64_t since = disconnected_since_ms_.load();
        if (since == 0) return 0;
        auto now = std::chrono::duration_cast<std::chrono::milliseconds>(
            std::chrono::steady_clock::now().time_since_epoch()).count();
        return now - since;
    }

    bool should_allow_fallback() const {
        int64_t downtime = get_downtime_ms();
        if (downtime == 0) return false;  // Still connected
        return downtime > max_downgrade_ms_;
    }

    // Returns delay in ms for next retry attempt (with exponential backoff + jitter)
    int calculate_delay_ms(int attempt) {
        if (attempt <= 0) return base_delay_ms_;

        // Exponential: base * 2^attempt, capped at max_delay_ms_
        int64_t delay = (int64_t)base_delay_ms_ << std::min(attempt, 10);
        if (delay > max_delay_ms_) delay = max_delay_ms_;

        // Add jitter: 0-25% random
        std::uniform_int_distribution<int> dist(0, delay / 4);
        delay += dist(rng_);

        return static_cast<int>(delay);
    }

    // Attempt reconnection with backoff. Returns true if connected.
    bool try_reconnect(bool* out_timed_out = nullptr) {
        if (out_timed_out) *out_timed_out = false;

        int attempt = reconnect_attempt_.fetch_add(1);

        if (attempt > 0) {
            int delay = calculate_delay_ms(attempt);
            logger_.info(log_prefix_ + "Redis reconnect attempt " + std::to_string(attempt) +
                        ", waiting " + std::to_string(delay) + "ms");
            std::this_thread::sleep_for(std::chrono::milliseconds(delay));
        }

        if (connect_func_()) {
            on_reconnected();
            return true;
        }

        // Check if we should enable fallback mode after prolonged outage
        if (!enable_fallback_.load() && should_allow_fallback()) {
            enable_fallback_.store(true);
            logger_.warning(log_prefix_ + "Redis unavailable for >" +
                          std::to_string(max_downgrade_ms_ / 1000) +
                          "s, enabling fallback mode");
        }

        return false;
    }

    // Blocking reconnect loop. Returns true when connected, false if shutdown.
    // max_attempts = 0 means infinite
    bool reconnect_loop(std::atomic<bool>& shutdown_flag, int max_attempts = 0) {
        int attempts = 0;
        while (!shutdown_flag.load()) {
            if (try_reconnect()) {
                return true;
            }
            ++attempts;
            if (max_attempts > 0 && attempts >= max_attempts) {
                break;
            }
        }
        return false;
    }
};


// === Redis Manager ===
class SlotManager {
private:
    redisContext* redis_ctx;
    RedisReconnect reconn_;

    // Хелпер для подключения/переподключения
    bool connect() {
        if (redis_ctx) {
            redisFree(redis_ctx);
            redis_ctx = nullptr;
        }

        struct timeval timeout = { 2, 0 }; // 2 секунды таймаут
        redis_ctx = redisConnectWithTimeout("45.9.73.98", 6379, timeout);

        if (redis_ctx == nullptr || redis_ctx->err) {
            logger.error("Redis connection failed: " + std::string(redis_ctx ? redis_ctx->errstr : "nullptr"));
            return false;
        }
        struct timeval rw_timeout = { 2, 0 };
        redisSetTimeout(redis_ctx, rw_timeout);

        redisReply* reply = (redisReply*)redisCommand(redis_ctx, "AUTH %s", "qw34rfvgtU9snaWE");
        if (reply == nullptr || reply->type == REDIS_REPLY_ERROR) {
            logger.error("Redis auth failed");
            if (reply) freeReplyObject(reply);
            redisFree(redis_ctx);
            redis_ctx = nullptr;
            return false;
        }

        freeReplyObject(reply);
        return true;
    }

    bool ensure_connected() {
        // Если указатель есть, но висит ошибка сети (обрыв)
        if (redis_ctx && redis_ctx->err) {
            logger.warning("Redis context error detected. Freeing context.");
            redisFree(redis_ctx);
            redis_ctx = nullptr;
            reconn_.on_disconnected();
        }
        // Если контекст пустой (или мы его только что удалили) — переподключаемся
        if (!redis_ctx) {
            if (connect()) {
                reconn_.on_reconnected();
                return true;
            }
            reconn_.on_disconnected();
            return false;
        }
        return true;
    }

public:
    SlotManager()
        : redis_ctx(nullptr)
        , reconn_(&redis_ctx, [this]() { return this->connect(); }, logger, "[SlotManager] ")
    {
        connect(); // Вызываем хелпер при старте
    }
    
    ~SlotManager() {
        if (redis_ctx) {
            redisFree(redis_ctx);
        }
    }

void report_active_source(const std::string& channel, const std::string& url, const std::string& agent) {
        if (!ensure_connected()) return; // Пытаемся восстановить связь
        try {
            redisReply* reply = (redisReply*)redisCommand(redis_ctx, "HSET channel_status:%s active_url %s user_agent %s updated_at %d", 
                         channel.c_str(), url.c_str(), agent.c_str(), (int)time(nullptr));
            
            if (!reply) { // Соединение отвалилось прямо во время отправки
                redisFree(redis_ctx);
                redis_ctx = nullptr;
            } else {
                freeReplyObject(reply);
            }
        } catch (...) {}
    }
    

// Помечает алокацию как "умирающую" в Redis.
// Балансировщик (hls.lua) при виде dying=true не будет переиспользовать этот слот
// и сразу выделит новую алокацию.
void mark_allocation_dying(const std::string& channel) {
    if (!ensure_connected()) return;
    try {
        // Атомарно: прочитать JSON, добавить dying=true, записать обратно
        const char* script = R"(
            local raw = redis.call('HGET', 'channel_allocations', KEYS[1])
            if not raw then return 0 end
            local ok, alloc = pcall(cjson.decode, raw)
            if not ok then return 0 end
            alloc['dying'] = true
            alloc['dying_at'] = tonumber(ARGV[1])
            redis.call('HSET', 'channel_allocations', KEYS[1], cjson.encode(alloc))
            return 1
        )";
        int now_ts = (int)time(nullptr);
        redisReply* reply = (redisReply*)redisCommand(redis_ctx,
            "EVAL %s 1 %s %d", script, channel.c_str(), now_ts);
        if (reply) {
            if (reply->type == REDIS_REPLY_INTEGER && reply->integer == 1) {
                logger.info("Marked allocation as DYING for channel=" + channel);
            }
            freeReplyObject(reply);
        } else {
            // Соединение оборвалось — не критично, cleanup подберёт
            redisFree(redis_ctx);
            redis_ctx = nullptr;
        }
    } catch (const std::exception& e) {
        logger.error("mark_allocation_dying exception: " + std::string(e.what()));
    }
}

void notify_proxy_stop(const std::string& channel, const std::string& provider, int slot,const std::string& token, int64_t allocated_at) {
    if (redis_message_sent) return;

    // Пытаемся отправить сообщение до 3-х раз с паузами
    for (int attempt = 0; attempt < 20; ++attempt) {
        if (!redis_ctx) {
            if (!connect()) {
                // Если не подключились, ждем немного и пробуем снова
                std::this_thread::sleep_for(std::chrono::seconds(1));
                continue;
            }
        }

        try {
            Json::Value root;
            root["channel"] = channel;
            root["provider"] = provider;
            root["slot"] = slot;
	    root["token"] = token;
	    root["allocated_at"] = (Json::Int64)allocated_at;
            root["status"] = "stopped"; // Добавим для ясности

            Json::StreamWriterBuilder builder;
            builder["indentation"] = "";
            std::string msg = Json::writeString(builder, root);

            redisReply* reply = (redisReply*)redisCommand(redis_ctx, "PUBLISH channel_stops %s", msg.c_str());

            // Если reply null, значит соединение разорвано -> переподключаемся в след. итерации
            if (!reply) {
                logger.warning("Redis publish fail (attempt " + std::to_string(attempt + 1) + "), reconnecting...");
                redisFree(redis_ctx);
                redis_ctx = nullptr;
                continue;
            }

            // УСПЕХ
            redis_message_sent = true;
            logger.info("STOP signal sent successfully: " + msg);
            freeReplyObject(reply);
            return;

        } catch (const std::exception& e) {
            logger.error("Redis publish exception: " + std::string(e.what()));
        }
    }
    
    logger.error("CRITICAL: Failed to send STOP signal for slot " + std::to_string(slot) + " after 20 attempts!");
    // Сохраняем "предсмертную записку" для последующей обработки
    std::ofstream queue_file("/tmp/redis_stop_queue.json", std::ios::app);
    if (queue_file.is_open()) {
        Json::Value root;
        root["channel"] = channel;
        root["provider"] = provider;
        root["slot"] = slot;
        root["token"] = token;
        root["status"] = "stopped";
        Json::StreamWriterBuilder builder;
        builder["indentation"] = "";
        queue_file << Json::writeString(builder, root) << "\n";
        queue_file.close();
    }
}

    // Пытаемся занять слот. Возвращает true, если успешно заняли (count < limit).
    bool try_acquire_provider_slot(const std::string& key, int limit) {
        if (key.empty() || limit <= 0) return true;
        // Если Redis лежит, разрешаем просмотр (чтобы не останавливать стримы клиентам)
        if (!ensure_connected()) return true; 

        const char* script = R"(
            local curr = tonumber(redis.call('GET', KEYS[1]) or '0')
            if curr < tonumber(ARGV[1]) then
                redis.call('INCR', KEYS[1])
                return 1
            else
                return 0
            end
        )";

        redisReply* reply = (redisReply*)redisCommand(redis_ctx, "EVAL %s 1 %s %d", script, key.c_str(), limit);

        if (!reply) {
            redisFree(redis_ctx); // Сбрасываем "мертвый" контекст
            redis_ctx = nullptr;
            reconn_.on_disconnected();
            return true; // Fallback: разрешаем стрим, если Redis упал
        }

        bool success = (reply->type == REDIS_REPLY_INTEGER && reply->integer == 1);
        freeReplyObject(reply);
        
        if (success) logger.info("Acquired slot for provider key: " + key);
        else logger.warning("Slot limit reached for key: " + key);
        
        return success;
    }

    // Освобождаем слот
    void release_provider_slot(const std::string& key) {
        if (key.empty() || !ensure_connected()) return;

        const char* script = R"(
            local curr = tonumber(redis.call('GET', KEYS[1]) or '0')
            if curr > 0 then
                redis.call('DECR', KEYS[1])
                return 1
            end
            return 0
        )";

        redisReply* reply = (redisReply*)redisCommand(redis_ctx, "EVAL %s 1 %s", script, key.c_str());
        if (!reply) {
            redisFree(redis_ctx);
            redis_ctx = nullptr;
            reconn_.on_disconnected();
        } else {
            freeReplyObject(reply);
            logger.info("Released slot for provider key: " + key);
        }
    }

    // Получить Redis контекст для отправки команд
    redisContext* get_redis_context() {
        ensure_connected();
        return redis_ctx;
    }

};

// === FAST ZAPPING: Channel Control Subscriber ===
void start_channel_control_subscriber(const std::string& channel_arg,
                                      const std::string& provider_arg,
                                      int slot_arg) {
    // Инициализируем глобальные переменные
    {
        std::lock_guard<std::mutex> lock(g_subscriber_channel_mutex);
        g_subscriber_channel = channel_arg; // Изначально это channel_arg
        g_subscriber_provider = provider_arg;
        g_subscriber_slot = slot_arg;
    }

    std::thread([=]() mutable { // mutable, чтобы можно было изменять channel_arg, slot_arg и т.д. в лямбде
        std::string current_channel_in_subscriber = channel_arg;
        std::string current_provider_in_subscriber = provider_arg;
        int current_slot_in_subscriber = slot_arg;

        logger.info("[FAST_ZAP_DEBUG] Starting channel_control subscriber thread for channel=" + current_channel_in_subscriber);

        auto connect_and_subscribe =[](redisContext*& ctx) -> bool {
            struct timeval timeout = { 2, 0 }; // Таймаут только на подключение
            ctx = redisConnectWithTimeout("45.9.73.98", 6379, timeout);
            if (!ctx || ctx->err) {
                logger.error("[FAST_ZAP_DEBUG] Failed to connect to Redis...");
                if (ctx) { redisFree(ctx); ctx = nullptr; }
                return false;
            }
            
            // ВАЖНО: Включаем Keep-Alive вместо жесткого таймаута
            redisEnableKeepAlive(ctx);

            redisReply* auth_reply = (redisReply*)redisCommand(ctx, "AUTH qw34rfvgtU9snaWE");
            if (auth_reply) {
                if (auth_reply->type == REDIS_REPLY_ERROR) {
                    logger.error("[FAST_ZAP_DEBUG] Redis auth failed: " + std::string(auth_reply->str));
                }
                freeReplyObject(auth_reply);
            }

            redisReply* sub_reply = (redisReply*)redisCommand(ctx, "SUBSCRIBE channel_control");
            if (!sub_reply) {
                logger.error("[FAST_ZAP_DEBUG] Failed to subscribe to channel_control");
                redisFree(ctx); ctx = nullptr;
                return false;
            }
            freeReplyObject(sub_reply);
            logger.info("[FAST_ZAP_DEBUG] Subscribed to channel_control");
            return true;
        };

        redisContext* sub_ctx = nullptr;
        if (!connect_and_subscribe(sub_ctx)) {
            return;
        }

        /*RedisReconnect reconn_sub(&sub_ctx, [&connect_and_subscribe]() {
            redisContext* tmp = nullptr;
            bool ok = connect_and_subscribe(tmp);
            return ok;
        }, logger, "[FAST_ZAP] ");*/
	// Обязательно передаем sub_ctx по ссылке [&sub_ctx] в лямбду!
        RedisReconnect reconn_sub(&sub_ctx, [&connect_and_subscribe, &sub_ctx]() {
            // 1. Очищаем старое сломанное соединение, чтобы избежать утечек памяти
            if (sub_ctx) {
                redisFree(sub_ctx);
                sub_ctx = nullptr;
            }
            // 2. Подключаем настоящий рабочий указатель
            bool ok = connect_and_subscribe(sub_ctx);
            return ok;
        }, logger, "[FAST_ZAP] ");	
        reconn_sub.set_base_delay_ms(2000);   // Фиксированные 2 секунды
        reconn_sub.set_max_delay_ms(2000);    // Без exponential backoff

        // --- ИЗМЕНЁННАЯ ЛОГИКА ЦИКЛА ---
        while (g_keep_running) { // Больше не проверяем g_hot_switch_requested здесь
            redisReply* reply;
            int status = redisGetReply(sub_ctx, (void**)&reply);

            if (status != REDIS_OK || !reply) {
                reconn_sub.on_disconnected();
                // Переподключение каждые 2 секунды без backoff
                while (g_keep_running.load()) {
                    std::this_thread::sleep_for(std::chrono::seconds(2));
                    logger.info("[FAST_ZAP] Attempting Redis reconnect for channel_control...");
                    if (sub_ctx) { redisFree(sub_ctx); sub_ctx = nullptr; }
                    if (connect_and_subscribe(sub_ctx)) {
                        reconn_sub.on_reconnected();
                        logger.info("[FAST_ZAP] Re-subscribed to channel_control");
                        break;
                    }
                }
                if (!g_keep_running.load()) break;
                continue;
            }

            if (reply->type == REDIS_REPLY_ARRAY && reply->elements == 3 &&
                reply->element[0]->type == REDIS_REPLY_STRING && reply->element[0]->str &&
                reply->element[2]->type == REDIS_REPLY_STRING && reply->element[2]->str) {
                std::string msg_type = reply->element[0]->str;
                std::string msg_data = reply->element[2]->str;

                if (msg_type == "message") {
                    try {
                        Json::Value root;
                        Json::CharReaderBuilder builder;
                        std::istringstream iss(msg_data);
                        std::string errs;

                        if (Json::parseFromStream(builder, iss, &root, &errs)) {
                            std::string action = root.get("action", "").asString();
                            std::string msg_channel = root.get("channel", "").asString();
                            std::string msg_type = root.get("type", "").asString();
                            std::string msg_provider = root.get("provider", "").asString();
                            int msg_slot = root.get("slot", -1).asInt();

                            // Читаем текущие значения из глобальных переменных
                            {
                                std::lock_guard<std::mutex> lock(g_subscriber_channel_mutex);
                                current_channel_in_subscriber = g_subscriber_channel;
                                current_provider_in_subscriber = g_subscriber_provider;
                                current_slot_in_subscriber = g_subscriber_slot;
                            }

                            // FAILOVER: матчим только по channel (provider/slot меняются)
                            bool is_failover = (action == "SWITCH" && msg_type == "FAILOVER" &&
                                                msg_channel == current_channel_in_subscriber);
                            // HOT SWITCH: матчим по channel + provider + slot (они не меняются)
                            bool is_hot_switch = (action == "SWITCH" && msg_type != "FAILOVER" &&
                                                  msg_channel == current_channel_in_subscriber &&
                                                  msg_provider == current_provider_in_subscriber &&
                                                  msg_slot == current_slot_in_subscriber);

                            if (is_failover || is_hot_switch) {
                                std::string new_channel = root.get("new_channel", "").asString();
                                std::string new_source_url = root.get("new_source_url", "").asString();
                                int new_slot_from_msg = root.get("new_slot", msg_slot).asInt();
                                std::string new_token_from_msg = root.get("new_token", "").asString();
                                std::string new_provider_from_msg = root.get("new_provider", root.get("provider", "")).asString();
                                int64_t new_allocated_at = root.get("allocated_at", 0).asInt64();

                                if (is_failover) {
                                    logger.info("[FAST_ZAP_DEBUG] FAILOVER SWITCH received: ch=" + msg_channel +
                                               " new_prov=" + new_provider_from_msg +
                                               " new_slot=" + std::to_string(new_slot_from_msg) +
                                               " new_url=" + new_source_url);
                                } else {
                                    logger.info("[FAST_ZAP_DEBUG] HOT SWITCH received: " +
                                               current_channel_in_subscriber + " → " + new_channel +
                                               " prov=" + msg_provider + " slot=" + std::to_string(msg_slot));
                                }

                                // 1. Формируем команду
                                SwitchCommand cmd;
                                cmd.channel = new_channel;
                                cmd.source_url = new_source_url;
                                cmd.save_dir = BASE_DIR + "/" + new_channel;
                                cmd.slot = new_slot_from_msg;
                                cmd.token = new_token_from_msg;
                                cmd.provider = new_provider_from_msg;
                                cmd.allocated_at = new_allocated_at;

                                // 2. Кладем в потокобезопасную очередь
                                {
                                    std::lock_guard<std::mutex> lock(g_switch_queue_mutex);
                                    g_switch_queue.push(cmd);
                                }

                                // 3. Сигнализируем главному потоку
                                g_hot_switch_requested.store(true);
                                g_sleep_cv.notify_all();

                                // 4. Обновляем локальные данные субскрайбера для следующего матча
                                {
                                    std::lock_guard<std::mutex> lock(g_subscriber_channel_mutex);
                                    g_subscriber_channel = new_channel;
                                    g_subscriber_slot = new_slot_from_msg;
                                    g_subscriber_provider = new_provider_from_msg;
                                }
                            } else if (action == "SWITCH") {
                                // Различаем: чужой канал (нормальный broadcast) vs свой канал но не совпал provider/slot (потенциальная проблема)
                                if (msg_channel != current_channel_in_subscriber) {
                                    // Чужой канал — нормальное поведение, все прокси получают все SWITCH через global pub/sub
                                    logger.info("[FAST_ZAP_DEBUG] SWITCH for other channel ignored: msg_ch=" + msg_channel +
                                               " (we handle ch=" + current_channel_in_subscriber + ")");
                                } else {
                                    // Наш канал, но provider/slot не совпали — потенциальная проблема
                                    logger.warning("[FAST_ZAP_DEBUG] SWITCH ignored (channel match, provider/slot mismatch): msg_ch=" + msg_channel +
                                               " msg_type=" + msg_type +
                                               " msg_prov=" + msg_provider + " msg_slot=" + std::to_string(msg_slot) +
                                               " | expected prov=" + current_provider_in_subscriber +
                                               " slot=" + std::to_string(current_slot_in_subscriber));
                                }
                            }
                        } else {
                            logger.error("[FAST_ZAP_DEBUG] Failed to parse JSON: " + errs);
                        }
                    } catch (const std::exception& e) {
                        logger.error("[FAST_ZAP_DEBUG] Exception parsing message: " + std::string(e.what()));
                    }
                }
                freeReplyObject(reply);
            } else {
                if (reply) freeReplyObject(reply);
            }
        }
        // --- КОНЕЦ ИЗМЕНЁННОЙ ЛОГИКИ ЦИКЛА ---

        if (sub_ctx) redisFree(sub_ctx);
        logger.info("[FAST_ZAP_DEBUG] Unsubscribed from channel_control");
    }).detach();
}

// === IPv6 Functions ===
std::vector<std::string> get_ipv6_addresses() {
    std::vector<std::string> ipv6_list;
    
    try {
        struct ifaddrs *ifaddrs_ptr, *ifa;
        if (getifaddrs(&ifaddrs_ptr) == -1) {
            return ipv6_list;
        }
        
        for (ifa = ifaddrs_ptr; ifa != nullptr; ifa = ifa->ifa_next) {
            if (ifa->ifa_addr == nullptr) continue;
            
            if (ifa->ifa_addr->sa_family == AF_INET6) {
                struct sockaddr_in6* addr_in6 = (struct sockaddr_in6*)ifa->ifa_addr;
                
                // Skip link-local addresses
                if (IN6_IS_ADDR_LINKLOCAL(&addr_in6->sin6_addr)) {
                    continue;
                }
                
                // Skip loopback
                if (IN6_IS_ADDR_LOOPBACK(&addr_in6->sin6_addr)) {
                    continue;
                }
                
                char ip_str[INET6_ADDRSTRLEN];
                if (inet_ntop(AF_INET6, &addr_in6->sin6_addr, ip_str, INET6_ADDRSTRLEN)) {
                    std::string ip(ip_str);
                    if (std::find(ipv6_list.begin(), ipv6_list.end(), ip) == ipv6_list.end()) {
                        ipv6_list.push_back(ip);
                    }
                }
            }
        }
        
        freeifaddrs(ifaddrs_ptr);
        logger.info("IPv6 addresses: ");
        for (const auto& ip : ipv6_list) {
            logger.info("  " + ip);
        }
        
    } catch (const std::exception& e) {
        logger.error("Failed to get IPv6: " + std::string(e.what()));
    }
    
    return ipv6_list;
}

std::string select_ipv6_address(const std::string& token, const std::string& channel) {
return ""; //заглушка
    if (token.empty()) {
        logger.info("No token → no IPv6 binding");
        return "";
    }
    
    try {
        // File locking
        std::string lock_file = IPV6_MAPPING_FILE + ".lock";
        int lock_fd = open(lock_file.c_str(), O_CREAT | O_WRONLY, 0644);
        if (lock_fd != -1) {
            struct flock fl;
            fl.l_type = F_WRLCK;
            fl.l_whence = SEEK_SET;
            fl.l_start = 0;
            fl.l_len = 0;
            if (fcntl(lock_fd, F_SETLK, &fl) == -1) {
                close(lock_fd);
                std::remove(lock_file.c_str());
                return "";
            }
        }
        
        // Read mapping
        std::map<std::string, std::map<std::string, std::string>> mapping;
        if (fs::exists(IPV6_MAPPING_FILE)) {
            std::ifstream file(IPV6_MAPPING_FILE);
            if (file.is_open()) {
                Json::CharReaderBuilder builder;
                Json::Value root;
                std::string errs;
                if (Json::parseFromStream(builder, file, &root, &errs)) {
                    for (const auto& key : root.getMemberNames()) {
                        mapping[key]["ipv6"] = root[key]["ipv6"].asString();
                        mapping[key]["count"] = std::to_string(root[key]["count"].asInt());
                    }
                }
                file.close();
            }
        }
        
        // Get IPv6 addresses
        auto ips = get_ipv6_addresses();
        if (ips.empty()) {
            logger.error("No IPv6 addresses available");
            if (lock_fd != -1) {
                close(lock_fd);
                std::remove(lock_file.c_str());
            }
            return "";
        }
        
        // Check existing mapping
        if (mapping.find(token) != mapping.end() && 
            std::find(ips.begin(), ips.end(), mapping[token]["ipv6"]) != ips.end()) {
            
            int count = std::stoi(mapping[token]["count"]);
            if (count < MAX_PROCESSES_PER_IP) {
                count++;
                mapping[token]["count"] = std::to_string(count);
                
                // Write updated mapping
                Json::Value root;
                for (const auto& pair : mapping) {
                    root[pair.first]["ipv6"] = pair.second.at("ipv6");
                    root[pair.first]["count"] = std::stoi(pair.second.at("count"));
                }
                
                std::ofstream file(IPV6_MAPPING_FILE);
                if (file.is_open()) {
                    Json::StreamWriterBuilder builder;
                    file << Json::writeString(builder, root);
                    file.close();
                }
                
                logger.info("Reused " + mapping[token]["ipv6"] + " for token " + token 
                          + " (count: " + std::to_string(count) + ")");
                
                if (lock_fd != -1) {
                    close(lock_fd);
                    std::remove(lock_file.c_str());
                }
                return mapping[token]["ipv6"];
            }
        }
        
        // Count address usage
        std::map<std::string, int> counts;
        for (const auto& ip : ips) {
            counts[ip] = 0;
        }
        
        for (const auto& pair : mapping) {
            const std::string& ip = pair.second.at("ipv6");
            if (counts.find(ip) != counts.end()) {
                counts[ip]++;
            }
        }
        
        // Select least used address
        auto selected = std::min_element(counts.begin(), counts.end(),
            [](const auto& a, const auto& b) { return a.second < b.second; });
        
        if (selected != counts.end()) {
            mapping[token]["ipv6"] = selected->first;
            mapping[token]["count"] = std::to_string(selected->second + 1);
            
            // Write updated mapping
            Json::Value root;
            for (const auto& pair : mapping) {
                root[pair.first]["ipv6"] = pair.second.at("ipv6");
                root[pair.first]["count"] = std::stoi(pair.second.at("count"));
            }
            
            std::ofstream file(IPV6_MAPPING_FILE);
            if (file.is_open()) {
                Json::StreamWriterBuilder builder;
                file << Json::writeString(builder, root);
                file.close();
            }
            
            logger.info("Assigned " + selected->first + " to token " + token);
            
            if (lock_fd != -1) {
                close(lock_fd);
                std::remove(lock_file.c_str());
            }
            return selected->first;
        }
        
        if (lock_fd != -1) {
            close(lock_fd);
            std::remove(lock_file.c_str());
        }
        
    } catch (const std::exception& e) {
        logger.error("IPv6 select error: " + std::string(e.what()));
    }
    
    return "";
}

bool has_ipv6_support() {
return false;
    try {
        int sock = socket(AF_INET6, SOCK_STREAM, 0);
        if (sock != -1) {
            close(sock);
            return true;
        }
    } catch (...) {
        return false;
    }
    return false;
}

// === Утилита для получения имени файла из URI ===
std::string get_fname(const std::string& uri) {
    std::string fname = uri;
    size_t q = fname.find('?');
    if (q != std::string::npos) fname = fname.substr(0, q);
    size_t s = fname.find_last_of('/');
    if (s != std::string::npos) fname = fname.substr(s + 1);
    return fname;
}

std::string get_local_filename(long long sequence, const std::string& uri) {
    return std::to_string(sequence) + "_" + get_fname(uri);
}


// === URL utilities (exact Python urljoin equivalent) ===
std::string url_join(const std::string& base, const std::string& url) {
    if (url.empty()) return base;
    
    // Если URL абсолютный (содержит схему), возвращаем его как есть
    if (url.find("://") != std::string::npos) {
        return url;
    }
    
    // Если URL начинается с //, это протокол-относительный URL
    if (url.length() >= 2 && url[0] == '/' && url[1] == '/') {
        size_t scheme_pos = base.find("://");
        if (scheme_pos != std::string::npos) {
            return base.substr(0, scheme_pos) + ":" + url;
        }
        return url;
    }

    // Если url это полный URL (http:// или https://), возвращаем как есть
    // но добавляем query параметры из base если их нет в url
    if (url.find("://") != std::string::npos) {
        // Это полный URL, проверяем нужно ли добавить query из base
        if (url.find('?') == std::string::npos) {
            // url не содержит query, добавляем из base
            size_t query_pos = base.find('?');
            if (query_pos != std::string::npos) {
                return url + base.substr(query_pos);
            }
        }
        return url;
    }

    std::string result = base;

    // Сохраняем query и fragment для добавления в конце
    std::string query_string;
    std::string fragment_string;

    size_t query_pos = result.find('?');
    if (query_pos != std::string::npos) {
        query_string = result.substr(query_pos);
        result = result.substr(0, query_pos);
    }

    size_t fragment_pos = result.find('#');
    if (fragment_pos != std::string::npos) {
        fragment_string = result.substr(fragment_pos);
        result = result.substr(0, fragment_pos);
    }
    
    // Если URL абсолютный путь (начинается с /)
    if (url[0] == '/') {
        // Находим начало пути в базовом URL (после домена)
        size_t scheme_pos = result.find("://");
        if (scheme_pos != std::string::npos) {
            size_t path_pos = result.find('/', scheme_pos + 3);
            std::string final_url;
            if (path_pos != std::string::npos) {
                final_url = result.substr(0, path_pos) + url;
            } else {
                final_url = result + url;
            }
            // Добавляем query/fragment только если url сам не содержит query
            if (url.find('?') == std::string::npos) {
                return final_url + query_string + fragment_string;
            } else {
                return final_url + fragment_string;
            }
        }
        // Добавляем query/fragment только если url сам не содержит query
        if (url.find('?') == std::string::npos) {
            return result + url + query_string + fragment_string;
        } else {
            return result + url + fragment_string;
        }
    }
    
    // Относительный путь - разрешаем относительно базового URL
    // Удаляем последний компонент пути (имя файла) из базового URL
    size_t last_slash = result.rfind('/');
    if (last_slash != std::string::npos && last_slash > 7) { // > 7 чтобы не удалить часть домена
        result = result.substr(0, last_slash + 1);
    } else if (last_slash <= 7) {
        // Добавляем слеш если его нет
        if (result.back() != '/') {
            result += "/";
        }
    }
    
    // Обрабатываем ./ в начале URL
    std::string path = url;
    while (path.length() >= 2 && path[0] == '.' && path[1] == '/') {
        path = path.substr(2);
    }
    
    // Обрабатываем ../ в начале URL
    while (path.length() >= 3 && path[0] == '.' && path[1] == '.' && path[2] == '/') {
        // Удаляем последнюю директорию из result
        if (result.length() > 1 && result.back() == '/') {
            result.pop_back();
        }
        size_t last_slash_pos = result.rfind('/');
        if (last_slash_pos != std::string::npos && last_slash_pos > 7) {
            result = result.substr(0, last_slash_pos + 1);
        }
        path = path.substr(3);
    }
    
    // Убираем завершающий слеш из result если path не пустой и не начинается с слеша
    if (!path.empty() && path[0] != '/' && result.length() > 0 && result.back() == '/') {
        result.pop_back();
    }
    
    // Добавляем слеш между result и path если нужно
    if (!result.empty() && result.back() != '/' && !path.empty() && path[0] != '/') {
        result += "/";
    }

    // Добавляем query и fragment обратно (только если path сам не содержит query)
    if (path.find('?') == std::string::npos) {
        return result + path + query_string + fragment_string;
    } else {
        // path уже содержит свои query параметры, не добавляем из base
        return result + path + fragment_string;
    }
}

// === Optimized HTTP Session with Keep-Alive ===
class HttpRequestSession {
private:
    std::string user_agent; // Храним текущий UA
    std::string source_ip;
    std::string last_effective_url; 
    std::string referer;
    std::map<std::string, std::string> custom_headers_;
    CURL* curl_handle_; // Постоянный хендл

    // Настройка общих параметров для каждого запроса
    void setup_defaults() {
        if (!curl_handle_) return;

        // Сброс опций к значениям по умолчанию, но СОХРАНЕНИЕ кэша соединений
        curl_easy_reset(curl_handle_);

        // Referer устанавливается ПОСЛЕ reset (ранее ставился ДО reset и терялся)
        if (!referer.empty()) {
            curl_easy_setopt(curl_handle_, CURLOPT_REFERER, referer.c_str());
        }

	std::string ua = user_agent.empty() ? USER_AGENT : user_agent;
        curl_easy_setopt(curl_handle_, CURLOPT_USERAGENT, ua.c_str());
        curl_easy_setopt(curl_handle_, CURLOPT_TIMEOUT, TIMEOUT);
        curl_easy_setopt(curl_handle_, CURLOPT_FOLLOWLOCATION, 1L);
        curl_easy_setopt(curl_handle_, CURLOPT_MAXREDIRS, 5L);
        
        // Включаем TCP Keep-Alive
        curl_easy_setopt(curl_handle_, CURLOPT_TCP_KEEPALIVE, 1L);
        curl_easy_setopt(curl_handle_, CURLOPT_TCP_KEEPIDLE, 120L);
        curl_easy_setopt(curl_handle_, CURLOPT_TCP_KEEPINTVL, 60L);
        
        curl_easy_setopt(curl_handle_, CURLOPT_NOSIGNAL, 1L);
        curl_easy_setopt(curl_handle_, CURLOPT_CONNECTTIMEOUT, 5L);
        curl_easy_setopt(curl_handle_, CURLOPT_ACCEPT_ENCODING, ""); // Auto-handle gzip
        curl_easy_setopt(curl_handle_, CURLOPT_TCP_NODELAY, 1L);
        curl_easy_setopt(curl_handle_, CURLOPT_SSL_VERIFYPEER, 0L);
        curl_easy_setopt(curl_handle_, CURLOPT_SSL_VERIFYHOST, 0L);
        
        // DNS Cache settings
        curl_easy_setopt(curl_handle_, CURLOPT_DNS_CACHE_TIMEOUT, 300L);

        // Привязка к IP (если есть)
        if (!source_ip.empty() && has_ipv6_support()) {
            curl_easy_setopt(curl_handle_, CURLOPT_INTERFACE, source_ip.c_str());
        }
    }

public:

void set_fast_start_timeouts() {
    if (!curl_handle_) return;
    curl_easy_setopt(curl_handle_, CURLOPT_TIMEOUT, 5L);           // 5с вместо 10с
    curl_easy_setopt(curl_handle_, CURLOPT_CONNECTTIMEOUT, 3L);    // 3с вместо 5с
}

    void set_custom_headers(const std::map<std::string, std::string>& h) { custom_headers_ = h; }
    void clear_custom_headers() { custom_headers_.clear(); }

    void set_referer(const std::string& ref) { referer = ref; }
    // Метод для установки UA перед запросом
    void set_user_agent(const std::string& ua) {
        user_agent = ua;
    }
    const std::string& get_source_ip() const { return source_ip; }
    const std::string& get_last_effective_url() const { return last_effective_url; }

    HttpRequestSession(const std::string& src_ip = "") : source_ip(src_ip) {
        curl_handle_ = curl_easy_init();
        if (!curl_handle_) {
            logger.error("CRITICAL: Failed to initialize CURL handle in HttpRequestSession");
        }
    }

    ~HttpRequestSession() {
        if (curl_handle_) {
            curl_easy_cleanup(curl_handle_);
            curl_handle_ = nullptr;
        }
    }

    // Запрет копирования, чтобы не было double-free (CURL handle уникален)
    HttpRequestSession(const HttpRequestSession&) = delete;
    HttpRequestSession& operator=(const HttpRequestSession&) = delete;

    bool get(const std::string& url, std::string& response, std::map<std::string, std::string> headers = {}) {
        if (!curl_handle_) return false;

        setup_defaults(); // Сброс и применение базовых настроек

        response.clear();
        last_effective_url.clear();

        curl_easy_setopt(curl_handle_, CURLOPT_URL, url.c_str());
        
        // Функция записи в строку
        curl_easy_setopt(curl_handle_, CURLOPT_WRITEFUNCTION,
            +[](char* ptr, size_t size, size_t nmemb, void* userdata) -> size_t {
                ((std::string*)userdata)->append(ptr, size * nmemb);
                return size * nmemb;
            });
        curl_easy_setopt(curl_handle_, CURLOPT_WRITEDATA, &response);

        // Формирование заголовков
        struct curl_slist* header_list = nullptr;

        for (const auto& header : custom_headers_) {
            std::string header_str = header.first + ": " + header.second;
            header_list = curl_slist_append(header_list, header_str.c_str());
        }

        for (const auto& header : headers) {
            std::string header_str = header.first + ": " + header.second;
            header_list = curl_slist_append(header_list, header_str.c_str());
        }
        // Стандартные заголовки
        header_list = curl_slist_append(header_list, "Accept: */*");
        header_list = curl_slist_append(header_list, "Connection: keep-alive");

        if (header_list) {
            curl_easy_setopt(curl_handle_, CURLOPT_HTTPHEADER, header_list);
        }

        CURLcode res = curl_easy_perform(curl_handle_);
        
        long http_code = 0;
        char* effective_url = nullptr;
        curl_easy_getinfo(curl_handle_, CURLINFO_RESPONSE_CODE, &http_code);
        curl_easy_getinfo(curl_handle_, CURLINFO_EFFECTIVE_URL, &effective_url);

        if (effective_url) {
            last_effective_url = std::string(effective_url);
        } else {
            last_effective_url = url;
        }

        if (header_list) {
            curl_slist_free_all(header_list);
        }

        if (res != CURLE_OK) {
            logger.error("GET request failed: " + std::string(curl_easy_strerror(res)) +
                        " (HTTP code: " + std::to_string(http_code) + ", URL: " + url + ")");
            return false;
        }

        // logger.info("GET request successful: " + url); // Можно раскомментировать для дебага
        return true;
    }

    bool download_file(const std::string& url, const std::string& path) {
        if (!curl_handle_) return false;

        setup_defaults(); // Сброс и применение базовых настроек

        std::string tmp_path = path + ".tmp";
        last_effective_url.clear();

        try {
            fs::create_directories(fs::path(path).parent_path());
        } catch (const std::exception& e) {
            logger.error("Failed to create directory for " + path + ": " + std::string(e.what()));
            return false;
        }

        FILE* file = fopen(tmp_path.c_str(), "wb");
        if (!file) {
            logger.error("Failed to create temporary file: " + tmp_path + " (errno: " + std::to_string(errno) + ")");
            return false;
        }

        curl_easy_setopt(curl_handle_, CURLOPT_URL, url.c_str());
        curl_easy_setopt(curl_handle_, CURLOPT_WRITEFUNCTION,
            +[](char* ptr, size_t size, size_t nmemb, void* userdata) -> size_t {
                return fwrite(ptr, size, nmemb, (FILE*)userdata);
            });
        curl_easy_setopt(curl_handle_, CURLOPT_WRITEDATA, file);
        curl_easy_setopt(curl_handle_, CURLOPT_BUFFERSIZE, CHUNK_SIZE);

        struct curl_slist* header_list = nullptr;
        header_list = curl_slist_append(header_list, "Accept: */*");
        header_list = curl_slist_append(header_list, "Connection: keep-alive");
        curl_easy_setopt(curl_handle_, CURLOPT_HTTPHEADER, header_list);

        CURLcode res = curl_easy_perform(curl_handle_);
        
        long http_code = 0;
        char* effective_url = nullptr;
        double content_length = 0;

        curl_easy_getinfo(curl_handle_, CURLINFO_RESPONSE_CODE, &http_code);
        curl_easy_getinfo(curl_handle_, CURLINFO_EFFECTIVE_URL, &effective_url);
        curl_easy_getinfo(curl_handle_, CURLINFO_CONTENT_LENGTH_DOWNLOAD, &content_length);

        if (effective_url) {
            last_effective_url = std::string(effective_url);
        }

        if (header_list) {
            curl_slist_free_all(header_list);
        }

        fclose(file); // Важно закрыть файл перед проверкой размера и удалением

        if (res != CURLE_OK) {
            logger.error("Failed to download file: " + std::string(curl_easy_strerror(res)) +
                        " (HTTP code: " + std::to_string(http_code) + ", URL: " + url + ")");
            std::remove(tmp_path.c_str());
            return false;
        }

        // Проверка размера файла
        std::error_code ec;
        auto size = fs::file_size(tmp_path, ec);
        if (ec || size == 0) {
            logger.error("Downloaded file is empty: " + tmp_path);
            std::remove(tmp_path.c_str());
            return false;
        }

        if (std::rename(tmp_path.c_str(), path.c_str()) != 0) {
            logger.error("Failed to rename file from " + tmp_path + " to " + path +
                        " (errno: " + std::to_string(errno) + ")");
            std::remove(tmp_path.c_str());
            return false;
        }

        logger.info("Downloaded file: " + path + ", size: " + std::to_string(size) + " bytes");
        return true;
    }
};

// Callback context for decryption (OPTIMIZED: unique_ptr for automatic memory management)
struct WriteCtx {
    FILE* file;
    std::unique_ptr<Aes128> aes;
    uint8_t buffer[16]; 
    size_t buf_pos = 0;
    bool direct_write = false;

    ~WriteCtx() {
        // aes automatically deleted by unique_ptr
    }
};

// Custom write callback that handles buffering and decryption
size_t decrypted_write_callback(char* ptr, size_t size, size_t nmemb, void* userdata) {
    size_t total_bytes = size * nmemb;
    WriteCtx* ctx = (WriteCtx*)userdata;

    if (!ctx->aes) {
        size_t written = fwrite(ptr, size, nmemb, ctx->file);
        if (ctx->direct_write) fflush(ctx->file);
        return written;
    }

    size_t processed = 0;
    while (processed < total_bytes) {
        size_t to_copy = std::min(total_bytes - processed, 16 - ctx->buf_pos);
        memcpy(ctx->buffer + ctx->buf_pos, ptr + processed, to_copy);
        ctx->buf_pos += to_copy;
        processed += to_copy;

        if (ctx->buf_pos == 16) {
            // Расшифровываем накопленный блок "на месте"
            // Класс Aes128 теперь сам хранит IV и делает XOR
            ctx->aes->decrypt_cbc(ctx->buffer, 16);
            
            fwrite(ctx->buffer, 1, 16, ctx->file);
            if (ctx->direct_write) fflush(ctx->file);
            ctx->buf_pos = 0;
        }
    }
    return total_bytes;
}

// === CurlMultiDownloader: неблокирующая загрузка N файлов в 1 потоке ===

// === DNS-КЭШ ДЛЯ CURL ===
static CURLSH* g_dns_cache = nullptr;
static std::once_flag g_dns_cache_init;

void init_dns_cache() {
    std::call_once(g_dns_cache_init, []() {
        g_dns_cache = curl_share_init();
        if (g_dns_cache) {
            curl_share_setopt(g_dns_cache, CURLSHOPT_SHARE, CURL_LOCK_DATA_DNS);
            logger.info("CURL: DNS cache initialized");
        }
    });
}

class CurlMultiDownloader {
private:
    std::string source_ip_;
    CURLM* multi_handle_;
    std::string user_agent_;
    std::string referer_;
    std::map<std::string, std::string> custom_headers_;
    
    // ИЗМЕНЕНИЕ 1: Добавили 4-й элемент в кортеж: curl_slist* (заголовки)
    // std::tuple<CURL*, FILE*, std::string, curl_slist*>
    //std::vector<std::tuple<CURL*, FILE*, std::string, curl_slist*>> handles_;
    struct TaskHandle {
        CURL* curl;
        FILE* file;
        std::string fpath;
        curl_slist* headers;
        std::unique_ptr<WriteCtx> wctx; // OPTIMIZED: unique_ptr for automatic memory management
        bool direct_write = false; // true = пишем сразу в fpath (без .tmp), для instant start
    };

    std::vector<TaskHandle> handles_; 
    
    long timeout_ms_ = TIMEOUT * 1000; // ms


    // ИЗМЕНЕНИЕ 2: Возвращаем пару {CURL*, curl_slist*}
    std::pair<CURL*, curl_slist*> create_easy_handle() {
        CURL* curl = curl_easy_init();
        if (!curl) return {nullptr, nullptr};

        if (!referer_.empty()) {
            curl_easy_setopt(curl, CURLOPT_REFERER, referer_.c_str());
        }
   	std::string ua_str = user_agent_.empty() ? USER_AGENT : user_agent_;
        curl_easy_setopt(curl, CURLOPT_USERAGENT, ua_str.c_str());
        curl_easy_setopt(curl, CURLOPT_TIMEOUT, TIMEOUT);
        curl_easy_setopt(curl, CURLOPT_FOLLOWLOCATION, 1L);
        curl_easy_setopt(curl, CURLOPT_MAXREDIRS, 5L);
        curl_easy_setopt(curl, CURLOPT_TCP_KEEPALIVE, 1L);
        curl_easy_setopt(curl, CURLOPT_NOSIGNAL, 1L);
        curl_easy_setopt(curl, CURLOPT_CONNECTTIMEOUT, 5L);
        curl_easy_setopt(curl, CURLOPT_TCP_NODELAY, 1L);
        curl_easy_setopt(curl, CURLOPT_SSL_VERIFYPEER, 0L);
        curl_easy_setopt(curl, CURLOPT_SSL_VERIFYHOST, 0L);
        curl_easy_setopt(curl, CURLOPT_BUFFERSIZE, CHUNK_SIZE);
        curl_easy_setopt(curl, CURLOPT_ACCEPT_ENCODING, "");

        // HTTP/2 для мультиплексирования
        //curl_easy_setopt(curl, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0);
        //curl_easy_setopt(curl, CURLOPT_PIPEWAIT, 1L);
	curl_easy_setopt(curl, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);

        // DNS-кэш
        if (g_dns_cache) {
            curl_easy_setopt(curl, CURLOPT_SHARE, g_dns_cache);
            curl_easy_setopt(curl, CURLOPT_DNS_CACHE_TIMEOUT, 300L);
        }

        // Агрессивные таймауты
        curl_easy_setopt(curl, CURLOPT_CONNECTTIMEOUT, 3L);
        curl_easy_setopt(curl, CURLOPT_LOW_SPEED_LIMIT, 50000L);
        curl_easy_setopt(curl, CURLOPT_LOW_SPEED_TIME, 3L);

        if (!source_ip_.empty() && has_ipv6_support()) {
            curl_easy_setopt(curl, CURLOPT_INTERFACE, source_ip_.c_str());
        }

        // Создаем список заголовков
        struct curl_slist* headers = nullptr;
        for (const auto& header : custom_headers_) {
            std::string header_str = header.first + ": " + header.second;
            headers = curl_slist_append(headers, header_str.c_str());
        }
        headers = curl_slist_append(headers, "Accept: */*");
        headers = curl_slist_append(headers, "Connection: keep-alive");
        
        // Прикрепляем заголовки (curl не копирует их, нужно хранить указатель!)
        curl_easy_setopt(curl, CURLOPT_HTTPHEADER, headers);

        return {curl, headers};
    }

public:
    void set_custom_headers(const std::map<std::string, std::string>& h) { custom_headers_ = h; }
    void clear_custom_headers() { custom_headers_.clear(); }

    explicit CurlMultiDownloader(const std::string& source_ip = "", const std::string& ua = USER_AGENT) 
        : source_ip_(source_ip), user_agent_(ua), multi_handle_(curl_multi_init()) {
        if (!multi_handle_) {
            throw std::runtime_error("curl_multi_init failed");
        }
        curl_multi_setopt(multi_handle_, CURLMOPT_MAX_TOTAL_CONNECTIONS, 6L);
        curl_multi_setopt(multi_handle_, CURLMOPT_PIPELINING, CURLPIPE_MULTIPLEX);
        curl_multi_setopt(multi_handle_, CURLMOPT_MAX_HOST_CONNECTIONS, 6L);
        init_dns_cache();
    }

    void set_user_agent(const std::string& ua) {
        user_agent_ = ua.empty() ? USER_AGENT : ua;
    }

    void set_referer(const std::string& ref) { 
        referer_ = ref; 
    }

    ~CurlMultiDownloader() {
        // ИЗМЕНЕНИЕ 3: Очистка в деструкторе
//        for (auto& [curl, file, fpath, headers] : handles_) {
        for (auto& task : handles_) {
            curl_multi_remove_handle(multi_handle_, task.curl);
            if (task.file) {
                fclose(task.file);
                if (task.direct_write) {
                    std::remove(task.fpath.c_str());
                    std::remove((task.fpath + ".downloading").c_str());
                } else {
                    std::remove((task.fpath + ".tmp").c_str());
                }
            }
            if (task.headers) curl_slist_free_all(task.headers); // Освобождаем заголовки
            if (task.curl) curl_easy_cleanup(task.curl);
        }
        if (multi_handle_) {
            curl_multi_cleanup(multi_handle_);
        }
    }

    bool add_task(const std::string& url, const std::string& fpath,
                  const std::vector<uint8_t>& key = {}, const std::vector<uint8_t>& iv = {},
                  bool direct_write = false) {

        std::string tmp_path = direct_write ? fpath : (fpath + ".tmp");
        try {
            fs::create_directories(fs::path(fpath).parent_path());
        } catch (const std::exception& e) {
            logger.error("CurlMultiDownloader: mkdir failed: " + std::string(e.what()));
            return false;
        }

        if (direct_write) {
            std::ofstream marker(fpath + ".downloading");
        }

        FILE* file = fopen(tmp_path.c_str(), "wb");
        if (!file) {
            logger.error("CurlMultiDownloader: fopen failed: " + tmp_path);
            return false;
        }

        // ИЗМЕНЕНИЕ 4: Получаем и сохраняем headers
        auto [curl, headers] = create_easy_handle();
        if (!curl) {
            fclose(file);
            std::remove(tmp_path.c_str());
            if (headers) curl_slist_free_all(headers);
            return false;
        }

        // --- OPTIMIZED: НАСТРОЙКА CONTEXT И DECRYPTION С unique_ptr ---
        std::unique_ptr<WriteCtx> wctx;
        try {
            wctx = std::make_unique<WriteCtx>();
            wctx->file = file;
            wctx->direct_write = direct_write;

            if (!key.empty() && !iv.empty()) {
                // OPTIMIZED: Используем make_unique для автоматического управления памятью
                wctx->aes = std::make_unique<Aes128>(key.data(), iv.data());
                logger.info("DOWNLOADER: AES enabled for " + fpath);
            } else {
                 // Если ключ пришел, но AES не создался — это ошибка логики
                 if (!key.empty()) logger.warning("DOWNLOADER: Key present but AES NOT created (IV missing?) for " + fpath);
            }
        } catch (const std::exception& e) {
            logger.error("CurlMultiDownloader: Failed to create WriteCtx: " + std::string(e.what()));
            fclose(file);
            std::remove(tmp_path.c_str());
            curl_slist_free_all(headers);
            curl_easy_cleanup(curl);
            return false;  // NO MEMORY LEAK - unique_ptr automatically cleaned up
        }

        curl_easy_setopt(curl, CURLOPT_URL, url.c_str());

        curl_easy_setopt(curl, CURLOPT_WRITEDATA, wctx.get());
        curl_easy_setopt(curl, CURLOPT_WRITEFUNCTION, decrypted_write_callback);


        // Сохраняем в вектор (используя новую структуру TaskHandle с unique_ptr)
        handles_.push_back({curl, file, fpath, headers, std::move(wctx), direct_write});
        curl_multi_add_handle(multi_handle_, curl);
        return true;
    }

    std::vector<std::pair<bool, std::string>> run() {
        std::vector<std::pair<bool, std::string>> results;
        int still_running = 1;

        while (still_running) {
            // === ВСТАВКА: ПРОВЕРКА ГЛОБАЛЬНОГО ФЛАГА ===
            if (!g_keep_running || g_hot_switch_requested) {
                // Если пришел сигнал остановки или HOT SWITCH, прерываем загрузку
                logger.info("[CurlMultiDownloader] Aborting downloads: g_keep_running=" +
                           std::to_string(g_keep_running.load()) +
                           " g_hot_switch_requested=" +
                           std::to_string(g_hot_switch_requested.load()));
                break;
            }
            curl_multi_perform(multi_handle_, &still_running);
            int numfds;
            curl_multi_wait(multi_handle_, nullptr, 0, 100, &numfds);

            CURLMsg* msg;
            int msgs_left;
            while ((msg = curl_multi_info_read(multi_handle_, &msgs_left))) {
                if (msg->msg == CURLMSG_DONE) {
                    CURL* curl = msg->easy_handle;
                    CURLcode res = msg->data.result;

                    auto it = std::find_if(handles_.begin(), handles_.end(),
//                        [curl](const auto& t) { return std::get<0>(t) == curl; });
                        [curl](const TaskHandle& t) { return t.curl == curl; });
                    
                    if (it != handles_.end()) {
                        // ИЗМЕНЕНИЕ 5: Распаковка 4 элементов
//                        auto& [c_handle, file, fpath, headers] = *it;
                        TaskHandle& task = *it;
                        
                        bool success = (res == CURLE_OK);
                        long http_code = 0;
                        if (success) {
                            curl_easy_getinfo(curl, CURLINFO_RESPONSE_CODE, &http_code);
                            success = (http_code >= 200 && http_code < 300);
                        }
                        
                        curl_multi_remove_handle(multi_handle_, curl);
                        
                        if (task.file) {
                            fclose(task.file);
                            task.file = nullptr;
                        }

                        if (task.direct_write) {
                            // direct_write: файл уже на финальном пути, rename не нужен
                            std::remove((task.fpath + ".downloading").c_str());
                            if (success) {
                                std::error_code ec;
                                auto size = fs::file_size(task.fpath, ec);
                                if (!ec && size > 0) {
                                    results.emplace_back(true, task.fpath);
                                } else {
                                    logger.error("Direct write file empty: " + task.fpath);
                                    std::remove(task.fpath.c_str());
                                    results.emplace_back(false, task.fpath);
                                }
                            } else {
                                std::remove(task.fpath.c_str());
                                results.emplace_back(false, task.fpath);
                            }
                        } else if (success) {
                            std::error_code ec;
                            auto size = fs::file_size(task.fpath + ".tmp", ec);
                            if (!ec && size > 0) {
                                if (std::rename((task.fpath + ".tmp").c_str(), task.fpath.c_str()) == 0) {
                                    results.emplace_back(true, task.fpath);
                                } else {
                                    logger.error("Rename FAILED for " + task.fpath + ": " + std::string(strerror(errno)));
                                    std::remove((task.fpath + ".tmp").c_str());
                                    results.emplace_back(false, task.fpath);
                                }   
                            } else {
                                logger.error("File empty: " + task.fpath + ".tmp");
                                std::remove((task.fpath + ".tmp").c_str());
                                results.emplace_back(false, task.fpath);
                            }
                        } else {
                            std::remove((task.fpath + ".tmp").c_str());
                            results.emplace_back(false, task.fpath);
                        }

                        // Освобождение ресурсов
                        if (task.headers) curl_slist_free_all(task.headers);
                        curl_easy_cleanup(curl);

                        // OPTIMIZED: unique_ptr automatically deletes wctx - no manual delete needed

                        handles_.erase(it);
                    }
                }
            }
        }

        // Очистка оставшихся (если loop прерван аварийно или остались хвосты)
        for (auto& task : handles_) {
            curl_multi_remove_handle(multi_handle_, task.curl);
            if (task.file) {
                fclose(task.file);
                if (task.direct_write) {
                    std::remove(task.fpath.c_str());
                    std::remove((task.fpath + ".downloading").c_str());
                } else {
                    std::remove((task.fpath + ".tmp").c_str());
                }
            }
            if (task.headers) curl_slist_free_all(task.headers);
            curl_easy_cleanup(task.curl);
            // OPTIMIZED: unique_ptr automatically deletes wctx - no manual delete needed
            results.emplace_back(false, task.fpath);
        }
        handles_.clear();

        return results;
    }

    // Метод для очистки всех незавершённых задач (для HOT SWITCH)
    void clear() {
        for (auto& task : handles_) {
            curl_multi_remove_handle(multi_handle_, task.curl);
            if (task.file) {
                fclose(task.file);
                if (task.direct_write) {
                    std::remove(task.fpath.c_str());
                    std::remove((task.fpath + ".downloading").c_str());
                } else {
                    std::remove((task.fpath + ".tmp").c_str());
                }
            }
            if (task.headers) curl_slist_free_all(task.headers);
            curl_easy_cleanup(task.curl);
            // OPTIMIZED: unique_ptr automatically deletes wctx - no manual delete needed
        }
        handles_.clear();
        logger.info("[CurlMultiDownloader] Cleared all pending tasks");
    }
};


bool load_sputnik_sig(const std::string& channel_id, SputnikSig& sig) {
    std::string path = get_sputnik_sig_file_path(channel_id);
    if (!fs::exists(path)) return false;

    std::ifstream file(path);
    if (!file.is_open()) return false;

    Json::Value root;
    Json::CharReaderBuilder builder;
    std::string errs;
    if (!Json::parseFromStream(builder, file, &root, &errs)) return false;

    if (root.isMember("channel_source") && root.isMember("expire_date")) {
        sig.channel_source = root["channel_source"].asString();
        sig.expire_date = root["expire_date"].asInt64();
        file.close();
        return true;
    }
    file.close();
    return false;
}

bool save_sputnik_sig(const std::string& channel_id, const std::string& source, long expire) {
    std::string path = get_sputnik_sig_file_path(channel_id);

    Json::Value root;
    root["channel_source"] = source;
    root["expire_date"] = static_cast<Json::Int64>(expire);

    std::ofstream file(path);
    if (!file.is_open()) return false;

    Json::StreamWriterBuilder builder;
    builder["indentation"] = "";
    file << Json::writeString(builder, root);
    file.close();
    fs::permissions(path, fs::perms::owner_read | fs::perms::owner_write);
    return true;
}

std::string get_valid_sputnik_source(const std::string& channel_id) {
    auto now_unix = std::chrono::duration_cast<std::chrono::seconds>(
        std::chrono::system_clock::now().time_since_epoch()
    ).count();

    auto now_steady = std::chrono::steady_clock::now();

    // 1. Проверяем кэш в памяти
    {
        std::unique_lock<std::shared_mutex> lock(g_sputnik_cache_mutex);
        auto it = g_sputnik_cache.find(channel_id);
        if (it != g_sputnik_cache.end()) {
            auto& e = it->second;
            if (e.expire_date > now_unix && (now_steady - e.last_check) < 30s) {
                return e.channel_source;
            }
        }
    }

    // 2. Если нет или протух — читаем файл
    SputnikSig sig;
    if (load_sputnik_sig(channel_id, sig) && sig.expire_date > now_unix && !sig.channel_source.empty()) {
        std::unique_lock<std::shared_mutex> lock(g_sputnik_cache_mutex);
        g_sputnik_cache[channel_id] = {sig.channel_source, sig.expire_date, now_steady};
        return sig.channel_source;
    }

    // 3. Обновляем через API
    std::string api_url = "https://api1.sputnik24.tv/api/v2/get-playlist-channel/" + channel_id + "?sig=undefined";
    logger.info("Sputnik24: refreshing source for channel_id=" + channel_id);

    HttpRequestSession session;
    std::map<std::string, std::string> headers = {
        { "X-Ref",           "https://sputnik24.tv" },
        { "Accept",          "application/json" }
    };

    std::string response;
    if (!session.get(api_url, response, headers)) {
        logger.error("Sputnik24: API request failed");
        // Попробуем вернуть старое из памяти
        std::unique_lock<std::shared_mutex> lock(g_sputnik_cache_mutex);
        auto it = g_sputnik_cache.find(channel_id);
        if (it != g_sputnik_cache.end()) return it->second.channel_source;
        return "";
    }

    Json::Value root;
    std::istringstream iss(response);
    std::string errs;
    if (!Json::parseFromStream(Json::CharReaderBuilder(), iss, &root, &errs)) { //|| !root["status"].asBool()) {
        logger.error("Sputnik24: invalid API response");
        return "";
    }

logger.info("DEBUG: status type = " + std::to_string(root["status"].type()) + 
            ", value = '" + root["status"].asString() + "'");

if (!root["status"].isBool() || !root["status"].asBool()) {
    if (root["status"].isString() && root["status"].asString() == "true") {
        logger.info("Sputnik24: status is string 'true' — treating as success");
    } else {
        logger.error("Sputnik24: invalid API response (status not true)");
        logger.error("Response body: " + response.substr(0, 600));
        return "";
    }
}

    std::string new_source = root["channel_source"].asString();
    if (new_source.empty()) return "";

    // Парсим expire из ?sig=1763450800_...
    long expire = 0;
    size_t pos = new_source.find("?sig=");
    if (pos != std::string::npos) {
        std::string sig = new_source.substr(pos + 5);
        size_t us = sig.find('_');
        if (us != std::string::npos) {
            try { expire = std::stol(sig.substr(0, us)); }
            catch (...) {}
        }
    }
    if (expire == 0) {
        expire = root["server_time"].asInt64() + 21600; // +6 часов
    }

    save_sputnik_sig(channel_id, new_source, expire);

    {
        std::unique_lock<std::shared_mutex> lock(g_sputnik_cache_mutex);
        g_sputnik_cache[channel_id] = {new_source, expire, now_steady};
    }

    logger.info("Sputnik24: source updated for channel_id=" + channel_id + ", valid until " + std::to_string(expire));
    return new_source;
}


/*int fast_download_initial_segments(const std::string& base_url,
                                  const std::vector<M3U8Playlist::Segment>& segments,
                                  const std::string& save_dir,
                                  double target_duration,
                                  HttpRequestSession& session,
                                  CurlMultiDownloader& downloader,
                                  std::function<void()> save_playlist_callback,
                                  bool is_first_run = false) {
    
    if (segments.empty()) return 0;
    bool is_peers_tv = (base_url.find("api.peers.tv") != std::string::npos);

    auto prepare_task = [&](size_t index, std::vector<std::pair<std::string, std::chrono::steady_clock::time_point>>& info_vec) -> bool {
        // ... (тело этой функции остается ВАШИМ, с обработкой AES и .tmp) ...
        const auto& seg = segments[index];
        std::string fname = get_local_filename(seg.sequence, seg.uri);
        std::string fpath = save_dir + "/" + fname;

        if (fs::exists(fpath)) return false; // Файл уже есть

        std::string url = url_join(base_url, seg.uri);
        if (url.empty()) return false;
        
        if (is_peers_tv) {
            std::unique_lock<std::shared_mutex> lock(g_peers_token_mutex);
            if (!g_peers_tv_cached_token.empty()) {
                url += (url.find('?') != std::string::npos ? "&token=" : "?token=") + g_peers_tv_cached_token;
            }
        }

        auto base_time = std::chrono::steady_clock::now();
        auto duration = std::chrono::duration<double>(target_duration + 2.0) * (index + 1);
        auto expire_time = base_time + std::chrono::duration_cast<std::chrono::steady_clock::duration>(duration);

        std::vector<uint8_t> key_bin;
        std::vector<uint8_t> iv_bin;

        if (seg.enc_state.active) {
            // ... (ваша логика загрузки ключей) ...
            std::string key_url = url_join(base_url, seg.enc_state.key_uri);
            if (is_peers_tv) {
                std::unique_lock<std::shared_mutex> lock(g_peers_token_mutex);
                if (!g_peers_tv_cached_token.empty()) {
                    key_url += (key_url.find('?') != std::string::npos ? "&token=" : "?token=") + g_peers_tv_cached_token;
                }
            }
            {
                std::unique_lock<std::shared_mutex> klock(g_key_cache_mutex);
                if (g_key_cache.count(key_url)) key_bin = g_key_cache[key_url];
            }
            if (key_bin.empty()) {
                std::string key_str;
                if (session.get(key_url, key_str) && key_str.size() == 16) {
                    key_bin.assign(key_str.begin(), key_str.end());
                    std::unique_lock<std::shared_mutex> klock(g_key_cache_mutex);
                    g_key_cache[key_url] = key_bin;
                } else {
                    return false; 
                }
            }
            iv_bin = seg.enc_state.iv;
        }

        // Downloader сам создает .tmp файлы
        if (downloader.add_task(url, fpath, key_bin, iv_bin)) {
            info_vec.emplace_back(fpath, expire_time);
            return true;
        }
        return false;
    };

    auto process_results = [&](const std::vector<std::pair<std::string, std::chrono::steady_clock::time_point>>& info_vec) -> int {
        auto results = downloader.run(); 
        int count = 0;
        for (const auto&[success, fpath] : results) {
            if (success) {
                count++;
                logger.info("Downloaded segment: " + fpath);
            }
        }
        return count;
    };

    // =========================================================================
    // ЛОГИКА РАННЕЙ ПУБЛИКАЦИИ И ЖЕСТКОГО ЛИМИТА "ПО 2 СЕГМЕНТА"
    // =========================================================================
    int total_downloaded = 0;
    size_t total_segments = segments.size();
    const size_t PLAYLIST_WINDOW = 15;
    size_t start_idx = (total_segments > PLAYLIST_WINDOW) ? (total_segments - PLAYLIST_WINDOW) : 0;
    
    const size_t MAX_BATCH_SIZE = 2; // СТРОГИЙ ЛИМИТ ОДНОВРЕМЕННЫХ ПОТОКОВ

    std::vector<std::pair<std::string, std::chrono::steady_clock::time_point>> batch_info;

    if (is_first_run) {
        // 1. РАННЯЯ ПУБЛИКАЦИЯ ПЛЕЙЛИСТА (ДО ТОГО, КАК НАЧАЛАСЬ ЗАГРУЗКА)
        if (save_playlist_callback) {
            logger.info("Fast Start: Publishing playlist EARLY.");
            save_playlist_callback();
        }

        // 2. Скачиваем Live Edge (например, 4 последних сегмента) пачками строго по 2
        size_t edge_offset = 4;
        if (total_segments < edge_offset) edge_offset = total_segments;
        size_t live_edge_start = total_segments - edge_offset;

        for (size_t i = live_edge_start; i < total_segments; ++i) {
            prepare_task(i, batch_info);
            
            // Как только в корзине 2 файла (или это последний файл) -> запускаем скачивание
            if (batch_info.size() >= MAX_BATCH_SIZE || i == total_segments - 1) {
                total_downloaded += process_results(batch_info);
                batch_info.clear(); // Очищаем корзину для следующей пары
            }
        }
        return total_downloaded; 
    } 
    else {
        // === ФОНОВЫЙ РЕЖИМ (Так же не более 2 потоков за раз) ===
        size_t edge_offset = 3;
        if (total_segments < edge_offset) edge_offset = total_segments;
        
        // Приоритет 1: Удерживаем Live Edge
        for (size_t i = total_segments - edge_offset; i < total_segments; ++i) {
            if (prepare_task(i, batch_info)) {
                if (batch_info.size() >= MAX_BATCH_SIZE) break;
            }
        }

        // Приоритет 2: Фоновая докачка старых сегментов (если слоты свободны)
        if (batch_info.size() < MAX_BATCH_SIZE) {
            for (size_t i = start_idx; i < total_segments - edge_offset; ++i) {
                if (prepare_task(i, batch_info)) {
                    if (batch_info.size() >= MAX_BATCH_SIZE) break;
                }
            }
        }

        // Выполняем загрузку 1 или 2 файлов
        if (!batch_info.empty()) {
            total_downloaded = process_results(batch_info);
            if (total_downloaded > 0 && save_playlist_callback) {
                save_playlist_callback();
            }
        }
    }

    return total_downloaded;
} */

int fast_download_initial_segments(const std::string& base_url,
                                  const std::vector<M3U8Playlist::Segment>& segments,
                                  const std::string& save_dir,
                                  double target_duration,
                                  HttpRequestSession& session,
                                  CurlMultiDownloader& downloader,
                                  std::function<void()> save_playlist_callback,
                                  bool is_first_run = false) {
    
    if (segments.empty()) return 0;
    bool is_peers_tv = (base_url.find("api.peers.tv") != std::string::npos);

    auto prepare_task = [&](size_t index, std::vector<std::pair<std::string, std::chrono::steady_clock::time_point>>& info_vec, bool direct_write = false) -> bool {
        const auto& seg = segments[index];
        std::string fname = get_local_filename(seg.sequence, seg.uri);
        std::string fpath = save_dir + "/" + fname;

        if (fs::exists(fpath)) return false; // Файл уже есть

        std::string url = url_join(base_url, seg.uri);
        if (url.empty()) return false;
        
        if (is_peers_tv) {
            std::unique_lock<std::shared_mutex> lock(g_peers_token_mutex);
            if (!g_peers_tv_cached_token.empty()) {
                url += (url.find('?') != std::string::npos ? "&token=" : "?token=") + g_peers_tv_cached_token;
            }
        }

        auto base_time = std::chrono::steady_clock::now();
        auto duration = std::chrono::duration<double>(target_duration + 2.0) * (index + 1);
        auto expire_time = base_time + std::chrono::duration_cast<std::chrono::steady_clock::duration>(duration);

        std::vector<uint8_t> key_bin;
        std::vector<uint8_t> iv_bin;

        if (seg.enc_state.active) {
            std::string key_url = url_join(base_url, seg.enc_state.key_uri);
            if (is_peers_tv) {
                std::unique_lock<std::shared_mutex> lock(g_peers_token_mutex);
                if (!g_peers_tv_cached_token.empty()) {
                    key_url += (key_url.find('?') != std::string::npos ? "&token=" : "?token=") + g_peers_tv_cached_token;
                }
            }
            {
                std::unique_lock<std::shared_mutex> klock(g_key_cache_mutex);
                if (g_key_cache.count(key_url)) key_bin = g_key_cache[key_url];
            }
            if (key_bin.empty()) {
                std::string key_str;
                if (session.get(key_url, key_str) && key_str.size() == 16) {
                    key_bin.assign(key_str.begin(), key_str.end());
                    std::unique_lock<std::shared_mutex> klock(g_key_cache_mutex);
                    g_key_cache[key_url] = key_bin;
                } else {
                    return false; 
                }
            }
            iv_bin = seg.enc_state.iv;
        }

        if (downloader.add_task(url, fpath, key_bin, iv_bin, direct_write)) {
            info_vec.emplace_back(fpath, expire_time);
            return true;
        }
        return false;
    };

    auto process_results = [&](const std::vector<std::pair<std::string, std::chrono::steady_clock::time_point>>& info_vec) -> int {
        auto results = downloader.run(); 
        int count = 0;
        for (const auto&[success, fpath] : results) {
            if (success) {
                count++;
                logger.info("Downloaded segment: " + fpath);
            }
        }
        return count;
    };

    // =========================================================================
    // ОПТИМИЗИРОВАННАЯ ЛОГИКА: ТОЛЬКО LIVE EDGE (БЕЗ ЗАГРУЗКИ ИСТОРИИ)
    // =========================================================================
    int total_downloaded = 0;
    size_t total_segments = segments.size();
    const size_t MAX_BATCH_SIZE = 2;

    std::vector<std::pair<std::string, std::chrono::steady_clock::time_point>> batch_info;

    // === СТРОГОЕ СООТВЕТСТВИЕ СТАНДАРТУ HLS RFC 8216 ===
    // Section 6.3.3: "The client SHOULD NOT choose a segment that starts
    // less than three target durations from the end of the Playlist file."
    //
    // Это означает: начинать воспроизведение с позиции "3 сегмента от конца"

    size_t edge_offset = 4;
    if (total_segments < edge_offset) edge_offset = total_segments;
    size_t live_edge_start = total_segments - edge_offset;

/*    if (is_first_run) {
        // === РЕЖИМ МОМЕНТАЛЬНОГО СТАРТА ===
        // Первый сегмент загружаем напрямую (direct_write) — файл появляется на диске
        // сразу при начале загрузки, Nginx может отдавать его клиенту ещё до завершения.
        // Плейлист публикуется ДО начала загрузки.

        // 1. Запускаем загрузку первого сегмента в режиме direct_write
        //    (файл пишется сразу по финальному пути, без .tmp)
        bool first_task_added = false;
        if (live_edge_start < total_segments) {
            first_task_added = prepare_task(live_edge_start, batch_info, true);
        }

        // 2. Публикуем плейлист СРАЗУ — файл первого сегмента уже создан (пусть и пуст)
        //    Nginx начнёт отдавать данные по мере их записи на диск
        if (save_playlist_callback) {
            logger.info("Instant Start: Publishing playlist BEFORE download (direct_write mode)");
            save_playlist_callback();
        }

        // 3. Дожидаемся завершения загрузки первого сегмента
        if (first_task_added) {
            int downloaded = process_results(batch_info);
            if (downloaded > 0) {
                total_downloaded += downloaded;
                logger.info("Instant Start: First segment downloaded (direct_write), player can start immediately");
            }
            batch_info.clear();
        }

        // 4. Остальные сегменты загружаем обычным способом (через .tmp)
        for (size_t i = live_edge_start + 1; i < total_segments; ++i) {
            batch_info.clear();
            if (prepare_task(i, batch_info)) {
                int downloaded = process_results(batch_info);
                if (downloaded > 0) {
                    total_downloaded += downloaded;
                    if (save_playlist_callback && i < total_segments - 1) {
                        logger.info("Instant Start: Added segment " + std::to_string(i - live_edge_start + 1) +
                                   "/" + std::to_string(edge_offset) + " to playlist");
                        save_playlist_callback();
                    }
                }
            }
        }
    }*/
if (is_first_run) {
    // 1. Первый сегмент — direct_write для мгновенного streaming
    bool first_task_added = false;
    if (live_edge_start < total_segments) {
        first_task_added = prepare_task(live_edge_start, batch_info, true);
    }

    // 2. Публикуем плейлист ДО завершения загрузки
    if (save_playlist_callback) {
        logger.info("Instant Start: Publishing playlist BEFORE download");
        save_playlist_callback();
    }

    // 3. Ждём первый сегмент
    if (first_task_added) {
        total_downloaded += process_results(batch_info);
        batch_info.clear();
    }

    // 4. Остальные сегменты live edge — ПАРАЛЛЕЛЬНО пачками по 2
    size_t i = live_edge_start + 1;
    while (i < total_segments) {
        batch_info.clear();
        size_t batch_end = std::min(i + (size_t)2, total_segments);  // пачка 2
        for (size_t j = i; j < batch_end; ++j) {
            prepare_task(j, batch_info, false);
        }
        if (!batch_info.empty()) {
            int n = process_results(batch_info);
            total_downloaded += n;
            if (n > 0 && save_playlist_callback) {
                save_playlist_callback();
            }
        }
        i = batch_end;
    }
    return total_downloaded;
}
    else {
        // === ФОНОВЫЙ РЕЖИМ (Универсальная логика для любого размера плейлиста) ===
        // Скачиваем все сегменты которых нет на диске, пачками по 2
        bool any_new_segments = false;
        for (size_t i = 0; i < total_segments; ++i) {
            const auto& seg = segments[i];
            std::string fname = get_local_filename(seg.sequence, seg.uri);
            std::string fpath = save_dir + "/" + fname;

            // Пропускаем если файл уже существует
            if (fs::exists(fpath)) continue;

            any_new_segments = true;

            // Добавляем задачу на скачивание
            if (prepare_task(i, batch_info)) {
                // Скачиваем пачками по 2 для эффективности
                if (batch_info.size() >= MAX_BATCH_SIZE) {
                    total_downloaded += process_results(batch_info);
                    batch_info.clear();
                }
            }
        }

        // Выполняем загрузку оставшихся сегментов
        if (!batch_info.empty()) {
            total_downloaded += process_results(batch_info);
            if (total_downloaded > 0 && save_playlist_callback) {
                save_playlist_callback();
            }
        }

        // Все сегменты уже на диске — нет новых в плейлисте (нормальная ситуация при poll < segment_duration)
        if (!any_new_segments) return -1;
    }

    return total_downloaded;
}

/*int fast_download_initial_segments(const std::string& base_url,
                                  const std::vector<M3U8Playlist::Segment>& segments,
                                  const std::string& save_dir,
                                  double target_duration,
                                  HttpRequestSession& session, // Синхронная сессия (оставим для AES)
                                  CurlMultiDownloader& downloader,
                                  std::function<void()> save_playlist_callback,
                                  bool is_first_run = false) {
    
    if (segments.empty()) return 0;
    bool is_peers_tv = (base_url.find("api.peers.tv") != std::string::npos);

    // Хелпер создания задачи
    auto prepare_task = [&](size_t index, std::vector<std::pair<std::string, std::chrono::steady_clock::time_point>>& info_vec) -> bool {
        const auto& seg = segments[index];
        std::string fname = get_local_filename(seg.sequence, seg.uri);
        std::string fpath = save_dir + "/" + fname;

        if (fs::exists(fpath)) return false; 

        std::string url = url_join(base_url, seg.uri);
        if (url.empty()) return false;
        
        if (is_peers_tv) {
            std::unique_lock<std::shared_mutex> lock(g_peers_token_mutex);
            if (!g_peers_tv_cached_token.empty()) {
                url += (url.find('?') != std::string::npos ? "&token=" : "?token=") + g_peers_tv_cached_token;
            }
        }

        auto expire_time = std::chrono::steady_clock::now() + 
                           std::chrono::duration_cast<std::chrono::steady_clock::duration>(
                               std::chrono::duration<double>(target_duration + 2.0) * (index + 1));

        std::vector<uint8_t> key_bin;
        std::vector<uint8_t> iv_bin;

        // AES-128
        if (seg.enc_state.active) {
            std::string key_url = url_join(base_url, seg.enc_state.key_uri);
            if (is_peers_tv) {
                std::unique_lock<std::shared_mutex> lock(g_peers_token_mutex);
                if (!g_peers_tv_cached_token.empty()) {
                    key_url += (key_url.find('?') != std::string::npos ? "&token=" : "?token=") + g_peers_tv_cached_token;
                }
            }
            {
                std::unique_lock<std::shared_mutex> klock(g_key_cache_mutex);
                if (g_key_cache.count(key_url)) key_bin = g_key_cache[key_url];
            }
            if (key_bin.empty()) {
                std::string key_str;
                // ВНИМАНИЕ: Это синхронный блок. Он затормозит первый сегмент.
                // Но так как ключ кэшируется, это произойдет только 1 раз.
                if (session.get(key_url, key_str) && key_str.size() == 16) {
                    key_bin.assign(key_str.begin(), key_str.end());
                    std::unique_lock<std::shared_mutex> klock(g_key_cache_mutex);
                    g_key_cache[key_url] = key_bin;
                } else {
                    return false; 
                }
            }
            iv_bin = seg.enc_state.iv;
        }

        if (downloader.add_task(url, fpath, key_bin, iv_bin)) {
            info_vec.emplace_back(fpath, expire_time);
            return true;
        }
        return false;
    };

    auto process_results = [&](const std::vector<std::pair<std::string, std::chrono::steady_clock::time_point>>& info_vec) -> int {
        auto results = downloader.run(); 
        int count = 0;
        for (const auto&[success, fpath] : results) if (success) count++;
        return count;
    };

    int total_downloaded = 0;
    size_t total_segments = segments.size();
    const size_t PLAYLIST_WINDOW = 15;
    size_t start_idx = (total_segments > PLAYLIST_WINDOW) ? (total_segments - PLAYLIST_WINDOW) : 0;
    
    const size_t MAX_BATCH_SIZE = 2; 
    std::vector<std::pair<std::string, std::chrono::steady_clock::time_point>> batch_info;

    if (is_first_run) {
        // =====================================================================
        // >>> УМНЫЙ РАСЧЕТ БУФЕРА (ЗАЩИТА ОТ ТЯЖЕЛЫХ ФАЙЛОВ) <<<
        // =====================================================================
        size_t edge_offset = 4; // По умолчанию качаем 4 куска (например, по 2 сек = 8 сек видео)
        
        if (target_duration >= 8.0) {
            // Если сегменты длинные (10 секунд), нам хватит всего ДВУХ сегментов (20 сек видео!)
            // Качать 4 куска по 10 сек (40 МБ) на старте — это убить канал и увеличить TTFF.
            edge_offset = 2; 
        } else if (target_duration >= 5.0) {
            edge_offset = 3;
        }

        if (total_segments < edge_offset) edge_offset = total_segments;
        size_t live_edge_start = total_segments - edge_offset;

        // Добавляем задачи (создаем пустые файлы для Chunked Streaming)
        for (size_t i = live_edge_start; i < total_segments; ++i) {
            prepare_task(i, batch_info);
        }

        // EARLY PUBLISH (Отдаем плейлист ДО старта скачивания)
        if (save_playlist_callback) {
            logger.info("Fast Start: Target Duration is " + std::to_string(target_duration) + 
                        ". Queued " + std::to_string(edge_offset) + " segments. Publishing EARLY.");
            save_playlist_callback();
        }

        // Выполняем скачивание батча
        if (!batch_info.empty()) {
            total_downloaded += process_results(batch_info);
        }
    } 
    else {
        // === ФОНОВЫЙ РЕЖИМ (Как и просили, качаем по 2 штуки с конца) ===
        size_t edge_offset = (target_duration >= 8.0) ? 2 : 4;
        if (total_segments < edge_offset) edge_offset = total_segments;
        
        // Качаем только Live Edge (историю не трогаем)
        for (size_t i = total_segments - edge_offset; i < total_segments; ++i) {
            if (prepare_task(i, batch_info)) {
                if (batch_info.size() >= MAX_BATCH_SIZE) break;
            }
        }

        if (!batch_info.empty()) {
            total_downloaded = process_results(batch_info);
            if (total_downloaded > 0 && save_playlist_callback) {
                save_playlist_callback();
            }
        }
    }

    return total_downloaded;
} */

// === Utility functions ===
void clear_directory_files(const std::string& dir) {
    try {
        if (!fs::exists(dir)) return;
        
        for (const auto& entry : fs::directory_iterator(dir)) {
            try {
                fs::remove(entry.path());
                //logger.info("Удален файл: " + entry.path().string());
            } catch (const std::exception& e) {
                logger.error("Ошибка при удалении файла " + entry.path().string() + ": " + e.what());
            }
        }
    } catch (const std::exception& e) {
        logger.error("Ошибка при чтении директории " + dir + ": " + e.what());
    }
}


bool check_last_access_file(const std::string& save_dir,
                           const std::string& channel,
                           const std::string& provider,
                           int slot,
                           const std::string& token,
                           SlotManager& manager,
                           double target_duration,
                           bool& client_has_connected_once,
			   int64_t allocated_at = 0) {

    // Если ожидается HOT SWITCH — не останавливаем процесс.
    // save_dir ещё указывает на старый канал, last_access там не обновляется,
    // но после обработки SWITCH save_dir переключится на новый канал.
    if (g_hot_switch_requested) return false;

    // Используем глобальные переменные вместо static
    auto now = std::chrono::steady_clock::now();
    // Проверяем раз в 0.5 сек для точности на старте
    if ((now - g_last_check_time) < 500ms) return false;
    g_last_check_time = now;
    
    std::string path = save_dir + "/last_access";
    std::string playlist_path = save_dir + "/playlist.m3u8";
    
    // === СЦЕНАРИЙ 1: Клиент здесь (или был здесь) ===
    if (fs::exists(path)) {
        client_has_connected_once = true; // Запоминаем, что клиент успешно подключился

        auto last_write = fs::last_write_time(path);
        auto now_sys = std::chrono::system_clock::now();
        auto last_write_sys = std::chrono::time_point_cast<std::chrono::system_clock::duration>(
            last_write - std::filesystem::file_time_type::clock::now() + std::chrono::system_clock::now());
        auto diff = std::chrono::duration_cast<std::chrono::seconds>(now_sys - last_write_sys).count();
        
        // ФОРМУЛА ИЗ ВАШЕГО ТЗ: MAX(6, TARGET * 1.5)
        // Если таргет 12с -> ждем 18с. Если таргет 2с -> ждем 6с.
        //double keepalive_timeout = std::max(6.0, target_duration * 2.0);
// Ограничиваем target_duration сверху (максимум 30 сек), 
// чтобы сломанный провайдер не сделал таймаут бесконечным.

//double keepalive_timeout = std::max(6.0, safe_target * 2.0);
//        double keepalive_timeout = std::max(6.0, target_duration * 1.5);
//double safe_target = std::min(target_duration, 20.0);
//double keepalive_timeout = std::max(25.0, safe_target * 2.0);
double calc_timeout = target_duration * 1.4; // Берем время сегмента с запасом 50%
double keepalive_timeout = std::min(25.0, std::max(15.0, calc_timeout));


        if (diff > keepalive_timeout) {
            // Re-check: если за время проверки пришёл HOT SWITCH — НЕ останавливаем
            if (g_hot_switch_requested) {
                logger.info("Keep-alive timeout, but HOT SWITCH pending → skipping stop");
                return false;
            }
            logger.info("Keep-alive timeout (" + std::to_string(diff) + "s > " + std::to_string(keepalive_timeout) + "s) → stopping");
            manager.mark_allocation_dying(channel);
	    clear_directory_files(save_dir);
            manager.notify_proxy_stop(channel, provider, slot, token, allocated_at);
            g_keep_running = false;
            return true;
        }
        return false;
    }
    
    // === СЦЕНАРИЙ 2: Клиент еще НЕ подключился (last_access нет) ===
    
    if (client_has_connected_once) {
        // Ситуация: клиент был, но удалил файл last_access (маловероятно, но вдруг).
        // Re-check: если за время проверки пришёл HOT SWITCH — НЕ останавливаем
        if (g_hot_switch_requested) return false;
        logger.info("Client disappeared (file missing) → stopping");
        manager.mark_allocation_dying(channel);
        manager.notify_proxy_stop(channel, provider, slot, token, allocated_at);
        g_keep_running = false;
        return true;
    }

    // Клиента еще не было ни разу.
    if (fs::exists(playlist_path)) {
        // Плейлист УЖЕ создан. Плеер должен запросить его МГНОВЕННО.
        if (g_playlist_detected_time == std::chrono::steady_clock::time_point::min()) {
             g_playlist_detected_time = now;
        }

        auto wait_time = std::chrono::duration_cast<std::chrono::seconds>(now - g_playlist_detected_time).count();
        
        // ЖЕСТКИЙ ЛИМИТ НА СТАРТЕ: 3 секунды
        if (wait_time >= 8) { 
             if (g_hot_switch_requested) return false;
             logger.info("Startup Timeout: Playlist ready, but no client for 8s → stopping (zapping?)");
             manager.mark_allocation_dying(channel);
             manager.notify_proxy_stop(channel, provider, slot, token, allocated_at);
             g_keep_running = false;
             return true;
        }
    } else {
        // Плейлиста еще нет (качаем источник).
        // Тут даем чуть больше времени на скачивание первого сегмента (например, 7-10 сек).
        auto elapsed = std::chrono::duration_cast<std::chrono::seconds>(now - g_monitoring_start_time).count();
        if (elapsed >= 15) {
             if (g_hot_switch_requested) return false;
             logger.error("Startup Timeout: Source too slow (no playlist in 15s) → stopping");
             manager.mark_allocation_dying(channel);
             manager.notify_proxy_stop(channel, provider, slot, token, allocated_at);
             g_keep_running = false;
             return true;
        }
    }
    return false;
}

/*void clean_expired_segments(const std::string& save_dir) {
    auto current_time = std::chrono::steady_clock::now();
    int expired_count = 0;
    
    std::lock_guard<std::mutex> lock(segments_mutex);
    
    auto it = downloaded_segments.begin();
    while (it != downloaded_segments.end()) {
        if (current_time >= it->expire_time) {
            try {
                if (fs::exists(it->file_path)) {
                    fs::remove(it->file_path);
                    logger.info("Удален просроченный сегмент: " + it->file_path);
                    expired_count++;
                }
                it = downloaded_segments.erase(it);
            } catch (const std::exception& e) {
                logger.error("Ошибка при удалении сегмента " + it->file_path + ": " + e.what());
                ++it;
            }
        } else {
            ++it;
        }
    }
    
    if (expired_count == 0) {
        logger.info("Нет устаревших сегментов для удаления в " + save_dir);
    } else {
        logger.info("Удалено " + std::to_string(expired_count) + " просроченных сегментов");
    }
}

void run_cleanup_task(const std::string& save_dir) {
    while (!stop_cleanup_event) {
        clean_expired_segments(save_dir);
        std::this_thread::sleep_for(std::chrono::seconds(CLEANUP_INTERVAL));
    }
} */

// === Quality selection (exact Python equivalent) ===
M3U8Playlist::Playlist* select_variant_by_quality(M3U8Playlist& playlist, const std::string& quality, long target_bandwidth) {
    if (playlist.playlists.empty()) return nullptr;

    if (quality.empty() && target_bandwidth <= 0) {
        logger.info("No quality/bandwidth specified, selecting first playlist");
        return playlist.playlists.empty() ? nullptr : &playlist.playlists[0];
    }
    
    std::string clean_quality = quality;
    std::replace(clean_quality.begin(), clean_quality.end(), '*', 'x');
    
   // Парсим целевое разрешение
    int target_w = 0, target_h = 0;
    bool has_resolution_target = false;
    
    if (!quality.empty()) {
        std::string clean_quality = quality;
        std::replace(clean_quality.begin(), clean_quality.end(), '*', 'x');
        size_t x_pos = clean_quality.find('x');
        if (x_pos != std::string::npos) {
            try {
                target_w = std::stoi(clean_quality.substr(0, x_pos));
                target_h = std::stoi(clean_quality.substr(x_pos + 1));
                has_resolution_target = true;
            } catch (...) {}
        }
    }
    
    std::vector<std::tuple<int, int, M3U8Playlist::Playlist*>> candidates;
    
/*    for (auto& pl : playlist.playlists) {
        if (pl.stream_info.resolution.first > 0 && pl.stream_info.resolution.second > 0) {
            int w = pl.stream_info.resolution.first;
            int h = pl.stream_info.resolution.second;
            int diff = std::abs(w - target_w) + std::abs(h - target_h);
            int bandwidth = pl.stream_info.bandwidth;
            candidates.push_back(std::make_tuple(diff, -bandwidth, &pl));
        }
    } */  
      for (auto& pl : playlist.playlists) {
        int w = pl.stream_info.resolution.first;
        int h = pl.stream_info.resolution.second;
        int bw = pl.stream_info.bandwidth;

        // 1. Расчет разницы разрешения
        long res_diff = 0;
        if (has_resolution_target) {
            if (w > 0 && h > 0) {
                res_diff = std::abs(w - target_w) + std::abs(h - target_h);
            } else {
                // Если у потока нет данных о разрешении, даем ему большой штраф,
                // чтобы он выбирался только если других нет.
                res_diff = 100000; 
            }
        }

        // 2. Расчет разницы битрейта
        long bw_diff = 0;
        if (target_bandwidth > 0) {
            // Ищем ближайший к целевому (минимальная разница по модулю)
            bw_diff = std::abs(bw - (int)target_bandwidth);
        } else {
            // Если цель не задана, берем максимальный битрейт (как было раньше)
            // Используем отрицательное значение для сортировки по возрастанию (самый маленький = самый большой битрейт)
            bw_diff = -bw; 
        }

        candidates.push_back(std::make_tuple(res_diff, bw_diff, &pl));
    }
    
    if (!candidates.empty()) {
        // Сортируем: сначала по разрешению, потом по битрейту
        std::sort(candidates.begin(), candidates.end());
        auto& selected = std::get<2>(candidates[0]);
        
        std::string res_str = std::to_string(selected->stream_info.resolution.first) + "x" + 
                              std::to_string(selected->stream_info.resolution.second);
        
        logger.info("Selected variant: " + res_str + 
                    ", bandwidth: " + std::to_string(selected->stream_info.bandwidth) +
                    " (Target: " + quality + " / " + std::to_string(target_bandwidth) + ")");
                    
        return selected;
    }
    
    logger.warning("No matching variants found, selecting first playlist");
    return &playlist.playlists[0];
    //return playlist.playlists.empty() ? nullptr : &playlist.playlists[0];
}

// === Main HLS checking logic ===
struct CheckResult {
    std::string status;
    std::string message;
    std::map<std::string, std::string> details;
    std::vector<M3U8Playlist::Segment> active_segments;
    std::string effective_media_url;
};

// ==========================================
// >>> 2. ИСПРАВЛЕННАЯ ОЧИСТКА (TTL 60 sec)
// ==========================================
void synchronous_cleanup(const std::string& save_dir, const std::vector<M3U8Playlist::Segment>& active_segments) {
    std::set<std::string> active_files;
    for (const auto& seg : active_segments) {
        active_files.insert(get_local_filename(seg.sequence, seg.uri));
    }

    if (!fs::exists(save_dir)) return;

    // Получаем текущее время для проверки возраста файлов
    auto now = std::chrono::system_clock::now();
    const auto MAX_FILE_AGE = std::chrono::seconds(90); // Храним файлы 90 секунд после выхода из плейлиста

    try {
        for (const auto& entry : fs::directory_iterator(save_dir)) {
            if (!entry.is_regular_file()) continue;

            std::string filename = entry.path().filename().string();

            if (filename == "playlist.m3u8" || filename == "playlist.m3u8.tmp" ||
                filename == "last_access" || filename.find(".lock") != std::string::npos ||
                filename.find("update_") != std::string::npos) {
                continue;
            }

            if (filename.find(".tmp") != std::string::npos) continue;

            // Не удаляем активные файлы (которые в текущем плейлисте)
            if (active_files.count(filename)) continue;

            // Проверяем возраст файла - удаляем только если старше 90 секунд
            try {
                auto file_time = fs::last_write_time(entry.path());
                auto file_age = now - std::chrono::time_point_cast<std::chrono::system_clock::duration>(
                    file_time - fs::file_time_type::clock::now() + std::chrono::system_clock::now()
                );

                if (file_age > MAX_FILE_AGE) {
                    std::error_code ec;
                    fs::remove(entry.path(), ec);
                }
            } catch (...) {
                // Если не можем определить возраст, не удаляем
            }
        }
    } catch (...) {}
}

// ============================================================================
// HLS PIPELINE PROCESSOR: 2-THREAD PIPELINE FOR HLS MODE
// Thread 1 (main/download): Polls playlist + downloads segments
// Thread 2 (writer): Writes playlist.m3u8 (ZeroCopy) + cleans up old segments
//
// Benefits:
// - Download thread doesn't block on disk I/O (playlist write + cleanup)
// - Playlist writes use mmap (ZeroCopy) with atomic rename
// - Old segment cleanup runs in background without blocking downloads
// - Next playlist poll can start immediately after segments are downloaded
// ============================================================================
class HlsPipelineProcessor {
public:
    struct PlaylistWriteTask {
        std::string content;
        std::string path;
        std::vector<M3U8Playlist::Segment> active_segments;
        std::string save_dir;
    };

private:
    std::thread writer_thread_;
    std::queue<PlaylistWriteTask> write_queue_;
    std::mutex queue_mutex_;
    std::condition_variable write_cv_;
    std::atomic<bool> running_{true};
    bool use_zero_copy_;

    void writer_thread_func() {
        logger.info("HlsPipeline: Writer thread started");
        while (running_ || !write_queue_.empty()) {
            PlaylistWriteTask task;
            {
                std::unique_lock<std::mutex> lock(queue_mutex_);
                write_cv_.wait_for(lock, std::chrono::milliseconds(200), [this] {
                    return !write_queue_.empty() || !running_;
                });
                if (write_queue_.empty()) {
                    if (!running_) break;
                    continue;
                }
                // Keep only the latest task — older playlists are stale
                while (write_queue_.size() > 1) {
                    write_queue_.pop();
                }
                task = std::move(write_queue_.front());
                write_queue_.pop();
            }

            if (!g_keep_running) break;

            // Write playlist.m3u8 using ZeroCopy (atomic via tmp + rename)
            if (use_zero_copy_ && !task.content.empty()) {
                write_playlist_zerocopy(task.path, task.content);
            } else if (!task.content.empty()) {
                std::ofstream out(task.path);
                if (out.is_open()) {
                    out << task.content;
                    out.close();
                }
            }

            // Background cleanup of old segments
            if (!task.active_segments.empty() && !task.save_dir.empty()) {
                synchronous_cleanup(task.save_dir, task.active_segments);
            }
        }
        logger.info("HlsPipeline: Writer thread stopped");
    }

    void write_playlist_zerocopy(const std::string& path, const std::string& content) {
        std::string tmp_path = path + ".pipe.tmp";
        int fd = ::open(tmp_path.c_str(), O_RDWR | O_CREAT | O_TRUNC, 0644);
        if (fd < 0) {
            std::ofstream out(path);
            if (out.is_open()) out << content;
            return;
        }

        size_t size = content.size();
        if (size == 0) {
            ::close(fd);
            ::unlink(tmp_path.c_str());
            return;
        }

        if (ftruncate(fd, size) < 0) {
            ::close(fd);
            ::unlink(tmp_path.c_str());
            std::ofstream out(path);
            if (out.is_open()) out << content;
            return;
        }

        void* mapped = mmap(nullptr, size, PROT_WRITE, MAP_SHARED, fd, 0);
        if (mapped == MAP_FAILED) {
            ::close(fd);
            ::unlink(tmp_path.c_str());
            std::ofstream out(path);
            if (out.is_open()) out << content;
            return;
        }

        memcpy(mapped, content.data(), size);
        msync(mapped, size, MS_ASYNC);
        munmap(mapped, size);
        ::close(fd);

        // Atomic rename — readers always see a complete playlist
        std::rename(tmp_path.c_str(), path.c_str());
    }

public:
    explicit HlsPipelineProcessor(bool zero_copy = true)
        : use_zero_copy_(zero_copy) {
        writer_thread_ = std::thread(&HlsPipelineProcessor::writer_thread_func, this);
        logger.info("HlsPipeline: Initialized (zero_copy=" +
                     std::string(zero_copy ? "true" : "false") + ")");
    }

    ~HlsPipelineProcessor() {
        stop();
    }

    // Non-blocking: submit playlist write + cleanup to background thread
    void submit_write(const std::string& content, const std::string& path,
                      const std::vector<M3U8Playlist::Segment>& active_segments,
                      const std::string& save_dir) {
        std::lock_guard<std::mutex> lock(queue_mutex_);
        write_queue_.push({content, path, active_segments, save_dir});
        write_cv_.notify_one();
    }

    // Submit just a cleanup task (no playlist write)
    void submit_cleanup(const std::vector<M3U8Playlist::Segment>& active_segments,
                        const std::string& save_dir) {
        std::lock_guard<std::mutex> lock(queue_mutex_);
        write_queue_.push({"", "", active_segments, save_dir});
        write_cv_.notify_one();
    }

    void stop() {
        if (running_) {
            running_ = false;
            write_cv_.notify_all();
            if (writer_thread_.joinable()) writer_thread_.join();
        }
    }

    bool is_running() const { return running_; }

    // Restart pipeline after HOT SWITCH (stop old writer, start fresh)
    void restart() {
        stop();
        // Clear any remaining tasks
        {
            std::lock_guard<std::mutex> lock(queue_mutex_);
            std::queue<PlaylistWriteTask>().swap(write_queue_);
        }
        running_ = true;
        writer_thread_ = std::thread(&HlsPipelineProcessor::writer_thread_func, this);
        logger.info("HlsPipeline: Restarted");
    }
};

CheckResult check_hls_stream(const std::string& master_url,
                             const std::string& quality,
                             long target_bandwidth,
                             const std::string& archive_dir,
                             const std::string& channel,
                             const std::string& provider,
                             int slot,
                             CurlMultiDownloader& downloader, 
                             HttpRequestSession& session,
                             const std::string& source_ip, 
                             bool is_first_run,
                             int start_write_after_segment,
                             const std::string& user_agent,
                             HlsPipelineProcessor* hls_pipeline = nullptr) {
   
    CheckResult result;
    result.status = "unknown";
    std::string save_dir = BASE_DIR + "/" + channel;
    //HttpRequestSession playlist_session(source_ip);

    if (!user_agent.empty()) {
        session.set_user_agent(user_agent);
    }
   
    try {
        // --- 1. Загрузка плейлиста ---
        logger.info("Loading playlist from " + master_url);
        std::string playlist_text;
        if (!session.get(master_url, playlist_text)) {
            result.status = "error";
            result.message = "Failed to load playlist from " + master_url;
            logger.error(result.message);
            return result;
        }
       
        std::string effective_master_url = session.get_last_effective_url();
        logger.info("Effective master URL: " + effective_master_url);
       
        auto playlist = M3U8Playlist::loads(playlist_text);
       
        M3U8Playlist media_playlist;
        std::string media_text;
        std::string media_url;
        std::string effective_media_url;
        std::string resolution = "unknown";
       
        // --- 2. Обработка Master/Media плейлиста ---
        if (playlist.is_variant) {
            if (playlist.playlists.empty()) return { "error", "Master playlist has no variants", {} };
           
            auto variant = select_variant_by_quality(playlist, quality, target_bandwidth);
            if (!variant) return { "error", "No valid variant selected", {} };
           
            media_url = url_join(effective_master_url, variant->uri);
            if (variant->stream_info.resolution.first > 0) {
                resolution = std::to_string(variant->stream_info.resolution.first) + "x" +
                            std::to_string(variant->stream_info.resolution.second);
            }
            logger.info("Selected stream: " + resolution + " → " + media_url);
           
            if (!session.get(media_url, media_text)) return { "error", "Failed to load media playlist", {} };
           
            effective_media_url = session.get_last_effective_url();
            media_playlist = M3U8Playlist::loads(media_text);
        } else {
            // Если это Peers.TV (прямой media-плейлист)
            media_playlist = playlist;
            media_text = playlist_text;
            effective_media_url = session.get_last_effective_url();
        }

        if (media_playlist.segments.empty()) return { "error", "Media playlist has no segments", {} };

        // ========================================================================
        // >>> СОХРАНЕНИЕ ПЕРВОГО ПЛЕЙЛИСТА ДЛЯ АНАЛИЗА (ТОЛЬКО ПРИ is_first_run)
        // ========================================================================
        if (is_first_run) {
            try {
                std::string debug_dir = "/tmp/hls_debug";
                fs::create_directories(debug_dir);

                auto now = std::chrono::system_clock::now();
                auto time_t = std::chrono::system_clock::to_time_t(now);
                auto ms = std::chrono::duration_cast<std::chrono::milliseconds>(now.time_since_epoch()) % 1000;

                std::stringstream ss_name;
                std::tm tm_buf;
                localtime_r(&time_t, &tm_buf);
                ss_name << std::put_time(&tm_buf, "%Y%m%d_%H%M%S");
                ss_name << "_" << std::setfill('0') << std::setw(3) << ms.count();

                std::string debug_file = debug_dir + "/first_playlist_" + channel + "_" + ss_name.str() + ".m3u8";

                std::ofstream out(debug_file);
                if (out.is_open()) {
                    out << "# ORIGINAL PLAYLIST FROM PROVIDER\n";
                    out << "# Channel: " << channel << "\n";
                    out << "# URL: " << effective_media_url << "\n";
                    out << "# Total segments: " << media_playlist.segments.size() << "\n";
                    out << "# Target duration: " << media_playlist.target_duration << "\n";
                    out << "# Media sequence: " << media_playlist.media_sequence << "\n";
                    out << "# ==========================================\n\n";
                    out << media_text;
                    out.close();
                    logger.info("DEBUG: First playlist saved to " + debug_file);
                } else {
                    logger.warning("DEBUG: Failed to save first playlist to " + debug_file);
                }
            } catch (const std::exception& e) {
                logger.warning("DEBUG: Exception while saving first playlist: " + std::string(e.what()));
            }
        }
        // ========================================================================

        // ========================================================================
        // >>> 3. ЗАПЛАТКА: ДИНАМИЧЕСКИЙ ПЕРЕСЧЕТ TARGETDURATION
        // ========================================================================
        double real_max_duration = 0.0;
        for (const auto& seg : media_playlist.segments) {
            if (seg.duration > real_max_duration) {
                real_max_duration = seg.duration;
            }
        }
        
        int corrected_target_duration = std::ceil(real_max_duration);
        if (corrected_target_duration <= 0) {
            corrected_target_duration = media_playlist.target_duration > 0 ? media_playlist.target_duration : 6;
        }
        
        // ПРИМЕНЯЕМ ЗАПЛАТКУ КО ВСЕЙ СТРУКТУРЕ!
        media_playlist.target_duration = corrected_target_duration;
        // ========================================================================

        // =================================================================================
        // >>> 4. ЛОГИКА ИСПРАВЛЕНИЯ ЗАСТЫВШЕГО SEQUENCE
        // =================================================================================
        {
            std::lock_guard<std::mutex> lock(g_state_mutex);
            ChannelState& state = g_channel_states[channel];

            if (!state.initialized) {
                state.local_sequence = media_playlist.media_sequence;
                if (state.local_sequence < 0) state.local_sequence = 0;
                
                state.last_upstream_seq = media_playlist.media_sequence;
                if (!media_playlist.segments.empty()) {
                    state.last_first_uri = media_playlist.segments[0].uri;
                }
                state.last_size = media_playlist.segments.size();
                state.initialized = true;
            } else {
                long long diff = media_playlist.media_sequence - state.last_upstream_seq;

                if (diff > 0) {
                    state.local_sequence += diff;
                    state.freeze_counter = 0; 
                } else if (diff == 0) {
                    if (!media_playlist.segments.empty() && 
                        media_playlist.segments[0].uri != state.last_first_uri) {
                        
                        state.local_sequence++;
                        state.freeze_counter = 0; 
                        logger.info("Fix: Detected static sequence. Synthetically incremented.");
                    } else if (media_playlist.segments.size() > state.last_size) {
                        state.freeze_counter = 0;
                    } else {
                        state.freeze_counter++;
                        if (state.freeze_counter >= 3) {
                            logger.error("Source playlist is completely frozen for 3 checks. Declaring dead.");
                            state.freeze_counter = 0;
                            return { "fatal_broken", "Source playlist is frozen", {} };
                        }
                    }
                } else {
                    state.local_sequence++;
                    state.freeze_counter = 0; 
                }

                state.last_upstream_seq = media_playlist.media_sequence;
                if (!media_playlist.segments.empty()) {
                    state.last_first_uri = media_playlist.segments[0].uri;
                }
                state.last_size = media_playlist.segments.size();
            }

            media_playlist.media_sequence = state.local_sequence;

            long long correct_seq = media_playlist.media_sequence;
            for (auto& seg : media_playlist.segments) {
                seg.sequence = correct_seq++;
            }
            media_playlist.original_sequence = media_playlist.media_sequence;
        }
        // =================================================================================

        // =================================================================================
        // >>> 5. ЛОГИКА ОКОН И ОБРЕЗКИ (DISK WINDOW vs PLAYLIST WINDOW)
        // =================================================================================
        const size_t PLAYLIST_WINDOW = 15; // Столько сегментов увидит плеер в .m3u8
        const size_t SAFETY_BUFFER = 20;   // Столько храним про запас (для медленных клиентов)
        const size_t DISK_WINDOW = PLAYLIST_WINDOW + SAFETY_BUFFER; // Итого храним ~35 файлов

        bool is_event_like = media_playlist.is_event || media_playlist.segments.size() >= 30;

        if (is_event_like && media_playlist.segments.size() > DISK_WINDOW) {
            size_t remove_count = media_playlist.segments.size() - DISK_WINDOW;
            media_playlist.media_sequence += remove_count;
            
            media_playlist.segments.erase(
                media_playlist.segments.begin(), 
                media_playlist.segments.begin() + remove_count
            );
        }
        // =================================================================================

/*        // =================================================================================
        // >>> 6. ОПРЕДЕЛЕНИЕ КОЛБЭКА ДЛЯ ЗАПИСИ
        // =================================================================================
        auto write_playlist_callback = [&]() {
            long long start_seq = media_playlist.media_sequence;
            size_t total = media_playlist.segments.size();
            size_t start_idx = (total > PLAYLIST_WINDOW) ? (total - PLAYLIST_WINDOW) : 0;
            
            long long display_sequence = start_seq + start_idx;
            std::string local_pl = save_dir + "/playlist.m3u8";

            int valid_visible_on_disk = 0;
            for (size_t i = start_idx; i < total; ++i) {
                std::string loc_name = get_local_filename(media_playlist.segments[i].sequence, media_playlist.segments[i].uri);
                if (fs::exists(save_dir + "/" + loc_name)) {
                    valid_visible_on_disk++;
                }
            }

            int effective_threshold = (is_event_like || is_first_run) ? 0 : start_write_after_segment;
            if (valid_visible_on_disk <= effective_threshold && valid_visible_on_disk < (total - start_idx)) {
                 return; 
            }

            std::stringstream ss;
            ss << "#EXTM3U\n";
            
            if (media_playlist.global_tags.find("VERSION") == std::string::npos) {
                ss << "#EXT-X-VERSION:3\n";
            }
            ss << media_playlist.global_tags;

            // ЗДЕСЬ ПИШЕТСЯ ИСПРАВЛЕННЫЙ ТАРГЕТ!
            int safe_td = std::min((int)media_playlist.target_duration, 30);
            if (safe_td <= 0) safe_td = 10;
            
            ss << "#EXT-X-TARGETDURATION:" << safe_td << "\n";
            ss << "#EXT-X-MEDIA-SEQUENCE:" << display_sequence << "\n";

            for (size_t i = start_idx; i < total; ++i) {
                std::string fname = get_local_filename(media_playlist.segments[i].sequence, media_playlist.segments[i].uri);
                if (fs::exists(save_dir + "/" + fname)) {
                    ss << media_playlist.segments[i].raw_tags; 
                    ss << fname << "\n";
                }
            }

            std::string current_content = ss.str();
            
            std::ofstream local_file(local_pl);
            if (local_file.is_open()) {
               local_file << current_content;
               local_file.close();
            }*/

	// =================================================================================
        // >>> 6. ОПРЕДЕЛЕНИЕ КОЛБЭКА ДЛЯ ЗАПИСИ
        // =================================================================================
        auto write_playlist_callback = [&]() {
            long long start_seq = media_playlist.media_sequence;
            size_t total = media_playlist.segments.size();
            size_t start_idx = (total > PLAYLIST_WINDOW) ? (total - PLAYLIST_WINDOW) : 0;
            
            long long display_sequence = start_seq + start_idx;
            std::string local_pl = save_dir + "/playlist.m3u8";

            std::stringstream ss;
            ss << "#EXTM3U\n";
            
            if (media_playlist.global_tags.find("VERSION") == std::string::npos) {
                ss << "#EXT-X-VERSION:3\n";
            }
            ss << media_playlist.global_tags;

            int safe_td = std::min((int)media_playlist.target_duration, 30);
            if (safe_td <= 0) safe_td = 10;
            
            ss << "#EXT-X-TARGETDURATION:" << safe_td << "\n";
            ss << "#EXT-X-MEDIA-SEQUENCE:" << display_sequence << "\n";

            // === ПИШЕМ ВСЕ СЕГМЕНТЫ ОКНА "ВСЛЕПУЮ" (БЕЗ fs::exists) ===
            for (size_t i = start_idx; i < total; ++i) {
                std::string fname = get_local_filename(media_playlist.segments[i].sequence, media_playlist.segments[i].uri);
                ss << media_playlist.segments[i].raw_tags; 
                ss << fname << "\n";
            }

            std::string current_content = ss.str();

            // PIPELINE: Use async ZeroCopy write if pipeline is available
/*            if (hls_pipeline) {
                hls_pipeline->submit_write(current_content, local_pl,
                                           media_playlist.segments, save_dir);
            } else {
                std::ofstream local_file(local_pl);
                if (local_file.is_open()) {
                   local_file << current_content;
                   local_file.close();
                }
            }       */

if (hls_pipeline) {
    hls_pipeline->submit_write(current_content, local_pl,
                               media_playlist.segments, save_dir);
} else {
    std::ofstream local_file(local_pl);
    if (local_file.is_open()) {
       local_file << current_content;
       local_file.close();
    }
}

#ifdef ARCHIVE
            // Сохраняем слепки плейлистов ТОЛЬКО при самом первом запуске
            if (is_first_run) {
                try {
                    auto now = std::chrono::system_clock::now();
                    auto in_time_t = std::chrono::system_clock::to_time_t(now);
                    auto ms = std::chrono::duration_cast<std::chrono::milliseconds>(now.time_since_epoch()) % 1000;

                    std::stringstream ss_name;
                    std::tm tm_buf;
                    localtime_r(&in_time_t, &tm_buf);
                    ss_name << std::put_time(&tm_buf, "%H%M%S");
                    ss_name << "_" << std::setfill('0') << std::setw(3) << ms.count();

                    // Создаем подпапку конкретного канала (чтобы файлы не смешались в кашу)
                    std::string debug_dir = archive_dir + "/" + channel;
                    fs::create_directories(debug_dir); 

                    std::string debug_path = debug_dir + "/local_" + ss_name.str() + ".m3u8";

                    std::ofstream debug_file(debug_path);
                    if (debug_file.is_open()) {
                         debug_file << ss.str(); 
                         debug_file.close();
                    } else {
                         logger.error("ARCHIVE: Failed to write file " + debug_path);
                    }
                } catch (const std::exception& e) {
                    logger.error("ARCHIVE Exception: " + std::string(e.what()));
                }
            }
        #endif
        };

        // =================================================================================
        // >>> 7. ЗАГРУЗКА
        // =================================================================================
        logger.info("Downloading segments for channel " + channel);
        
        int downloaded_count = fast_download_initial_segments(
            effective_media_url, media_playlist.segments, save_dir,
            media_playlist.target_duration, session, downloader, 
            write_playlist_callback, 
            is_first_run);
           
        if (!is_first_run) write_playlist_callback();

        // СИНХРОННАЯ ОЧИСТКА
        //synchronous_cleanup(save_dir, media_playlist.segments); 
	result.active_segments = media_playlist.segments;
        result.status = "success";
        result.message = playlist.is_variant ? "Stream loaded" : "Media playlist loaded";
        result.details["downloaded_segments"] = std::to_string(downloaded_count);
        result.details["target_duration"] = std::to_string(media_playlist.target_duration);
        result.details["resolution"] = resolution;
        result.effective_media_url = effective_media_url;

        return result;
       
    } catch (const std::exception& e) {
        result.status = "error";
        result.message = "Error: " + std::string(e.what());
        logger.error(result.message);
        return result;
    }
}

void child_signal_handler(int sig) {
    if (sig == SIGTERM || sig == SIGINT) g_keep_running = false;
}

void release_ipv6(const std::string& token, const std::string& source_ip) {
return; //заглушка
    if (source_ip.empty() || token.empty()) return;

    try {
        if (fs::exists(IPV6_MAPPING_FILE)) {
            std::string lock_file = IPV6_MAPPING_FILE + ".lock";
            int lock_fd = open(lock_file.c_str(), O_CREAT | O_WRONLY, 0644);
            if (lock_fd != -1) {
                struct flock fl = {F_WRLCK, SEEK_SET, 0, 0, 0};
                fcntl(lock_fd, F_SETLK, &fl);

                std::ifstream file(IPV6_MAPPING_FILE);
                Json::Value root;
                if (file.is_open()) {
                    Json::CharReaderBuilder builder;
                    std::string errs;
                    Json::parseFromStream(builder, file, &root, &errs);
                    file.close();
                }

                if (root.isMember(token) && root[token]["ipv6"].asString() == source_ip) {
                    int count = std::max(0, root[token]["count"].asInt() - 1);
                    root[token]["count"] = count;

                    std::ofstream out_file(IPV6_MAPPING_FILE);
                    Json::StreamWriterBuilder writer_builder;
                    writer_builder["indentation"] = "";
                    out_file << Json::writeString(writer_builder, root);
                    out_file.close();
                    logger.info("IPv6 released for token " + token);
                }

                close(lock_fd);
                std::remove(lock_file.c_str());
            }
        }
    } catch (...) {
        logger.error("Error releasing IPv6");
    }
}

// === 2. StreamGuard (RAII для автоматической очистки при завершении прокси) ===
class StreamGuard {
    std::string save_dir;
    std::string channel;
    std::string provider;
    int slot;
    SlotManager& manager;
    std::string token;
    std::string source_ip;
    int64_t allocated_at;

public:
    StreamGuard(std::string d, std::string c, std::string p, int s, SlotManager& m, std::string t, std::string ip, int64_t alloc_at)
        : save_dir(d), channel(c), provider(p), slot(s), manager(m), token(t), source_ip(ip), allocated_at(alloc_at) {}

    // Обновить channel/save_dir/slot/token после hot switch (чтобы деструктор слал stop с правильными значениями)
    void update_channel(const std::string& new_channel, const std::string& new_save_dir,
                        int new_slot = -1, const std::string& new_token = "", int64_t new_allocated_at = -1) {
        channel = new_channel;
        save_dir = new_save_dir;
        if (new_slot >= 0) {
            logger.info("StreamGuard: updating slot " + std::to_string(slot) + " → " + std::to_string(new_slot));
            slot = new_slot;
        }
        if (!new_token.empty()) {
            token = new_token;
        }

        if (new_allocated_at >= 0) {
            allocated_at = new_allocated_at; // ← ДОБАВЛЕНО (обновляем маркер при Hot Switch)
        }
    }

    ~StreamGuard() {
        logger.info("StreamGuard: Cleanup started for " + channel);

        release_ipv6(token, source_ip);

        // C++ НЕ декрементирует слоты - это делает Lua при получении STOP
        // Lua является единственным источником истины для provider:usage

        try {
            manager.mark_allocation_dying(channel);
            manager.notify_proxy_stop(channel, provider, slot, token, allocated_at);
        } catch (const std::exception& e) {
            logger.error("Redis notify error: " + std::string(e.what()));
        }
        try {
            clear_directory_files(save_dir);
        } catch (...) {}
        try {
            std::string lock_file = "/tmp/hls_check_" + channel + ".lock";
            std::string active_file = lock_file + ".active";
            if (fs::exists(lock_file)) std::remove(lock_file.c_str());
            if (fs::exists(active_file)) std::remove(active_file.c_str());
        } catch (...) {}
    }
};

// === 3. check_url_update — читает JSON {url,slot,token} и обновляет все три поля ===
bool check_url_update(const std::string& channel, std::string& current_url, const std::string& provider,
                      int* out_slot = nullptr, std::string* out_token = nullptr) {
    std::string update_file = "/tmp/update_" + channel + ".url";
    
    if (!fs::exists(update_file)) return false;

    try {
        std::ifstream file(update_file);
        if (!file.is_open()) return false;

        std::string raw((std::istreambuf_iterator<char>(file)),
                         std::istreambuf_iterator<char>());
        file.close();
        std::remove(update_file.c_str());

        if (raw.empty()) return false;

        std::string new_url;
        int new_slot = -1;
        std::string new_token;

        // Пробуем распарсить как JSON (новый формат)
        Json::Value root;
        Json::CharReaderBuilder builder;
        std::istringstream iss(raw);
        std::string errs;
        if (Json::parseFromStream(builder, iss, &root, &errs) && root.isObject()) {
            new_url   = root.get("url",   "").asString();
            new_slot  = root.get("slot",  -1).asInt();
            new_token = root.get("token", "").asString();
        } else {
            // Старый формат: просто строка с URL
            new_url = raw;
            // Убираем возможный trailing newline
            while (!new_url.empty() && (new_url.back() == '\n' || new_url.back() == '\r'))
                new_url.pop_back();
        }

        if (new_url.empty()) return false;

        if (provider == "direct_url" && new_url.find("api.peers.tv") != std::string::npos) {
            new_url = replace_peers_token_in_url(new_url);
        }

        bool changed = false;

        if (new_url != current_url) {
            logger.info("LIVE UPDATE: Switching source URL to: " + new_url);
            current_url = new_url;
            changed = true;
        }

        if (out_slot && new_slot >= 0) {
            logger.info("LIVE UPDATE: Switching slot to: " + std::to_string(new_slot));
            *out_slot = new_slot;
            // Обновляем g_subscriber_slot чтобы следующий SWITCH в channel_control матчился правильно
            {
                std::lock_guard<std::mutex> lock(g_subscriber_channel_mutex);
                g_subscriber_slot = new_slot;
            }
            changed = true;
        }

        if (out_token && !new_token.empty()) {
            logger.info("LIVE UPDATE: Switching token to: " + new_token);
            *out_token = new_token;
            changed = true;
        }

        return changed;
    } catch (const std::exception& e) {
        logger.error("Failed to read update file: " + std::string(e.what()));
    }
    return false;
}

// Объявление структуры (должно быть до функции)
struct StreamSource {
    std::string url;
    std::string agent;
    std::string referer;
    std::string quality;
    long bandwidth = 0;

    bool manage_slot = false;       // Нужно ли управлять слотом
    std::string usage_key;          // Ключ в Redis (например, "tvclub:usage")
    int limit = 0;                  // Лимит подключений (например, 2)
    std::string provider_type;      // Для логов (например, "provider_tvclub")
};

// ============================================================================
// OPTIMIZED: ZERO-COPY SEGMENT WRITER USING MMAP
// ============================================================================
class ZeroCopySegmentWriter {
private:
    int fd;
    uint8_t* mapped_data;
    size_t mapped_size;
    std::string file_path;
    bool is_open;

public:
    ZeroCopySegmentWriter() : fd(-1), mapped_data(nullptr), mapped_size(0), is_open(false) {}

    bool open_segment(const std::string& path, size_t expected_size) {
        file_path = path;
        fd = ::open(path.c_str(), O_RDWR | O_CREAT, 0644);
        if (fd < 0) {
            logger.error("ZeroCopyWriter: Failed to open " + path);
            return false;
        }

        // Pre-allocate file space
        if (ftruncate(fd, expected_size) < 0) {
            logger.error("ZeroCopyWriter: Failed to truncate " + path);
            close(fd);
            return false;
        }

        // Map file into memory
        mapped_data = (uint8_t*)mmap(nullptr, expected_size,
                                     PROT_WRITE, MAP_SHARED, fd, 0);
        if (mapped_data == MAP_FAILED) {
            logger.error("ZeroCopyWriter: Failed to mmap " + path);
            close(fd);
            return false;
        }

        mapped_size = expected_size;
        is_open = true;
        return true;
    }

    void write_data(const uint8_t* data, size_t offset, size_t size) {
        if (!is_open || offset + size > mapped_size) return;
        memcpy(mapped_data + offset, data, size);  // Fast memory copy
    }

    void close_segment() {
        if (is_open) {
            // Flush to disk asynchronously
            if (msync(mapped_data, mapped_size, MS_ASYNC) < 0) {
                logger.warning("ZeroCopyWriter: msync failed for " + file_path);
            }

            // Unmap memory
            if (munmap(mapped_data, mapped_size) < 0) {
                logger.warning("ZeroCopyWriter: munmap failed for " + file_path);
            }

            close(fd);
            is_open = false;
            mapped_data = nullptr;
            mapped_size = 0;
        }
    }

    uint8_t* get_data_ptr() {
        return mapped_data;
    }

    ~ZeroCopySegmentWriter() {
        close_segment();
    }
};

// ============================================================================
// OPTIMIZED: ASYNC FILE WRITER FOR NON-BLOCKING FILE I/O
// ============================================================================
class AsyncFileWriter {
private:
    struct WriteTask {
        std::string path;
        std::vector<uint8_t> data;
    };

    std::thread writer_thread;
    std::queue<WriteTask> write_queue;
    std::mutex queue_mutex;
    std::condition_variable cv;
    std::atomic<bool> running{true};

public:
    AsyncFileWriter() {
        writer_thread = std::thread([this]() {
            while (running || !write_queue.empty()) {
                WriteTask task;
                {
                    std::unique_lock<std::mutex> lock(queue_mutex);
                    cv.wait(lock, [this]() {
                        return !write_queue.empty() || !running;
                    });

                    if (!running && write_queue.empty()) break;

                    task = write_queue.front();
                    write_queue.pop();
                }

                // Write file without blocking main thread
                std::ofstream f(task.path, std::ios::binary);
                if (f.is_open()) {
                    f.write(reinterpret_cast<char*>(task.data.data()), task.data.size());
                    f.close();
                } else {
                    logger.error("AsyncFileWriter: Failed to open " + task.path);
                }
            }
        });
    }

    void async_write(const std::string& path, const std::vector<uint8_t>& data) {
        {
            std::lock_guard<std::mutex> lock(queue_mutex);
            write_queue.push({path, data});
        }
        cv.notify_one();
    }

    void async_write_string(const std::string& path, const std::string& data) {
        std::vector<uint8_t> data_vec(data.begin(), data.end());
        async_write(path, data_vec);
    }

    ~AsyncFileWriter() {
        running = false;
        cv.notify_all();
        if (writer_thread.joinable()) {
            writer_thread.join();
        }
    }
};

// ============================================================================
// OPTIMIZED: ASYNC NETWORK READER FOR NON-BLOCKING I/O
// ============================================================================
class AsyncNetworkReader {
private:
    int sock;
    std::atomic<bool> running{true};

public:
    AsyncNetworkReader(int socket_fd) : sock(socket_fd) {
        // Set non-blocking mode
        int flags = fcntl(sock, F_GETFL, 0);
        if (flags != -1) {
            fcntl(sock, F_SETFL, flags | O_NONBLOCK);
        }
    }

    // Non-blocking read with timeout
    ssize_t async_read(uint8_t* buffer, size_t size, int timeout_ms = 100) {
        auto start = std::chrono::steady_clock::now();

        while (running) {
            ssize_t n = recv(sock, buffer, size, 0);

            if (n > 0) return n;  // Data received
            if (n == 0) return 0; // Connection closed

            if (errno == EAGAIN || errno == EWOULDBLOCK) {
                // Check timeout
                auto now = std::chrono::steady_clock::now();
                auto elapsed = std::chrono::duration_cast<std::chrono::milliseconds>(
                    now - start).count();

                if (elapsed >= timeout_ms) {
                    return -1; // Timeout
                }

                // Brief pause before retry
                std::this_thread::sleep_for(std::chrono::milliseconds(1));
                continue;
            }

            return -1; // Error
        }

        return -1; // Stopped
    }

    void stop() {
        running = false;
    }

    ~AsyncNetworkReader() {
        running = false;
    }
};

// ============================================================================
// HIGH-PERFORMANCE PRE-ALLOCATED BUFFER POOL FOR PIPELINE
// ============================================================================
class BufferPool {
private:
    std::vector<std::vector<uint8_t>> pool;
    std::queue<size_t> free_indices;
    std::mutex mutex;

public:
    BufferPool(size_t blocks, size_t block_size) {
        pool.assign(blocks, std::vector<uint8_t>(block_size));
        for (size_t i = 0; i < blocks; ++i) {
            free_indices.push(i);
        }
    }

    // Возвращает указатель на сырые данные блока и его индекс в пуле
    uint8_t* acquire(size_t& out_idx) {
        std::lock_guard<std::mutex> lock(mutex);
        if (free_indices.empty()) return nullptr;
        out_idx = free_indices.front();
        free_indices.pop();
        return pool[out_idx].data();
    }

    // Возвращает блок обратно в пул свободных ресурсов
    void release(size_t idx) {
        std::lock_guard<std::mutex> lock(mutex);
        free_indices.push(idx);
    }

    uint8_t* get_block_ptr(size_t idx) {
        return pool[idx].data();
    }
};

// ============================================================================
// DIRECT STREAM SEGMENTER (MPEG-TS Logic)
// ALGO:
// 1. Seq 0-7: Instant cut (Target ~0s) -> TD=1 in Playlist
// 2. Seq 8: Target 2.0s (Transition) -> TD=1
// 3. Seq 9: Target 3.0s (Transition) -> TD=1
// 4. Seq 10+: Target 4.0s (Normal) -> TD=1 until fast segs gone, then TD=4
// ============================================================================
class DirectStreamSegmenter {
private:
    // === НАСТРОЙКИ АЛГОРИТМА (ОПТИМИЗИРОВАНО ДЛЯ БЫСТРОГО СТАРТА) ===
    const int INITIAL_WINDOW_SIZE = 1;  // Минимальное окно — плеер начинает сразу после 1 сегмента
    const int WINDOW_SIZE = 5;          // Финальный размер окна после стабилизации
    const int FAST_PHASE_COUNT = 10;    // Увеличено с 5 до 10 для плавного старта
    const double NORMAL_DURATION = 1.0; // Уменьшено с 2.0 до 1.0 для быстрых сегментов

    // === TIER 1: FAST START — Progressive segment duration ===
    // Первые сегменты отрезаются по первому keyframe (даже 0.05s),
    // затем плавно увеличиваются до нормального размера (2.0s)
const double FORCE_CUT_TIMEOUT_FAST_MS = 100.0; // seq 0-2: почти мгновенный cut
const double FORCE_CUT_TIMEOUT_RAMP_MS = 300.0; // seq 3-4: переходный
const double FORCE_CUT_TIMEOUT_NORM_MS = 500.0;
     const double FORCE_CUT_TIMEOUT_MS = 500.0; // Force-cut если нет keyframe за 500ms
    
    // === ДЕБАГ ===
    int debug_playlist_counter = 0;

    // === СИСТЕМНЫЕ ===
    const int DS_BUFFER_SIZE = 1024 * 1024;
    const int TS_PACKET_SIZE = 188;
    const int MAX_REDIRECTS = 5;
    const std::vector<int> AUDIO_TYPES = { 0x0F, 0x03, 0x04, 0x11, 0x81, 0x82, 0x83 };

    struct Url { std::string protocol, host, path; int port; };
    struct PacketInfo { uint16_t pid; bool is_keyframe; };
    struct SegmentMeta { std::string filename; double duration; int sequence; };

    std::string channel_id;
    std::string save_dir;
    std::string target_lang;
    int current_socket = -1;
    std::unique_ptr<AsyncNetworkReader> async_reader;  // OPTIMIZED: Async network reader
    std::unique_ptr<AsyncFileWriter> async_writer;    // OPTIMIZED: Async file writer
    std::unique_ptr<ZeroCopySegmentWriter> zero_copy_writer;  // OPTIMIZED: Zero-copy writer
    bool use_zero_copy = false;  // Flag to enable zero-copy mode
    bool use_pipeline = false;   // OPTIMIZED: Flag to enable pipeline processing

    // Pipeline structures
    struct ProcessedSegment {
        std::string filename;
        std::vector<uint8_t> data;
        double duration;
        int sequence;
    };

    // Pipeline structures
    struct PoolBlock {
        size_t pool_idx;
        size_t size;
    };

    // Pipeline queues
    std::unique_ptr<BufferPool> buffer_pool; // ← Пул буферов (управляет памятью)
    std::queue<PoolBlock> network_queue;  
    std::queue<ProcessedSegment> parse_queue;
    std::mutex network_mutex;
    std::mutex parse_mutex;
    std::condition_variable network_cv;
    std::condition_variable parse_cv;
    std::atomic<bool> pipeline_running{false};

    // Парсер MPEG-TS
    int found_pmt_pid = -1;
    int detected_video_pid = -1;
    int detected_video_type = -1;
    int detected_audio_pid = -1;
    int detected_audio_type = -1;
    int fallback_audio_pid = -1;
    int fallback_audio_type = -1;
    int detected_pcr_pid = -1;
    int pmt_program_number = 1;
    std::set<int> all_audio_pids;
    std::vector<uint8_t> selected_audio_descriptors;
    
    int global_target_duration = 1; 

    // === PID CACHE: пропуск PID detection при повторном запуске канала ===
    static std::string get_pid_cache_path(const std::string& ch_id) {
        return "/tmp/pid_cache/" + ch_id + ".pid";
    }

    void save_pid_cache() {
        if (detected_video_pid < 0) return;
        try {
            fs::create_directories("/tmp/pid_cache");
            std::string path = get_pid_cache_path(channel_id);
            std::ofstream f(path);
            if (f) {
                f << found_pmt_pid << " "
                  << detected_video_pid << " " << detected_video_type << " "
                  << detected_audio_pid << " " << detected_audio_type << " "
                  << detected_pcr_pid << " " << pmt_program_number;
                for (int ap : all_audio_pids) f << " " << ap;
                f << "\n";
                logger.info("PID cache saved for channel " + channel_id);
            }
        } catch (...) {}
    }

    bool load_pid_cache() {
        try {
            std::string path = get_pid_cache_path(channel_id);
            std::ifstream f(path);
            if (!f) return false;
            int pmt, vpid, vtype, apid, atype, pcr, prog;
            if (!(f >> pmt >> vpid >> vtype >> apid >> atype >> pcr >> prog)) return false;
            found_pmt_pid = pmt;
            detected_video_pid = vpid;
            detected_video_type = vtype;
            detected_audio_pid = apid;
            detected_audio_type = atype;
            detected_pcr_pid = pcr;
            pmt_program_number = prog;
            // Читаем all_audio_pids
            int ap;
            while (f >> ap) all_audio_pids.insert(ap);
            if (detected_audio_pid > 0) all_audio_pids.insert(detected_audio_pid);
            logger.info("PID cache loaded for channel " + channel_id +
                       " (video=" + std::to_string(vpid) + " audio=" + std::to_string(apid) +
                       " pcr=" + std::to_string(pcr) + ")");
            return true;
        } catch (...) { return false; }
    }

    static long long get_now_ms() {
        struct timespec ts;
        clock_gettime(CLOCK_MONOTONIC, &ts);
        return (long long)ts.tv_sec * 1000 + ts.tv_nsec / 1000000;
    }

    // === ДИНАМИЧЕСКИЙ РАЗМЕР ОКНА ДЛЯ БЫСТРОГО СТАРТА ===
    int get_dynamic_window_size(int seq) const {
        if (seq < 3) return INITIAL_WINDOW_SIZE;  // Первые 3 сегмента — минимальное окно (1)
        if (seq < 6) return 3;                     // Следующие 3 - среднее окно (3)
        return WINDOW_SIZE;                        // Потом полное окно (5)
    }

    // === TIER 1: Progressive segment duration для быстрого старта ===
    // Seq 0-2: 0.0s → мгновенная картинка (любой keyframe)
    // Seq 3-4: 0.5s → короткие сегменты
    // Seq 5-6: 1.0s → плавный переход
    // Seq 7-8: 1.5s → ещё плавнее
    // Seq 9+:  2.0s → нормальный режим
    double get_fast_start_threshold(int seq) const {
        if (seq <= 2) return 0.0;   // Любой keyframe = сегмент (мгновенная картинка)
        if (seq <= 4) return 0.5;   // Короткие сегменты
        if (seq <= 6) return 1.0;   // Плавный переход
        if (seq <= 8) return 1.5;   // Ещё плавнее
        return 2.0;                 // Нормальный режим
    }

    // --- Helpers (String, Path, CRC, Socket) ---
    std::string clean_string(std::string s) {
        if (s.empty()) return s;
        size_t first = s.find_first_not_of(" \t\r\n");
        if (first == std::string::npos) return "";
        s.erase(0, first);
        size_t last = s.find_last_not_of(" \t\r\n");
        s.erase(last + 1);
        return s;
    }

    std::string join_path(const std::string& dir, const std::string& file) {
        if (dir.empty()) return file;
        if (dir.back() == '/') return dir + file;
        return dir + "/" + file;
    }

    uint32_t calculate_crc32(const uint8_t *data, int len) {
        uint32_t crc = 0xFFFFFFFF;
        for (int i = 0; i < len; i++) {
            crc = crc ^ ((uint32_t)data[i] << 24);
            for (int j = 0; j < 8; j++) {
                if (crc & 0x80000000) crc = (crc << 1) ^ 0x04C11DB7;
                else crc = crc << 1;
            }
        }
        return crc;
    }

    Url parse_url_internal(std::string url_str) {
        url_str = clean_string(url_str);
        Url res; res.port = 80; res.path = "/";
        std::string u = url_str;
        size_t proto_pos = u.find("://");
        if (proto_pos != std::string::npos) u = u.substr(proto_pos + 3);
        size_t path_pos = u.find('/');
        if (path_pos != std::string::npos) { res.path = u.substr(path_pos); res.host = u.substr(0, path_pos); } 
        else { res.host = u; }
        size_t port_pos = res.host.find(':');
        if (port_pos != std::string::npos) {
            try { res.port = std::stoi(res.host.substr(port_pos + 1)); } catch(...) {}
            res.host = res.host.substr(0, port_pos);
        }
        res.path = clean_string(res.path);
        return res;
    }

    int create_tcp_socket(const std::string& host, int port) {
        struct hostent* server = gethostbyname(host.c_str());
        if (!server) return -1;
        int sockfd = socket(AF_INET, SOCK_STREAM, 0);
        if (sockfd < 0) return -1;
        int one = 1;
        setsockopt(sockfd, IPPROTO_TCP, TCP_NODELAY, &one, sizeof(one));
        struct timeval tv; tv.tv_sec = 10; tv.tv_usec = 0; 
        setsockopt(sockfd, SOL_SOCKET, SO_RCVTIMEO, (const char*)&tv, sizeof tv);
        int rcvbuf = 4 * 1024 * 1024;
        setsockopt(sockfd, SOL_SOCKET, SO_RCVBUF, (const char*)&rcvbuf, sizeof(rcvbuf));
        struct sockaddr_in serv_addr;
        bzero((char*)&serv_addr, sizeof(serv_addr));
        serv_addr.sin_family = AF_INET;
        bcopy((char*)server->h_addr, (char*)&serv_addr.sin_addr.s_addr, server->h_length);
        serv_addr.sin_port = htons(port);
        if (connect(sockfd, (struct sockaddr*)&serv_addr, sizeof(serv_addr)) < 0) {
            close(sockfd); return -1;
        }
        return sockfd;
    }

    // --- Networking ---
    int open_http_stream(std::string url_str, std::vector<uint8_t>& leftover_data, const std::string& user_agent, const std::string& referer) {
        int redirects = 0;
        char buffer[16384]; 
        while (redirects < MAX_REDIRECTS && g_keep_running) {
            Url url = parse_url_internal(url_str);
            logger.info("DirectStream: Connecting to " + url.host + ":" + std::to_string(url.port));
            int sock = create_tcp_socket(url.host, url.port);
            if (sock < 0) { logger.error("DirectStream: Connect failed."); return -1; }
            current_socket = sock;
            std::string host_header = url.host;
            if (url.port != 80) host_header += ":" + std::to_string(url.port);
            std::string ua_val = user_agent.empty() ? "HLSProxy/Direct" : user_agent;
            //std::string req = "GET " + url.path + " HTTP/1.1\r\nHost: " + host_header + 
            //                  "\r\nUser-Agent: " + ua_val + 
            //                  "\r\nAccept: */*\r\nConnection: close\r\n\r\n";
            std::string req = "GET " + url.path + " HTTP/1.1\r\nHost: " + host_header + 
                              "\r\nUser-Agent: " + ua_val;
            
            if (!referer.empty()) {
                req += "\r\nReferer: " + referer; // <--- ДОБАВЛЯЕМ REFERER В СЫРОЙ ЗАПРОС
            }
            req += "\r\nAccept: */*\r\nConnection: close\r\n\r\n";
            send(sock, req.c_str(), req.length(), 0);
            std::string headers;
            bool done = false; 
            int body_start = 0;
            while (!done && g_keep_running) {
                int n = recv(sock, buffer, sizeof(buffer), 0);
                if (n <= 0) { close(sock); return -1; }
                headers.append(buffer, n);
                size_t pos = headers.find("\r\n\r\n");
                if (pos != std::string::npos) {
                    done = true; body_start = pos + 4;
                    if (headers.length() > body_start) leftover_data.assign(headers.data() + body_start, headers.data() + headers.length());
                }
            }
            std::stringstream ss_head; ss_head << headers;
            std::string ver; int code; ss_head >> ver >> code;
            logger.info("DirectStream: HTTP Code: " + std::to_string(code));
            if (code == 200) return sock;
            if (code == 301 || code == 302) {
                size_t pos = headers.find("\nLocation: ");
                if (pos == std::string::npos) pos = headers.find("\nlocation: ");
                if (pos != std::string::npos) {
                    size_t start = pos + 11;
                    size_t end = headers.find("\r\n", start);
                    url_str = clean_string(headers.substr(start, end - start));
                    logger.info("DirectStream: Redirect -> " + url_str);
                    close(sock); redirects++; leftover_data.clear(); continue;
                }
            }
            close(sock); return -1;
        }
        return -1;
    }

    // --- Parsing ---
    void parse_pat(const uint8_t* packet) {
        int offset = 4;
        if (packet[1] & 0x40) offset += 1 + packet[4];
        offset += 8; 
        while (offset < TS_PACKET_SIZE - 4) {
            int prog = (packet[offset] << 8) | packet[offset+1];
            int pid = ((packet[offset+2] & 0x1F) << 8) | packet[offset+3];
            if (prog != 0) { pmt_program_number = prog; found_pmt_pid = pid; return; }
            offset += 4;
        }
    }

    std::string get_iso639_lang(const uint8_t* desc_data, int len) {
        int pos = 0;
        while (pos < len - 2) {
            int tag = desc_data[pos];
            int desc_len = desc_data[pos+1];
            if (pos + 2 + desc_len > len) break;
            if (tag == 0x0A && desc_len >= 3) {
                char lang[4] = {0};
                lang[0] = desc_data[pos+2]; lang[1] = desc_data[pos+3]; lang[2] = desc_data[pos+4];
                return std::string(lang);
            }
            pos += 2 + desc_len;
        }
        return "";
    }

    void parse_pmt(const uint8_t* packet) {
        int offset = 4;
        if (packet[1] & 0x40) offset += 1 + packet[4];
        detected_pcr_pid = ((packet[offset+8] & 0x1F) << 8) | packet[offset+9];
        int sec_len = ((packet[offset+1] & 0x0F) << 8) | packet[offset+2];
        int prog_info_len = ((packet[offset+10] & 0x0F) << 8) | packet[offset+11];
        int pos = offset + 12 + prog_info_len;
        int end = offset + 3 + sec_len - 4; 
        while (pos < end && pos < TS_PACKET_SIZE - 5) {
            int type = packet[pos];
            int pid = ((packet[pos+1] & 0x1F) << 8) | packet[pos+2];
            int es_len = ((packet[pos+3] & 0x0F) << 8) | packet[pos+4];
            if (detected_video_pid == -1 && (type == 0x1B || type == 0x24 || type == 0x02)) { 
                detected_video_pid = pid; detected_video_type = type;
                logger.info("DirectStream: Video PID selected: " + std::to_string(detected_video_pid));
            }
            bool is_audio = false;
            for (int at : AUDIO_TYPES) if (type == at) is_audio = true;
            if (is_audio) {
                all_audio_pids.insert(pid);
                if (!target_lang.empty()) {
                    std::string lang = get_iso639_lang(packet + pos + 5, es_len);
                    if (lang == target_lang) {
                        // Приоритет: MP2/MP3 (0x03, 0x04) > AAC (0x0F) > AC3 (0x81)
                        bool is_preferred = (type == 0x03 || type == 0x04); // MP2/MP3
                        bool is_aac = (type == 0x0F);                        // AAC
                        bool is_ac3 = (type == 0x81 || type == 0x06);       // AC3/E-AC3

                        if (detected_audio_pid == -1) {
                            // Первая найденная русская дорожка
                            detected_audio_pid = pid;
                            detected_audio_type = type;
                            if (es_len > 0) selected_audio_descriptors.assign(packet + pos + 5, packet + pos + 5 + es_len);
                        } else if (is_preferred && detected_audio_type != 0x03 && detected_audio_type != 0x04) {
                            // Заменяем на MP2/MP3 если текущая не MP2/MP3
                            logger.info("DirectStream: Switching to preferred audio codec (MP2/MP3) PID: " + std::to_string(pid));
                            detected_audio_pid = pid;
                            detected_audio_type = type;
                            if (es_len > 0) selected_audio_descriptors.assign(packet + pos + 5, packet + pos + 5 + es_len);
                        }
                    }
                    if (fallback_audio_pid == -1) {
                        fallback_audio_pid = pid;
                        fallback_audio_type = type;
                    }
                }
            }
            pos += 5 + es_len;
        }
        if (!target_lang.empty() && detected_audio_pid == -1 && fallback_audio_pid != -1 && pos >= end) {
            detected_audio_pid = fallback_audio_pid; detected_audio_type = fallback_audio_type;
            logger.info("DirectStream: Using fallback audio PID: " + std::to_string(detected_audio_pid) + " (type: 0x" +
                       std::to_string(detected_audio_type) + ")");
        }

        // Логирование выбранной аудио дорожки
        if (detected_audio_pid != -1) {
            std::string codec_name;
            if (detected_audio_type == 0x03 || detected_audio_type == 0x04) codec_name = "MP2/MP3";
            else if (detected_audio_type == 0x0F) codec_name = "AAC";
            else if (detected_audio_type == 0x81) codec_name = "AC3";
            else if (detected_audio_type == 0x06) codec_name = "E-AC3";
            else codec_name = "Unknown";

            logger.info("DirectStream: Selected audio PID: " + std::to_string(detected_audio_pid) +
                       " (type: 0x" + std::to_string(detected_audio_type) + " = " + codec_name + ")");
        }
    }

    void rewrite_pmt_packet(uint8_t* p) {
        if (detected_video_pid == -1) return;
        std::memset(p, 0xFF, TS_PACKET_SIZE);
        p[0] = 0x47; p[1] = 0x40 | ((found_pmt_pid >> 8) & 0x1F); p[2] = found_pmt_pid & 0xFF; p[3] = 0x10; p[4] = 0;
        int offset = 5; p[offset++] = 0x02; int section_len_pos = offset; offset += 2; 
        p[offset++] = (pmt_program_number >> 8) & 0xFF; p[offset++] = pmt_program_number & 0xFF;
        p[offset++] = 0xC1; p[offset++] = 0x00; p[offset++] = 0x00; 
        int pcr_pid = (detected_pcr_pid != -1) ? detected_pcr_pid : detected_video_pid;
        p[offset++] = 0xE0 | ((pcr_pid >> 8) & 0x1F); p[offset++] = pcr_pid & 0xFF; p[offset++] = 0xF0; p[offset++] = 0x00; 
        p[offset++] = detected_video_type; p[offset++] = 0xE0 | ((detected_video_pid >> 8) & 0x1F); p[offset++] = detected_video_pid & 0xFF; p[offset++] = 0xF0; p[offset++] = 0x00; 
        if (detected_audio_pid != -1) {
            p[offset++] = detected_audio_type; p[offset++] = 0xE0 | ((detected_audio_pid >> 8) & 0x1F); p[offset++] = detected_audio_pid & 0xFF;
            int desc_len = selected_audio_descriptors.size(); if (desc_len > 1000) desc_len = 0; 
            p[offset++] = 0xF0 | ((desc_len >> 8) & 0x0F); p[offset++] = desc_len & 0xFF;
            if (desc_len > 0) { std::memcpy(&p[offset], selected_audio_descriptors.data(), desc_len); offset += desc_len; }
        }
        int section_len = (offset - section_len_pos - 2) + 4; 
        p[section_len_pos] = 0xB0 | ((section_len >> 8) & 0x0F); p[section_len_pos + 1] = section_len & 0xFF;
        uint32_t crc = calculate_crc32(&p[5], offset - 5);
        p[offset++] = (crc >> 24) & 0xFF; p[offset++] = (crc >> 16) & 0xFF; p[offset++] = (crc >> 8) & 0xFF; p[offset++] = crc & 0xFF;
    }

void generate_pat_packet(uint8_t* p) {
        // Заполняем весь пакет байтом стаффинга 0xFF
        std::memset(p, 0xFF, TS_PACKET_SIZE);
        
        // TS Header (4 байта)
        p[0] = 0x47; // Sync byte
        p[1] = 0x40; // Payload Unit Start Indicator = 1, PID = 0
        p[2] = 0x00; 
        p[3] = 0x10; // Payload only, CC = 0
        
        p[4] = 0x00; // Pointer field = 0 (указывает на начало секции)

        // Таблица PAT (Program Association Table)
        p[5] = 0x00; // Table ID = 0x00 (PAT)
        p[6] = 0xB0; // Section syntax indicator = 1, Section length high
        p[7] = 0x0D; // Section length low = 13 (длина секции от TS ID до CRC32 включительно)
        p[8] = 0x00; // Transport Stream ID high
        p[9] = 0x01; // Transport Stream ID low (1)
        p[10] = 0xC1; // Version = 0, current/next indicator = 1
        p[11] = 0x00; // Section number
        p[12] = 0x00; // Last section number

        // Программа 1
        int pmt_pid = (found_pmt_pid != -1) ? found_pmt_pid : 0x100; // Берем кэшированный PMT PID или дефолтный 256
        p[13] = (pmt_program_number >> 8) & 0xFF; // Program Number high
        p[14] = pmt_program_number & 0xFF;        // Program Number low
        p[15] = 0xE0 | ((pmt_pid >> 8) & 0x1F);   // Reserved (3 bits) + PMT PID high
        p[16] = pmt_pid & 0xFF;                   // PMT PID low

        // Вычисляем CRC32 секции PAT (от p[5] до p[16] включительно — 12 байт)
        uint32_t crc = calculate_crc32(&p[5], 12);
        p[17] = (crc >> 24) & 0xFF;
        p[18] = (crc >> 16) & 0xFF;
        p[19] = (crc >> 8) & 0xFF;
        p[20] = crc & 0xFF;
    }

    double get_pcr_time(const uint8_t* p) {
        if (!(p[3] & 0x20)) return -1.0; if (p[4] < 7) return -1.0; if (!(p[5] & 0x10)) return -1.0;
        uint64_t pcr_base = ((uint64_t)p[6] << 25) | ((uint64_t)p[7] << 17) | ((uint64_t)p[8] << 9) | ((uint64_t)p[9] << 1) | ((uint64_t)p[10] >> 7);
        return (double)pcr_base / 90000.0;
    }

    double get_pts_time(const uint8_t* p) {
        // Проверяем индикатор начала PES и наличие полезной нагрузки
        if (!(p[1] & 0x40) || !(p[3] & 0x10)) return -1.0;

        int offset = 4;
        bool has_af = (p[3] & 0x20);
        if (has_af) {
            offset += 1 + p[4]; // Пропускаем адаптационное поле
        }
        if (offset + 14 > TS_PACKET_SIZE) return -1.0;

        // Префикс кода начала PES: 0x00 0x00 0x01
        if (p[offset] != 0x00 || p[offset+1] != 0x00 || p[offset+2] != 0x01) return -1.0;

        // Наличие флагов PTS/DTS в 8-м байте PES-заголовка (биты 7-6)
        uint8_t flags = p[offset+7];
        uint8_t pts_dts_flags = (flags >> 6) & 0x03;

        if (pts_dts_flags == 2 || pts_dts_flags == 3) {
            // Выделяем 33-битный PTS из заголовка
            uint64_t pts = 0;
            pts |= (uint64_t)(p[offset+9] & 0x0E) << 29;
            pts |= (uint64_t)p[offset+10] << 22;
            pts |= (uint64_t)(p[offset+11] & 0xFE) << 14;
            pts |= (uint64_t)p[offset+12] << 7;
            pts |= (uint64_t)(p[offset+13] >> 1);
            return (double)pts / 90000.0; // Конвертируем в секунды
        }
        return -1.0;
    }

    bool contains_h264_keyframe(const uint8_t* payload, int len) {
        for (int i = 0; i < len - 4; i++) {
            if (payload[i] == 0 && payload[i+1] == 0 && payload[i+2] == 1) {
                uint8_t nal = payload[i+3] & 0x1F;
                if (nal == 5 || nal == 7) return true;
            }
        }
        return false;
    }

    PacketInfo parse_ts_packet_deep(const uint8_t* buffer) {
        PacketInfo info = {0, false};
        info.pid = ((buffer[1] & 0x1F) << 8) | buffer[2];
        bool has_af = (buffer[3] & 0x20); bool payload_exists = (buffer[3] & 0x10);
        if (has_af && buffer[4] > 0) { if (buffer[5] & 0x40) info.is_keyframe = true; }
        if (!info.is_keyframe && payload_exists && info.pid == detected_video_pid) {
            int payload_offset = 4; if (has_af) payload_offset += 1 + buffer[4];
            if (payload_offset < TS_PACKET_SIZE) if (contains_h264_keyframe(buffer + payload_offset, TS_PACKET_SIZE - payload_offset)) info.is_keyframe = true;
        }
        return info;
    }

    // =========================================================================
    // ПЛЕЙЛИСТ (STRICT LOGIC)
    // 1. Если в окне есть хоть один сегмент с seq < 8 -> TargetDuration = 1
    // 2. Иначе -> TargetDuration = ceil(max_duration)
    // =========================================================================

    // OPTIMIZED: Super Instant Start — публикует playlist с реальным именем сегмента.
    // Создаёт пустой файл + .downloading маркер. Nginx (stream_segment.lua) начнёт
    // поллить файл и отдавать данные клиенту по мере записи.
    void super_instant_start() {
        std::string seg_name = "seg_1.ts";
        std::string seg_path = join_path(save_dir, seg_name);
        std::string marker_path = seg_path + ".downloading";

        // Создаём пустой файл сегмента и .downloading маркер
        { std::ofstream f(seg_path, std::ios::binary); }
        { std::ofstream f(marker_path); }

        std::string stub_playlist =
            "#EXTM3U\n"
            "#EXT-X-VERSION:3\n"
            "#EXT-X-TARGETDURATION:1\n"
            "#EXT-X-MEDIA-SEQUENCE:1\n"
            "#EXTINF:0.001,\n"
            + seg_name + "\n";

        std::string tmp = join_path(save_dir, "playlist.m3u8.tmp");
        std::ofstream f(tmp);
        if (f) {
            f << stub_playlist;
            f.close();
            std::rename(tmp.c_str(), join_path(save_dir, "playlist.m3u8").c_str());
            logger.info("DirectStream: SUPER INSTANT START - playlist with " + seg_name + " + .downloading marker");
        } else {
            logger.warning("DirectStream: Failed to create instant start playlist");
        }
    }

    void update_playlist(const std::deque<SegmentMeta>& segs, int seq) {
        std::string tmp = join_path(save_dir, "playlist.m3u8.tmp");
        std::ofstream f(tmp);
        if (!f) return;

        // ========================================================================
        // ДИНАМИЧЕСКИЙ ПЕРЕСЧЕТ TARGETDURATION (Strict HLS Compliance)
        // ========================================================================
        double max_duration_in_window = 0.0;
        
        for (const auto& s : segs) {
            if (s.duration > max_duration_in_window) {
                max_duration_in_window = s.duration;
            }
        }

        // Округляем в большую сторону (ceil), как того требует HLS стандарт
        int target_duration_header = (int)std::ceil(max_duration_in_window);
        
        // Защита от нулевого/пустого плейлиста
        if (target_duration_header < 1) {
            target_duration_header = 1;
        }
        // ========================================================================

        f << "#EXTM3U\n";
        f << "#EXT-X-VERSION:3\n";
        f << "#EXT-X-TARGETDURATION:" << target_duration_header << "\n";
        // TIER 1: EXT-X-START — плеер начинает с начала первого сегмента
        f << "#EXT-X-START:TIME-OFFSET=0,PRECISE=YES\n";
        
        int current_seq = segs.empty() ? seq : segs.front().sequence;
        f << "#EXT-X-MEDIA-SEQUENCE:" << current_seq << "\n";

        for (const auto& s : segs) {
            f << "#EXTINF:" << std::fixed << std::setprecision(6) << s.duration << ",\n" << s.filename << "\n";
        }
        f.close();
        std::rename(tmp.c_str(), join_path(save_dir, "playlist.m3u8").c_str());
        
        // --- ДЕБАГ ВЕРНУЛ ---
        if (debug_playlist_counter < 30) {
            std::string debug_name = "debug_pl_" + std::to_string(debug_playlist_counter) + ".m3u8";
            std::ofstream df(join_path(save_dir, debug_name));
            if (df.is_open()) { 
                std::stringstream ss;
                ss << "#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-TARGETDURATION:" << target_duration_header << "\n";
                ss << "#EXT-X-MEDIA-SEQUENCE:" << current_seq << "\n";
                for (const auto& s : segs) ss << "#EXTINF:" << s.duration << ",\n" << s.filename << "\n";
                df << ss.str(); 
                df.close(); 
            }

            logger.info("Playlist Updated[#" + std::to_string(debug_playlist_counter) + 
                        "]: TD=" + std::to_string(target_duration_header) + 
                        " (MaxDur=" + std::to_string(max_duration_in_window) + ")");
            debug_playlist_counter++;
        }
    }



public:
    DirectStreamSegmenter(std::string dir, std::string ch_id, std::string lang = "", bool enable_zero_copy = false, bool enable_pipeline = false)
        : save_dir(dir), channel_id(ch_id), target_lang(lang), use_zero_copy(enable_zero_copy), use_pipeline(enable_pipeline) {
        if (use_zero_copy) {
            logger.info("DirectStream: Zero-copy mode ENABLED");
        }
        if (use_pipeline) {
            logger.info("DirectStream: Pipeline processing mode ENABLED");
            // Инициализируем пул: 32 блока размером DS_BUFFER_SIZE (1 MB)
            buffer_pool = std::make_unique<BufferPool>(32, DS_BUFFER_SIZE);
        }
    }
    
    ~DirectStreamSegmenter() {
        if (current_socket != -1) close(current_socket);
        pipeline_running = false;
        // Очистка .downloading маркеров при завершении
        try {
            for (auto& entry : fs::directory_iterator(save_dir)) {
                if (entry.path().extension() == ".downloading") {
                    fs::remove(entry.path());
                }
            }
        } catch (...) {}
    }

    // ========================================================================
    // PIPELINE PROCESSING: Network Thread
    // ========================================================================
    void network_thread_func(int sock, std::atomic<bool>& running) {
        int consecutive_timeouts = 0;
        const int MAX_TIMEOUTS = 100;

        while (running && g_keep_running && !g_hot_switch_requested) {
            size_t pool_idx = 0;
            // Получаем свободный блок из пула без аллокации памяти
            uint8_t* block_ptr = buffer_pool->acquire(pool_idx);
            if (!block_ptr) {
                // Если пул временно переполнен (парсер не успел обработать), спим 5 мс
                std::this_thread::sleep_for(std::chrono::milliseconds(5));
                continue;
            }

            ssize_t n;
            if (async_reader) {
                n = async_reader->async_read(block_ptr, DS_BUFFER_SIZE, 100);
            } else {
                n = recv(sock, block_ptr, DS_BUFFER_SIZE, 0);
            }

            if (n > 0) {
                consecutive_timeouts = 0;
                std::lock_guard<std::mutex> lock(network_mutex);
                // Отправляем в очередь только индекс блока и реальный размер
                network_queue.push({pool_idx, (size_t)n});
                network_cv.notify_one();
            } else {
                // Возвращаем блок в пул, если чтение не удалось
                buffer_pool->release(pool_idx);
                
                if (n == 0) {
                    logger.info("DirectStream pipeline: Connection closed by peer");
                    running = false;
                    break;
                } else {
                    if (async_reader && errno == EAGAIN) {
                        consecutive_timeouts++;
                        if (consecutive_timeouts >= MAX_TIMEOUTS) {
                            logger.warning("DirectStream pipeline: Too many consecutive timeouts, stopping");
                            running = false;
                            break;
                        }
                        continue;
                    }
                    logger.error("DirectStream pipeline: recv error: " + std::string(strerror(errno)));
                    running = false;
                    break;
                }
            }
        }

        // Оповещаем конвейер о завершении
        {
            std::lock_guard<std::mutex> lock(network_mutex);
            network_cv.notify_all();
        }
    }

    // ========================================================================
    // PIPELINE PROCESSING: Parse Thread
    // ========================================================================
void parse_thread_func(std::atomic<bool>& running, std::atomic<bool>& pid_found, std::atomic<int>& global_seq) {
        std::vector<uint8_t> residual;
        residual.reserve(DS_BUFFER_SIZE * 2);

        std::vector<uint8_t> current_segment_buffer;
        current_segment_buffer.reserve(DS_BUFFER_SIZE);
        int current_seq = global_seq.load();
        std::string current_segment_name = "seg_" + std::to_string(current_seq) + ".ts";
        double segment_start_pcr = -1.0;
        double current_pcr = -1.0;
        long fallback_packets = 0;
        double first_video_pts = -1.0; // ← Локальный маркер выравнивания для текущей сессии

        while ((running || !network_queue.empty()) && !g_hot_switch_requested) {
            PoolBlock block = {0, 0};
            bool has_block = false;
            {
                std::unique_lock<std::mutex> lock(network_mutex);
                network_cv.wait_for(lock, std::chrono::milliseconds(200), [&]() {
                    return !network_queue.empty() || !running || g_hot_switch_requested.load();
                });

                if (g_hot_switch_requested) break;
                if (!running && network_queue.empty()) break;

                if (!network_queue.empty()) {
                    block = network_queue.front();
                    network_queue.pop();
                    has_block = true;
                }
            }

            if (has_block) {
                // Извлекаем данные напрямую из ячейки пула по индексу
                uint8_t* block_ptr = buffer_pool->get_block_ptr(block.pool_idx);
                residual.insert(residual.end(), block_ptr, block_ptr + block.size);
                
                // Сразу же освобождаем блок в пуле для сетевого потока
                buffer_pool->release(block.pool_idx);

                size_t offset = 0;
                while (offset + TS_PACKET_SIZE <= residual.size()) {
                    uint8_t* p = &residual[offset];
                    if (p[0] != 0x47) {
                        offset++;
                        continue;
                    }

                    PacketInfo info = parse_ts_packet_deep(p);

                    // Фаза автодетекта PID
                    if (!pid_found) {
                        if (info.pid == 0) parse_pat(p);
                        else if (found_pmt_pid != -1 && info.pid == found_pmt_pid) {
                            parse_pmt(p);
                            if (detected_video_pid != -1) {
                                pid_found = true;
                                current_segment_name = "seg_" + std::to_string(current_seq) + ".ts";
                                save_pid_cache();
                            }
                        }
                        current_segment_buffer.insert(current_segment_buffer.end(), p, p + TS_PACKET_SIZE);
                        offset += TS_PACKET_SIZE;
                        continue;
                    }

                    // === ЖЕСТКОЕ PTS-ВЫРАВНИВАНИЕ НА ПЕРВОМ КАДРЕ ===
                    if (info.pid == detected_video_pid) {
                        double pts = get_pts_time(p);
                        if (pts > 0 && first_video_pts < 0) {
                            first_video_pts = pts;
                            logger.info("Pipeline parse: First Video PTS aligned at " + std::to_string(first_video_pts) + "s");
                        }
                    }

                    // Отбрасываем все аудиопакеты до тех пор, пока видео не запустится
                    if (info.pid == detected_audio_pid && first_video_pts < 0) {
                        offset += TS_PACKET_SIZE;
                        continue; 
                    }

                    // Фильтрация пакетов по PID
                    bool keep = false;
                    if (target_lang.empty()) {
                        if (info.pid == 0 || info.pid == found_pmt_pid || info.pid == detected_video_pid ||
                            all_audio_pids.count(info.pid) || info.pid == detected_pcr_pid) {
                            keep = true;
                        }
                    } else {
                        if (info.pid == 0) keep = true;
                        else if (info.pid == found_pmt_pid) {
                            rewrite_pmt_packet(p);
                            keep = true;
                        }
                        else if (info.pid == detected_video_pid) keep = true;
                        else if (detected_audio_pid != -1 && info.pid == detected_audio_pid) keep = true;
                        else if (detected_pcr_pid != -1 && info.pid == detected_pcr_pid) keep = true;
                    }

                    // Расчет длительности по PCR
                    double pcr = get_pcr_time(p);
                    if (pcr > 0) {
                        if (segment_start_pcr < 0) segment_start_pcr = pcr;
                        current_pcr = pcr;
                    }

                    double dur = 0.0;
                    if (segment_start_pcr > 0 && current_pcr > 0) {
                        dur = current_pcr - segment_start_pcr;
                        if (dur < -1000.0) dur += 95443.7;
                        if (dur < 0) dur = 0.0;
                    } else {
                        fallback_packets++;
                        dur = (double)fallback_packets / 3300.0;
                    }

                    double target_threshold = get_fast_start_threshold(current_seq);
                    bool is_normal_cut = (info.pid == detected_video_pid && info.is_keyframe && dur >= target_threshold);
                    bool is_force_cut_timeout = (current_seq <= 2 && dur >= (FORCE_CUT_TIMEOUT_MS / 1000.0));
                    bool is_force_cut = (dur >= std::max(target_threshold * 2.0, 4.0));

                    if (is_normal_cut || is_force_cut || is_force_cut_timeout) {
                        if (!current_segment_buffer.empty()) {
                            if (dur < 0.001) dur = 0.001;

                            ProcessedSegment seg;
                            seg.filename = current_segment_name;
                            seg.data = std::move(current_segment_buffer);
                            seg.duration = dur;
                            seg.sequence = current_seq;

                            {
                                std::lock_guard<std::mutex> lock(parse_mutex);
                                parse_queue.push(std::move(seg));
                                parse_cv.notify_one();
                            }

                            current_seq++;
                            global_seq.store(current_seq);
                            current_segment_name = "seg_" + std::to_string(current_seq) + ".ts";
                            current_segment_buffer.clear();
                            current_segment_buffer.reserve(DS_BUFFER_SIZE);
                            segment_start_pcr = -1.0;
                            fallback_packets = 0;
                        }
                    }

                    if (keep) {
                        current_segment_buffer.insert(current_segment_buffer.end(), p, p + TS_PACKET_SIZE);
                    }

                    offset += TS_PACKET_SIZE;
                }

                if (offset > 0) {
                    if (offset >= residual.size()) {
                        residual.clear();
                    } else {
                        residual.erase(residual.begin(), residual.begin() + offset);
                    }
                }
            }
        }

        if (!current_segment_buffer.empty()) {
            ProcessedSegment seg;
            seg.filename = current_segment_name;
            seg.data = std::move(current_segment_buffer);
            seg.duration = 1.0;
            seg.sequence = current_seq;

            {
                std::lock_guard<std::mutex> lock(parse_mutex);
                parse_queue.push(std::move(seg));
                parse_cv.notify_one();
            }
        }

        {
            std::lock_guard<std::mutex> lock(parse_mutex);
            parse_cv.notify_all();
        }
    }

    // ========================================================================
    // PIPELINE PROCESSING: Write Thread
    // ========================================================================
    void write_thread_func(std::atomic<bool>& running, std::deque<SegmentMeta>& window, std::mutex& window_mutex) {
        while ((running || !parse_queue.empty()) && !g_hot_switch_requested) {
            ProcessedSegment segment;
            {
                std::unique_lock<std::mutex> lock(parse_mutex);
                parse_cv.wait_for(lock, std::chrono::milliseconds(200), [&]() {
                    return !parse_queue.empty() || !running || g_hot_switch_requested.load();
                });

                if (g_hot_switch_requested) break;
                if (!running && parse_queue.empty()) break;

                if (!parse_queue.empty()) {
                    segment = std::move(parse_queue.front());
                    parse_queue.pop();
                }
            }

            if (!segment.filename.empty()) {
                std::string path = join_path(save_dir, segment.filename);
                std::string marker_path = path + ".downloading";

                // Streaming write: пишем в финальный файл + .downloading маркер
                { std::ofstream m(marker_path); } // Создаём маркер

                bool write_success = false;
                std::ofstream f(path, std::ios::binary);
                if (f.is_open()) {
                    // Пишем чанками по 32KB с flush для streaming отдачи
                    const size_t CHUNK = 32768;
                    size_t written = 0;
                    while (written < segment.data.size()) {
                        size_t to_write = std::min(CHUNK, segment.data.size() - written);
                        f.write(reinterpret_cast<char*>(segment.data.data() + written), to_write);
                        f.flush();
                        written += to_write;
                    }
                    f.close();
                    write_success = true;
                }

                // Удаляем .downloading маркер — сегмент полностью записан
                std::remove(marker_path.c_str());

                if (write_success) {
                    // Update playlist window
                    {
                        std::lock_guard<std::mutex> lock(window_mutex);
                        window.push_back({segment.filename, segment.duration, segment.sequence});
                        int current_window_size = get_dynamic_window_size(segment.sequence);
                        if ((int)window.size() > current_window_size) {
                            std::string old_file = join_path(save_dir, window.front().filename);
                            try { fs::remove(old_file); } catch(...) {}
                            std::remove((old_file + ".downloading").c_str());
                            window.pop_front();
                        }
                        update_playlist(window, window.empty() ? segment.sequence : window.front().sequence);
                    }

                    logger.info("Pipeline: Wrote segment #" + std::to_string(segment.sequence) +
                               " (" + std::to_string(segment.duration) + "s, " +
                               std::to_string(segment.data.size()) + " bytes, streaming)");
                } else {
                    logger.error("Pipeline: Failed to write segment " + segment.filename);
                }
            }
        }
    }

    // ========================================================================
    // PIPELINE: Main orchestration
    // ========================================================================
    bool run_pipeline(const std::string& start_url,
                     const std::string& user_agent,
                     const std::string& referer,
                     SlotManager& slot_mgr,
                     const std::string& provider,
                     int slot,
                     const std::string& token,
                     bool& client_connected_once,
                     int64_t allocated_at = 0) {
        logger.info("DirectStream: Starting PIPELINE mode");

        fs::create_directories(save_dir);
        std::vector<uint8_t> residual;
        residual.reserve(DS_BUFFER_SIZE * 2);

        // Initialize async components
        int sock = open_http_stream(start_url, residual, user_agent, referer);
        if (sock < 0) {
            logger.error("DirectStream: Failed to open stream.");
            return false;
        }

        try {
            async_reader = std::make_unique<AsyncNetworkReader>(sock);
            async_writer = std::make_unique<AsyncFileWriter>();
            logger.info("DirectStream: Pipeline components initialized");
        } catch (const std::exception& e) {
            logger.error("DirectStream: Failed to initialize pipeline: " + std::string(e.what()));
            close(sock);
            return false;
        }

        // Super instant start
        super_instant_start();

	// If initial connection returned residual data, inject into network queue via BufferPool
        if (!residual.empty()) {
            size_t pool_idx = 0;
            // Получаем свободный блок памяти из пула
            uint8_t* block_ptr = buffer_pool->acquire(pool_idx);
            
            if (block_ptr) {
                // Копируем данные из вектора residual в ячейку пула
                size_t copy_size = std::min(residual.size(), (size_t)DS_BUFFER_SIZE);
                std::memcpy(block_ptr, residual.data(), copy_size);

                std::lock_guard<std::mutex> lock(network_mutex);
                // Отправляем структуру PoolBlock в очередь
                network_queue.push({pool_idx, copy_size});
                network_cv.notify_one();
                
                logger.info("DirectStream: Injected " + std::to_string(copy_size) + 
                            " bytes of residual data into pipeline pool block #" + std::to_string(pool_idx));
            } else {
                logger.error("DirectStream: Failed to acquire pool block for residual data injection!");
            }
        }

        // Start pipeline threads
        pipeline_running = true;
        current_socket = sock;
        std::atomic<bool> network_running{true};
        std::atomic<bool> parse_running{true};
        std::atomic<bool> write_running{true};
        // PID CACHE: попытка загрузить кэш PID для пропуска PAT/PMT фазы
        std::atomic<bool> pid_found{load_pid_cache()};
        std::atomic<int> atomic_global_seq{1};
        std::deque<SegmentMeta> window;
        std::mutex window_mutex;

        std::thread network_thread(&DirectStreamSegmenter::network_thread_func, this, sock, std::ref(network_running));
        std::thread parse_thread(&DirectStreamSegmenter::parse_thread_func, this, std::ref(parse_running), std::ref(pid_found), std::ref(atomic_global_seq));
        std::thread write_thread(&DirectStreamSegmenter::write_thread_func, this, std::ref(write_running), std::ref(window), std::ref(window_mutex));

        logger.info("DirectStream: Pipeline threads started");

        // Monitor and coordinate
        long long last_check_time = get_now_ms();

        while (pipeline_running && g_keep_running && !g_hot_switch_requested) {
            // Check client alive
            long long now_ms = get_now_ms();
            if (now_ms - last_check_time > 1000) {
                if (check_last_access_file(save_dir, channel_id, provider, slot, token, slot_mgr, NORMAL_DURATION, client_connected_once, allocated_at)) {
                    logger.info("DirectStream: Client disconnected, stopping pipeline");
                    break;
                }
                last_check_time = now_ms;
            }

            // Monitor queue sizes and health
            {
                std::lock_guard<std::mutex> lock(network_mutex);
                if (network_queue.size() > 1000) {
                    logger.warning("DirectStream: Network queue backlog: " + std::to_string(network_queue.size()));
                }
            }

            std::this_thread::sleep_for(std::chrono::milliseconds(100));
        }

        // Shutdown pipeline
        logger.info("DirectStream: Shutting down pipeline");
        network_running = false;
        parse_running = false;
        write_running = false;
        pipeline_running = false;

        // Stop async reader to unblock network thread
        if (async_reader) async_reader->stop();

        // Wake up all threads
        network_cv.notify_all();
        parse_cv.notify_all();

        // Wait for threads to complete
        if (network_thread.joinable()) network_thread.join();
        if (parse_thread.joinable()) parse_thread.join();
        if (write_thread.joinable()) write_thread.join();

        // Cleanup socket
        if (sock >= 0) {
            ::close(sock);
            current_socket = -1;
        }

        logger.info("DirectStream: Pipeline shutdown complete");
        return true;
    }

    bool run(const std::string& start_url,
             const std::string& user_agent,
             const std::string& referer,
             SlotManager& slot_mgr,
             const std::string& provider,
             int slot,
             const std::string& token,
             bool& client_connected_once,
             int64_t allocated_at = 0)
    {
        // OPTIMIZED: Use pipeline mode if enabled
        if (use_pipeline) {
            return run_pipeline(start_url, user_agent, referer, slot_mgr, provider, slot, token, client_connected_once, allocated_at);
        }

        // Original single-threaded implementation
        fs::create_directories(save_dir);
        std::vector<uint8_t> residual;
        residual.reserve(DS_BUFFER_SIZE * 2);

        logger.info("DirectStream: Starting single-threaded mode (" + start_url + ")");

        int sock = open_http_stream(start_url, residual, user_agent, referer);
        if (sock < 0) {
            logger.error("DirectStream: Failed to open stream.");
            return false;
        }

        // OPTIMIZED: Initialize async network reader
        try {
            async_reader = std::make_unique<AsyncNetworkReader>(sock);
            logger.info("DirectStream: Async network reader initialized");
        } catch (const std::exception& e) {
            logger.warning("DirectStream: Failed to initialize async reader, using fallback: " + std::string(e.what()));
        }

        // OPTIMIZED: Initialize async file writer
        try {
            async_writer = std::make_unique<AsyncFileWriter>();
            logger.info("DirectStream: Async file writer initialized");
        } catch (const std::exception& e) {
            logger.warning("DirectStream: Failed to initialize async writer: " + std::string(e.what()));
        }

        // OPTIMIZED: Super Instant Start - publish stub playlist immediately
        super_instant_start();

        std::deque<SegmentMeta> window;
        int global_seq = 1;
        double first_video_pts = -1.0;

        // PID CACHE: попытка загрузить кэш PID для пропуска PAT/PMT фазы
        bool pid_found = load_pid_cache();
        std::string cur_name = "seg_0.ts";
        std::ofstream cur_file;

        // Если PID загружен из кэша — сразу открываем сегмент для записи
        if (pid_found) {
            cur_name = "seg_" + std::to_string(global_seq) + ".ts";
            std::string seg_path = join_path(save_dir, cur_name);
            std::string marker_path = seg_path + ".downloading";
            { std::ofstream m(marker_path); }
            cur_file.open(seg_path, std::ios::binary);
            logger.info("DirectStream: PID from cache, streaming write started for seg #" +
                       std::to_string(global_seq) + " (skipped PAT/PMT detection)");
        }
        std::vector<uint8_t> buf_vec(DS_BUFFER_SIZE); 
        uint8_t* buf = buf_vec.data();
        // TIER 1: Pre-PID buffer — collect all packets before PAT/PMT detection
        std::vector<uint8_t> pre_pid_buffer;
        pre_pid_buffer.reserve(TS_PACKET_SIZE * 100); // ~18KB — enough for PAT+PMT phase

        double segment_start_pcr = -1.0;
        double current_pcr = -1.0;
        long fallback_packets = 0;
        int flush_counter = 0;
        long long last_check_time = get_now_ms();

	while (g_keep_running) {
            // === 1. Проверка жизни клиента ДО блокирующего чтения ===
            long long now_ms = get_now_ms();
            if (now_ms - last_check_time > 1000) { 
                if (check_last_access_file(save_dir, channel_id, provider, slot, token, slot_mgr, NORMAL_DURATION, client_connected_once, allocated_at)) {
                    if (sock >= 0) close(sock);
                    return true; // Клиент ушел, корректно завершаем поток
                }
                last_check_time = now_ms;
            }

            // OPTIMIZED: Use async network reader instead of blocking recv()
            ssize_t n;
            if (async_reader) {
                n = async_reader->async_read(buf, DS_BUFFER_SIZE, 100);  // 100ms timeout
            } else {
                n = recv(sock, buf, DS_BUFFER_SIZE, 0);  // Fallback to blocking if async not initialized
            }
            
            // === 2. ОБРАБОТКА ОБРЫВА СОЕДИНЕНИЯ И ПЕРЕПОДКЛЮЧЕНИЕ ===
            if (n <= 0) {
                bool is_timeout = (n < 0 && (errno == EINTR || errno == EAGAIN));
                if (is_timeout) {
                    logger.warning("DirectStream: Source stream frozen (10s timeout).");
                } else {
                    logger.warning("DirectStream: Socket closed by peer or network error.");
                }

                if (sock >= 0) { close(sock); sock = -1; }

                int retry_count = 0;
                bool reconnected = false;

                // Цикл из 10 попыток переподключения
                while (retry_count < 10 && g_keep_running) {
                    retry_count++;
                    logger.info("DirectStream: Reconnecting... Attempt " + std::to_string(retry_count) + " of 10");

                    // Обязательно проверяем, не ушел ли клиент, пока мы пытаемся переподключиться
                    if (check_last_access_file(save_dir, channel_id, provider, slot, token, slot_mgr, NORMAL_DURATION, client_connected_once,allocated_at)) {
                        logger.info("DirectStream: Client left during reconnect. Stopping.");
                        return true; 
                    }

                    residual.clear(); // Сбрасываем старые недокачанные "ошметки"
                    sock = open_http_stream(start_url, residual, user_agent, referer);

                    if (sock >= 0) {
                        reconnected = true;
                        // OPTIMIZED: Reinitialize async reader after reconnect
                        try {
                            async_reader = std::make_unique<AsyncNetworkReader>(sock);
                            logger.info("DirectStream: Async network reader reinitialized after reconnect");
                        } catch (const std::exception& e) {
                            logger.warning("DirectStream: Failed to reinitialize async reader: " + std::string(e.what()));
                        }
                        logger.info("DirectStream: Successfully reconnected to source!");
                        break;
                    }

                    // Пауза 2 секунды перед следующей попыткой (проверяя флаг выхода)
                    for (int w = 0; w < 20 && g_keep_running; ++w) {
                        std::this_thread::sleep_for(std::chrono::milliseconds(100));
                    }
                }

                if (!reconnected) {
                    logger.error("DirectStream: Failed to reconnect after 10 attempts. Triggering failover.");
                    return false; // Выход в главный цикл для смены источника (Failover)
                }

                // Мы успешно переподключились!
                // Закрываем оборванный сегмент, если он был открыт, и отдаем его клиенту "как есть".
                if (cur_file.is_open()) {
                    cur_file.flush();
                    cur_file.close();
                    
                    // Удаляем .downloading маркер — сегмент закончен (пусть и частично)
                    std::remove((join_path(save_dir, cur_name) + ".downloading").c_str());
                    
                    double dur = 0.1;
                    if (segment_start_pcr > 0 && current_pcr > 0) {
                        dur = current_pcr - segment_start_pcr;
                        if (dur < 0) dur += 95443.7;
                    } else {
                        dur = (double)fallback_packets / 3300.0;
                    }
                    if (dur < 0.1) dur = 0.1;

                    window.push_back({cur_name, dur, global_seq});
                    int current_window_size = get_dynamic_window_size(global_seq);
                    if (window.size() > static_cast<size_t>(current_window_size)) {
                        std::string old_file = join_path(save_dir, window.front().filename);
                        try { fs::remove(old_file); } catch(...) {}
                        std::remove((old_file + ".downloading").c_str());
                        window.pop_front();
                    }
                    update_playlist(window, global_seq);

                    logger.info("DirectStream: Saved partial segment #" + std::to_string(global_seq) + " due to reconnect.");
                    
                    global_seq++;
                    cur_name = "seg_" + std::to_string(global_seq) + ".ts";
                    flush_counter = 0;
                }

                // Сброс парсера, чтобы он заново нашел PAT/PMT в новом потоке
                pid_found = false;
                segment_start_pcr = -1.0;
                current_pcr = -1.0;
                fallback_packets = 0;
                pre_pid_buffer.clear();
                last_check_time = get_now_ms();

                continue; // Начинаем чтение с нового сокета
            }

            // === 3. ШТАТНАЯ ОБРАБОТКА ДАННЫХ ===
            size_t old_size = residual.size();
            residual.resize(old_size + n);
            std::memcpy(residual.data() + old_size, buf, n);

            size_t offset = 0;
            while (offset + TS_PACKET_SIZE <= residual.size()) {
                uint8_t* p = &residual[offset];
                if (p[0] != 0x47) { offset++; continue; }

                PacketInfo info = parse_ts_packet_deep(p);

                if (!pid_found) {
                    if (info.pid == 0) parse_pat(p);
                    else if (found_pmt_pid != -1 && info.pid == found_pmt_pid) {
                        parse_pmt(p);
                        bool ready = false;
                        if (!target_lang.empty()) {
                             if (detected_video_pid != -1 && (detected_audio_pid != -1 || fallback_audio_pid != -1)) {
                                 if (detected_audio_pid == -1) { detected_audio_pid = fallback_audio_pid; detected_audio_type = fallback_audio_type; }
                                 ready = true;
                             }
                        } else { if (detected_video_pid != -1) ready = true; }

                        if (ready) {
                            pid_found = true;
                            
                            cur_name = "seg_" + std::to_string(global_seq) + ".ts";
                            
                            // STREAMING WRITE: пишем напрямую в финальный файл + .downloading маркер
                            // Nginx stream_segment.lua начнёт отдавать данные клиенту по мере записи
                            std::string seg_path = join_path(save_dir, cur_name);
                            std::string marker_path = seg_path + ".downloading";
                            { std::ofstream m(marker_path); } // Создаём маркер
                            
                            if (!cur_file.is_open()) {
                                cur_file.open(seg_path, std::ios::binary);
                            }

                            // Flush pre-buffered packets (PAT/PMT + all data before PID detection)
                            if (!pre_pid_buffer.empty() && cur_file.is_open()) {
                                cur_file.write(reinterpret_cast<char*>(pre_pid_buffer.data()), pre_pid_buffer.size());
                                cur_file.flush(); // Данные видны Nginx мгновенно
                                logger.info("DirectStream: Flushed " + std::to_string(pre_pid_buffer.size()) + 
                                           " pre-PID bytes to segment (streaming)");
                                pre_pid_buffer.clear();
                                pre_pid_buffer.shrink_to_fit();
                            }
                            
                            // Instant Start: playlist уже опубликован в super_instant_start()
                            // с именем seg_1.ts, файл + маркер уже созданы
                            logger.info("DirectStream: PID detected, streaming write started for seg #" +
                                       std::to_string(global_seq));

                            // PID CACHE: сохраняем для следующего запуска
                            save_pid_cache();

                            segment_start_pcr = -1.0;
                        }
                    }
                    // TIER 1: Buffer ALL packets during PID detection phase
                    // so first segment starts from byte 0 of stream
                    pre_pid_buffer.insert(pre_pid_buffer.end(), p, p + TS_PACKET_SIZE);
                    offset += TS_PACKET_SIZE; continue;
                }

                // ... (тут остается ваш код keep: проверка pid и фильтрация) ...
                if (info.pid == detected_video_pid) {
                    double pts = get_pts_time(p);
                    if (pts > 0 && first_video_pts < 0) {
                        first_video_pts = pts;
                        logger.info("DirectStream: First Video PTS aligned at " + std::to_string(first_video_pts) + "s");
                    }
                }

                // Отбрасываем (drop) аудиопакеты до тех пор, пока видеодекодер не захватит первый PTS
                if (info.pid == detected_audio_pid && first_video_pts < 0) {
                    offset += TS_PACKET_SIZE;
                    continue; // Пропускаем этот пакет, переходим к следующему
                }
                // =================================================================

                // === ДАЛЕЕ ИДЕТ СТАНДАРТНЫЙ БЛОК ФИЛЬТРАЦИИ ПАКЕТОВ ===
                bool keep = false;
                if (target_lang.empty()) {
                    if (info.pid == 0 || info.pid == found_pmt_pid || info.pid == detected_video_pid || all_audio_pids.count(info.pid) || info.pid == detected_pcr_pid) keep = true;
                } else {
                    if (info.pid == 0) keep = true;
                    else if (info.pid == found_pmt_pid) { rewrite_pmt_packet(p); keep = true; }
                    else if (info.pid == detected_video_pid) keep = true;
                    else if (detected_audio_pid != -1 && info.pid == detected_audio_pid) keep = true;
                    else if (detected_pcr_pid != -1 && info.pid == detected_pcr_pid) keep = true;
                }

                double pcr = get_pcr_time(p);
                if (pcr > 0) {
                    if (segment_start_pcr < 0) segment_start_pcr = pcr;
                    current_pcr = pcr;
                }
                
                double dur = 0.0;
                if (segment_start_pcr > 0 && current_pcr > 0) {
                    dur = current_pcr - segment_start_pcr;
                    if (dur < -1000.0) dur += 95443.7;
                    if (dur < 0) dur = 0.0;
                } else {
                    fallback_packets++;
                    dur = (double)fallback_packets / 3300.0;
                }

                // TIER 1: Progressive segment cutting — первые сегменты по keyframe без ожидания
                double target_threshold = get_fast_start_threshold(global_seq);

                bool is_normal_cut = (info.pid == detected_video_pid && info.is_keyframe && dur >= target_threshold);
                // TIER 1: Force-cut если нет keyframe за 500ms (первые 3 сегмента)

/*                bool is_force_cut_timeout = false;
                if (global_seq <= 2 && dur >= (FORCE_CUT_TIMEOUT_MS / 1000.0)) {
                    is_force_cut_timeout = true;
                }*/

bool is_force_cut_timeout = false;
if (global_seq <= 2 && dur >= (FORCE_CUT_TIMEOUT_FAST_MS / 1000.0)) {
    is_force_cut_timeout = true;
} else if (global_seq <= 4 && dur >= (FORCE_CUT_TIMEOUT_RAMP_MS / 1000.0)) {
    is_force_cut_timeout = true;
} else if (global_seq > 4 && dur >= (FORCE_CUT_TIMEOUT_NORM_MS / 1000.0)) {
    is_force_cut_timeout = true;
}

                bool is_force_cut = (dur >= std::max(target_threshold * 2.0, 4.0));

                if (is_normal_cut || is_force_cut || is_force_cut_timeout) {
                    cur_file.flush();
                    cur_file.close();
                    if (dur < 0.001) dur = 0.001; 

                    // Удаляем .downloading маркер — сегмент готов
                    std::string marker_path = join_path(save_dir, cur_name) + ".downloading";
                    std::remove(marker_path.c_str());

                    window.push_back({cur_name, dur, global_seq});
                    int current_window_size = get_dynamic_window_size(global_seq);
                    if (window.size() > current_window_size) {
                        // Удаляем старый файл и его маркер (на всякий случай)
                        std::string old_file = join_path(save_dir, window.front().filename);
                        try { fs::remove(old_file); } catch(...) {}
                        std::remove((old_file + ".downloading").c_str());
                        window.pop_front();
                    }

                    // Обновляем плейлист с готовым сегментом
                    update_playlist(window, window.front().sequence);

                    logger.info("DirectStream: Seg #" + std::to_string(global_seq) +
                                " (" + std::to_string(dur) + "s / Tgt: " + std::to_string(target_threshold) + "s" +
                                (is_force_cut_timeout ? " FORCE_CUT" : "") + ") - added to playlist");

                    global_seq++;

                    // Открываем новый сегмент: streaming write (финальное имя + маркер)
                    cur_name = "seg_" + std::to_string(global_seq) + ".ts";
                    std::string new_seg_path = join_path(save_dir, cur_name);
                    std::string new_marker = new_seg_path + ".downloading";
                    { std::ofstream m(new_marker); } // Создаём маркер
                    cur_file.open(new_seg_path, std::ios::binary);

                    segment_start_pcr = -1.0;
                    fallback_packets = 0;
                    flush_counter = 0;
                }

                if (keep && cur_file.is_open()) {
                    cur_file.write((char*)p, TS_PACKET_SIZE);
                    // Flush каждые ~32KB для streaming отдачи через Nginx
                    flush_counter += TS_PACKET_SIZE;
size_t flush_threshold = (global_seq <= 2) ? (size_t)TS_PACKET_SIZE * 10  // ~1.8KB
                                            : (size_t)32768;
if (flush_counter >= flush_threshold) {
                    //if (flush_counter >= 32768) {
                        cur_file.flush();
                        flush_counter = 0;
                    }
                }
                offset += TS_PACKET_SIZE;
            }

            if (offset > 0) {
                if (offset >= residual.size()) residual.clear();
                else residual.erase(residual.begin(), residual.begin() + offset);
            }
        }
        return true; 
    }
};
// ==========================================
// END OF DIRECT STREAM SEGMENTER
// ==========================================

void monitor_hls_stream(std::string sources_json_arg, 
                        const std::string& default_quality,
                        const std::string& archive_dir,
                        std::string channel,
                        std::string provider,
                        int slot,
                        std::string token,
                        int start_write_after_segment = 0,
                        int64_t allocated_at = 0) {
    
    redis_message_sent = false;
    //stop_cleanup_event = false;
    g_keep_running = true;
    bool is_first_run = true;

    // Флаг состояния подключения клиента
    bool client_has_connected_once = false;
    double current_stream_target_duration = 6.0;

    // === 1. УЛУЧШЕННЫЙ ПАРСИНГ ИСТОЧНИКОВ ===
    std::vector<StreamSource> sources;
    {
        Json::Value root;
        Json::CharReaderBuilder builder;
        std::istringstream iss(sources_json_arg);
        std::string errs;
        
        // Попытка распарсить как JSON массив
        if (Json::parseFromStream(builder, iss, &root, &errs) && root.isArray()) {
            for (const auto& item : root) {
                // Проверяем, есть ли вложенные кандидаты (для твклуб и подобных)
                if (item.isMember("candidates") && item["candidates"].isArray()) {
                    // Это группа источников (например, provider_tvclub)
                    bool manage = item.get("manage_slot", false).asBool();
                    std::string u_key = item.get("usage_key", "").asString();
                    int limit = item.get("limit", 0).asInt();
                    std::string type = item.get("type", "unknown").asString();

                    for (const auto& cand : item["candidates"]) {
                        StreamSource s;
                        s.url = cand["url"].asString();
                        s.agent = cand.get("agent", USER_AGENT).asString(); // Или брать из parent, если нужно
                        s.quality = cand.get("quality", default_quality).asString();

			if (item.isMember("bandwidth")) {
			    // В JSON может прийти и строка "3727157", и число 3727157
			    if (item["bandwidth"].isString()) {
			        try { s.bandwidth = std::stol(item["bandwidth"].asString()); } catch(...) { s.bandwidth = 0; }
			    } else if (item["bandwidth"].isInt64() || item["bandwidth"].isInt()) {
			        s.bandwidth = (long)item["bandwidth"].asInt64();
			    }
			}
                        
                        // Наследуем параметры управления слотами от родителя
                        s.manage_slot = manage;
                        s.usage_key = u_key;
                        s.limit = limit;
                        s.provider_type = type;
                        
                        // Токен в кандидате может переопределять глобальный, если нужно, 
                        // но пока оставим url как есть.
                        sources.push_back(s);
                    }
                } else {
                    // Обычный одиночный источник (main, direct_url и т.д.)
                    StreamSource s;
                    s.url = item["url"].asString();
                    s.agent = item.get("agent", USER_AGENT).asString();
                    s.referer = item.get("referer", "").asString();
                    s.quality = item.get("quality", default_quality).asString();
                    s.provider_type = item.get("type", "simple").asString();
                    // Обычно main не требует manage_slot, но если придет в JSON - учтем
                    s.manage_slot = item.get("manage_slot", false).asBool(); 
                    if (s.manage_slot) {
                         s.usage_key = item.get("usage_key", "").asString();
                         s.limit = item.get("limit", 0).asInt();
                    }
                    sources.push_back(s);
                }
            }
        } else {
            // Fallback для совместимости (просто URL строка)
            StreamSource s;
            s.url = sources_json_arg;
            s.agent = USER_AGENT;
            s.quality = default_quality;
            sources.push_back(s);
        }
    }

    if (sources.empty()) return;
    
    signal(SIGTERM, child_signal_handler);
    signal(SIGINT, child_signal_handler);

    logger.info("Starting HLS stream monitoring. Sources queue size: " + std::to_string(sources.size()));

    std::string save_dir = BASE_DIR + "/" + channel;
    fs::create_directories(save_dir);
    fs::create_directories(archive_dir);
    
    std::string source_ip = token.empty() ? "" : select_ipv6_address(token, channel);
    SlotManager slot_manager;

    // === FAST ZAPPING: Initialize global timers ===
    g_last_check_time = std::chrono::steady_clock::now();
    g_monitoring_start_time = std::chrono::steady_clock::now();
    g_playlist_detected_time = std::chrono::steady_clock::time_point::min();
    logger.info("[FAST_ZAP_DEBUG] Global timers initialized");

    // === FAST ZAPPING: Start channel_control subscriber ===
    start_channel_control_subscriber(channel, provider, slot);
    logger.info("[FAST_ZAP_DEBUG] Channel control subscriber started");

    // === Блокировка процесса (Lock file) ===
    std::string lock_file = "/tmp/hls_check_" + channel + ".lock";
    int lock_fd = open(lock_file.c_str(), O_CREAT | O_WRONLY | O_TRUNC, 0644);
    if (lock_fd != -1) {
        struct flock fl = {F_WRLCK, SEEK_SET, 0, 0, 0};
        if (fcntl(lock_fd, F_SETLK, &fl) == -1) {logger.warning("Process for channel " + channel + " is already running. Aborting duplicate."); close(lock_fd); return; } // Already running
    } else {
        std::string active_file = lock_file + ".active";
        // Простая проверка, чтобы не перезапускать слишком часто, если файл остался
        if (fs::exists(active_file)) {
             // Можно добавить проверку PID, но для упрощения оставим как есть
              throw std::runtime_error("Already running");
        }
        std::ofstream active(active_file);
        active << getpid();
        active.close();
    }
    // =======================================

    StreamGuard guard(save_dir, channel, provider, slot, slot_manager, token, source_ip, allocated_at);
    
    //std::thread cleanup_thread(run_cleanup_task, save_dir);
    //cleanup_thread.detach();

    static double prev_target = 6.0;
    CurlMultiDownloader shared_downloader(source_ip);

    // PIPELINE: Create HLS pipeline processor for async playlist writes + cleanup
    HlsPipelineProcessor hls_pipeline(true); // true = ZeroCopy enabled

    size_t current_idx = 0;
    bool source_needs_switch = false; // Флаг для принудительного поиска следующего источника

    // Счетчики для failover логики
    int failover_attempts = 0;           // Сколько раз делали failover для текущего провайдера
    const int MAX_FAILOVER_PER_PROVIDER = 2;  // Максимум 2 попытки на провайдера
    int total_failover_attempts = 0;     // Общее количество failover попыток
    const int MAX_TOTAL_FAILOVERS = 4;   // Максимум 4 failover (2 провайдера * 2 попытки)
    std::string last_failed_provider = "";  // Последний провайдер который упал

    // Счетчики для мониторинга скачивания сегментов
    int consecutive_failed_downloads = 0;  // Сколько итераций подряд не скачали ни одного сегмента
    const int MAX_FAILED_DOWNLOADS = 3;    // Максимум 3 неудачных попытки подряд -> failover

    // C++ больше не управляет слотами напрямую
    // Lua выделяет слот при отправке START команды
    // При failover C++ запрашивает новый слот через REQUEST_FAILOVER

    // Текущий URL источника (обновляется при SWITCH и failover)
    std::string current_url = sources[current_idx].url;

 HttpRequestSession playlist_session(source_ip);

auto last_cleanup_time = std::chrono::steady_clock::now();
const auto CLEANUP_INTERVAL = std::chrono::seconds(30);
std::vector<M3U8Playlist::Segment> last_active_segments;
std::string active_media_url = "";

 while (g_keep_running) {
        // === FAST ZAPPING: Check for HOT SWITCH ===
        // === FAST ZAPPING: Check for HOT SWITCH ===
        if (g_hot_switch_requested.load()) {
            SwitchCommand target_cmd;
            
            // 1. Выгребаем все накопившиеся команды из очереди
            {
                std::lock_guard<std::mutex> lock(g_switch_queue_mutex);
                if (g_switch_queue.empty()) {
                    g_hot_switch_requested.store(false);
                    continue;
                }
                
                // Оставляем ТОЛЬКО последнюю команду переключения
                while (!g_switch_queue.empty()) {
                    target_cmd = g_switch_queue.front();
                    g_switch_queue.pop();
                }
                g_hot_switch_requested.store(false); // Сбрасываем флаг
            }

            logger.info("[FAST_ZAP_DEBUG] ========================================");
            logger.info("[FAST_ZAP_DEBUG] HOT SWITCH EXECUTING!");
            logger.info("[FAST_ZAP_DEBUG] Old channel: " + channel);
            logger.info("[FAST_ZAP_DEBUG] New channel: " + target_cmd.channel);
            logger.info("[FAST_ZAP_DEBUG] Old source_url: " + (current_idx < sources.size() ? sources[current_idx].url : ""));
            logger.info("[FAST_ZAP_DEBUG] New source_url: " + target_cmd.source_url);
            logger.info("[FAST_ZAP_DEBUG] Old save_dir: " + save_dir);
            logger.info("[FAST_ZAP_DEBUG] New save_dir: " + target_cmd.save_dir);
            logger.info("[FAST_ZAP_DEBUG] ========================================");

            // Сохраняем старую директорию ДО обновления переменных
            std::string old_save_dir = save_dir;

            // Прерываем все незавершённые загрузки
            shared_downloader.clear();
            logger.info("[FAST_ZAP_DEBUG] Cleared pending downloads");

            // PIPELINE: Stop old pipeline and create fresh one for new channel
            hls_pipeline.stop();
            logger.info("[FAST_ZAP_DEBUG] HLS Pipeline stopped for channel switch");

	    // Обновляем переменные
            channel = target_cmd.channel;
            save_dir = target_cmd.save_dir;

            // ========================================================
            // >>> ИСПРАВЛЕНИЕ БЛОКИРОВОК (HOT SWITCH LOCK FIX) <<<
            // ========================================================
            // 1. Отпускаем старый lock-файл
            if (lock_fd != -1) {
                close(lock_fd);
                lock_fd = -1;
            }

            // 2. Захватываем новый lock-файл для нового канала
            std::string new_lock_file = "/tmp/hls_check_" + channel + ".lock";
            lock_fd = open(new_lock_file.c_str(), O_CREAT | O_WRONLY | O_TRUNC, 0644);
            if (lock_fd != -1) {
                struct flock fl = {F_WRLCK, SEEK_SET, 0, 0, 0};
                if (fcntl(lock_fd, F_SETLK, &fl) == -1) { 
                    // Если целевой канал УЖЕ кем-то заблокирован (Race Condition)
                    logger.error("[FAST_ZAP_CRITICAL] Target channel " + channel + " is already locked by another process! Aborting proxy.");
                    close(lock_fd);
                    g_keep_running = false; // Завершаем себя, так как слот уже занят
                    break;
                }
            }
            // ========================================================

            if (target_cmd.slot >= 0 && target_cmd.slot != slot) {
                logger.info("[FAST_ZAP_DEBUG] Updating slot: " + std::to_string(slot) +
                            " → " + std::to_string(target_cmd.slot));
                slot = target_cmd.slot;
            }
            if (!target_cmd.token.empty() && target_cmd.token != token) {
                logger.info("[FAST_ZAP_DEBUG] Updating token: " + token +
                            " → " + target_cmd.token);
                token = target_cmd.token;
            }
            if (!target_cmd.provider.empty() && target_cmd.provider != provider) {
                logger.info("[FAST_ZAP_DEBUG] Updating provider: " + provider +
                            " → " + target_cmd.provider);
                provider = target_cmd.provider;

                // Сбрасываем счетчики failover при смене провайдера
                failover_attempts = 0;
                last_failed_provider = target_cmd.provider;
                logger.info("[FAILOVER] Reset failover counter for new provider: " + target_cmd.provider);
            }

            // Обновляем StreamGuard
            guard.update_channel(target_cmd.channel, target_cmd.save_dir,
                                 target_cmd.slot, target_cmd.token, target_cmd.allocated_at);

            // Переоткрываем per-channel лог (потокобезопасно через logger)
            {
                std::string new_log = "/var/log/cgi/hls_proxy_" + target_cmd.channel + ".log";
                logger.redirect_output(new_log);
                logger.info("[FAST_ZAP_DEBUG] Log file switched to: " + new_log);
            }

// Оставляем только текущий источник, отбрасывая старые fallback'и,
            // так как они принадлежат старому каналу и больше не валидны!
            if (current_idx < sources.size()) {
                StreamSource active_src = sources[current_idx];
                active_src.url = target_cmd.source_url;
                sources.clear();
                sources.push_back(active_src);
                current_idx = 0;
            }
            active_media_url = ""; // <--- Сбрасываем кэш Media URL для нового канала

            // Создаём новую директорию
            fs::create_directories(save_dir);

            // Сбрасываем таймеры
            reset_last_access_check();
            client_has_connected_once = false;

            // Сбрасываем ChannelState для нового канала
            {
                std::lock_guard<std::mutex> lock(g_state_mutex);
                g_channel_states[target_cmd.channel] = ChannelState();
            }

            is_first_run = true;
            redis_message_sent = false;

            // Очищаем СТАРУЮ директорию
            try {
                clear_directory_files(old_save_dir);
                logger.info("[FAST_ZAP_DEBUG] Cleared OLD directory: " + old_save_dir);
            } catch (...) {}


            // PIPELINE: Restart pipeline
            hls_pipeline.restart();

            logger.info("[FAST_ZAP_DEBUG] HOT SWITCH completed successfully");
            logger.info("[FAST_ZAP_DEBUG] Now monitoring: " + save_dir + "/last_access");
            logger.info("[FAST_ZAP_DEBUG] ========================================");

            // Продолжаем цикл с новым каналом
            continue;
        }
        // === ЛОГИКА ПЕРЕКЛЮЧЕНИЯ ИСТОЧНИКОВ (FAILOVER) ===
        if (source_needs_switch) {
            // Проверяем лимиты failover
            total_failover_attempts++;

            // Если сменился провайдер, сбрасываем счетчик попыток для провайдера
            if (last_failed_provider != provider) {
                failover_attempts = 0;
                last_failed_provider = provider;
            }

            failover_attempts++;

            logger.info("[FAILOVER] Attempt " + std::to_string(failover_attempts) + "/" +
                       std::to_string(MAX_FAILOVER_PER_PROVIDER) + " for provider " + provider +
                       " (total: " + std::to_string(total_failover_attempts) + "/" +
                       std::to_string(MAX_TOTAL_FAILOVERS) + ")");

            // Проверяем не превысили ли лимиты
            if (total_failover_attempts > MAX_TOTAL_FAILOVERS) {
                logger.error("[FAILOVER] Exceeded maximum total failover attempts (" +
                           std::to_string(MAX_TOTAL_FAILOVERS) + "). Stopping proxy.");
                g_keep_running = false;
                break;
            }

            if (failover_attempts > MAX_FAILOVER_PER_PROVIDER) {
                logger.error("[FAILOVER] Exceeded maximum failover attempts for provider " + provider +
                           " (" + std::to_string(MAX_FAILOVER_PER_PROVIDER) + "). Stopping proxy.");
                g_keep_running = false;
                break;
            }

            // C++ НЕ управляет слотами напрямую
            // Вместо этого запрашиваем у Lua новый источник через REQUEST_FAILOVER

            logger.info("[FAILOVER] Current source failed, requesting failover from Lua...");

            try {
                Json::Value request;
                request["action"] = "REQUEST_FAILOVER";
                request["channel"] = channel;
                request["reason"] = "source_failed";
                request["failed_url"] = current_url;
                request["current_provider"] = provider;
                request["current_slot"] = slot;
                request["failover_attempt"] = failover_attempts;

                Json::StreamWriterBuilder builder;
                builder["indentation"] = "";
                std::string msg = Json::writeString(builder, request);

                redisContext* redis_ctx = slot_manager.get_redis_context();
                if (redis_ctx) {
                    redisReply* reply = (redisReply*)redisCommand(redis_ctx,
                        "PUBLISH channel_control %s", msg.c_str());
                    if (reply) {
                        freeReplyObject(reply);
                        logger.info("[FAILOVER] REQUEST_FAILOVER sent to Lua");
                    }
                }
            } catch (const std::exception& e) {
                logger.error("[FAILOVER] Failed to send REQUEST_FAILOVER: " + std::string(e.what()));
            }

            // Ждём SWITCH команду от Lua (через g_hot_switch_requested)
            logger.info("[FAILOVER] Waiting for Lua to provide failover source...");
            auto timeout = std::chrono::steady_clock::now() + std::chrono::seconds(10);

            bool found = false;
            while (!g_hot_switch_requested.load() &&
                   std::chrono::steady_clock::now() < timeout &&
                   g_keep_running.load()) {
                std::this_thread::sleep_for(std::chrono::milliseconds(100));
            }

            if (g_hot_switch_requested.load()) {
                logger.info("[FAILOVER] Failover source received from Lua, will be processed in next iteration");
                found = true;
                source_needs_switch = false;
                continue;  // Обработается в начале цикла
            } else {
                logger.error("[FAILOVER] Lua failover timeout after 10 seconds");
                found = false;
            }

            if (!found) {
                logger.error("[FAILOVER] Lua did not provide failover source. Stopping process.");
                g_keep_running = false;
                break;
            }
        }

        StreamSource& src = sources[current_idx];
        current_url = src.url;  // Обновляем существующую переменную
        std::string current_ua = src.agent;
        std::string current_ref = src.referer;

	playlist_session.set_user_agent(current_ua);
        playlist_session.set_referer(current_ref);
        shared_downloader.set_user_agent(current_ua);
        shared_downloader.set_referer(current_ref);

        slot_manager.report_active_source(channel, current_url, current_ua);

        // Внешнее обновление URL/slot/token (listener пишет JSON когда процесс already_running)
        {
            int upd_slot = slot;
            std::string upd_token = token;
            if (check_url_update(channel, current_url, provider, &upd_slot, &upd_token)) {
                src.url = current_url;
		active_media_url = "";
                logger.info("URL updated externally for source #" + std::to_string(current_idx));
                if (upd_slot != slot) {
                    slot = upd_slot;
                    guard.update_channel(channel, save_dir, upd_slot, "");
                }
                if (upd_token != token) {
                    token = upd_token;
                    guard.update_channel(channel, save_dir, -1, upd_token);
                }
            }
        }

/*        // Проверка last_access
        if (check_last_access_file(save_dir, channel, provider, slot, is_first_run, slot_manager,allocated_at)) {
            logger.info("Stopping due to expired last_access");
            break; 
        }*/

        if (g_hot_switch_requested) {
            continue;
        }

        // === HEARTBEAT: Отправляем признак жизни каждые 5 секунд ===
        static auto last_heartbeat = std::chrono::steady_clock::now();
        auto now_hb = std::chrono::steady_clock::now();
        if (std::chrono::duration_cast<std::chrono::seconds>(now_hb - last_heartbeat).count() >= 5) {
            try {
                redisContext* redis_ctx = slot_manager.get_redis_context();
                if (redis_ctx) {
                    // Устанавливаем heartbeat с TTL 15 секунд
                    // Если C++ упадёт, ключ истечёт через 15 секунд
                    redisReply* reply = (redisReply*)redisCommand(redis_ctx,
                        "SETEX proxy_heartbeat:%s 15 %d", channel.c_str(), (int)time(nullptr));
                    if (reply) {
                        freeReplyObject(reply);
                    }
                }
            } catch (...) {}
            last_heartbeat = now_hb;
        }

        // ПРОВЕРКА ТАЙМАУТА
        // Передаем current_stream_target_duration и флаг client_has_connected_once
        if (check_last_access_file(save_dir, channel, provider, slot, token,
                                   slot_manager, current_stream_target_duration, client_has_connected_once,allocated_at)) {
            break;
        }

        // Peers.TV логика
        if (current_url.find("api.peers.tv") != std::string::npos) {
            std::string current_token = get_or_refresh_peers_token();
            if (current_token.empty()) {
                logger.error("Failed to refresh Peers.TV token — skipping iteration");
                std::this_thread::sleep_for(std::chrono::seconds(5));
                continue;
            }
            current_url = replace_peers_token_in_url(current_url);
        }
        
        // Sputnik24 логика
        /*if (!g_sputnik_cache.empty() && current_url.find("sputnik24") != std::string::npos) {
            std::string channel_id = g_sputnik_cache.begin()->first;
            long expire_date = g_sputnik_cache.begin()->second.expire_date;
            auto now = std::chrono::duration_cast<std::chrono::seconds>(
                std::chrono::system_clock::now().time_since_epoch()).count();

            if (expire_date <= now) {
                std::string new_url = get_valid_sputnik_source(channel_id);
                if (!new_url.empty()) {
                    src.url = new_url;
                    current_url = new_url;
                }
            }
        }*/

// ==========================================
        // УМНАЯ ЛОГИКА SPUTNIK24.TV
        // ==========================================
        if (current_url.find("sputnik24.tv") != std::string::npos) {
            logger.info("Sputnik24: Detected URL: " + current_url);
            std::string channel_id;

            // 1. Попытка достать ID из стартовой API ссылки (например, /get-playlist-channel/165)
            size_t api_pos = current_url.find("get-playlist-channel/");
            if (api_pos != std::string::npos) {
                size_t start = api_pos + 21; // длина "get-playlist-channel/"
                size_t end = current_url.find('?', start);
                channel_id = current_url.substr(start, end == std::string::npos ? std::string::npos : end - start);
                logger.info("Sputnik24: Extracted channel_id from API URL: " + channel_id);
            }
            // 2. Если мы уже играем поток и у нас есть ID в кэше
            else if (!g_sputnik_cache.empty()) {
                channel_id = g_sputnik_cache.begin()->first;
                logger.info("Sputnik24: Using cached channel_id: " + channel_id);
            }

            // 3. Запрашиваем реальный плейлист
            if (!channel_id.empty()) {
                // get_valid_sputnik_source сама проверит кэш и сделает API запрос, если нужно
                std::string valid_hls_url = get_valid_sputnik_source(channel_id);
                if (!valid_hls_url.empty()) {
                    logger.info("Sputnik24: Resolved to HLS URL: " + valid_hls_url);
                    current_url = valid_hls_url; // Теперь тут лежит ссылка с .m3u8!
                } else {
                    logger.error("Failed to resolve Sputnik24 API URL -> HLS URL. Retrying...");
                    std::this_thread::sleep_for(std::chrono::seconds(5));
                    continue;
                }
            } else {
                logger.error("Sputnik24: channel_id is empty!");
            }
        }
        // ==========================================

        // ==========================================
        // УМНАЯ ЛОГИКА LIMEHD (IPTV2021.COM)
        // ==========================================
        if (current_url.find("pl.iptv2021.com") != std::string::npos || current_url.find("limehd.tv") != std::string::npos) {
            logger.info("LimeHD: Detected API URL: " + current_url);
            
            // 1. Извлекаем ID канала из ссылки
            std::string chan_id = "";
            size_t ch_start = current_url.find("/channel/");
            if (ch_start != std::string::npos) {
                ch_start += 9;
                size_t ch_end = current_url.find('?', ch_start);
                if (ch_end != std::string::npos) {
                    chan_id = current_url.substr(ch_start, ch_end - ch_start);
                } else {
                    chan_id = current_url.substr(ch_start);
                }
            }

            // Извлекаем параметр tz=... если он есть
            std::string tz_param = "tz=5";
            size_t tz_pos = current_url.find("tz=");
            if (tz_pos != std::string::npos) {
                size_t tz_end = current_url.find('&', tz_pos);
                if (tz_end != std::string::npos) {
                    tz_param = current_url.substr(tz_pos, tz_end - tz_pos);
                } else {
                    tz_param = current_url.substr(tz_pos);
                }
            }

            // 2. Формируем URL API v4
            auto now_ms = std::chrono::duration_cast<std::chrono::milliseconds>(
                std::chrono::system_clock::now().time_since_epoch()).count();
                
            std::ostringstream new_url_stream;
            new_url_stream << "https://pl.iptv2021.com/api/v4/channel/" << chan_id 
                           << "?epg=0&podcasts=0&installts=" << now_ms 
                           << "&region=21&" << tz_param;
                           
            std::string api_url = new_url_stream.str();
            logger.info("LimeHD: Generated API URL: " + api_url);

            // 3. Устанавливаем специфичные заголовки
            std::string device_id = "67730.65600974215-1775809608898";
            std::string x_lhd_agent = R"({"platform":"web","app":"limehd.tv","device_id":")" + device_id + R"("})";

            std::map<std::string, std::string> lime_headers = {
                {"X-LHD-Agent", x_lhd_agent},
                {"Origin", "https://limehd.tv"}
            };
            
            current_ua = "Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0";
            current_ref = "https://limehd.tv/";

            HttpRequestSession api_session;
            api_session.set_user_agent(current_ua);
            api_session.set_referer(current_ref);
            
            std::string response;
            if (!api_session.get(api_url, response, lime_headers)) {
                logger.error("LimeHD: API request failed");
                std::this_thread::sleep_for(std::chrono::seconds(5));
                continue;
            }
            
            // 4. Парсим ответ штатным JsonCpp
            Json::Value root;
            Json::CharReaderBuilder builder;
            std::istringstream iss(response);
            std::string errs;
            
            if (!Json::parseFromStream(builder, iss, &root, &errs)) {
                logger.error("LimeHD: Failed to parse JSON response: " + errs);
                std::this_thread::sleep_for(std::chrono::seconds(5));
                continue;
            }
            
            std::string new_source = "";
            
            // Ищем поток в stream.common
            if (root.isMember("stream") && root["stream"].isObject() && root["stream"].isMember("common")) {
                new_source = root["stream"]["common"].asString();
            } 
            // Фолбэк на корневой url
            else if (root.isMember("url") && root["url"].isString()) {
                new_source = root["url"].asString();
            }

            if (new_source.empty()) {
                logger.error("LimeHD: Stream URL not found in JSON.");
                logger.error("LimeHD: Full Response: " + response); 
                std::this_thread::sleep_for(std::chrono::seconds(5));
                continue;
            }
            
            // 5. Сохраняем заголовки для скачивания HLS
            current_url = new_source;
            playlist_session.set_custom_headers(lime_headers);
            shared_downloader.set_custom_headers(lime_headers);
            
            logger.info("LimeHD: Successfully resolved to HLS URL: " + current_url);
            
        } else {
            // Очищаем заголовки для других провайдеров
            playlist_session.clear_custom_headers();
            shared_downloader.clear_custom_headers();
        }
        // ==========================================

        // ==========================================
        // ВСТАВЛЯЕМ СЮДА НОВУЮ ЛОГИКУ ВЫБОРА РЕЖИМА
        // ==========================================
        
        bool is_hls = true;
        // Простая эвристика: если нет .m3u8, считаем это MPEG-TS потоком
        if (current_url.find(".m3u8") == std::string::npos) {
            is_hls = false;
        }

        if (!is_hls) {
            // === РЕЖИМ 2: DIRECT MPEG-TS ===
            // Этот вызов блокирующий! Он крутится внутри, пока поток жив.
            logger.info("Detected MPEG-TS source. Starting Direct Streamer.");

            // Используем глобальную переменную g_audio_lang
            std::string audio_lang = g_audio_lang;

            if (audio_lang.empty()) {
                logger.info("DirectStream: Audio tracks disabled (video only mode)");
            } else {
                logger.info("DirectStream: Audio language: " + audio_lang);
            }

            // OPTIMIZED: Enable performance optimizations by default
            DirectStreamSegmenter segmenter(save_dir, channel, audio_lang,
                                         true,  // enable_zero_copy - mmap for faster file writes
                                         true); // enable_pipeline - ENABLED for maximum performance
            // Запускаем с полным набором параметров:
            // 1. URL
            // 2. User-Agent
            // 3. Ссылка на SlotManager
            // 4. Имя провайдера
            // 5. Номер слота
            // 6. Token
            // 7. Ссылка на флаг client_has_connected_once (чтобы остановить при потере клиента)
            bool success = segmenter.run(current_url, current_ua,  current_ref, slot_manager, provider, slot, token, client_has_connected_once, allocated_at);
            
            if (!success) {
                logger.error("Direct Streamer failed (stream died). Switching source.");
                source_needs_switch = true; // Триггерим переход на след. источник
//                std::this_thread::sleep_for(std::chrono::seconds(2));
                {
                    std::unique_lock<std::mutex> lock(g_sleep_mutex);
                    g_sleep_cv.wait_for(lock, std::chrono::seconds(2),[]{ return g_hot_switch_requested.load() || !g_keep_running.load(); });
                }
            } else {
                // Если вернулось true, значит g_keep_running = false (выход из программы)
                break;
            }
        
        } else {
            // === РЕЖИМ 1: HLS (Ваш старый код) ===
            // Здесь остается старый вызов check_hls_stream
            auto loop_start = std::chrono::steady_clock::now();            
            
            // Если у нас уже есть прямой Media URL (и он не протух), используем его. 
            // Иначе берем Master URL (index.m3u8).
            std::string url_to_fetch = active_media_url.empty() ? current_url : active_media_url;

if (is_first_run) {
    playlist_session.set_fast_start_timeouts();
}

            auto res = check_hls_stream(url_to_fetch, src.quality, src.bandwidth, archive_dir, channel, 
                                        provider, slot, shared_downloader, playlist_session, source_ip, is_first_run, 
                                        start_write_after_segment, current_ua, &hls_pipeline);

            // Сохраняем Media URL для следующих итераций, чтобы не дергать Master Playlist 
            // и не ломать счетчики сегментов (спасает от фризов на Teletarget)
            if (res.status == "success" && !res.effective_media_url.empty()) {
                active_media_url = res.effective_media_url;
            }

            if (res.status == "error" || res.status == "fatal_broken") {
                 logger.error("HLS Source failed: " + res.message);

                 // Если отвалился закэшированный Media URL (например, протух токен в ссылке lvl4.m3u8)
                 // Мы сбрасываем кэш и на следующем круге попробуем взять свежий из Master URL
                 if (!active_media_url.empty()) {
                     logger.warning("Media URL failed. Falling back to Master URL...");
                     active_media_url = ""; // Сброс кэша
                     {
                         std::unique_lock<std::mutex> lock(g_sleep_mutex);
                         g_sleep_cv.wait_for(lock, std::chrono::milliseconds(500),[]{ return g_hot_switch_requested.load() || !g_keep_running.load(); });
                     }
                     continue;
                 }

                 // Если отвалился сам Master URL (current_url)
                 if (sources.size() <= 1) {
                     logger.error("No fallback sources available. Stopping immediately.");
                     g_keep_running = false;
                     break; // МГНОВЕННАЯ СМЕРТЬ (Защита от зависаний)
                 }

                 // Включаем флаг переключения для следующей итерации
                 source_needs_switch = true;

                 //std::this_thread::sleep_for(std::chrono::milliseconds(500));
                 {
                     std::unique_lock<std::mutex> lock(g_sleep_mutex);
                     g_sleep_cv.wait_for(lock, std::chrono::milliseconds(500),[]{ return g_hot_switch_requested.load() || !g_keep_running.load(); });
                 }
                 continue;
            } 

        // === ВАЖНАЯ ЛОГИКА БЫСТРОГО СТАРТА (СОХРАНЕНА) ===
        int downloaded_count = 0;
        try { downloaded_count = std::stoi(res.details["downloaded_segments"]); } catch(...) {}

        // === МОНИТОРИНГ СКАЧИВАНИЯ СЕГМЕНТОВ ===
        // downloaded_count: -1 = все сегменты уже на диске (нормально, poll быстрее segment_duration)
        //                    0 = были новые сегменты, но скачать не удалось (реальный сбой)
        //                   >0 = успешно скачаны
        if (downloaded_count == -1) {
            // Нет новых сегментов в плейлисте — нормальная ситуация, НЕ считаем сбоем
            // (poll interval ~6s < segment duration ~10-18s)
        } else if (downloaded_count == 0 && res.status == "success") {
            consecutive_failed_downloads++;
            logger.warning("[FAILOVER] Download failed for new segments (" +
                         std::to_string(consecutive_failed_downloads) + "/" +
                         std::to_string(MAX_FAILED_DOWNLOADS) + ")");

            if (consecutive_failed_downloads >= MAX_FAILED_DOWNLOADS) {
                logger.error("[FAILOVER] Failed to download segments for " +
                           std::to_string(MAX_FAILED_DOWNLOADS) + " iterations. Triggering failover.");
                source_needs_switch = true;
                consecutive_failed_downloads = 0;
                continue;
            }
        } else if (downloaded_count > 0) {
            // Успешно скачали сегменты - сбрасываем счетчик
            if (consecutive_failed_downloads > 0) {
                logger.info("[FAILOVER] Segments downloaded successfully, resetting failure counter");
                consecutive_failed_downloads = 0;
            }
        }

        if (is_first_run) {
            if (downloaded_count > 0) {
                // Скачали сегмент -> крутимся сразу, чтобы скачать следующий
                logger.info("Initial buffer backfill complete. Switching to normal mode.");
                is_first_run = false;
                std::this_thread::yield();
                continue;
            } else {
                // Буфер заполнен
                logger.info("Initial buffer backfill complete. Switching to normal mode.");
                if (fs::exists(save_dir + "/playlist.m3u8")) {
                     is_first_run = false;
                }
            }
        }
        
        if (res.status != "error") {
             is_first_run = false;
        }
        // =================================================

        if (!res.active_segments.empty()) {
            last_active_segments = res.active_segments;
        }

        // PIPELINE: Cleanup is now handled by HlsPipelineProcessor's writer thread
        // Submit cleanup task to background thread (non-blocking)
        auto now_cleanup = std::chrono::steady_clock::now();
        if (!last_active_segments.empty() && 
            now_cleanup - last_cleanup_time >= CLEANUP_INTERVAL) {
            hls_pipeline.submit_cleanup(last_active_segments, save_dir);
            last_cleanup_time = now_cleanup;
        }

        // Расчет времени сна

       /* if (!res.details["target_duration"].empty()) {
            try { target_duration = std::stod(res.details["target_duration"]); } catch(...) {}
        }
        
        if (target_duration > 65) target_duration = 6.0;
        if (prev_target >= 5 && prev_target <= 6 && target_duration > 6) target_duration = 6.0;
        prev_target = target_duration;*/
	
	// Обновляем РЕАЛЬНЫЙ target duration для keep-alive таймаута

        if (!res.details["target_duration"].empty()) {
            try { 
                double parsed_td = std::stod(res.details["target_duration"]); 
                if (parsed_td > 0) {
                    current_stream_target_duration = parsed_td; // Для функции check_last_access_file (например 17.0)
                }
            } catch(...) {}
        }

        // Расчет времени сна (sleep_duration)
        double sleep_target_duration = current_stream_target_duration;
        
        // Для СНА (polling) мы не должны спать больше 15 секунд, иначе прокси будет тупить.
        // Но сам таймаут клиента (current_stream_target_duration) при этом не искажается!
        if (sleep_target_duration > 15) sleep_target_duration = 6.0;
        if (prev_target >= 5 && prev_target <= 6 && sleep_target_duration > 6) sleep_target_duration = 6.0;
        prev_target = sleep_target_duration;

        //double sleep_duration = std::max(2.0, sleep_target_duration);
	double min_sleep = is_first_run ? 0.2 : 2.0;   // ← на старте минимум 200мс вместо 2с
	double sleep_duration = std::max(min_sleep, sleep_target_duration);

        //double sleep_duration = std::max(2.0, target_duration);
        auto loop_end = std::chrono::steady_clock::now();
        double elapsed_seconds = std::chrono::duration<double>(loop_end - loop_start).count();
        double actual_sleep = sleep_duration - elapsed_seconds;

        if (actual_sleep < 0) {
            actual_sleep = 0.1;
        }

        //std::this_thread::sleep_for(std::chrono::duration<double>(actual_sleep));
	// "Чуткий сон" - если придёт SWITCH, мы проснёмся за 1 миллисекунду
        {
            std::unique_lock<std::mutex> lock(g_sleep_mutex);
            g_sleep_cv.wait_for(lock, std::chrono::duration<double>(actual_sleep),[]{
                return g_hot_switch_requested.load() || !g_keep_running.load();
            });
        }
	}
}
}
// === CDN Listener Implementation ===
std::string get_local_ip() {
    try {
        // Используем современную команду 'ip' вместо устаревшей 'ifconfig'
        FILE* pipe = popen("/sbin/ip -4 addr show", "r");
        if (!pipe) return "192.168.1.101";

        char buffer[128];
        std::string result;
        while (fgets(buffer, sizeof buffer, pipe) != nullptr) {
            result += buffer;
        }
        pclose(pipe);

        // Ищем inet адрес (не loopback)
        // Формат: inet 192.168.1.100/24 brd 192.168.1.255 scope global eth0
        std::regex pattern(R"(inet\s+(\d+\.\d+\.\d+\.\d+)/\d+)");
        std::smatch match;

        // Ищем все совпадения и берём первый не-loopback адрес
        std::string::const_iterator searchStart(result.cbegin());
        while (std::regex_search(searchStart, result.cend(), match, pattern)) {
            std::string ip = match[1].str();
            if (ip != "127.0.0.1") {
                return ip;
            }
            searchStart = match.suffix().first;
        }
    } catch (const std::exception& e) {
        logger.error("Failed to get IP: " + std::string(e.what()));
    }
    return "192.168.1.101";  // Fallback
}

void daemonize(const std::string& log_file = "/var/log/cdn_listener.log") {
    // Fork the process
    pid_t pid = fork();
    if (pid < 0) {
        std::cerr << "Fork failed" << std::endl;
        exit(1);
    }
    
    // If we're in the parent process, exit
    if (pid > 0) {
        exit(0);
    }
    
    // Create a new session and become the session leader
    if (setsid() < 0) {
        std::cerr << "setsid failed" << std::endl;
        exit(1);
    }
    
    // Fork again to ensure we can never acquire a controlling terminal
    pid = fork();
    if (pid < 0) {
        std::cerr << "Second fork failed" << std::endl;
        exit(1);
    }
    
    // If we're in the parent process, exit
    if (pid > 0) {
        exit(0);
    }
    
    // Change working directory to root
    if (chdir("/") < 0) {
        std::cerr << "chdir failed" << std::endl;
        exit(1);
    }
    
    // Set file permissions mask
    umask(0);
    
    // Open log file before closing file descriptors
    int log_fd = open(log_file.c_str(), O_WRONLY | O_APPEND | O_CREAT, 0644);
    if (log_fd < 0) {
        log_fd = open("/dev/null", O_WRONLY);
    }
    
    // Close all open file descriptors except the log file
    int max_fd = getdtablesize();
    for (int i = 0; i < max_fd; i++) {
        if (i != log_fd) {
            close(i);
        }
    }
    
    // Duplicate log file descriptor to stdout and stderr
    dup2(log_fd, STDOUT_FILENO);
    dup2(log_fd, STDERR_FILENO);
    
    // Close the original log file descriptor if it's not one of the standard ones
    if (log_fd != STDOUT_FILENO && log_fd != STDERR_FILENO) {
        close(log_fd);
    }
    
    // Redirect stdin to /dev/null
    int stdin_fd = open("/dev/null", O_RDONLY);
    if (stdin_fd != STDIN_FILENO) {
        dup2(stdin_fd, STDIN_FILENO);
        close(stdin_fd);
    }
}

class CdnListener {
private:
    static std::string exe_path;
    Logger listener_logger;
    redisContext* redis_ctx;
    RedisReconnect reconn_;
    std::string local_ip;
    std::vector<std::pair<std::string, pid_t>> active_processes;
    std::atomic<bool> shutdown_requested{false}; // Должно быть atomic для сигналов

    // Хелпер для защиты вектора от изменений из обработчика сигналов
    void block_sigchld(bool block) {
        sigset_t set;
        sigemptyset(&set);
        sigaddset(&set, SIGCHLD);
        sigprocmask(block ? SIG_BLOCK : SIG_UNBLOCK, &set, nullptr);
    }

    void launch_proxy(const std::string& channel, const std::string& provider,
                      int slot, std::string source_url,
                      const std::string& quality, const std::string& token,
                      int64_t allocated_at = 0) {

    // Пишем stub playlist ДО fork() — плеер уже идёт на CDN
    std::string save_dir = BASE_DIR + "/" + channel;
    try {
        fs::create_directories(save_dir);
        std::string playlist_path = save_dir + "/playlist.m3u8";
        
        // Пишем только если нет реального плейлиста
        // (канал мог быть активен, не хотим затирать живой поток)
    } catch (const std::exception& e) {
        listener_logger.error("[STUB] Failed to write stub: " + std::string(e.what()));
    }
        
        // Логика Peers.TV
        if (provider == "direct_url" && source_url.find("api.peers.tv") != std::string::npos) {
            logger.info("Detected api.peers.tv URL — applying token replacement");
            source_url = replace_peers_token_in_url(source_url);
            if (source_url.find("${p_token}") != std::string::npos) {
                listener_logger.error("Failed to replace ${p_token} in URL for channel " + channel);
                return;
            }
        }

        // Логика Sputnik24
        /*if (provider == "direct_url" && source_url.find("api1.sputnik24.tv") != std::string::npos) {
            logger.info("Detected api1.sputnik24.tv — fetching valid source");
            std::string channel_id;
            std::smatch m;
            // id_regex и fallback_regex должны быть видны здесь (они глобальные static)
            if (std::regex_search(source_url, m, id_regex) && m.size() > 1) {
                channel_id = m[1].str();
            } else if (std::regex_search(source_url, m, fallback_regex)) {
                channel_id = m[1].str();
            }

            if (channel_id.empty()) {
                listener_logger.error("Sputnik24: cannot extract channel_id");
                return;
            }
            
            std::string valid_url = get_valid_sputnik_source(channel_id);
            if (valid_url.empty()) {
                listener_logger.error("Sputnik24: failed to get valid source");
                return;
            }
            source_url = valid_url;
        }*/

        // Блокируем сигналы перед fork и добавлением в вектор, 
        // чтобы sigchld не сработал посередине добавления
        block_sigchld(true);

        pid_t pid = fork();
        if (pid == 0) {
            // Дочерний процесс
            // Восстанавливаем маску сигналов для ребенка
            sigset_t set;
            sigemptyset(&set);
            sigprocmask(SIG_SETMASK, &set, nullptr);

            int env_count = 0;
            while (environ[env_count]) env_count++;
            char** env_copy = new char*[env_count + 1];
            for (int i = 0; i < env_count; ++i) env_copy[i] = strdup(environ[i]);
            env_copy[env_count] = nullptr;

            std::string slot_str = std::to_string(slot);
            std::string allocated_at_str = std::to_string(allocated_at);
            const char* argv[] = {
                exe_path.c_str(), channel.c_str(), provider.c_str(),
                slot_str.c_str(), source_url.c_str(), quality.c_str(),
                token.c_str(), "0", allocated_at_str.c_str(), nullptr
            };

            execve(exe_path.c_str(), const_cast<char* const*>(argv), env_copy);
            perror("execve failed");
            _exit(127);
        }
        else if (pid > 0) {
            active_processes.emplace_back(channel, pid);
            listener_logger.info("Launched proxy | channel=" + channel + " | PID=" + std::to_string(pid));
        }
        else {
            listener_logger.error("Fork failed for channel " + channel);
        }

        block_sigchld(false); // Разблокируем сигналы
    }

public:
    CdnListener()
        : listener_logger("/var/log/cdn_listener.log")
        , redis_ctx(nullptr)
        , reconn_(&redis_ctx, [this]() { return this->connect_to_redis(); }, listener_logger, "[CdnListener] ")
    {
        local_ip = get_local_ip();
        init_exe_path();

        g_active_processes = &active_processes;
        g_listener_logger = &listener_logger;

        struct sigaction sa;
        sa.sa_handler = sigchld_handler;
        sigemptyset(&sa.sa_mask);
        sa.sa_flags = SA_RESTART | SA_NOCLDSTOP;
        sigaction(SIGCHLD, &sa, nullptr);
    }

    ~CdnListener() {
        if (redis_ctx) redisFree(redis_ctx);
    }

    static void init_exe_path() {
        if (exe_path.empty()) {
            char path[PATH_MAX];
            ssize_t len = readlink("/proc/self/exe", path, sizeof(path)-1);
            if (len != -1) {
                path[len] = '\0';
                exe_path = path;
            } else {
                exe_path = "/usr/local/bin/hls_proxy";
            }
            logger.info("Binary path cached: " + exe_path);
        }
    }

	bool connect_to_redis() {
        struct timeval timeout = { 1, 0 };
        // Таймаут здесь работает ТОЛЬКО на этап установки соединения
        redis_ctx = redisConnectWithTimeout("45.9.73.98", 6379, timeout);
        if (!redis_ctx || redis_ctx->err) {
            listener_logger.error("Redis connection failed");
            if (redis_ctx) { redisFree(redis_ctx); redis_ctx = nullptr; }
            return false;
        }
        
        // ВАЖНО: Удаляем redisSetTimeout! 
        // Вместо него включаем TCP Keep-Alive для отслеживания мертвых соединений
        redisEnableKeepAlive(redis_ctx);

        redisReply* auth = (redisReply*)redisCommand(redis_ctx, "AUTH %s", "qw34rfvgtU9snaWE");
        if (!auth || auth->type == REDIS_REPLY_ERROR) {
            if (auth) freeReplyObject(auth);
            redisFree(redis_ctx); redis_ctx = nullptr;
            return false;
        }
        freeReplyObject(auth);
        listener_logger.info("Connected to Redis");
        return true;
    }

    void cleanup() {
        // Здесь сигналы уже не страшны, так как мы выходим
        listener_logger.info("Cleanup: killing " + std::to_string(active_processes.size()) + " proxies");
        for (const auto& p : active_processes) {
            kill(p.second, SIGTERM);
        }
        // Небольшая пауза, чтобы дать процессам завершиться
        std::this_thread::sleep_for(std::chrono::milliseconds(500));
        
        if (redis_ctx) {
            redisFree(redis_ctx);
            redis_ctx = nullptr;
        }
    }

int run(bool no_daemon = false) {
        if (!no_daemon) {
            listener_logger.info("Starting as daemon");
            daemonize("/var/log/cdn_listener.log");
        }

        listener_logger.info("CDN listener started on " + local_ip);

        if (!connect_to_redis()) return 1;

        // Отправляем SUBSCRIBE - Redis вернёт подтверждение на КАЖДЫЙ канал
        void* reply_raw = redisCommand(redis_ctx, "SUBSCRIBE channel_starts channel_control");
        if (!reply_raw) {
            listener_logger.error("SUBSCRIBE command failed");
            redisFree(redis_ctx);
            return 1;
        }
        redisReply* sub_reply = (redisReply*)reply_raw;
        listener_logger.info("SUBSCRIBE sent, first confirmation received");
        freeReplyObject(sub_reply);

        // Читаем второе подтверждение (на channel_control)
        void* confirm2_raw = nullptr;
        if (redisGetReply(redis_ctx, &confirm2_raw) == REDIS_OK && confirm2_raw) {
            listener_logger.info("Second channel confirmation received");
            freeReplyObject((redisReply*)confirm2_raw);
        }

        listener_logger.info("Both channels subscribed, ready for messages");

        static std::atomic<bool>* p_shutdown = &shutdown_requested;
        auto exit_handler =[](int) { 
            if (p_shutdown) *p_shutdown = true; 
        };
        
        signal(SIGTERM, exit_handler);
        signal(SIGINT, exit_handler);
        signal(SIGHUP, SIG_IGN);

        listener_logger.info("[DEBUG] Entering main loop, waiting for messages...");

while (!shutdown_requested) {
            // Ожидаем данные в сокете с таймаутом 1 секунда
            struct pollfd pfd;
            pfd.fd = redis_ctx->fd;
            pfd.events = POLLIN;

            int p = poll(&pfd, 1, 1000); // 1000 мс = 1 секунда
            
            if (p == 0) {
                // Таймаут. Данных нет. Просто продолжаем цикл для проверки shutdown_requested
                continue;
            } else if (p < 0) {
                if (errno == EINTR) continue; // Прервано сигналом завершения
                listener_logger.error("poll() error: " + std::string(strerror(errno)));
                break;
            }

            // Если poll() дождался данных, hiredis прочитает их без блокировок и ошибок таймаута
            redisReply* reply = nullptr;
            int status = redisGetReply(redis_ctx, (void**)&reply);

            // 1. Проверка на обрыв соединения
            if (status == REDIS_ERR || !reply) {
                listener_logger.error("Redis disconnected! Error: " + std::string(redis_ctx ? redis_ctx->errstr : "unknown"));

                if (reply) freeReplyObject(reply);
                redisFree(redis_ctx);
                redis_ctx = nullptr;
                reconn_.on_disconnected();

                // Переподключение каждые 2 секунды без backoff
                while (!shutdown_requested.load()) {
                    std::this_thread::sleep_for(std::chrono::seconds(2));
                    listener_logger.info("Attempting Redis reconnect for channel_starts/channel_control...");
                    if (connect_to_redis()) {
                        reconn_.on_reconnected();
                        redisReply* sub2 = (redisReply*)redisCommand(redis_ctx, "SUBSCRIBE channel_starts channel_control");
                        if (sub2) {
                            freeReplyObject(sub2);
                            // Читаем второе подтверждение подписки (channel_control)
                            void* confirm2_raw = nullptr;
                            if (redisGetReply(redis_ctx, &confirm2_raw) == REDIS_OK && confirm2_raw) {
                                freeReplyObject((redisReply*)confirm2_raw);
                            }
                            listener_logger.info("Successfully re-subscribed to channel_starts and channel_control");
                            break;
                        } else {
                            listener_logger.error("Re-subscribe failed, retrying in 2s...");
                            redisFree(redis_ctx);
                            redis_ctx = nullptr;
                        }
                    }
                }
                if (shutdown_requested.load()) break;
                continue;
            }

            // 2. Обработка сообщения
            if (reply->type == REDIS_REPLY_ARRAY && reply->elements >= 3) {
                
                // Строгая проверка типов для предотвращения падений
                if (reply->element[0]->type == REDIS_REPLY_STRING &&
                    reply->element[1]->type == REDIS_REPLY_STRING &&
                    reply->element[2]->type == REDIS_REPLY_STRING) {
                    
                    std::string type(reply->element[0]->str);
                    std::string chan(reply->element[1]->str);
                    std::string data(reply->element[2]->str);

                    // Игнорируем технические сообщения типа "subscribe", реагируем только на "message"
                    if (type == "message") {
                        listener_logger.info("[DEBUG] Received: type=" + type + " chan=" + chan + " data_len=" + std::to_string(data.length()));

                        // === HOT SWITCH: обновление active_processes при SWITCH ===
                        if (chan == "channel_control") {
                            Json::Value root;
                            Json::CharReaderBuilder builder;
                            std::string errs;
                            std::istringstream iss(data);
                            if (Json::parseFromStream(builder, iss, &root, &errs)) {
                                std::string action = root["action"].asString();
                                if (action == "SWITCH") {
                                    std::string old_channel = root["channel"].asString();
                                    std::string new_channel = root["new_channel"].asString();
                                    if (!old_channel.empty() && !new_channel.empty()) {
                                        block_sigchld(true);
                                        auto it = std::find_if(active_processes.begin(), active_processes.end(),[&old_channel](const auto& p) { return p.first == old_channel; });
                                        if (it != active_processes.end()) {
                                            listener_logger.info("[FAST_ZAP] active_processes updated: " + old_channel + " → " + new_channel + " (PID " + std::to_string(it->second) + ")");
                                            it->first = new_channel;
                                        }
                                        block_sigchld(false);
                                    }
                                }
                            }
                        }
                        
                        // === ЗАПУСК НОВОГО ПРОКСИ ===
                        else if (chan == "channel_starts") {
                            listener_logger.info("[DEBUG] Processing channel_starts message");
                            Json::Value root;
                            Json::CharReaderBuilder builder;
                            std::string errs;
                            std::istringstream iss(data);

                            if (Json::parseFromStream(builder, iss, &root, &errs)) {
                                std::string cdn_ip = root["cdn_ip"].asString();
                                listener_logger.info("[DEBUG] Parsed: cdn_ip=" + cdn_ip + " local_ip=" + local_ip);
                                if (cdn_ip != local_ip) {
                                    listener_logger.warning("[DEBUG] IP mismatch, ignoring message");
                                    freeReplyObject(reply);
                                    continue;
                                }

                                // Получаем базовые параметры
                                std::string channel = root["channel"].asString();
                                std::string provider = root["provider"].asString();
                                int slot = root["slot"].asInt();
                                std::string url = root["source_url"].asString();
                                std::string quality = root["quality"].asString();
                                std::string token = root["token"].asString();
                                int64_t allocated_at = root.get("allocated_at", 0).asInt64();

                                listener_logger.info("[DEBUG] Extracted: channel=" + channel + " provider=" + provider +
                                                   " slot=" + std::to_string(slot) + " url=" + url);
                                
                                Json::Value combined_sources(Json::arrayValue);

                                Json::Value main_source;
                                main_source["url"] = root["source_url"]; 
                                
                                if (root.isMember("user_agent")) {
                                    main_source["agent"] = root["user_agent"];
                                }

                                if (root.isMember("referer")) {
                                    main_source["referer"] = root["referer"];
                                }

                                if (root.isMember("quality")) {
                                    main_source["quality"] = root["quality"];
                                }
                                if (root.isMember("bandwidth")) {
                                    main_source["bandwidth"] = root["bandwidth"];
                                }
                                main_source["type"] = "main"; 
                                combined_sources.append(main_source);

                                if (root.isMember("sources") && root["sources"].isArray()) {
                                    for (auto backup : root["sources"]) { 
                                        backup["type"] = "backup"; 
                                        combined_sources.append(backup);
                                    }
                                }

                                Json::StreamWriterBuilder w;
                                w["indentation"] = ""; 
                                std::string sources_arg = Json::writeString(w, combined_sources);

                                if (channel.empty() || provider.empty() || url.empty()) {
                                    freeReplyObject(reply);
                                    continue;
                                }

                                // Защита вектора от гонки данных
                                block_sigchld(true);
                                
                                auto it = std::find_if(active_processes.begin(), active_processes.end(),[&channel](const auto& p) { return p.first == channel; });

                                bool already_running = (it != active_processes.end());
                                
                                if (already_running) {
                                    listener_logger.info("Channel " + channel + " is running (PID " + std::to_string(it->second) + "). Updating URL/slot/token.");
                                    
                                    // Пишем JSON чтобы процесс мог обновить slot и token, а не только url
                                    std::string update_file = "/tmp/update_" + channel + ".url";
                                    try {
                                        Json::Value upd;
                                        upd["url"]   = url;
                                        upd["slot"]  = slot;
                                        upd["token"] = token;
                                        Json::StreamWriterBuilder wb;
                                        wb["indentation"] = "";
                                        std::ofstream ofs(update_file);
                                        if (ofs.is_open()) {
                                            ofs << Json::writeString(wb, upd);
                                            ofs.close();
                                            listener_logger.info("Update file written: slot=" + std::to_string(slot) + " token=" + token);
                                        } else {
                                            listener_logger.error("Failed to write update file");
                                        }
                                    } catch (const std::exception& e) {
                                        listener_logger.error("Failed to serialize update file: " + std::string(e.what()));
                                    }
                                }
                                
                                block_sigchld(false); // Разблокируем сигналы

                                if (already_running) {
                                    freeReplyObject(reply);
                                    continue;
                                }

                                if (provider != "direct_url" && token.empty()) {
                                    listener_logger.error("Token required for " + provider);
                                    freeReplyObject(reply);
                                    continue;
                                }
                                launch_proxy(channel, provider, slot, sources_arg, quality, token, allocated_at);

                            } else {
                                listener_logger.error("JSON parse error: " + errs);
                            }
                        }
                    }
                }
            }
            if (reply) freeReplyObject(reply);
        }

        cleanup();
        return 0;
    }
};

std::string CdnListener::exe_path;

// Async-signal-safe crash handler (не использует std::string/malloc)
void crash_handler(int signal) {
    if (signal == SIGSEGV) {
        static const char msg[] = "\n[CRITICAL] Process crashed with signal: SIGSEGV\n";
        write(STDERR_FILENO, msg, sizeof(msg) - 1);
    } else if (signal == SIGABRT) {
        static const char msg[] = "\n[CRITICAL] Process crashed with signal: SIGABRT\n";
        write(STDERR_FILENO, msg, sizeof(msg) - 1);
    } else {
        static const char msg[] = "\n[CRITICAL] Process crashed with unknown signal\n";
        write(STDERR_FILENO, msg, sizeof(msg) - 1);
    }
    _exit(128 + signal);
}

// === CLI ===

// === ПАРСИНГ АРГУМЕНТОВ КОМАНДНОЙ СТРОКИ ===
void parse_optimization_args(int argc, char* argv[]) {
    for (int i = 1; i < argc; i++) {
        std::string arg = argv[i];

        if (arg == "--playlist-mode" && i + 1 < argc) {
            std::string mode = argv[++i];
            if (mode == "immediate") {
                g_playlist_write_mode = PlaylistWriteMode::IMMEDIATE;
                logger.info("Playlist mode: IMMEDIATE (fastest start)");
            } else if (mode == "after-first") {
                g_playlist_write_mode = PlaylistWriteMode::AFTER_FIRST;
                logger.info("Playlist mode: AFTER_FIRST");
            } else if (mode == "after-n") {
                g_playlist_write_mode = PlaylistWriteMode::AFTER_N_SEGMENTS;
                logger.info("Playlist mode: AFTER_N_SEGMENTS");
            } else if (mode == "validated") {
                g_playlist_write_mode = PlaylistWriteMode::VALIDATED;
                logger.info("Playlist mode: VALIDATED (safest)");
            }
        }

        if (arg == "--min-segments" && i + 1 < argc) {
            g_min_segments_before_write = std::stoi(argv[++i]);
            logger.info("Min segments before write: " + std::to_string(g_min_segments_before_write));
        }

        if (arg == "--log-level" && i + 1 < argc) {
            std::string level = argv[++i];
            if (level == "debug") {
                g_log_level = LogLevel::DEBUG;
                logger.set_level(LogLevel::DEBUG);
                logger.info("Log level: DEBUG (verbose)");
            } else if (level == "info") {
                g_log_level = LogLevel::INFO;
                logger.set_level(LogLevel::INFO);
                logger.info("Log level: INFO (default)");
            } else if (level == "stats") {
                g_log_level = LogLevel::STATS;
                logger.set_level(LogLevel::STATS);
                logger.info("Log level: STATS (with performance metrics)");
            } else if (level == "warning") {
                g_log_level = LogLevel::WARNING;
                logger.set_level(LogLevel::WARNING);
                logger.info("Log level: WARNING (warnings and errors only)");
            } else if (level == "error") {
                g_log_level = LogLevel::ERROR;
                logger.set_level(LogLevel::ERROR);
                logger.error("Log level: ERROR (errors only)");
            }
        }

        if (arg == "--audio-lang" && i + 1 < argc) {
            g_audio_lang = argv[++i];
            if (g_audio_lang == "none" || g_audio_lang == "disabled") {
                g_audio_lang = "";
                logger.info("Audio: DISABLED (video only)");
            } else {
                logger.info("Audio language: " + g_audio_lang);
            }
        }
    }
}

int main(int argc, char* argv[]) {
    parse_optimization_args(argc, argv);
    // 1. Установка перехватчика падений
    signal(SIGPIPE, SIG_IGN); 
    signal(SIGSEGV, crash_handler);
    signal(SIGABRT, crash_handler);

    // 2. Глобальная инициализация CURL (Обязательно для многопоточности/SSL)
    curl_global_init(CURL_GLOBAL_ALL);

    CdnListener::init_exe_path();  // Один раз

    // === Прямой запуск прокси ===
    if (argc >= 7 && std::string(argv[1]) != "--no-daemon") {
        std::string channel = argv[1], provider = argv[2], source_url = argv[4], quality = argv[5], token = argv[6];
        int slot = std::stoi(argv[3]), start_after = (argc >= 8) ? std::stoi(argv[7]) : 1;
        int64_t allocated_at = (argc >= 9) ? std::stoll(argv[8]) : 0;

        std::string log_file = "/var/log/cgi/hls_proxy_" + channel + ".log";
        freopen(log_file.c_str(), "a", stdout);
        freopen(log_file.c_str(), "a", stderr);
        setvbuf(stdout, nullptr, _IOLBF, 1024);
        setvbuf(stderr, nullptr, _IOLBF, 1024);

        monitor_hls_stream(source_url, quality, "/hls_archive", channel, provider, slot, token, start_after, allocated_at);
        return 0;
    }

    bool no_daemon = false;
    for (int i = 1; i < argc; ++i)
        if (std::string(argv[i]) == "--no-daemon") no_daemon = true;

    CdnListener listener;
    int ret=listener.run(no_daemon);

  curl_global_cleanup();
    return ret;
}
