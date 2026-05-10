<?php
/**
 * Monitor Dashboard
 * ----------------------------------------------------------------------------
 * Рефакторинг: вся бизнес-логика сохранена 1:1, изменены только:
 *   - все AJAX endpoints возвращают строгий JSON ({ok: bool, ...})
 *   - бэкенд разнесён по чистым функциям-хендлерам
 *   - фронт переписан на vanilla JS + fetch + клиентский рендеринг
 *   - современный тёмный UI, минимальная встроенная CSS дизайн-система,
 *     эмодзи-иконки (как в оригинале), без внешних CDN
 * Внешние зависимости (config.php, TinyRedis, checkLoggedIn) не трогаются.
 */

/* Output buffering: гарантируем, что любой непредвиденный вывод из config.php
   (BOM, лишний пробел после закрывающего PHP-тега) не сломает JSON-ответы AJAX. */
ob_start();

// ============================================================================
// 1. КОНФИГУРАЦИЯ И ИНИЦИАЛИЗАЦИЯ
// ============================================================================
include_once("config.php");
checkLoggedIn("yes");
if (($_SESSION['a'] ?? null) != 1) {
    exit();
}

$dbConfig = [
    'host' => 'localhost',
    'name' => 'mpol',
    'user' => 'root',
    'pass' => 'uiF5bcaw8',
];

$redisConfig = [
    'host' => '45.9.73.98',
    'port' => 6379,
    'pass' => 'qw34rfvgtU9snaWE',
];

$sshUser = 'root';

$serversMap = [
    '51.254.135.10'  => ['pass' => 'bossismyname',  'port' => 45822],
    '45.90.217.114'  => ['pass' => 'uikjm9',        'port' => 22],
    '83.136.233.101' => ['pass' => 'bossismyname',  'port' => 45822],
    '103.213.249.5'  => ['pass' => 'SSK4w6DfSGzk',  'port' => 45822],
    '77.110.104.120' => ['pass' => 'y677Wd2jEdPQ',  'port' => 45822],
    '84.252.101.140' => ['pass' => 'SSK4w6DfSGzk',  'port' => 45822],
];

// PDO
try {
    $dsn = "mysql:host={$dbConfig['host']};dbname={$dbConfig['name']};charset=utf8";
    $pdo = new PDO($dsn, $dbConfig['user'], $dbConfig['pass']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    if (isset($_POST['action'])) {
        respond_error('Ошибка БД', 500);
    }
    $pdo = null;
}

// ============================================================================
// 2. УТИЛИТЫ
// ============================================================================
function respond_json(array $data, int $code = 200): void {
    // Сбрасываем любой ранее накопленный вывод, иначе JSON будет повреждён.
    if (ob_get_level() > 0) {
        @ob_clean();
    }
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
    }
    if (!isset($data['ok'])) {
        $data = ['ok' => true] + $data;
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function respond_error(string $message, int $code = 400): void {
    respond_json(['ok' => false, 'error' => $message], $code);
}

function get_redis(array $cfg): TinyRedis {
    $r = new TinyRedis();
    $r->connect($cfg['host'], $cfg['port']);
    if (!empty($cfg['pass'])) {
        $r->execute(['AUTH', $cfg['pass']]);
    }
    return $r;
}

/**
 * Парсит ответ Redis HGETALL (плоский массив hash, json, hash, json, ...)
 * в ассоциативный массив [hash => decoded_data].
 */
function parse_redis_hash_table(mixed $raw): array {
    $result = [];
    if (!empty($raw) && is_array($raw)) {
        $count = count($raw);
        for ($i = 0; $i < $count; $i += 2) {
            $hash = $raw[$i];
            $json = json_decode($raw[$i + 1] ?? '', true);
            if ($json) {
                $result[$hash] = $json;
            }
        }
    }
    return $result;
}

/**
 * Загружает channel_allocations и возвращает [allocations, allocMap (id => cdn_ip)].
 */
function load_allocations(TinyRedis $redis): array {
    $raw = $redis->execute(['HGETALL', 'channel_allocations']);
    $allocations = [];
    $allocMap = [];
    if (!empty($raw) && is_array($raw)) {
        $count = count($raw);
        for ($i = 0; $i < $count; $i += 2) {
            $cid = $raw[$i];
            $data = json_decode($raw[$i + 1] ?? '', true);
            if ($data) {
                $allocations[$cid] = $data;
                if (isset($data['cdn_ip'])) {
                    $allocMap[$cid] = $data['cdn_ip'];
                }
            }
        }
    }
    return ['allocations' => $allocations, 'allocMap' => $allocMap];
}

/**
 * Достаёт имена каналов по списку ID одним запросом.
 */
function fetch_channel_names(PDO $pdo, array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT id, name FROM channels WHERE id IN ($placeholders)");
    $stmt->execute($ids);
    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[(int)$row['id']] = $row['name'];
    }
    return $map;
}

// ============================================================================
// 3. AJAX ХЕНДЛЕРЫ
// ============================================================================
if (isset($_POST['action'])) {
    $action = (string)$_POST['action'];

    try {
        match ($action) {
            'search_users'            => handle_search_users($pdo),
            'toggle_status'           => handle_toggle_status($redisConfig),
            'toggle_ban_device'       => handle_toggle_ban_device($redisConfig),
            'unblock_zapping_device'  => handle_unblock_zapping_device($redisConfig),
            'get_zapping_devices'     => handle_get_zapping_devices($redisConfig),
            'get_zapping_blocked'     => handle_get_zapping_blocked($redisConfig),
            'check_input'             => handle_check_input($pdo, $redisConfig),
            'get_online_users'        => handle_get_online_users($pdo, $redisConfig),
            'get_stats'               => handle_get_stats($pdo, $redisConfig),
            'get_streams'             => handle_get_streams($pdo, $redisConfig),
            'delete_stream'           => handle_delete_stream($redisConfig),
            'check_stream_alive'      => handle_check_stream_alive($serversMap, $sshUser),
            default                   => respond_error("Неизвестное действие: $action", 404),
        };
    } catch (Throwable $e) {
        respond_error('Server error: ' . $e->getMessage(), 500);
    }
    exit;
}

// ----------------------------------------------------------------------------
// 3.1 search_users
// ----------------------------------------------------------------------------
function handle_search_users(PDO $pdo): void {
    $term = trim((string)($_POST['term'] ?? ''));
    if (mb_strlen($term) < 3) {
        respond_json(['ok' => true, 'users' => []]);
    }
    $stmt = $pdo->prepare("SELECT user FROM accounts WHERE islocal = 1 AND user LIKE :term LIMIT 10");
    $stmt->execute(['term' => "%$term%"]);
    respond_json(['ok' => true, 'users' => $stmt->fetchAll(PDO::FETCH_COLUMN)]);
}

// ----------------------------------------------------------------------------
// 3.2 toggle_status (бан/разбан пользователя через Redis status)
// ----------------------------------------------------------------------------
function handle_toggle_status(array $redisConfig): void {
    $token = (string)($_POST['token'] ?? '');
    if (!$token) {
        respond_error('No token');
    }
    $redis = get_redis($redisConfig);
    $current = $redis->execute(['GET', "user:{$token}:status"]);
    $newStatus = ($current === 'active') ? 'blocked' : 'active';
    $redis->execute(['SET', "user:{$token}:status", $newStatus]);
    respond_json(['ok' => true, 'new_status' => $newStatus]);
}

// ----------------------------------------------------------------------------
// 3.3 toggle_ban_device (бан/разбан конкретного устройства)
// ----------------------------------------------------------------------------
function handle_toggle_ban_device(array $redisConfig): void {
    $token = (string)($_POST['token'] ?? '');
    $hash  = (string)($_POST['hash'] ?? '');
    $mode  = (string)($_POST['mode'] ?? 'ban');
    if (!$token || !$hash) {
        respond_error('Error params');
    }
    $redis = get_redis($redisConfig);
    $key = "blocked:devices:{$token}";
    if ($mode === 'ban') {
        $redis->execute(['SADD', $key, $hash]);
        $redis->execute(['ZREM', "online:users:{$token}", $hash]);
        $redis->execute(['HDEL', "online:users:{$token}:meta", $hash]);
    } else {
        $redis->execute(['SREM', $key, $hash]);
    }
    respond_json(['ok' => true]);
}

// ----------------------------------------------------------------------------
// 3.4 unblock_zapping_device
// ----------------------------------------------------------------------------
function handle_unblock_zapping_device(array $redisConfig): void {
    $token      = (string)($_POST['token'] ?? '');
    $sessionId  = (string)($_POST['session_id'] ?? '');
    if (!$token || !$sessionId) {
        respond_error('Error params');
    }
    $redis = get_redis($redisConfig);
    $redis->execute(['SREM', "blocked:devices:{$token}", $sessionId]);
    $redis->execute(['DEL',  "blocked:devices:{$token}:info:{$sessionId}"]);
    $redis->execute(['DEL',  "blocked:devices:{$token}:reason:{$sessionId}"]);
    $redis->execute(['DEL',  "zap:ban:{$sessionId}"]);
    $redis->execute(['DEL',  "zap:violations:{$sessionId}"]);
    $redis->execute(['DEL',  "zap:last_violation:{$sessionId}"]);
    respond_json(['ok' => true]);
}

// ----------------------------------------------------------------------------
// 3.5 get_zapping_devices (устройства с zap:device:* + violations)
// ----------------------------------------------------------------------------
function handle_get_zapping_devices(array $redisConfig): void {
    $redis = get_redis($redisConfig);
    $deviceKeys = $redis->execute(['KEYS', 'zap:device:*']);
    $devices = [];
    if (!empty($deviceKeys) && is_array($deviceKeys)) {
        foreach ($deviceKeys as $key) {
            $infoJson = $redis->execute(['GET', $key]);
            if (!$infoJson) continue;
            $info = json_decode($infoJson, true);
            if (!$info) continue;

            $sessionId  = str_replace('zap:device:', '', $key);
            $violations = (int)$redis->execute(['GET', "zap:violations:{$sessionId}"]);
            $banTtl     = (int)$redis->execute(['TTL', "zap:ban:{$sessionId}"]);
            $isBanned   = $banTtl > 0;

            if ($violations > 0 || $isBanned) {
                $devices[] = [
                    'session_id'      => $sessionId,
                    'token'           => $info['token']        ?? '-',
                    'ip'              => $info['ip']           ?? '-',
                    'user_agent'      => $info['user_agent']   ?? '-',
                    'channel'         => $info['channel']      ?? '-',
                    'violations'      => $violations,
                    'is_banned'       => $isBanned,
                    'ban_time_left'   => $isBanned ? $banTtl : null,
                    'last_violation'  => $info['last_violation'] ?? null,
                    'last_seen'       => $info['last_seen']      ?? null,
                ];
            }
        }
    }
    usort($devices, fn($a, $b) => $b['violations'] <=> $a['violations']);
    respond_json(['ok' => true, 'devices' => $devices, 'total' => count($devices)]);
}

// ----------------------------------------------------------------------------
// 3.6 get_zapping_blocked (заблокированные устройства с reason=anti-zapping)
// ----------------------------------------------------------------------------
function handle_get_zapping_blocked(array $redisConfig): void {
    $redis = get_redis($redisConfig);
    $blockedKeys = $redis->execute(['KEYS', 'blocked:devices:*:info:*']);
    $blockedDevices = [];

    if (!empty($blockedKeys) && is_array($blockedKeys)) {
        foreach ($blockedKeys as $key) {
            $infoJson = $redis->execute(['GET', $key]);
            if (!$infoJson) continue;
            $info = json_decode($infoJson, true);
            if (!$info) continue;
            if (!preg_match('/blocked:devices:([^:]+):info:(.+)/', $key, $m)) continue;

            $token     = $m[1];
            $sessionId = $m[2];
            $reason    = $redis->execute(['GET', "blocked:devices:{$token}:reason:{$sessionId}"]);
            $banTtl    = (int)$redis->execute(['TTL', $key]);

            if ($reason === 'anti-zapping') {
                $blockedDevices[] = [
                    'token'         => $token,
                    'session_id'    => $sessionId,
                    'ip'            => $info['ip']         ?? '-',
                    'user_agent'    => $info['user_agent'] ?? '-',
                    'reason'        => $reason,
                    'violations'    => $info['violations'] ?? 0,
                    'ban_time_left' => $banTtl > 0 ? $banTtl : null,
                ];
            }
        }
    }
    respond_json(['ok' => true, 'devices' => $blockedDevices, 'total' => count($blockedDevices)]);
}

// ----------------------------------------------------------------------------
// 3.7 check_input — карточка пользователя с сессиями
// ----------------------------------------------------------------------------
function handle_check_input(PDO $pdo, array $redisConfig): void {
    $inputVal = trim((string)($_POST['input'] ?? ''));
    $stmt = $pdo->prepare("SELECT user, token FROM accounts WHERE user = :v OR token = :v LIMIT 1");
    $stmt->execute(['v' => $inputVal]);
    $account = $stmt->fetch();
    if (!$account) {
        respond_json(['ok' => false, 'error' => 'Пользователь не найден'], 404);
    }

    $token    = (string)$account['token'];
    $userName = (string)$account['user'];

    $redis = get_redis($redisConfig);

    $allocResult = load_allocations($redis);
    $allocMap = $allocResult['allocMap'];

    $status = $redis->execute(['GET', "user:{$token}:status"]);
    $expire = (int)$redis->execute(['GET', "user:{$token}:expire"]);
    $ttl    = (int)$redis->execute(['TTL', "user:{$token}:status"]);

    $bannedHashes = $redis->execute(['SMEMBERS', "blocked:devices:{$token}"]);
    $bannedMap = [];
    if (!empty($bannedHashes) && is_array($bannedHashes)) {
        foreach ($bannedHashes as $h) {
            $bannedMap[$h] = true;
        }
    }

    $rawOnline  = $redis->execute(['HGETALL', "online:users:{$token}:meta"]);
    $onlineKeys = [];
    if (!empty($rawOnline) && is_array($rawOnline)) {
        $cnt = count($rawOnline);
        for ($i = 0; $i < $cnt; $i += 2) {
            $onlineKeys[$rawOnline[$i]] = true;
        }
    }
    $rawHistory = $redis->execute(['HGETALL', "history:users:{$token}"]);

    $historyData     = parse_redis_hash_table($rawHistory);
    $onlineDataOnly  = parse_redis_hash_table($rawOnline);
    $allSessionsMap  = array_merge($historyData, $onlineDataOnly);

    $sessions = [];
    $chIds = [];
    $now = time();
    foreach ($allSessionsMap as $hash => $data) {
        $isOnline = isset($onlineKeys[$hash]);
        $chId     = $data['channel'] ?? 0;
        if (!empty($chId)) {
            $chIds[] = $chId;
        }

        $server = $data['server'] ?? '-';
        if ($isOnline && $chId && isset($allocMap[$chId])) {
            $server = $allocMap[$chId] . " (CDN)";
        }

        $startTs    = (int)($data['start']     ?? $now);
        $lastSeenTs = (int)($data['last_seen'] ?? $now);
        $duration   = ($isOnline ? $now : $lastSeenTs) - $startTs;
        if ($duration < 0) {
            $duration = 0;
        }

        $sessions[] = [
            'hash'         => $hash,
            'channel_id'   => (int)$chId,
            'channel_name' => null, // дозаполним ниже
            'ip'           => $data['ip']  ?? '-',
            'server'       => $server,
            'ua'           => $data['ua']  ?? '-',
            'start_ts'     => $startTs,
            'last_seen_ts' => $lastSeenTs,
            'duration_sec' => $duration,
            'is_online'    => $isOnline,
            'is_banned'    => isset($bannedMap[$hash]),
        ];
    }

    usort($sessions, function ($a, $b) {
        if ($a['is_online'] !== $b['is_online']) {
            return $b['is_online'] ? 1 : -1;
        }
        return $b['last_seen_ts'] <=> $a['last_seen_ts'];
    });

    $chMap = fetch_channel_names($pdo, $chIds);
    foreach ($sessions as &$s) {
        $s['channel_name'] = $chMap[$s['channel_id']] ?? 'Неизвестно';
    }
    unset($s);

    $isAccessAllowed = ($status === 'active' && $expire > $now);

    respond_json([
        'ok'   => true,
        'user' => [
            'name'              => $userName,
            'token'             => $token,
            'status'            => $status ?: null,
            'expire_ts'         => $expire ?: null,
            'ttl_sec'           => $ttl,
            'is_access_allowed' => $isAccessAllowed,
        ],
        'sessions' => $sessions,
    ]);
}

// ----------------------------------------------------------------------------
// 3.8 get_online_users
// ----------------------------------------------------------------------------
function handle_get_online_users(PDO $pdo, array $redisConfig): void {
    $redis = get_redis($redisConfig);

    $chMap = [];
    try {
        $stmt = $pdo->query("SELECT id, name FROM channels");
        while ($row = $stmt->fetch()) {
            $chMap[(int)$row['id']] = $row['name'];
        }
    } catch (Throwable $e) {
        // игнорируем — список просто будет с "ID: x"
    }

    // Нужны аллокации, чтобы понять live/archive статус
    $allocResult = load_allocations($redis);
    $allocations = $allocResult['allocations'];

    $usersFound = [];
    $cursor = '0';
    do {
        $response = $redis->execute(['SCAN', $cursor, 'MATCH', 'online:users:*', 'COUNT', 1000]);
        if (!$response || !is_array($response) || count($response) < 2) {
            break;
        }
        $cursor = $response[0];
        $keys   = $response[1];

        if (!empty($keys) && is_array($keys)) {
            foreach ($keys as $key) {
                if (strpos($key, ':meta') !== false) continue;
                $token = substr($key, 13);
                if ($token !== false && $token !== '') {
                    $usersFound[$token] = $key;
                }
            }
        }
    } while ($cursor !== '0' && $cursor !== 0);

    $now = time();
    $resultList = [];
    foreach ($usersFound as $token => $key) {
        $metaData = $redis->execute(['HGETALL', $key . ':meta']);
        if (empty($metaData) || !is_array($metaData)) continue;

        $cnt = count($metaData);
        for ($i = 0; $i < $cnt; $i += 2) {
            $jsonStr = $metaData[$i + 1] ?? '';
            $data = json_decode($jsonStr, true);
            if (!$data) continue;

            $start    = (int)($data['start']     ?? $now);
            $lastSeen = (int)($data['last_seen'] ?? $now);
            $chId     = (int)($data['channel']   ?? 0);
            $duration = $now - $start;

            $isLive = false;
            $isArchive = false;
            if ($chId && isset($allocations[$chId])) {
                $isLive    = (bool)($allocations[$chId]['is_live']    ?? false);
                $isArchive = (bool)($allocations[$chId]['is_archive'] ?? false);
            }

            $resultList[] = [
                'token'        => (string)$token,
                'user_name'    => null,
                'ip'           => $data['ip'] ?? '-',
                'ua'           => $data['ua'] ?? 'Unknown',
                'channel_id'   => $chId,
                'channel_name' => $chMap[$chId] ?? "ID: $chId",
                'duration_sec' => $duration,
                'start_ts'     => $start,
                'last_seen_ts' => $lastSeen,
                'server'       => $data['server'] ?? '-',
                'is_live'      => $isLive,
                'is_archive'   => $isArchive,
            ];
        }
    }

    usort($resultList, fn($a, $b) => $b['duration_sec'] <=> $a['duration_sec']);

    // Подгружаем имена пользователей одним запросом
    $tokens = array_column($resultList, 'token');
    $userMap = [];
    if (!empty($tokens)) {
        $placeholders = implode(',', array_fill(0, count($tokens), '?'));
        $stmt = $pdo->prepare("SELECT token, user FROM accounts WHERE token IN ($placeholders)");
        $stmt->execute($tokens);
        while ($row = $stmt->fetch()) {
            $userMap[$row['token']] = $row['user'];
        }
    }
    foreach ($resultList as &$row) {
        $row['user_name'] = $userMap[$row['token']] ?? null;
    }
    unset($row);

    respond_json(['ok' => true, 'users' => $resultList, 'total' => count($resultList)]);
}

// ----------------------------------------------------------------------------
// 3.9 get_stats — статистика по каналам
// ----------------------------------------------------------------------------
function handle_get_stats(PDO $pdo, array $redisConfig): void {
    $redis = get_redis($redisConfig);
    $channels = $redis->sMembers("stats:channels_list");
    $today = date("Y-m-d");

    if (empty($channels) || !is_array($channels)) {
        respond_json(['ok' => true, 'rows' => [], 'totals' => ['online' => 0, 'daily' => 0]]);
    }

    $names = fetch_channel_names($pdo, $channels);

    $rows = [];
    $totalOnline = 0;
    $totalDaily  = 0;
    foreach ($channels as $cid) {
        $cidInt = (int)$cid;
        $onl = (int)$redis->zCard("stats:online:channel:$cid");
        $day = (int)$redis->pfCount("stats:daily:$today:channel:$cid");
        $totalOnline += $onl;
        $totalDaily  += $day;
        if ($onl > 0 || $day > 0) {
            $rows[] = [
                'id'     => $cidInt,
                'name'   => $names[$cidInt] ?? "ID $cid",
                'online' => $onl,
                'daily'  => $day,
            ];
        }
    }

    respond_json([
        'ok'     => true,
        'rows'   => $rows,
        'totals' => ['online' => $totalOnline, 'daily' => $totalDaily],
    ]);
}

// ----------------------------------------------------------------------------
// 3.10 get_streams — активные аллокации
// ----------------------------------------------------------------------------
function handle_get_streams(PDO $pdo, array $redisConfig): void {
    $redis = get_redis($redisConfig);
    $raw = $redis->execute(['HGETALL', 'channel_allocations']);

    $streams = [];
    if (!empty($raw) && is_array($raw)) {
        $cnt = count($raw);
        for ($i = 0; $i < $cnt; $i += 2) {
            $cid  = $raw[$i];
            $data = json_decode($raw[$i + 1] ?? '', true);
            if ($data) {
                $data['allocation_id']      = $cid;
                $data['display_channel_id'] = $data['current_channel'] ?? $cid;
                $streams[] = $data;
            }
        }
    }

    usort($streams, fn($a, $b) => ($b['allocated_at'] ?? 0) <=> ($a['allocated_at'] ?? 0));

    $channelIds = [];
    foreach ($streams as $s) {
        $channelIds[] = $s['display_channel_id'];
        if (isset($s['root_id'])) {
            $channelIds[] = $s['root_id'];
        }
    }
    $names = fetch_channel_names($pdo, $channelIds);

    $out = [];
    foreach ($streams as $s) {
        $allocId    = $s['allocation_id'];
        $displayId  = (int)$s['display_channel_id'];
        $rootId     = (int)($s['root_id'] ?? $allocId);
        $out[] = [
            'allocation_id'       => (string)$allocId,
            'display_channel_id'  => $displayId,
            'display_channel_name'=> $names[$displayId] ?? "ID $displayId",
            'root_id'             => $rootId,
            'root_name'           => $names[$rootId] ?? "ID $rootId",
            'provider'            => (string)($s['provider'] ?? '-'),
            'slot'                => (string)($s['slot'] ?? '0'),
            'cdn_ip'              => (string)($s['cdn_ip'] ?? '-'),
            'allocated_at'        => (int)($s['allocated_at'] ?? 0),
            'source_url'          => (string)($s['source_url'] ?? ''),
            'token'               => (string)($s['token'] ?? ''),
            'quality'             => (string)($s['quality'] ?? ''),
            'switch_count'        => (int)($s['switch_count'] ?? 0),
            'is_live'             => (bool)($s['is_live'] ?? false),
            'is_archive'          => (bool)($s['is_archive'] ?? false),
        ];
    }

    respond_json(['ok' => true, 'streams' => $out, 'total' => count($out)]);
}

// ----------------------------------------------------------------------------
// 3.11 delete_stream — Redis PUBLISH stop
// ----------------------------------------------------------------------------
function handle_delete_stream(array $redisConfig): void {
    $cid   = (string)($_POST['cid'] ?? '');
    $slot  = (string)($_POST['slot'] ?? '');
    $prov  = (string)($_POST['provider'] ?? '');
    $token = (string)($_POST['token'] ?? '');
    if (!$cid || !$prov) {
        respond_error('Error params');
    }
    $payload = json_encode([
        'slot'     => $slot,
        'provider' => $prov,
        'channel'  => $cid,
        'token'    => $token,
    ]);
    $redis = get_redis($redisConfig);
    $redis->execute(['PUBLISH', 'channel_stops', $payload]);
    respond_json(['ok' => true]);
}

// ----------------------------------------------------------------------------
// 3.12 check_stream_alive — SSH ps aux | grep hls_p
// ----------------------------------------------------------------------------
function handle_check_stream_alive(array $serversMap, string $sshUser): void {
    $cid    = (string)($_POST['cid'] ?? '');
    $cdnIp  = (string)($_POST['cdn'] ?? '');
    $rootId = (string)($_POST['root_id'] ?? $cid);
    if (!$cid || !$cdnIp) {
        respond_error('Invalid params');
    }
    $conf = $serversMap[$cdnIp] ?? null;
    if (!$conf) {
        respond_error("CONFIG_ERR: No settings for $cdnIp");
    }
    $pass = (string)($conf['pass'] ?? '');
    $port = (int)($conf['port'] ?? 22);

    $cmd = "ps auxww | grep hls_p | grep -w " . escapeshellarg((string)$rootId) . " | grep -v grep";
    $output = '';

    if (function_exists('ssh2_connect')) {
        $connection = @ssh2_connect($cdnIp, $port);
        if (!$connection) {
            respond_error('SSH_CONN_ERR');
        }
        if (!@ssh2_auth_password($connection, $sshUser, $pass)) {
            respond_error('SSH_AUTH_ERR');
        }
        $stream = ssh2_exec($connection, $cmd);
        if (!$stream) {
            respond_error('SSH_EXEC_ERR');
        }
        stream_set_blocking($stream, true);
        $output = (string)stream_get_contents($stream);
    } else {
        $safePass = escapeshellarg($pass);
        $fullCmd = "sshpass -p $safePass ssh -p $port -o StrictHostKeyChecking=no $sshUser@$cdnIp \"$cmd\" 2>&1";
        $output = (string)shell_exec($fullCmd);
    }

    $alive = (strpos($output, 'hls_p') !== false);
    respond_json(['ok' => true, 'alive' => $alive, 'root_id' => $rootId]);
}

$isEmbed = isset($_GET['embed']);
if (!$isEmbed):
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Monitor Dashboard</title>
<?php else: ob_end_clean(); endif; ?>
<style>
/* ============================================================================
   Дизайн-система: тёмная тема, минимальный набор переменных и компонентов.
   ========================================================================== */
<?php if (!$isEmbed): ?>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html, body { width: 100%; min-height: 100vh; }
:root {
    --bg:           #0a0e16;
    --bg-elevated:  #0f1520;
    --surface:      #131a26;
    --surface-2:    #1a2333;
    --surface-hi:   #22304a;
    --border:       #243043;
    --border-hi:    #324562;
    --text:         #e6edf3;
    --text-dim:     #8b96a8;
    --text-faint:   #5b6678;
    --primary:      #4d8eff;
    --primary-hov:  #6ba1ff;
    --primary-bg:   rgba(77, 142, 255, 0.12);
    --success:      #3ecf8e;
    --success-bg:   rgba(62, 207, 142, 0.12);
    --warning:      #f5a623;
    --warning-bg:   rgba(245, 166, 35, 0.12);
    --danger:       #ef4444;
    --danger-bg:    rgba(239, 68, 68, 0.12);
    --radius:       10px;
    --radius-lg:    14px;
    --radius-pill:  999px;
    --shadow-1:     0 1px 2px rgba(0,0,0,.3), 0 4px 12px rgba(0,0,0,.2);
    --shadow-2:     0 10px 30px rgba(0,0,0,.45);
    --transition:   150ms ease;
    --font:         -apple-system, BlinkMacSystemFont, "Segoe UI", "Inter", Roboto, "Helvetica Neue", Arial, sans-serif;
    --mono:         "SF Mono", "JetBrains Mono", Menlo, Consolas, monospace;
}
body {
    font-family: var(--font);
    background: var(--bg);
    color: var(--text);
    font-size: 14px;
    line-height: 1.5;
    overflow-x: hidden;
    -webkit-font-smoothing: antialiased;
    text-rendering: optimizeLegibility;
}
<?php else: ?>
#result *, #result *::before, #result *::after { box-sizing: border-box; }
#result {
    --bg:           #0a0e16;
    --bg-elevated:  #0f1520;
    --surface:      #131a26;
    --surface-2:    #1a2333;
    --surface-hi:   #22304a;
    --border:       #243043;
    --border-hi:    #324562;
    --text:         #e6edf3;
    --text-dim:     #8b96a8;
    --text-faint:   #5b6678;
    --primary:      #4d8eff;
    --primary-hov:  #6ba1ff;
    --primary-bg:   rgba(77, 142, 255, 0.12);
    --success:      #3ecf8e;
    --success-bg:   rgba(62, 207, 142, 0.12);
    --warning:      #f5a623;
    --warning-bg:   rgba(245, 166, 35, 0.12);
    --danger:       #ef4444;
    --danger-bg:    rgba(239, 68, 68, 0.12);
    --radius:       10px;
    --radius-lg:    14px;
    --radius-pill:  999px;
    --shadow-1:     0 1px 2px rgba(0,0,0,.3), 0 4px 12px rgba(0,0,0,.2);
    --shadow-2:     0 10px 30px rgba(0,0,0,.45);
    --transition:   150ms ease;
    --font:         -apple-system, BlinkMacSystemFont, "Segoe UI", "Inter", Roboto, "Helvetica Neue", Arial, sans-serif;
    --mono:         "SF Mono", "JetBrains Mono", Menlo, Consolas, monospace;
    font-family: var(--font);
    background: var(--bg);
    color: var(--text);
    font-size: 14px;
    line-height: 1.5;
    -webkit-font-smoothing: antialiased;
    text-rendering: optimizeLegibility;
}
<?php endif; ?>

/* ---- Uman tabs (embed mode — внутри header mb.php) ---- */
#uman-tabs {
    display: flex;
    gap: 4px;
    padding: 4px;
    align-items: center;
    flex-wrap: nowrap;
    overflow-x: auto;
}
.uman-tab {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: transparent;
    border: none;
    color: #8b96a8;
    padding: 5px 10px;
    border-radius: 999px;
    cursor: pointer;
    font-size: 11px;
    font-weight: 500;
    transition: 150ms ease;
    white-space: nowrap;
}
.uman-tab:hover { color: #e6edf3; background: rgba(255,255,255,0.08); }
.uman-tab.active { background: #4d8eff; color: #fff; }
.uman-tab--danger { color: #ff8b8b; }
.uman-tab--danger.active { background: #ef4444; color: #fff; }

/* ---- Top bar / Tabs ---- */
.topbar {
    position: sticky;
    top: 0;
    z-index: 100;
    background: rgba(10, 14, 22, 0.85);
    backdrop-filter: saturate(180%) blur(12px);
    border-bottom: 1px solid var(--border);
    padding: 12px 20px;
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    align-items: center;
    justify-content: space-between;
}
.brand {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 600;
    color: var(--text);
    letter-spacing: -0.01em;
    font-size: 15px;
}
.brand-glyph { color: var(--primary); }
.tabs {
    display: flex;
    gap: 4px;
    background: var(--surface);
    padding: 4px;
    border-radius: var(--radius-pill);
    border: 1px solid var(--border);
}
.tab {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: transparent;
    border: none;
    color: var(--text-dim);
    padding: 7px 14px;
    border-radius: var(--radius-pill);
    cursor: pointer;
    font-size: 13px;
    font-weight: 500;
    transition: var(--transition);
    white-space: nowrap;
}
.tab .glyph { font-size: 14px; }
.tab:hover { color: var(--text); background: var(--surface-2); }
.tab.active { background: var(--primary); color: #fff; }
.tab--danger { color: #ff8b8b; }
.tab--danger.active { background: var(--danger); color: #fff; }

/* ---- Layout ---- */
.container {
    max-width: 1200px;
    margin: 24px auto;
    padding: 0 20px;
}
.view { display: none; animation: fadeIn 200ms ease; }
.view.active { display: block; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: translateY(0); } }

/* ---- View header ---- */
.view-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 16px;
    flex-wrap: wrap;
    gap: 12px;
}
.view-title {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 18px;
    font-weight: 600;
    color: var(--text);
}

.view-actions { display: flex; gap: 8px; align-items: center; }

/* ---- Buttons ---- */
.btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 14px;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    background: var(--surface);
    color: var(--text);
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    transition: var(--transition);
    font-family: inherit;
}
.btn:hover { background: var(--surface-2); border-color: var(--border-hi); }
.btn:active { transform: translateY(1px); }
.btn:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-glyph { font-size: 14px; }
.btn--primary { background: var(--primary); border-color: var(--primary); color: #fff; }
.btn--primary:hover { background: var(--primary-hov); border-color: var(--primary-hov); }
.btn--success { background: var(--success); border-color: var(--success); color: #07140d; font-weight: 600; }
.btn--success:hover { filter: brightness(1.1); }
.btn--danger { background: var(--danger); border-color: var(--danger); color: #fff; }
.btn--danger:hover { filter: brightness(1.1); }
.btn--ghost { background: transparent; }
.btn--sm { padding: 5px 9px; font-size: 12px; }
.btn--icon { padding: 5px 9px; font-size: 16px; line-height: 1; }

/* ---- Card ---- */
.card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-1);
    overflow: hidden;
}
.card + .card { margin-top: 16px; }

/* ---- Search bar ---- */
.search-bar {
    position: relative;
    display: flex;
    align-items: center;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-pill);
    padding: 4px 4px 4px 16px;
    max-width: 560px;
    margin: 0 auto 24px;
    transition: var(--transition);
}
.search-bar:focus-within {
    border-color: var(--primary);
    box-shadow: 0 0 0 4px var(--primary-bg);
}
.search-bar .search-icon { color: var(--text-faint); font-size: 16px; flex-shrink: 0; }
.toast-icon { font-size: 16px; line-height: 1; }
.search-bar input {
    flex: 1;
    background: transparent;
    border: none;
    outline: none;
    color: var(--text);
    font-size: 14px;
    padding: 10px 12px;
    font-family: inherit;
}
.search-bar input::placeholder { color: var(--text-faint); }
.search-bar .clear-x {
    cursor: pointer;
    color: var(--text-faint);
    padding: 0 8px;
    font-size: 18px;
    display: none;
    user-select: none;
}
.search-bar .clear-x:hover { color: var(--text); }
.search-bar.has-value .clear-x { display: block; }

.autocomplete {
    position: absolute;
    top: calc(100% + 6px);
    left: 8px;
    right: 8px;
    background: var(--surface-2);
    border: 1px solid var(--border-hi);
    border-radius: var(--radius);
    box-shadow: var(--shadow-2);
    z-index: 50;
    overflow: hidden;
    display: none;
    max-height: 280px;
    overflow-y: auto;
}
.autocomplete.open { display: block; }
.ac-item {
    padding: 10px 16px;
    cursor: pointer;
    border-bottom: 1px solid var(--border);
    color: var(--text);
    font-size: 13px;
    transition: var(--transition);
}
.ac-item:last-child { border-bottom: none; }
.ac-item:hover, .ac-item.active { background: var(--primary-bg); color: var(--primary-hov); }

/* ---- Tables ---- */
.table-wrap {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}
table.data {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
table.data th, table.data td {
    padding: 10px 14px;
    text-align: left;
    border-bottom: 1px solid var(--border);
    vertical-align: middle;
}
table.data thead th {
    background: var(--bg-elevated);
    color: var(--text-dim);
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    font-weight: 600;
    user-select: none;
    position: sticky;
    top: 0;
    z-index: 1;
}
table.data thead th.sortable { cursor: pointer; transition: var(--transition); }
table.data thead th.sortable:hover { color: var(--text); }
table.data thead th.sortable .sort-arrow { display: inline-block; width: 10px; opacity: 0.4; }
table.data thead th.sort-asc .sort-arrow::before { content: '▲'; opacity: 1; }
table.data thead th.sort-desc .sort-arrow::before { content: '▼'; opacity: 1; }
table.data tbody tr { transition: background var(--transition); }
table.data tbody tr:hover { background: var(--bg-elevated); }
table.data td.num { font-family: var(--mono); font-weight: 500; }
table.data td.num.online-cell { color: var(--success); }
table.data tfoot td {
    font-weight: 600;
    background: var(--surface-hi);
    color: var(--text);
    border-bottom: none;
}

/* row states */
tr.row-alive { background: var(--success-bg) !important; }
tr.row-dead  { background: var(--danger-bg) !important; box-shadow: inset 3px 0 0 var(--danger); }

/* ---- Badges & status dots ---- */
.badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 8px;
    border-radius: var(--radius-pill);
    font-size: 11px;
    font-weight: 600;
    line-height: 1.6;
}
.badge--success { background: var(--success-bg); color: var(--success); }
.badge--warning { background: var(--warning-bg); color: var(--warning); }
.badge--danger  { background: var(--danger-bg);  color: var(--danger);  }
.badge--info    { background: var(--primary-bg); color: var(--primary-hov); }
.badge--muted   { background: var(--surface-2);  color: var(--text-dim); }

.status-dot {
    display: inline-block;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    margin-right: 6px;
    background: var(--text-faint);
    flex-shrink: 0;
}
.status-dot--live    { background: var(--success); box-shadow: 0 0 8px var(--success); animation: pulse 1.6s infinite; }
.status-dot--archive { background: var(--warning); box-shadow: 0 0 6px var(--warning); }
.status-dot--unknown { background: var(--text-faint); }
@keyframes pulse {
    0%, 100% { opacity: 1; transform: scale(1); }
    50%      { opacity: 0.55; transform: scale(0.85); }
}

/* ---- Stats / info grid ---- */
.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 10px;
    padding: 16px;
}
.info-item {
    background: var(--bg-elevated);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 12px 14px;
}
.info-item .lbl {
    display: block;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-dim);
    margin-bottom: 4px;
}
.info-item .val {
    font-size: 16px;
    font-weight: 600;
    color: var(--text);
    word-break: break-all;
}
.info-item .val.mono { font-family: var(--mono); font-size: 13px; }

/* ---- User card ---- */
.user-card .card-header {
    padding: 18px 20px;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
}
.user-card .user-name {
    font-size: 18px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 10px;
}
.user-card .user-name i { color: var(--primary); width: 20px; height: 20px; }
.user-card .token-chip {
    font-family: var(--mono);
    font-size: 12px;
    color: var(--text-dim);
    background: var(--bg-elevated);
    padding: 4px 10px;
    border-radius: var(--radius-pill);
    border: 1px solid var(--border);
    word-break: break-all;
}
.section-title {
    padding: 14px 20px 10px;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--text-dim);
    font-weight: 600;
    border-top: 1px solid var(--border);
}
.user-card .access-bar { padding: 0 16px 16px; }
.btn-verdict {
    width: 100%;
    padding: 14px;
    border: none;
    border-radius: var(--radius);
    font-weight: 600;
    cursor: pointer;
    font-size: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-direction: column;
    gap: 4px;
    transition: var(--transition);
    color: #fff;
    font-family: inherit;
}
.btn-verdict:hover { filter: brightness(1.05); }
.btn-verdict:active { transform: scale(0.99); }
.btn-verdict small { font-weight: 400; opacity: 0.85; font-size: 12px; }
.btn-verdict.allow { background: linear-gradient(180deg, #3ecf8e, #1f8a5b); }
.btn-verdict.deny  { background: linear-gradient(180deg, #ef4444, #b53030); }

/* ---- Sessions list ---- */
.sessions-list { padding: 16px; display: grid; gap: 10px; }
.session {
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 12px 14px;
    background: var(--bg-elevated);
    border-left-width: 3px;
    transition: var(--transition);
}
.session.is-online  { border-left-color: var(--success); }
.session.is-offline { border-left-color: var(--text-faint); opacity: 0.7; }
.session.is-banned  { border-left-color: var(--danger);  background: rgba(239,68,68,0.06); }

.session-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    margin-bottom: 8px;
    font-weight: 600;
}
.session-head .channel-name { display: flex; align-items: center; gap: 6px; }
.session-head .channel-id { color: var(--text-faint); font-weight: 400; font-size: 12px; }
.session-status {
    display: inline-flex;
    align-items: center;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-dim);
    font-weight: 600;
}
.session.is-online  .session-status { color: var(--success); }
.session.is-offline .session-status { color: var(--text-dim); }
.session.is-banned  .session-status { color: var(--danger); }

.session-body {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 6px 14px;
    font-size: 12px;
    color: var(--text-dim);
}
.session-body .field { display: flex; gap: 6px; }
.session-body .field b { color: var(--text); font-weight: 500; }
.session-foot {
    margin-top: 10px;
    padding-top: 10px;
    border-top: 1px dashed var(--border);
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    gap: 12px;
    flex-wrap: wrap;
}
.session-foot .last-seen { font-size: 12px; color: var(--text-dim); }
.session-foot .hash { font-family: var(--mono); font-size: 11px; color: var(--text-faint); word-break: break-all; }

/* ---- Empty / loading / error ---- */
.empty, .loading, .err {
    text-align: center;
    padding: 32px 16px;
    color: var(--text-dim);
}
.err { color: var(--danger); }
.skeleton {
    background: linear-gradient(90deg, var(--surface) 0%, var(--surface-2) 50%, var(--surface) 100%);
    background-size: 200% 100%;
    animation: shimmer 1.4s infinite;
    border-radius: 4px;
    height: 14px;
}
@keyframes shimmer {
    0%   { background-position: 200% 0; }
    100% { background-position: -200% 0; }
}

/* ---- Filter inputs in table headers ---- */
.th-filter {
    margin-top: 4px;
}
.th-filter input {
    width: 60px;
    background: var(--surface-2);
    border: 1px solid var(--border);
    color: var(--text);
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 11px;
    font-family: var(--mono);
}
.th-filter input:focus { outline: none; border-color: var(--primary); }

/* ---- Streams cells ---- */
.cell-channel { min-width: 200px; }
.cell-channel .ch-row { display: flex; align-items: center; gap: 6px; }
.cell-channel .ch-row strong { color: var(--text); }
.cell-channel .ch-meta { display: block; font-size: 11px; color: var(--text-faint); margin-top: 2px; }
.cell-channel .ch-meta i { width: 11px; height: 11px; vertical-align: -2px; }

.action-cell { display: flex; gap: 4px; justify-content: flex-end; }

/* ---- Two-column zapping grid ---- */
.zapping-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}
@media (max-width: 900px) {
    .zapping-grid { grid-template-columns: 1fr; }
}
.zapping-pane h4 {
    font-size: 13px;
    font-weight: 600;
    padding: 14px 16px;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    gap: 8px;
}
.zapping-pane.violations h4 { color: var(--warning); }
.zapping-pane.blocked    h4 { color: var(--danger);  }

/* ---- Modal ---- */
.modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(5, 9, 16, 0.7);
    backdrop-filter: blur(4px);
    z-index: 9999;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 16px;
    animation: fadeIn 150ms ease;
}
.modal-overlay.active { display: flex; }
.modal {
    background: var(--surface);
    border: 1px solid var(--border-hi);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-2);
    width: 100%;
    max-width: 440px;
    overflow: hidden;
    animation: scaleIn 180ms ease;
}
@keyframes scaleIn { from { transform: scale(0.96); opacity: 0; } to { transform: scale(1); opacity: 1; } }
.modal-header {
    padding: 16px 20px;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: 600;
    color: var(--text);
}
.modal-header i { color: var(--warning); width: 18px; height: 18px; }
.modal-body { padding: 16px 20px; color: var(--text-dim); font-size: 13px; line-height: 1.6; }
.modal-body .modal-info {
    background: var(--bg-elevated);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 12px;
    margin-top: 10px;
    font-size: 12px;
    display: grid;
    gap: 4px;
    color: var(--text);
}
.modal-body .modal-info span { color: var(--text-dim); }
.modal-footer {
    padding: 12px 20px;
    border-top: 1px solid var(--border);
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    background: var(--bg-elevated);
}

/* ---- Toast ---- */
.toast-stack {
    position: fixed;
    bottom: 20px;
    right: 20px;
    z-index: 10000;
    display: flex;
    flex-direction: column;
    gap: 8px;
    max-width: 360px;
}
.toast {
    background: var(--surface-2);
    border: 1px solid var(--border-hi);
    border-radius: var(--radius);
    padding: 12px 16px;
    box-shadow: var(--shadow-2);
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 13px;
    color: var(--text);
    animation: slideIn 220ms ease;
}
@keyframes slideIn { from { transform: translateY(8px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
.toast i { width: 16px; height: 16px; flex-shrink: 0; }
.toast--success { border-color: var(--success); }
.toast--success i { color: var(--success); }
.toast--error   { border-color: var(--danger); }
.toast--error i { color: var(--danger); }
.toast--info    { border-color: var(--primary); }
.toast--info i  { color: var(--primary); }

/* ---- Misc ---- */
.text-muted { color: var(--text-dim); }
.text-danger { color: var(--danger); }
.mono { font-family: var(--mono); font-size: 12px; }
.row-muted small { color: var(--text-faint); }
a.user-link {
    color: var(--primary-hov);
    text-decoration: none;
    border-bottom: 1px dashed var(--border-hi);
    cursor: pointer;
}
a.user-link:hover { color: var(--text); border-bottom-color: var(--primary); }
.shorten { max-width: 240px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; display: inline-block; vertical-align: middle; }

/* responsive */
@media (max-width: 640px) {
    .topbar { padding: 10px 14px; }
    .container { padding: 0 12px; margin: 16px auto; }
    .tabs { width: 100%; justify-content: space-between; overflow-x: auto; }
    .tab span.label { display: none; }
}
</style>
<?php if (!$isEmbed): ?>
</head>
<body>
<?php endif; ?>
<?php if ($isEmbed): ?>
<!-- UMAN-TABS-START -->
<div id="uman-tabs">
    <button class="uman-tab active" data-view="search">Поиск</button>
    <button class="uman-tab"        data-view="streams">Потоки</button>
    <button class="uman-tab"        data-view="stats">Статистика</button>
    <button class="uman-tab"        data-view="users">Зрители</button>
    <button class="uman-tab uman-tab--danger" data-view="zapping">🚫 Zapping</button>
</div>
<!-- UMAN-TABS-END -->
<?php else: ?>
<header class="topbar">
    <div class="brand"><span>Metropoliten Monitor</span></div>
    <nav class="tabs" id="tabs" role="tablist">
        <button class="tab active" data-view="search"  role="tab">Поиск</button>
        <button class="tab"        data-view="streams" role="tab">Потоки</button>
        <button class="tab"        data-view="stats"   role="tab">Статистика</button>
        <button class="tab"        data-view="users"   role="tab">Зрители</button>
        <button class="tab tab--danger" data-view="zapping" role="tab">🚫 Zapping</button>
    </nav>
</header>
<?php endif; ?>

<main class="container">

    <!-- SEARCH VIEW -->
    <section class="view active" id="view-search">
        <div class="search-bar" id="searchBar">
            <span class="search-icon" aria-hidden="true">🔍</span>
            <input type="text" id="searchInput" placeholder="Введите логин или токен (минимум 3 символа)…" autocomplete="off">
            <span class="clear-x" id="clearBtn">&times;</span>
            <button class="btn btn--primary btn--sm" id="btnSearch">Найти</button>
            <div class="autocomplete" id="acList"></div>
        </div>
        <div id="resultContainer"></div>
    </section>

    <!-- STREAMS VIEW -->
    <section class="view" id="view-streams">
        <div class="view-header">
            <div class="view-title">Активные аллокации <span id="streamsCount" class="text-muted uman-count"></span></div>
            <div class="view-actions">
                <button class="btn btn--success" id="btnReloadStreams">↻ Обновить</button>
            </div>
        </div>
        <div class="card">
            <div class="table-wrap">
                <table class="data" id="streamsTable">
                    <thead>
                        <tr>
                            <th class="sortable" data-col="display_channel_name" data-type="str">Канал <span class="sort-arrow"></span></th>
                            <th class="sortable" data-col="allocation_id" data-type="num">ID <span class="sort-arrow"></span></th>
                            <th class="sortable" data-col="slot" data-type="num">Slot <span class="sort-arrow"></span></th>
                            <th class="sortable" data-col="provider" data-type="str">Provider <span class="sort-arrow"></span></th>
                            <th class="sortable" data-col="cdn_ip" data-type="str">CDN IP <span class="sort-arrow"></span></th>
                            <th class="sortable" data-col="allocated_at" data-type="num">Allocated <span class="sort-arrow"></span></th>
                            <th>Source</th>
                            <th class="text-right">Действия</th>
                        </tr>
                    </thead>
                    <tbody id="streamsBody">
                        <tr><td colspan="8" class="loading">Загрузка…</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <!-- STATS VIEW -->
    <section class="view" id="view-stats">
        <div class="view-header">
            <div class="view-title">Каналы</div>
            <div class="view-actions">
                <button class="btn btn--success" id="btnReloadStats">↻ Обновить</button>
            </div>
        </div>
        <div class="card">
            <div class="table-wrap">
                <table class="data" id="statsTable">
                    <thead>
                        <tr>
                            <th class="sortable" data-col="id" data-type="num">ID <span class="sort-arrow"></span></th>
                            <th class="sortable" data-col="name" data-type="str">Название <span class="sort-arrow"></span></th>
                            <th class="sortable" data-col="online" data-type="num">
                                Online <span class="sort-arrow"></span>
                                <div class="th-filter"><input type="number" id="filterOnline" placeholder="≥"></div>
                            </th>
                            <th class="sortable" data-col="daily" data-type="num">
                                Daily <span class="sort-arrow"></span>
                                <div class="th-filter"><input type="number" id="filterDaily" placeholder="≥"></div>
                            </th>
                        </tr>
                    </thead>
                    <tbody id="statsBody">
                        <tr><td colspan="4" class="loading">Загрузка…</td></tr>
                    </tbody>
                    <tfoot id="statsFoot"></tfoot>
                </table>
            </div>
        </div>
    </section>

    <!-- USERS ONLINE VIEW -->
    <section class="view" id="view-users">
        <div class="view-header">
            <div class="view-title">Зрители онлайн <span id="usersCount" class="text-muted uman-count"></span></div>
            <div class="view-actions">
                <button class="btn btn--success" id="btnReloadUsers">↻ Обновить</button>
            </div>
        </div>
        <div class="card">
            <div class="table-wrap">
                <table class="data" id="usersTable">
                    <thead>
                        <tr>
                            <th class="sortable" data-col="user_name" data-type="str">Token / Login <span class="sort-arrow"></span></th>
                            <th class="sortable" data-col="ip" data-type="str">IP <span class="sort-arrow"></span></th>
                            <th class="sortable" data-col="channel_name" data-type="str">Канал <span class="sort-arrow"></span></th>
                            <th class="sortable" data-col="duration_sec" data-type="num">Длительность <span class="sort-arrow"></span></th>
                            <th class="sortable" data-col="last_seen_ts" data-type="num">Last seen <span class="sort-arrow"></span></th>
                            <th>Device (UA)</th>
                        </tr>
                    </thead>
                    <tbody id="usersBody">
                        <tr><td colspan="6" class="loading">Загрузка…</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <!-- ZAPPING VIEW -->
    <section class="view" id="view-zapping">
        <div class="view-header">
            <div class="view-title">🚫 Zapping — устройства</div>
            <div class="view-actions">
                <button class="btn btn--success" id="btnReloadZapping">↻ Обновить</button>
            </div>
        </div>

        <div class="zapping-grid">
            <div class="card zapping-pane violations">
                <h4>⚠️ С нарушениями</h4>
                <div class="table-wrap">
                    <table class="data">
                        <thead>
                            <tr>
                                <th>Token</th>
                                <th>Session ID</th>
                                <th>Нарушения</th>
                                <th>Бан</th>
                                <th>Действия</th>
                            </tr>
                        </thead>
                        <tbody id="zappingViolationsBody">
                            <tr><td colspan="5" class="loading">Загрузка…</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card zapping-pane blocked">
                <h4>🔒 Заблокированы</h4>
                <div class="table-wrap">
                    <table class="data">
                        <thead>
                            <tr>
                                <th>Token</th>
                                <th>Session ID</th>
                                <th>Причина</th>
                                <th>Осталось</th>
                                <th>Действия</th>
                            </tr>
                        </thead>
                        <tbody id="zappingBlockedBody">
                            <tr><td colspan="5" class="loading">Загрузка…</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>

</main>

<!-- Modal & toasts -->
<div class="modal-overlay" id="modal" role="dialog" aria-modal="true">
    <div class="modal" id="modalDialog"></div>
</div>
<div class="toast-stack" id="toastStack" aria-live="polite" aria-atomic="true"></div>

<script>
"use strict";

/* ============================================================================
   Утилиты: API, форматтеры, escape, toast/modal/confirm.
   ========================================================================== */

const UMAN_API_URL = <?php echo $isEmbed ? "'uman.php'" : "''"; ?>;

const Api = {
    async post(action, params = {}) {
        const fd = new FormData();
        fd.append('action', action);
        for (const [k, v] of Object.entries(params)) {
            if (v !== undefined && v !== null) fd.append(k, String(v));
        }
        const res = await fetch(UMAN_API_URL, { method: 'POST', body: fd, credentials: 'same-origin' });
        if (!res.ok) {
            // пытаемся достать JSON-ошибку, иначе текст
            let body;
            try { body = await res.json(); } catch (_) { body = { error: await res.text() }; }
            throw new Error(body.error || `HTTP ${res.status}`);
        }
        const json = await res.json();
        if (json.ok === false) throw new Error(json.error || 'Server error');
        return json;
    },
};

function esc(value) {
    if (value === null || value === undefined) return '';
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function fmtTs(ts, opts = {}) {
    if (!ts) return '—';
    const d = new Date(Number(ts) * 1000);
    const o = {
        day: '2-digit', month: '2-digit',
        hour: '2-digit', minute: '2-digit', second: '2-digit',
        ...opts,
    };
    return d.toLocaleString('ru-RU', o).replace(',', '');
}
function fmtDate(ts) { return fmtTs(ts, { day: '2-digit', month: '2-digit', year: 'numeric', second: undefined }); }

function fmtDuration(sec) {
    sec = Math.max(0, Number(sec) || 0);
    const h = Math.floor(sec / 3600);
    const m = Math.floor((sec % 3600) / 60);
    const s = sec % 60;
    return `${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`;
}

function fmtTimeLeft(seconds) {
    if (!seconds || seconds <= 0) return 'N/A';
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = seconds % 60;
    if (h > 0) return `${h}ч ${m}м`;
    if (m > 0) return `${m}м ${s}с`;
    return `${s}с`;
}

function shorten(str, n = 24) {
    str = String(str ?? '');
    return str.length > n ? str.slice(0, n) + '…' : str;
}

// no-op: эмодзи-иконки не требуют ре-рендеринга
function refreshIcons() {}

/* ---- Toast ---- */
function toast(message, type = 'info', timeout = 3500) {
    const stack = document.getElementById('toastStack');
    const el = document.createElement('div');
    el.className = `toast toast--${type}`;
    const glyph = type === 'success' ? '✓'
               : type === 'error'   ? '✕'
               : type === 'warning' ? '⚠️'
               : 'ℹ︎';
    el.innerHTML = `<span class="toast-icon">${glyph}</span><span>${esc(message)}</span>`;
    stack.appendChild(el);
    setTimeout(() => {
        el.style.transition = 'opacity 250ms';
        el.style.opacity = '0';
        setTimeout(() => el.remove(), 250);
    }, timeout);
}

/* ---- Modal / confirm ---- */
function modalConfirm({ title = 'Подтверждение', body = '', confirmText = 'OK', confirmVariant = 'danger' }) {
    return new Promise((resolve) => {
        const overlay = document.getElementById('modal');
        const dialog  = document.getElementById('modalDialog');
        dialog.innerHTML = `
            <div class="modal-header">⚠️ ${esc(title)}</div>
            <div class="modal-body">${body}</div>
            <div class="modal-footer">
                <button class="btn" id="modalCancel">Отмена</button>
                <button class="btn btn--${confirmVariant}" id="modalOk">${esc(confirmText)}</button>
            </div>`;
        overlay.classList.add('active');

        const close = (val) => {
            overlay.classList.remove('active');
            overlay.removeEventListener('click', onOverlay);
            document.removeEventListener('keydown', onKey);
            resolve(val);
        };
        const onOverlay = (e) => { if (e.target === overlay) close(false); };
        const onKey = (e) => { if (e.key === 'Escape') close(false); };
        overlay.addEventListener('click', onOverlay);
        document.addEventListener('keydown', onKey);
        document.getElementById('modalCancel').onclick = () => close(false);
        document.getElementById('modalOk').onclick = () => close(true);
    });
}

/* ============================================================================
   Глобальная навигация по табам
   ========================================================================== */

const UMAN_TAB_SEL = <?php echo $isEmbed ? "'.uman-tab'" : "'.tab'"; ?>;

const App = {
    currentView: 'search',
    init() {
        document.querySelectorAll(UMAN_TAB_SEL).forEach(btn => {
            btn.addEventListener('click', () => this.switchView(btn.dataset.view));
        });
        Search.init();
        Streams.init();
        Stats.init();
        Users.init();
        Zapping.init();
        refreshIcons();
    },
    switchView(name) {
        this.currentView = name;
        document.querySelectorAll('.view').forEach(v => v.classList.toggle('active', v.id === `view-${name}`));
        document.querySelectorAll(UMAN_TAB_SEL).forEach(t => t.classList.toggle('active', t.dataset.view === name));
        switch (name) {
            case 'streams': Streams.load(); break;
            case 'stats':   Stats.loadIfEmpty(); break;
            case 'users':   Users.load(); break;
            case 'zapping': Zapping.load(); break;
        }
    },
};

/* ============================================================================
   Универсальная сортировка таблиц данных
   ========================================================================== */

class TableSorter {
    constructor(tableEl, storageKey) {
        this.table = tableEl;
        this.key = storageKey;
        this.col = null;
        this.dir = 'desc';
        this.type = 'str';
        this._restore();
        tableEl.querySelectorAll('thead th.sortable').forEach(th => {
            th.addEventListener('click', () => {
                const col = th.dataset.col;
                const type = th.dataset.type || 'str';
                if (this.col === col) {
                    this.dir = this.dir === 'asc' ? 'desc' : 'asc';
                } else {
                    this.col = col;
                    this.type = type;
                    this.dir = 'asc';
                }
                this._save();
                this._renderHeaders();
                this.onChange?.();
            });
        });
        this._renderHeaders();
    }
    _save() {
        try { localStorage.setItem(this.key, JSON.stringify({ col: this.col, dir: this.dir, type: this.type })); } catch (_) {}
    }
    _restore() {
        try {
            const raw = localStorage.getItem(this.key);
            if (raw) {
                const o = JSON.parse(raw);
                this.col = o.col; this.dir = o.dir; this.type = o.type;
            }
        } catch (_) {}
    }
    _renderHeaders() {
        this.table.querySelectorAll('thead th.sortable').forEach(th => {
            th.classList.remove('sort-asc', 'sort-desc');
            if (th.dataset.col === this.col) {
                th.classList.add(this.dir === 'asc' ? 'sort-asc' : 'sort-desc');
            }
        });
    }
    apply(rows) {
        if (!this.col) return rows;
        const k = this.col, t = this.type, dir = this.dir === 'asc' ? 1 : -1;
        return [...rows].sort((a, b) => {
            const x = a[k], y = b[k];
            if (t === 'num') return ((+x || 0) - (+y || 0)) * dir;
            return String(x ?? '').localeCompare(String(y ?? ''), 'ru') * dir;
        });
    }
}

/* ============================================================================
   Поиск пользователя
   ========================================================================== */

const Search = {
    input: null, ac: null, clearX: null, bar: null, container: null,
    debounce: null,
    init() {
        this.input = document.getElementById('searchInput');
        this.ac = document.getElementById('acList');
        this.clearX = document.getElementById('clearBtn');
        this.bar = document.getElementById('searchBar');
        this.container = document.getElementById('resultContainer');

        this.input.addEventListener('input', () => this._onInput());
        this.input.addEventListener('keydown', (e) => { if (e.key === 'Enter') this.execute(); });
        document.getElementById('btnSearch').addEventListener('click', () => this.execute());
        this.clearX.addEventListener('click', () => this._clear());
        document.addEventListener('click', (e) => {
            if (!this.bar.contains(e.target)) this.ac.classList.remove('open');
        });
    },
    _onInput() {
        const v = this.input.value.trim();
        clearTimeout(this.debounce);
        this.bar.classList.toggle('has-value', v.length > 0);
        if (v.length < 3) {
            this.ac.classList.remove('open');
            if (!v) this.container.innerHTML = '';
            return;
        }
        this.debounce = setTimeout(async () => {
            try {
                const data = await Api.post('search_users', { term: v });
                this._renderAc(data.users || []);
            } catch (e) {
                this.ac.classList.remove('open');
                console.error('search_users failed:', e);
                toast(`Поиск не удался: ${e.message}`, 'error');
            }
        }, 250);
    },
    _renderAc(users) {
        if (!users.length) { this.ac.classList.remove('open'); return; }
        this.ac.innerHTML = users.map(u => `<div class="ac-item" data-user="${esc(u)}">${esc(u)}</div>`).join('');
        this.ac.classList.add('open');
        this.ac.querySelectorAll('.ac-item').forEach(el => {
            el.addEventListener('click', () => {
                this.input.value = el.dataset.user;
                this.ac.classList.remove('open');
                this.execute(el.dataset.user);
            });
        });
    },
    _clear() {
        this.input.value = '';
        this.ac.classList.remove('open');
        this.container.innerHTML = '';
        this.bar.classList.remove('has-value');
    },
    async execute(value) {
        const v = value || this.input.value.trim();
        if (v.length < 3) return;
        this.ac.classList.remove('open');
        this.container.innerHTML = '<div class="card"><div class="loading">Загрузка…</div></div>';
        try {
            const data = await Api.post('check_input', { input: v });
            this.render(data);
        } catch (e) {
            this.container.innerHTML = `<div class="card"><div class="err">${esc(e.message)}</div></div>`;
        }
    },
    render(data) {
        const u = data.user;
        const sessions = data.sessions || [];
        const verdictClass = u.is_access_allowed ? 'allow' : 'deny';
        const verdictTitle = u.is_access_allowed ? 'ДОСТУП РАЗРЕШЕН' : 'ДОСТУП ЗАПРЕЩЕН';
        const verdictHint  = u.is_access_allowed ? 'Нажмите, чтобы заблокировать' : 'Нажмите, чтобы разрешить';

        const expireStr = u.expire_ts ? fmtDate(u.expire_ts) : '—';
        const statusStr = u.status || 'OFF';

        let html = `
            <div class="card user-card">
                <div class="card-header">
                    <div class="user-name">${esc(u.name)}</div>
                    <div class="token-chip">${esc(u.token)}</div>
                </div>

                <div class="info-grid">
                    <div class="info-item"><span class="lbl">Redis статус</span><span class="val">${esc(statusStr)}</span></div>
                    <div class="info-item"><span class="lbl">Дата окончания</span><span class="val">${esc(expireStr)}</span></div>
                    <div class="info-item"><span class="lbl">TTL (сек)</span><span class="val mono">${u.ttl_sec}</span></div>
                </div>

                <div class="access-bar">
                    <button class="btn-verdict ${verdictClass}" data-token="${esc(u.token)}" id="btnVerdict">
                        <span>${verdictTitle}</span>
                        <small>${verdictHint}</small>
                    </button>
                </div>

                <div class="section-title">Устройства (${sessions.length})</div>`;

        if (!sessions.length) {
            html += `<div class="empty">Нет данных об активности</div>`;
        } else {
            html += `<div class="sessions-list">`;
            for (const s of sessions) {
                const stateClass = s.is_banned ? 'is-banned' : (s.is_online ? 'is-online' : 'is-offline');
                const stateText  = s.is_banned ? 'BANNED' : (s.is_online ? 'ONLINE' : 'OFFLINE');
                const banBtn = s.is_banned
                    ? `<button class="btn btn--success btn--sm" data-action="unban" data-hash="${esc(s.hash)}">Разбанить</button>`
                    : `<button class="btn btn--danger  btn--sm" data-action="ban"   data-hash="${esc(s.hash)}">Забанить</button>`;
                html += `
                    <div class="session ${stateClass}">
                        <div class="session-head">
                            <div class="channel-name">
                                <span>${esc(s.channel_name)}</span>
                                <span class="channel-id">ID: ${s.channel_id}</span>
                            </div>
                            <span class="session-status"><span class="status-dot ${s.is_online && !s.is_banned ? 'status-dot--live' : ''}"></span>${stateText}</span>
                        </div>
                        <div class="session-body">
                            <div class="field"><small>IP устройства:</small><b>${esc(s.ip)}</b></div>
                            <div class="field"><small>IP сервера:</small><b>${esc(s.server)}</b></div>
                            <div class="field"><small>На канале:</small><b class="mono">${fmtDuration(s.duration_sec)}</b></div>
                            <div class="field"><small>UA:</small><b title="${esc(s.ua)}" class="shorten">${esc(s.ua)}</b></div>
                        </div>
                        <div class="session-foot">
                            <div>
                                <div class="last-seen">Последний запрос: <b>${fmtTs(s.last_seen_ts)}</b></div>
                                <div class="hash">${esc(s.hash)}</div>
                            </div>
                            <div>${banBtn}</div>
                        </div>
                    </div>`;
            }
            html += `</div>`;
        }

        html += `</div>`;
        this.container.innerHTML = html;
        refreshIcons();

        // вешаем обработчики
        this.container.querySelector('#btnVerdict')?.addEventListener('click', () => this._toggleStatus(u.token));
        this.container.querySelectorAll('button[data-action]').forEach(btn => {
            btn.addEventListener('click', () => this._toggleBan(u.token, btn.dataset.hash, btn.dataset.action));
        });
    },
    async _toggleStatus(token) {
        if (!await modalConfirm({
            title: 'Изменить статус доступа?',
            body: 'Будет переключён глобальный статус пользователя в Redis (active ↔ blocked).',
            confirmText: 'Переключить',
            confirmVariant: 'primary',
        })) return;
        try {
            await Api.post('toggle_status', { token });
            toast('Статус обновлён', 'success');
            this.execute(token);
        } catch (e) {
            toast(`Ошибка: ${e.message}`, 'error');
        }
    },
    async _toggleBan(token, hash, mode) {
        const isBan = mode === 'ban';
        const ok = await modalConfirm({
            title: isBan ? 'Заблокировать устройство?' : 'Разблокировать устройство?',
            body: `<div class="modal-info"><div><span>Hash:</span> <span class="mono">${esc(hash)}</span></div></div>`,
            confirmText: isBan ? 'Заблокировать' : 'Разблокировать',
            confirmVariant: isBan ? 'danger' : 'success',
        });
        if (!ok) return;
        try {
            await Api.post('toggle_ban_device', { token, hash, mode });
            toast(isBan ? 'Устройство заблокировано' : 'Устройство разблокировано', 'success');
            this.execute(token);
        } catch (e) {
            toast(`Ошибка: ${e.message}`, 'error');
        }
    },
};

/* ============================================================================
   Streams (аллокации)
   ========================================================================== */

const Streams = {
    body: null, table: null, sorter: null, count: null, data: [],
    init() {
        this.table = document.getElementById('streamsTable');
        this.body  = document.getElementById('streamsBody');
        this.count = document.getElementById('streamsCount');
        this.sorter = new TableSorter(this.table, 'streams_sort');
        this.sorter.onChange = () => this.render();
        document.getElementById('btnReloadStreams').addEventListener('click', () => this.load());
    },
    async load() {
        this.body.innerHTML = `<tr><td colspan="8" class="loading">Загрузка…</td></tr>`;
        try {
            const data = await Api.post('get_streams');
            this.data = data.streams || [];
            this.count.textContent = `· ${data.total} активных`;
            this.render();
        } catch (e) {
            this.body.innerHTML = `<tr><td colspan="8" class="err">${esc(e.message)}</td></tr>`;
        }
    },
    render() {
        if (!this.data.length) {
            this.body.innerHTML = `<tr><td colspan="8" class="empty">Нет активных потоков</td></tr>`;
            return;
        }
        const sorted = this.sorter.apply(this.data);
        this.body.innerHTML = sorted.map(s => this._row(s)).join('');
        refreshIcons();
        // обработчики
        this.body.querySelectorAll('button[data-act="check"]').forEach(b => {
            b.addEventListener('click', () => this._check(b));
        });
        this.body.querySelectorAll('button[data-act="delete"]').forEach(b => {
            b.addEventListener('click', () => this._confirmDelete(b));
        });
    },
    _row(s) {
        const dotCls = s.is_live ? 'status-dot--live' : (s.is_archive ? 'status-dot--archive' : 'status-dot--unknown');
        const dotTitle = s.is_live ? 'Прямой эфир' : (s.is_archive ? 'Архив' : 'Статус не указан');
        const qInfo = s.quality ? `<div class="ch-meta">📺 ${esc(s.quality)}</div>` : '';
        const sInfo = s.switch_count > 0 ? `<div class="ch-meta ch-meta--warning">🔄 Переключений: ${s.switch_count}</div>` : '';
        return `
            <tr id="stream-row-${esc(s.allocation_id)}">
                <td class="cell-channel">
                    <div class="ch-row">
                        <span class="status-dot ${dotCls}" title="${dotTitle}"></span>
                        <strong>${esc(s.display_channel_name)}</strong>
                    </div>
                    ${qInfo}
                    ${sInfo}
                    <div class="ch-meta">📡 Источник: ${esc(s.root_name)} <span class="text-muted">(ID: ${s.root_id})</span></div>
                </td>
                <td class="num">${esc(s.allocation_id)}</td>
                <td class="num">${esc(s.slot)}</td>
                <td>${esc(s.provider)}</td>
                <td class="num">${esc(s.cdn_ip)}</td>
                <td>${fmtTs(s.allocated_at, { day:'2-digit', month:'2-digit', second: undefined })}</td>
                <td>${s.source_url ? `<a class="user-link" href="${esc(s.source_url)}" target="_blank" rel="noopener" title="${esc(s.source_url)}">🔗 Link</a>` : '—'}</td>
                <td class="action-cell">
                    <button class="btn btn--icon btn--sm" data-act="check" title="Проверить процесс (SSH)"
                        data-id="${esc(s.allocation_id)}" data-cdn="${esc(s.cdn_ip)}" data-root="${esc(s.root_id)}">⚡</button>
                    <button class="btn btn--icon btn--sm btn--danger" data-act="delete" title="Остановить (Publish Stop)"
                        data-id="${esc(s.allocation_id)}" data-slot="${esc(s.slot)}" data-prov="${esc(s.provider)}" data-token="${esc(s.token)}" data-name="${esc(s.display_channel_name)}">✕</button>
                </td>
            </tr>`;
    },
    async _check(btn) {
        const row = document.getElementById('stream-row-' + btn.dataset.id);
        btn.disabled = true;
        btn.style.opacity = '0.5';
        try {
            const data = await Api.post('check_stream_alive', {
                cid: btn.dataset.id,
                cdn: btn.dataset.cdn,
                root_id: btn.dataset.root || btn.dataset.id,
            });
            row.classList.remove('row-alive', 'row-dead');
            row.classList.add(data.alive ? 'row-alive' : 'row-dead');
            btn.style.color = data.alive ? 'var(--success)' : 'var(--danger)';
            toast(data.alive ? `ALIVE · root ${data.root_id}` : `DEAD · root ${data.root_id}`,
                  data.alive ? 'success' : 'error');
        } catch (e) {
            toast(`Ошибка: ${e.message}`, 'error');
        } finally {
            btn.disabled = false;
            btn.style.opacity = '';
        }
    },
    async _confirmDelete(btn) {
        const id    = btn.dataset.id;
        const slot  = btn.dataset.slot;
        const prov  = btn.dataset.prov;
        const token = btn.dataset.token;
        const name  = btn.dataset.name;
        const ok = await modalConfirm({
            title: 'Остановить поток?',
            body: `Будет отправлена команда <b>PUBLISH STOP</b> и аллокация удалена из Redis.
                <div class="modal-info">
                    <div><span>Канал:</span> <b>${esc(name)}</b></div>
                    <div><span>ID:</span> <span class="mono">${esc(id)}</span></div>
                    <div><span>Slot:</span> <span class="mono">${esc(slot)}</span></div>
                    <div><span>Provider:</span> <b>${esc(prov)}</b></div>
                </div>`,
            confirmText: 'Остановить',
            confirmVariant: 'danger',
        });
        if (!ok) return;
        await this._delete(id, slot, prov, token);
    },
    async _delete(id, slot, prov, token) {
        const row = document.getElementById('stream-row-' + id);
        if (row) row.style.opacity = '0.4';
        try {
            await Api.post('delete_stream', { cid: id, slot, provider: prov, token });
            toast('Стоп-команда отправлена', 'success');
            if (row) {
                row.style.transition = 'opacity 400ms';
                row.style.opacity = '0';
                setTimeout(() => {
                    row.remove();
                    this.data = this.data.filter(x => x.allocation_id !== id);
                    this.count.textContent = `· ${this.data.length} активных`;
                    if (!this.data.length) {
                        this.body.innerHTML = `<tr><td colspan="8" class="empty">Нет активных потоков</td></tr>`;
                    }
                }, 400);
            }
        } catch (e) {
            if (row) row.style.opacity = '1';
            toast(`Ошибка: ${e.message}`, 'error');
        }
    },
};

/* ============================================================================
   Stats
   ========================================================================== */

const Stats = {
    body: null, foot: null, sorter: null, data: [], totals: null,
    init() {
        this.body = document.getElementById('statsBody');
        this.foot = document.getElementById('statsFoot');
        this.sorter = new TableSorter(document.getElementById('statsTable'), 'stats_sort');
        this.sorter.onChange = () => this.render();
        document.getElementById('btnReloadStats').addEventListener('click', () => this.load());
        for (const id of ['filterOnline', 'filterDaily']) {
            const el = document.getElementById(id);
            el.addEventListener('input', () => this.render());
            // не даём клику в инпут триггерить сортировку колонки
            el.addEventListener('click', (e) => e.stopPropagation());
        }
    },
    loadIfEmpty() { if (!this.data.length) this.load(); },
    async load() {
        this.body.innerHTML = `<tr><td colspan="4" class="loading">Загрузка…</td></tr>`;
        this.foot.innerHTML = '';
        try {
            const data = await Api.post('get_stats');
            this.data   = data.rows || [];
            this.totals = data.totals || { online: 0, daily: 0 };
            this.render();
        } catch (e) {
            this.body.innerHTML = `<tr><td colspan="4" class="err">${esc(e.message)}</td></tr>`;
        }
    },
    render() {
        if (!this.data.length) {
            this.body.innerHTML = `<tr><td colspan="4" class="empty">Нет данных</td></tr>`;
            this.foot.innerHTML = '';
            return;
        }
        const minOnl = parseInt(document.getElementById('filterOnline').value, 10) || 0;
        const minDay = parseInt(document.getElementById('filterDaily').value,  10) || 0;

        let rows = this.data.filter(r => r.online >= minOnl && r.daily >= minDay);
        rows = this.sorter.apply(rows);

        this.body.innerHTML = rows.map(r => `
            <tr>
                <td class="num">${r.id}</td>
                <td>${esc(r.name)}</td>
                <td class="num online-cell">${r.online}</td>
                <td class="num">${r.daily}</td>
            </tr>`).join('');
        this.foot.innerHTML = `
            <tr>
                <td colspan="2" class="text-right">ИТОГО:</td>
                <td class="num online-cell">${this.totals.online}</td>
                <td class="num">${this.totals.daily}</td>
            </tr>`;
    },
};

/* ============================================================================
   Online users
   ========================================================================== */

const Users = {
    body: null, count: null, sorter: null, data: [],
    init() {
        this.body  = document.getElementById('usersBody');
        this.count = document.getElementById('usersCount');
        this.sorter = new TableSorter(document.getElementById('usersTable'), 'users_sort');
        this.sorter.onChange = () => this.render();
        document.getElementById('btnReloadUsers').addEventListener('click', () => this.load());
    },
    async load() {
        this.body.innerHTML = `<tr><td colspan="6" class="loading">Загрузка…</td></tr>`;
        try {
            const data = await Api.post('get_online_users');
            this.data = data.users || [];
            this.count.textContent = `· ${data.total} зрителей`;
            this.render();
        } catch (e) {
            this.body.innerHTML = `<tr><td colspan="6" class="err">${esc(e.message)}</td></tr>`;
        }
    },
    render() {
        if (!this.data.length) {
            this.body.innerHTML = `<tr><td colspan="6" class="empty">Нет активных зрителей</td></tr>`;
            return;
        }
        const sorted = this.sorter.apply(this.data);
        this.body.innerHTML = sorted.map(u => this._row(u)).join('');
        this.body.querySelectorAll('a.user-link[data-token]').forEach(a => {
            a.addEventListener('click', (e) => {
                e.preventDefault();
                App.switchView('search');
                document.getElementById('searchInput').value = a.dataset.token;
                Search.execute(a.dataset.token);
            });
        });
    },
    _row(u) {
        const dot = u.is_live ? `<span class="status-dot status-dot--live"   title="Прямой эфир"></span>`
                  : u.is_archive ? `<span class="status-dot status-dot--archive" title="Архив"></span>` : '';
        const tokenShort = shorten(u.token, 14);
        return `
            <tr>
                <td>
                    <a class="user-link" data-token="${esc(u.token)}">
                        <b>${esc(u.user_name || '—')}</b><br>
                        <small class="text-muted mono">${esc(tokenShort)}</small>
                    </a>
                </td>
                <td class="mono">${esc(u.ip)}</td>
                <td>${dot}${esc(u.channel_name)}</td>
                <td class="num">${fmtDuration(u.duration_sec)}</td>
                <td>${fmtTs(u.last_seen_ts, { day: undefined, month: undefined })}</td>
                <td title="${esc(u.ua)}"><span class="shorten">${esc(u.ua)}</span></td>
            </tr>`;
    },
};

/* ============================================================================
   Zapping
   ========================================================================== */

const Zapping = {
    vBody: null, bBody: null,
    init() {
        this.vBody = document.getElementById('zappingViolationsBody');
        this.bBody = document.getElementById('zappingBlockedBody');
        document.getElementById('btnReloadZapping').addEventListener('click', () => this.load());
    },
    async load() {
        this.vBody.innerHTML = `<tr><td colspan="5" class="loading">Загрузка…</td></tr>`;
        this.bBody.innerHTML = `<tr><td colspan="5" class="loading">Загрузка…</td></tr>`;

        Api.post('get_zapping_devices').then(data => {
            const list = data.devices || [];
            if (!list.length) {
                this.vBody.innerHTML = `<tr><td colspan="5" class="empty">Нет устройств с нарушениями</td></tr>`;
                return;
            }
            this.vBody.innerHTML = list.map(d => {
                const cls = d.violations >= 5 ? 'badge--danger' : d.violations >= 2 ? 'badge--warning' : 'badge--info';
                const sess = shorten(d.session_id, 16);
                const tok  = shorten(d.token, 12);
                const banCell = d.is_banned
                    ? `<span class="badge badge--danger">${esc(fmtTimeLeft(d.ban_time_left))}</span>`
                    : `<span class="badge badge--success">Нет</span>`;
                const action = d.is_banned
                    ? `<button class="btn btn--success btn--sm" data-act="unblock" data-token="${esc(d.token)}" data-sess="${esc(d.session_id)}">Разблокировать</button>`
                    : `<button class="btn btn--sm" data-act="reset" data-token="${esc(d.token)}" data-sess="${esc(d.session_id)}">Сбросить</button>`;
                return `<tr>
                    <td><span class="mono">${esc(tok)}</span></td>
                    <td><span class="mono">${esc(sess)}</span></td>
                    <td><span class="badge ${cls}">${d.violations}</span></td>
                    <td>${banCell}</td>
                    <td>${action}</td>
                </tr>`;
            }).join('');
            this._wire(this.vBody);
        }).catch(e => {
            this.vBody.innerHTML = `<tr><td colspan="5" class="err">${esc(e.message)}</td></tr>`;
        });

        Api.post('get_zapping_blocked').then(data => {
            const list = data.devices || [];
            if (!list.length) {
                this.bBody.innerHTML = `<tr><td colspan="5" class="empty">Нет заблокированных устройств</td></tr>`;
                return;
            }
            this.bBody.innerHTML = list.map(d => {
                const sess = shorten(d.session_id, 16);
                const tok  = shorten(d.token, 12);
                return `<tr>
                    <td><span class="mono">${esc(tok)}</span></td>
                    <td><span class="mono">${esc(sess)}</span></td>
                    <td><span class="badge badge--danger">${esc(d.reason)}</span></td>
                    <td><span class="badge badge--warning">${esc(fmtTimeLeft(d.ban_time_left))}</span></td>
                    <td><button class="btn btn--success btn--sm" data-act="unblock" data-token="${esc(d.token)}" data-sess="${esc(d.session_id)}">Разблокировать</button></td>
                </tr>`;
            }).join('');
            this._wire(this.bBody);
        }).catch(e => {
            this.bBody.innerHTML = `<tr><td colspan="5" class="err">${esc(e.message)}</td></tr>`;
        });
    },
    _wire(scope) {
        scope.querySelectorAll('button[data-act]').forEach(b => {
            b.addEventListener('click', () => this._action(b.dataset.act, b.dataset.token, b.dataset.sess));
        });
    },
    async _action(kind, token, sessionId) {
        const isReset = kind === 'reset';
        const ok = await modalConfirm({
            title: isReset ? 'Сбросить нарушения?' : 'Разблокировать устройство?',
            body: `<div class="modal-info">
                    <div><span>Token:</span> <span class="mono">${esc(token)}</span></div>
                    <div><span>Session:</span> <span class="mono">${esc(shorten(sessionId, 32))}</span></div>
                </div>`,
            confirmText: isReset ? 'Сбросить' : 'Разблокировать',
            confirmVariant: isReset ? 'primary' : 'success',
        });
        if (!ok) return;
        try {
            await Api.post('unblock_zapping_device', { token, session_id: sessionId });
            toast(isReset ? 'Нарушения сброшены' : 'Устройство разблокировано', 'success');
            this.load();
        } catch (e) {
            toast(`Ошибка: ${e.message}`, 'error');
        }
    },
};

/* ============================================================================
   Старт
   ========================================================================== */

<?php if ($isEmbed): ?>
App.init();
</script>
<?php else: ?>
document.addEventListener('DOMContentLoaded', () => App.init());
</script>
</body>
</html>
<?php endif; ?>
