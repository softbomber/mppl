<?php
include_once("config.php");
checkLoggedIn("no");
$title="Качественный кардшаринг! Стабильные сервера!";

if(isset($_POST["submit"])) {
    // Проверка и обработка логина и пароля
    field_validator("Логин", trim($_POST["login"]), "alphanumeric", 4, 33);
    field_validator("Пароль", trim($_POST["password"]), "string", 4, 33);

    // Проверка капчи
    if(isset($_POST["captcha"])) {
        field_validator("Код", trim($_POST["captcha"]), "string", 3, 3);
        if (trim($_POST["captcha"]) != $_SESSION['secpic'])
            $messages[] = 'Неправильно введён код';
    }

    if( !($row = checkPass($_POST["login"], $_POST["password"])) && (!empty($_POST["login"]) && !empty($_POST["password"]))) {
        $messages[] = "Неправильная пара логин/пароль, попробуйте ещё раз";
    }

    if($messages){
        doIndex();
        exit;
    }

    // Получаем часовой пояс клиента
    $timeZoneOffset = $_POST["timeZoneOffset"];

    // Сохраняем часовой пояс в сессии и куках
    $_SESSION['timeZoneOffset'] = $timeZoneOffset;
    setcookie('timeZoneOffset', $timeZoneOffset, time() + 86400, '/');

    if (!empty($timeZoneOffset)) {
        $dealerId = $row['id'];  // предполагается, что ID пользователя хранится в $row['id']
        $query = "UPDATE dealers SET tz = ? WHERE id = ?";
        $stmt = $link->prepare($query);
        $stmt->bind_param('ii', $timeZoneOffset, $dealerId);
        $stmt->execute();
        $stmt->close();
    }

    $a=$d=0;
    $s_time=time()+86400;
//   if($row['a']==1 || $row['a']==2)
  //{
    if(isset($row['a']))
       {
       setcookie("a",$row['a'],$s_time,'/');
         $a=$row['a'];
       }
    else {
       unset($_COOKIE['a']);
       setcookie("a", "", time() - 86400);
         }
    //}
  if(isset($row['id']))
      {$d=$row['id'];
      setcookie("i", $d,$s_time,'/');}
  if(isset($row['hash']))
      {$hash=$row['hash'];
      setcookie("hsh", $hash,$s_time,'/');}
      setcookie("pp", $row['postpaid'],$s_time,'/');
      setcookie("sort",$row['t_srt'],$s_time,'/');
ini_set('session.cookie_lifetime',$s_time);
ini_set('session.gc_maxlifetime',$s_time);
// header("Location: check.php"); exit();
  $ip=$_SERVER['REMOTE_ADDR'];
  $d=$row['id'];
  $link->query("INSERT INTO ip_log (did, ip,`when`) VALUES ($d, '$ip',NOW())" ) or die("inserting.  Error returned if any: ".$link->error_list);
cleanMemberSession($row["user"],$d,$a,$row['hash'],$row['id'],$row['currency'],$row['rate'],$row['postpaid']);

if (!$row['id'])
  header("Location: user.php");
else
  header("Location: dealer.php");

} else {
    doIndex();
}

function doIndex() {
    global $messages;
    global $title;
?>
<!DOCTYPE html>
<html>
<head>
<title><?php print $title; ?></title>
<meta name="KeyWords" content="шаринг лучший качественный быстрый в Ташкенте в Узбекистане нтв+ восток континент шара кардсервер без затыков с защитой от DDoS"/>
<meta name="Description" content="Лучший кардшаринг сервис. Стабильная Шара на НТВ+, НТВ+ Восток, Континент. У нас вы получите: SMS оповещения об отключении пакетов; подключение менее 5 с.; стабильные сервера с отличным пингом c защитой от DDoS атак"/>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8"/>
<link href="https://fonts.googleapis.com/css2?family=PT+Mono&amp;family=Source+Sans+3&amp;display=swap" rel="stylesheet">
<link rel="shortcut icon" href="../favicon.ico" type="image/x-icon"/>
<link rel="stylesheet" type="text/css" href="css/lg.css" />
<script>
    function setTimeZoneOffset() {
        var timeZoneOffset = new Date().getTimezoneOffset();
        document.getElementById("timeZoneOffsetInput").value = timeZoneOffset;
    }
</script>
</head>
<body>
<div id="container">
    <div id="content">
        <div class="logo">
            <h1>METROPOLITEN</h1>
            <span>Cardsharing Premium System</span>
            <div>
                <img align="middle" style="line-height:25px" src="png/telegram.png" alt=""/>
                <span><a href="https://t.me/mpolbot">https://t.me/mpolbot</a></span>
            </div>
        </div>
        <div style="display:table;width:100%;height:100%">
            <?php include_once("adv.php")?>
            <div style="display:table-cell;vertical-align:middle;max-height:1000px">
                <div id="carbonForm">
                    <div style="font-size:14px;padding:8px 8px 7px 12px;color:#0e0c0c">Вход в Биллинг
                        <div style="padding-left:15px;float:right;font-size:13px">
                            <a href="restore.php" style="color:#6B8DC4;text-decoration:none" title="Щелкните для перехода на страницу восстановления забытого пароля">Забыли пароль?</a>
                        </div>
                    </div>
                    <form action="<?php print $_SERVER["PHP_SELF"]; ?>" method="post" id="loginForm" onsubmit="setTimeZoneOffset()">
                        <input type="hidden" name="timeZoneOffset" id="timeZoneOffsetInput" value="">
                        <div class="fCont">
                            <div class="field">
                                <input align=center type="text" name="login" id="login" placeholder="логин" value="<?php print isset($_POST["login"]) ? $_POST["login"] : "" ; ?>"/>
                            </div>
                            <div class="field">
                                <input align=center name="password" type="password" placeholder="пароль" id="pass"/>
                            </div>
                            <?php
                            if($messages)
                            {
                                echo '<div  class="field"><input align=center type="text" name="captcha" placeholder="код с картинки" id="captcha"/></div>';
                                echo '<div align=center style="height:40px"><img src="secpic.php" alt="защитный код" /></div>';
                            }
                            ?>
                        </div>
                        <div style="padding:0 10px;float:right;margin-top:10px;margin-bottom:6px">
                            <input type="submit" class="signupButton" name="submit" id="submit" value="      ВХОД     " />
                            <input type="button" class="signupButton" name="register" id="register" value="РЕГИСТРАЦИЯ" onClick="window.location.href='join.php'"/>
                        </div>
                        <div align="center" style="clear:both;color:darkred">
                            <?php
                            if($messages)displayErrors($messages)
                            ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php include_once("footer.htm")?>
</div>
</body></html>
<?php
}
?>
