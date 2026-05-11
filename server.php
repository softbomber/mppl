<?php
include_once("config.php");
checkLoggedIn("yes");
$adm = $_SESSION['a'];
if (!$adm) exit();

$link = new mysqli(
    getenv('DB_HOST') ?: 'localhost',
    getenv('DB_USER') ?: 'root',
    getenv('DB_PASS') ?: '',
    getenv('DB_NAME') ?: 'mpol'
);
if ($link->connect_error) exit(json_encode(["error" => "Ошибка подключения к БД"]));

$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
$limit = 2; // Обрабатываем по 2 записи за раз

// Читаем сохраненные данные
$json_data = file_get_contents("iptvuserdata.json");
$all_data = json_decode($json_data, true);

if (!$all_data) exit(json_encode(["error" => "Файл данных не найден или пуст"]));

$total = count($all_data);
$slice = array_slice($all_data, $offset, $limit);
$result = [];

foreach ($slice as $item) {
    $l = $item['refLinkUName']['username'];
    $html = ilookChkacc($l);
    $prolongState = extractText($html, 'prolongStateText');
    $tariffState = extractText($html, 'tariffState');
    $status = ($tariffState === "активен") ? "Тариф активен" : "Отключен";

    // Получаем данные из MySQL
    $query = "SELECT iptvactdate, iptvmonths FROM accounts WHERE iptvusr = ?";
    $stmt = $link->prepare($query);
    $stmt->bind_param("s", $l);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $end_date_str = "—";
    if ($row) {
        $iptvactdate = (int)$row['iptvactdate'];
        $iptvmonths = (int)explode(':', $row['iptvmonths'])[0];
        $end_date_unix = strtotime("+$iptvmonths months", $iptvactdate);
        $end_date_str = date('Y-m-d', $end_date_unix);

        if ($end_date_unix <= time() && $tariffState === "активен") {
            $end_date_str = "<b style='color:red;'>$end_date_str</b>";
        }
    }

    $result[] = [
        "login" => $l,
        "prolongState" => $prolongState,
        "status" => $status,
        "endDate" => $end_date_str
    ];
}

echo json_encode(["data" => $result, "offset" => $offset + $limit, "total" => $total]);
?>
