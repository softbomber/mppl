<?php
include_once("config.php");
checkLoggedIn("no");
$title="Качественный кардшаринг! Стабильные сервера!";

if(isset($_POST["submit"]))
   {
   
   field_validator("Логин", trim($_POST["login"]), "alphanumeric", 4, 33);
   field_validator("Пароль", trim($_POST["password"]), "string", 4, 33);
if(isset($_POST["captcha"])) {
    field_validator("Код", trim($_POST["captcha"]), "string", 3, 3);
    if (trim($_POST["captcha"]) != $_SESSION['secpic'])
        $messages[] = 'Неправильно введён код';
}
   /*if($messages){
     doIndex();
     exit;
   }*/
   if( !($row = checkPass($_POST["login"], $_POST["password"])) && (!empty($_POST["login"]) && !empty($_POST["password"]))) {
         $messages[]="Неправильная пара логин/пароль, попробуйте ещё раз";
     }
       if($messages){
           doIndex();
           exit;
       }

	$a=$d=0;	
$s_time=time()+60;
//   if($row['a']==1 || $row['a']==2)
    //{
      $a=$row['a'];
      setcookie("a",$a,$s_time);
      //}
    if(isset($row['id']))
        {$d=$row['id'];
        setcookie("i", $d,$s_time);}
    if(isset($row['hash']))
        {$hash=$row['hash'];
        setcookie("hsh", $hash,$s_time);}
        setcookie("pp", $row['postpaid'],$s_time);
  ini_set('session.cookie_lifetime',$s_time);
  ini_set('session.gc_maxlifetime',$s_time);
// header("Location: check.php"); exit();
    $ip=$_SERVER['REMOTE_ADDR'];
    $d=$row['id'];
    $link->sql_query("INSERT INTO ip_log (did, ip,`when`) VALUES ($d, '$ip',NOW())" ) or die("inserting. Error: ".mysql_error());
cleanMemberSession($row["user"],$d,$a,$row['hash'],$d,$row['currency'],$row['rate'],$row['postpaid']);


if (!$row['dealer'])
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
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html>
<head>
<title><?php print $title; ?></title>
<meta name="KeyWords" content="шаринг лучший качественный быстрый в Ташкенте в Узбекистане нтв+ восток континент шара кардсервер без затыков с защитой от DDoS"/>
<meta name="Description" content="Лучший кардшаринг сервис. Стабильная Шара на НТВ+, НТВ+ Восток, Континент. У нас вы получите: SMS оповещения об отключении пакетов; подключение менее 5 с.; стабильные сервера с отличным пингом c защитой от DDoS атак"/>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8"/>
<link rel="shortcut icon" href="../favicon.ico" type="image/x-icon"/>
<link rel="stylesheet" type="text/css" href="css/lg2.css" />
</head>
<body>
<script type="text/javascript">
var _tmr = window._tmr || (window._tmr = []);
_tmr.push({id:"2814371",type:"pageView",start:(new Date()).getTime()});
(function(d,w,id){if(d.getElementById(id)) return;var ts=d.createElement("script"); ts.type="text/javascript"; ts.async=true; ts.id=id;
ts.src = (d.location.protocol == "https:" ? "https:" : "http:") + "//top-fwz1.mail.ru/js/code.js";var f = function () {var s = d.getElementsByTagName("script")[0]; s.parentNode.insertBefore(ts, s);};
if (w.opera=="[object Opera]"){d.addEventListener("DOMContentLoaded",f,false); } else { f();}})(document, window,"topmailru-code");
</script><noscript><div style="position:absolute;left:-10000px;"><img src="//top-fwz1.mail.ru/counter?id=2814371;js=na" style="border:0;" height="1" width="1" alt="Рейтинг@Mail.ru" />
</div></noscript>
<div class="logo"><h1>METROPOLITEN</h1><span>Cardsharing Premium System</span></div>
<div style="display:table;width:100%;height:100%">
<div style="display:table-cell;vertical-align:middle;max-height:1000px">
<div id="carbonForm"><div style="font-size:14px;padding:1px 8px 7px 12px;color:#0e0c0c">ВХОД в БИЛЛИНГ<div style="padding-left:15px;float:right;font-size:13px" ><a href="restore.php" style="color:#6B8DC4;text-decoration:none" title="Щелкните для перехода на страницу
восстановления забытого пароля">Забыли пароль?</a></div></div>
<form action="<?php print $_SERVER["PHP_SELF"]; ?>" method="post" id="signupForm"><div class="fCont"><div class="field">
<input align=center type="text" name="login" id="login" placeholder="логин" value="<?php print isset($_POST["login"]) ? $_POST["login"] : "" ; ?>"/>
</div><div class="field"><input align=center name="password" type="password" placeholder="пароль" id="pass"/>
</div>
    <?php
    if($messages)
    {echo '<div  class="field"><input align=center type="text" name="captcha" placeholder="код с картинки" id="captcha"/></div>';
     echo '<div align=center style="height:40px"><img src="secpic.php" alt="защитный код" /></div>';
    }
?></div>
<div style="padding:10px">
<input type="submit" class="signupButton" name="submit" id="submit" value="      ВОЙТИ     " />
<a name="register" id="register" href='join2.php'"/>РЕГИСТРАЦИЯ</a>
</div>

<div align="center" style="clear:both;color:darkred">
<?php
if($messages)displayErrors($messages)
?>
</div>
</form>
</div>
<div style="position:absolute;bottom:0px;text-align:center;"><?php include_once("adv2.php")?></div>
</div>

</div>

<?php include_once("footer.htm")?>
</body></html>
<?php
}
?>