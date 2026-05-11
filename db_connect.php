<?php
/**
 * Shared PDO connection setup for admin pages (uman.php, usrman.php, chklg.php).
 * Provides: $dbConfig, $redisConfig, $sshUser, $serversMap, $pdo
 */

$dbConfig = [
    'host' => getenv('DB_HOST') ?: 'localhost',
    'name' => getenv('DB_NAME') ?: 'mpol',
    'user' => getenv('DB_USER') ?: 'root',
    'pass' => getenv('DB_PASS') ?: '',
];

$redisConfig = [
    'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
    'port' => (int)(getenv('REDIS_PORT') ?: 6379),
    'pass' => getenv('REDIS_PASS') ?: '',
];

$sshUser = getenv('SSH_USER') ?: 'root';

$serversMap = json_decode(getenv('SERVERS_MAP') ?: '{}', true) ?: [];

try {
    $dsn = "mysql:host={$dbConfig['host']};dbname={$dbConfig['name']};charset=utf8";
    $pdo = new PDO($dsn, $dbConfig['user'], $dbConfig['pass']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    if (isset($_POST['action'])) {
        die(json_encode(['error' => 'Ошибка БД']));
    }
    $pdo = null;
}
