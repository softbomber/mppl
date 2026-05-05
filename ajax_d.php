<?php
include_once("config.php");

// Обработка GET-запроса для поиска дилеров
if (isset($_GET['query'])) {
    $query = isset($_GET['query']) ? trim($_GET['query']) : '';

    // Формируем SQL-запрос
    if ($query !== '') {
        $query = $link->real_escape_string($query);
        $sql = "SELECT id, user FROM dealers WHERE user LIKE '%$query%' ORDER BY user";
    } else {
        $sql = "SELECT id, user FROM dealers ORDER BY user";
    }

    // Выполняем запрос
    $res = $link->query($sql) or die(json_encode(['error' => 'SQL Error: ' . $link->error]));

    // Формируем массив данных
    $dealers = [];
    while ($row = $res->fetch_assoc()) {
        $dealers[] = [
            'id' => $row['id'],
            'name' => htmlspecialchars($row['user'], ENT_QUOTES, 'UTF-8')
        ];
    }

    // Возвращаем данные в формате JSON
    header('Content-Type: application/json');
    echo json_encode($dealers);
}

// Обработка POST-запроса для обновления дилера
if (isset($_POST['uid']) && isset($_POST['did'])) {
    $did = $link->real_escape_string($_POST['did']);
    $uid = $link->real_escape_string($_POST['uid']);

    // Обновляем запись в базе данных
    $updateQuery = "UPDATE accounts SET dealer='$did' WHERE id='$uid'";
    $link->query($updateQuery) or die(json_encode(['error' => 'SQL Error: ' . $link->error]));

    // Возвращаем успешный статус
    header('Content-Type: application/json');
    echo json_encode(['status' => 'success']);
}
?>