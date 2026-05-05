<?php
// export_to_m3u8.php — окончательная версия с tvg-rec и grpid
header('Content-Type: text/plain; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die('ERROR: Только POST');
}

$required = ['grpvariant', 'plname', 'iptvplaylist', 'iptvsdom', 'iptvkey'];
foreach ($required as $key) {
    if (!isset($_POST[$key]) || trim($_POST[$key]) === '') {
        die("ERROR: Отсутствует параметр $key");
    }
}

$playlist_id   = (int)$_POST['grpvariant'];     // channel_groups_list.playlist_id
$playlist_name = trim($_POST['plname']);
$grpid_list    = trim($_POST['iptvplaylist']);  // список grpid через запятую
$domain        = rtrim(trim($_POST['iptvsdom']), '/');
$key           = trim($_POST['iptvkey']);
$sdom="xyz.com";

$pdo = new PDO("mysql:host=localhost;dbname=mpol;charset=utf8mb4", "root", "uiF5bcaw8", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

$output = "#EXTM3U\n";
//$output .= "#PLAYLIST:{$playlist_name}\n\n";

// === Выборка групп ===
if ($grpid_list === '' || $grpid_list === '0') {
    $sql = "SELECT name, channel_ids 
            FROM channel_groups_list 
            WHERE playlist_id = ? 
              AND channel_ids != '' 
              AND channel_ids IS NOT NULL
            ORDER BY id ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$playlist_id]);
} else {
    $grpids = array_filter(array_map('intval', explode(',', $grpid_list)));
    if (empty($grpids)) {
        die("ERROR: Неверный формат grpid");
    }

    $placeholders = str_repeat('?,', count($grpids) - 1) . '?';
    $sql = "SELECT name, channel_ids 
            FROM channel_groups_list 
            WHERE playlist_id = ? 
              AND grpid IN ($placeholders)
              AND channel_ids != '' 
              AND channel_ids IS NOT NULL
            ORDER BY id ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([$playlist_id], $grpids));
}

// === Формирование плейлиста ===
foreach ($stmt as $group) {
    $groupName = $group['name'];
    $ids = array_filter(array_map('trim', explode(',', $group['channel_ids'])));

    foreach ($ids as $id) {
        $id = (int)$id;
        if ($id <= 0) continue;

        // Получаем имя и rec
        $ch = $pdo->prepare("SELECT name, rec FROM channels WHERE id = ?");
        $ch->execute([$id]);
        $channel = $ch->fetch(PDO::FETCH_ASSOC);
        if (!$channel) continue;

        $rec_days = (int)$channel['rec'];
        $tvg_rec  = $rec_days > 0 ? " tvg-rec=\"{$rec_days}\"" : '';

        $url = "http://{$domain}.{$sdom}/iptv/{$key}/{$id}/index.m3u8";

        $output .= "#EXTINF:-1{$tvg_rec},{$channel['name']}\n";
        $output .= "#EXTGRP:{$groupName}\n";
        $output .= $url . "\n";
    }
}

header('Content-Disposition: attachment; filename="' . urlencode($playlist_name) . '.m3u8"');
header('Content-Length: ' . strlen($output));
echo $output;
exit;