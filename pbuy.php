<?php
include_once("config.php");
checkLoggedIn("yes");

// --- Helper functions ---

/**
 * Send JSON response and exit
 */
function jsonExit(array $data): void {
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/**
 * Log failed buy attempt and send error JSON
 */
function failAndExit(mysqli $link, int $did, string $message): void {
    $ip = $_SERVER['REMOTE_ADDR'];
    $stmt = $link->prepare("INSERT INTO buy_fail (did, ip) VALUES (?, ?)");
    $stmt->bind_param("is", $did, $ip);
    $stmt->execute();
    $stmt->close();
    jsonExit(["m" => $message]);
}

/**
 * Calculate package price based on admin level and currency
 */
function calcPackagePrice(array $pp, int $days, int $adminLevel, int $currency): float {
    $priceField = match ($adminLevel) {
        0, 1    => 'price',
        2       => ($currency == 1) ? 'paynet' : 'sum',
        3, 4    => 'special',
        14      => 'zamir',
        default => 'sum',
    };
    return ($pp[$priceField] / 30) * $days;
}

// --- Init ---

$dta = [];
$telegramMessages = [];

$dealer  = $_SESSION['d'];
$user    = $_SESSION['l'];
$hash    = $_SESSION['h'];
$cr      = $_SESSION['c'];
$did     = $dealer ? $_SESSION['i'] : 0;
$adm     = $_SESSION['a'];

$accdsum = 0;
$accdlim = 0;
$accpp   = 0;
$mindays = 0;
$skidka  = 0;

$nw = time();

// --- Load dealer info ---

if ($dealer) {
    $stmt = $link->prepare(
        "SELECT `sum`, `limit`, postpaid, mindays, stop_disable FROM dealers WHERE id = ?"
    );
    $stmt->bind_param("i", $did);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($row = $res->fetch_assoc()) {
        $accdsum  = $row['sum'];
        $accdlim  = $row['limit'];
        $accpp    = (int)($row['postpaid'] ?? 0);
        $mindays  = $row['mindays'];
        $dta['sd'] = $row['stop_disable'];
    }
    $stmt->close();

    // Count active accounts for discount
    $stmt = $link->prepare(
        "SELECT COUNT(*) AS cnt
         FROM pdates
         JOIN accounts ON pdates.user_id = accounts.id
         WHERE accounts.dealer = ? AND pdates.dend >= NOW()"
    );
    $stmt->bind_param("i", $dealer);
    $stmt->execute();
    $active = $stmt->get_result()->fetch_assoc()['cnt'];
    $stmt->close();

    $skidka = getInterestRate($active);
}

// ========================
// Handler: refresh account
// ========================
if (isset($_POST['racc'])) {
    if ($dealer) {
        $dta['s'] = $accdsum;
        if ($adm != 2) {
            $dta['i'] = $skidka;
        }
        header('Content-Type: application/json');
        echo json_encode($dta);
    }
    exit;
}

// ============================
// Handler: balance transfer
// ============================
if (isset($_POST["sum"]) && isset($_POST["l"]) && is_numeric($_POST["sum"]) && floatval($_POST["sum"]) != 0) {
    $sum = floatval($_POST["sum"]);

    if ($sum > $accdsum) {
        jsonExit(["m" => "НА БАЛАНСЕ НЕДОСТАТОЧНО СРЕДСТВ"]);
    }

    $login = $_POST["l"];

    $stmt = $link->prepare("SELECT id, sum, req FROM accounts WHERE user = ? LIMIT 1");
    $stmt->bind_param("s", $login);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows != 1) {
        $stmt->close();
        jsonExit(["m" => "ПОЛЬЗОВАТЕЛЬ НЕ НАЙДЕН"]);
    }

    $row    = $res->fetch_assoc();
    $accid  = $row['id'];
    $accsum = $row['sum'];
    $accreq = $row['req'];
    $stmt->close();

    $canDeposit  = ($sum > 0 && $accdsum >= $sum);
    $canWithdraw = ($sum < 0 && $accsum >= abs($sum) && isset($accreq));

    if (!$canDeposit && !$canWithdraw) {
        jsonExit(["m" => "НА БАЛАНСЕ НЕДОСТАТОЧНО СРЕДСТВ"]);
    }

    $action = ($sum > 0) ? 1 : 91;

    $link->begin_transaction();
    try {
        $stmt = $link->prepare("UPDATE accounts SET sum = sum + ? WHERE id = ?");
        $stmt->bind_param("di", $sum, $accid);
        $stmt->execute();
        $stmt->close();

        $stmt = $link->prepare("UPDATE dealers SET sum = sum - ? WHERE id = ?");
        $stmt->bind_param("di", $sum, $did);
        $stmt->execute();
        $stmt->close();

        $stmt = $link->prepare(
            "INSERT INTO bphistory (did, action, uid, pid, time, dend, days, sum, ost, ostafter)
             VALUES (?, ?, ?, 0, NOW(), 0, 0, ?, ?, ?)"
        );
        $ostafter = $accsum - $sum;
        $stmt->bind_param("iiiddd", $did, $action, $accid, $sum, $accsum, $ostafter);
        $stmt->execute();
        $dta['id'] = $link->insert_id;
        $stmt->close();

        $link->commit();
    } catch (Exception $e) {
        $link->rollback();
        jsonExit(["m" => "ОШИБКА ТРАНЗАКЦИИ"]);
    }

    // Read updated balances
    $stmt = $link->prepare("SELECT `sum` FROM dealers WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $did);
    $stmt->execute();
    $dta['d'] = $stmt->get_result()->fetch_assoc()['sum'];
    $stmt->close();

    $stmt = $link->prepare("SELECT sum FROM accounts WHERE user = ? LIMIT 1");
    $stmt->bind_param("s", $login);
    $stmt->execute();
    $dta['ad'] = $stmt->get_result()->fetch_assoc()['sum'];
    $stmt->close();

    jsonExit($dta);
}

// ============================
// Handler: package purchase
// ============================
if (isset($_POST['uid']) && isset($_POST['pb'])) {
    $uid = trim($_POST['uid']);
    $dta["i"] = $skidka;

    $stmt = $link->prepare(
        "SELECT id, user, tcid, tblock, sum, dealer, paused FROM accounts WHERE id = ?"
    );
    $stmt->bind_param("s", $uid);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows != 1) {
        $stmt->close();
        jsonExit(["m" => "ПОЛЬЗОВАТЕЛЬ С ТАКИМ ЛОГИНОМ НЕ НАЙДЕН"]);
    }

    $r      = $res->fetch_assoc();
    $accid  = $r['id'];
    $accsum = $r['sum'];
    $udid   = $r['dealer'];
    $user   = $r['user'];
    $tcid   = $r['tcid'];
    $tblock = $r['tblock'];
    $stmt->close();

    if ($r['paused'] == 1) {
        jsonExit(["m" => "АККАУНТ НА ПАУЗЕ"]);
    }

    // Check dealer/admin permissions
    $dta["d"] = ($did == $udid || (!$dealer && $user) || $adm) ? 1 : 0;

    // Check balance conditions
    $hasBalance = ($accpp != 1 && $accdsum > 0)
               || ($accpp == 1)
               || ($adm == 2);

    if (!$hasBalance) {
        failAndExit($link, $did, "НА БАЛАНСЕ НЕДОСТАТОЧНО СРЕДСТВ");
    }

    $pb = $_POST['pb'];
    if (!$pb || !is_array($pb)) {
        jsonExit(["m" => "ПРОИЗОШЛА ОШИБКА"]);
    }

    // --- Phase 1: Calculate total cost ---

    $sum = 0;
    $notmess = [];
    $packetsCache = [];

    foreach ($pb as $i => $package) {
        $pid = (int)$package[0];
        $days = (int)$package[1];

        $stmt = $link->prepare(
            "SELECT pname, price, sum, paynet, special, special2, zamir FROM packets WHERE id = ?"
        );
        $stmt->bind_param("i", $pid);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res->num_rows != 1) {
            $stmt->close();
            failAndExit($link, $did, "ПРОИЗОШЛА ОШИБКА");
        }

        $pp = $res->fetch_assoc();
        $stmt->close();
        $packetsCache[$i] = $pp;

        $notmess[$i]['pname'] = $pp['pname'];

        $calcsum = calcPackagePrice($pp, $days, $adm, $cr);
        $pb[$i][2] = ($adm != 2) ? $calcsum * (1 - $skidka / 100) : $calcsum;
        $sum += $pb[$i][2];
    }

    if ($sum < 0) {
        failAndExit($link, $did, "НА БАЛАНСЕ НЕДОСТАТОЧНО СРЕДСТВ");
    }

    // Effective balance for check
    $effectiveBalance = $dealer ? $accdsum : $accsum;

    $canProceed = ($accpp && (($effectiveBalance + $sum) <= $accdlim || !$accdlim))
               || (!$accpp && $effectiveBalance >= $sum);

    if (!$canProceed) {
        $msg = $accpp ? "ПРЕВЫШЕН ЛИМИТ" : "НА БАЛАНСЕ НЕДОСТАТОЧНО СРЕДСТВ";
        jsonExit(["m" => $msg, "success" => 0]);
    }

    // --- Phase 2: Execute transaction ---

    $ee = [];
    $ostafter = $accdsum; // running balance

    try {
        $link->begin_transaction();

        foreach ($pb as $i => $package) {
            [$idd, $dinterval, $psum] = $package;
            $previd = 0;

            if ($dinterval < $mindays && $adm != 1) {
                continue;
            }

            // Check existing subscription with row lock
            $stmt = $link->prepare(
                "SELECT pid, UNIX_TIMESTAMP(dend) AS udend
                 FROM pdates
                 WHERE user_id = ? AND packet = ? FOR UPDATE"
            );
            $stmt->bind_param("ii", $accid, $idd);
            $stmt->execute();
            $res = $stmt->get_result();

            $gmdend = null;

            if ($row = $res->fetch_assoc()) {
                // Existing subscription
                $pid  = $row['pid'];
                $udend = $row['udend'];
                $stmt->close();

                // Check date discrepancy with bphistory
                $stmt = $link->prepare(
                    "SELECT UNIX_TIMESTAMP(dend) AS history_dend
                     FROM bphistory
                     WHERE uid = ? AND undone != 1
                     ORDER BY dend DESC LIMIT 1"
                );
                $stmt->bind_param("i", $accid);
                $stmt->execute();
                $histRes = $stmt->get_result();

                if ($histRow = $histRes->fetch_assoc()) {
                    $diff = abs($udend - $histRow['history_dend']);
                    if ($diff > 3600) {
                        $telegramMessages[] = [
                            'to'   => TG_ADMIN,
                            'text' => "Warning: Date discrepancy for user $user\n"
                                    . "Packet: $idd\n"
                                    . "pdates: " . date('Y-m-d H:i:s', $udend) . "\n"
                                    . "bphistory: " . date('Y-m-d H:i:s', $histRow['history_dend']) . "\n"
                                    . "Diff: " . round($diff / 3600, 2) . " hours"
                        ];
                    }
                }
                $stmt->close();

                if ($udend > $nw) {
                    // Extend existing active subscription
                    $action = 3;
                    $stmt = $link->prepare(
                        "UPDATE pdates SET dend = DATE_ADD(dend, INTERVAL ? DAY), dstart = NOW()
                         WHERE user_id = ? AND packet = ? AND pid = ?"
                    );
                    $stmt->bind_param("iiii", $dinterval, $accid, $idd, $pid);
                    $stmt->execute();
                    $stmt->close();

                    // Get previous bphistory record
                    $stmt = $link->prepare(
                        "SELECT bpid FROM bphistory
                         WHERE uid = ? AND pid = ? AND action IN (2,3) AND (undone IS NULL OR undone = 0)
                         ORDER BY bpid DESC LIMIT 1"
                    );
                    $stmt->bind_param("ii", $accid, $idd);
                    $stmt->execute();
                    $prevRes = $stmt->get_result();
                    if ($prevRow = $prevRes->fetch_assoc()) {
                        $previd = $prevRow['bpid'];
                    }
                    $stmt->close();
                } else {
                    // Reactivate expired subscription
                    $action = 2;
                    $stmt = $link->prepare(
                        "UPDATE pdates SET dend = DATE_ADD(NOW(), INTERVAL ? DAY), dstart = NOW()
                         WHERE user_id = ? AND packet = ? AND pid = ?"
                    );
                    $stmt->bind_param("iiii", $dinterval, $accid, $idd, $pid);
                    $stmt->execute();
                    $stmt->close();
                }

                $gmdend = date('Y-m-d H:i:s', $udend);
            } else {
                // New subscription
                $stmt->close();
                $action = 2;
                $stmt = $link->prepare(
                    "INSERT INTO pdates (user_id, dstart, dend, packet)
                     VALUES (?, NOW(), DATE_ADD(NOW(), INTERVAL ? DAY), ?)"
                );
                $stmt->bind_param("iii", $accid, $dinterval, $idd);
                $stmt->execute();
                $pid = $link->insert_id;
                $stmt->close();
            }

            $notmess[$i]['action'] = $action;
            $notmess[$i]['days']   = $dinterval;

            // Update running balance
            $ostafter = $accpp ? $ostafter + $psum : $ostafter - $psum;
            $rpl = ($action == 3 && $gmdend) ? "'$gmdend'" : "NOW()";

            $stmt = $link->prepare(
                "INSERT INTO bphistory (rowid, previd, did, action, uid, pid, time, dend, days, sum, ost, ostafter, currency, postpaid)
                 VALUES (?, ?, ?, ?, ?, ?, NOW(), DATE_ADD($rpl, INTERVAL ? DAY), ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param(
                "iiiiiiiidddii",
                $pid, $previd, $did, $action, $accid, $idd,
                $dinterval, $dinterval, $psum, $accdsum, $ostafter, $cr, $accpp
            );
            $stmt->execute();
            $ee[$i][0] = $link->insert_id;
            $stmt->close();

            $accdsum = $ostafter;
        }

        // Update dealer balance once (not per package)
        if ($dealer) {
            $balanceChange = $accpp ? $sum : -$sum;
            $stmt = $link->prepare("UPDATE dealers SET sum = sum + ? WHERE id = ?");
            $stmt->bind_param("di", $balanceChange, $did);
            $stmt->execute();
            $stmt->close();
        }

        // Build telegram notification
        if ($tcid && $tblock != 1) {
            $tosend = "Была произведена оплата.\nЛогин " . $user . "\n";
            foreach ($pb as $i => $package) {
                if (!isset($notmess[$i])) continue;
                $tosend .= "Пакет " . $notmess[$i]['pname'];
                if ($notmess[$i]['action'] == 3) $tosend .= " продлён";
                $tosend .= " на " . $notmess[$i]['days'] . " д.\n";
            }
            $tosend .= "@Mpolbot";
            $telegramMessages[] = ['to' => $tcid,     'text' => $tosend, 'tcid_check' => true];
            $telegramMessages[] = ['to' => TG_ADMIN,  'text' => $tosend];
        }

        $link->commit();

    } catch (Exception $e) {
        $link->rollback();
        die("Transaction failed: " . $e->getMessage());
    }

    // Send all telegram messages AFTER successful commit
    foreach ($telegramMessages as $msg) {
        $result = tgSend(TG_TOKEN, $msg['to'], $msg['text']);
        if (!empty($msg['tcid_check']) && !$result['ok'] && ($result['error_code'] ?? 0) == 403) {
            $stmt = $link->prepare("UPDATE accounts SET tblock = 1 WHERE tcid = ?");
            $stmt->bind_param("s", $msg['to']);
            $stmt->execute();
            $stmt->close();
        }
    }

    $dta["sum"] = $accdsum;
    $dta["md"]  = $mindays;
    $dta["e"]   = $ee;
    $dta["m"]   = "ОПЛАТА ПРОШЛА УСПЕШНО";
    jsonExit($dta);
}

$link->close();
