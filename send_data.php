<?php
include_once("config.php");
checkLoggedIn("yes");

// Получите данные из POST-запроса
$data = json_decode(file_get_contents("php://input"), true);

//echo $data;
if (isset($data['id']) && isset($data['tw'])) {
//    if (isset($_POST["id"]) && isset($_POST["tw"])){
    $twiniptvkey = '';
    $iptvkey = '';
    $tw = $data['tw'];
    $id = $data['id'];
    // Подготовка и выполнение запроса UPDATE
    $stmt = $link->prepare("UPDATE accounts SET twin=? WHERE id=?");
    if ($stmt === false) {
        die("SQL prepare error: " . $link->error);
    }
    $stmt->bind_param("ii", $tw, $id);
    if (!$stmt->execute()) {
        die("SQL execute error: " . $stmt->error);
    }
    $stmt->close();

    // Получение iptvkey для twin
    if ($tw) {
        $stmt = $link->prepare("SELECT iptvkey,plname FROM accounts WHERE id=?");
        if ($stmt === false) {
            die("SQL prepare error: " . $link->error);
        }
        $stmt->bind_param("i", $tw);
        if (!$stmt->execute()) {
            die("SQL execute error: " . $stmt->error);
        }
        $result = $stmt->get_result();
        if ($result->num_rows === 1) {
            $row = $result->fetch_assoc();
            $twiniptvkey = $row['iptvkey'];
	    $twinplname= $row['plname'];
        }
        $stmt->close();
    }

    $stmt = $link->prepare("SELECT iptvusr FROM accounts WHERE id=? AND iptvauto=1");
    if ($stmt === false) {
        die("SQL prepare error: " . $link->error);
    }
    $stmt->bind_param("i", $id);
    if (!$stmt->execute()) {
        die("SQL execute error: " . $stmt->error);
    }
    $result = $stmt->get_result();
    
    if ($result->num_rows === 1) {
        $row = $result->fetch_assoc();
        $login = $row['iptvusr'];
        
        // Вызов функции ilookToggleAuto()
        $r = ilookToggleAuto($login, 0);
        $r = json_decode($r, true);

        // Если успешно, сбрасываем iptvauto в 0
        if ($r['state'] === 'success') {
            $stmt = $link->prepare("UPDATE accounts SET iptvauto=0 WHERE id=?");
            if ($stmt === false) {
                die("SQL prepare error: " . $link->error);
            }
            $stmt->bind_param("i", $id);
            if (!$stmt->execute()) {
                die("SQL execute error: " . $stmt->error);
            }
            $stmt->close();
        }
    }
    $stmt->close();

if($tw)
{
    $stmt = $link->prepare("SELECT iptvkey,plname FROM accounts WHERE id=?");
    if ($stmt === false) {
        die("SQL prepare error: " . $link->error);
    }
    $stmt->bind_param("i", $id);
    if (!$stmt->execute()) {
        die("SQL execute error: " . $stmt->error);
    }
    $result = $stmt->get_result();
    if ($result->num_rows === 1) {
        $row = $result->fetch_assoc();
        $iptvkey = $row['iptvkey'];
        $plname = $row['plname'];
    }
    $stmt->close();

    // Замена ссылок
    if (function_exists('rplLnk')) {
    rplLnk("/var/www/p/" . ($twinplname ? $twinplname : $twiniptvkey) . ".m3u8",
           "/var/www/p/" . ($plname ? $plname : $iptvkey) . ".m3u8");
    } else {
        die("Function rplLnk is not defined.");
    }
}
}

echo json_encode(['status' => 'success', 'message' => 'Data received', 'id' => $id]);
?>
