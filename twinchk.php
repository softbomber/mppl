<?php
include_once("config.php");
checkLoggedIn("yes");

echo "<style>html,body {     background-color: #232323; color: aliceblue; }</style>"; // Устанавливаем светло-серый фон для body

$accdealer = "";
$dealer = $_SESSION['i'];
$user = $_SESSION['l'];
$adm = $_SESSION['a'];
$num_elements = 44;
$doru = $_SESSION["d"];
$p = 1;
$d = 0;
$actv = 0;
$active_packet = 0;
$active = 0;
$now = time();
$desc = 'ASC';

$r = "SELECT 
    a.id,
    a.`user`,
    a.req,
    a.pwd,
    a.dealer,
    a.iptvactdate,
    a.iptvmonths,
    a.grpvariant,
    a.twin,
    b.`user` AS b_user,
    c.`user` AS c_user,
    dealers.tz
FROM 
    accounts a
LEFT JOIN 
    accounts b ON a.twin = b.id
LEFT JOIN 
    accounts c ON a.id = c.twin
INNER JOIN
    dealers ON a.dealer = dealers.id
WHERE 
    a.twin > 0 AND
    a.deleted = '0'
GROUP BY 
    a.id, a.`user`, a.req, a.pwd, a.dealer, a.iptvactdate, a.iptvmonths, 
    a.grpvariant, a.twin, b.`user`, c.`user`, dealers.tz;";

$res = $link->query($r) or die("SQL error: " . $link->error_list);

if ($res->num_rows >= 1) {
    while ($row = $res->fetch_assoc()) {
        $accuser = $row['user'];
        $req = $row['req'];
        $iptvactdate = $row['iptvactdate'];
        $iptvmonths = $row['iptvmonths'];
        $twinusr = $row['b_user'];
        $twined = $row['c_user'];
        $tz = $row['tz'];
        
        // Расчет $iptvenddate для текущего пользователя ($accuser)
        if ($iptvactdate && ($iptvenddate = addMonths($iptvactdate, explode(":", $iptvmonths)[0])) >= $now) {
            // Проверка на оставшиеся дни
            $remaining_days = ($iptvenddate - $now) / 86400;
            if ($remaining_days <= 2) {
                $style = "style='color: white; background-color: red;'";
            } else {
                $style = "style='color: green;'";
            }
            echo  $accuser . " enddate=" .  "<span $style>" . u_time_c($iptvenddate - ($tz * 60), 0, 1) . "</span></br>";
            echo "Twined to " . $twinusr . "</br>";
        }

        // Выполнение дополнительного запроса для привязанного пользователя ($twinusr)
        if ($row['twin']) {
            $twin_id = $row['twin'];
            $twin_query = "SELECT iptvactdate, iptvmonths FROM accounts WHERE user = '$twinusr' AND deleted = '0'";
            $twin_res = $link->query($twin_query);
            
            if ($twin_res && $twin_row = $twin_res->fetch_assoc()) {
                $b_iptvactdate = $twin_row['iptvactdate'];
                $b_iptvmonths = $twin_row['iptvmonths'];
                
                // Расчет $iptvenddate для привязанного пользователя ($twinusr)
                if ($b_iptvactdate && ($twin_iptvenddate = addMonths($b_iptvactdate, explode(":", $b_iptvmonths)[0])) >= $now) {
                    // Проверка на оставшиеся дни для привязанного пользователя
                    $remaining_days_twin = ($twin_iptvenddate - $now) / 86400;
                    if ($remaining_days_twin <= 2) {
                        $style_twin = "style='color: white; background-color: red;'";
                    } else {
                        $style_twin = "style='color: green;'";
                    }
                    echo "Twined user's enddate (" . $twinusr . ")=" . "<span $style_twin>" . u_time_c($twin_iptvenddate - ($tz * 60), 0, 1) . "</span></br></br>";
                }
            }
        }
    }
}

$link->close();
?>
