<?php
include_once("config.php");
 checkLoggedIn("no");
$title="Cтраница регистрации";

if(isset($_POST["submit"])){
   $login=$link->real_escape_string(trim($_POST["login"]));
    $pass=$link->real_escape_string(trim($_POST["pass"]));
    $pass2=$link->real_escape_string(trim($_POST["pasr"]));
    $email=$link->real_escape_string(trim($_POST["eml"]));
   field_validator("Логин", $login, "alphanumeric", 4, 33);
   field_validator("Пароль", $pass, "string", 4, 33);
   field_validator("Повторный пароль", $pass2, "string", 4, 33);
   field_validator("Email", $email,"string", 5, 33);
   if(strcmp($pass, $pass2)) { $messages[]="Пароли не совпадают";  }
if(!empty($login)) {
    $query = "SELECT user FROM dealers WHERE user='" . $login . "'";
    $res=$link->query($query) or die("MySQL query $query failed.  Error if any: " . mysql_error());
    if (($row = $res->fetch_assoc())) {
        $messages[] = "Логин \"" . $login . "\" уже занят, попробуйте другой." . $row;
    }
}
if(!empty($email)) {
    $query = "SELECT eml FROM dealers WHERE eml='" . $email . "'";
    $res=$link->query($query) or die("MySQL query $query failed.  Error if any: " . mysql_error());
    if (($row = $res->fetch_assoc())) {
        $messages[] = "Такой Email \"" . $email . "\", уже используется." . $row;
    }
}
//   else
  // {
    if(empty($messages)) 
    {
    $row = newDealer($login,$pass,$email,$_SERVER['REMOTE_ADDR']);

    if (isset($row['id']) && $row['id'] > 0 && !empty($email)) {
        // Send verification email instead of logging in directly
        require_once(__DIR__ . '/email_verify.php');
        sendVerificationEmail($link, (int)$row['id'], $email, 1);
        header("Location: verify_email.php?dealer=" . (int)$row['id']);
        exit;
    }

    // Fallback: if no email provided (e.g. social login) — log in directly
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
        cleanMemberSession($row["user"],$d,$a,$row['hash'],$row['dealer'],$row['currency'],$row['rate'],$row['postpaid']);
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
<!---<link rel="stylesheet" type="text/css" href="css/lg.css" />-->
<link href="https://fonts.googleapis.com/css2?family=PT+Mono&amp;family=Source+Sans+3&amp;display=swap" rel="stylesheet">
<link rel="stylesheet" type="text/css" href="css/mb.css?v=1" />
<meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body>
<?php  include_once("adv.php")?>
<!--- <div class="logo"><h1>METROPOLITEN</h1><span>Cardsharing and IPTV/OTT premium system</span><div><img align="middle" style="line-height:25px" src="png/telegram.png" alt=""/><span><a href="https://t.me/mpolbot">@MPOLBot</a></span></div></div>
<div style="display:table;width:100%;height:86%">

<div style="display:table-cell;vertical-align:middle;max-height:1000px">
 --->
<div id="container">
    <div id="content">
<div id="carbonForm">
        <div class="logo">
            <h1>METROPOLITEN</h1>
            <span>Cardsharing and IPTV/OTT Premium System</span>
            <div style="display: inline-flex; align-items: center;">
                <img src="png/telegram.png" alt="" />
                <span><a href="https://t.me/mpolbot">@MPOLBot</a></span>
            </div>
        </div>
<div class="title">РЕГИСТРАЦИЯ В БИЛЛИНГЕ</div>
<form action="<?php print $_SERVER["PHP_SELF"]; ?>" method="POST">
<!--    <div class="fContR"> -->
    <div class="field"><input align=center placeholder=Логин type="text" name="login" id="login" value="<?php print isset($_POST["login"]) ? $_POST["login"] : "" ; ?>" maxlength="22"></div>
    <div class="field"><input align=center placeholder="Пароль" type="password" name="pass" id="pass" maxlength="33"></div>
    <div class="field"><input align=center placeholder="Пароль повторно" type="password" name="pasr" id="pasr" maxlength="33"></div>
    <div class="field"><input align=center placeholder="Email" type="email" name="eml" id="eml" maxlength="33"></div>
<!--  </div> -->
    <div align=right style="float:right;padding:0 10px;margin:10px 0 6px">
    <input type="submit" class="signupButton" name="submit" id="submit" value="ЗАРЕГИСТРИРОВАТЬ" />
    <input type="button" class="signupButton" name="register" id="register" value="ВХОД В БИЛЛИНГ" onClick="window.location.href='login.php'"/>
    </div>
<div align=center style="clear:both;color:#ff9494">
<?php
// <label class="label" for="login">ЛОГИН:</label>
if($messages) { displayErrors($messages); }
?>
</div>
</form>
</div>
</div>
</div>
<!--<?php include_once("footer.htm")?>-->
</body>
</html>