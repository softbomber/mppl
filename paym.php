<?
//require_once($_SERVER["DOCUMENT_ROOT"]."/conf/config_db_ro_without_auth1.php");
require_once(dirname(__FILE__) . '\yamoney\lib\api.php');
require_once(dirname(__FILE__) . '\yasa\constants.php');

echo dirname(__FILE__) . '\yamoney\lib\api.php';
$ym = new YandexMoney(CLIENT_ID, '\ym.log');

main();
function main()
{
ob_start();
print_r($_POST);
$message = ob_get_contents();
ob_end_clean();

//mail('igor@itsoft.ru', 'Notification details', $message);

if($_POST['codepro']!='false')
 return mail('igor@itsoft.ru', 'We got YM with protection code', "We cannot  automatically get this payment.\n\n $message");


$str=$_POST['notification_type'] . '&' .
        $_POST['operation_id'] . '&' .
        $_POST['amount'] . '&' .
        $_POST['currency'] . '&' .
        $_POST['datetime'] . '&' .
        $_POST['sender'] . '&' .
        $_POST['codepro'] . '&секретный код со страницы https://sp-money.yandex.ru/myservices/online.xml&' .
        $_POST['label'];
 
if(sha1($str)!=$_POST['sha1_hash'])
   return mail('igor@itsoft.ru', 'Fake notification', $message);
 

$ym = new YandexMoneyNew(CLIENT_ID);

$token='токен полученный на Шаге 4';
$resp = $ym->operationDetail($token, $_POST['operation_id']);

$message .= "\r\n". var_export($_POST, 1) . var_export($resp);

if($resp->isSuccess())
 mail('igor@itsoft.ru', 'We got payment', $message . "\n\nmessage=" . $resp->getMessage() .
      "\n\ndetail=" . $resp->getDetail() .
      "\n\ntitle=" . $resp->getTitle());
else
 mail('igor@itsoft.ru', 'We did not get payment... Hm... Why?', $message);

$operation_id = $_POST['operation_id'];
$sender = $_POST['sender'];
$amount = $_POST['amount'];
$datetime = $_POST['datetime'];
preg_match('/i(\d+);/', $resp->getMessage(), $m);
$invoice_id = $m[1];

$r=mysql_query("INSERT INTO it_payment_ym VALUES('$operation_id', '$sender', '$amount', '$datetime', '$invoice_id')");
if(!$r)
 mail('igor@itsoft.ru', 'Problem to insert in it_payment_ym', $message . mysql_error());


}//main
?>