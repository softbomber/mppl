<?php
include_once("config.php");
checkLoggedIn("no");
$title="Восстановление пароля";

if(isset($_POST["submit"]))
{
    $login=mysql_real_escape_string(trim($_POST["login"]));
    $eml=mysql_real_escape_string(trim($_POST["eml"]));
    if(empty($login) && empty($eml)) {
        $messages[]="ОДНО ИЗ ПОЛЕЙ ДОЛЖНО БЫТЬ ЗАПОЛНЕНО!";
    }
    else
    {
         if(!empty($login)) field_validator("Логин", $login, "alphanumeric", 4, 33);
         if(!empty($eml)) field_validator("Email", $eml, "string", 5, 33);
    }
    if($messages){
        doIndex();
        exit;
    }
    if( !($row = checkL($login,$eml)) ) {
        $messages[]="ЛОГИН/EMAIL НЕ НАЙДЕНЫ В БАЗЕ";
    }
    else {$ruser=$row['user'];$reml=$row['eml'];}
    if($messages){
        doIndex();
        exit;
    }
    $mess[]="ССЫЛКА ДЛЯ ВОССТАНОВЛЕНИЯ ПАРОЛЯ ВЫСЛАНА НА EMAIL,<br>УКАЗАННЫЙ ПРИ РЕГИСТРАЦИИ.";
    //if($messages){
//        doIndex();
  //  }
    $key=md5(microtime());
    $sql_req="update dealers set hash='".$key."',rest=1,resttime=NOW() where";
    if(!empty($login))
        $sql_req .= " user='".$ruser."'";
    if(!empty($login) && !empty($eml))
        $sql_req .= " or ";
    if(!empty($eml))
        $sql_req .= " eml='".$reml."'";
    $link->sql_query($sql_req);//"update dealers set hash='$key',rest=1,resttime=NOW() where user='$login' or eml='$eml' "
    $name_from = "POSTBOT";
    $email_from = "noreply@mpol.co";
    $data_charset = "UTF-8";
    $send_charset = "windows-1251";
    $subject = "Восстановление пароля. Логин: ".$login;
    $body = "Вы или кто то другой, инициировали процесс восстановления пароля для логина ".$ruser.'<br>Для восстановления пройдите по нижеследующей ссылке<br>https://mpol.co/passchange.php?key='.$key.'<br>С уважением администрация Metropoliten';
    send_mime_mail($name_from, // имя отправителя
        $email_from, // email отправителя
        $login, // имя получателя
        $reml, // email получателя
        $data_charset, // кодировка переданных данных
        $send_charset, // кодировка письма
        $subject, // тема письма
        $body,"html");
    /*cleanMemberSession($row["user"],$d,$a,$row['hash'],$row['dealer']);

    if (!$row['dealer'])
        header("Location: user.php");
    else
        header("Location: dealer.php");*/
  // header("Location: check.php");
    if($messages || $mess){
        doIndex();
    }
    exit();
} else {
    doIndex();
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
        <meta name="KeyWords" content="шаринг лучший качественный быстрый в Ташкенте в Узбекистане нтв+ восток континент шара кардсервер без затыков с защитой от DDoS"/>
        <meta name="Description" content="Лучший кардшаринг сервис. Стабильная Шара на НТВ+, НТВ+ Восток, Континент. У нас вы получите: SMS оповещения об отключении пакетов; подключение менее 5 с.; стабильные сервера с отличным пингом c защитой от DDoS атак"/>
        <meta http-equiv="Content-Type" content="text/html; charset=UTF-8"/>
        <link rel="shortcut icon" href="../favicon.ico" type="image/x-icon"/>
        <link rel="stylesheet" type="text/css" href="css/lg.css" />
    </head>
    <body>
    <div style="display:table;width:100%;height:100%">
        <div style="display:table-cell;vertical-align:middle;max-height:1000px">
            <div
<?php
                if(!$mess)
                echo 'id="carbonForm" style="margin:auto">';
else
                echo 'id="msg">';
                    ?>
                <h1>ВОССТАНОВЛЕНИЕ ПАРОЛЯ</h1>
                <form action="<?php print $_SERVER["PHP_SELF"]; ?>" method="post" id="rFrom">
                    <div class="fCont">
                        <div align="center" style="clear:both;margin-bottom:3px">
                            <?php
                            if($mess) displayMess($mess)
                            ?>
                        </div>
<?php
                        if(!$mess)
{
?>
                        <div class="field"> <input placeholder=логин type="text" name="login" id="login"/></div>
                        <div class="field"><input placeholder=email type="email" name="eml" id="eml" maxlength="33"></div>
                    </div>
                    <div style="padding:0 10px;text-align:center;margin:10px 0 6px 0;float:right">
                        <input type="submit" class="signupButton" name="submit" id="submit" value="ВОССТАНОВИТЬ"/>
                        <input type="button" class="signupButton" value="АВТОРИЗАЦИЯ"
                               onClick="window.location.href='login.php'"/>
                    </div>
                    <div align="center" style="clear:both;color:darkred">
                        <?php
                        if ($messages)displayErrors($messages)
                        ?>
                    </div>
                    <?php
                    }
                    ?>
                </form>
            </div>
        </div>
    </div>
    </html>
    <?php
}
?>