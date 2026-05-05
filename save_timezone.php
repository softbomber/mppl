<?php
include_once("config.php");
 checkLoggedIn("yes");
$dealer = $_SESSION['i'];


if (isset($_POST['timezoneOffset'])) {
    $timezoneOffset = intval($_POST['timezoneOffset']); // Преобразование в целое число для безопасности

    // Обновление смещения часового пояса в базе данных
    $stmt = $link->prepare("UPDATE dealers SET tz = ? WHERE id = ?");
    $stmt->bind_param("ii", $timezoneOffset, $dealer);

    if ($stmt->execute()) {
       // echo "Timezone offset updated successfully";
    } else {
       // echo "Error updating timezone offset: " . $stmt->error;
    }

    $stmt->close();
} else {
   // echo "Timezone offset not received";
}

$link->close();
?>
