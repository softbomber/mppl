<?php
include_once("config.php");
 checkLoggedIn("yes");

$name_from = "postbot@mpol.co";
$email_from = "postbot@mpol.co";
$name_to = 'univb';
$email_to ='univb@mail.ru';
$data_charset = "UTF-8";
$send_charset = "windows-1251";
$subject = "Добро пожаловать на Metropoliten";
$body = "Добро пожаловать на Metropoliten\nСпасибо за выбор нашего сервиса!\nДанные вашего аккаунта\nС уважением администрация Metropoliten";
send_mime_mail($name_from, // имя отправителя
                        $email_from, // email отправителя
                        $name_to, // имя получателя
                        $email_to, // email получателя
                        $data_charset, // кодировка переданных данных
                        $send_charset, // кодировка письма
                        $subject, // тема письма
                        $body,
                        'html');
echo 'Email sent';
?>