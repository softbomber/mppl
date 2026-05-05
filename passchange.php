<?php
include_once("config.php");
checkLoggedIn("no");
$title="СМЕНА СТАРОГО ПАРОЛЯ НА НОВЫЙ";
$rid=0;
$ruser='';
$rpwd='';
$reml='';
$key='';

if(isset($_POST["submit"]) && isset($_POST["password"]) && isset($_POST["key"]))
{
    $pwd=mysql_real_escape_string(trim($_POST["password"]));
    $key=mysql_real_escape_string(trim($_POST["key"]));
    field_validator("Пароль",$pwd,"string", 4, 33);
    if($messages){
        doIndex();
        exit();
    }

    $link->sql_query("select id,user,pwd,eml from dealers where hash='$key' and rest=1 limit 1");
    if ($row = $link->sql_fetchrow())
    {
        $rid=$row['id'];
        $ruser=$row['user'];
        $rpwd=$row['pwd'];
        $reml=$row['eml'];
    }

    $link->sql_query("update dealers set rest=0, pwd='$pwd' where hash='$key' and rest=1");
    $name_from = "POSTBOT";
    $email_from = "noreply@mpol.co";
    $data_charset = "UTF-8";
    $send_charset = "windows-1251";
    $subject = "Восстановление пароля. Логин: ".$ruser;
    $body = 'Пароль переустановлен!<br><br>Текущий пароль: '.$pwd.'<br><br>С уважением администрация Metropoliten';
    send_mime_mail($name_from, // имя отправителя
        $email_from, // email отправителя
        $ruser, // имя получателя
        $reml, // email получателя
        $data_charset, // кодировка переданных данных
        $send_charset, // кодировка письма
        $subject, // тема письма
        $body,
        'html');

    $mess[]="ПАРОЛЬ СМЕНЁН И ВЫСЛАН НА EMAIL, УКАЗАННЫЙ ПРИ РЕГИСТРАЦИИ.";
    if($messages || $mess){
        doIndex();
    }
    exit();
}/* else
{
    doIndex();
    exit();
}*/

if(isset($_GET['key']))
{
    $key=trim($_GET['key']);
    $link->sql_query("select id,user,pwd,eml from dealers where hash='$key' and rest=1 limit 1");
    if ($row = $link->sql_fetchrow())
    {
        $rid=$row['id'];
        $ruser=$row['user'];
        $rpwd=$row['pwd'];
        $reml=$row['eml'];
        //doIndex();
    }
    else
    {
        $mess[] = "ВЫ ПРОШЛИ ПО УСТАРЕВШЕЙ ССЫЛКЕ.";
    }
    doIndex();
    exit();
}

function doIndex() {
global $messages;
global $title;
global $mess;
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html>
<head>
    <title><?php print $title; ?></title>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8"/>
    <?php if($mess) {  echo "<noscript><meta http-equiv=\"refresh\" content=\"10\" url=\"https://localhost/login.php\"></noscript>";} ?>
    <link rel="shortcut icon" href="../favicon.ico" type="image/x-icon"/>
    <link rel="stylesheet" type="text/css" href="css/lg.css" />
</head>
<body>
<div style="display:table;width:100%;height:100%">
    <div style="display:table-cell;vertical-align:middle;max-height:1000px">
        <div
            <?php
            if(!$mess)
                echo 'id="carbonForm" style="margin:0 auto">';
            else
                echo 'id="msg">';
            ?>
            <h1>СМЕНА ПАРОЛЯ</h1>
            <form action="<?php print $_SERVER["PHP_SELF"]; ?>" method="POST">
               <div class="fContR">
                   <?php
                   if($mess)
                   {
                       echo '<div align="center" style="clear:both;margin-bottom:3px">';
                       displayMess($mess);
                     echo  "</div>";
                       echo "<script type=\"text/javascript\">setTimeout('location.replace(\"https://localhost/login.php\")',10000);</script>";
                    }
                   ?>

                   <?php
                   if(!$mess)
                   {
                   ?>
                   <div class="field"><input placeholder="новый пароль" type="password" name="password" value="" id="password" maxlength="33"></div>
               </div>
                <?php
                if (isset($_GET['key'])) {
                    echo '<input type="hidden" name=key value="' .mysql_real_escape_string(trim($_GET["key"])) . '">';
                }
                ?>
                <div align=right style="padding:0 10px;margin:15px 0 4px 0">
                    <input type="submit" class="signupButton" name="submit" id="submit" value="СОХРАНИТЬ"/>
                </div>
                <div align=center>
                    <?php
                    if ($messages) {
                        displayErrors($messages);
                    }
                    ?>
                </div>
        </div>
        <?php
        }
        ?>
        </form>
    </div>
</div>
</div>
</body>
</html>
<?php

}

?>