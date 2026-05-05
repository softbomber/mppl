<?php
include_once("config.php");
checkLoggedIn("yes");
$accdealer="";
$dealer=$_SESSION['i'];
$user="";
$tarrif=$_SESSION['a'];
$num_elements=44;
$doru=$_SESSION["d"];
$p=1;
$d=0;
$actv=0;
$active_packet=0;
$active=0;
$now=time();
$desc='ASC';
$tz=$_SESSION['timeZoneOffset'];

if (isset($_GET["l"]))
{
$user=$link->real_escape_string(trim($_GET["l"]));
$packets = [];

$result = $link->query("SELECT 1 FROM accounts WHERE user = '$user' AND dealer = $dealer LIMIT 1");
$sql = "SELECT accounts.id, accounts.iptvusr FROM accounts WHERE `user`='$user'";
$res = $link->query($sql);

/*if ((($_SESSION['a'] != 2 || $_SESSION['a'] != 1 ) && $res->num_rows == 0) || ($result->num_rows == 0 && ($_SESSION['a'] != 2 || $_SESSION['a'] != 1 ))) {
    echo json_encode(["res->num_rows" => $res->num_rows,"result->num_rows" => $result->num_rows, "_SESSION['a']" => $_SESSION['a']], JSON_UNESCAPED_UNICODE);
    exit();
}*/

if ($res->num_rows == 0 || ($_SESSION['a'] != 1 && $_SESSION['a'] != 2) &&  $result->num_rows == 0) {
    exit();
}

$sql_active = "SELECT pdates.packet AS packet_id, packets.pname, UNIX_TIMESTAMP(pdates.dstart) as dstart , UNIX_TIMESTAMP(pdates.dend) as dend FROM accounts
    INNER JOIN pdates ON accounts.id = pdates.user_id  INNER JOIN packets ON pdates.packet = packets.id  WHERE accounts.`user` = ? AND packets.dsbled = 0 and pdates.dend >=NOW()
";
$stmt = $link->prepare($sql_active);
$stmt->bind_param("s", $user);
$stmt->execute();
$result_active = $stmt->get_result();

$active_subscriptions = [];
while ($row = $result_active->fetch_assoc()) {
    $active_subscriptions[$row['packet_id']] = $row;
}
$stmt->close();

$r="SELECT dealers.mindays FROM dealers	INNER JOIN accounts ON dealers.id = accounts.dealer WHERE accounts.`user` = '$user' limit 1";
$res=$link->query($r);
$rw = $res->fetch_assoc();
$mindays=$rw['mindays'];
// Получаем список всех пакетов из ppackets
$result = $link->query("SELECT id, ident FROM ppackets");
while ($row = $result->fetch_assoc()) {
    $ids = !empty($row['ident']) ? explode(',', $row['ident']) : [];

    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $link->prepare("SELECT id, pname, ident, degree, price FROM packets WHERE id IN ($placeholders) AND dsbled = 0");

        if ($stmt) {
            $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
            $stmt->execute();
            $res = $stmt->get_result();

            $pnames = [];
            $idents = [];
            $degrees = [];
            $caid_list = [];
            $main_pname = null;
            $main_price = null;
            $packet_prices = [];

            while ($packet = $res->fetch_assoc()) {
                if (!empty($packet['pname'])) {
                    $pnames[] = $packet['pname'];
                }
                if (!empty($packet['price'])) {
                    $packet_prices[$packet['id']] = $packet['price'];
                }
                if (!empty($packet['ident']) && strpos($packet['ident'], ';') === false) {
                    if (strpos($packet['ident'], '0500:') === 0) {
                        $caid_list[] = substr($packet['ident'], 5);
                    } else {
                        $idents[] = $packet['ident'];
                    }
                }
                if (!empty($packet['degree'])) {
                    $degrees[] = $packet['degree'];
                }
            }
            $stmt->close();


            switch ($tarrif) {
                case 0: case 1:
                    $sqltarrif = 'price';
                    break;
                case 2:
                    $sqltarrif = ($_SESSION['c'] == 1) ? 'paynet' : 'sum';
                    break;
                case 3: case 4:
                    $sqltarrif = 'special';
                    break;
                case 14:
                    $sqltarrif = 'zamir';
                    break;
                default:
                $sqltarrif = 'sum';
                }
           

            // Получаем имя и цену основного пакета
            $stmt_main = $link->prepare("SELECT pname, $sqltarrif as tarrif FROM packets WHERE id = ?");
            $stmt_main->bind_param('i', $row['id']);
            $stmt_main->execute();
            $stmt_main->bind_result($main_pname, $main_price);
            $stmt_main->fetch();
            $stmt_main->close();

            $idents = array_filter(array_unique($idents));
            $degrees = array_filter(array_unique($degrees));

            if (!empty($caid_list)) {
                $idents[] = '0500:' . implode(',', array_unique($caid_list));
            }

            if ($row['id'] == 1) {
                $pname = "Vip (Все пакеты)";
            } elseif (count($ids) > 2) {
                $pname = $main_pname ?? 'Неизвестный пакет';
            } else {
                $pname = implode(' + ', array_filter(array_unique($pnames)));
            }

            $price = (float) $main_price;
            if ($is_active = isset($active_subscriptions[$row['id']])) {
                $dstart = $active_subscriptions[$row['id']]['dstart'];
                $dend = $active_subscriptions[$row['id']]['dend'];
            } else {
                $dstart = '';
                $dend = '';
            }

            if (!empty($pname) || !empty($idents) || !empty($degrees)) {
                $packets[] = [
                    'id' => $row['id'],
                    'pname' => $pname,
                    'ident' => implode(';', $idents),
                    'degree' => implode(',', $degrees),
                    'price' => $price,
                    'dstart' => $dstart,
                    'dend' => $dend,
                    'is_active' => $is_active,
                    'components' => $ids  // Добавлен массив идентификаторов составных пакетов
                ];
            }
        }
    }
}

$link->close();

echo json_encode(["price_list" => $packets,"mindays" => $mindays], JSON_UNESCAPED_UNICODE);
}
else{echo "ERROR";}
?>