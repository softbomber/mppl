<?php
include_once("config.php");
checkLoggedIn("yes");

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

$did = $_SESSION['i'];
$adm = $_SESSION['a'];
$lastSum = null;
$lastIntrst = null;

while (true) {
    if (connection_aborted()) break;

    $stmt = $link->prepare("SELECT sum FROM dealers WHERE id = ?");
    $stmt->bind_param('i', $did);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stmt->close();

    if (!$row) break;

    $sum = sprintf("%.2f", $row['sum']);
    $intrst = null;

    if ($adm != 2) {
        $stmt2 = $link->prepare(
            "SELECT COUNT(*) AS cnt FROM pdates JOIN accounts ON pdates.user_id = accounts.id WHERE accounts.dealer = ? AND pdates.dend >= NOW()"
        );
        $stmt2->bind_param('i', $did);
        $stmt2->execute();
        $cnt = $stmt2->get_result()->fetch_assoc()['cnt'];
        $stmt2->close();
        $intrst = getInterestRate(intval($cnt));
    }

    if ($sum !== $lastSum || $intrst !== $lastIntrst) {
        $data = ['s' => $sum];
        if ($intrst !== null) $data['i'] = $intrst;
        echo "data: " . json_encode($data) . "\n\n";
        ob_flush();
        flush();
        $lastSum = $sum;
        $lastIntrst = $intrst;
    }

    sleep(5);
}
