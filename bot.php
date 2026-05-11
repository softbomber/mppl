<?php
header('Content-Type: text/html; charset=utf-8');
include_once("config.php");

$token = getenv('TG_BOT_TOKEN') ?: '';
$bot = new TelegramBot($token);

// Проверяем наличие файла registered.trigger
if (!file_exists("registered.trigger")) {
    $page_url = "https://$_SERVER[SERVER_NAME]$_SERVER[REQUEST_URI]";
    $result = $bot->setWebhook($page_url);
    if ($result) {
        file_put_contents("registered.trigger", time());
    }
}

// Обработчик команды /start
$bot->command("/start", function ($message) use ($bot) {
    $chatId = $message->chat->id;
    //$chatId = $message->getChat()->getId();
    $user = $message->from;
    //$user = $message->getFrom();
    $username = $user->username ?? $user->first_name ?? "";
    //$username = $user->getUsername() ?? $user->getFirstName() ?? "";
   // if ($message->text == "/start auth")
      if ($message->getText() == "/start auth") {
        $bot->sendMessage($chatId, "Добро пожаловать, $username! Вы успешно авторизовались через Telegram.");
    } else {
        // Показываем клавиатуру для выбора роли
        $keyboard = [
            'keyboard' => [
                [['text' => "Зарегистрироваться как юзер"]],
                [['text' => "Зарегистрироваться как дилер"]]
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => true
        ];
        $bot->sendMessage($chatId, "Выберите, как хотите зарегистрироваться:", $keyboard);
    }
});

// Обработчик текстовых сообщений и контактов
$bot->on(function ($update) use ($bot, $link) {
    $msg = $update->message;
    if (!$msg) return;

    $chatId = $msg->chat->id;
    $text = $msg->text ?? '';
    $contact = $msg->contact ?? null;
    $username = $msg->from->username ?? '';

    // Проверяем выбор роли
    if ($text === "Зарегистрироваться как юзер" || $text === "Зарегистрироваться как дилер") {
        // Сохраняем выбранную роль (например, в глобальной переменной или базе)
        saveUserRole($chatId, $text === "Зарегистрироваться как юзер" ? 'user' : 'dealer');

        // Запрашиваем контактные данные
        $keyboard = [
            'keyboard' => [[['text' => "КОНТАКТНЫЕ ДАННЫЕ", 'request_contact' => true]]],
            'resize_keyboard' => true,
            'one_time_keyboard' => true
        ];
        $bot->sendMessage($chatId, "Пожалуйста, отправьте ваши контактные данные:", $keyboard);
    }

    // Обработка полученных контактных данных
    if ($contact) {
        $role = getUserRole($chatId);
        if ($role === 'dealer') {
            $result = registerDealer($contact->first_name ?? '', $contact->last_name ?? '', $contact->phone_number ?? '', $contact->user_id, $username, $chatId);
            if ($result['success']) {
                $bot->sendMessage($chatId, "Вы успешно зарегистрированы как дилер!", ['remove_keyboard' => true]);
                $keyboard = [
                    'keyboard' => [
                        [["text" => "🔍 Поиск по имени юзера"]],
                        [["text" => "\xF0\x9F\x93\xAB  СТАТУС"]]
                    ],
                    'resize_keyboard' => true,
                    'one_time_keyboard' => false
                ];
                $bot->sendMessage($chatId, "Выберите действие: поиск аккаунта или проверка статуса.", $keyboard);
            } else {
                $bot->sendMessage($chatId, "Ошибка при регистрации дилера. Обратитесь в поддержку.");
            }
        } elseif ($role === 'user') {
            if (make_user($contact->first_name, $contact->last_name, $contact->phone_number, $contact->user_id)) {
                $bot->sendMessage($chatId, "Спасибо за регистрацию!", ['remove_keyboard' => true]);
                $keyboard = [
                    'keyboard' => [[["text" => "\xF0\x9F\x93\xAB  СТАТУС"]]],
                    'resize_keyboard' => true,
                    'one_time_keyboard' => true
                ];
                $bot->sendMessage($chatId, "Для получения состояния вашего логина нажмите кнопку СТАТУС", $keyboard);
            } else {
                $bot->sendMessage($chatId, "Ошибка при регистрации пользователя. Обратитесь в поддержку.");
            }
        }
        clearUserRole($chatId); // Очищаем состояние после регистрации
    }

    if ($text === "🔍 Поиск по имени юзера") {
        $bot->sendMessage($chatId, "Пожалуйста, введите имя пользователя для поиска:");
        saveSearchState($chatId, true);
    }

    if (isSearchState($chatId) && $text !== "🔍 Поиск по имени юзера") {
        $dId = getDealerId($chatId);
        if ($dId) {
            $accountInfo = searchAccountByUsername($text, $dId);
            if (!empty($accountInfo)) {
                $bot->sendMessage($chatId, "Найден аккаунт:\n" . $accountInfo);
            } else {
                $bot->sendMessage($chatId, "Аккаунт $text не найден.");
            }
        } else {
            $bot->sendMessage($chatId, "Ошибка: вы не зарегистрированы как дилер.");
        }
        clearSearchState($chatId);
    }

    // Обработка команды СТАТУС
    if (mb_stripos($text, "СТАТУС") !== false) {
        $messageText = tstat($chatId);
        if (!empty($messageText)) {
            $bot->sendMessage($chatId, $messageText);
        } else {
            $bot->sendMessage('85534516', 'Пустое сообщение');
        }
    }
}, function ($update) {
    return true;
});

// Функции для управления ролью пользователя (можно реализовать через базу или временное хранилище)
function saveUserRole($chatId, $role) {
    global $link;
    $stmt = $link->prepare("INSERT INTO temp_roles (chat_id, role) VALUES (?, ?) ON DUPLICATE KEY UPDATE role=?");
    $stmt->bind_param('sss', $chatId, $role, $role);
    $stmt->execute();
    $stmt->close();
}

function getUserRole($chatId) {
    global $link;
    $stmt = $link->prepare("SELECT role FROM temp_roles WHERE chat_id=?");
    $stmt->bind_param('s', $chatId);
    $stmt->execute();
    $result = $stmt->get_result();
    $val = $result->num_rows ? $result->fetch_assoc()['role'] : null;
    $stmt->close();
    return $val;
}

function clearUserRole($chatId) {
    global $link;
    $stmt = $link->prepare("DELETE FROM temp_roles WHERE chat_id=?");
    $stmt->bind_param('s', $chatId);
    $stmt->execute();
    $stmt->close();
}

function tstat($cid) {
    global $link;
    $link->set_charset("utf8mb4");
    $stmt = $link->prepare("SELECT id, user, iptvusr FROM accounts WHERE tcid=?");
    $stmt->bind_param('s', $cid);
    $stmt->execute();
    $r = $stmt->get_result();
    $prow = '';
    while ($usrlst = $r->fetch_assoc()) {
        $prow .= "Логин <b>{$usrlst['user']}</b>\n";
        $uid = $usrlst['id'];
        $iptvusr = $usrlst['iptvusr'];
        $stmt2 = $link->prepare("SELECT packets.pname, DATE_FORMAT(pdates.dend, '%d.%m.%y %H:%i') AS dend FROM pdates INNER JOIN packets ON pdates.packet = packets.id WHERE user_id=? AND pdates.dend >= NOW()");
        $stmt2->bind_param('i', $uid);
        $stmt2->execute();
        $r2 = $stmt2->get_result();
        $rnums2 = $r2->num_rows;

        if ($rnums2) {
            while ($row = $r2->fetch_assoc()) {
                $prow .= $row['pname'] . " до " . $row['dend'] . "\n";
            }
        } else {
            $prow .= "Активных пакетов нет!\n";
        }
    }
    if ($iptvusr !== null && $iptvusr !== '') {
        $stmt3 = $link->prepare("SELECT iptvactdate, iptvmonths FROM accounts WHERE id=?");
        $stmt3->bind_param('i', $uid);
        $stmt3->execute();
        $r2 = $stmt3->get_result();
        $rnums2 = $r2->num_rows;
        $prow .= "Пакет IPTV 4600+ "; 
        if ($rnums2) {
            while ($row = $r2->fetch_assoc()) {
                $iptvactdate = $row['iptvactdate'];
                $iptvmonths = $row['iptvmonths'];
                if ($iptvactdate && ($iptvenddate = addMonths($iptvactdate, explode(":", $iptvmonths)[0])) >= time()) {
                    $prow .= " активен до " . u_time_c($iptvenddate, 0, 1) . "\n";
                } else {
                    $prow .= ' не активен' . "\n";
                }
            }
        }
    }
    return $prow;
}

function make_user($fname, $lname, $phone, $chat_id) {
    global $link;
    $link->set_charset("utf8mb4");
    $lname = $link->real_escape_string($lname ?? '');
    $fname = $link->real_escape_string($fname ?? '');
    $phone = $link->real_escape_string($phone ?? '');
    $chat_id = $link->real_escape_string($chat_id);

    $stmt = $link->prepare("SELECT * FROM tbase WHERE phone=? LIMIT 1");
    $stmt->bind_param('s', $phone);
    $stmt->execute();
    $r = $stmt->get_result();

    if (!$r->num_rows) {
        $stmt2 = $link->prepare("INSERT INTO `tbase` (fname, lname, phone, cid) VALUES (?, ?, ?, ?)");
        $stmt2->bind_param('ssss', $fname, $lname, $phone, $chat_id);
        $stmt2->execute() or die("пользователя создать не удалось");
    }

    if ($rows = is_ph_set($phone)) {
        $stmt3 = $link->prepare("UPDATE accounts SET tcid=? WHERE phone=?");
        $stmt3->bind_param('ss', $chat_id, $phone);
        $stmt3->execute() or die("ошибка обновления аккаунта");
        return true;
    } else {
        return false;
    }
}

function registerDealer($fname, $lname, $phone, $user_id, $username, $chatId) {
    global $link;
    $link->set_charset("utf8mb4");
    $phone = $link->real_escape_string($phone ?? '');
    $fname = $link->real_escape_string($fname ?? '');
    $lname = $link->real_escape_string($lname ?? '');
    $user_id = $link->real_escape_string($user_id);
    $username = $link->real_escape_string($username ?? '');

    $stmt = $link->prepare("SELECT id FROM dealers WHERE phone=? OR t_id=? LIMIT 1");
    $stmt->bind_param('ss', $phone, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $stmt = $link->prepare("UPDATE dealers SET t_id=?, t_fname=?, t_lname=?, phone=?, t_usr=? WHERE phone=? OR t_id=?");
        $stmt->bind_param("sssssss", $user_id, $fname, $lname, $phone, $username, $phone, $user_id);
        $stmt->execute();
        $stmt->close();
    } else {
        $result2 = $link->prepare("SELECT id FROM dealers WHERE t_usr=? AND (t_fname=? OR t_lname=?) LIMIT 1");
        $result2->bind_param('sss', $username, $fname, $lname);
        $result2->execute();
        $result = $result2->get_result();
        if ($result->num_rows > 0) {
            $stmt = $link->prepare("UPDATE dealers SET t_id=?, t_fname=?, t_lname=?, phone=?, t_usr=? WHERE t_usr=? AND (t_fname=? OR t_lname=?)");
            $stmt->bind_param("ssssssss", $user_id, $fname, $lname, $phone, $username, $username, $fname, $lname);
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $link->prepare("INSERT INTO dealers (t_id, t_fname, t_lname, phone, t_usr) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("sssss", $user_id, $fname, $lname, $phone, $username);
            $stmt->execute();
            $stmt->close();
        }
    }

    return ['success' => true];
}

function is_ph_set($phone) {
    global $link;
    $stmt = $link->prepare("SELECT id FROM accounts WHERE phone=? LIMIT 1");
    $stmt->bind_param('s', $phone);
    $stmt->execute();
    $result = $stmt->get_result();
    $val = $result->num_rows > 0;
    $stmt->close();
    return $val;
}

function saveSearchState($chatId, $state) {
    global $link;
    $state = $state ? 1 : 0;
    $stmt = $link->prepare("INSERT INTO temp_search (chat_id, is_searching) VALUES (?, ?) ON DUPLICATE KEY UPDATE is_searching=?");
    $stmt->bind_param('sii', $chatId, $state, $state);
    $stmt->execute();
    $stmt->close();
}

function isSearchState($chatId) {
    global $link;
    $stmt = $link->prepare("SELECT is_searching FROM temp_search WHERE chat_id=?");
    $stmt->bind_param('s', $chatId);
    $stmt->execute();
    $result = $stmt->get_result();
    $val = $result->num_rows && $result->fetch_assoc()['is_searching'] == 1;
    $stmt->close();
    return $val;
}

function getDealerId($chatId) {
    global $link;
    $stmt = $link->prepare("SELECT id FROM dealers WHERE t_id=? LIMIT 1");
    $stmt->bind_param('s', $chatId);
    $stmt->execute();
    $result = $stmt->get_result();
    $val = $result->num_rows ? $result->fetch_assoc()['id'] : null;
    $stmt->close();
    return $val;
}

function searchAccountByUsername($username, $dId) {
    global $link;
    $link->set_charset("utf8mb4");
    $username = $link->real_escape_string($username);
    $dId = $link->real_escape_string($dId);

    $result = $link->query("SELECT id, user, iptvusr FROM accounts WHERE user='$username' AND dealer='$dId' LIMIT 1");
    
    if ($result->num_rows > 0) {
        $account = $result->fetch_assoc();
        return getAccountInfoForDealer($account['id']);
    }
    return '';
}

function clearSearchState($chatId) {
    global $link;
    $stmt = $link->prepare("DELETE FROM temp_search WHERE chat_id=?");
    $stmt->bind_param('s', $chatId);
    $stmt->execute();
    $stmt->close();
}

function getAccountInfoForDealer($accountId) {
    global $link;
    $link->set_charset("utf8mb4");
    $accountId = $link->real_escape_string($accountId);

    $prow = '';
    $r = $link->query("SELECT id, user, iptvusr FROM accounts WHERE id='$accountId'");
    while ($usrlst = $r->fetch_assoc()) {
        $prow .= "Логин <b>{$usrlst['user']}</b>\n";
        $uid = $usrlst['id'];
        $iptvusr = $usrlst['iptvusr'];
        $r2 = $link->query("SELECT packets.pname, DATE_FORMAT(pdates.dend, '%d.%m.%y %H:%i') AS dend FROM pdates INNER JOIN packets ON pdates.packet = packets.id WHERE user_id='$uid' AND pdates.dend >= NOW()");
        $rnums2 = $r2->num_rows;

        if ($rnums2) {
            while ($row = $r2->fetch_assoc()) {
                $prow .= $row['pname'] . " до " . $row['dend'] . "\n";
            }
        } else {
            $prow .= "Активных пакетов нет!\n";
        }

        if ($iptvusr !== null && $iptvusr !== '') {
            $r2 = $link->query("SELECT iptvactdate, iptvmonths FROM accounts WHERE id='$uid'");
            $rnums2 = $r2->num_rows;
            $prow .= "Пакет IPTV 4600+ ";
            if ($rnums2) {
                while ($row = $r2->fetch_assoc()) {
                    $iptvactdate = $row['iptvactdate'];
                    $iptvmonths = $row['iptvmonths'];
                    if ($iptvactdate && ($iptvenddate = addMonths($iptvactdate, explode(":", $iptvmonths)[0])) >= time()) {
                        $prow .= "активен до " . u_time_c($iptvenddate, 0, 1) . "\n";
                    } else {
                        $prow .= "не активен\n";
                    }
                }
            }
        }
    }
    return $prow;
}

$bot->run();
?>