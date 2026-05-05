<?php
include_once("config.php");
checkLoggedIn("yes");

// --- Helper functions ---

function jsonExit(array $data): void {
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function failAndExit(mysqli $link, int $did, string $message): void {
    $ip = $_SERVER['REMOTE_ADDR'];
    $stmt = $link->prepare("INSERT INTO buy_fail (did, ip) VALUES (?, ?)");
    $stmt->bind_param("is", $did, $ip);
    $stmt->execute();
    $stmt->close();
    jsonExit(["m" => $message]);
}

/**
 * Calculate IPTV package price based on admin level and currency
 */
function calcIptvPrice(array $pp, int $months, int $adminLevel, int $currency): float {
    return match (true) {
        ($currency == 0 && in_array($adminLevel, [0, 1])) => $pp['price'] * $months,
        ($adminLevel == 2 && $currency == 1)               => $pp['paynet'] * $months,
        ($adminLevel == 3 && $currency == 0)               => $pp['special'] * $months,
        ($adminLevel == 4 && $currency == 0)               => $pp['special2'] * $months,
        ($adminLevel == 14 && $currency == 1)              => $pp['zamir'] * $months,
        default                                            => $pp['sum'] * $months,
    };
}

/**
 * Sync user status and expiry to Redis (for local IPTV users)
 */
function syncRedisUser(string $token, int $expireTimestamp): void {
    if (empty($token)) return;

    try {
        static $redis = null;
        if ($redis === null) {
            $redis = new TinyRedis();
            $redis->connect('45.9.73.98', 6379);
            $redis->execute(['AUTH', 'qw34rfvgtU9snaWE']);
        }

        $redis->execute(['SET', "user:{$token}:status", "active"]);
        $redis->execute(['SET', "user:{$token}:expire", $expireTimestamp]);

        $ttl = $expireTimestamp - time();
        if ($ttl > 0) {
            $redis->execute(['EXPIRE', "user:{$token}:status", $ttl]);
            $redis->execute(['EXPIRE', "user:{$token}:expire", $ttl]);
        }
    } catch (Exception $e) {
        error_log("Redis Sync Error: " . $e->getMessage());
    }
}

/**
 * Get filename for IPTV playlist (plname or iptvkey)
 */
function iptvFileName(?string $plname, string $iptvkey): string {
    return (!empty($plname)) ? $plname : $iptvkey;
}

// --- Init ---

$dta = [];
$dealer  = $_SESSION['d'];
$did     = $dealer ? $_SESSION['i'] : 0;
$adm     = $_SESSION['a'];
$nw      = time();
$cr      = $_SESSION['c'];

$accdsum = 0;
$accdlim = 0;
$accpp   = 0;
$mindays = 0;

if ($dealer) {
    $stmt = $link->prepare(
        "SELECT `sum`, `limit`, postpaid, mindays, stop_disable FROM dealers WHERE id = ?"
    );
    $stmt->bind_param("i", $did);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($row = $res->fetch_assoc()) {
        $accdsum = $row['sum'];
        $accdlim = $row['limit'];
        $accpp   = (int)($row['postpaid'] ?? 0);
        $mindays = $row['mindays'];
        $dta['sd'] = $row['stop_disable'];
    }
    $stmt->close();
}

// ========================
// Handler: Set CDN
// ========================
if (isset($_POST["u"]) && isset($_POST["cdn"])) {
    $cdn  = $_POST["cdn"];
    $user = $_POST["u"];

    $stmt = $link->prepare("UPDATE accounts SET iptvcdn = ? WHERE user = ?");
    $stmt->bind_param("ss", $cdn, $user);
    $stmt->execute();
    $stmt->close();

    $stmt = $link->prepare("SELECT iptvusr FROM accounts WHERE user = ?");
    $stmt->bind_param("s", $user);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($row = $res->fetch_assoc()) {
        $r = ilookSetCDN($row['iptvusr'], $cdn);
        $d = json_decode($r);
        $dta['m'] = ($d->state === "warning" && $d->cur_cdn_id === $cdn)
            ? 'Аккаунт уже подключен к данному серверу'
            : 'Сервер сменён. Изменения вступят в силу в течение 5-10 мин.';
        echo json_encode($dta);
    }
    $stmt->close();
    exit();
}

// ========================
// Handler: Set DOM
// ========================
if (isset($_POST["u"]) && isset($_POST["dom"])) {
    $dom  = $_POST["dom"];
    $user = $_POST["u"];

    $stmt = $link->prepare("UPDATE accounts SET iptvsdom = ? WHERE user = ?");
    $stmt->bind_param("ss", $dom, $user);
    $stmt->execute();
    $stmt->close();

    $stmt = $link->prepare("SELECT iptvusr FROM accounts WHERE user = ?");
    $stmt->bind_param("s", $user);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($row = $res->fetch_assoc()) {
        $r = ilookSetDom($row['iptvusr'], $dom);
        $d = json_decode($r);
        $dta['m'] = ($d->state === "success")
            ? 'DOM сменён'
            : 'Произошла ошибка при сохранении DOM';
        echo json_encode($dta);
    }
    $stmt->close();
    exit();
}

// ========================
// Handler: Reset Key
// ========================
if (isset($_POST["u"]) && isset($_POST["k"])) {
    $user = $_POST["u"];

    $stmt = $link->prepare("SELECT iptvusr FROM accounts WHERE user = ?");
    $stmt->bind_param("s", $user);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($row = $res->fetch_assoc()) {
        $r = ilookResetKey($row['iptvusr']);
        $d = json_decode($r);

        if ($d->state === "success") {
            $dta['m'] = $d->message;
            $dta['k'] = $d->key;

            $stmt2 = $link->prepare("UPDATE accounts SET iptvkey = ? WHERE user = ?");
            $stmt2->bind_param("ss", $d->key, $user);
            $stmt2->execute();
            $stmt2->close();
        } else {
            $dta['m'] = 'Смена KEY не удалась. Попробуйте позже ещё раз';
        }
        echo json_encode($dta);
    }
    $stmt->close();
    exit();
}

// ================================
// Handler: Set Groups / Playlist
// ================================
if (isset($_POST["grp"]) && isset($_POST["u"]) && isset($_POST["plnm"])) {
    header('Content-Type: application/json');

    $grp   = $_POST["grp"];
    $user  = $_POST["u"];
    $plnum = $_POST["plnm"];

    $stmt = $link->prepare("UPDATE accounts SET iptvplaylist = ?, grpvariant = ? WHERE user = ?");
    $stmt->bind_param("sis", $grp, $plnum, $user);
    $stmt->execute();
    $stmt->close();

    $stmt = $link->prepare(
        "SELECT iptvusr, iptvurl, iptvkey, plname, iptvactdate, iptvmonths,
                islocal, grpvariant, token, iptvplaylist
         FROM accounts WHERE user = ?"
    );
    $stmt->bind_param("s", $user);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($row = $res->fetch_assoc()) {
        $islocal = intval($row['islocal']);
        $fileName = iptvFileName($row['plname'], $row['iptvkey']);

        if (!$islocal) {
            // External IPTV
            $r = ilookSetGroups($row['iptvusr'], $grp, $plnum);
            $d = json_decode($r);
            if ($d->state) {
                downloadAndSaveFile($fileName, $row['iptvurl']);
            }
        } else {
            // Local IPTV
            cream3u8(
                "/var/www/p/{$fileName}.m3u8",
                $row['grpvariant'], $row['plname'], $row['iptvplaylist'],
                '45.9.73.98:8123', '', '', $row['token']
            );

            if ($islocal == 1) {
                $mparts = ($row['iptvmonths'] !== null && $row['iptvmonths'])
                    ? explode(":", $row['iptvmonths'])
                    : [0, 0];

                $edate = addMonths($row['iptvactdate'], $mparts[0]);

                if (!empty($row['token']) && $edate > $nw) {
                    syncRedisUser($row['token'], $edate);
                }
            }
        }

        $dta['m'] = "Группы каналов установлены";
        echo json_encode($dta);
    }
    $stmt->close();
    exit();
}

// ================================
// Handler: IPTV Package Purchase
// ================================
if (isset($_POST['uid']) && (isset($_POST['pb']) && isset($_POST['m']) || isset($_POST['tw']))) {
    $uid = trim($_POST['uid']);
    $tw = 0;

    // --- Get account info ---
    $stmt = $link->prepare(
        "SELECT id, user, tcid, tblock, sum, dealer, paused FROM accounts WHERE id = ?"
    );
    $stmt->bind_param("i", $uid);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows != 1) {
        $stmt->close();
        jsonExit(["m" => "ПОЛЬЗОВАТЕЛЬ С ТАКИМ ЛОГИНОМ НЕ НАЙДЕН"]);
    }

    $r = $res->fetch_assoc();
    $accid  = $r['id'];
    $accsum = $r['sum'];
    $udid   = $r['dealer'];
    $user   = $r['user'];
    $tcid   = $r['tcid'];
    $tblock = $r['tblock'];
    $stmt->close();

    // Dealer/admin permission flag
    $dta["d"] = ($did == $udid || (!$dealer && $user) || $adm) ? 1 : 0;

    // First balance check
    if ($accpp != 1 && $accdsum <= 0) {
        jsonExit(["m" => "НА БАЛАНСЕ НЕДОСТАТОЧНО СРЕДСТВ"]);
    }

    // --- Get extended IPTV account info ---
    $stmt = $link->prepare(
        "SELECT id, user, iptvkey, iptvusr, tcid, tblock, sum, dealer,
                iptvactdate, iptvmonths, iptvauto, twin, plname, islocal, token
         FROM accounts WHERE id = ?"
    );
    $stmt->bind_param("i", $uid);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows != 1) {
        $stmt->close();
        jsonExit(["m" => "ПОЛЬЗОВАТЕЛЬ С ТАКИМ ЛОГИНОМ НЕ НАЙДЕН"]);
    }

    $r = $res->fetch_assoc();
    $accid       = $r['id'];
    $accsum      = $r['sum'];
    $udid        = $r['dealer'];
    $usr         = $r['user'];
    $iptvuser    = $r['iptvusr'];
    $tcid        = $r['tcid'];
    $tblock      = $r['tblock'];
    $iptvactdate = $r['iptvactdate'];
    $iptvmonths  = $r['iptvmonths'];
    $switch      = $r['iptvauto'];
    $iptvkey     = $r['iptvkey'];
    $plname      = $r['plname'];
    $islocal     = intval($r['islocal']);
    $token       = $r['token'];
    $tw          = $islocal ? 0 : (intval($r['twin']) ?? null);
    $hasTwin     = !in_array($tw, [null, 0, '0', ''], true);
    $stmt->close();

    // Second balance check
    $hasBalance = ($accpp != 1 && $accdsum > 0)
               || ($accpp == 1)
               || ($adm == 2);

    if ($hasBalance) {
        $pb     = $link->real_escape_string(trim($_POST['pb']));
        $months = $link->real_escape_string($_POST['m']);

        // --- Twin assignment logic ---
        if (isset($_POST['tw']) && $hasTwin) {
            $tw = intval($_POST['tw']);
        } elseif (isCandidate(intval($uid))) {
            if ($slot = getSlot($months)) {
                $tw = intval($slot['id']);
                $stmt = $link->prepare("UPDATE accounts SET twin = ? WHERE id = ?");
                $stmt->bind_param("ii", $tw, $uid);
                $stmt->execute();
                $stmt->close();
            }
        } else {
            $stmt = $link->prepare("UPDATE accounts SET twin = 0 WHERE id = ?");
            $stmt->bind_param("i", $uid);
            $stmt->execute();
            $stmt->close();
        }

    } elseif ($accdsum == 0 && isset($_POST['pb'])) {
        failAndExit($link, $did, "НА БАЛАНСЕ НЕДОСТАТОЧНО СРЕДСТВ");
    }

    // --- Calculate price ---
    $sum = 0;
    $notmess = [];

    if (isset($pb) && strlen($pb) > 0) {
        $stmt = $link->prepare(
            "SELECT pname, price, sum, paynet, special, special2, zamir FROM packets WHERE id = ?"
        );
        $stmt->bind_param("i", $pb);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res->num_rows != 1) {
            $stmt->close();
            failAndExit($link, $did, "ПРОИЗОШЛА ОШИБКА, НЕ УКАЗАН ПАКЕТ ДЛЯ ПОКУПКИ");
        }

        $pp = $res->fetch_assoc();
        $stmt->close();

        $notmess['pname'] = $pp['pname'];
        $calcsum = calcIptvPrice($pp, $months, $adm, $cr);
        $sum += $calcsum;

        if ($sum < 0) {
            failAndExit($link, $did, "НА БАЛАНСЕ НЕДОСТАТОЧНО СРЕДСТВ");
        }

        if ($dealer) {
            $accsum = $accdsum;
        }

        // Final affordability check
        $canProceed = ($accpp && (($accsum + $sum) <= $accdlim || !$accdlim))
                   || (!$accpp && $accsum >= $sum);

        if ($canProceed) {
            $rowid = 0;
            $switchertoupdate = 0;
            $iptvauto = 0;
            $stillact = 0;

            // Parse existing months
            $mparts = ($iptvmonths !== null && $iptvmonths)
                ? explode(":", $iptvmonths)
                : [0, 0];

            // --- Purchase logic (admin=1 or dealer) ---
            if ((int)$adm == 1 || (int)$dealer > 0) {
                $psum      = $calcsum;
                $dinterval = $months;
                $idd       = $pb;

                // Check IPTV status for external non-twin users
                $ttt = addMonths($iptvactdate, $mparts[0]) - $nw;
                if ($ttt > 86400 && !$tw && !$islocal) {
                    $html   = ilookChkacc($iptvuser);
                    $active = extractText($html, 'tariffState');
                    if ($active === "активен") {
                        $stillact = 1;
                    }
                }

                // Determine: Extension (3) or Activation (2)
                if ($stillact == 1 || ($iptvactdate && addMonths($iptvactdate, $mparts[0]) >= $nw)) {
                    // === EXTENSION ===
                    $action  = 3;
                    $dta["m"] = "ПРОДЛЕНИЕ";
                    $mdiff   = ($mparts[0] + $months) . ":" . $mparts[1];
                    $dta["e"] = addMonths($iptvactdate, $mparts[0] + $months);

                    // External API (non-local only)
                    if ($tw == 0 && $islocal != 1) {
                        if ($stillact == 1 && $switch != 1) {
                            $apiResult = json_decode(ilookToggleAuto($iptvuser, 1), true);
                            $iptvauto  = ($apiResult['state'] == 'success') ? 1 : $iptvauto;
                        } else {
                            $iptvauto = $months > 1 ? 1 : 0;
                        }
                    }
                    if ($islocal == 1) {
                        $iptvauto = $months > 1 ? 1 : 0;
                    }

                    // Update account
                    $query = "UPDATE accounts SET iptvmonths = ?, iptvauto = ?";
                    $params = [$mdiff, $iptvauto];
                    $types  = "si";

                    if ($tw != 0 && $tw !== '' && $tw !== null) {
                        $query .= ", twin = ?";
                        $params[] = $tw;
                        $types .= "i";
                    }
                    $query .= " WHERE id = ?";
                    $params[] = $accid;
                    $types .= "i";

                    logQuery($query);
                    $stmt = $link->prepare($query);
                    $stmt->bind_param($types, ...$params);
                    $stmt->execute();
                    $stmt->close();

                    logQuery("{$dta["m"]} tw=$tw stillact=$stillact switch=$switch months=$months islocal=$islocal");

                } else {
                    // === ACTIVATION ===
                    $action  = 2;
                    $dta["m"] = "АКТИВАЦИЯ";
                    $mdiff   = "$months:1";
                    $dta["e"] = addMonths($nw, $months);

                    // External API (non-local only)
                    if ($tw == 0 && $islocal != 1) {
                        if ($months > 1 || $stillact == 1) {
                            $apiResult = json_decode(ilookToggleAuto($iptvuser, 1), true);
                            $iptvauto  = ($apiResult['state'] == 'success') ? 1 : $iptvauto;
                        }
                    }
                    if ($islocal == 1) {
                        $iptvauto = $months > 1 ? 1 : 0;
                    }

                    // Update account
                    $query = "UPDATE accounts SET iptvmonths = ?, iptvactdate = ?, iptvauto = ?";
                    $params = [$mdiff, $nw, $iptvauto];
                    $types  = "sii";

                    if ($tw != 0 && $tw !== '' && $tw !== null) {
                        $query .= ", twin = ?";
                        $params[] = $tw;
                        $types .= "i";
                    }
                    $query .= " WHERE id = ?";
                    $params[] = $accid;
                    $types .= "i";

                    logQuery($query);
                    $stmt = $link->prepare($query);
                    $stmt->bind_param($types, ...$params);
                    $stmt->execute();
                    $stmt->close();
                }

                // --- Redis sync for local users ---
                if ($islocal == 1) {
                    syncRedisUser($token, $dta["e"]);
                }

                // --- Insert bphistory ---
                $ostafter = $accpp ? $accsum + $sum : $accsum - $sum;

                $notmess['action'] = $action;
                $notmess['days']   = $dinterval;

                $dendStr = date('Y-m-d H:i:s', $dta["e"]);

                if ($action == 3) {
                    $stmt = $link->prepare(
                        "INSERT INTO bphistory (did, rowid, action, uid, pid, time, dend, days, sum, previd, ost, ostafter, currency, postpaid)
                         VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, ?, 0, ?, ?, ?, ?)"
                    );
                    $stmt->bind_param(
                        "iiiiisidddii",
                        $did, $rowid, $action, $accid, $idd,
                        $dendStr, $dinterval, $psum,
                        $accsum, $ostafter, $cr, $accpp
                    );
                } else {
                    $stmt = $link->prepare(
                        "INSERT INTO bphistory (did, rowid, action, uid, pid, time, dend, days, sum, previd, ost, ostafter, currency, postpaid)
                         VALUES (?, ?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? MONTH), ?, ?, 0, ?, ?, ?, ?)"
                    );
                    $stmt->bind_param(
                        "iiiiiiidddii",
                        $did, $rowid, $action, $accid, $idd,
                        $months, $dinterval, $psum,
                        $accsum, $ostafter, $cr, $accpp
                    );
                }
                $stmt->execute();
                $stmt->close();

                // --- Telegram notification ---
                if ($tcid && $tblock != 1) {
                    $tosend = "Была произведена оплата.\nЛогин " . $usr . "\n";
                    $tosend .= "Пакет " . $notmess['pname'];
                    if ($notmess['action'] == 3) {
                        $tosend .= " продлён";
                    }
                    $tosend .= " на " . $notmess['days'] . " мес.\n@Mpolbot";

                    $result = tgSend(TG_TOKEN, $tcid, $tosend);
                    if (!$result['ok'] && ($result['error_code'] ?? 0) == 403) {
                        $stmt = $link->prepare("UPDATE accounts SET tblock = 1 WHERE tcid = ?");
                        $stmt->bind_param("s", $tcid);
                        $stmt->execute();
                        $stmt->close();
                    }

                    tgSend(TG_TOKEN, TG_ADMIN, $tosend);
                }
            }

            // --- Update dealer balance ---
            $tsum = $accpp ? $accdsum + $sum : $accdsum - $sum;
            $dta["sum"] = $tsum;
            logQuery(print_r($dta, true));

            if ($dealer) {
                $stmt = $link->prepare("UPDATE dealers SET sum = ? WHERE id = ?");
                $stmt->bind_param("di", $tsum, $did);
                $stmt->execute();
                $stmt->close();
            }

            // --- Twin playlist file copy ---
            if ($tw) {
                $stmt = $link->prepare("SELECT iptvkey, plname FROM accounts WHERE id = ?");
                $stmt->bind_param("i", $tw);
                $stmt->execute();
                $res = $stmt->get_result();

                if ($res && $row = $res->fetch_assoc()) {
                    $twinFile = iptvFileName($row['plname'], $row['iptvkey']);
                    $mainFile = iptvFileName($plname, $iptvkey);
                    rplLnk("/var/www/p/{$twinFile}.m3u8", "/var/www/p/{$mainFile}.m3u8");
                }
                $stmt->close();
            }

        } else {
            $dta["m"] = $accpp ? "ПРЕВЫШЕН ЛИМИТ" : "НА БАЛАНСЕ НЕДОСТАТОЧНО СРЕДСТВ";
        }
    }

    echo json_encode($dta);
    exit();
}

$link->close();
