<?php
include_once("config.php");
checkLoggedIn("yes");

if (isset($_POST['un'])) {
$dealerId = $_SESSION['i'];
$adm = $_SESSION['a'];
$uname = $link->real_escape_string($_POST['un']);
    if ($adm == 1) {
        $stmt = $link->prepare("SELECT paused, UNIX_TIMESTAMP(pdate) as pdate, id FROM accounts WHERE user = ?");
        $stmt->bind_param("s", $uname);
    } else {

        $stmt = $link->prepare("SELECT paused, UNIX_TIMESTAMP(pdate) as pdate, id FROM accounts WHERE user = ? AND dealer = ?");
        $stmt->bind_param("si", $uname, $dealerId);
    }
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows == 1) {
        $row = $res->fetch_assoc();
        $paused = $row['paused'];
        $pdate = $row['pdate'];
        $uid = $row['id'];
        if ($paused == 1) { // Если в паузе
            $stmt = $link->prepare("SELECT UNIX_TIMESTAMP(dstart) as ds, UNIX_TIMESTAMP(dend) as dd, pid, packet FROM pdates WHERE user_id = ? AND paused = 1");
            $stmt->bind_param("s", $uid);
            $stmt->execute();
            $res = $stmt->get_result();
            $rcnt = $res->num_rows;
            $arr['packets'] = [];
            if ($rcnt >= 1) {
                $pdatesRows = [];
                for ($i = 0; $i < $rcnt; $i++) {
                    $pdatesRows[$i] = $res->fetch_assoc();
                }
                for ($i = 0; $i < $rcnt; $i++) {
                    $pd = $pdatesRows[$i]['pid'];
                    $dd = $pdatesRows[$i]['dd'];
		            $ds = $pdatesRows[$i]['ds'];
                    $dcorr = time() - $pdate;
                    $packet = $pdatesRows[$i]['packet'];
		            $stmt = $link->prepare("UPDATE pdates SET dend = DATE_ADD(dend, INTERVAL ? SECOND), paused = 0 WHERE user_id = ? AND pid = ?");
                    $stmt->bind_param("iss", $dcorr, $uid, $pd);
                    if (!$stmt->execute()) {
                        echo "Ошибка обновления pdates для pid=$pd: " . $link->error . "<br>";
                    }
                     $arr['packets'][] =[$packet,$ds,$dd+$dcorr];
                }

                $stmt = $link->prepare("SELECT bphistory.bpid, bphistory.did, bphistory.uid, bphistory.`time` FROM bphistory WHERE bphistory.`action` = '5' AND bphistory.uid = ? ORDER BY bpid DESC LIMIT 1");
                $stmt->bind_param("s", $uid);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res->num_rows == 1) {
                    $row = $res->fetch_assoc();
                    $pauseTime = $row['time'];
                }

                $stmt = $link->prepare("INSERT INTO bphistory (did, rowid, action, uid, pid, time, days, sum, previd, ost, ostafter, pausedate) VALUES (?, 0, 95, ?, 0, NOW(), 0, 0, 0, 0, 0, ?)");
                $stmt->bind_param("sss", $_SESSION['i'], $uid, $pauseTime);
                if (!$stmt->execute()) {
                    echo "Ошибка вставки в bphistory: " . $link->error . "<br>";
                }

                $stmt = $link->prepare("UPDATE accounts SET paused = 0, pdate = NOW() WHERE id = ?");
                $stmt->bind_param("s", $uid);
                if (!$stmt->execute()) {
                    echo "Ошибка обновления accounts: " . $link->error . "<br>";
                }
                $arr['success']=1;
            }
        } else { // Если не в паузе
            if ($row['pdate'] == NULL || floor((time() - $row['pdate']) / 86400) > 7) {
                $stmt = $link->prepare("SELECT pid, UNIX_TIMESTAMP(dend) as dend FROM pdates WHERE user_id = ? AND dend > NOW()");
                $stmt->bind_param("s", $uid);
                $stmt->execute();
                $res = $stmt->get_result();
                $rcnt = $res->num_rows;

                if ($rcnt) {
                    $pdatesRows = [];
                    for ($i = 0; $i < $rcnt; $i++) {
                        $pdatesRows[$i] = $res->fetch_assoc();
                    }

                    $pauseRequired = false; // Флаг для паузы
                    for ($i = 0; $i < $rcnt; $i++) {
                        $pd = $pdatesRows[$i]['pid'];
                        $dend = $pdatesRows[$i]['dend'];
                        //$daysDiff = floor(($dend - time()) / 86400); // Разница в днях между dend и NOW()
                        if (($dend - time()) > 7 * 86400) { // Условие: больше 7 дней
                            $pauseRequired = true;
                            $stmt = $link->prepare("UPDATE pdates SET paused = 1, pdate = NOW() WHERE user_id = ? AND pid = ?");
                            $stmt->bind_param("ss", $uid, $pd);
                            if (!$stmt->execute()) {
                                echo "Ошибка обновления pdates для pid=$pd: " . $link->error . "<br>";
                            }
                        }
                        else{$arr['m']="ТОЛЬКО ЕСЛИ ДО ОКОНЧАНИЯ ПАКЕТА БОЛЬШЕ 7 ДНЕЙ";
                            $arr['success']=0;
                        break;}
                    }
                    if ($pauseRequired) { // Выполняем только если есть записи с разницей > 7 дней
                        $stmt = $link->prepare("UPDATE accounts SET paused = 1, pdate = NOW() WHERE id = ?");
                        $stmt->bind_param("s", $uid);
                        if (!$stmt->execute()) {
                            echo "Ошибка обновления accounts: " . $link->error . "<br>";
                        }
                        $stmt = $link->prepare("INSERT INTO bphistory (did, rowid, action, uid, pid, time, days, sum, previd, ost, ostafter) 
                                                VALUES (?, 0, 5, ?, 0, NOW(), 0, 0, 0, 0, 0)");
                        $stmt->bind_param("ss", $_SESSION['i'], $uid);
                        if (!$stmt->execute()) {
                            echo "Ошибка вставки в bphistory: " . $link->error . "<br>";
                        }
                        $arr['success']=1;
                    }
                }
            }
        }
    } else {
        $arr['success']=0;
    }
    echo json_encode($arr);
}

$link->close();
?>