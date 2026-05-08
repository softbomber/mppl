<?php
include_once("config.php");
checkLoggedIn("no");
$title="online authorization";

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
$s_time=time()+86400;
//   if($row['a']==1 || $row['a']==2)
    //{
      $a=$row['a'];
      setcookie("a",$a,$s_time,'/');
      //}
    if(isset($row['id']))
        {$d=$row['id'];
        setcookie("i", $d,$s_time,'/');}
    if(isset($row['hash']))
        {$hash=$row['hash'];
        setcookie("hsh", $hash,$s_time,'/');}
        setcookie("pp", $row['postpaid'],$s_time,'/');
        setcookie("sort", $row['t_srt'],$s_time,'/');
  ini_set('session.cookie_lifetime',$s_time);
  ini_set('session.gc_maxlifetime',$s_time);
    $ip=$_SERVER['REMOTE_ADDR'];
    $d=$row['id'];
    $link->sql_query("INSERT INTO ip_log (did, ip,`when`) VALUES ($d, '$ip',NOW())" ) or die("inserting.  Error returned if any: ".mysql_error());
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


<div id="auth" style="display:none;width:100%;height:100%;position:absolute;">
<link rel="stylesheet" type="text/css" href="css/lg.css" />
<div style="display:table-cell;vertical-align:middle;max-height:1000px">
<div id="carbonForm"><div style="font-size:14px;padding:1px 8px 7px 12px;color:#0e0c0c">Авторизация</div>
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
<div style="padding:0 10px;float:right;margin-top:10px;margin-bottom:6px">
<input type="submit" class="signupButton" name="submit" id="submit" value="      ВХОД     " />
</div>
<div align="center" style="clear:both;color:darkred">
</div>
</form>
</div>
</div>
</div>
<script>$("body").prepend($("#auth"));$("#auth").show();</script>
<?php
}
?>