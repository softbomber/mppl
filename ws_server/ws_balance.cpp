/*
 * WebSocket balance push server.
 *
 * Accepts WS connections on a configurable port (default 9800).
 * Each client sends a JSON auth message: {"dealer_id": <int>}
 * The server polls MySQL every POLL_SEC seconds and pushes
 * {"s":"123.45","i":5} to each connected dealer whose balance
 * or discount rate changed since the last push.
 *
 * Build:
 *   g++ -std=c++17 -O2 -o ws_balance ws_balance.cpp \
 *       -lmysqlclient -lpthread -lssl -lcrypto
 *
 * Run:
 *   ./ws_balance      # reads /var/www/.env, daemonizes, logs to /var/log/ws_balance.log
 *
 * Settings (read from /var/www/.env, env vars override):
 *   WS_PORT   – listen port          (default 9800)
 *   DB_HOST   – MySQL host           (default 127.0.0.1)
 *   DB_USER   – MySQL user           (default root)
 *   DB_PASS   – MySQL password       (default "")
 *   DB_NAME   – MySQL database       (default mpol)
 *   POLL_SEC  – DB poll interval sec (default 5)
 */

#include <arpa/inet.h>
#include <netinet/in.h>
#include <sys/socket.h>
#include <sys/epoll.h>
#include <unistd.h>
#include <fcntl.h>
#include <signal.h>
#include <openssl/sha.h>
#include <mysql/mysql.h>

#include <cstdio>
#include <cstdlib>
#include <cstring>
#include <ctime>
#include <cerrno>
#include <string>
#include <unordered_map>
#include <vector>
#include <algorithm>
#include <sstream>
#include <mutex>
#include <thread>
#include <atomic>
#include <functional>

/* ───────── base64 encoder (RFC 4648) ───────── */

static const char b64[] =
    "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/";

static std::string base64_encode(const unsigned char *in, size_t len) {
    std::string out;
    out.reserve(((len + 2) / 3) * 4);
    for (size_t i = 0; i < len; i += 3) {
        unsigned n = (unsigned)in[i] << 16;
        if (i + 1 < len) n |= (unsigned)in[i + 1] << 8;
        if (i + 2 < len) n |= in[i + 2];
        out += b64[(n >> 18) & 0x3F];
        out += b64[(n >> 12) & 0x3F];
        out += (i + 1 < len) ? b64[(n >> 6) & 0x3F] : '=';
        out += (i + 2 < len) ? b64[n & 0x3F] : '=';
    }
    return out;
}

/* ───────── helpers ───────── */

static std::unordered_map<std::string, std::string> g_env_file;

static void load_env_file(const char *path) {
    FILE *f = fopen(path, "r");
    if (!f) return;
    char line[4096];
    while (fgets(line, sizeof(line), f)) {
        // skip comments and empty lines
        char *p = line;
        while (*p == ' ' || *p == '\t') ++p;
        if (*p == '#' || *p == '\n' || *p == '\0') continue;
        char *eq = strchr(p, '=');
        if (!eq) continue;
        std::string key(p, eq - p);
        // trim key
        while (!key.empty() && (key.back() == ' ' || key.back() == '\t'))
            key.pop_back();
        char *val = eq + 1;
        // trim leading spaces/quotes from value
        while (*val == ' ' || *val == '\t') ++val;
        std::string value(val);
        // trim trailing newline/spaces/quotes
        while (!value.empty() && (value.back() == '\n' || value.back() == '\r'
               || value.back() == ' ' || value.back() == '\t'))
            value.pop_back();
        // strip surrounding quotes
        if (value.size() >= 2 &&
            ((value.front() == '"' && value.back() == '"') ||
             (value.front() == '\'' && value.back() == '\''))) {
            value = value.substr(1, value.size() - 2);
        }
        if (!key.empty())
            g_env_file[key] = value;
    }
    fclose(f);
}

static std::string env(const char *key, const char *def) {
    // 1) real environment variable takes priority
    const char *v = std::getenv(key);
    if (v && v[0]) return v;
    // 2) value from .env file
    auto it = g_env_file.find(key);
    if (it != g_env_file.end() && !it->second.empty()) return it->second;
    // 3) default
    return def;
}

static void set_nonblock(int fd) {
    fcntl(fd, F_SETFL, fcntl(fd, F_GETFL) | O_NONBLOCK);
}

static void send_bytes(int fd, const void *data, size_t len) {
    ssize_t r = write(fd, data, len);
    (void)r;
}

/* ───────── WebSocket frame helpers ───────── */

// Build a WS text frame (server→client, no mask)
static std::string ws_frame(const std::string &payload) {
    std::string f;
    f += (char)0x81; // FIN + text opcode
    size_t len = payload.size();
    if (len <= 125) {
        f += (char)len;
    } else if (len <= 65535) {
        f += (char)126;
        f += (char)((len >> 8) & 0xFF);
        f += (char)(len & 0xFF);
    } else {
        f += (char)127;
        for (int i = 7; i >= 0; --i)
            f += (char)((len >> (8 * i)) & 0xFF);
    }
    f += payload;
    return f;
}

// Build a WS close frame
static std::string ws_close_frame(uint16_t code = 1000) {
    std::string f;
    f += (char)0x88; // FIN + close
    f += (char)2;
    f += (char)((code >> 8) & 0xFF);
    f += (char)(code & 0xFF);
    return f;
}

/* ───────── per-client state ───────── */

struct Client {
    int fd = -1;
    bool handshake_done = false;
    int dealer_id = 0;
    std::string last_balance;
    int last_intrst = -1;
    std::string recv_buf;
};

/* ───────── globals ───────── */

static std::atomic<bool> g_running{true};
static std::mutex g_mtx;
static std::unordered_map<int, Client> g_clients; // fd → Client
static int g_epoll_fd = -1;

/* ───────── WebSocket handshake ───────── */

static bool do_handshake(Client &c) {
    auto &buf = c.recv_buf;
    auto hdr_end = buf.find("\r\n\r\n");
    if (hdr_end == std::string::npos) return false; // incomplete

    // Extract Sec-WebSocket-Key
    std::string key;
    {
        auto pos = buf.find("Sec-WebSocket-Key:");
        if (pos == std::string::npos) pos = buf.find("sec-websocket-key:");
        if (pos == std::string::npos) {
            // bad request
            const char *resp = "HTTP/1.1 400 Bad Request\r\nConnection: close\r\n\r\n";
            send_bytes(c.fd, resp, strlen(resp));
            return false;
        }
        auto eol = buf.find("\r\n", pos);
        key = buf.substr(pos + 19, eol - pos - 19);
        // trim
        while (!key.empty() && key.front() == ' ') key.erase(key.begin());
        while (!key.empty() && key.back() == ' ') key.pop_back();
    }

    // SHA-1 of key + magic
    std::string accept_src = key + "258EAFA5-E914-47DA-95CA-C5AB0DC85B11";
    unsigned char sha1[20];
    SHA1(reinterpret_cast<const unsigned char *>(accept_src.data()),
         accept_src.size(), sha1);
    std::string accept = base64_encode(sha1, 20);

    std::string resp = "HTTP/1.1 101 Switching Protocols\r\n"
                       "Upgrade: websocket\r\n"
                       "Connection: Upgrade\r\n"
                       "Sec-WebSocket-Accept: " + accept + "\r\n"
                       "\r\n";
    send_bytes(c.fd, resp.data(), resp.size());

    buf.erase(0, hdr_end + 4);
    c.handshake_done = true;
    return true;
}

/* ───────── read a WS frame from buffer ───────── */

// Returns payload string, or empty if frame incomplete.
// Erases consumed bytes from buf. Sets `opcode`.
static std::string read_frame(std::string &buf, int &opcode) {
    opcode = -1;
    if (buf.size() < 2) return {};
    auto *b = reinterpret_cast<const unsigned char *>(buf.data());
    opcode = b[0] & 0x0F;
    bool masked = (b[1] & 0x80) != 0;
    uint64_t plen = b[1] & 0x7F;
    size_t hdr = 2;
    if (plen == 126) {
        if (buf.size() < 4) { opcode = -1; return {}; }
        plen = ((uint64_t)b[2] << 8) | b[3];
        hdr = 4;
    } else if (plen == 127) {
        if (buf.size() < 10) { opcode = -1; return {}; }
        plen = 0;
        for (int i = 0; i < 8; ++i) plen = (plen << 8) | b[2 + i];
        hdr = 10;
    }
    size_t mask_len = masked ? 4 : 0;
    size_t total = hdr + mask_len + plen;
    if (buf.size() < total) { opcode = -1; return {}; }

    const unsigned char *mask_key = b + hdr;
    const unsigned char *payload = mask_key + mask_len;
    std::string out(plen, '\0');
    for (uint64_t i = 0; i < plen; ++i)
        out[i] = payload[i] ^ (masked ? mask_key[i % 4] : 0);
    buf.erase(0, total);
    return out;
}

/* ───────── client data handler ───────── */

static void on_client_data(Client &c) {
    while (true) {
        int opcode;
        std::string payload = read_frame(c.recv_buf, opcode);
        if (opcode == -1) break; // incomplete

        if (opcode == 0x8) {
            // close
            auto fr = ws_close_frame();
            send_bytes(c.fd, fr.data(), fr.size());
            return;
        }
        if (opcode == 0x9) {
            // ping → pong
            std::string pong;
            pong += (char)0x8A;
            pong += (char)payload.size();
            pong += payload;
            send_bytes(c.fd, pong.data(), pong.size());
            continue;
        }
        if (opcode == 0x1) {
            // text — expect {"dealer_id":123}
            auto pos = payload.find("dealer_id");
            if (pos != std::string::npos) {
                // extract number after the key
                auto colon = payload.find(':', pos);
                if (colon != std::string::npos) {
                    int did = 0;
                    for (size_t i = colon + 1; i < payload.size(); ++i) {
                        if (payload[i] >= '0' && payload[i] <= '9')
                            did = did * 10 + (payload[i] - '0');
                        else if (did > 0) break;
                    }
                    if (did > 0) {
                        c.dealer_id = did;
                    }
                }
            }
        }
    }
}

/* ───────── remove client ───────── */

static void remove_client(int fd) {
    epoll_ctl(g_epoll_fd, EPOLL_CTL_DEL, fd, nullptr);
    close(fd);
    std::lock_guard<std::mutex> lk(g_mtx);
    g_clients.erase(fd);
}

/* ───────── MySQL poller thread ───────── */

struct DealerRow {
    int id;
    double sum;
    int active_cnt;
};

static int getInterestRate(int active) {
    if (active <= 5) return 0;
    if (active <= 10) return 2;
    if (active <= 20) return 3;
    if (active <= 50) return 5;
    if (active <= 100) return 7;
    if (active <= 200) return 10;
    if (active <= 400) return 13;
    return 15;
}

static void poller_thread(const std::string &db_host,
                          const std::string &db_user,
                          const std::string &db_pass,
                          const std::string &db_name,
                          int poll_sec) {
    MYSQL *conn = nullptr;

    auto db_connect = [&]() -> bool {
        if (conn) { mysql_close(conn); conn = nullptr; }
        conn = mysql_init(nullptr);
        if (!conn) return false;
        unsigned int timeout = 5;
        mysql_options(conn, MYSQL_OPT_CONNECT_TIMEOUT, &timeout);
        bool reconnect_flag = true;
        mysql_options(conn, MYSQL_OPT_RECONNECT, &reconnect_flag);
        if (!mysql_real_connect(conn, db_host.c_str(), db_user.c_str(),
                                db_pass.c_str(), db_name.c_str(), 0,
                                nullptr, 0)) {
            fprintf(stderr, "[ws] MySQL connect failed: %s\n", mysql_error(conn));
            mysql_close(conn); conn = nullptr;
            return false;
        }
        mysql_set_character_set(conn, "utf8mb4");
        return true;
    };

    if (!db_connect()) {
        fprintf(stderr, "[ws] Initial DB connection failed, poller retrying...\n");
    }

    while (g_running.load()) {
        sleep(poll_sec);
        if (!g_running.load()) break;

        // Collect dealer IDs we care about
        std::vector<int> dealer_ids;
        {
            std::lock_guard<std::mutex> lk(g_mtx);
            for (auto &[fd, c] : g_clients)
                if (c.dealer_id > 0) dealer_ids.push_back(c.dealer_id);
        }
        if (dealer_ids.empty()) continue;

        // Reconnect if needed
        if (!conn || mysql_ping(conn) != 0) {
            if (!db_connect()) continue;
        }

        // Deduplicate
        std::sort(dealer_ids.begin(), dealer_ids.end());
        dealer_ids.erase(std::unique(dealer_ids.begin(), dealer_ids.end()),
                         dealer_ids.end());

        // Build query
        std::string ids_str;
        for (size_t i = 0; i < dealer_ids.size(); ++i) {
            if (i) ids_str += ',';
            ids_str += std::to_string(dealer_ids[i]);
        }

        // Get balances
        std::string q1 = "SELECT id, sum FROM dealers WHERE id IN (" + ids_str + ")";
        std::unordered_map<int, double> balances;
        if (mysql_query(conn, q1.c_str()) == 0) {
            MYSQL_RES *res = mysql_store_result(conn);
            if (res) {
                MYSQL_ROW row;
                while ((row = mysql_fetch_row(res))) {
                    int did = std::atoi(row[0]);
                    double s = std::atof(row[1]);
                    balances[did] = s;
                }
                mysql_free_result(res);
            }
        }

        // Get active account counts for discount
        std::string q2 =
            "SELECT accounts.dealer, COUNT(*) AS cnt "
            "FROM pdates JOIN accounts ON pdates.user_id = accounts.id "
            "WHERE accounts.dealer IN (" + ids_str + ") AND pdates.dend >= NOW() "
            "GROUP BY accounts.dealer";
        std::unordered_map<int, int> active_counts;
        if (mysql_query(conn, q2.c_str()) == 0) {
            MYSQL_RES *res = mysql_store_result(conn);
            if (res) {
                MYSQL_ROW row;
                while ((row = mysql_fetch_row(res))) {
                    int did = std::atoi(row[0]);
                    int cnt = std::atoi(row[1]);
                    active_counts[did] = cnt;
                }
                mysql_free_result(res);
            }
        }

        // Push to clients
        std::lock_guard<std::mutex> lk(g_mtx);
        for (auto &[fd, c] : g_clients) {
            if (c.dealer_id <= 0 || !c.handshake_done) continue;

            auto bit = balances.find(c.dealer_id);
            if (bit == balances.end()) continue;

            char sum_buf[32];
            snprintf(sum_buf, sizeof(sum_buf), "%.2f", bit->second);
            std::string sum_str(sum_buf);

            int intrst = getInterestRate(active_counts[c.dealer_id]);

            if (sum_str == c.last_balance && intrst == c.last_intrst) continue;

            c.last_balance = sum_str;
            c.last_intrst = intrst;

            // Build JSON
            std::string json = "{\"s\":\"" + sum_str + "\",\"i\":" +
                               std::to_string(intrst) + "}";
            auto frame = ws_frame(json);
            send_bytes(fd, frame.data(), frame.size());
        }
    }

    if (conn) mysql_close(conn);
}

/* ───────── daemonize ───────── */

static const char *LOG_PATH = "/var/log/ws_balance.log";
static const char *PID_PATH = "/var/run/ws_balance.pid";

static void daemonize() {
    pid_t pid = fork();
    if (pid < 0) { perror("[ws] fork"); exit(1); }
    if (pid > 0) {
        // parent — write child PID and exit
        FILE *pf = fopen(PID_PATH, "w");
        if (pf) { fprintf(pf, "%d\n", pid); fclose(pf); }
        _exit(0);
    }

    // child — new session
    if (setsid() < 0) { perror("[ws] setsid"); exit(1); }

    // redirect stdout/stderr to log file
    int logfd = open(LOG_PATH, O_WRONLY | O_CREAT | O_APPEND, 0644);
    if (logfd >= 0) {
        dup2(logfd, STDOUT_FILENO);
        dup2(logfd, STDERR_FILENO);
        close(logfd);
    }

    // close stdin
    int devnull = open("/dev/null", O_RDONLY);
    if (devnull >= 0) { dup2(devnull, STDIN_FILENO); close(devnull); }
}

/* ───────── main ───────── */

int main() {
    signal(SIGPIPE, SIG_IGN);

    auto handle_signal = [](int) { g_running.store(false); };
    signal(SIGINT, handle_signal);
    signal(SIGTERM, handle_signal);

    // Load .env from /var/www/.env
    load_env_file("/var/www/.env");

    int port = std::atoi(env("WS_PORT", "9800").c_str());
    std::string db_host = env("DB_HOST", "127.0.0.1");
    std::string db_user = env("DB_USER", "root");
    std::string db_pass = env("DB_PASS", "");
    std::string db_name = env("DB_NAME", "mpol");
    int poll_sec = std::atoi(env("POLL_SEC", "5").c_str());

    // Daemonize
    daemonize();

    // TCP listen socket
    int srv = socket(AF_INET, SOCK_STREAM, 0);
    int opt = 1;
    setsockopt(srv, SOL_SOCKET, SO_REUSEADDR, &opt, sizeof(opt));
    sockaddr_in addr{};
    addr.sin_family = AF_INET;
    addr.sin_addr.s_addr = INADDR_ANY;
    addr.sin_port = htons(port);
    if (bind(srv, (sockaddr *)&addr, sizeof(addr)) < 0) {
        perror("[ws] bind"); return 1;
    }
    listen(srv, 64);
    set_nonblock(srv);

    g_epoll_fd = epoll_create1(0);
    epoll_event ev{};
    ev.events = EPOLLIN;
    ev.data.fd = srv;
    epoll_ctl(g_epoll_fd, EPOLL_CTL_ADD, srv, &ev);

    printf("[ws] Listening on :%d  (poll every %ds)\n", port, poll_sec);
    fflush(stdout);

    // Start DB poller in background
    std::thread poller(poller_thread, db_host, db_user, db_pass, db_name, poll_sec);
    poller.detach();

    epoll_event events[128];
    while (g_running.load()) {
        int n = epoll_wait(g_epoll_fd, events, 128, 1000);
        for (int i = 0; i < n; ++i) {
            int fd = events[i].data.fd;

            if (fd == srv) {
                // Accept new connections
                while (true) {
                    sockaddr_in ca{};
                    socklen_t cl = sizeof(ca);
                    int cfd = accept(srv, (sockaddr *)&ca, &cl);
                    if (cfd < 0) break;
                    set_nonblock(cfd);
                    epoll_event ce{};
                    ce.events = EPOLLIN | EPOLLET;
                    ce.data.fd = cfd;
                    epoll_ctl(g_epoll_fd, EPOLL_CTL_ADD, cfd, &ce);
                    std::lock_guard<std::mutex> lk(g_mtx);
                    g_clients[cfd].fd = cfd;
                }
                continue;
            }

            // Client data
            char buf[4096];
            ssize_t rd = read(fd, buf, sizeof(buf));
            if (rd <= 0) {
                remove_client(fd);
                continue;
            }

            std::lock_guard<std::mutex> lk(g_mtx);
            auto it = g_clients.find(fd);
            if (it == g_clients.end()) continue;
            Client &c = it->second;
            c.recv_buf.append(buf, rd);

            if (!c.handshake_done) {
                if (!do_handshake(c)) {
                    g_mtx.unlock();
                    remove_client(fd);
                    g_mtx.lock();
                }
            } else {
                on_client_data(c);
            }
        }
    }

    close(srv);
    close(g_epoll_fd);
    printf("[ws] Shutting down.\n");
    return 0;
}
