<?php
include_once("config.php");
 checkLoggedIn("no");
$title="Cтраница регистрации";

if(isset($_POST["submit"])){
   $login=mysql_real_escape_string(trim($_POST["login"]));
    $pass=mysql_real_escape_string(trim($_POST["pass"]));
    $pass2=mysql_real_escape_string(trim($_POST["pasr"]));
    $email=mysql_real_escape_string(trim($_POST["eml"]));
   field_validator("Логин", $login, "alphanumeric", 4, 33);
   field_validator("Пароль", $pass, "string", 4, 33);
   field_validator("Повторный пароль", $pass2, "string", 4, 33);
   field_validator("Email", $email,"string", 5, 33);
   if(strcmp($pass, $pass2)) { $messages[]="Пароли не совпадают";  }
if(!empty($login)) {
    $query = "SELECT user FROM dealers WHERE user='" . $login . "'";
    $link->sql_query($query) or die("MySQL query $query failed.  Error if any: " . mysql_error());
    if (($row = $link->sql_fetchrow())) {
        $messages[] = "Логин \"" . $login . "\" уже занят, попробуйте другой." . $row;
    }
}
if(!empty($email)) {
    $query = "SELECT eml FROM dealers WHERE eml='" . $email . "'";
    $link->sql_query($query) or die("MySQL query $query failed.  Error if any: " . mysql_error());
    if (($row = $link->sql_fetchrow())) {
        $messages[] = "Такой Email \"" . $email . "\", уже используется." . $row;
    }
}
//   else
  // {
    if(empty($messages)) 
    {
    $row = newDealer($login,$pass,$email,$_SERVER['REMOTE_ADDR']);
     
    $a=$d=0;  
    if(isset($row['a']))
    {    $a=$row['a'];
    setcookie("a",$a,time()+60*60*24);}
    if(isset($row['id']))
        {$d=$row['id'];
        setcookie("i", $d, time()+86400);}
    if(isset($row['hash']))
        {$hash=$row['hash'];
        setcookie("hsh", $hash, time()+86400);}
  ini_set('session.cookie_lifetime', 86400);
  ini_set('session.gc_maxlifetime', 86400);
  //cleanMemberSession($row["user"],$d,$a,$row['hash'],$row['dealer']);
        cleanMemberSession($row["user"],$d,$a,$row['hash'],$row['dealer'],$row['currency'],$row['rate'],$row['postpaid']);
$name_from = "POSTBOT";
$email_from = "noreply@mpol.co";
$data_charset = "UTF-8";
$send_charset = "windows-1251";
$subject = "Добро пожаловать на Metropoliten";
$body = "Добро пожаловать на Metropoliten<br>Спасибо за выбор нашего сервиса!<br>Данные вашего аккаунта<br>Логин: ".$_POST["login"].'<br>Пароль: '.$_POST["pass"]."<br>С уважением администрация Metropoliten";
send_mime_mail($name_from, // имя отправителя
               $email_from, // email отправителя
               $login, // имя получателя
               $email, // email получателя
               $data_charset, // кодировка переданных данных
               $send_charset, // кодировка письма
               $subject, // тема письма
               $body,
               'html');
     header("Location: dealer.php");
   }
 //}
}
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html>
<head>
<title><?php print $title; ?></title>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8"/>
<link rel="shortcut icon" href="../favicon.ico" type="image/x-icon"/>
<link rel="stylesheet" type="text/css" href="css/lg2.css" />
</head>
<body>
<script type="text/javascript">
var _tmr = window._tmr || (window._tmr = []);
_tmr.push({id: "2814371", type: "pageView", start: (new Date()).getTime()});
(function (d, w, id) {
if(d.getElementById(id)) return;
var ts = d.createElement("script"); ts.type = "text/javascript"; ts.async = true; ts.id = id;
ts.src = (d.location.protocol == "https:" ? "https:" : "http:") + "//top-fwz1.mail.ru/js/code.js";
var f = function () {var s = d.getElementsByTagName("script")[0]; s.parentNode.insertBefore(ts, s);};
if (w.opera == "[object Opera]") { d.addEventListener("DOMContentLoaded", f, false); } else { f(); }
})(document, window,"topmailru-code");
</script><noscript><div style="position:absolute;left:-10000px;">
<img src="//top-fwz1.mail.ru/counter?id=2814371;js=na" style="border:0;" height="1" width="1" alt="Рейтинг@Mail.ru" />
</div></noscript>
<div class="logo"><h1>METROPOLITEN</h1><span>Cardsharing Premium System</span></div>
<div style="display:table;width:100%;height:100%">
<div style="display:table-cell;vertical-align:middle;max-height:1000px">
<div id="carbonForm">
<h1>РЕГИСТРАЦИЯ в БИЛЛИНГЕ</h1>
<form action="<?php print $_SERVER["PHP_SELF"]; ?>" method="POST">
    <div class="fContR">
    <div class="field"><input align=center placeholder=Логин type="text" name="login" id="login" value="<?php print isset($_POST["login"]) ? $_POST["login"] : "" ; ?>" maxlength="22"></div>
    <div class="field"><input align=center placeholder="Пароль" type="password" name="pass" id="pass" maxlength="33"></div>
    <div class="field"><input align=center placeholder="Пароль повторно" type="password" name="pasr" id="pasr" maxlength="33"></div>
    <div class="field"><input align=center placeholder="Email" type="email" name="eml" id="eml" maxlength="33"></div>
    </div>
    <div align=right style="padding:10px">
    <input type="submit" class="signupButton" name="submit" id="submit" value="ЗАРЕГИСТРИРОВАТЬ" />
    <a id="register" href='login2.php'/>ВХОД в БИЛЛИНГ</a>
    </div>
<div align=center style="clear:both;color:darkred">
<?php
// <label class="label" for="login">ЛОГИН:</label>
if($messages) { displayErrors($messages); }
?>
</div>
</form>
</div>
<?php include_once("adv2.php")?>
</div>
</div>
<?php include_once("footer.htm")?>
</div>
</body>
</html>