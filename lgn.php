<?php 
use Google\Service\Dataform\WriteFile;
include_once("config.php");
checkLoggedIn("no");

$title = "Качественный кардшаринг! Стабильные сервера!";

// Функция для проверки аутентификации через Telegram
function verifyTelegramAuth($data, $botToken) {
    $check_hash = $data['hash'];
    unset($data['hash']);
    $data_check_arr = [];
    foreach ($data as $key => $value) {
        $data_check_arr[] = $key . '=' . $value;
    }
    sort($data_check_arr);
    $data_check_string = implode("\n", $data_check_arr);
    $secret_key = hash('sha256', $botToken, true);
    $hash = hash_hmac('sha256', $data_check_string, $secret_key);
    if (strcmp($hash, $check_hash) !== 0) {
        throw new Exception('Data is NOT from Telegram');
    }
    if ((time() - $data['auth_date']) > 86400) {
        throw new Exception('Data is outdated');
    }
    return hash_equals($hash, $check_hash);
}


if (isset($_GET['id']) && isset($_GET['hash'])) {
    $botToken = '967967173:AAG4CEMpB-SyYC0jN6Z2aOlhvGSp9YvCPpM';

    $data = [ 
        'id' => $_GET['id'],
        'first_name' => $_GET['first_name'] ?? '',
        'last_name' => $_GET['last_name'] ?? '',
        'username' => $_GET['username'] ?? '',
        'auth_date' => $_GET['auth_date'],
        'hash' => $_GET['hash']
    ];

    if (verifyTelegramAuth($data, $botToken)) { 
        $telegramId = $data['id'];
        $telegramUsername = $data['username'];
        $stmt = $link->prepare("SELECT id FROM dealers WHERE t_id = ? OR t_usr = ?"); 
        $stmt->bind_param('is', $telegramId, $telegramUsername);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            $id = $row['id'];
            $stmtUpdate = $link->prepare("UPDATE dealers SET t_id=?, t_fname = ?, t_lname = ?, t_auth_date = ?, t_usr = ? WHERE id = ?");
            $stmtUpdate->bind_param('issisi', $data['id'], $data['first_name'], $data['last_name'], $data['auth_date'], $telegramUsername, $id);
            $stmtUpdate->execute();
            $stmtUpdate->close();  

            $res = $link->query("SELECT id, user, a, hash, currency, rate, postpaid, t_srt FROM dealers WHERE id='$id' and (block=0 or block is null)") or die("checkPass fatal error: " . $link->error_list); 
            if ($res->num_rows == 1) {
                $row = $res->fetch_assoc();
            }
        } else {
            $ip = $_SERVER['REMOTE_ADDR'];
            $row = newDealer($telegramUsername, '', '', '$ip', '', '', '', 'telegram', $telegramUsername, $telegramId, $data['last_name'], $data['first_name'], '', $data['auth_date']); 
        }

        $s_time = time() + 86400;
        if (isset($row['a'])) {
            setcookie("a", $row['a'], $s_time, '/');
        } else {
            unset($_COOKIE['a']);
            setcookie("a", "", time() - 86400);
        }
        setcookie("i", $row['id'], $s_time, '/');
        setcookie("hsh", $row['hash'], $s_time, '/');
        setcookie("pp", $row['postpaid'], $s_time, '/');
        setcookie("sort", $row['t_srt'], $s_time, '/');

        ini_set('session.cookie_lifetime', $s_time);
        ini_set('session.gc_maxlifetime', $s_time);

        $link->query("INSERT INTO ip_log (did, ip, `when`) VALUES ({$row['id']}, '{$_SERVER['REMOTE_ADDR']}', NOW())");

        cleanMemberSession($row["user"], $row['id'], $row['a'], $row['hash'], $row['id'], $row['currency'], $row['rate'], $row['postpaid']);

        header("Location: " . ($row['id'] ? "dealer.php" : "user.php"));
        exit;
    } else {
        $messages[] = "Ошибка аутентификации.";
        doIndex();
        exit;
    }
    exit;
}

if (isset($_POST["submit"])) {
    field_validator("Логин", trim($_POST["login"]), "alphanumeric,email", 4, 33);
    field_validator("Пароль", trim($_POST["password"]), "string", 4, 33);
    if (isset($_POST["captcha"])) {
        field_validator("Код", trim($_POST["captcha"]), "string", 3, 3);
        if (trim($_POST["captcha"]) != $_SESSION['secpic'])
            $messages[] = 'Неправильно введён код';
    }
    if (!(($row = checkPass($_POST["login"], $_POST["password"])) && (!empty($_POST["login"]) && !empty($_POST["password"])))) {
        $messages[] = "Неправильная пара логин/пароль, попробуйте ещё раз";
    }
    if ($messages) {
        doIndex();
        exit;
    }

    if (!empty($timeZoneOffset)) {
        $dealerId = $row['id'];
        $query = "UPDATE dealers SET tz = ? WHERE id = ?";
        $stmt = $link->prepare($query);
        $stmt->bind_param('ii', $timeZoneOffset, $dealerId);
        $stmt->execute();
        $stmt->close();
    }

    $s_time = time() + 86400;
    if (isset($row['a'])) {
        setcookie("a", $row['a'], $s_time, '/');
    } else {
        unset($_COOKIE['a']);
        setcookie("a", "", time() - 86400);
    }
    setcookie("i", $row['id'], $s_time, '/');
    setcookie("hsh", $row['hash'], $s_time, '/');
    setcookie("pp", $row['postpaid'], $s_time, '/');
    setcookie("sort", $row['t_srt'], $s_time, '/');

    ini_set('session.cookie_lifetime', $s_time);
    ini_set('session.gc_maxlifetime', $s_time);

    $link->query("INSERT INTO ip_log (did, ip, `when`) VALUES ({$row['id']}, '{$_SERVER['REMOTE_ADDR']}', NOW())");
    cleanMemberSession($row["user"], $row['id'], $row['a'], $row['hash'], $row['id'], $row['currency'], $row['rate'], $row['postpaid']);
    header("Location: " . ($row['id'] ? "dealer.php" : "user.php"));
    exit;
} else {
    doIndex();
}

function doIndex() {
    global $messages, $title;
 ?><!DOCTYPE html>
 <html lang="ru">
 <head>
 <title><?php print $title; ?></title>
 <meta charset="UTF-8" />
 <link href="https://fonts.googleapis.com/css2?family=PT+Mono&amp;family=Source+Sans+3&amp;display=swap" rel="stylesheet">
 <link rel="stylesheet" type="text/css" href="css/mb.css?v=2" />
 <meta name="viewport" content="width=device-width, initial-scale=1.0">
 </head>
 <body>
 <input type="hidden" id="timeZoneOffsetInput" name="timeZoneOffset">
 <div id="container">
 
     <div id="content">
      <div id="carbonForm">
         <div class="logo">
             <h1>METROPOLITEN</h1>
             <span>Cardsharing and IPTV/OTT Premium System</span>
             <div style="display: inline-flex; align-items: center; width: 100%; justify-content: center;">
                 <img src="png/telegram.png" alt="" />
                 <span><a href="https://t.me/mpolbot">@MPOLBot</a></span>      
         </div>
         </div>
 
 
         <div class="title">ВХОД В БИЛЛИНГ</div>
 
         <form action="<?php print $_SERVER['PHP_SELF']; ?>" method="post" id="loginForm" onsubmit="setTimeZoneOffset()">
             <input type="hidden" name="timeZoneOffsett" id="timeZoneOffsetInputt" value="">
             <div class="field">
                 <input type="text" name="login" id="login" placeholder="логин или email" />
             </div>
             <div class="field">
                 <input type="password" name="password" id="pass" placeholder="пароль" />
             </div>
 
             <?php if($messages): ?>
                 <div class="field">
                     <input type="text" name="captcha" placeholder="код с картинки" id="captcha"/>
                 </div>
                 <div align="center" style="height:40px">
                     <img src="secpic.php" alt="защитный код" />
                 </div>
             <?php endif; ?>
 
             <div class="enter">
                 <input type="submit" class="signupButton" name="submit" value="ВОЙТИ"/>
                 <input type="button" class="signupButton" name="register" id="register" value="РЕГИСТРАЦИЯ" onClick="window.location.href='join.php'"/>
             </div>
         </form>
 <div style="font-size:13px;margin:10px 0">
 <a href="restore.php" style="color:#6bc498;text-decoration:none" title="Щелкните для перехода на страницу
 восстановления забытого пароля">Забыли пароль?</a></div>
         <!-- Social Login Buttons -->
         <div class="social-login">
 <form id="authForm" style="width: 100%" method="POST" action="glogin.php">
     <button type="submit">
         <img src="gologo.png" alt="Google Logo" />
         Войти через Google
     </button>
 </form>
         </div>
         <div class="social-login">
 <!--         <button type="button" onClick="gooAuth()">
         <img src="gologo.png" alt="Google Logo" />
         Войти через Google
     </button>             -->
 <button type="button" id="tlg"> <!-- onClick="window.location.href='login_telegram.php'"> -->
 <!--	<button id="telegramAuthButton">      -->
 <!--                <img src="png/telegram.png" alt="Telegram Logo" /> -->
 <script async src="https://telegram.org/js/telegram-widget.js?7" data-telegram-login="Mpolbot" data-userpic="false" data-size="medium" data-radius=3 data-auth-url=""></script>
 <!--                Войти через Telegram --->
             </button>
         </div>
     </div>
     </div>
 </div>
 <?php include_once("footer.htm"); ?>
 </body>
 <script>
 document.querySelectorAll('link[rel="stylesheet"]').forEach(link => {
     if (link.href.includes('widget-frame.css?67')) {
         link.remove();
     }
 });
 document.getElementById("authForm").onsubmit = function() {
         var offset = new Date().getTimezoneOffset();
         document.getElementById("timeZoneOffsetInput").value = offset;
     };
 function setTimeZoneOffset() {
     var offset = new Date().getTimezoneOffset();
     document.getElementById('timeZoneOffsetInput').value = offset;
 }
 document.getElementById("telegramAuthButton").onclick = function() {
     setTimeZoneOffset();
     window.location.href = 'https://t.me/Mpolbot?start=auth';
 };
 </script>
 </html><?php } ?>
