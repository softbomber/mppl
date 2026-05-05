<?php
include_once("config.php");
 checkLoggedIn("yes");
$accdealer="";
$dealer=$_SESSION['i'];
$user=$_SESSION['l'];
$adm=$_SESSION['a'];
$num_elements=44;
$doru=$_SESSION["d"];
$p=1;
$d=0;
$actv=0;
$active_packet=0;
$active=0;
$now=time();
$desc='ASC';


$tz = -300;  // Смещение временной зоны в минутах (например, -300 для UTC+5)
$iptvenddate = 1725635892;  // Пример значения Unix-времени

// Преобразуем смещение из минут в секунды
$tz_seconds = $tz * 60;

// Применяем смещение к Unix-времени
$corrected_time = $iptvenddate + $tz_seconds;

// Теперь $corrected_time — это скорректированное Unix-время
echo "Корректированное Unix-время: " . $corrected_time."</br>";
echo	u_time_c($corrected_time,0,1)."</br>";
echo	u_time_c($iptvenddate,0,1)."</br>";


$unixtime = 1725635892; // Unix-время
$timezoneOffsetMinutes = -300; // Смещение в минутах

// Преобразование Unix-времени в строку времени
$timestamp = date('Y-m-d H:i:s', $unixtime);

// Корректировка времени с учетом смещения
$adjustedTime = date('Y-m-d H:i:s', $unixtime + ($timezoneOffsetMinutes * 60));

echo "Original UTC Time: " . date('Y-m-d H:i:s', $unixtime) . "\n";
echo "Adjusted Time: " . $adjustedTime;


?>
