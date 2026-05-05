<?php
$servername = "localhost";
$username = "root";
$password = "uiF5bcaw8";
$dbname = "mpol";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Строки с доменами
$domainsString = ".otttv.pw .ottclub.xyz .rostelekom.xyz .tvclub.xyz .russtv.net .megatv.fun .megogo.xyz";

// Преобразуем строку в массив, разделенный пробелами
$domainsArray = explode(" ", $domainsString);

$l="mp46_9624";
// Запрос на выборку iptvsdom и iptvkey
$selectQuery = "SELECT iptvsdom, iptvkey FROM accounts WHERE iptvusr='$l'";
$result = $conn->query($selectQuery);

// Получаем результат запроса
if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $iptvsdom = $row["iptvsdom"];
    $iptvkey = $row["iptvkey"];

    // SQL-запрос для получения данных о каналах
    $sql = "SELECT 
    iptvchannels.channel_name_column, 
    iptvchannels.days_column, 
    iptvchannels.channel_number_column, 
    iptvchannels.id, 
    iptvgroups.title
FROM
    accounts
INNER JOIN
    iptvgroups
    ON FIND_IN_SET(iptvgroups.grpid, accounts.iptvplaylist) > 0
INNER JOIN
    iptvchannels
    ON FIND_IN_SET(iptvchannels.id, iptvgroups.groups) > 0
WHERE
    accounts.iptvusr = '$l'
GROUP BY
    iptvgroups.title,
    iptvchannels.channel_name_column,
    iptvchannels.days_column,
    iptvchannels.channel_number_column,
    iptvchannels.id
ORDER BY
    iptvgroups.title DESC,
    iptvchannels.channel_name_column ASC,
    iptvchannels.days_column ASC,
    iptvchannels.channel_number_column ASC,
    iptvchannels.id ASC";
    $result = $conn->query($sql);
    $randomDomain = $domainsArray[array_rand($domainsArray)];    // Выполняем запрос

    $fileContent = "#EXTM3U\n";
    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            
            $fileContent .= "#EXTINF:0 tvg-rec=\"" . $row["days_column"] . "\"," . $row["channel_name_column"] . "\n";
            $fileContent .= "#EXTGRP:" . $row["title"] . "\n";
            $fileContent .= "http://" . $iptvsdom . $randomDomain . "/iptv/" . $iptvkey . "/" . $row["channel_number_column"] . "/index.m3u8\n";
        }
    } else {
        echo "0 results";
    }

    // Закрытие соединения с базой данных
    $conn->close();

    // Создание и запись в файл
    $filename = $iptvkey.".m3u8";
    $file = fopen($filename, "w");
    fwrite($file, $fileContent);
    fclose($file);

    echo "File '$filename' created successfully.";
} else {
    echo "No rows found in accounts table.";
}

?>
