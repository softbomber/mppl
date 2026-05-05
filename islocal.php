<?php
include_once("config.php");
 checkLoggedIn("yes");
// Пример обработчика ajax/update_account.php
if ($_POST['action'] == 'update_islocal') {
    global $link;
    $id = $_POST['id'];
    $val = intval($_POST['val']); // Будет 0 или 1

    // Выполняем SQL update
    // Пример для MySQLi:
    // $db->query("UPDATE accounts SET islocal = $val WHERE id = $id");
    $q="UPDATE accounts SET islocal = $val WHERE user = '$id'";
    $link->query($q) or die("SQL Req. error: ".$link->error_list);
    
    echo "OK";
}
?>