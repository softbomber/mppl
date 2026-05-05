<?php
$host = 'localhost';
$dbname = 'mpol';
$user = 'root';
$pass = 'uiF5bcaw8';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Ошибка подключения: " . $e->getMessage());
}

// Проверка наличия дилера
if (isset($_GET['dealer_search'])) {
    $dealerSearch = $_GET['dealer_search'] . '%';
    $stmt = $pdo->prepare("SELECT `user` FROM dealers WHERE `user` LIKE :dealer_search LIMIT 10");
    $stmt->execute(['dealer_search' => $dealerSearch]);
    $dealers = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo json_encode($dealers);
    exit;
}

// Получение данных с фильтрацией по дате и дилеру
$startDate = isset($_GET['start_date']) ? $_GET['start_date'] . ' 00:00:00' : '2000-01-01 00:00:00';
$endDate = isset($_GET['end_date']) ? $_GET['end_date'] . ' 23:59:59' : date('Y-m-d 23:59:59');
$dealer = isset($_GET['dealer']) ? $_GET['dealer'] : '';

$sql = "SELECT 
	dealers.`user` AS dealer_user, 
	actiondsc.adesc, 
	UNIX_TIMESTAMP(bphistory.time) as time, 
	UNIX_TIMESTAMP(bphistory.dend) as dend , 
	bphistory.days, 
	bphistory.ost, 
	bphistory.sum, 
	bphistory.ostafter, 
	bphistory.action, 
	accounts.`user` AS account_user,
	packets.pname
FROM
	bphistory
	INNER JOIN
	dealers
	ON 
		bphistory.did = dealers.id
	INNER JOIN
	actiondsc
	ON 
		bphistory.action = actiondsc.actionid
	LEFT JOIN
	accounts
	ON 
		bphistory.uid = accounts.id
	LEFT JOIN
	packets
	ON 
		bphistory.pid = packets.id
        WHERE bphistory.time BETWEEN :start_date AND :end_date";

if ($dealer !== '') {
    $sql .= " AND dealers.`user` = :dealer";
}

$sql .= " ORDER BY bphistory.time DESC";

$stmt = $pdo->prepare($sql);
$params = ['start_date' => $startDate, 'end_date' => $endDate];
if ($dealer !== '') {
    $params['dealer'] = $dealer;
}
$stmt->execute($params);
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($data);
?>