<?php
// Redis credentials are loaded from .env via config.php

include_once("config.php"); 

try {
    $redis = new TinyRedis();
    
    // 1. Подключение
    $redis->connect($redis_host, $redis_port);

    // 2. Авторизация
    $authResp = $redis->execute(['AUTH', $redis_pass]);
    if ($authResp !== 'OK') {
        die("Ошибка авторизации Redis!</br>");
    }

    // 3. Получаем список каналов (используем новый метод)
    $channels = $redis->sMembers("stats:channels_list");
    
if (empty($channels)) {
    die("Нет активных просмотров.");
}

$today = date("Y-m-d");
// 2. ГОТОВИМ СПИСОК ID ДЛЯ MYSQL
// Превращаем массив [105, 106, 200] в строку "105,106,200"
// array_map('intval') нужен для защиты от SQL инъекций, чтобы там были только цифры
$ids_safe = array_map('intval', $channels);
$ids_string = implode(',', $ids_safe);

// 3. ЗАГРУЖАЕМ ИМЕНА ОДНИМ ЗАПРОСОМ
// Запрашиваем только те каналы, которые есть в списке Redis
$sql = "SELECT id, name FROM channels WHERE id IN ($ids_string)";
$result = $link->query($sql);

// Создаем "справочник" (ассоциативный массив): [ID => "Название"]
// Чтобы потом быстро доставать имя по ID без запросов к БД
$channel_names = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $channel_names[ $row['id'] ] = $row['name'];
    }
}

// 4. ВЫВОДИМ ТАБЛИЦУ
echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
echo "<tr>
        <th>ID Канала</th>
        <th>Канал</th>
        <th>Онлайн сейчас</th>
        <th>Уникальных за сутки ($today)</th>
      </tr>";

foreach ($channels as $channel_id) {
    // Получаем статистику из Redis (это очень быстро)
    $online_count = $redis->zCard("stats:online:channel:$channel_id");
    $daily_count  = $redis->pfCount("stats:daily:$today:channel:$channel_id");

    // Берем имя из нашего справочника. Если имени нет в базе, пишем заглушку.
    $name = isset($channel_names[$channel_id]) ? $channel_names[$channel_id] : "Неизвестный ($channel_id)";
    
    // Преобразуем спецсимволы, чтобы html не сломался
    $name_safe = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');

    echo "<tr>";
    echo "<td><b>$channel_id</b></td>";
    echo "<td>$name_safe</td>"; // Выводим имя
    echo "<td style='color:green; text-align:center;'>$online_count</td>";
    echo "<td style='text-align:center;'>$daily_count</td>";
    echo "</tr>";
}
echo "</table>";
    
    $redis->close();

} catch (Exception $e) {
    echo "Ошибка: " . $e->getMessage();
}
?>