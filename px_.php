<?php
include_once("config.php");
checkLoggedIn("yes");

$dealerId = $_SESSION['i'];
$user = $_SESSION['l'];
$tarrif = $_SESSION['a'];
$num_elements = 44;
$doru = $_SESSION["d"];
$now = time();
$tz = $_SESSION['timeZoneOffset'];
$adm = $_SESSION['a'];
$dealer = $dealerId;

$response = [
    'status' => 'success',
    'data' => [
        'session' => [
            'i' => $_SESSION['i'],
            'a' => $_SESSION['a'],
            'd' => $_SESSION['d'],
            'c' => $_SESSION['c'],
            'l' => $_SESSION['l']
        ]
    ]
];

if (isset($_POST["l"]) || (isset($_POST["l"]) && isset($_POST["p"]))) {
    $user = $link->real_escape_string(trim($_POST["l"]));
    $p = isset($_POST["p"]) ? $link->real_escape_string(trim($_POST["p"])) : '';
    $searchType = isset($_POST["searchType"]) ? $link->real_escape_string($_POST["searchType"]) : '1'; // По умолчанию "ВСЁ"

    $results = [];
    $multiple = false;

    // Функция для добавления результатов в массив, избегая дубликатов
    function addResult(&$results, $row, $type) {
        foreach ($results as $existing) {
            if ($existing['user'] === $row['user'] && $existing['type'] === $type) {
                return; // Пропускаем дубликат
            }
        }
        $results[] = [
            'user' => $row['user'],
            'phone' => $row['phone'],
            'email' => $row['email'],
            'type' => $type
        ];
    }

    // Нормализация телефона для поиска (убираем всё кроме цифр)
    $phoneSearch = preg_replace('/\D/', '', $user);

    // Логика поиска
    if ($searchType == '1' || $searchType == '2') { // ВСЁ или ЛОГИН
        // Точный поиск по логину
        $qu = "SELECT user, phone, email FROM accounts WHERE user = '$user' AND deleted='0'";
        if ($adm != '1' && $adm != '2') $qu .= " AND dealer='$dealer'";
        $res = $link->query($qu) or die("sql error: " . $link->error_list);
        while ($row = $res->fetch_assoc()) {
            addResult($results, $row, 'login');
        }

        // Частичный поиск, если точный не дал результатов
        if (empty($results) || $searchType == '1') {
            $qu = "SELECT user, phone, email FROM accounts WHERE user LIKE '%$user%' AND deleted='0'";
            if ($adm != '1') $qu .= " AND dealer='$dealer'";
            $res = $link->query($qu) or die("sql error: " . $link->error_list);
            while ($row = $res->fetch_assoc()) {
                addResult($results, $row, 'login');
            }
        }
    }

    if ($searchType == '1' || $searchType == '3') { // ВСЁ или Т.НОМЕР
        // Точный поиск по телефону (сравниваем только цифры)
        $qu = "SELECT user, phone, email FROM accounts WHERE REPLACE(REPLACE(REPLACE(REPLACE(phone,'+',''),' ',''),'-',''),'(','') = '$phoneSearch' AND deleted='0'";
        if ($adm != '1' && $adm != '2') $qu .= " AND dealer='$dealer'";
        $res = $link->query($qu) or die("sql error: " . $link->error_list);
        while ($row = $res->fetch_assoc()) {
            addResult($results, $row, 'phone');
        }

        // Частичный поиск, если точный не дал результатов
        if (empty($results) || $searchType == '1') {
            $qu = "SELECT user, phone, email FROM accounts WHERE REPLACE(REPLACE(REPLACE(REPLACE(phone,'+',''),' ',''),'-',''),'(','') LIKE '%$phoneSearch%' AND deleted='0'";
            if ($adm != '1' && $adm != '2') $qu .= " AND dealer='$dealer'";
            $res = $link->query($qu) or die("sql error: " . $link->error_list);
            while ($row = $res->fetch_assoc()) {
                addResult($results, $row, 'phone');
            }
        }
    }

    if ($searchType == '1' || $searchType == '4') { // ВСЁ или EMAIL
        // Точный поиск по email
        $qu = "SELECT user, phone, email FROM accounts WHERE email = '$user' AND deleted='0'";
        if ($adm != '1' && $adm != '2') $qu .= " AND dealer='$dealer'";
        $res = $link->query($qu) or die("sql error: " . $link->error_list);
        while ($row = $res->fetch_assoc()) {
            addResult($results, $row, 'email');
        }

        // Частичный поиск, если точный не дал результатов
        if (empty($results) || $searchType == '1') {
            $qu = "SELECT user, phone, email FROM accounts WHERE email LIKE '%$user%' AND deleted='0'";
            if ($adm != '1' && $adm != '2') $qu .= " AND dealer='$dealer'";
            $res = $link->query($qu) or die("sql error: " . $link->error_list);
            while ($row = $res->fetch_assoc()) {
                addResult($results, $row, 'email');
            }
        }
    }

    // Проверка результатов
    if (count($results) == 0) {
        $response['status'] = 'error';
        $response['message'] = 'Аккаунт с такими данными не найден';
        echo json_encode($response);
        $link->close();
        exit;
    } elseif (count($results) == 1 && $searchType != '1') {
        // Если найден один результат и поиск не по "ВСЁ", возвращаем данные аккаунта
        $user = $results[0]['user'];
    } else {
        // Множественные результаты
        $response['data']['multiple'] = true;
        $response['data']['results'] = $results;
        echo json_encode($response);
        $link->close();
        exit;
    }

    // Логика для одного аккаунта (без изменений)
    $result = $link->query("SELECT 1 FROM accounts WHERE user = '$user' AND dealer = $dealerId AND deleted!=1 LIMIT 1");
    $sql = "SELECT accounts.id, accounts.iptvusr FROM accounts WHERE `user`='$user' AND deleted!=1";
    $res = $link->query($sql);
    if ((($_SESSION['a'] == 1 || $_SESSION['a'] == 2) && $res->num_rows > 0) || ($res->num_rows > 0 && $result->num_rows > 0)) {
        $row = $res->fetch_assoc();
        $accid = $row['id'];

        $res = $link->query("SELECT id,
            CASE 
                WHEN iptvusr IS NOT NULL AND iptvusr != '' THEN 1 
                ELSE 0 
            END AS iptvusr, dealer, user, pwd, sum, dscr, phone, server, paused, UNIX_TIMESTAMP(pdate) AS pdt, email, DATE_FORMAT(dreg,'%d.%m.%y') AS dreg, sndnote, req, acccardnum, tcid 
            FROM accounts WHERE user='$user' AND deleted!=1 LIMIT 1");
        if ($res->num_rows == 1) {
            $account_data = $res->fetch_assoc();

            $res2 = $link->query("SELECT user FROM dealers WHERE id='{$account_data['dealer']}'");
            unset($account_data['dealer']);
            $dealer_info = $res2->fetch_assoc() ?: ['user' => ''];

            $res2 = $link->query("SELECT mindays FROM dealers WHERE id=$dealerId");
            $mindays = $res2->fetch_assoc()['mindays'] ?? 0;

            $res2 = $link->query("SELECT card, cid, owner, exp FROM cardslist WHERE uid='$accid' AND card!='' AND did=0");
            $cards = [];
            while ($card = $res2->fetch_assoc()) {
                $cards[] = $card;
            }

            $response['data']['account'] = $account_data;
            $response['data']['dealer'] = $dealer_info;
            $response['data']['mindays'] = $mindays;
            $response['data']['cards'] = $cards;

            if ($row["iptvusr"] && ($p == "i" || $p == "")) {
                $query = "SELECT a.id, a.`user`, a.req, a.pwd, a.dealer, a.sum, a.dscr, a.dreg, a.phone, a.email, a.sndnote, a.tcid, a.iptvcdn, a.iptvplaylist, a.iptvkey, ";
                if ($tarrif == 1) $query .= "a.iptvsdom, a.token, a.islocal, a.twin, b.`user` AS b_user, ";
                
                $query .= "a.iptvactdate, a.iptvmonths, a.grpvariant, a.plname, dealers.tz
                    FROM accounts a
                    LEFT JOIN accounts b ON a.twin = b.id
                    INNER JOIN dealers ON a.dealer = dealers.id
                    WHERE a.`user` = '$user' AND a.deleted = '0'
                    GROUP BY a.id, a.`user`, a.req, a.pwd, a.dealer, a.sum, a.dscr, a.dreg, a.phone, a.email, a.sndnote, a.tcid, a.iptvcdn, a.iptvplaylist, a.iptvkey, ";
                    
                if ($tarrif == 1) $query .= "a.iptvsdom, a.twin,a.token, a.islocal, b_user, ";
                    
                $query .= "a.iptvactdate, a.iptvmonths, a.grpvariant, a.plname, dealers.tz LIMIT 1";
                $res = $link->query($query);

                $c_users = [];
                if ($tarrif == 1) {
                    $c_user_query = "SELECT c.`user` AS c_user 
                                    FROM accounts a
                                    LEFT JOIN accounts c ON a.id = c.twin
                                    WHERE a.`user` = '$user' AND a.deleted = '0' AND c.`user` IS NOT NULL";
                    $c_user_res = $link->query($c_user_query);
                    while ($c_user_row = $c_user_res->fetch_assoc()) {
                        $c_users[] = $c_user_row['c_user'];
                    }
                }

                if ($res->num_rows == 1) {
                    $row = $res->fetch_assoc();
                    $iptvurl = "http://pl.mpol.co/p/" . (!empty($row['plname']) ? $row['plname'] : $row['iptvkey']) . ".m3u8";
                    if ($tarrif != 1) unset($row["iptvkey"]);

                    $agentQuery = "SELECT ad.date, ag.agent, ad.ip 
                                  FROM agent_dates ad 
                                  JOIN agents ag ON ad.agent_id = ag.id 
                                  JOIN accounts ac ON ac.id = ad.account_id 
                                  WHERE ac.user = '$user' 
                                  ORDER BY ad.date DESC";
                    $agentResult = $link->query($agentQuery);
                    $agentData = [];
                    while ($agentRow = $agentResult->fetch_assoc()) {
                        $agentData[] = $agentRow;
                    }
//                    foreach ($agentData as &$entry) {
//                        $entry['prov'] = provFromip($entry['ip']);
//                    }
                    unset($entry);

                    $locations = [];
                    if ($row['iptvcdn'] >= 0) {
                        $res2 = $link->query("SELECT option_value, option_text FROM locations WHERE active=1 ORDER BY option_text");
                        while ($locRow = $res2->fetch_assoc()) {
                            $locations[] = $locRow;
                        }
                    }

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

                    $rq = "SELECT pname, $sqltarrif AS tarrif FROM packets WHERE id = 40";
                    $res2 = $link->query($rq);
                    $iptv_packets = [];
                    while ($packetRow = $res2->fetch_assoc()) {
                        $iptv_packets[] = $packetRow;
                    }

                    $grplst = explode(",", $row['iptvplaylist']);
                    $playlists = [1 => [], 2 => [], 3 => []];
                    $sql1 = "SELECT grpid, grpname FROM subgroups WHERE playlstid = 1";
                    $result1 = $link->query($sql1);
                    while ($row1 = $result1->fetch_assoc()) {
                        $playlists[1][] = [
                            'grpid' => $row1['grpid'],
                            'grpname' => $row1['grpname'],
                            'selected' => ($row['grpvariant'] == 1 && in_array($row1['grpid'], $grplst))
                        ];
                    }
                    $sql2 = "SELECT grpid, grpname FROM subgroups WHERE playlstid = 2";
                    $result2 = $link->query($sql2);
                    while ($row2 = $result2->fetch_assoc()) {
                        $playlists[2][] = [
                            'grpid' => $row2['grpid'],
                            'grpname' => $row2['grpname'],
                            'selected' => ($row['grpvariant'] == 2 && in_array($row2['grpid'], $grplst))
                        ];
                    }
                    $sql3 = "SELECT grpid, `name` FROM channel_groups_list WHERE playlist_id = 3";
                    $result3 = $link->query($sql3);
                    while ($row3 = $result3->fetch_assoc()) {
                        $playlists[3][] = [
                            'grpid' => $row3['grpid'],
                            'grpname' => $row3['name'],
                            'selected' => ($row['grpvariant'] == 3 && in_array($row3['grpid'], $grplst))
                        ];
                    }

                    $response['data']['iptv'] = [
                        'account' => array_merge($row, ['c_users' => $c_users]),
                        'iptvurl' => $iptvurl,
                        'locations' => $locations,
                        'packets' => $iptv_packets,
                        'playlists' => $playlists,
                        'iptvenddate' => $row['iptvactdate'] ? addMonths($row['iptvactdate'], explode(":", $row['iptvmonths'])[0]) : null,
                        'tz' => $row['tz']
                    ];
                    if ($tarrif == 1) $response['data']['iptv']['agent_data'] = $agentData;
                }
            } else if ((!$row["iptvusr"] && ($p == "s") || ($p == "s" || $p == ""))) {
                $response['data']['account'] = $account_data;
       
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
       
                $rq = "SELECT t1.id, t1.$sqltarrif AS tarrif, UNIX_TIMESTAMP(t2.dend) AS unixt FROM packets AS t1 
                    LEFT JOIN pdates AS t2 ON t2.packet = t1.id AND t2.user_id=$accid AND (t2.dend>=NOW() OR t2.paused=1) 
                    WHERE t1.dsbled!=1 ORDER BY t1.id ASC";

                $res = $link->query($rq);
                $packets = [];
                while ($packet = $res->fetch_assoc()) {
                    $packets[] = $packet;
                }

                $response['data']['packets'] = $packets;
                $response['data']['tmt'] = ($account_data['pdt'] + 7 * 86400 <= $now || $account_data['paused']) ? 0 : ($account_data['pdt'] + 7 * 86400) - $now;

                if ($account_data['req']) {
                    $res = $link->query("SELECT ident, pname, ncmd FROM caids WHERE disabled=0 ORDER BY ncmd");
                    $caids = [];
                    while ($caid = $res->fetch_assoc()) {
                        $caids[] = $caid;
                    }

                    $res = $link->query("SELECT server.url, cwslog.cwok, cwslog.lastcon, server.s_id 
                                        FROM server INNER JOIN cwslog ON cwslog.s_id = server.s_id 
                                        WHERE cwslog.uid='$accid' AND hide!=1");
                    $cws = [];
                    while ($cw = $res->fetch_assoc()) {
                        $cws[] = $cw;
                    }

                    $response['data']['caids'] = $caids;
                    $response['data']['cws'] = $cws;
                }

                $res = $link->query("SELECT pdates.dend FROM pdates WHERE user_id='$accid' AND pdates.dend >= NOW() LIMIT 1");
                $response['data']['connection_status'] = $res->num_rows > 0;
            

            $res2 = $link->query("SELECT url, ip FROM server WHERE s_id='{$account_data['server']}'");
            $server = $res2->fetch_assoc() ?: ['url' => '', 'ip' => ''];
            $response['data']['server'] = $server;
            $response['data']['server']['s_id'] = $account_data['server'];
        }
        } 
    } else {
        $response['status'] = 'error';
        $response['message'] = 'Аккаунт с такими данными не найден';
    }

    echo json_encode($response);
    $link->close();
    exit();
}


function hlight_string($spath,$sstr)
{
    $spath2=$spath;
    $nc=strrpos($spath,$sstr);
           if($nc!==false)
        return $spath2=substr($spath,0,$nc)."<el class=grn>".$sstr."</el>".substr($spath,$nc+(strlen($sstr)));
}
?>