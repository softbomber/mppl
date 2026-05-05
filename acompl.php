<?php
header('Content-Type:application/json;charset=utf-8;Access-Control-Allow-Origin:"*";"Access-Control-Allow-Credentials":true');
include_once("functions.php");
$dbhost="localhost";
$dbuser="root";
$dbpass="uiF5bcaw8";
$dbname="mpol";
connectToDB();

if(isset($_GET['query'])) { // Проверка наличия GET параметра query
  
  $query = $_GET['query'];
  $logins = array();
  
$mysqli = new mysqli($dbhost, $dbuser, $dbpass, $dbname);

// проверяем соединение на ошибки
if ($mysqli->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
  
  // Выбираем логины из базы данных, которые начинаются с запроса
  $stmt = $mysqli->prepare("SELECT user FROM dealers WHERE user LIKE ?");
  $str=$query."%";
  $stmt->bind_param("s",$str);
  $stmt->execute();
  $result = $stmt->get_result();
  
  // Добавляем найденные логины в массив
  while($row = $result->fetch_assoc()) {
    $logins[] = $row['user'];
  }
  
  // Отправляем массив в формате json на клиент
  echo json_encode($logins);
}
?>