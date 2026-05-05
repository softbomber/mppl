<?php
/**
 * ajax_login.php
 * Лёгкий ajax-эндпоинт для повторной авторизации без перезагрузки страницы.
 * Принимает POST { login, password } и возвращает JSON.
 *
 * Используется js/reauth.js: когда какой-либо AJAX-запрос получает 401
 * (см. checkLoggedIn в functions.php), мы открываем модальную форму
 * входа поверх текущей страницы и постим credentials сюда. После успеха
 * пользователь остаётся на том же месте.
 *
 * Бизнес-логика не дублируется: вызываются ровно те же функции
 * checkPass() / cleanMemberSession() из functions.php, что и в обычном
 * login.php / mblogin.php.
 */

include_once("config.php");
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'method_not_allowed']);
    exit;
}

$login    = isset($_POST['login'])    ? trim((string)$_POST['login'])    : '';
$password = isset($_POST['password']) ? trim((string)$_POST['password']) : '';

if ($login === '' || $password === '') {
    echo json_encode(['ok' => false, 'message' => 'empty_credentials']);
    exit;
}

$row = checkPass($login, $password);
if (!$row) {
    echo json_encode(['ok' => false, 'message' => 'invalid_credentials']);
    exit;
}

// Восстанавливаем session-данные точно так же, как обычный логин.
cleanMemberSession(
    $row['user'],
    $row['id'],
    $row['a'],
    $row['hash'],
    isset($row['dealer']) ? $row['dealer'] : 0,
    isset($row['currency']) ? $row['currency'] : '',
    isset($row['rate']) ? $row['rate'] : 0,
    isset($row['postpaid']) ? $row['postpaid'] : 0
);

// Куки те же, что выставляет ajax_auth.php — чтобы поведение совпадало.
$s_time = time() + 60 * 60 * 24;
setcookie('a',   isset($row['a'])    ? $row['a']    : 0, $s_time, '/');
setcookie('i',   isset($row['id'])   ? $row['id']   : 0, $s_time, '/');
setcookie('hsh', isset($row['hash']) ? $row['hash'] : '', $s_time, '/');
if (isset($row['postpaid'])) {
    setcookie('pp', $row['postpaid'], $s_time, '/');
}

echo json_encode(['ok' => true]);
