<?php
// ==========================================
// 1. КОНФИГУРАЦИЯ И БЭКЕНД
// ==========================================
include_once("config.php");

// Проверка сессии
checkLoggedIn("yes");
if($_SESSION['a'] != 1) exit();

$dbConfig = [
    'host' => 'localhost',
    'name' => 'mpol',
    'user' => 'root',
    'pass' => 'uiF5bcaw8'
];

$redisConfig = [
    'host' => '45.9.73.98',
    'port' => 6379,
    'pass' => 'qw34rfvgtU9snaWE'
];

$sshUser = 'root';

$serversMap = [
    '51.254.135.10' => [
        'pass' => 'bossismyname', 
        'port' => 45822
    ],
    '45.90.217.114' => [
        'pass' => 'uikjm9', 
        'port' => 22
    ],
    '83.136.233.101' => [
        'pass' => 'bossismyname', 
        'port' => 45822
    ],
    '103.213.249.5' => [
        'pass' => 'SSK4w6DfSGzk', 
        'port' => 45822
    ],
    '77.110.104.120' => [
        'pass' => 'y677Wd2jEdPQ', 
        'port' => 45822
    ],
    '84.252.101.140' => [
        'pass' => 'SSK4w6DfSGzk', 
        'port' => 45822
    ],
];

// Подключение к БД
try {
    $dsn = "mysql:host={$dbConfig['host']};dbname={$dbConfig['name']};charset=utf8";
    $pdo = new PDO($dsn, $dbConfig['user'], $dbConfig['pass']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    if (isset($_POST['action'])) die(json_encode(['error' => 'Ошибка БД']));
}

// ------------------------------------------
// AJAX ОБРАБОТЧИКИ
// ------------------------------------------

if (isset($_POST['action'])) {

    function getRedis($cfg) {
        $r = new TinyRedis();
        $r->connect($cfg['host'], $cfg['port']);
        if (!empty($cfg['pass'])) $r->execute(['AUTH', $cfg['pass']]);
        return $r;
    }

    // 1. Поиск логинов
    if ($_POST['action'] === 'search_users') {
        $term = trim($_POST['term'] ?? '');
        if (mb_strlen($term) >= 3) {
            $stmt = $pdo->prepare("SELECT user FROM accounts WHERE islocal = 1 AND user LIKE :term LIMIT 10");
            $stmt->execute(['term' => "%$term%"]);
            echo json_encode($stmt->fetchAll(PDO::FETCH_COLUMN));
        } else {
            echo json_encode([]);
        }
        exit;
    }

    // 2. Переключение статуса (БАН / РАЗБАН ПОЛЬЗОВАТЕЛЯ)
    if ($_POST['action'] === 'toggle_status') {
        $token = $_POST['token'] ?? '';
        if (!$token) die('No token');
        try {
            $redis = getRedis($redisConfig);
            $current = $redis->execute(['GET', "user:{$token}:status"]);
            $newStatus = ($current === 'active') ? 'blocked' : 'active';
            $redis->execute(['SET', "user:{$token}:status", $newStatus]);
            echo "OK";
        } catch (Exception $e) { echo "ERROR"; }
        exit;
    }

    // 3. БАН/РАЗБАН УСТРОЙСТВА
    if ($_POST['action'] === 'toggle_ban_device') {
        $token = $_POST['token'] ?? '';
        $hash = $_POST['hash'] ?? '';
        $mode = $_POST['mode'] ?? 'ban';

        if (!$token || !$hash) die('Error params');

        try {
            $redis = getRedis($redisConfig);
            $key = "blocked:devices:{$token}";
            if ($mode === 'ban') {
                $redis->execute(['SADD', $key, $hash]);
                $redis->execute(['ZREM', "online:users:{$token}", $hash]);
                $redis->execute(['HDEL', "online:users:{$token}:meta", $hash]);
            } else {
                $redis->execute(['SREM', $key, $hash]);
            }
            echo "OK";
        } catch (Exception $e) { echo "Error"; }
        exit;
    }

    // 3.1 РАЗБАН УСТРОЙСТВА ПОСЛЕ ZAPPING (с указанием причины)
    if ($_POST['action'] === 'unblock_zapping_device') {
        $token = $_POST['token'] ?? '';
        $session_id = $_POST['session_id'] ?? '';

        if (!$token || !$session_id) die('Error params');

        try {
            $redis = getRedis($redisConfig);
            $redis->execute(['SREM', "blocked:devices:{$token}", $session_id]);
            $redis->execute(['DEL', "blocked:devices:{$token}:info:{$session_id}"]);
            $redis->execute(['DEL', "blocked:devices:{$token}:reason:{$session_id}"]);
            $redis->execute(['DEL', "zap:ban:{$session_id}"]);
            $redis->execute(['DEL', "zap:violations:{$session_id}"]);
            $redis->execute(['DEL', "zap:last_violation:{$session_id}"]);

            echo "OK";
        } catch (Exception $e) { echo "Error: " . $e->getMessage(); }
        exit;
    }

    // 3.2 ПОЛУЧЕНИЕ СПИСКА УСТРОЙСТВ С ZAPPING НАРУШЕНИЯМИ
    if ($_POST['action'] === 'get_zapping_devices') {
        try {
            $redis = getRedis($redisConfig);

            $deviceKeys = $redis->execute(['KEYS', 'zap:device:*']);
            $devices = [];

            if (!empty($deviceKeys) && is_array($deviceKeys)) {
                foreach ($deviceKeys as $key) {
                    $infoJson = $redis->execute(['GET', $key]);
                    if ($infoJson) {
                        $info = json_decode($infoJson, true);
                        $sessionId = str_replace('zap:device:', '', $key);
                        $violations = (int)$redis->execute(['GET', "zap:violations:{$sessionId}"]);
                        $banTtl = $redis->execute(['TTL', "zap:ban:{$sessionId}"]);
                        $isBanned = $banTtl > 0;
                        
                        if ($violations > 0 || $isBanned) {
                            $devices[] = [
                                'session_id' => $sessionId,
                                'token' => $info['token'] ?? '-',
                                'ip' => $info['ip'] ?? '-',
                                'user_agent' => $info['user_agent'] ?? '-',
                                'channel' => $info['channel'] ?? '-',
                                'violations' => $violations,
                                'is_banned' => $isBanned,
                                'ban_time_left' => $isBanned ? $banTtl : null,
                                'last_violation' => $info['last_violation'] ?? null,
                                'last_seen' => $info['last_seen'] ?? null
                            ];
                        }
                    }
                }
            }

            usort($devices, function($a, $b) {
                return $b['violations'] <=> $a['violations'];
            });

            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['devices' => $devices, 'total' => count($devices)]);

        } catch (Exception $e) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => $e->getMessage(), 'devices' => [], 'total' => 0]);
        }
        exit;
    }

    // 3.3 ПОЛУЧЕНИЕ СПИСКА ЗАБЛОКИРОВАННЫХ УСТРОЙСТВ (ZAPPING)
    if ($_POST['action'] === 'get_zapping_blocked') {
        try {
            $redis = getRedis($redisConfig);

            $blockedKeys = $redis->execute(['KEYS', 'blocked:devices:*:info:*']);
            $blockedDevices = [];

            if (!empty($blockedKeys) && is_array($blockedKeys)) {
                foreach ($blockedKeys as $key) {
                    $infoJson = $redis->execute(['GET', $key]);
                    if ($infoJson) {
                        $info = json_decode($infoJson, true);
                        if (preg_match('/blocked:devices:([^:]+):info:(.+)/', $key, $matches)) {
                            $token = $matches[1];
                            $sessionId = $matches[2];
                            $reason = $redis->execute(['GET', "blocked:devices:{$token}:reason:{$sessionId}"]);
                            $banTtl = $redis->execute(['TTL', $key]);
                            
                            if ($reason === 'anti-zapping') {
                                $blockedDevices[] = [
                                    'token' => $token,
                                    'session_id' => $sessionId,
                                    'ip' => $info['ip'] ?? '-',
                                    'user_agent' => $info['user_agent'] ?? '-',
                                    'reason' => $reason,
                                    'violations' => $info['violations'] ?? 0,
                                    'ban_time_left' => $banTtl > 0 ? $banTtl : null
                                ];
                            }
                        }
                    }
                }
            }

            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['devices' => $blockedDevices, 'total' => count($blockedDevices)]);

        } catch (Exception $e) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => $e->getMessage(), 'devices' => [], 'total' => 0]);
        }
        exit;
    }

    // 4. ПОИСК И ВЫВОД ИНФОРМАЦИИ ПО ЮЗЕРУ
    if ($_POST['action'] === 'check_input') {
        $inputVal = trim($_POST['input'] ?? '');
        $stmt = $pdo->prepare("SELECT user, token FROM accounts WHERE user = :v OR token = :v LIMIT 1");
        $stmt->execute(['v' => $inputVal]);
        $account = $stmt->fetch();
        if (!$account) { echo "<div class='error-box'>Пользователь не найден</div>"; exit; }

        $token = $account['token'];
        $userName = $account['user'];

        ob_start();
        try {
            $redis = getRedis($redisConfig);
            
            // --- ЗАГРУЖАЕМ АЛЛОКАЦИИ (ЧТОБЫ УЗНАТЬ РЕАЛЬНЫЙ CDN) ---
            $rawAlloc = $redis->execute(['HGETALL', 'channel_allocations']);
            $allocMap = []; // [channel_id => cdn_ip]
            if (!empty($rawAlloc) && is_array($rawAlloc)) {
                for ($i = 0; $i < count($rawAlloc); $i += 2) {
                    $cId = $rawAlloc[$i];
                    $cData = json_decode($rawAlloc[$i+1], true);
                    if ($cData && isset($cData['cdn_ip'])) {
                        $allocMap[$cId] = $cData['cdn_ip'];
                    }
                }
            }

            $status = $redis->execute(['GET', "user:{$token}:status"]);
            $expire = $redis->execute(['GET', "user:{$token}:expire"]);
            $ttl = $redis->execute(['TTL', "user:{$token}:status"]);
            
            $bannedHashes = $redis->execute(['SMEMBERS', "blocked:devices:{$token}"]);
            $bannedMap = [];
            if (!empty($bannedHashes)) {
                foreach ($bannedHashes as $h) $bannedMap[$h] = true;
            }

            $rawOnline = $redis->execute(['HGETALL', "online:users:{$token}:meta"]);
            $onlineKeys = [];
            if (!empty($rawOnline) && is_array($rawOnline)) {
                for ($i = 0; $i < count($rawOnline); $i += 2) $onlineKeys[$rawOnline[$i]] = true;
            }

            $rawHistory = $redis->execute(['HGETALL', "history:users:{$token}"]);

            $parseRedisData = function($raw) {
                $result = [];
                if (!empty($raw) && is_array($raw)) {
                    for ($i = 0; $i < count($raw); $i += 2) {
                        $hash = $raw[$i];
                        $json = json_decode($raw[$i+1], true);
                        if ($json) $result[$hash] = $json;
                    }
                }
                return $result;
            };

            $historyData = $parseRedisData($rawHistory);
            $onlineDataOnly = $parseRedisData($rawOnline);
            
            $allSessionsMap = array_merge($historyData, $onlineDataOnly);
            $sessions = [];
            $chIds = [];

            foreach ($allSessionsMap as $hash => $data) {
                $isOnline = isset($onlineKeys[$hash]);
                if (!empty($data['channel'])) $chIds[] = $data['channel'];
                
                // --- ЛОГИКА ПОДМЕНЫ IP СЕРВЕРА ---
                if ($isOnline && isset($data['channel']) && isset($allocMap[$data['channel']])) {
                    $data['server'] = $allocMap[$data['channel']] . " (CDN)";
                }

                $sessions[] = [
                    'hash' => $hash,
                    'data' => $data,
                    'is_online' => $isOnline,
                    'is_banned' => isset($bannedMap[$hash])
                ];
            }

            usort($sessions, function($a, $b) {
                if ($a['is_online'] !== $b['is_online']) return $b['is_online'] ? 1 : -1;
                return ($b['data']['last_seen'] ?? 0) <=> ($a['data']['last_seen'] ?? 0);
            });
            
            $chMap = [];
            if (!empty($chIds)) {
                $ids = implode(',', array_unique(array_map('intval', $chIds)));
                if ($ids) {
                    $r = $pdo->query("SELECT id, name FROM channels WHERE id IN ($ids)");
                    while($row = $r->fetch()) $chMap[$row['id']] = $row['name'];
                }
            }

            $isAccessAllowed = ($status == 'active' && $expire > time());
            ?>
            <div class="result-box">
                <div class="header-status">
                    <h3><?= htmlspecialchars($userName) ?></h3>
                    <div class="token-display">
                        <span class="label">Token:</span> <span class="val"><?= htmlspecialchars($token) ?></span>
                    </div>
                </div>
                
                <div class="section access-section">
                    <h4>Статус доступа</h4>
                    <div class="info-grid">
                        <div class="info-item"><span class="lbl">Redis Статус</span><span class="val"><?= $status ?: 'OFF' ?></span></div>
                        <div class="info-item"><span class="lbl">Дата окончания</span><span class="val">
                            <?php if($expire): ?>
                                <span class="local-date year short" data-ts="<?= $expire ?>">
                                    <?= date("d.m.Y H:i", $expire) ?>
                                </span>
                            <?php else: ?> - <?php endif; ?>
                        </span></div>
                        <div class="info-item"><span class="lbl">TTL (сек)</span><span class="val"><?= $ttl ?></span></div>
                    </div>
                    <div class="access-control">
                        <button onclick="toggleStatus('<?= $token ?>')" 
                                class="btn-verdict <?= $isAccessAllowed ? 'btn-allow' : 'btn-deny' ?>">
                            <?php if ($isAccessAllowed): ?>
                                <span class="icon">✓</span> ДОСТУП РАЗРЕШЕН <br><small>(Нажмите, чтобы заблокировать)</small>
                            <?php else: ?>
                                <span class="icon">✕</span> ДОСТУП ЗАПРЕЩЕН <br><small>(Нажмите, чтобы разрешить)</small>
                            <?php endif; ?>
                        </button>
                    </div>
                </div>
                <div class="section online-section">
                    <h4>Устройства (<?= count($sessions) ?>)</h4>
                    <?php if (empty($sessions)): ?>
                        <div class="status-line offline"><span class="dot"></span> Нет данных об активности</div>
                    <?php else: ?>
                        <div class="sessions-list">
                        <?php foreach ($sessions as $s): 
                            $d = $s['data'];
                            $chId = $d['channel'] ?? 0;
                            $nm = $chMap[$chId] ?? 'Неизвестно';
                            
                            $startTime = $d['start'] ?? time();
                            $lastSeen = $d['last_seen'] ?? time();
                            $now = time();
                            
                            $durationSec = ($s['is_online'] ? $now : $lastSeen) - $startTime;
                            if ($durationSec < 0) $durationSec = 0;
                            $durationStr = gmdate("H:i:s", $durationSec);
                            
                            if ($s['is_banned']) {
                                $statusClass = 'banned'; $statusText = 'BANNED'; 
                                $cardStyle = 'border-left: 4px solid #dc3545; background:black; color:#333';
                            } elseif ($s['is_online']) {
                                $statusClass = 'online'; $statusText = 'ONLINE'; 
                                $cardStyle = 'border-left: 4px solid #5cb570';
                            } else {
                                $statusClass = 'offline'; $statusText = 'OFFLINE'; 
                                $cardStyle = 'border-left: 4px solid #999; opacity: 0.6;';
                            }
                        ?>
                            <div class="session-card" style="<?= $cardStyle ?>">
                                <div class="card-head" style="display:flex; justify-content:space-between; align-items:center;">
                                    <span><?= htmlspecialchars($nm)?> <small>(ID: <?= $chId ?>)</small></span>
                                    <span class="status-line <?= $statusClass ?>">
                                        <span class="dot"></span> <?= $statusText ?>
                                    </span>
                                </div>
                                <div class="card-body">
                                    <div><small>IP Устройства:</small> <?= $d['ip']??'-' ?></div>
                                    <div><small>IP Сервера:</small> <?= $d['server']??'-' ?></div>
                                    <div><small>На канале:</small> <strong><?= $durationStr ?></strong></div>
                                    <div class="ua-container"><small>UA:</small> <span title="<?= htmlspecialchars($d['ua']??'') ?>"><?= htmlspecialchars($d['ua']??'-') ?> </span></div>
                                    
                                    <div style="grid-column: span 2; display:flex; justify-content:space-between; align-items:flex-end; border-top:1px solid #ccc; padding-top:5px; margin-top:5px;">
                                        <div>
                                            <small>Последний запрос:</small> 
                                            <b><span class="local-date" data-ts="<?= $lastSeen ?>"><?= date("d.m H:i:s", $lastSeen) ?></span></b>
                                            <br>
                                            <div class="hash-row"><?= $s['hash'] ?></div>
                                        </div>
                                        <div>
                                            <?php if ($s['is_banned']): ?>
                                                <button class="btn-action unban" onclick="toggleBan('<?= $token ?>', '<?= $s['hash'] ?>', 'unban')">Разбанить</button>
                                            <?php else: ?>
                                                <button class="btn-action ban" onclick="toggleBan('<?= $token ?>', '<?= $s['hash'] ?>', 'ban')">Забанить</button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php
        } catch (Exception $e) { echo "<div class='error-box'>Redis Error: ".$e->getMessage()."</div>"; }
        echo ob_get_clean();
        exit;
    }

    // 9. ПОЛУЧЕНИЕ СПИСКА ВСЕХ ОНЛАЙН ПОЛЬЗОВАТЕЛЕЙ
    if ($_POST['action'] === 'get_online_users') {
        try {
            $redis = getRedis($redisConfig);
            
            $chMap = [];
            try {
                $stmt = $pdo->query("SELECT id, name FROM channels");
                while ($row = $stmt->fetch()) {
                    $chMap[$row['id']] = $row['name'];
                }
            } catch (Exception $e) { /* игнорируем ошибки БД */ }

            $usersFound = [];
            $now = time();
            
            $cursor = '0';
            
            do {
                $response = $redis->execute(['SCAN', $cursor, 'MATCH', 'online:users:*', 'COUNT', 1000]);
                
                if (!$response || !is_array($response) || count($response) < 2) {
                    break; 
                }
                
                $cursor = $response[0];
                $keys = $response[1];
                
                if (!empty($keys) && is_array($keys)) {
                    foreach ($keys as $key) {
                        if (strpos($key, ':meta') !== false) continue;
                        
                        $token = substr($key, 13);
                        if ($token) {
                            $usersFound[$token] = $key;
                        }
                    }
                }
                
            } while ($cursor != '0');

            $resultList = [];

            foreach ($usersFound as $token => $key) {
                $metaData = $redis->execute(['HGETALL', $key . ':meta']);
                
                if (!empty($metaData) && is_array($metaData)) {
                    for ($i = 0; $i < count($metaData); $i += 2) {
                        $sessHash = $metaData[$i];
                        $jsonStr = $metaData[$i+1];
                        $data = json_decode($jsonStr, true);

                        if ($data) {
                            $start = $data['start'] ?? $now;
                            $lastSeen = $data['last_seen'] ?? $now;
                            $duration = $now - $start;
                            
                            $chId = $data['channel'] ?? 0;
                            $chName = $chMap[$chId] ?? "ID: $chId";

// Получаем информацию из аллокаций для определения live/archive
$isLive = false;
$isArchive = false;
if ($chId && isset($allocMap[$chId])) {
    // Если есть аллокация для этого канала, проверяем статус
    $allocData = json_decode($redis->execute(['HGET', 'channel_allocations', $chId]), true);
    if ($allocData) {
        $isLive = $allocData['is_live'] ?? false;
        $isArchive = $allocData['is_archive'] ?? false;
    }
}

$resultList[] = [
    'token' => $token,
    'ip' => $data['ip'] ?? '-',
    'ua' => $data['ua'] ?? 'Unknown',
    'channel' => $chName,
    'channel_id' => $chId,
    'duration' => $duration,
    'start_ts' => $start,
    'last_seen' => $lastSeen,
    'server' => $data['server'] ?? '-',
    'is_live' => $isLive,
    'is_archive' => $isArchive
];
                        }
                    }
                }
            }

            usort($resultList, function($a, $b) {
                return $b['duration'] <=> $a['duration'];
            });

            if (empty($resultList)) {
                echo "<tr><td colspan='6' style='text-align:center; padding:20px'>Нет активных зрителей</td></tr>";
                exit;
            }

            $tokens = array_column($resultList, 'token');
            if (!empty($tokens)) {
                $placeholders = str_repeat('?,', count($tokens) - 1) . '?';
                $stmt = $pdo->prepare("SELECT token, user FROM accounts WHERE token IN ($placeholders)");
                $stmt->execute($tokens);
                $userMap = [];
                while ($row = $stmt->fetch()) {
                    $userMap[$row['token']] = $row['user'];
                }
            } else {
                $userMap = [];
            }
            echo "<tr><td colspan='6' style='text-align:center; padding:10px; font-weight:bold; background:#2b354b; color:#f0f8ff;'>Всего: " . count($resultList) . " пользователей</td></tr>";

            foreach ($resultList as $row) {
                $durStr = gmdate("H:i:s", $row['duration']);
                $uaShort = mb_strlen($row['ua']) > 40 ? mb_substr($row['ua'], 0, 40)."..." : $row['ua'];
                $tokenShort = mb_strlen($row['token']) > 15 ? mb_substr($row['token'], 0, 15)."..." : $row['token'];
                $userName = htmlspecialchars($userMap[$row['token']] ?? '-');
                
                echo "<tr>";
                echo "<td><a href='#' onclick='switchToUser(\"{$row['token']}\"); return false;' style='color:#6c96bb; text-decoration:none; border-bottom:1px dashed #444'>";
                echo "$userName<br><small style='color:#888'>$tokenShort</small>";
                echo "</a></td>";
                echo "<td>{$row['ip']}</td>";
// Формируем индикатор статуса (зеленая точка для live, желтая для archive)
$streamIndicator = '';
if ($row['is_live']) {
    $streamIndicator = "<span class='stream-indicator live-dot' title='Прямой эфир' style='font-size:12px;'>●</span> ";
} elseif ($row['is_archive']) {
    $streamIndicator = "<span class='stream-indicator archive-dot' title='Архив' style='font-size:12px;'>●</span> ";
}

echo "<td>{$streamIndicator}{$row['channel']}</td>";
                echo "<td class='num'>{$durStr}</td>";
                echo "<td><span class='local-date' data-ts='{$row['last_seen']}'>" . date('H:i:s', $row['last_seen']) . "</span></td>";
                echo "<td title='" . htmlspecialchars($row['ua']) . "'>{$uaShort}</td>";
                echo "</tr>";
            }

        } catch (Exception $e) {
            echo "<tr><td colspan='6'>Ошибка: " . $e->getMessage() . "</td></tr>";
        }
        exit;
    }

    // 5. ПОЛУЧЕНИЕ СТАТИСТИКИ
    if ($_POST['action'] === 'get_stats') {
        try {
            $redis = getRedis($redisConfig);
            $channels = $redis->sMembers("stats:channels_list");
            $today = date("Y-m-d");
            
            if (empty($channels)) { echo "<tr><td colspan='4' style='text-align:center'>Нет данных</td></tr>"; exit; }

            $ids = implode(',', array_map('intval', $channels));
            $names = $pdo->query("SELECT id, name FROM channels WHERE id IN ($ids)")->fetchAll(PDO::FETCH_KEY_PAIR);

            $totalOnline = 0;
            $totalDaily = 0;

            foreach ($channels as $cid) {
                $onl = $redis->zCard("stats:online:channel:$cid");
                $day = $redis->pfCount("stats:daily:$today:channel:$cid");
                $totalOnline += $onl;
                $totalDaily += $day;            
                if ($onl > 0 || $day > 0) {
                    $name = htmlspecialchars($names[$cid] ?? "ID $cid");
                    echo "<tr>";
                    echo "<td class='num'>$cid</td>";
                    echo "<td>$name</td>";
                    echo "<td class='num onl'>$onl</td>";
                    echo "<td class='num'>$day</td>";
                    echo "</tr>";
                }
            }
            echo "<tr style='font-weight:bold; background:#2b354b; color:#f0f8ff;'>";
            echo "<td colspan='2' style='text-align:right;'>ИТОГО:</td>";
            echo "<td class='num onl'>$totalOnline</td>";
            echo "<td class='num'>$totalDaily</td>";
            echo "</tr>";
        } catch (Exception $e) { echo "<tr><td colspan='4'>Ошибка: ".$e->getMessage()."</td></tr>"; }
        exit;
    }

    // 6. ПОЛУЧЕНИЕ СПИСКА ПОТОКОВ (ALLOCATIONS) — ВОЗВРАЩАЕТ JSON
    if ($_POST['action'] === 'get_streams') {
        try {
            $redis = getRedis($redisConfig);
            $raw = $redis->execute(['HGETALL', 'channel_allocations']);
            
            $streams = [];
            if (!empty($raw) && is_array($raw)) {
                for ($i = 0; $i < count($raw); $i += 2) {
                    $cid = $raw[$i];
                    $data = json_decode($raw[$i+1], true);
                    if ($data) {
                        // Определяем ID канала для отображения
                        $displayChannelId = $data['current_channel'] ?? $cid;
                        $data['display_channel_id'] = $displayChannelId;
                        $data['allocation_id'] = $cid;
                        $streams[] = $data;
                    }
                }
            }

            usort($streams, function($a, $b) {
                return ($b['allocated_at'] ?? 0) <=> ($a['allocated_at'] ?? 0);
            });

            // Получаем названия каналов
            $channelIds = [];
            foreach ($streams as $s) {
                $channelIds[] = $s['display_channel_id'];
                if (isset($s['root_id'])) $channelIds[] = $s['root_id'];
            }
            $channelIds = array_unique($channelIds);
            
            $names = [];
            if (!empty($channelIds)) {
                $ids = implode(',', array_map('intval', $channelIds));
                $names = $pdo->query("SELECT id, name FROM channels WHERE id IN ($ids)")->fetchAll(PDO::FETCH_KEY_PAIR);
            }

            $rowsHtml = '';
            if (empty($streams)) {
                $rowsHtml = "<tr><td colspan='8' style='text-align:center; padding:20px'>Нет активных потоков</td></tr>";
            } else {
                foreach ($streams as $s) {
                    $allocationId = $s['allocation_id'];
                    $displayChId = $s['display_channel_id'];
                    $rootId = $s['root_id'] ?? $allocationId;
                    $prov = htmlspecialchars($s['provider'] ?? '-');
                    $slot = htmlspecialchars($s['slot'] ?? '0');
                    $cdn = htmlspecialchars($s['cdn_ip'] ?? '-');
                    $ts = $s['allocated_at'] ?? 0;
                    $url = $s['source_url'] ?? '';
                    $token = $s['token'] ?? '';
                    $quality = $s['quality'] ?? '';
                    $switchCount = $s['switch_count'] ?? 0;
                    
$chName = htmlspecialchars($names[$displayChId] ?? "ID $displayChId");
$rootName = htmlspecialchars($names[$rootId] ?? "ID $rootId");

// Формируем строку с информацией о качестве
$qualityInfo = '';
if ($quality) {
    $qualityInfo = "<small style='color:#888;'>📺 $quality</small><br>";
}

// Информация о переключениях
$switchInfo = '';
if ($switchCount > 0) {
    $switchInfo = "<small style='color:#ffa500;'>🔄 Переключений: $switchCount</small><br>";
}

// Определяем статусы
$isLive = $s['is_live'] ?? false;
$isArchive = $s['is_archive'] ?? false;

// Формируем индикаторы статуса (только иконки, без текста)
$statusIndicator = '';
if ($isLive) {
    $statusIndicator = "<span class='stream-indicator live-dot' title='Прямой эфир'>●</span> ";
} elseif ($isArchive) {
    $statusIndicator = "<span class='stream-indicator archive-dot' title='Архив'>●</span> ";
} else {
    $statusIndicator = "<span class='stream-indicator unknown-dot' title='Статус не указан'>●</span> ";
}

$rowsHtml .= "<tr id='stream-row-$allocationId' data-allocation-id='$allocationId' data-slot='$slot' data-provider='" . htmlspecialchars($prov, ENT_QUOTES) . "'  data-token='" . htmlspecialchars($token, ENT_QUOTES) . "' data-root-id='" . htmlspecialchars($rootId, ENT_QUOTES) . "'>";
$rowsHtml .= "<td style='min-width:150px;'>";
$rowsHtml .= "<div style='display:flex; align-items:center; gap:6px;'>";
$rowsHtml .= $statusIndicator;
$rowsHtml .= "<strong>$chName</strong>";
$rowsHtml .= "</div>";
$rowsHtml .= $qualityInfo;
$rowsHtml .= $switchInfo;
// ВСЕГДА показываем root_id, даже если совпадает с current_channel
$rowsHtml .= "<small style='color:#666;'>📡 Источник: $rootName <span style='color:#888;'>(ID: $rootId)</span></small>";
$rowsHtml .= "</td>";
$rowsHtml .= "<td class='num'>$allocationId</td>";
$rowsHtml .= "<td class='num'>$slot</td>";
$rowsHtml .= "<td>$prov</td>";
$rowsHtml .= "<td class='num'>$cdn</td>";
$rowsHtml .= "<td><span class='local-date' data-ts='$ts'>" . date('d.m H:i', $ts) . "</span></td>";
$rowsHtml .= "<td><a href='" . htmlspecialchars($url) . "' target='_blank' style='color:#6c96bb; text-decoration:none' title='" . htmlspecialchars($url) . "'>🔗 Link</a></td>";
$rowsHtml .= "<td style='text-align:right'>";
$rowsHtml .= "<button class='btn-mini-action check' onclick='checkStream(this, \"$allocationId\", \"$cdn\", \"$rootId\")' title='Проверить процесс (SSH)'>⚡</button> ";
$rowsHtml .= "<button class='btn-mini-action delete' onclick='confirmDeleteStream(this)' title='Остановить (Publish Stop)'>✕</button>";
$rowsHtml .= "</td>";
$rowsHtml .= "</tr>";
                }
            }

            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'html' => $rowsHtml,
                'total' => count($streams)
            ]);

        } catch (Exception $e) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'html' => "<tr><td colspan='8'>Ошибка Redis: " . htmlspecialchars($e->getMessage()) . "</td></tr>",
                'total' => 0
            ]);
        }
        exit;
    }

    // 7. УДАЛЕНИЕ (PUBLISH STOP)
    if ($_POST['action'] === 'delete_stream') {
        $cid  = $_POST['cid'] ?? '';
        $slot = $_POST['slot'] ?? '';
        $prov = $_POST['provider'] ?? '';
        $token = $_POST['token'] ?? '';
        
        if (!$cid || !$prov) die('Error params');
        
        $payload = json_encode([
            'slot' => (string)$slot,
            'provider' => $prov,
            'channel' => (string)$cid,
            'token' => $token
        ]);

        try {
            $redis = getRedis($redisConfig);
            $redis->execute(['PUBLISH', 'channel_stops', $payload]);
            echo "OK";
        } catch (Exception $e) { echo "ERR"; }
        exit;
    }

    // 8. ПРОВЕРКА ПРОЦЕССА (SSH: PS AUX) — ИСПОЛЬЗУЕТ ROOT_ID
    if ($_POST['action'] === 'check_stream_alive') {
        global $serversMap, $sshUser;
        
        $cid = $_POST['cid'] ?? '';
        $cdnIp = $_POST['cdn'] ?? '';
        $rootId = $_POST['root_id'] ?? $cid; // ИСПОЛЬЗУЕМ ROOT_ID ДЛЯ ПОИСКА ПРОЦЕССА

        
        if (!$cid || !$cdnIp) die('Invalid params');

        $conf = $serversMap[$cdnIp] ?? null;

        if (!$conf) {
            die("CONFIG_ERR: No settings for $cdnIp");
        }

        $currentPass = $conf['pass'] ?? '';
        $currentPort = $conf['port'] ?? 22; 
        
        // ИСПОЛЬЗУЕМ ROOT_ID для поиска процесса hls_p
        $cmd = "ps auxww | grep hls_p | grep -w '{$rootId}' | grep -v grep";
        $output = '';
        
        if (function_exists('ssh2_connect')) {
            $connection = @ssh2_connect($cdnIp, $currentPort);
            if (!$connection) die('SSH_CONN_ERR');
            
            if (!@ssh2_auth_password($connection, $sshUser, $currentPass)) {
                die('SSH_AUTH_ERR');
            }
            
            $stream = ssh2_exec($connection, $cmd);
            stream_set_blocking($stream, true);
            $output = stream_get_contents($stream);
            
        } else {
            $safePass = escapeshellarg($currentPass);
            $fullCmd = "sshpass -p $safePass ssh -p $currentPort -o StrictHostKeyChecking=no $sshUser@$cdnIp \"$cmd\" 2>&1";
            $output = shell_exec($fullCmd);
        }

        if (strpos($output, 'hls_p') !== false) {
            echo "ALIVE|Root ID: $rootId"; 
        } else {
            echo "DEAD|Root ID: $rootId"; 
        }
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Monitor Dashboard</title>
<style>
* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

html, body {
    width: 100%;
    overflow-x: hidden;
}
:root { --primary: #007bff; --bg: #010910; --text: #333; --success: #5cb570; --danger: #dc3545; }
body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: var(--bg); color: var(--text); margin: 0; padding: 0; }

.table-responsive {
    width: 100%;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

table { 
    width: 100%; 
    border-collapse: collapse; 
    min-width: 500px;
}
.top-bar { background: black; padding: 10px 12px;  display: flex; justify-content: space-between; position: sticky; top: 0; z-index: 1000; width:100%; max-width:100vw}
    .nav-btn { background: none; border: 1px solid #736868; padding: 8px 15px; border-radius: 20px; cursor: pointer; color:#859eb9;}
    .nav-btn.active { background: #264b73; color: #95b9d3; border-color: #264b73 }

.container { 
    width: 100%; 
    max-width: 900px; 
    margin: 30px auto; 
    padding: 0 15px; 
    display: block;
}
    .view-section { display: none; }
    .view-section.active { display: inline-block; animation: fadeIn 0.3s; }
    @keyframes fadeIn { from{opacity:0;transform:translateY(5px)} to{opacity:1;transform:translateY(0)} }

    .search-wrap { 
        position: relative; 
        background: #121d27; 
        padding: 2px; 
        border-radius: 30px; 
        box-shadow: 0 4px 15px rgba(0,0,0,0.08); 
        display: flex; 
        align-items: center; 
        border: 1px solid #1c3551; 
        width: fit-content;
        max-width: 500px;
        margin: 0 auto 20px auto;
    }
    input[type="text"] { flex: 1; border: none; padding: 0px 23px; font-size:17px; outline: none; background:no-repeat; color: #f0f8ff; text-align: center }

    .btn-search { background: var(--primary); color: white; border: none; padding: 8px 22px; border-radius: 40px; cursor: pointer; font-weight: 600; margin-left: 5px; }
    .clear-btn { cursor: pointer; color: #999; padding: 0 10px; display: none; font-size: 20px;}
    .autocomplete { position: absolute; top: 110%; left: 15px; right: 15px; background: white; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); z-index: 99; overflow: hidden; display: none; }
    .ac-item { padding: 12px 20px; cursor: pointer; border-bottom: 1px solid #eee; }
    .ac-item:hover { background: #f0f8ff; }

    .result-box { background: #000000b0; border-radius: 16px; padding: 14px; margin-top: 19px; color: #6c96bb }
    .header-status { text-align: center; border-bottom: 1px solid #22364b; padding-bottom: 12px}
    .token-display { font-family: monospace;  font-size: 0.9em; word-break: break-all}
    
    .info-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap:3px; text-align: center; margin-bottom:14px; }
    .info-item { background: #6fa932; padding: 4px; border-radius: 6px; color: #223126 }
    .info-item .lbl {display: block; font-size: 0.75em; color:#c8f4ff}
    .info-item .val {font-weight: bold; }

    .access-control { margin-top: 15px; }
    .btn-verdict { width: 100%; padding:5px; border: none; border-radius: 10px; font-weight: bold; cursor: pointer; transition: transform 0.1s; display: flex; align-items: center; justify-content: center; flex-direction: column; line-height: 1.2; }
    .btn-verdict:active { transform: scale(0.98); }
    .btn-verdict .icon { font-size:17px; margin-bottom:3px}
    .btn-verdict small { font-weight: normal; opacity: 0.7; font-size: 0.8em; margin-top: 3px; }
    
    .btn-allow { background: linear-gradient(178deg, #67b9b0, #22472a); color: white; box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3); }
    .btn-deny { background: linear-gradient(178deg, #dc3545, #c82333); color: white; box-shadow: 0 4px 15px rgba(220, 53, 69, 0.3); }

    .status-line { font-weight: bold; margin-bottom: 15px; display: flex; align-items: center; }
    .status-line.online { color: var(--success); }
    .status-line.offline { color: #6c757d; }
    .dot { width: 10px; height: 10px; border-radius: 50%; margin-right: 8px; display: inline-block; background: currentColor; }
    
    .session-card { border: 1px solid #333333; border-radius: 10px; padding:13px; margin-bottom: 10px; border-left: 4px solid var(--primary); }
    .session-card.card-history { border-left-color: #6c757d; background: #fafafa; opacity: 0.6}
    .card-head { font-weight: bold; margin-bottom: 8px; }
    .card-body { font-size: 0.9em; color:#dce5f5; display: grid; grid-template-columns: 1fr 1fr; gap:2px}

    .stats-card { background: #36506740; border-radius: 8px; padding: 8px; box-shadow: 0 5px 20px rgba(0,0,0,0.05); color:#f0f8ff }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding:3px 5px; border-bottom: 1px solid #2b354b; text-align: left; }
    th { cursor: pointer; color:#666; font-size:0.85em; text-transform: uppercase; user-select: none; }
    th:hover { color: #c7dff9; }
    th.sort-asc::after { content: " ▲"; font-size: 0.8em; }
    th.sort-desc::after { content: " ▼"; font-size: 0.8em; }
    
    td.num { font-family: monospace; font-weight: bold; }
    td.onl { color: var(--success); }
    .th-filter { margin-top: 5px; }
    .filter-inp { width: 40px; border: 1px solid #ddd; padding: 2px 4px; }
    .error-box { background: #fff0f0; color: #d63031; padding: 15px; border-radius: 8px; text-align: center; border: 1px solid #ffd0d0}
    .access-section{text-align:center}
    h3,h4 {margin:10px}

    .ua-container { display: flex; min-width: 0; align-items: center; }
    .ua-container small { margin-right: 5px; white-space: nowrap; flex-shrink: 0; }
    .ua-container span { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; flex-grow: 1; display: block; }
    .hash-row { grid-column: 1 / -1; font-family: monospace; font-size: 0.85em; color: #999; padding-top: 5px; border-top: 1px dashed #eee; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

    .status-line.banned .dot { background: #dc3545; }
    .status-line.banned { color: #dc3545; font-weight: bold; }
    .btn-action { padding: 5px 10px; border: none; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: bold; color: white; }
    .btn-action.ban { background: #dc3545; }
    .btn-action.unban { background: linear-gradient(178deg, #6eb77f, #22472a); }
    .btn-action.reset { background: #ffc107; color: #333; }
    
    .badge { display: inline-block; padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: bold; }
    .badge-danger { background: #dc3545; color: white; }
    .badge-warning { background: #ffc107; color: #333; }
    .badge-success { background: #28a745; color: white; }
    .badge-info { background: #17a2b8; color: white; }

    .btn-mini-action { width: 39px; height: 25px; border-radius: 6px; border: none; cursor: pointer; font-size: 12px; display: inline-flex; align-items: center; justify-content: center; transition: 0.2s; }
    .btn-mini-action.delete { background: #4a1b1b; color: #ff6b6b; }
    .btn-mini-action.delete:hover { background: #c82333; color: white; }
    .btn-mini-action.check { background: #1b3a4a; color: #6bb9ff; margin-right: 5px; }
    .btn-mini-action.check:hover { background: #007bff; color: white; }
    
    .row-alive { background: rgba(40, 167, 69, 0.1); }
    .row-dead { background: rgba(220, 53, 69, 0.15); border-left: 3px solid #dc3545; }
    #usersTable,#streamsBody{font-size: 13px}

/* Индикаторы статуса потока (цветные точки) */
.stream-indicator {
    font-size: 14px;
    line-height: 1;
    flex-shrink: 0;
}

.live-dot {
    color: #28a745;
    animation: livePulse 1.5s infinite;
    text-shadow: 0 0 8px rgba(40, 167, 69, 0.6);
}

.archive-dot {
    color: #ffc107;
    text-shadow: 0 0 8px rgba(255, 193, 7, 0.4);
}

.unknown-dot {
    color: #6c757d;
}

@keyframes livePulse {
    0%, 100% { opacity: 1; transform: scale(1); }
    50% { opacity: 0.5; transform: scale(0.8); }
}

/* Модальное окно подтверждения */
.modal-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.8);
    z-index: 9999;
    justify-content: center;
    align-items: center;
}

.modal-overlay.active {
    display: flex;
}

.modal-dialog {
    background: #1a1a2e;
    border: 1px solid #333;
    border-radius: 12px;
    padding: 20px;
    max-width: 400px;
    width: 90%;
    color: #f0f8ff;
    box-shadow: 0 10px 40px rgba(0,0,0,0.5);
}

.modal-title {
    font-size: 18px;
    font-weight: bold;
    margin-bottom: 15px;
    color: #ff6b6b;
}

.modal-body {
    margin-bottom: 20px;
    line-height: 1.5;
}

.modal-info {
    background: #16213e;
    padding: 10px;
    border-radius: 6px;
    margin: 10px 0;
    font-size: 13px;
}

.modal-info strong {
    color: #6c96bb;
    display: block;
    margin-bottom: 3px;
}

.modal-buttons {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
}

.btn-modal {
    padding: 8px 16px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-weight: bold;
    transition: all 0.2s;
}

.btn-modal:hover {
    transform: scale(1.05);
}

.btn-modal-delete {
    background: #dc3545;
    color: white;
}

.btn-modal-cancel {
    background: #6c757d;
    color: white;
}
</style>
</head>
<body>
<div class="top-bar">
<div style="font-weight:bold; color:#c7dff9; padding:5px;">Metropoliten</div>
<div>
<button class="nav-btn active" onclick="switchView('search')">Поиск</button>
<button class="nav-btn" onclick="switchView('streams')">Потоки</button>
<button class="nav-btn" onclick="switchView('stats')">Статистика</button>
<button class="nav-btn" onclick="switchView('users')">Пользователи</button>
<button class="nav-btn" onclick="switchView('zapping')" style="background:#dc3545; color:#fff;">🚫 Zapping</button>
</div>
</div>
<div class="container">
<!-- SEARCH VIEW -->
<div class="table-responsive">
<div id="view-search" class="view-section active">
    <div class="search-wrap">
        <input type="text" id="searchInput" placeholder="Введите логин или токен..." autocomplete="off">
        <span class="clear-btn" id="clearBtn">&times;</span>
        <button class="btn-search" id="btnSearch">Найти</button>
        <div class="autocomplete" id="acList"></div>
    </div>
    <div id="resultContainer"></div>
</div>
</div>

<!-- STREAMS VIEW (NEW) -->
<div class="table-responsive">
<div id="view-streams" class="view-section">
    <div class="stats-controls" style="display:flex; justify-content:space-between; margin-bottom:15px; align-items:center;">
        <h3 style="margin:0">Активные аллокации (Streams)</h3>
        <button class="btn-refresh" onclick="loadStreams()" style="background:#28a745; color:#fff; border:none; padding:8px 16px; border-radius:20px; cursor:pointer;">↻ Обновить</button>
    </div>
    <div id="streamsCount" style="text-align:center; margin-bottom:10px; font-weight:bold; color:#6c96bb; display:none;"></div>
    <div class="stats-card">
        <table id="streamsTable">
            <thead>
                <tr>
                    <th onclick="sortStreams(0, 'str')">Канал</th>
                    <th onclick="sortStreams(1, 'num')">ID</th>
                    <th onclick="sortStreams(2, 'num')">Slot</th>
                    <th onclick="sortStreams(3, 'str')">Provider</th>
                    <th onclick="sortStreams(4, 'str')">CDN IP</th>
                    <th onclick="sortStreams(5, 'date')">Allocated</th>
                    <th>Source</th>
                    <th style="text-align:right">Action</th>
                </tr>
            </thead>
            <tbody id="streamsBody">
                <tr><td colspan="8" style="text-align:center; padding:20px; color:#999">Загрузка...</td></tr>
            </tbody>
        </table>
    </div>
</div>
</div>

<!-- STATS VIEW -->
<div class="table-responsive">
<div id="view-stats" class="view-section">
    <div class="stats-controls" style="display:flex; justify-content:space-between; margin-bottom:15px; align-items:center;">
        <h3 style="margin:0">Каналы</h3>
        <button class="btn-refresh" onclick="loadStats()" style="background:#28a745; color:#fff; border:none; padding:8px 16px; border-radius:20px; cursor:pointer;">↻ Обновить</button>
    </div>
    <div class="stats-card">
        <table id="statsTable">
            <thead>
                <tr>
                    <th onclick="sortStats(0, 'num')">ID</th>
                    <th onclick="sortStats(1, 'str')">Название</th>
                    <th onclick="sortStats(2, 'num')">Online <div class="th-filter"><input type="number" class="filter-inp" oninput="filterTable()"></div></th>
                    <th onclick="sortStats(3, 'num')">Daily <div class="th-filter"><input type="number" class="filter-inp" oninput="filterTable()"></div></th>
                </tr>
            </thead>
            <tbody id="statsBody">
                <tr><td colspan="4" style="text-align:center; padding:20px; color:#999">Загрузка...</td></tr>
            </tbody>
        </table>
    </div>
</div>
</div>

<!-- ONLINE USERS VIEW (NEW) -->
<div class="table-responsive">
<div id="view-users" class="view-section">
    <div class="stats-controls" style="display:flex; justify-content:space-between; margin-bottom:15px; align-items:center;">
        <h3 style="margin:0">Зрители онлайн</h3>
        <button class="btn-refresh" onclick="loadOnlineUsers()" style="background:#28a745; color:#fff; border:none; padding:8px 16px; border-radius:20px; cursor:pointer;">↻ Обновить</button>
    </div>
    <div class="stats-card">
        <table id="usersTable">
            <thead>
                <tr>
                    <th>Token</th>
                    <th>IP Address</th>
                    <th>Channel</th>
                    <th onclick="sortUsers(3, 'num')">Duration</th>
                    <th>Last Seen</th>
                    <th>Device (UA)</th>
                </tr>
            </thead>
            <tbody id="usersBody">
                <tr><td colspan="6" style="text-align:center; padding:20px; color:#999">Загрузка...</td></tr>
            </tbody>
        </table>
    </div>
</div>
</div>

<!-- ZAPPING BLOCKED DEVICES VIEW -->
<div class="table-responsive">
<div id="view-zapping" class="view-section">
    <div class="stats-controls" style="display:flex; justify-content:space-between; margin-bottom:15px; align-items:center;">
        <h3 style="margin:0">🚫 Устройства, заблокированные за заппинг</h3>
        <button class="btn-refresh" onclick="loadZappingDevices()" style="background:#28a745; color:#fff; border:none; padding:8px 16px; border-radius:20px; cursor:pointer;">↻ Обновить</button>
    </div>
    
    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px; margin-bottom:20px;">
        <div class="stats-card">
            <h4 style="margin-bottom:10px; color:#ffc107;">⚠️ С нарушениями</h4>
            <table id="zappingViolationsTable">
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
                    <tr><td colspan="5" style="text-align:center; padding:20px; color:#999">Загрузка...</td></tr>
                </tbody>
            </table>
        </div>
        
        <div class="stats-card">
            <h4 style="margin-bottom:10px; color:#dc3545;">🔒 Заблокированы</h4>
            <table id="zappingBlockedTable">
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
                    <tr><td colspan="5" style="text-align:center; padding:20px; color:#999">Загрузка...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
</div>
<script>

// Функция преобразования времени
function renderLocalDates() {
document.querySelectorAll('.local-date').forEach(el => {
if (el.dataset.processed) return;
const ts = parseInt(el.getAttribute('data-ts'));
if (!ts) return;
const date = new Date(ts * 1000);
const options = {
day: '2-digit', month: '2-digit',
hour: '2-digit', minute: '2-digit', second: '2-digit'
};
if (el.classList.contains('short')) delete options.second;
if (el.classList.contains('year')) options.year = 'numeric';
el.innerText = date.toLocaleString('ru-RU', options).replace(',', '');
el.dataset.processed = "true";
});
}

// ----------------------
// NAVIGATION & VIEW LOGIC
// ----------------------
function switchView(viewName) {
document.querySelectorAll('.view-section').forEach(el => el.classList.remove('active'));
document.querySelectorAll('.nav-btn').forEach(el => el.classList.remove('active'));

document.getElementById('view-' + viewName).classList.add('active');

const btns = document.querySelectorAll('.nav-btn');
if (viewName === 'search') btns[0].classList.add('active');
else if (viewName === 'streams') {
btns[1].classList.add('active');
loadStreams();
}
else if (viewName === 'stats') {
btns[2].classList.add('active');
if(document.getElementById('statsBody').innerText.includes('Загрузка')) loadStats();
}
else if (viewName === 'users') {
btns[3].classList.add('active');
loadOnlineUsers();
}
else if (viewName === 'zapping') {
btns[4].classList.add('active');
loadZappingDevices();
}
}

// ----------------------
// STREAMS LOGIC (SORTING + ACTIONS)
// ----------------------
function loadStreams() {
    const tbody = document.getElementById('streamsBody');
    const countEl = document.getElementById('streamsCount');
    tbody.style.opacity = '0.5';
    countEl.style.display = 'none';

    const fd = new FormData();
    fd.append('action', 'get_streams');

    fetch('', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            tbody.innerHTML = data.html;
            tbody.style.opacity = '1';
            renderLocalDates();

            if (data.total >= 0) {
                countEl.innerText = `Всего активных потоков: ${data.total}`;
                countEl.style.display = 'block';
            }

            restoreStreamSort();
        })
        .catch(e => {
            tbody.innerHTML = "<tr><td colspan='8'>Connection Error</td></tr>";
            tbody.style.opacity = '1';
            countEl.style.display = 'none';
        });
}

function deleteStream(cid, slot, provider, token) {
    const fd = new FormData();
    fd.append('action', 'delete_stream');
    fd.append('cid', cid);
    fd.append('slot', slot);
    fd.append('provider', provider);
    if (token) fd.append('token', token);

    fetch('', { method: 'POST', body: fd })
    .then(r => r.text())
    .then(resp => {
    if (resp.trim() === 'OK') {
    const row = document.getElementById('stream-row-' + cid);
    if(row) {
    row.style.opacity = '0.3';
    row.style.background = '#ffdada';
    setTimeout(() => row.remove(), 1000);
    }
    } else {
    alert('Ошибка отправки STOP команды');
    }
    });
}

function checkStream(btn, cid, cdnIp, rootId) {
    const row = document.getElementById('stream-row-' + cid);
    btn.innerHTML = '...';
    btn.disabled = true;

    const fd = new FormData();
    fd.append('action', 'check_stream_alive');
    fd.append('cid', cid);
    fd.append('cdn', cdnIp);
//    fd.append('root_id', rootId);  // ПЕРЕДАЕМ ROOT_ID
    // В строке где формируется FormData, убедись что есть:
    fd.append('root_id', rootId || cid);

    fetch('', { method: 'POST', body: fd })
    .then(r => r.text())
    .then(status => {
    btn.innerHTML = '⚡';
    btn.disabled = false;
    row.classList.remove('row-alive', 'row-dead');

    if (status.trim().startsWith('ALIVE')) {
    row.classList.add('row-alive');
    btn.style.color = '#28a745';
    } else if (status.trim().startsWith('DEAD')) {
    row.classList.add('row-dead');
    btn.style.color = '#dc3545';
    } else {
    alert('Ошибка подключения: ' + status);
    }
    })
    .catch(e => {
    alert('Ошибка сети при проверке');
    btn.disabled = false;
    btn.innerHTML = '⚡';
    });
}

function sortStreams(n, type) {
    const table = document.getElementById('streamsTable');
    const tbody = document.getElementById('streamsBody');
    const rows = Array.from(tbody.rows);
    if(rows.length < 1 || rows[0].cells.length < 7) return;

    const th = table.querySelectorAll('th')[n];
    let dir = th.classList.contains("sort-asc") ? "desc" : "asc";

    table.querySelectorAll('th').forEach(t => t.className = '');
    th.classList.add(dir === "asc" ? "sort-asc" : "sort-desc");

    localStorage.setItem('streams_sort_col', n);
    localStorage.setItem('streams_sort_dir', dir);
    localStorage.setItem('streams_sort_type', type);

    rows.sort((a, b) => {
    let x = a.cells[n].innerText.trim();
    let y = b.cells[n].innerText.trim();

    if (type === 'date') {
    x = parseInt(a.cells[n].querySelector('span')?.getAttribute('data-ts')) || 0;
    y = parseInt(b.cells[n].querySelector('span')?.getAttribute('data-ts')) || 0;
    return dir === 'asc' ? x - y : y - x;
    }

    return type === 'num'
    ? (dir === 'asc' ? parseFloat(x) - parseFloat(y) : parseFloat(y) - parseFloat(x))
    : (dir === 'asc' ? x.localeCompare(y) : y.localeCompare(x));
    });

    rows.forEach(r => tbody.appendChild(r));
}

function restoreStreamSort() {
    const col = localStorage.getItem('streams_sort_col');
    const dir = localStorage.getItem('streams_sort_dir');
    const type = localStorage.getItem('streams_sort_type');

    if (col !== null && dir !== null) {
    const table = document.getElementById('streamsTable');
    const th = table.querySelectorAll('th')[col];
    if(th) {
    th.classList.add(dir === 'asc' ? 'sort-desc' : 'sort-asc');
    sortStreams(col, type || 'str');
    }
    }
}

// ----------------------
// SEARCH & USER LOGIC
// ----------------------
function toggleBan(token, hash, mode) {
if (!confirm(mode === 'ban' ? 'Заблокировать это устройство?' : 'Разблокировать?')) return;
const fd = new FormData(); fd.append('action', 'toggle_ban_device'); fd.append('token', token); fd.append('hash', hash); fd.append('mode', mode);
fetch('', { method: 'POST', body: fd }).then(r => r.text()).then(resp => { if (resp.trim() === 'OK') executeCheck(); else alert('Ошибка: ' + resp); });
}

const input = document.getElementById('searchInput');
const acList = document.getElementById('acList');
const clearBtn = document.getElementById('clearBtn');
const resultContainer = document.getElementById('resultContainer');
let dbTimer;

input.addEventListener('input', function() {
const val = this.value.trim();
clearTimeout(dbTimer);
clearBtn.style.display = val.length > 0 ? 'block' : 'none';
if (val.length < 3) { acList.style.display = 'none'; if(!val) resultContainer.innerHTML = ''; return; }

dbTimer = setTimeout(() => {
const fd = new FormData(); fd.append('action', 'search_users'); fd.append('term', val);
fetch('', { method: 'POST', body: fd }).then(r => r.json()).then(data => {
acList.innerHTML = '';
if (data.length) {
data.forEach(user => {
const div = document.createElement('div'); div.className = 'ac-item'; div.textContent = user;
div.onclick = () => { input.value = user; acList.style.display = 'none'; executeCheck(user); };
acList.appendChild(div);
});
acList.style.display = 'block';
} else acList.style.display = 'none';
});
}, 300);
});

document.getElementById('btnSearch').onclick = () => executeCheck();
input.onkeydown = (e) => { if(e.key==='Enter') executeCheck(); };
clearBtn.onclick = () => { input.value=''; acList.style.display='none'; resultContainer.innerHTML=''; clearBtn.style.display='none'; };

function executeCheck(val) {
val = val || input.value.trim();
if(val.length < 3) return;
acList.style.display = 'none';
resultContainer.innerHTML = '<div style="text-align:center; padding:30px; color:#999;">Загрузка...</div>';
const fd = new FormData(); fd.append('action', 'check_input'); fd.append('input', val);
fetch('', { method: 'POST', body: fd }).then(r => r.text()).then(html => {resultContainer.innerHTML = html;renderLocalDates()})
.catch(() => resultContainer.innerHTML = '<div class="error-box">Ошибка сети</div>')
}

function toggleStatus(token) {
if(!confirm("Вы уверены, что хотите изменить статус доступа?")) return;
const fd = new FormData(); fd.append('action', 'toggle_status'); fd.append('token', token);
fetch('', { method: 'POST', body: fd }).then(r => r.text()).then(res => { if(res.trim() === 'OK') executeCheck(token); else alert("Ошибка обновления статуса"); }).catch(e => alert("Ошибка сети"));
}

// ----------------------
// STATS LOGIC
// ----------------------
function loadStats() {
const tbody = document.getElementById('statsBody'); tbody.style.opacity = '0.5';
const fd = new FormData(); fd.append('action', 'get_stats');
fetch('', { method: 'POST', body: fd }).then(r => r.text()).then(html => {
tbody.innerHTML = html; tbody.style.opacity = '1'; filterTable();
});
}

function filterTable() {
const rows = document.getElementById('statsBody').rows;
const inps = document.querySelectorAll('.filter-inp');
const minOnl = parseInt(inps[0].value)||0; const minDay = parseInt(inps[1].value)||0;
for (let r of rows) {
if(r.cells.length < 4) continue;
r.style.display = (parseInt(r.cells[2].innerText||0) < minOnl || parseInt(r.cells[3].innerText||0) < minDay) ? 'none' : '';
}
}

function sortStats(n, type) {
const table = document.getElementById('statsTable'); const tbody = document.getElementById('statsBody');
const rows = Array.from(tbody.rows); if(rows[0].cells.length < 4) return;
const th = table.querySelectorAll('th')[n];
let dir = th.classList.contains("sort-asc") ? "desc" : "asc";
table.querySelectorAll('th').forEach(t => t.className = ''); th.classList.add(dir === "asc" ? "sort-asc" : "sort-desc");
rows.sort((a, b) => {
let x = a.cells[n].innerText.trim(), y = b.cells[n].innerText.trim();
return type==='num' ? (dir==='asc' ? x-y : y-x) : (dir==='asc' ? x.localeCompare(y) : y.localeCompare(x));
});
rows.forEach(r => tbody.appendChild(r));
}

// ----------------------
// ONLINE USERS LOGIC
// ----------------------
function loadOnlineUsers() {
    const tbody = document.getElementById('usersBody');
    tbody.style.opacity = '0.5';
    
    const fd = new FormData();
    fd.append('action', 'get_online_users');

    fetch('', { method: 'POST', body: fd })
        .then(r => r.text())
        .then(html => {
            tbody.innerHTML = html;
            tbody.style.opacity = '1';
            renderLocalDates();
        })
        .catch(e => {
            tbody.innerHTML = "<tr><td colspan='6'>Connection Error</td></tr>";
            tbody.style.opacity = '1';
        });
}

function switchToUser(token) {
    switchView('search');
    const input = document.getElementById('searchInput');
    input.value = token;
    executeCheck(token);
}

// ----------------------
// ZAPPING MANAGEMENT
// ----------------------
function loadZappingDevices() {
    const violationsBody = document.getElementById('zappingViolationsBody');
    const blockedBody = document.getElementById('zappingBlockedBody');
    
    violationsBody.style.opacity = '0.5';
    blockedBody.style.opacity = '0.5';
    
    const fd1 = new FormData();
    fd1.append('action', 'get_zapping_devices');
    
    fetch('chklg.php', { method: 'POST', body: fd1 })
        .then(r => r.json())
        .then(data => {
            violationsBody.style.opacity = '1';
            if (data.error || !data.devices || data.devices.length === 0) {
                violationsBody.innerHTML = "<tr><td colspan='5' style='text-align:center; padding:20px; color:#999'>Нет устройств с нарушениями</td></tr>";
            } else {
                let html = '';
                data.devices.forEach(dev => {
                    const violationsClass = dev.violations >= 5 ? 'badge-danger' : dev.violations >= 2 ? 'badge-warning' : 'badge-info';
                    const sessShort = dev.session_id.substring(0, 16) + '...';
                    const tokenShort = dev.token.substring(0, 12);
                    
                    html += "<tr>";
                    html += "<td><small>" + tokenShort + "</small></td>";
                    html += "<td><small>" + sessShort + "</small></td>";
                    html += "<td><span class='badge " + violationsClass + "'>" + dev.violations + "</span></td>";
                    html += "<td>" + (dev.is_banned ? '<span class="badge badge-danger">' + formatTimeLeft(dev.ban_time_left) + '</span>' : '<span class="badge badge-success">Нет</span>') + "</td>";
                    html += "<td>";
                    if (dev.is_banned) {
                        html += "<button class='btn-action unban' onclick='unblockZappingDevice(\"" + dev.token + "\", \"" + dev.session_id + "\")'>Разблокировать</button>";
                    } else {
                        html += "<button class='btn-action reset' onclick='resetZappingViolations(\"" + dev.token + "\", \"" + dev.session_id + "\")'>Сбросить</button>";
                    }
                    html += "</td>";
                    html += "</tr>";
                });
                violationsBody.innerHTML = html;
            }
        })
        .catch(e => {
            violationsBody.style.opacity = '1';
            violationsBody.innerHTML = "<tr><td colspan='5' style='text-align:center; color:#dc3545'>Ошибка: " + e.message + "</td></tr>";
        });
    
    const fd2 = new FormData();
    fd2.append('action', 'get_zapping_blocked');
    
    fetch('chklg.php', { method: 'POST', body: fd2 })
        .then(r => r.json())
        .then(data => {
            blockedBody.style.opacity = '1';
            if (data.error || !data.devices || data.devices.length === 0) {
                blockedBody.innerHTML = "<tr><td colspan='5' style='text-align:center; padding:20px; color:#999'>Нет заблокированных устройств</td></tr>";
            } else {
                let html = '';
                data.devices.forEach(dev => {
                    const sessShort = dev.session_id.substring(0, 16) + '...';
                    const tokenShort = dev.token.substring(0, 12) + '...';
                    
                    html += "<tr>";
                    html += "<td><small>" + tokenShort + "</small></td>";
                    html += "<td><small>" + sessShort + "</small></td>";
                    html += "<td><span class='badge badge-danger'>" + dev.reason + "</span></td>";
                    html += "<td><span class='badge badge-warning'>" + formatTimeLeft(dev.ban_time_left) + "</span></td>";
                    html += "<td><button class='btn-action unban' onclick='unblockZappingDevice(\"" + dev.token + "\", \"" + dev.session_id + "\")'>Разблокировать</button></td>";
                    html += "</tr>";
                });
                blockedBody.innerHTML = html;
            }
        })
        .catch(e => {
            blockedBody.style.opacity = '1';
            blockedBody.innerHTML = "<tr><td colspan='5' style='text-align:center; color:#dc3545'>Ошибка: " + e.message + "</td></tr>";
        });
}

function unblockZappingDevice(token, sessionId) {
    if (!confirm('Разблокировать устройство?\n\nToken: ' + token + '\nSession: ' + sessionId.substring(0, 20) + '...')) return;
    
    const fd = new FormData();
    fd.append('action', 'unblock_zapping_device');
    fd.append('token', token);
    fd.append('session_id', sessionId);
    
    fetch('chklg.php', { method: 'POST', body: fd })
        .then(r => r.text())
        .then(resp => {
            if (resp.trim() === 'OK') {
                loadZappingDevices();
            } else {
                alert('Ошибка: ' + resp);
            }
        })
        .catch(e => alert('Ошибка сети: ' + e.message));
}

function resetZappingViolations(token, sessionId) {
    if (!confirm('Сбросить нарушения для устройства?\n\nSession: ' + sessionId.substring(0, 20) + '...')) return;
    
    const fd = new FormData();
    fd.append('action', 'unblock_zapping_device');
    fd.append('token', token);
    fd.append('session_id', sessionId);
    
    fetch('', { method: 'POST', body: fd })
        .then(r => r.text())
        .then(resp => {
            if (resp.trim() === 'OK') {
                loadZappingDevices();
            } else {
                alert('Ошибка: ' + resp);
            }
        })
        .catch(e => alert('Ошибка сети: ' + e.message));
}

function formatTimeLeft(seconds) {
    if (!seconds || seconds <= 0) return 'N/A';
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = seconds % 60;
    if (h > 0) return h + 'ч ' + m + 'м';
    if (m > 0) return m + 'м ' + s + 'с';
    return s + 'с';
}

function sortUsers(n, type) {
    const table = document.getElementById('usersTable');
    const tbody = document.getElementById('usersBody');
    const rows = Array.from(tbody.rows);
    if(rows.length < 1) return;

    let dir = table.getAttribute('data-sort-dir') === 'asc' ? 'desc' : 'asc';
    table.setAttribute('data-sort-dir', dir);

    rows.sort((a, b) => {
        let x = a.cells[n].innerText.trim();
        let y = b.cells[n].innerText.trim();
        
        if(n === 3) {
             x = parseDuration(x);
             y = parseDuration(y);
             return dir === 'asc' ? x - y : y - x;
        }

        return type === 'num'
            ? (dir === 'asc' ? parseFloat(x) - parseFloat(y) : parseFloat(y) - parseFloat(x))
            : (dir === 'asc' ? x.localeCompare(y) : y.localeCompare(x));
    });

    rows.forEach(r => tbody.appendChild(r));
}

function parseDuration(str) {
    const parts = str.split(':');
    if (parts.length === 3) return (+parts[0]) * 3600 + (+parts[1]) * 60 + (+parts[2]);
    return 0;
}

// Функция подтверждения удаления
/*function confirmDeleteStream(allocationId, slot, provider, token, channelName) {
    // Создаем модальное окно, если его нет
    let modal = document.getElementById('confirmModal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'confirmModal';
        modal.className = 'modal-overlay';
        modal.innerHTML = `
            <div class="modal-dialog">
                <div class="modal-title">⚠️ Подтверждение удаления</div>
                <div class="modal-body">
                    <p>Вы действительно хотите остановить поток?</p>
                    <div class="modal-info">
                        <strong>Канал:</strong> ${channelName}
                        <strong>ID аллокации:</strong> ${allocationId}
                        <strong>Slot:</strong> ${slot}
                        <strong>Provider:</strong> ${provider}
                    </div>
                    <p style="color: #ff6b6b; font-size: 13px;">Это действие отправит команду PUBLISH STOP и удалит аллокацию из Redis.</p>
                </div>
                <div class="modal-buttons">
                    <button class="btn-modal btn-modal-cancel" onclick="closeModal()">Отмена</button>
                    <button class="btn-modal btn-modal-delete" onclick="executeDeleteStream('${allocationId}', '${slot}', '${provider}', '${token}')">Удалить</button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
    }
    
    // Показываем модальное окно
    modal.classList.add('active');
    
    // Закрытие по клику вне окна
    modal.onclick = function(e) {
        if (e.target === modal) {
            closeModal();
        }
    };
} */

function confirmDeleteStream(btn) {
    const row = btn.closest('tr');
    const allocationId = row.dataset.allocationId;
    const slot = row.dataset.slot;
    const provider = row.dataset.provider;
    const token = row.dataset.token;
    const channelName = row.dataset.channelName;
    
    showDeleteModal(allocationId, slot, provider, token, channelName);
}

function showDeleteModal(allocationId, slot, provider, token, channelName) {
    const modal = document.getElementById('confirmModal');
    if (modal) modal.remove(); // Удаляем старый если есть
    
    const newModal = document.createElement('div');
    newModal.id = 'confirmModal';
    newModal.className = 'modal-overlay active';
    newModal.innerHTML = `
        <div class="modal-dialog">
            <div class="modal-title">⚠️ Подтверждение удаления</div>
            <div class="modal-body">
                <p>Удалить поток?</p>
                <div class="modal-info">
                    <strong>Канал:</strong> ${channelName}
                    <strong>ID:</strong> ${allocationId}
                </div>
            </div>
            <div class="modal-buttons">
                <button class="btn-modal btn-modal-cancel" id="modalCancelBtn">Отмена</button>
                <button class="btn-modal btn-modal-delete" id="modalDeleteBtn">Удалить</button>
            </div>
        </div>
    `;
    
    document.body.appendChild(newModal);
    
    // Вешаем обработчики ПОСЛЕ создания DOM
    document.getElementById('modalCancelBtn').onclick = () => {
        newModal.remove();
    };
    
    document.getElementById('modalDeleteBtn').onclick = () => {
        newModal.remove();
        deleteStream(allocationId, slot, provider, token);
    };
    
    // Клик вне окна
    newModal.onclick = (e) => {
        if (e.target === newModal) newModal.remove();
    };
}

function closeModal() {
    const modal = document.getElementById('confirmModal');
    if (modal) {
        modal.classList.remove('active');
    }
}

function executeDeleteStream(allocationId, slot, provider, token) {
    closeModal();
    deleteStream(allocationId, slot, provider, token);
}

// Обновленная функция deleteStream (замени существующую)
function deleteStream(cid, slot, provider, token) {
    const fd = new FormData();
    fd.append('action', 'delete_stream');
    fd.append('cid', cid);
    fd.append('slot', slot);
    fd.append('provider', provider);
    if (token) fd.append('token', token);

    const row = document.getElementById('stream-row-' + cid);
    if (row) {
        row.style.opacity = '0.5';
        row.style.transition = 'opacity 0.3s';
    }

    fetch('', { method: 'POST', body: fd })
    .then(r => r.text())
    .then(resp => {
        if (resp.trim() === 'OK') {
            if (row) {
                row.style.opacity = '0.3';
                row.style.background = '#ffdada';
                setTimeout(() => {
                    row.style.transition = 'all 0.5s';
                    row.style.maxHeight = '0';
                    row.style.overflow = 'hidden';
                    row.style.padding = '0';
                    setTimeout(() => row.remove(), 500);
                }, 300);
                
                // Обновляем счетчик
                updateStreamsCount();
            }
        } else {
            if (row) {
                row.style.opacity = '1';
            }
            alert('Ошибка отправки STOP команды: ' + resp);
        }
    })
    .catch(e => {
        if (row) {
            row.style.opacity = '1';
        }
        alert('Ошибка сети при удалении потока');
    });
}

// Обновление счетчика после удаления
function updateStreamsCount() {
    setTimeout(() => {
        const tbody = document.getElementById('streamsBody');
        const rows = tbody.querySelectorAll('tr:not([style*="display: none"])');
        const countEl = document.getElementById('streamsCount');
        let visibleCount = 0;
        rows.forEach(row => {
            if (row.style.maxHeight !== '0px') visibleCount++;
        });
        if (countEl) {
            countEl.innerText = `Всего активных потоков: ${visibleCount}`;
        }
    }, 1000);
}
</script>
</body>
</html>