<?php
// Установка заголовков для разрешения доступа к ресурсу с другого домена
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

// Подключение к базе данных (замените данными вашей БД)
require_once(__DIR__ . '/env_loader.php');
$servername = getenv('DB_HOST') ?: 'localhost';
$username = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASS') ?: '';
$dbname = getenv('DB_NAME') ?: 'mpol';

// Создание подключения
$conn = new mysqli($servername, $username, $password, $dbname);

// Проверка подключения
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// SQL запрос для получения данных из базы данных
$sql = "SELECT grpid, grpname FROM subgroups WHERE playlstid = 1";

$result = $conn->query($sql);

// Проверка наличия данных
if ($result->num_rows > 0) {
    // Создание массива для хранения данных
    $data = array();

    // Заполнение массива данными из базы данных
    while($row = $result->fetch_assoc()) {
        $data[] = $row;
    }

    // Отправка данных в формате JSON
    echo json_encode($data);
} else {
    echo "0 results";
}

// Закрытие соединения с базой данных
$conn->close();
?>
