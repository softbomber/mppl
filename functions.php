<?php
//require_once("Mpolbot/vendor/autoload.php");
$token = getenv('TG_BOT_TOKEN') ?: '';
$bot = new TelegramBot($token);

$redis_host = getenv('REDIS_HOST') ?: '127.0.0.1';
$redis_port = (int)(getenv('REDIS_PORT') ?: 6379);
$redis_pass = getenv('REDIS_PASS') ?: '';


function tgSend(string $token, string $chatId, string $text): array {
    $url = "https://api.telegram.org/bot{$token}/sendMessage";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'HTML',
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 3,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_NOSIGNAL       => 1,
    ]);
    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    curl_close($ch);
    if ($errno || !$body) return ['ok' => false, 'error_code' => 0];
    return json_decode($body, true) ?? ['ok' => false, 'error_code' => 0];
}

define('TG_TOKEN', getenv('TG_BOT_TOKEN') ?: '');
define('TG_ADMIN', getenv('TG_ADMIN_CHAT_ID') ?: '85534516');

$headers = array(
//  'User-Agent' => 'Mozilla/5.0 (Windows NT 6.1; Win64; x64; rv:109.0) Gecko/20100101 Firefox/115.0',
//  'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
//  'Accept-Language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
//  'Accept-Encoding' => 'gzip, deflate, br',
//  'Content-Type' => 'application/x-www-form-urlencoded',
  'Cookie' => '',
//  'Connection' => 'keep-alive',
//  'Upgrade-Insecure-Requests' => '1',
//  'Sec-Fetch-Dest' => 'document',
//  'Sec-Fetch-Mode' => 'navigate',
//  'Sec-Fetch-Site' => 'same-origin',
//  'Sec-Fetch-User' => '?1',
//  'Pragma' => 'no-cache',
//  'Cache-Control' => 'no-cache',
);



function GetSess()
{
global $link;
$res=$link->query('SELECT session,cf_clear,sessdate FROM iptvsession');
if ($res->num_rows == 1) {
   $row = $res->fetch_assoc();
   $s= $row['session'];
   $cf_cl=$row['cf_clear'];	
   $sessdate= $row['sessdate'];
}
/*if ((time() - $sessdate) >= 3000) 
{
  $s = shell_exec("cd /var/www/js && /usr/local/bin/node \"./gs.js\" 2>&1");
//  $s = shell_exec("/usr/local/bin/node /var/www/js/gs.js");
  $p = explode(';', $s);
  $v = array_map('trim', $p);
  if(strcmp($v[4],"https://zedom.net/cabinet")===0)
        {
	$s=$v[0];
        $link->query("UPDATE iptvsession set session='$s',sessdate=UNIX_TIMESTAMP(NOW())");
	}
} */
return $s."; ".$cf_cl;
}

function UpdSess()
{
global $link;
$res=$link->query('SELECT sessdate FROM iptvsession');
if ($res->num_rows == 1) {
   $row = $res->fetch_assoc();
   $sessdate= $row['sessdate'];
}
if ((time() - $sessdate) >= 29000) 
{
do
{
  $s = shell_exec("cd /var/www/js && /usr/bin/node -e 'require(\"./getsession.js\")()' 2>&1");
}while ($s == null);

if ($s !== null)
  $p = explode(';', $s);
  $v = array_map('trim', $p);
if (isset($v[4])) {
    $string1 = $v[4];
    $string2 = "https://zedom.net/cabinet";
    if (strcmp($string1 ?? '', $string2) === 0) {
        $s = $v[0];
        $link->query("UPDATE iptvsession set session='$s', sessdate=UNIX_TIMESTAMP(NOW())");
    }
}
} 
}

function logQuery($query) {
    $logFile = '/tmp/query_log.txt'; // Имя файла для логов

    // Открываем файл для записи (добавление)
    $handle = fopen($logFile, 'a');
    
    if ($handle) {
        $date = date('Y-m-d H:i:s'); // Получаем текущую дату и время
        fwrite($handle, "[$date] $query\n"); // Записываем дату и запрос в файл
        fclose($handle); // Закрываем файл
    } else {
        echo "Не удалось открыть файл для записи.";
    }
}

function renewCookie()
{
 global $link;
// $headers['Origin']='https://zedom.net';
 $headers['Referer']='https://zedom.net/partner/by_link';
 $url = 'https://zedom.net/partner';
 $headers['Accept']='text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8';
 $headers['Accept-Language']='ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7';
 $headers['Connection']='keep-alive';
 $headers['Content-Type']='application/x-www-form-urlencoded; charset=UTF-8';
// $headers['X-Requested-With']='XMLHttpRequest';
 $headers['Sec-Fetch-Dest']='document';
 $headers['Sec-Fetch-Mode']='navigate';
 $headers['Sec-Fetch-Site']='same-origin';
 $headers['Sec-Fetch-User']='?1';
 $headers['Accept-Encoding']='gzip, deflate, br';
 $headers['Upgrade-Insecure-Requests']='1';
 $res=mkReq($url,'', $headers);
 return $res['response'];
}

function renew()
{
 $headers['Origin']='https://zedom.net';
 $headers['Referer']='https://zedom.net/tariff/stalkerportal';
 $url = 'https://zedom.net/partner/by_create';
 $headers['Accept']='text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8';
 $headers['Accept-Language']='ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7';
 $headers['Connection']='keep-alive';
 $headers['Content-Type']='application/x-www-form-urlencoded; charset=UTF-8';
 $headers['X-Requested-With']='XMLHttpRequest';
 $headers['Sec-Fetch-Dest']='document';
 $headers['Sec-Fetch-Mode']='navigate';
 $headers['Sec-Fetch-Site']='same-origin';
 $headers['Sec-Fetch-User']='?1';
 $headers['Accept-Encoding']='gzip, deflate, br';
 $headers['Upgrade-Insecure-Requests']='1';
 $res=mkReq($url,'', $headers);
 return $res['response'];
}

function mkReq($url, $data = array(), $headers = array(),$proxyPort = 0)  //, $proxyHost = 'localhost', 
{
  do {
      $ch = curl_init($url);
      $sess = GetSess();
      $headers['Cookie'] = $sess;
      $headersProcessed = array_map(
          function ($key, $value) {
              if (is_array($value)) {
                  $value = json_encode($value); // Or use implode(", ", $value) for a simple string
              }
              if (is_array($key)) {
                  $key = json_encode($key); // Or use implode(", ", $key) for a simple string
              }
              return $key . ': ' . $value;
          }, 
          array_keys($headers), 
          $headers
      );

      $options = array(
          CURLOPT_RETURNTRANSFER => true,
//CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:137.0) Gecko/20100101 Firefox/137.0',
//CURLOPT_USERAGENT => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36',
CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36',
//          CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36',
//	  CURLOPT_USERAGENT => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
          CURLOPT_SSL_VERIFYPEER => false,
          CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
      );

      if ($proxyPort > 0) {
          $options[CURLOPT_PROXY] = "localhost:$proxyPort";//"$proxyHost:$proxyPort"; // Set the SSH proxy
          $options[CURLOPT_PROXYTYPE] = CURLPROXY_HTTP; // Specify the proxy type
      }

      if (!empty($data)) {
          $options[CURLOPT_POST] = true;
          $options[CURLOPT_POSTFIELDS] = $data;
      }

      if (!empty($headers)) {
          $options[CURLOPT_HTTPHEADER] = $headersProcessed;
      }

      curl_setopt_array($ch, $options);
      $response = curl_exec($ch);

      if (curl_errno($ch)) {
          echo 'Curl error: ' . curl_error($ch);
      }

      $info = curl_getinfo($ch);
      $locationHeader = isset($info['url']) ? $info['url'] : null;
      curl_close($ch);

      if (strcasecmp($locationHeader, 'https://zedom.net/auth/login') === 0 || 
          strcasecmp($locationHeader, 'https://zedom.net/auth/logln') === 0) {
          $locationHeader = null;
          $response = null;
      }
  } while (strcasecmp($locationHeader, 'https://zedom.net/auth/login') === 0 || 
           strcasecmp($locationHeader, 'https://zedom.net/auth/logln') === 0);

  return array('locationHeader' => $locationHeader, 'response' => $response);
}

function mkReqQ($url, $data = array(), $headers = array()) {
  do {
      $ch = curl_init($url);
      $sess=GetSess();
      $headers['Cookie'] = $sess;
	    $headersProcessed = array_map(
	    function ($key, $value) {
        	if (is_array($value)) {
	          $value = json_encode($value); // Или используйте implode(", ", $value) для простой строки
		      }
	       if (is_array($key)) {
	          $key = json_encode($key); // Или используйте implode(", ", $key) для простой строки
		      }
	      return $key . ': ' . $value;
	    },	    array_keys($headers),
	    $headers
	    );    
//	echo "current cookie is: ".$headers['Cookie']."\n";
      $options = array(
          CURLOPT_RETURNTRANSFER => true,
//          CURLOPT_USERAGENT => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36',
CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36',
//        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 6.1; Win64; x64; rv:109.0) Gecko/20100101 Firefox/115.0',
//        CURLOPT_USERAGENT => 'Mozilla/5.0 (Linux; Android 6.0; Nexus 5 Build/MRA58N) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/109.0.0.0 Mobile Safari/537.36',
          CURLOPT_SSL_VERIFYPEER => false,
      );

      curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);

      if (!empty($data)) {
          $options[CURLOPT_POST] = true;
          $options[CURLOPT_POSTFIELDS] = $data;
      }
/* else {
          $options[CURLOPT_HTTPGET] = true;
      }*/
//      $options[CURLOPT_URL] = $url;
      if (!empty($headers)) {
          $options[CURLOPT_HTTPHEADER] = $headersProcessed;
      }
      curl_setopt_array($ch, $options);
      $response = curl_exec($ch);
//echo "response is: ".$response."\n";
      if (curl_errno($ch)) {
          echo 'Curl error: ' . curl_error($ch);
      }
      $info = curl_getinfo($ch);
      $locationHeader = isset($info['url']) ? $info['url'] : null;
//      echo "location header is: ".$locationHeader;
      curl_close($ch);
      if (strcasecmp($locationHeader, 'https://zedom.net/auth/login') === 0 || strcasecmp($locationHeader, 'https://zedom.net/auth/logln') === 0) {
          $locationHeader = null;
          $response = null;
      }
  } while (strcasecmp($locationHeader, 'https://zedom.net/auth/login') === 0 || strcasecmp($locationHeader, 'https://zedom.net/auth/logln') === 0);
  return array('locationHeader' => $locationHeader, 'response' => $response);
}

/*function mkReq_($url, $data = array(), $headers = array()) {
  $ch = curl_init();

  $headers['Cookie']=GetSess();
  $options = array(
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_USERAGENT => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
//'Mozilla/5.0 (Windows NT 6.1; Win64; x64; rv:109.0) Gecko/20100101 Firefox/115.0',
//        CURLOPT_AUTOREFERER => true,
//        CURLOPT_CONNECTTIMEOUT => 30,
//        CURLOPT_TIMEOUT => 30,
//        CURLOPT_MAXREDIRS => 10,
  );
  curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
  if (!empty($data)) {
      $options[CURLOPT_POST] = true;
      $options[CURLOPT_POSTFIELDS] = http_build_query($data);
  } else {
      $options[CURLOPT_HTTPGET] = true;
  }
  $options[CURLOPT_URL] = $url;
  if (!empty($headers)) {
      $options[CURLOPT_HTTPHEADER] = $headers;
  }
  curl_setopt_array($ch, $options);
  $response = curl_exec($ch);
  if (curl_errno($ch)) {
      echo 'Curl error: ' . curl_error($ch);
  }
  curl_close($ch);
  return $response;
} */

function ilookActivate($l)
{
 global $link;
 $headers['Origin']='https://zedom.net';
 $headers['Referer']='https://zedom.net/partner/manage/'.$l;
 $url = 'https://zedom.net/ajax/partner_activate_tariff';
 $headers['Accept']='*/*';
 $headers['Accept-Language']='ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7';
 $headers['Connection']='keep-alive';
 $headers['Upgrade-Insecure-Requests']='1';
$headers['Content-Type'] = 'application/x-www-form-urlencoded; charset=UTF-8';
$headers['Priority'] = 'u=1, i';
$headers['Sec-CH-UA'] = '"Google Chrome";v="142", "Chromium";v="142", "Not/A)Brand";v="24"';
$headers['Sec-CH-UA-Arch'] = '"x86"';
$headers['Sec-CH-UA-Bitness'] = '"64"';
$headers['Sec-CH-UA-Full-Version'] = '"142.0.0.0"';
$headers['Sec-CH-UA-Full-Version-List'] = '"Google Chrome";v="142.0.0.0", "Chromium";v="142.0.0.0", "Not/A)Brand";v="24.0.0.0"';
$headers['Sec-CH-UA-Mobile'] = '?0';
$headers['Sec-CH-UA-Model'] = '""';
$headers['Sec-CH-UA-Platform'] = '"Linux"';
$headers['Sec-CH-UA-Platform-Version'] = '"10.0.0"';
$headers['Sec-Fetch-Dest'] = 'empty';
$headers['Sec-Fetch-Mode'] = 'cors';
$headers['Sec-Fetch-Site'] = 'same-origin';
$headers['Sec-Fetch-User']='?1';
$headers['X-Requested-With'] = 'XMLHttpRequest';
 $data = 'ref_name='.$l;
 $res=mkReq($url, $data, $headers);
 return $res['response'];
/*$r="update accounts set iptvkey='$iptvkey',iptvurl='$pllink',iptvsdom='$tldname' where iptvusr='$l'";
$res=$link->query($r);*/
}

function ilookGetAmounts()
{
 global $link;
$url = 'https://zedom.net/finance/overview';
$headers['Accept'] = 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7';
$headers['Accept-Language'] = 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7';
$headers['Content-type']= 'application/x-www-form-urlencoded';
$headers['Priority'] = 'u=0, i';
//$headers['Origin']= 'https://zedom.net';
$headers['Referer'] = 'https://zedom.net/finance/overview';
$headers['Sec-CH-UA'] = '"Google Chrome";v="137", "Chromium";v="137", "Not/A)Brand";v="24"';
$headers['Sec-CH-UA-Arch'] = '"x86"';
$headers['Sec-CH-UA-Bitness'] = '"64"';
$headers['Sec-CH-UA-Full-Version'] = '"142.0.0.0"';
$headers['Sec-CH-UA-Full-Version-List'] = '"Google Chrome";v="142.0.0.0", "Chromium";v="142.0.0.0", "Not/A)Brand";v="24.0.0.0"';
$headers['Sec-CH-UA-Mobile'] = '?0';
$headers['Sec-CH-UA-Model'] = '""';
$headers['Sec-CH-UA-Platform'] = '"Linux"';
$headers['Sec-CH-UA-Platform-Version'] = '"10.0.0"';
$headers['Sec-Fetch-Dest'] = 'document';
$headers['Sec-Fetch-Mode'] = 'navigate';
$headers['Sec-Fetch-Site'] = 'same-origin';
$headers['Sec-Fetch-User'] = '?1';
$headers['Upgrade-Insecure-Requests'] = '1';
 $res=mkReq($url, '', $headers);
 return $res['response'];
/*$r="update accounts set iptvkey='$iptvkey',iptvurl='$pllink',iptvsdom='$tldname' where iptvusr='$l'";
$res=$link->query($r);*/
}

function ilookGetStAcc($l)
{
 global $link;
 $headers['Origin']='https://zedom.net';
 $headers['Referer']='https://zedom.net/partner/manage/'.$l;
 $url = 'https://zedom.net/ajax/partner_get_stpacc_data';
 $headers['Content-Type']='application/x-www-form-urlencoded; charset=UTF-8';
 $headers['Accept']='*/*';
 $headers['Accept-Language']='ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7';
 $headers['Connection']='keep-alive';
 $headers['Sec-Fetch-Dest']='empty';
 $headers['Sec-Fetch-Mode']='cors';
 $headers['Sec-Fetch-Site']='same-origin';
 $headers['X-Requested-With']='XMLHttpRequest';
// $headers['sec-ch-ua']='"Not_A Brand";v="99", "Google Chrome";v="109", "Chromium";v="109"';
 $data = 'ref_name='.$l;
 $res=mkReq($url, $data, $headers);
 return $res['response'];
}

function ilookCreateAcc($l,$p,$d_id,$plname)
{
global $link;
global $bot;

/*try{
      $tosend = "Был создан IPTV Логин ".$l."\nпароль: ".$p;
      $bot->sendMessage("85534516",$tosend,"HTML");
   } catch (\TelegramBot\Api\Exception $e) { };*/
$iptvusr="mp".$d_id."_".$l;
$headers['Origin']='https://zedom.net';
$headers['Referer']='https://zedom.net/partner/by_create';
$url = 'https://zedom.net/partner/by_create';
$headers['Priority'] = 'u=0, i';
$headers['Sec-CH-UA'] = '"Google Chrome";v="137", "Chromium";v="137", "Not/A)Brand";v="24"';
$headers['Sec-CH-UA-Arch'] = '"x86"';
$headers['Sec-CH-UA-Bitness'] = '"64"';
$headers['Sec-CH-UA-Full-Version'] = '"142.0.0.0"';
$headers['Sec-CH-UA-Full-Version-List'] = '"Google Chrome";v="142.0.0.0", "Chromium";v="142.0.0.0", "Not/A)Brand";v="24.0.0.0"';
$headers['Sec-CH-UA-Mobile'] = '?0';
$headers['Sec-CH-UA-Model'] = '""';
$headers['Sec-CH-UA-Platform'] = '"Linux"';
$headers['Sec-CH-UA-Platform-Version'] = '"10.0.0"';
$headers['Sec-Fetch-Dest'] = 'document';
$headers['Sec-Fetch-Mode'] = 'navigate';
$headers['Sec-Fetch-Site'] = 'same-origin';
$headers['Sec-Fetch-User'] = '?1';
$headers['Upgrade-Insecure-Requests'] = '1';
 $data = 'username='.$iptvusr.'&password='.$p.'&repassword='.$p;
$resp=mkReq($url, $data, $headers);
/*sleep(2);
$html=ilookChkacc($iptvusr);*/
$attempts = 0;
$html = '';
while ($attempts < 4) {
    $html = ilookChkacc($iptvusr);
    
    if (!empty(trim(strip_tags($html)))) {
        break;
    }
    $attempts++;
    sleep(1); 
}

if (empty(trim(strip_tags($html)))) {
    file_put_contents(
        'error_log.txt',
        date('Y-m-d H:i:s') . " - 4 пустых ответа\nHTML:\n" . print_r($html, true) . "\nRESP:\n" . print_r($resp, true) . PHP_EOL,
        FILE_APPEND
    );
}
$tldname=getElbyId($html,'tldName');
$pllink=extractText($html,'pllink');
$iptvkey=extractText($html,'keyWrap');
/*$r="update accounts set iptvkey='$iptvkey',iptvurl='$pllink',iptvsdom='$tldname',iptvcdn=1 where accounts.`user`='$l'";
$res=$link->query($r);*/
$stmt = $link->prepare("UPDATE accounts SET iptvkey=?, iptvurl=?, iptvsdom=?, iptvcdn=1 WHERE accounts.`user`=?");
$stmt->bind_param("ssss", $iptvkey, $pllink, $tldname, $l);
$res = $stmt->execute();
$affected_rows = $stmt->affected_rows;
$query = "UPDATE accounts SET iptvkey='$iptvkey',iptvurl='$pllink',iptvsdom='$tldname',iptvcdn=1 WHERE accounts.`user`='$l'";
logQuery("$query\n| Affected rows: $affected_rows\n");
//sleep(1);
//downloadAndSaveFile($iptvkey,$pllink);
downloadAndSaveFileBash($plname,$pllink);
return $resp;
}

/*function downloadAndSaveFileold($filenamePart, $fileUrl) {
  $savePath = '/var/www/p/' . $filenamePart .".m3u8";
  $fileContent = file_get_contents($fileUrl);

  if ($fileContent === false) {
      echo "Ошибка при загрузке файла с URL: $fileUrl\n";
      return false;
  }
  $result = file_put_contents($savePath, $fileContent);

  if ($result === false) {
      echo "Ошибка при сохранении файла в каталог: $savePath\n";
      return false;
  }

  return $savePath;
} */

function downloadAndSaveFileBash($filenamePart, $fileUrl) {
    $savePath = '/var/www/p/' . $filenamePart . ".m3u8";
    $scriptPath = '/usr/local/bin/getm3u8.sh';

    /*    if (!file_exists($scriptPath) || !is_executable($scriptPath)) {
        echo "Скрипт $scriptPath не найден или не имеет прав на выполнение.\n";
        return false;
    }*/

//    $command = escapeshellcmd("$scriptPath '$fileUrl' '$savePath' > 2>&1 &");
    $command="bash $scriptPath '$fileUrl' '$savePath' > /dev/null 2>&1 &";
    exec($command, $output);
//print_r($output);


    // Продолжение выполнения кода
//    return $savePath;
}

function checkFileExistsold($url) {
    $headers = @get_headers($url);
  return is_array($headers) && strpos($headers[0], '200 OK');
}

function checkFileExists($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
  return $httpCode === 200;
}

function downloadAndSaveFile($filenamePart, $fileUrl) {

    global $link;
    $fileDownloaded = false;

    // -------------------------------------------------------------------------
    // 🔻 БЛОК: ПОИСК АККАУНТА ПО $filenamePart В БАЗЕ
    // -------------------------------------------------------------------------     
    // ⚠️ ОПАСНО! SQL-инъекция: $filenamePart вставляется напрямую в запрос!
    // Если $filenamePart = "'; DROP TABLE accounts; --", последствия катастрофические.
    // Нужно использовать $link->prepare() + bind_param!
    $query = "SELECT user, twin, iptvkey, plname, iptvactdate, iptvmonths, subdomain, plname, grpvariant,iptvplaylist,iptvsdom
              FROM accounts 
              WHERE iptvkey = '$filenamePart' OR plname = '$filenamePart'";

    // Выполняем запрос
    $result = $link->query($query);
    $row = $result->fetch_assoc();
    $twin      = $row['twin'] ?? null;          // ID «родительского» аккаунта (0 или NULL — нет twin)
    $iptvsdom = $row['iptvsdom'];
    $sdom = $row['subdomain'];
    $hasTwin = !in_array($twin, [null, 0, '0', ''], true); // strict comparison

    if(subd_extract($filenamePart) == false || !$hasTwin && $sdom =='')
    {
      $fileContent = file_get_contents($fileUrl);
      if ($fileContent !== false) {
          $fileDownloaded = true;
      }

      // 🔴 Если файл так и не скачался — выводим ошибку и завершаем работу
      if (!$fileDownloaded) {
          // ⚠️ echo в функции — плохо для API/CLI. Лучше error_log() или выброс исключения.
          echo "Ошибка: файл по URL $fileUrl не существует или не может быть скачан после $maxAttempts попыток.\n";
          return false;
      }
    }   
   
    // Проверяем: есть ли результат, и можем ли мы извлечь строку
    if ($result) {
        // Извлекаем данные аккаунта:
        $iptvkey   = $row['iptvkey'];      // уникальный ключ аккаунта
        $actdate   = $row['iptvactdate'];  // дата активации (ожидается timestamp или 'Y-m-d H:i:s')
        $months    = $row['iptvmonths'];   // строка вида "12:..." — количество месяцев активности
        $user = $row['user'];
        
        $plname = $row['plname'];
        $grpvariant = (int)$row['grpvariant'];
        $iptvplaylist = $row['iptvplaylist'];
        
        
        // -------------------------------------------------------------------------
        // 🔻 БЛОК: ПРОВЕРКА СТАТУСА АККАУНТА — «активен» или «не активен»?
        // -------------------------------------------------------------------------
        /*
         * Условие для прямого сохранения (без twin-логики):
         *   (twin == 0 ИЛИ twin IS NULL)      → нет родителя
         *   ИЛИ
         *   (аккаунт просрочен И twin != 0)    → есть родитель, но текущий аккаунт истёк
         * Как проверяется просроченность:
         *   addMonths($actdate, explode(":", $months)[0]) — получаем дату окончания
         *   <= time() — сравниваем с текущим timestamp'ом
         * ⚠️ Проблемы:
         *   - explode(":", $months)[0] может сломаться, если $months не содержит ':'
         *   - addMonths() — кастомная функция; неизвестно, как она работает (с timestamp или строкой?)
         *   - Нет валидации $actdate/$months → при ошибке — PHP Notice/Warning и некорректная логика
         */
	      
        if (
            (!$hasTwin) 
            || 
            (
                //(addMonths($actdate, explode(":", $months)[0])) <= time() 
(addMonths($actdate, (int)(explode(":", $months ?? '')[0]))) <= time()
                && !$hasTwin 
            )
        ) {

            // ✅ Сценарий 1: аккаунт НЕ активен → сохраняем файл напрямую в публичную папку
            // Формируем путь: /var/www/p/iptvkey.m3u8
            // ⚠️ Опасно: $filenamePart может содержать "../" → path traversal!
            $savePath = '/var/www/p/' . $filenamePart . ".m3u8";
            // Сохраняем содержимое в файл
            if($fileDownloaded)
            {
              $result = file_put_contents($savePath, $fileContent);
            // Проверяем результат: file_put_contents возвращает FALSE при ошибке (нет прав, диск переполнен и т.д.)
              if ($result === false) {
                  echo "Ошибка при сохранении файла в каталог: $savePath\n";
                  return false;
              }
              else if ($sdom == ''){
                subd_extract($filenamePart);
              }
            }
            else 
              cream3u8($savePath,$grpvariant, $plname, $iptvplaylist, $iptvsdom, $iptvkey, $sdom);
            // ✅ Успех: возвращаем путь к файлу (например, для логирования или дальнейшей обработки)
            return $savePath;

        } else {
            // ✅ Сценарий 2: аккаунт АКТИВЕН и имеет twin → нужна обработка через twin-файл

            // Сохраняем скачанный файл ВО ВРЕМЕННУЮ ПАПКУ
            // Это необходимо, потому что далее файл будет модифицирован (слияние с twin)
            $tempSavePath = '/tmp/' . $filenamePart . ".m3u8";
            if($fileDownloaded)
            {
              $result = file_put_contents($tempSavePath, $fileContent);
            
              if ($result === false) {
                  echo "Ошибка при сохранении файла в каталог: $tempSavePath\n";
                  return false;
              }
              else if ($sdom == ''){
                subd_extract($filenamePart);
              }
            }
            else 
              cream3u8($tempSavePath,$grpvariant, $plname, $iptvplaylist, $iptvsdom, $iptvkey, $sdom);
            // -------------------------------------------------------------------------
            // 🔻 БЛОК: ПОИСК TWIN-АККАУНТА ПО ID ($twin)
            // -------------------------------------------------------------------------
            // ⚠️ ОПАСНО! SQL-инъекция через $twin (если он пришёл извне)!
            // В текущем коде $twin берётся из БД — относительно безопасно, но лучше всё равно prepare()
            $query2 = "SELECT iptvkey, plname, twin, iptvurl FROM accounts WHERE id = $twin";
            $result2 = $link->query($query2);
            if ($result2 && $row2 = $result2->fetch_assoc()) {
                // $row2 — данные twin-аккаунта
                
                // Проверяем: у twin-аккаунта тоже есть свой twin? (цепочка: A → B → C)
                if ($row2['twin'] != 0 && !is_null($row2['twin'])) {
                    // ✅ Сценарий 2.1: twin сам имеет родителя → рекурсивная логика (но здесь только 1 уровень!)

                    // Получаем URL twin-файла (например, http://.../playlist.m3u8)
                    $twinFileUrl = $row2['iptvurl'];
                    $plname = $row2['plname'];
                    $iptvkey = $row2['iptvkey'];
                    $plfname = ($plname ?: $iptvkey) . '.m3u8';

                    // Флаг: скачан ли twin-файл
                    $twinFileDownloaded = false;

                    if(!file_exists("/var/www/p/".$plfname))
                    {
                    // 🔁 Повторные попытки скачивания twin-файла (до 5 раз)
                    // Здесь цикл НЕ закомментирован — используется $maxAttempts (но переменная не определена!)
                    // ⚠️ ОШИБКА: $maxAttempts = 5 закомментирована выше → будет Notice: Undefined variable!
                    // Нужно или раскомментировать $maxAttempts = 5;, или задать здесь.
                    $maxAttempts = 5; // ← явно задаём, чтобы избежать ошибки
                    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                        // Проверяем существование файла (кастомная функция, должна возвращать true/false)
                        if (checkFileExists($twinFileUrl)) {
                            $twinFileContent = file_get_contents($twinFileUrl);
                            if ($twinFileContent !== false) {
                                $twinFileDownloaded = true;
                                break; // выход при успехе
                            }
                        }
                        sleep(1); // пауза 1 сек (защита от флуда)
                    }
                  }
                  else
                      $twinFileContent=file_get_contents("/var/www/p/".$plfname);

                    if ($twinFileDownloaded || $twinFileContent != false) {
                        // ✅ Twin-файл успешно скачан → сохраняем его во временную папку
                        $twinTempPath = '/tmp/' . $row2['iptvkey'] . ".m3u8";
                        $twinResult = file_put_contents($twinTempPath, $twinFileContent);

                        if ($twinResult !== false) {
                            // ✅ Оба файла во /tmp/ — запускаем кастомную функцию rplLnk()
                            // Предполагаемое назначение rplLnk($src, $dst):
                            //   — читает $src и $dst
                            //   — заменяет ссылки (URL) в $dst на ссылки из $src (или наоборот)
                            //   — перезаписывает $dst модифицированным содержимым
                            $file1 = $twinTempPath;   // источник (twin-файл)
                            $file2 = $tempSavePath;   // цель (исходный файл)
                            rplLnk($file1, $file2);

                            // Удаляем временный twin-файл (флаг @ подавляет ошибку, если файла нет)
                            @unlink($twinTempPath);
                        } else {
                            echo "Ошибка при сохранении twin файла в каталог: $twinTempPath\n";
                            // ❗ Не возвращаем false — продолжаем (возможно, fallback ниже)
                        }
                    } else {
                        echo "Ошибка: файл twin по URL $twinFileUrl не может быть скачан после $maxAttempts попыток.\n";
                        // ❗ Не возвращаем false — продолжаем (возможно, fallback ниже)
                    }
                } else {
                    // ✅ Сценарий 2.2: twin НЕ имеет своего twin (цепочка закончилась)
                    // Используем ПОСТОЯННЫЙ файл twin-аккаунта из /var/www/p/
                    // Например: /var/www/p/plname.m3u8 или /var/www/p/iptvkey.m3u8

                    // Определяем имя twin-файла: сначала plname, если нет — iptvkey
                    $twinFilename = isset($row2['plname']) && $row2['plname'] 
                                  ? $row2['plname'] 
                                  : $row2['iptvkey'];

                    $file1 = '/var/www/p/' . $twinFilename . ".m3u8"; // исходный twin-файл (публичный)
                    $file2 = $tempSavePath;                           // временный исходный файл

                    // Применяем rplLnk() — модифицируем $file2 на основе $file1
                    rplLnk($file1, $file2);
                }

            } else {
                // 🔴 Ошибка: twin-аккаунт не найден
                echo "Ошибка: не удалось найти запись с id = $twin\n";
                // ❗ Закомментирован возврат — функция продолжит выполнение и удалит временный файл
                // Но дальше нет логики сохранения → вернётся NULL. Лучше явно вернуть false.
                // return false;
            }

        } // end else (аккаунт активен)

    } else {
        // 🔴 Ошибка: аккаунт не найден по $filenamePart
        echo "Запись не найдена или ошибка запроса для iptvkey = $filenamePart\n";
        return false;
    }

    // -------------------------------------------------------------------------
    // 🔻 ФИНАЛЬНЫЙ ШАГ: ОЧИСТКА ВРЕМЕННЫХ ФАЙЛОВ
    // -------------------------------------------------------------------------

    // Удаляем временный файл ($tempSavePath), если он существует
    // Флаг @ подавляет предупреждение, если файла нет (например, при ошибке выше)
    @unlink($tempSavePath);

    // ⚠️ ВАЖНО: в ветке «аккаунт активен» НЕТ явного return!
    // Если всё прошло успешно (включая rplLnk), функция завершится здесь и вернёт NULL.
    // Это может нарушить контракт — ожидали путь или false.
    //
    // ✅ Рекомендация: добавить в конец:
    //   return $tempSavePath;   // но файл уже удалён!
    //   или
    //   return true;            // если задача — просто «обработать», а не вернуть путь
    //
    // В текущей реализации:
    //   - при прямом сохранении → return $savePath (OK)
    //   - при twin-логике → функция завершается без return → NULL
    //
    // Это потенциальный баг! Проверьте, как использует результат вызывающий код.
}

function cream3u8($file, $grpvariant, $plname, $iptvplaylist, $iptvsdom, $iptvkey, $sdom, $token = '')
{
  global $link;

  // --- Валидация и подготовка входных данных ---
  $playlist_id = (int) $grpvariant;
  $playlist_name = trim((string)($plname ?? ""));
  $grpid_list = trim($iptvplaylist);
  $domain = trim($iptvsdom); // Здесь ожидается IP:PORT (для нового) или домен (для старого)
  $key = trim($iptvkey);
  $subdomain = trim($sdom);
  $token = trim($token); // Очищаем токен

  // --- Начало формирования M3U8 ---
  $output = "#EXTM3U\n";

  // === Выборка групп (оставляем без изменений) ===
  $groups = [];

  if ($grpid_list === '' || $grpid_list === '0') {
    $sql = "SELECT name, channel_ids 
                FROM channel_groups_list 
                WHERE playlist_id = ? 
                  AND channel_ids != '' 
                  AND channel_ids IS NOT NULL
                ORDER BY id ASC";

    $stmt = $link->prepare($sql);
    if (!$stmt) {
      error_log("SQL prepare error (groups all): " . $link->error);
      return false;
    }
    $stmt->bind_param("i", $playlist_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $groups = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
  } else {
    $grpids = array_filter(array_map('intval', explode(',', $grpid_list)), fn($x) => $x > 0);
    if (empty($grpids)) {
      error_log("cream3u8: invalid grpid_list: " . $grpid_list);
      return false;
    }

    $placeholders = str_repeat('?,', count($grpids) - 1) . '?';
    $sql = "SELECT name, channel_ids 
                FROM channel_groups_list 
                WHERE playlist_id = ? 
                  AND grpid IN ($placeholders)
                  AND channel_ids != '' 
                  AND channel_ids IS NOT NULL
                ORDER BY id ASC";

    $types = str_repeat('i', count($grpids) + 1); 
    $params = array_merge([$playlist_id], $grpids);

    $stmt = $link->prepare($sql);
    if (!$stmt) {
      error_log("SQL prepare error (groups by ID): " . $link->error);
      return false;
    }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $groups = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
  }

  // === Формирование плейлиста ===
  foreach ($groups as $group) {
    $groupName = htmlspecialchars($group['name'], ENT_XML1, 'UTF-8');
    $channel_ids_str = $group['channel_ids'];

    if (empty($channel_ids_str)) continue;

    $ids = array_filter(array_map('trim', explode(',', $channel_ids_str)), fn($x) => is_numeric($x) && (int) $x > 0);
    $ids = array_map('intval', $ids);

    if (empty($ids)) continue;

    $idPlaceholders = str_repeat('?,', count($ids) - 1) . '?';
    $sql_ch = "SELECT id, name, rec 
                   FROM channels 
                   WHERE id IN ($idPlaceholders) 
                   ORDER BY FIELD(id, " . implode(',', $ids) . ")";

    $stmt_ch = $link->prepare($sql_ch);
    if (!$stmt_ch) {
      error_log("SQL prepare (channels): " . $link->error);
      continue;
    }

    $types_ch = str_repeat('i', count($ids));
    $stmt_ch->bind_param($types_ch, ...$ids);
    $stmt_ch->execute();
    $channels = $stmt_ch->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_ch->close();

    foreach ($channels as $channel) {
      $id = (int) $channel['id'];
      $name = htmlspecialchars($channel['name'], ENT_XML1, 'UTF-8');
      $rec_days = (int) $channel['rec'];
      $tvg_rec = $rec_days > 0 ? " tvg-rec=\"{$rec_days}\"" : '';

      // === ЛОГИКА ВЫБОРА URL ===
      if (!empty($token)) {
        // Если есть токен -> Новый формат
        // http://45.9.73.98:8123/705/index.m3u8?token=c7hwejwejedd
        $url = "http://{$domain}/{$id}/index.m3u8?token={$token}";
      } else {
        // Если токена нет -> Старый формат
        // http://example.s1/iptv/key/705/index.m3u8
        $url = "http://{$domain}.{$subdomain}/iptv/{$key}/{$id}/index.m3u8";
      }

      $output .= "#EXTINF:-1{$tvg_rec},{$name}\n";
      $output .= "#EXTGRP:{$groupName}\n";
      $output .= $url . "\n";
    }
  }
  file_put_contents($file, $output);
  return true;
}

class TinyRedis {
    private $fp;

    public function connect($host, $port, $timeout = 5) {
        $this->fp = fsockopen($host, $port, $errno, $errstr, $timeout);
        if (!$this->fp) {
            throw new Exception("Не удалось подключиться: $errstr ($errno)");
        }
        stream_set_timeout($this->fp, $timeout);
    }

    public function execute($args) {
        // Формируем команду RESP
        $cmd = '*' . count($args) . "\r\n";
        foreach ($args as $arg) {
            $cmd .= '$' . strlen($arg) . "\r\n" . $arg . "\r\n";
        }

        fwrite($this->fp, $cmd);
        return $this->readResponse();
    }

    private function readResponse() {
        $line = fgets($this->fp);
        if (!$line) return false;

        $type = $line[0];
        $payload = trim(substr($line, 1));

        switch ($type) {
            case '+': return $payload; // Строка
            case '-': throw new Exception("Redis Error: $payload"); // Ошибка
            case ':': return (int)$payload; // Число
            case '$': // Bulk String
                if ($payload == '-1') return null;
                $len = (int)$payload;
                $data = "";
                $received = 0;
                while ($received < $len) {
                    $chunk = fread($this->fp, $len - $received);
                    $data .= $chunk;
                    $received += strlen($chunk);
                }
                fread($this->fp, 2); 
                return $data;
            case '*': // Массив
                $count = (int)$payload;
                $data = [];
                for ($i = 0; $i < $count; $i++) {
                    $data[] = $this->readResponse();
                }
                return $data;
            default:
                throw new Exception("Неизвестный ответ протокола: $line");
        }
    }

    public function close() {
        if ($this->fp) fclose($this->fp);
    }

    // === ДОБАВЛЕННЫЕ МЕТОДЫ (ОБЕРТКИ) ===

    /**
     * Аналог $redis->sMembers($key)
     * Возвращает все элементы множества (Set)
     */
    public function sMembers($key) {
        return $this->execute(['SMEMBERS', $key]);
    }

    /**
     * Аналог $redis->zCard($key)
     * Возвращает количество элементов в сортированном множестве (ZSet)
     */
    public function zCard($key) {
        return $this->execute(['ZCARD', $key]);
    }

    /**
     * Аналог $redis->pfCount($key)
     * Возвращает приблизительное количество уникальных элементов (HyperLogLog)
     */
    public function pfCount($key) {
        return $this->execute(['PFCOUNT', $key]);
    }
}

function subd_extract($plname)
{
global $link;

  $file='/var/www/p/' . $plname . ".m3u8";
  if(file_exists($file))
  {
	    // Читаем файл, игнорируя пустые строки и символы новой строки
	  $lines1 = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

	  // Проверка: минимум 4 строки (индексация с 0 → нужна строка [3])
	  if (count($lines1) < 4) {
	      echo "File 1 does not have 4 lines.\n";
	      return false; // или throw new Exception
	  }

	  // Берём 4-ю строку (индекс 3)
	  $line1 = $lines1[3];

	  // Проверяем, что строка начинается с 'http://'
	  if (strpos($line1, 'http://') !== 0) {
	      echo "Invalid URL in the 4th line of the first file.\n";
	      return false;
	  }

	  // Извлекаем хост (включая поддомен и домен, например: 443e7c85.tvclub.xyz)
	  // Используем более надёжный способ — parse_url()
	  $parsed = parse_url($line1);
	  if (!$parsed || empty($parsed['host'])) {
	      echo "Could not parse host from URL: $line1\n";
	      return false;
	  }

	  $fullHost = $parsed['host']; // например: "443e7c85.tvclub.xyz"

	  // 🔍 Извлекаем именно "субдомен + домен", убирая первый (случайный) префикс
	  // Пример: 443e7c85.tvclub.xyz → tvclub.xyz
	  // Алгоритм: если host содержит ≥2 точки → убираем первую часть до точки
	  $hostParts = explode('.', $fullHost);
	  if (count($hostParts) >= 3) {
	      // Берём всё, кроме первого элемента (например, ['443e7c85', 'tvclub', 'xyz'] → 'tvclub.xyz')
	      array_shift($hostParts); // удаляем первый элемент (случайный префикс)
	      $subdomain = implode('.', $hostParts);
	  } elseif (count($hostParts) === 2) {
	      // Уже без префикса: например, "tvclub.xyz" → оставляем как есть
	      $subdomain = $fullHost;
	  } else {
	      // Некорректный host (например, "localhost")
	      echo "Host format not recognized: $fullHost\n";
	      return false;
	  }

	  // Подготавливаем запрос с параметризацией (защита от SQL-инъекций!)
	  $stmt = $link->prepare("UPDATE accounts SET subdomain = ? WHERE plname = ?");
	  if (!$stmt) {
	      error_log("DB prepare failed: " . $link->error);
	      return false;
	  }

	  // Привязываем параметры: s = string, s = string
	  $stmt->bind_param("ss", $subdomain, $plname);

	  // Выполняем
	  if (!$stmt->execute()) {
	      error_log("DB update failed: " . $stmt->error);
	      $stmt->close();
	      return false;
	  }

	  // ✅ Успех
	  $stmt->close();
	  //echo "✅ Subdomain '$subdomain' saved for user '$user'.\n";
	  return true;
  }
  else
    return false;
}

function rplLnk($file1, $file2) {
    if (!file_exists($file1)) {
        echo "File 1, $file1 not found!\n";
        return;
    }

    if (!file_exists($file2)) {
        echo "File 2 not found!\n";
        return;
    }

    $lines1 = file($file1, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (count($lines1) < 4) {
        echo "File 1 does not have 4 lines.\n";
        return;
    }
    $line1 = $lines1[3];

    if (strpos($line1, 'http://') !== 0) {
        echo "Invalid URL in the 4th line of the first file.\n";
        return;
    }

    preg_match('|(http://[^/]+/[^/]+/[^/]+).*|', $line1, $matches1);
    $searchStr = $matches1[1] ?? '';

    $lines2 = file($file2, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (count($lines2) < 4) {
        echo "File 2 does not have 4 lines.\n";
        return;
    }
    $line2 = $lines2[3];

    if (strpos($line2, 'http://') !== 0) {
        echo "Invalid URL in the 4th line of the second file.\n";
        return;
    }
    preg_match('|(http://[^/]+/[^/]+/[^/]+).*|', $line2, $matches2);
    $rStr = $matches2[1] ?? '';

    if (empty($rStr)) {
        echo "The extracted string from the content of the second file is empty.\n";
        return;
    }

    $fileContent2 = file_get_contents($file2);
    $updatedContent = str_replace($rStr, $searchStr, $fileContent2);

    $tempFile = tempnam('/tmp', 'tmpfile_');
    if ($tempFile === FALSE) {
        echo "Failed to create a temporary file.\n";
        return;
    }

    $success = true;

    if (file_put_contents($tempFile, $updatedContent) === FALSE) {
        echo "Failed to write to the temporary file.\n";
        $success = false;
    }

    $fDest = '/var/www/p/' . basename($file2);

    if ($success && !copy($tempFile, $fDest)) {
        echo "Failed to copy file to $fDest\n";
        $success = false;
    }

    if (!unlink($tempFile)) {
        echo "Failed to delete the temporary file.\n";
    }

    if ($success) {
 	return 1;
    }
  else
	return 0;
}

function ilookSetCDN($l,$cdn)
{
 $headers['Origin']='https://zedom.net';
 $headers['Accept']='*/*';
 $headers['Referer']='https://zedom.net/partner/manage/'.$l;
 $headers['Content-Type']='application/x-www-form-urlencoded; charset=UTF-8';
 $headers["x-requested-with"]="XMLHttpRequest";
$headers['Priority'] = 'u=1, i';
$headers['Sec-CH-UA'] = '"Google Chrome";v="142", "Chromium";v="142", "Not/A)Brand";v="24"';
$headers['Sec-CH-UA-Arch'] = '"x86"';
$headers['Sec-CH-UA-Bitness'] = '"64"';
$headers['Sec-CH-UA-Full-Version'] = '"142.0.0.0"';
$headers['Sec-CH-UA-Full-Version-List'] = '"Google Chrome";v="142.0.0.0", "Chromium";v="142.0.0.0", "Not/A)Brand";v="24.0.0.0"';
$headers['Sec-CH-UA-Mobile'] = '?0';
$headers['Sec-CH-UA-Model'] = '""';
$headers['Sec-CH-UA-Platform'] = '"Linux"';
$headers['Sec-CH-UA-Platform-Version'] = '"10.0.0"';
$headers['Sec-Fetch-Dest'] = 'empty';
$headers['Sec-Fetch-Mode'] = 'cors';
$headers['Sec-Fetch-Site'] = 'same-origin';
unset($headers['Upgrade-Insecure-Requests']);
 $url = "https://zedom.net/ajax/partner_set_cdn";
 $data = "ref_name=".$l."&cdn_id=".$cdn;
 $res=mkReq($url, $data, $headers);
return $res['response'];
}

function ilookSetDom($l,$dom)
{
  $url='https://zedom.net/ajax/partner_user_server';
  $headers['Referer']='https://zedom.net/partner/manage/'.$l;
  $headers['Accept']='*/*';
  $headers['Accept-Language']='ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7';
  $headers['Content-Type']='application/x-www-form-urlencoded; charset=UTF-8';
  $headers['sec-ch-ua']='"Not_A Brand";v="99", "Google Chrome";v="109", "Chromium";v="109"';
  $headers['sec-ch-ua-mobile']='?0';
  $headers['sec-ch-ua-platform']='"Linux"';
  $headers['Sec-Fetch-Dest']='empty';
  $headers['Sec-Fetch-Mode']='cors';
  $headers['Sec-Fetch-Site']='same-origin';
  $headers['X-Requested-With']='XMLHttpRequest';
  $data = "ref_name=".$l."&name=".$dom;
  $res=mkReq($url, $data, $headers);
 return $res['response'];
}

function ilookResetKey($l)
{
  $url='https://zedom.net/ajax/partner_reset_key';
  $headers['Referer']='https://zedom.net/partner/manage/'.$l;
  $headers['Accept']='*/*';
  $headers['Accept-Language']='ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7';
  $headers['Content-Type']='application/x-www-form-urlencoded; charset=UTF-8';
  $headers['sec-ch-ua']='"Not_A Brand";v="99", "Google Chrome";v="109", "Chromium";v="109"';
  $headers['sec-ch-ua-mobile']='?0';
  $headers['sec-ch-ua-platform']='"Linux"';
  $headers['Sec-Fetch-Dest']='empty';
  $headers['Sec-Fetch-Mode']='cors';
  $headers['Sec-Fetch-Site']='same-origin';
  $headers['X-Requested-With']='XMLHttpRequest';
  $data = "ref_name=".$l;
  $res=mkReq($url, $data, $headers);
 return $res['response'];
}

function ilookChkacc($logintochk)
{
  $url='https://zedom.net/partner/manage/'.$logintochk;
  $headers['Accept']='text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.9';
  $headers['Accept-Language']='ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7';
  $headers['Connection']='keep-alive';
  $headers['Referer']='https://zedom.net/partner/by_create';
$headers['Priority'] = 'u=0, i';
$headers['Sec-CH-UA'] = '"Google Chrome";v="142", "Chromium";v="142", "Not/A)Brand";v="24"';
$headers['Sec-CH-UA-Arch'] = '"x86"';
$headers['Sec-CH-UA-Bitness'] = '"64"';
$headers['Sec-CH-UA-Full-Version'] = '"142.0.0.0"';
$headers['Sec-CH-UA-Full-Version-List'] = '"Google Chrome";v="142.0.0.0", "Chromium";v="142.0.0.0", "Not/A)Brand";v="24.0.0.0"';
$headers['Sec-CH-UA-Mobile'] = '?0';
$headers['Sec-CH-UA-Model'] = '""';
$headers['Sec-CH-UA-Platform'] = '"Linux"';
$headers['Sec-CH-UA-Platform-Version'] = '"10.0.0"';
$headers['Sec-Fetch-Dest'] = 'document';
$headers['Sec-Fetch-Mode'] = 'navigate';
$headers['Sec-Fetch-Site'] = 'same-origin';
$headers['Sec-Fetch-User'] = '?1';
$headers['Upgrade-Insecure-Requests'] = '1';
  $res=mkReq($url,'', $headers);
  return $res['response'];
}

function ilookSetGroups($l,$gr,$pl)
{
  global $link;
  $headers['Origin']='https://zedom.net';
  $headers['Referer']='https://zedom.net/partner/manage/'.$l;
  $headers['Accept']='*/*';
  $headers['accept-language']='ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7';
  $headers['Content-Type']='application/x-www-form-urlencoded; charset=UTF-8';
$headers['Origin'] = 'https://zedom.net';
$headers['Priority'] = 'u=1, i';
$headers['Sec-CH-UA'] = '"Google Chrome";v="142", "Chromium";v="142", "Not/A)Brand";v="24"';
$headers['Sec-CH-UA-Arch'] = '"x86"';
$headers['Sec-CH-UA-Bitness'] = '"64"';
$headers['Sec-CH-UA-Full-Version'] = '"142.0.0.0"';
$headers['Sec-CH-UA-Full-Version-List'] = '"Google Chrome";v="142.0.0.0", "Chromium";v="137.0.7151.103", "Not/A)Brand";v="24.0.0.0"';
$headers['Sec-CH-UA-Mobile'] = '?0';
$headers['Sec-CH-UA-Model'] = '""';
$headers['Sec-CH-UA-Platform'] = '"Linux"';
$headers['Sec-CH-UA-Platform-Version'] = '"10.0.0"';
$headers['Sec-Fetch-Dest'] = 'empty';
$headers['Sec-Fetch-Mode'] = 'cors';
$headers['Sec-Fetch-Site'] = 'same-origin';
$headers['X-Requested-With'] = 'XMLHttpRequest';
  unset($headers['Upgrade-Insecure-Requests']);
  $url = 'https://zedom.net/ajax/partner_ref_groups';
$rq='ref_name='.$l.'&ref_groups=['.$gr.']&grp_variant='.$pl; 
$res=mkReq($url,$rq,$headers);
return $res['response'];
}

function ilookGetAccs()
{
$headers['Accept'] = 'application/json, text/javascript, */*; q=0.01';
$headers['Accept-Language'] = 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7';
$headers['Content-Type'] = 'application/x-www-form-urlencoded; charset=UTF-8';
$headers['Origin'] = 'https://zedom.net';
$headers['Priority'] = 'u=1, i';
$headers['Referer'] = 'https://zedom.net/partner/by_create';
$headers['Sec-CH-UA'] = '"Google Chrome";v="137", "Chromium";v="137", "Not/A)Brand";v="24"';
$headers['Sec-CH-UA-Arch'] = '"x86"';
$headers['Sec-CH-UA-Bitness'] = '"64"';
$headers['Sec-CH-UA-Full-Version'] = '"142.0.0.0"';
$headers['Sec-CH-UA-Full-Version-List'] = '"Google Chrome";v="142.0.0.0", "Chromium";v="142.0.0.0", "Not/A)Brand";v="24.0.0.0"';
$headers['Sec-CH-UA-Mobile'] = '?0';
$headers['Sec-CH-UA-Model'] = '""';
$headers['Sec-CH-UA-Platform'] = '"Linux"';
$headers['Sec-CH-UA-Platform-Version'] = '"6.1.0"';
$headers['Sec-Fetch-Dest'] = 'empty';
$headers['Sec-Fetch-Mode'] = 'cors';
$headers['Sec-Fetch-Site'] = 'same-origin';
$headers['X-Requested-With'] = 'XMLHttpRequest';
unset($headers['Upgrade-Insecure-Requests']);
$url = 'https://zedom.net/ajax/partner_refs_create';
$data = 'draw=2&columns%5B0%5D%5Bdata%5D=refLinkUName&columns%5B0%5D%5Bname%5D=&columns%5B0%5D%5Bsearchable%5D=true&columns%5B0%5D%5Borderable%5D=true&columns%5B0%5D%5Bsearch%5D%5Bvalue%5D=&columns%5B0%5D%5Bsearch%5D%5Bregex%5D=false&columns%5B1%5D%5Bdata%5D=access_key&columns%5B1%5D%5Bname%5D=&columns%5B1%5D%5Bsearchable%5D=true&columns%5B1%5D%5Borderable%5D=true&columns%5B1%5D%5Bsearch%5D%5Bvalue%5D=&columns%5B1%5D%5Bsearch%5D%5Bregex%5D=false&columns%5B2%5D%5Bdata%5D=bundle_state&columns%5B2%5D%5Bname%5D=&columns%5B2%5D%5Bsearchable%5D=true&columns%5B2%5D%5Borderable%5D=false&columns%5B2%5D%5Bsearch%5D%5Bvalue%5D=&columns%5B2%5D%5Bsearch%5D%5Bregex%5D=false&columns%5B3%5D%5Bdata%5D=refBalance&columns%5B3%5D%5Bname%5D=&columns%5B3%5D%5Bsearchable%5D=true&columns%5B3%5D%5Borderable%5D=false&columns%5B3%5D%5Bsearch%5D%5Bvalue%5D=&columns%5B3%5D%5Bsearch%5D%5Bregex%5D=false&columns%5B4%5D%5Bdata%5D=refRemark&columns%5B4%5D%5Bname%5D=&columns%5B4%5D%5Bsearchable%5D=true&columns%5B4%5D%5Borderable%5D=false&columns%5B4%5D%5Bsearch%5D%5Bvalue%5D=&columns%5B4%5D%5Bsearch%5D%5Bregex%5D=false&order%5B0%5D%5Bcolumn%5D=0&order%5B0%5D%5Bdir%5D=asc&start=0&length=650&search%5Bvalue%5D=&search%5Bregex%5D=false';
//  $data = 'draw=1&columns%5B0%5D%5Bdata%5D=refLinkUName&columns%5B0%5D%5Bname%5D=&columns%5B0%5D%5Bsearchable%5D=true&columns%5B0%5D%5Borderable%5D=true&columns%5B0%5D%5Bsearch%5D%5Bvalue%5D=&columns%5B0%5D%5Bsearch%5D%5Bregex%5D=false&columns%5B1%5D%5Bdata%5D=access_key&columns%5B1%5D%5Bname%5D=&columns%5B1%5D%5Bsearchable%5D=true&columns%5B1%5D%5Borderable%5D=true&columns%5B1%5D%5Bsearch%5D%5Bvalue%5D=&columns%5B1%5D%5Bsearch%5D%5Bregex%5D=false&columns%5B2%5D%5Bdata%5D=bundle_state&columns%5B2%5D%5Bname%5D=&columns%5B2%5D%5Bsearchable%5D=true&columns%5B2%5D%5Borderable%5D=false&columns%5B2%5D%5Bsearch%5D%5Bvalue%5D=&columns%5B2%5D%5Bsearch%5D%5Bregex%5D=false&columns%5B3%5D%5Bdata%5D=refBalance&columns%5B3%5D%5Bname%5D=&columns%5B3%5D%5Bsearchable%5D=true&columns%5B3%5D%5Borderable%5D=false&columns%5B3%5D%5Bsearch%5D%5Bvalue%5D=&columns%5B3%5D%5Bsearch%5D%5Bregex%5D=false&columns%5B4%5D%5Bdata%5D=refRemark&columns%5B4%5D%5Bname%5D=&columns%5B4%5D%5Bsearchable%5D=true&columns%5B4%5D%5Borderable%5D=false&columns%5B4%5D%5Bsearch%5D%5Bvalue%5D=&columns%5B4%5D%5Bsearch%5D%5Bregex%5D=false&order%5B0%5D%5Bcolumn%5D=0&order%5B0%5D%5Bdir%5D=asc&start=0&length=20&search%5Bvalue%5D=&search%5Bregex%5D=false';
  $res=mkReq($url,$data, $headers);
  return $res['response'];
}

function ilookChkAuth()
{
  $headers['Origin']='https://zedom.net';
  $headers['Referer']='https://zedom.net/cabinet';
  $url = 'https://zedom.net/ajax/check_auth';
  $headers['Accept']='*/*';
  $headers['Accept-Language']='ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7';
  $headers['X-Requested-With']='XMLHttpRequest';
  $headers['Sec-Fetch-Dest']='empty';
  $headers['Sec-Fetch-Mode']='cors';
  unset($headers['Upgrade-Insecure-Requests']);
  $headers['priority'] = 'u=1, i';
  $headers['Connection']='keep-alive';
  $headers['X-Requested-With']='XMLHttpRequest';
  $headers['Sec-Fetch-Site']='same-origin';
  $headers['Sec-Fetch-User']='?1';
  $headers['sec-ch-ua-mobile']='?0';
  $headers['sec-ch-ua-platform']='"Linux"';
$headers['Sec-CH-UA'] = '"Google Chrome";v="137", "Chromium";v="137", "Not/A)Brand";v="24"';
$headers['Sec-CH-UA-Arch'] = '"x86"';
$headers['Sec-CH-UA-Bitness'] = '"64"';
$headers['Sec-CH-UA-Full-Version'] = '"142.0.0.0"';
$headers['Sec-CH-UA-Full-Version-List'] = '"Google Chrome";v="142.0.0.0", "Chromium";v="142.0.0.0", "Not/A)Brand";v="24.0.0.0"';
$headers['Sec-CH-UA-Mobile'] = '?0';
$headers['Sec-CH-UA-Model'] = '""';
$headers['Sec-CH-UA-Platform-Version'] = '"10.0.0"';
  $data = ' ';
  $res=mkReq($url,$data, $headers);
  return $res['response'];
}

function ilookToggleAuto($l,$switch)
{
  $url = 'https://zedom.net/ajax/partner_toggle_auto';
    unset($headers['Upgrade-Insecure-Requests']);
    $headers['Accept']='*/*';
    $headers['Accept-Language']='ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7';
    $headers['Content-Type']='application/x-www-form-urlencoded; charset=UTF-8';
    $headers['X-Requested-With']='XMLHttpRequest';
    $headers['Referer']="https://zedom.net/partner/manage/".$l;
$headers['Origin'] = 'https://zedom.net';
$headers['Priority'] = 'u=1, i';
$headers['Sec-CH-UA'] = '"Google Chrome";v="137", "Chromium";v="137", "Not/A)Brand";v="24"';
$headers['Sec-CH-UA-Arch'] = '"x86"';
$headers['Sec-CH-UA-Bitness'] = '"64"';
$headers['Sec-CH-UA-Full-Version'] = '"142.0.0.0"';
$headers['Sec-CH-UA-Full-Version-List'] = '"Google Chrome";v="142.0.0.0", "Chromium";v="142.0.0.0", "Not/A)Brand";v="24.0.0.0"';
$headers['Sec-CH-UA-Mobile'] = '?0';
$headers['Sec-CH-UA-Model'] = '""';
$headers['Sec-CH-UA-Platform'] = '"Linux"';
$headers['Sec-CH-UA-Platform-Version'] = '"10.0.0"';
$headers['Sec-Fetch-Dest'] = 'empty';
$headers['Sec-Fetch-Mode'] = 'cors';
$headers['Sec-Fetch-Site'] = 'same-origin';
$headers['X-Requested-With'] = 'XMLHttpRequest';
    $switch=($switch) ? "on" : "off";
    $data = 'ref_name='.$l.'&set_state='.$switch;
    $res=mkReq($url,$data, $headers);
  return $res['response'];
}

function ipToLong($ip) {
    return sprintf('%u', ip2long($ip));
}

function provFromip($ip)
{
global $link;
$prov='';
$ipLong = ipToLong($ip);
$query = "SELECT p.name AS provider_name 
          FROM ip_ranges r 
          JOIN providers p ON r.provider_id = p.id 
          WHERE ? BETWEEN r.ip_start AND r.ip_end";
$stmt = $link->prepare($query);
$stmt->bind_param("i", $ipLong);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
//    echo "Провайдер: " . $row['provider_name'];
$prov=$row['provider_name'];
} else {
    // Шаг 2: Если IP не найден, выполняем whois-запрос
    $whoisOutput = shell_exec("whois $ip");

    // Шаг 2.1: Попробуем найти hostname
    $hostname = null;
    if (preg_match('/netname:\s*(.+)/i', $whoisOutput ?? '', $matches)) {
        $hostname = trim($matches[1]);
    }

    // Загружаем всех провайдеров из базы
    $query = "SELECT id, name FROM providers";
    $result = $link->query($query);
    $providersFromDb = [];
    while ($row = $result->fetch_assoc()) {
        $providersFromDb[$row['id']] = $row['name'];
    }

    // Если hostname совпадает с одним из провайдеров
    $foundProviderId = null;
    $foundProviderName = null;
    if ($hostname) {
        foreach ($providersFromDb as $id => $provider) {
            if (stripos($hostname, $provider) !== false) {
                $foundProviderId = $id;
                $foundProviderName = $provider;
                break;
            }
        }
    }

    // Если hostname не дал результата, ищем в выводе whois
    if (!$foundProviderId) {
        foreach ($providersFromDb as $id => $provider) {
            if (stripos($whoisOutput ?? "", $provider) !== false) {
                $foundProviderId = $id;
                $foundProviderName = $provider;
                break;
            }
        }
    }

    if ($foundProviderId) {
//        echo "Провайдер найден через hostname или whois: $foundProviderName";

        // Пример извлечения диапазона IP из whois
        if (preg_match('/inetnum:\s*([\d.]+)\s*-\s*([\d.]+)/i', $whoisOutput ?? '', $matches)) {
            $ipStart = ipToLong($matches[1]);
            $ipEnd = ipToLong($matches[2]);

            // Добавляем диапазон в таблицу ip_ranges
            $insertQuery = "INSERT INTO ip_ranges (provider_id, ip_start, ip_end) VALUES (?, ?, ?)";
            $stmt = $link->prepare($insertQuery);
            $stmt->bind_param("iii", $foundProviderId, $ipStart, $ipEnd);
            $stmt->execute();
	    $prov=$foundProviderName;
//            echo " Диапазон ($matches[1] - $matches[2]) добавлен в базу.";
        }
    } else {
        // Шаг 3: Если провайдер не найден, извлекаем примерное название
        if (preg_match('/org-name:\s*(.+)/i', $whoisOutput ?? '', $matches)) {
            $approxProvider = trim($matches[1]);

//            echo "Примерное название провайдера: $approxProvider";
	    $prov=$approxProvider;
            // Проверяем, есть ли уже провайдер с таким названием
            $query = "SELECT id FROM providers WHERE name = ?";
            $stmt = $link->prepare($query);
            $stmt->bind_param("s", $approxProvider);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($row = $result->fetch_assoc()) {
                $foundProviderId = $row['id'];
            } else {
                // Добавляем нового провайдера в таблицу providers
                $insertQuery = "INSERT INTO providers (name) VALUES (?)";
                $stmt = $link->prepare($insertQuery);
                $stmt->bind_param("s", $approxProvider);
                $stmt->execute();
                $foundProviderId = $link->insert_id;
            }

            // Пример извлечения диапазона IP из whois
            if (preg_match('/inetnum:\s*([\d.]+)\s*-\s*([\d.]+)/i', $whoisOutput ?? '', $matches)) {
                $ipStart = ipToLong($matches[1]);
                $ipEnd = ipToLong($matches[2]);

                // Добавляем диапазон в таблицу ip_ranges
                $insertQuery = "INSERT INTO ip_ranges (provider_id, ip_start, ip_end) VALUES (?, ?, ?)";
                $stmt = $link->prepare($insertQuery);
                $stmt->bind_param("iii", $foundProviderId, $ipStart, $ipEnd);
                $stmt->execute();

//                echo " Диапазон ($matches[1] - $matches[2]) добавлен в базу с именем провайдера: $approxProvider.";
            } else {
//                echo " Не удалось определить диапазон IP.";
            }
        } else {
//            echo " Не удалось определить провайдера.";
        }
    }
}
return $prov;
}

function getElbyId($html,$el)
{
    $sstr='id="'.$el.'"';
    $startPos = strpos($html,$sstr);
if ($startPos !== false) {
    $startPos = strpos($html, 'value="', $startPos) + 7;
    $endPos = strpos($html, '"', $startPos);
    $valueAttribute = substr($html, $startPos, $endPos - $startPos);
    return $valueAttribute;
} else {
    return 0;
}
}

function extractTextOld($html, $elementId) {
    $escapedElementId = preg_quote($elementId, '/');
    $pattern = "/<(div|p)\s+[^>]*id\s*=\s*[\"']{$escapedElementId}[\"'][^>]*>(.*?)<\/(div|p)>/is";
    preg_match($pattern, $html, $matches);

    if (!empty($matches[2])) {
        return strip_tags($matches[2]);
    } else {
        return 0;
    }
}

function extractText($html, $elementId) {
    $escapedElementId = preg_quote($elementId, '/');
    $pattern = "/<(div|p|span)\s+[^>]*(id|class)\s*=\s*[\"']{$escapedElementId}[\"'][^>]*>(.*?)<\/(div|p|span)>/is";
    preg_match($pattern, $html, $matches);

    if (!empty($matches[3])) {
        return strip_tags($matches[3]);
    } else {
        return 0;
    }
}
function getInterestRate($numberOfAccounts) {
  global $link;
  $query = "SELECT rate 
                FROM interest_rates 
                WHERE min_accounts <= $numberOfAccounts
                AND (max_accounts >= $numberOfAccounts OR max_accounts IS NULL)
                ORDER BY min_accounts DESC 
                LIMIT 1";
      $r = $link->query($query);
      $result = $r->fetch_assoc();
      return $result ? (float)$result['rate'] : 0;
  }

function isMobileDevice() {
    $userAgent = strtolower($_SERVER['HTTP_USER_AGENT']);
    $mobileKeywords = ['mobile','touch','android', 'iphone', 'ipad', 'ipod', 'blackberry', 'windows phone'];
    
    foreach ($mobileKeywords as $keyword) {
        if (strpos($userAgent, $keyword) !== false) {
            return true;
        }
    }
    return false;
}

function addMonths($unixTimestamp, $monthsToAdd) {
  $date = getdate($unixTimestamp);
  $newMonth = $date['mon'] + $monthsToAdd;
  $newYear = $date['year'] + intdiv($newMonth - 1, 12);
  $newMonth = (($newMonth - 1) % 12) + 1;
  $newDay = min($date['mday'], cal_days_in_month(CAL_GREGORIAN, $newMonth, $newYear));
  return mktime($date['hours'], $date['minutes'], $date['seconds'], $newMonth, $newDay, $newYear);
}

function tzDateCorr($unixtime,$client_timezone_offset)
{
$server_timezone = date_default_timezone_get();  // Получаем временную зону сервера (например, Europe/Moscow)
$server_datetime = new DateTime("now", new DateTimeZone($server_timezone));  // Текущая дата/время сервера
$server_timezone_offset = $server_datetime->getOffset() / 60;  // Смещение сервера в минутах относительно UTC
logQuery("server_timezone_offset - {$server_timezone_offset}");
$client_timezone_offset_seconds = $client_timezone_offset * 60;  // Сдвиг клиента в секундах
$server_timezone_offset_seconds = $server_timezone_offset * 60;  // Сдвиг сервера в секундах

// Корректируем timestamp с учетом разницы между сервером и клиентом
return $adjusted_timestamp = $unixtime - $client_timezone_offset_seconds + $server_timezone_offset_seconds;
}

function tzDate(int $unixTimeFromDb,string $clientTimezone = 'UTC', 
bool $fullYear = false,  string $customFormat = null): string {
  $date = new DateTime("@$unixTimeFromDb");
  $date->modify(($clientTimezone * -60) . ' seconds');

  $format = $customFormat ?? ($fullYear ? 'd.m.Y H:i' : 'd.m.y H:i');
  return $date->format($format);
}

function connectToDB() {
   global $link, $dbhost, $dbuser, $dbpass, $dbname;
   if(!isset($link))
   $link = new mysqli($dbhost, $dbuser, $dbpass, $dbname);
   if ($link->connect_errno) {
    die("Connection failed: " . $link->connect_error);
   }
}
/*function closeDB()
{
  global $link;
  $link->close();
}*/

function genRStr($uName) {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    $length = 10;
    $rStr="";
    
    $seed = microtime(true) * 1000000 ^ crc32($uName);
    mt_srand($seed);
    
    $randomStr = '';
    for ($i = 0; $i < $length; $i++) {
        $rStr .= $chars[mt_rand(0, strlen($chars) - 1)];
    }
    $logEntry = date('Y-m-d H:i:s') . " | User: $uName | Generated: $rStr\n";
    file_put_contents("genRStr.log", $logEntry, FILE_APPEND | LOCK_EX);
    
    return $rStr;
}

function getFingerprint($ua, &$fallbackAgents = []) {
    // Удаление `; wv)` — WebView метка
    $ua = preg_replace('/;\s*wv\)/i', ')', $ua);

    // ==== Явные специфические IPTV-приложения ====
    $customPatterns = [
        '/Televizo/i' => 'App|Televizo',
        '/PerfectIPTV\/(\d+)/i' => 'App|PerfectIPTV|v$1',
        '/WorldIPTV\/([\d\.]+)/i' => 'App|WorldIPTV|v$1',
        '/IPTV%20Live\/(\d+)/i' => 'App|IPTV Live|v$1',
        '/IPTVPlayer\/(\d+)/i' => 'App|IPTV Player|v$1',
        '/IPTV%20Good%20Player\/(\d+)/i' => 'App|IPTV Good Player|v$1',
        '/stream_player\/(\d+)/i' => 'App|Stream Player|v$1',
        '/OttPlayeriOS\/(\d+)/i' => 'App|Ott Player iOS|v$1',
        '/OTT\s+TV\/([\d\.]+)/i' => 'App|OTT TV|v$1',
        '/OTT Player\/([\d\.]+)/i' => 'App|OTT Player|v$1',
        '/OTT Navigator\/([\d\.]+)/i' => 'App|OTT Navigator|v$1',
        '/IPTV%20%D0%BF%D0%BB%D0%B5%D0%B5%D1%80\/(\d+)/i' => 'App|IPTV плеер|v$1',
        '/Go-http-client\/([\d\.]+)/i' => 'App|Go-http-client|v$1',
        '/m3uIn\/([\d\.]+)/i' => 'App|m3uIn|v$1',
        '/M3U IPTV App Android/i' => 'App|M3U IPTV',
        '/m3u-ip.tv\s+([\d\.]+)/i' => 'App|m3u-ip.tv|v$1',
    ];
    foreach ($customPatterns as $pattern => $label) {
        if (preg_match($pattern, $ua, $m)) {
            return isset($m[1]) ? str_replace('$1', $m[1], $label) : $label;
        }
    }

    // ==== Android устройства ====
    if (preg_match('/Android[^;]*;\s*([^\;]+)\s+Build\/([^\)\;]+)/i', $ua, $info)) {
        $device = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $info[1])); // normalize name
        $build = trim($info[2]);
        return "Android|$device|$build";
    }

    // ==== iOS ====
    if (preg_match('/(iPhone|iPad).*?OS\s+([\d_]+)/i', $ua, $info)) {
        return "iOS|" . $info[1] . "|" . str_replace('_', '.', $info[2]);
    }

    // ==== Smart TV Samsung с Tizen ====
    if (stripos($ua, 'SMART-TV') !== false && preg_match('/Tizen[^\d]*([\d\.]+)/i', $ua, $info)) {
        return "SmartTV|Samsung Tizen|" . $info[1];
    }

    // ==== LG NetCast ====
    if (preg_match('/NetCast\.TV.*?\/([\d\.]+).*?\(([^,]+),\s*([^\)]+)/i', $ua, $info)) {
        return "SmartTV|LG NetCast.TV-" . $info[1] . "|" . trim($info[3]);
    }

    // ==== Windows ====
    if (preg_match('/Windows NT ([\d\.]+)/i', $ua, $info)) {
        $browser = stripos($ua, 'Edge') !== false ? 'Edge' :
                   (stripos($ua, 'Chrome') !== false ? 'Chrome' :
                   (stripos($ua, 'Firefox') !== false ? 'Firefox' : 'Other'));
        return "Windows|" . $info[1] . "|$browser";
    }

    // ==== Linux/Unix ====
    if (preg_match('/X11; (\w+)/i', $ua, $info)) {
        return "Unix|" . $info[1];
    }

    // ==== Xiaomi MIUI ====
    if (preg_match('/Android\s+\d+; [^;]+;\s*([^\s]+)\s+Build\/([^\s;]+).*?MIUI\/([^\s\)]+)/i', $ua, $info)) {
        return "Android|" . $info[1] . "|MIUI " . $info[3];
    }

    // ==== Неизвестное устройство — fallback ====
    $fallback = "Other|" . substr(sha1($ua), 0, 10);
    $fallbackAgents[$fallback][] = $ua;
    return $fallback;
}

function isCandidate($uid) {
    global $link;

    // Проверка twin
    logQuery("uid $uid: Checking twin in accounts");
    $stmt = $link->prepare("SELECT 1 FROM accounts WHERE twin = ?");
    if (!$stmt) die("SQL prepare error: " . $link->error);
    $stmt->bind_param("i", $uid);
    if (!$stmt->execute()) die("SQL execute error: " . $stmt->error);
    $res = $stmt->get_result();
    $stmt->close();

    if ($res->num_rows >= 1) {
        return false;
    }
    logQuery("uid $uid: Twin not found, proceeding to check dates");

    // Получение всех дат младше 6 дней
    $query = "
        SELECT agent_dates.date 
        FROM agent_dates
        INNER JOIN accounts ON agent_dates.account_id = accounts.id
        WHERE accounts.id = ? 
        AND agent_dates.date >= DATE_SUB(NOW(), INTERVAL 6 DAY)
        ORDER BY agent_dates.date DESC";

    $stmt = $link->prepare($query);
    if (!$stmt) die("SQL prepare error: " . $link->error);
    $stmt->bind_param("i", $uid);
    if (!$stmt->execute()) die("SQL execute error: " . $stmt->error);

    $result = $stmt->get_result();
    $stmt->close();

    // Сбор дат и отладка
    $dates = [];
    $date_strings = [];
    logQuery("uid $uid: Fetching dates younger than 6 days");
    while ($row = $result->fetch_assoc()) {
        $dateTimestamp = strtotime($row['date']);
        if ($dateTimestamp === false) {
            logQuery("uid $uid: Invalid date format: " . $row['date']);
            return false;
        }
        $dates[] = $dateTimestamp;
        $date_strings[] = date('Y-m-d', $dateTimestamp);
        logQuery("uid $uid: Date found: " . $row['date'] . " (timestamp: $dateTimestamp, Y-m-d: " . date('Y-m-d', $dateTimestamp) . ")");
    }

    logQuery("uid $uid: Total dates found: " . count($dates));
    if (count($dates) < 2) {
        logQuery("uid $uid: Less than 2 dates, candidate by default");
        return true;
    }

    // Проверяем одинаковость первых 4 дат
    $first_four_dates = array_slice($date_strings, 0, 4);
    if (count($first_four_dates) >= 4 && count(array_unique($first_four_dates)) === 1) {
        logQuery("uid $uid: Not a candidate: first 4 dates are identical (" . $first_four_dates[0] . ")");
        return false;
    }
    logQuery("uid $uid: First 4 dates check: " . (count($first_four_dates) < 4 ? "Less than 4 dates, skipping identical check" : "Dates are not identical"));

    // Проверяем разницу между последовательными датами
    logQuery("uid $uid: Checking time differences between consecutive dates");
    for ($i = 0; $i < count($dates) - 1; $i++) {
        $diff = $dates[$i] - $dates[$i + 1]; // DESC, поэтому N1 > N2
        $date1 = date('Y-m-d H:i:s', $dates[$i]);
        $date2 = date('Y-m-d H:i:s', $dates[$i + 1]);
        logQuery("uid $uid: Comparing dates $date1 and $date2, difference: $diff seconds (" . round($diff / 86400, 2) . " days)");
        if ($diff < 24 * 60 * 60) { // Меньше 24 часов
            logQuery("uid $uid: Not a candidate: dates $date1 and $date2 are too close ($diff seconds)");
            return false;
        }
    }

    logQuery("uid $uid: Candidate: all consecutive dates are at least 24 hours apart");
    return true;
}

function getSlot() {
    global $link;

    // Текущая дата в формате UNIX timestamp
    $currentTimestamp = time();

    // 1. Выборка записей из accounts, где twin = 0 или twin IS NULL, с полями iptvactdate и iptvmonths
    $queryAccounts = "
        SELECT id, user, iptvactdate, iptvmonths 
        FROM accounts a
        WHERE (a.twin = 0 OR a.twin IS NULL) 
            AND a.iptvusr IS NOT NULL 
            AND a.iptvactdate IS NOT NULL
	    AND a.islocal != 1
            AND NOT EXISTS (
                SELECT 1 
                FROM accounts b 
                WHERE b.twin = a.id)
    ";
    $res=$link->query($queryAccounts);
/*    $stmtAccounts = $link->prepare($queryAccounts);
    if (!$stmtAccounts) {
        return []; // Возвращаем пустой массив при ошибке
    }

    if (!$stmtAccounts->execute()) {
        return [];
    }*/

//    $resultAccounts = $stmtAccounts->get_result();
  //  $stmtAccounts->close();

    // Массив для хранения подходящих кандидатов с их expirationTimestamp
    $candidatesWithExpiration = [];
    $minDaysDifference = 15; // Минимальная разница в днях
    $minSecondsDifference = $minDaysDifference * 24 * 60 * 60; // 15 дней в секундах

    // 2. Перебор записей и проверка на условие iptvactdate + iptvmonths
    while ($account = $res->fetch_assoc()) {
        $id = $account['id'];
        $iptvactdate = $account['iptvactdate']; // UNIX timestamp
        $iptvmonths = $account['iptvmonths'];   // Формат "X:1"

        // Извлекаем количество месяцев из формата "X:1"
        $months = (int)explode(':', $iptvmonths)[0];

        // Вычисляем дату истечения: iptvactdate + (iptvmonths * 30 дней в секундах)
        $secondsInMonth = 30 * 24 * 60 * 60; // 30 дней в секундах
        $expirationTimestamp = $iptvactdate + ($months * $secondsInMonth);

        // Проверяем, больше ли дата истечения текущей даты и разница больше 15 дней
        if ($expirationTimestamp > $currentTimestamp && ($expirationTimestamp - $currentTimestamp) > $minSecondsDifference) {
            // Если условие выполнено, проверяем через isCandidate
            if (isCandidate($id)) {
                // Сохраняем кандидата вместе с его expirationTimestamp
                $candidatesWithExpiration[] = [
                    'id' => $account['id'],
                    'user' => $account['user'],
                    'expirationTimestamp' => $expirationTimestamp
                ];
            }
        }
    }

    // 3. Если нет подходящих кандидатов, возвращаем пустой массив
    if (empty($candidatesWithExpiration)) {
        return [];
    }

    // 4. Находим кандидата с максимальным expirationTimestamp
    $maxExpirationCandidate = array_reduce($candidatesWithExpiration, function($carry, $item) {
        if ($carry === null || $item['expirationTimestamp'] > $carry['expirationTimestamp']) {
            return $item;
        }
        return $carry;
    }, null);

    // 5. Возвращаем только id и user кандидата с максимальным expirationTimestamp
    return [
        'id' => $maxExpirationCandidate['id'],
        'user' => $maxExpirationCandidate['user']
    ];
}

function newUser($login, $password, $email, $ip, $srv, $req = 0, $iptv = 0)
{
  global $link;
  $d_id = $_SESSION["i"];
  $l = $link->real_escape_string($login);
  $p = $link->real_escape_string($password);
  $rq = "INSERT INTO accounts  ";
  if ($iptv) {
    $grpvar = 3;
    $ids = array();
    $plName = genRstr($login);
    $token = generateUniqueToken();
    $q = "SELECT grpid FROM channel_groups_list WHERE playlist_id =$grpvar";
    $res = $link->query($q);
    if ($res->num_rows >= 1) {
      while ($rw = $res->fetch_assoc()) {
        $ids[] = $rw['grpid'];
      }
      $inList = implode(',', $ids);
    }
    if (strlen($p) <= 5)
      $p .= "12";
    $iptvusr = "mp" . $d_id . "_" . $l;
    $rq .= "(plname,  user, pwd, dealer, sum,   email,   ip, dreg,unq,server,phone,   iptvusr,iptvpwd,islocal,iptvcdn,iptvplaylist,grpvariant,token,req) VALUES ('$plName','$l', '$p','$d_id',  0,'$email','$ip',NOW(),  4,  $srv,   '','$iptvusr',   '$p',  1,    1,   '$inList',   $grpvar, '$token',";
  } else {
    $rq .= "(user, pwd, dealer, sum, email,ip,dreg,unq,server,phone,req) VALUES ('$l', '$p','$d_id',1,'$email','$ip',NOW(),4,$srv,'',";
  }
  if ($req)
    $rq .= "(select IF(currency=0,1,0) from dealers where id='$d_id'))";
  else
    $rq .= '0)';
  file_put_contents("query1.log", date("[Y-m-d H:i:s] ") . $rq . PHP_EOL, FILE_APPEND);
  $rs = $link->query($rq);
  if ($rs && !empty($iptv)) {
    //ilookCreateAcc($l, $p, $d_id, $plName);
      cream3u8("/var/www/p/$plName.m3u8", $grpvar, $plName,$inList, '45.9.73.98:8123', '', '', $token);
  } else if (!$rs) {
    die("Died inserting login info into db. Error returned: " . $link->error);
  }
  return true;
}

function checkPass($login, $password)
{
  global $link;
    $stmt=$link->prepare("SELECT id,user,a,hash,currency,rate,postpaid,t_srt FROM dealers WHERE (user=? or eml=?) and pwd=? and (block=0 or block is null)");
    $stmt->bind_param('sss', $login, $login, $password);
    $stmt->execute();
    $res=$stmt->get_result();
    if ($res->num_rows == 1) {
        $row = $res->fetch_assoc();
        $hash = gensessionhash();
        $row['hash'] = $hash;
//        $row['dealer'] = 1;
        $stmt2=$link->prepare("UPDATE dealers SET hash=? WHERE (user=? or eml=?) and pwd=?");
        $stmt2->bind_param('ssss', $hash, $login, $login, $password);
        $stmt2->execute();
        return $row;
    }
/*else
    {
    $link->sql_query("SELECT id, user, hash FROM accounts WHERE user='$login' and pwd='$password'") or die("checkPass fatal error: " . mysql_error());
    if ($link->sql_numrows() == 1)
        {
        $row = $link->sql_fetchrow();
        $hash = gensessionhash();
        $row['hash'] = $hash;
        $row['dealer'] = 0;
        $link->sql_query("update accounts set hash='$hash' where user='$login' and pwd='$password'");
        return $row;
        }
    }*/

   return false;
}

function newDealerOld($login,$password,$email,$ip)
{
   global $link;
    $stmt=$link->prepare("INSERT INTO dealers (user,pwd,sum,eml,ip,dreg,fe,currency,rate) VALUES(?,?,0,?,?,NOW(),1,0,0)");
    $stmt->bind_param('ssss', $login, $password, $email, $ip);
    $stmt->execute() or die("Died inserting login into db.");

    $stmt2=$link->prepare("SELECT id, user, a, hash,currency,rate,postpaid FROM dealers WHERE user=? and pwd=?");
    $stmt2->bind_param('ss', $login, $password);
    $stmt2->execute();
    $res=$stmt2->get_result();
    if ($res->num_rows==1)
     {
        $row = $res->fetch_assoc();
        $hash=gensessionhash();
        $row['hash']=$hash;
        $row['dealer']=1;
        $stmt3=$link->prepare("UPDATE dealers SET hash=? WHERE user=? and pwd=?");
        $stmt3->bind_param('sss', $hash, $login, $password);
        $stmt3->execute();
      return $row;
    }
   return true;
}

function newDealer($login, $password, $email, $ip, $name=null, $family_name=null, $picture=null, $social = null, $t_usr=null, $t_id=null, $t_lname=null, $t_fname = null,$t_phurl = null,$t_auth_date = null) {
    global $link;

    // Проверка регистрации через соцсеть (Google)
    if ($social === 'google' || $social === 'telegram' ) {
        $l = '';
        $p = '';
    } else {
        // Обычная регистрация с логином и паролем
        $l = $link->real_escape_string($login);
        $p = $link->real_escape_string($password);
    }

    if ($social === 'telegram' ) {$e='';$n='';$f='';$pic='';
	$t_usr = $link->real_escape_string($t_usr);
        $t_id = $link->real_escape_string($t_id);
	$t_lname = $link->real_escape_string($t_lname);
	$t_fname = $link->real_escape_string($t_fname);
	$t_phurl = $link->real_escape_string($t_phurl);
	$t_auth_date = $link->real_escape_string($t_auth_date);
        }
	else {
 	   $e = $link->real_escape_string($email);
	   $n = $link->real_escape_string($name);
	   $f = $link->real_escape_string($family_name);
	   $pic = $link->real_escape_string($picture);
	   $t_id = 0; 
	   $t_auth_date=0;
	   }
    
    // Вставка данных нового пользователя
    $link->query("INSERT INTO dealers (user, pwd, sum, eml, ip, dreg, fe, currency, rate, name, family_name, picture,t_usr,t_id,t_lname,t_fname,t_phurl,t_auth_date) 
                  VALUES ('$l', '$p', 0, '$e', '$ip', NOW(), 1, 0, 0, '$n', '$f', '$pic', '$t_usr', '$t_id', '$t_lname', '$t_fname', '$t_phurl', '$t_auth_date')") 
                  or die("Ошибка при добавлении пользователя в базу данных: " . $link->error_list);

    // Получение данных пользователя для сессии
    $res = $link->query("SELECT id, user, a, hash, currency, rate, postpaid FROM dealers WHERE eml='$e'") 
    or die("Ошибка при получении данных пользователя: " . $link->error_list);

    if ($res->num_rows == 1) {
        $row = $res->fetch_assoc();
        $hash = gensessionhash();
        $row['hash'] = $hash;
        $row['dealer'] = 1;
        $link->query("UPDATE dealers SET hash='$hash' WHERE eml='$e'");
        return $row;
    }
    
    return true;
}


function updateUser($usr,$password,$email,$phone,$comment,$server,$toph)
{
   global $link;
   $q='';
/*   $link->sql_query("select id from account where user=$usr");
   if ($link->sql_numrows()==1)
   {
     $row = $link->sql_fetchrow();
   }*/
   if($_SESSION["i"]==46)
    $cstr='';
   else
    $cstr='dealer='.$_SESSION["i"].' AND';
   if(isset($password) && $password!='.')
      $q.="pwd='".$password."'";
   if(isset($email) && $email!='.')
      {if($q)
        $q.=",";
        $q.="email='".$email."'";
      }
    if(isset($phone) && $phone!='.')
      {if($q)
        $q.=",";
        $q.="phone='".$phone."'";}
    if(isset($server) && $server!='.')
      {if($q)
        $q.=",";
        $q.="server='".$server."'";}
    if($comment!='.')
      {if($q)
        $q.=",";
        $q.="dscr='".$comment."'";}
    if($toph=='on')
      {if($q)
        $q.=",";
        $q.="sndnote='1'";
      }
     else
	    {
        if($q)
        $q.=",";
        $q.="sndnote='0'";
      }
   $query_str="UPDATE accounts SET ".$q." WHERE ".$cstr." user='$usr'";
   $link->query($query_str) or die("Died updating login info. Error returned if any: ".$link->error_list);
   if(isset($server)  && $server!='.')
   {
    $res=$link->query("SELECT cwslog.s_id FROM accounts Inner Join cwslog ON accounts.id = cwslog.uid WHERE accounts.`user` = '$usr' AND cwslog.s_id = '$server'") or die("MYSQL1 Error: ".$link->error_list);
    if($res->num_rows != 1)
    {
      $link->query("INSERT INTO cwslog (uid,did,s_id) SELECT id, case when dealer=0 then '46' else dealer end,'$server' from accounts WHERE accounts.`user`= '$usr'") or die("MYSQL2 Error: ".$link->error_list);
    }
   }
   return true;
}

function updatedlr($password,$email,$phone,$toph,$wmid="")
{
   global $link;
   $q='';
/*   $link->sql_query("select id from account where user=$usr");
   if ($link->sql_numrows()==1)
   {
     $row = $link->sql_fetchrow();
   }*/
   $cstr='id='.$_SESSION["i"];

   if(isset($password) && $password!='.')
      $q.="pwd='".$password."'";
   if(isset($email) && $email!='.')
      {if($q)
        $q.=",";
        $q.="eml='".$email."'";
      }
    if(isset($phone) && $phone!='.')
      {if($q)
        $q.=",";
        $q.="phone='".$phone."'";}
    if(isset($wmid) && $wmid!='.')
        {if($q)
          $q.=",";
          $q.="wmid='".$wmid."'";}
    if($toph=='on')
      {
        if($q)
        $q.=",";
        $q.="sndnote='1'";
      }
      else
        {if($q)
        $q.=",";
        $q.="sndnote='0'";
      }
      /*if(isset($crdnum) && $crdnum!='.')
        {if($q)
        $q.=",";
        $q.="cardnum='".$crdnum."'";
       }*/

   $link->query("UPDATE dealers SET ".$q." WHERE ".$cstr) or die("Died updating login info. Error returned if any: ".$link->error_list);
   return true;
}

function displayErrors($messages) {
   foreach($messages as $msg){
     print("$msg\n");
   }
}

function displayMess($mess) {
    foreach($mess as $msg){
        print("$msg\n");
    }
}

function checkLoggedIn($status) {
  global $link;

  switch ($status) {
      case "yes":
          if (!isset($_SESSION["loggedIn"])) {
              // Проверяем, AJAX ли это
              if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
                  if (strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
                      header('Content-Type: application/json');
                      header('Cache-Control: no-store, no-cache, must-revalidate');
                      http_response_code(401);
                      echo json_encode(['status' => 'error', 'message' => 'Session expired', 'redirect' => '/login.php']);
                  } else {
                      header('X-Session-Expired: true');
                      header('Cache-Control: no-store, no-cache, must-revalidate');
                      http_response_code(401);
                      echo 'SESSION_EXPIRED';
                  }
                  exit; // Завершаем выполнение для AJAX-запросов
              } else {
                  // Для не-AJAX-запросов делаем редирект
                  header("Location: login.php");
                  exit;
              }
          }
          // fallback: восстанавливаем $_SESSION['d'] из $_SESSION['i']
          // для сессий, созданных до фикса ajax_login.php
          if (empty($_SESSION['d']) && !empty($_SESSION['i'])) {
              $_SESSION['d'] = $_SESSION['i'];
          }
          break;

      case "no":
          if (isset($_SESSION["loggedIn"]) && $_SESSION["loggedIn"] === TRUE) {
         if (isMobileDevice()) {
    header("Location: mb.php");
} else {
    header("Location: dealer.php");
}
              exit;
          }
          break;
  }
  return true;
}

function checkL($login,$email) {
    global $link;
    $sql_req="SELECT id, user, eml FROM dealers WHERE";
    if(!empty($login))
        $sql_req .= " user='".$login."'";
    if(!empty($login) && !empty($email))
        $sql_req .= " OR";
    if(!empty($email))
        $sql_req .= " eml='".$email."'";
    $sql_req .=  " LIMIT 1";
    $res=$link->query($sql_req) or die("checkL fatal error: ".$link->error_list);
    if ($res->num_rows==1)
    {
        $row = $res->fetch_assoc();
        return $row;
    }
    else
        return false;
}

function cleanMemberSession($login,$id,$adm,$hash,$dea,$currency,$rate,$postpaid) {
   $_SESSION["l"]=$login;
   $_SESSION["loggedIn"]=true;
   $_SESSION["i"]=$id;
   $_SESSION["a"]=$adm;
   $_SESSION["h"]=$hash;
   $_SESSION["d"]=$dea;
   $_SESSION["c"]=$currency;
   $_SESSION["rate"]=$rate;
   $_SESSION["pp"]=$postpaid;
  //  date_default_timezone_set('Asia/Tashkent');
}

function flushMemberSession() {
   unset($_SESSION["l"]);
   unset($_SESSION["loggedIn"]);
   unset($_SESSION["i"]);
   unset($_SESSION["a"]);
   unset($_SESSION["h"]);
   unset($_SESSION["d"]);
   unset($_SESSION["c"]);
   unset($_SESSION["rate"]);
   unset($_SESSION["pp"]);
   $past = time() - 3600;
foreach ($_COOKIE as $key => $value )
{
setcookie( $key, $value, $past, '/' );
}
   session_unset();
   session_destroy();

   return true;
}

function chkbox($_name)
{
$result=0;
if (isset($_REQUEST[$_name]))
{ if ($_REQUEST[$_name]=='on') { $result=1; } else { $result=0; }
}
return $result;
}

function field_validator($field_descr, $field_data, $field_types, $min_length="", $max_length="", $field_required=1) {
   global $messages;
   if(!$field_data && !$field_required){ return; }

   $field_ok = false;

   $email_regexp = "/^([a-zA-Z0-9_\-\.]+)@((\[[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.)|(([a-zA-Z0-9\-]+\.)+))([a-zA-Z]{2,4}|[0-9]{1,3})(\]?)$/";

   $data_types = array(
     "email" => $email_regexp,
     "digit" => "/^[0-9]$/",
     "number" => "/^[0-9]+$/",
     "alpha" => "/^[a-zA-Z]+$/",
     "alpha_space" => "/^[a-zA-Z ]+$/",
     "alphanumeric" => "/^[a-zA-Z0-9_]+$/",
     "alphanumeric_space" => "/^[a-zA-Z0-9 _]+$/",
     "string" => "//"
   );

   // Если поле обязательное, но пустое
   if ($field_required && empty($field_data)) {
     $messages[] = "$field_descr не должен быть пустым<br>";
     return;
   }

   // Проверяем каждое указанное правило
   foreach (explode(",", $field_types) as $field_type) {
     $field_type = trim($field_type); // Убираем пробелы
     if ($field_type == "string") {
       $field_ok = true;
       break;
     } elseif (isset($data_types[$field_type])) {
       if (preg_match($data_types[$field_type], $field_data)) {
         $field_ok = true;
         break;
       }
     }
   }

   if (!$field_ok) {
     $messages[] = "Пожалуйста, введите корректный $field_descr.<br>";
     return;
   }

   // Проверяем минимальную длину
   if ($field_ok && ($min_length > 0) && strlen($field_data) < $min_length) {
     $messages[] = "$field_descr должен быть не короче $min_length символов.<br>";
     return;
   }

   // Проверяем максимальную длину
   if ($field_ok && ($max_length > 0) && strlen($field_data) > $max_length) {
     $messages[] = "$field_descr не должен быть длиннее $max_length символов.<br>";
     return;
   }
}

function restSpaces($s) {
  $r = str_replace('&nbsp;', ' ', $s??"");
  $r = str_replace("\xc2\xa0", ' ', $r);
  $r = trim(preg_replace('/\s+/u', ' ', $r));
  return $r;
}

function gensessionhash()
{
   return md5(mt_rand(1,10));
}

function NavPan($p,$num_pages,$func,$uid=0){
if($num_pages>1)
  $active=' <span class="nbA"> '.$p.'</span> ';
 else
  $active='';
  $pofp='<span>Страница '.$p.' из '.$num_pages.' </span>';
//Проверяем нужна ли ссылка "На первую"
  if($p > 2){
    $first_page = ' <a onclick="'.$func.'(0,'.$uid.')">«</a> ';   //или просто $first_page = '<a href="/index.php"><<</a>';
  }
  else{
    $first_page = "\0";
  }
//Проверяем нужна ли ссылка "На последнюю"
  if($p < ($num_pages - 2)){
    $last_page = ' <a onclick="'.$func.'('.$num_pages.','.$uid.')">»</a> ';
  }
  else{
    $last_page = "\0";
  }
//Проверяем нужна ли ссылка "На предыдущую"
  if($p > 1){
    $prev_page = ' <a onclick="'.$func.'('.($p - 1).','.$uid.')"><</a> ';
  }
  else{
    $prev_page = "\0";
  }
//Проверяем нужна ли ссылка "На следущую"
  if($p < $num_pages){
    $next_page = '<a onclick="'.$func.'('.($p + 1).','.$uid.')">></a> ';
  }
  else{
    $next_page = "\0";
  }

$nav = $pofp.$first_page.$prev_page;
$pp=1;
do
{
if($p - $pp > 0){$nav .= '<a onclick="'.$func.'('.($p - $pp).','.$uid.')">'.($p - $pp).'</a>';}
} while ($pp-- > 1);

$nav .= $active;

for ($pp=1;$pp<=2;$pp++){if($p + $pp <= $num_pages){$nav .= '<a onclick="'.$func.'('.($p + $pp).','.$uid.')">'.($p + $pp).'</a>';}
}
$nav = $nav.$next_page.$last_page;
  return $nav;
}

# OSCam class **********************************************
class OSCam {
    public function getXMLfile($part) {
        $r = null;
        if(LOCAL_ACCESS_ONLY) {
            $r = trim(shell_exec('wget -O - "'.OSCAM_URL.'?'.PART_NAME.'='.$part.'"'));
        } else {
            $r = curl_get_file_contents(OSCAM_URL.'?'.PART_NAME.'='.$part);
//	echo $r;
        }
        return $r;
    }

    public function getStatusNumOfType(&$xml, $nType) {
        $r = 0;
        $x = new SimpleXMLElement($xml);
        foreach($x->status->client as $c) {
            if(!empty($c['type']) && $c['type'] == $nType) $r++;
        }
        return $r;
    }
}

function curl_get_file_contents($URL)
{
 $c = curl_init();
 curl_setopt($c, CURLOPT_RETURNTRANSFER, 1);
 curl_setopt($c, CURLOPT_URL, $URL);
 curl_setopt($c, CURLOPT_SSL_VERIFYPEER, false);
 curl_setopt($c, CURLOPT_SSL_VERIFYHOST, 0);
 curl_setopt($c, CURLOPT_USERPWD, 'boss:worldismine');
 curl_setopt($c, CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);
 curl_setopt($c, CURLOPT_HEADER, FALSE);
 $contents = curl_exec($c);
 curl_close($c);

 if ($contents) return $contents;
           else return FALSE;
}

function time_convert ($timing)
{
 $date_time=strtotime("%2d.%2d.%4d %2d:%2d",$timing);
 //$date_time = explode("-",$timing);
$data_unix = mktime(0,0,0,$date_time[1],$date_time[0],$date_time[2]);
return $data_unix;
}

function u_time_c($timestamp,$shr=0,$ly=0)
{
  $m="d.m.";
  $y=($ly) ? "Y":"y";
  $fstr=($shr) ? $m.$y:$m.$y." H:i";
  return  date ($fstr, $timestamp);
}

function u2c($secs)
{
$res="";
$w=0;$s=0;$y=0;$d=0;$h=0;$m=0;
    if ($secs >= 31556926 % 12)
    $y = $secs / 31556926 % 12;
    if ($secs >= 604800 % 52)
    $w = $secs / 604800 % 52;
    if ($secs >= 86400 % 7)
    $d = $secs / 86400 % 7;
    if ($secs >= 3600 % 24)
    $h = $secs / 3600 % 24;
    if ($secs>60 % 60)
    $m = $secs / 60 % 60;
    if ($secs>60)
    $s = $secs % 60;
    if($w >= 1) $res.=$w." нед. ";
    if($d) $res.=$d." д. ";
    if($y) $res.=$y." г. ";
    if($h) $res.=$h." ч. ";
    if($m) $res.=$m." мин. ";
    //if($s>0) $res.=$s." сек. ";
  return $res;
}

function table_row_format(& $row_counter, $active)
  {
  if($row_counter & 1)
  {
      if($active==1)
          $row_color  =   "ra3";
      elseif($active==2)
          $row_color = 'rd1';
      elseif($active==3)
          $row_color = 'psd2';
      else
          $row_color  =   "rw1";
  }
  else
  {
      if($active==1)
          $row_color  =   "ra4";
      elseif($active==2)
          $row_color = 'rd2';
      elseif($active==3)
          $row_color = 'psd1';
      else
         $row_color =  "rw2";
  }
 return $row_color;
}

function send_mime_mail($name_from, // имя отправителя
                        $email_from, // email отправителя
                        $name_to, // имя получателя
                        $email_to, // email получателя
                        $data_charset, // кодировка переданных данных
                        $send_charset, // кодировка письма
                        $subject, // тема письма
                        $body, // текст письма
                        $html = FALSE, // письмо в виде html или обычного текста
                        $reply_to = FALSE
                        ) {
  $to = mime_header_encode($name_to, $data_charset, $send_charset)
                 . ' <' . $email_to . '>';
  $subject = mime_header_encode($subject, $data_charset, $send_charset);
  $from =  mime_header_encode($name_from, $data_charset, $send_charset)
                     .' <' . $email_from . '>';
  if($data_charset != $send_charset) {
    $body = iconv($data_charset, $send_charset, $body);
  }
  $headers = "From: $from\r\n";
  $type = ($html) ? 'html' : 'plain';
  $headers .= "Content-type: text/$type; charset=$send_charset\r\n";
  $headers .= "Mime-Version: 1.0\r\n";
  if ($reply_to) {
      $headers .= "Reply-To: $reply_to";
  }
  return mail($to, $subject, $body, $headers);
}

function mime_header_encode($str, $data_charset, $send_charset) {
  if($data_charset != $send_charset) {
    $str = iconv($data_charset, $send_charset, $str);
  }
  return '=?' . $send_charset . '?B?' . base64_encode($str) . '?=';
}

function getMonthRus($num_month = false) {
if(!$num_month){$num_month = date('n');}
$monthes = array(
        1 => 'ЯНВАРЬ' , 2 => 'ФЕВРАЛЬ' , 3 => 'МАРТ' ,
        4 => 'АПРЕЛЬ' , 5 => 'МАЙ' , 6 => 'ИЮНЬ' ,
        7 => 'ИЮЛЬ' , 8 => 'АВГУСТ' , 9 => 'СЕНТЯБРЬ' ,
        10 => 'ОКТЯБРЬ' , 11 => 'НОЯБРЬ' ,
        12 => 'ДЕКАБРЬ'
    );
  $name_month = $monthes[$num_month];
return $name_month;
}

function cardsinsert($cards,$did,$uid,$owner='')
{
global $link;
$artosend=array();
$qcardin=$selid='';
if($did)
  $selid="did=".$did;
else if($uid!=0)
  $selid="uid=".$uid;

$rq="SELECT cid,card from cardslist where ".$selid;
$rs=$link->query($rq) or die("SQL error:".$link->connect_errno);
        if ($numofcards=$rs->num_rows)
           {
            for($i=0;$i<$numofcards;$i++)
             $pp = $rs->fetch_assoc();
             $cardstoupdate[$i]['card']=$pp['card'];
             $cardstoupdate[$i]['cid']=$pp['cid'];
           }

$rcardcount=count($cards);
for($i=0;$i<$rcardcount;$i++)
        {
         // if($i>0) $qcardin.=",";
          $id=$cards[$i]['id'];
          $card=$cards[$i]['card'];
          $owner=$cards[$i]['owner'];
          $exp=$cards[$i]['exp'];
          $collector="";
          
          /*if(strlen($card))
            $qcardin.="'".$card."'";
          else $card="''";*/
          if($id==0 && $numofcards<3)
          {
            $rq="INSERT IGNORE INTO cardslist (did,uid,card,owner,exp) VALUES ($did,$uid,'$card','$owner','$exp')";
            //echo $rq;
            $link->query($rq) or die("SQL error: ".$link->error_list);
          }
          else if($id && empty($card))
          {
            $rq="DELETE LOW_PRIORITY FROM cardslist WHERE cid='$id' limit 1";
  //          echo $rq;
          $link->query($rq) or die("SQL error: ".$link->error_list);
          continue;
          }
          if($id)
          {
            if($owner!='')
              $collector=", owner='".$owner."'";
              else
              $collector=' ';

            //$rq="UPDATE cardslist set card=$card".$owner." where ".$selid." AND cid=$id limit 1";
  //          echo $rq;
          }
          if($exp!='')
          {
              $collector.=", exp='".$exp."'";
          }
          if($collector!="")
          {
            $rq="UPDATE cardslist set card='$card'".$collector." where ".$selid." AND cid=$id limit 1";
            $link->query($rq) or die("SQL error: ".$link->error_list);
          }
          
          //else
                //       $artosend[$i]['check']="UPDATE cardslist set card=$card where did=$did and cid=$id";
//            $link->query("UPDATE cardslist set card=$card,owner='$owner' where ".$selid.) or die("SQL error: ".$link->error_list);
        }
/*        if($numofcards>$rcardcount)
          {
            $query="UPDATE cardslist set card='' where card not in (".$qcardin.") and did=$did";
            $link->query($query) or die("SQL error: ".mysql_error());
          }*/
          $res=$link->query("SELECT cid,card,owner,exp from cardslist where $selid and card!='' order by cid limit 3") or die("SQL error: ".$link->error_list);
   $numofcards=$res->num_rows;
   for($i=0;$i<$numofcards;$i++)
          {
             $pp = $res->fetch_assoc();
             $cardstosend[$i]['cid']=$pp['cid'];
             $cardstosend[$i]['card']=$pp['card'];
             $cardstosend[$i]['owner']=$pp['owner'];
             $cardstosend[$i]['exp']=$pp['exp'];
          }
       $artosend['cards']=$cardstosend;
return $artosend;
}

class TelegramBot {
    private $token;
    private $apiUrl;
    private $commands = [];
    private $callback;

    public function __construct($token) {
        $this->token = $token;
        $this->apiUrl = "https://api.telegram.org/bot$token/";
    }

    public function sendMessage($chatId, $text, $replyMarkup = null) {
        $params = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ];
        if ($replyMarkup) {
            $params['reply_markup'] = json_encode($replyMarkup);
        }
        file_get_contents($this->apiUrl . 'sendMessage?' . http_build_query($params));
    }

    public function setWebhook($url) {
        return file_get_contents($this->apiUrl . "setWebhook?url=" . urlencode($url));
    }

    public function command($command, $callback) {
        $this->commands[$command] = $callback;
    }

    public function on($callback) {
        $this->callback = $callback; // Устанавливаем обработчик
    }

    public function run() {
        $update = json_decode(file_get_contents('php://input'));
        if ($this->isValidUpdate($update)) {
            $message = $update->message ?? null;
            if ($message) {
                $chatId = $message->chat->id;
                $text = $message->text ?? '';

                // Обработка команд
                if (isset($this->commands[$text])) {
                    call_user_func($this->commands[$text], $message);
                } else if ($this->callback) {
                    call_user_func($this->callback, $update);
                }
            }
        }
    }

    private function isValidUpdate($update) {
        // Проверка на наличие сообщения
        return isset($update->message);
    }
}

function generateUniqueToken(int $length = 12): string
{
    global $link; // mysqli

    $alphabet = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    $alphabetLength = strlen($alphabet);

    while (true) {
        // Генерация токена
        $token = '';
        for ($i = 0; $i < $length; $i++) {
            $token .= $alphabet[random_int(0, $alphabetLength - 1)];
        }

        $tokenEscaped = $token;//$mysql->real_escape_string($token);

        $result = $link->query(
            "SELECT 1 FROM accounts WHERE token = '$tokenEscaped' LIMIT 1"
        );

        if ($result && $result->num_rows === 0) {
            return $token;
        }
        // иначе — коллизия, повтор
    }
}


?>