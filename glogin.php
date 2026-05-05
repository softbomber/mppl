<?php
include_once("config.php");
checkLoggedIn("no");
require_once 'vendor/autoload.php'; // Google Client Library via Composer

$client = new Google_Client();
$client->setClientId('18354799548-i3lf5v1ikc2emama5m71l369o4eqpi9f.apps.googleusercontent.com');
$client->setClientSecret('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');
$client->setRedirectUri('https://mpol.co/glogin.php');
$client->addScope('email');
$client->addScope('profile');

if (!isset($_GET['code'])) {
    $timeZoneOffset = isset($_POST["timeZoneOffset"]) ? $_POST["timeZoneOffset"] : 0; 
    $client->setState(base64_encode($timeZoneOffset));
    $authUrl = $client->createAuthUrl();
    header('Location: ' . filter_var($authUrl, FILTER_SANITIZE_URL));
    exit();
} else {
if (isset($_GET['state'])) {
    $timeZoneOffset =  base64_decode($_GET['state']);
}
    $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);
    $client->setAccessToken($token['access_token']);

    // Получаем информацию о пользователе
    $oauth2 = new Google_Service_Oauth2($client);
    $google_account_info = $oauth2->userinfo->get();
    $email = $google_account_info->email;
    $name = $google_account_info->given_name;
    $family_name = $google_account_info->family_name;
    $picture = $google_account_info->picture;
    $query = $link->prepare("SELECT id,user, a, name, family_name,currency,hash,postpaid,t_srt,rate,tz FROM dealers WHERE eml = ?");
    $query->bind_param('s', $email);
    $query->execute();
    $result = $query->get_result();
    $dealer = $result->fetch_assoc();
    $dealerId = $dealer['id'];
    // Если пользователь не найден, создаём нового
    if (!$dealer) {
        newDealer("", "", $email, $_SERVER['REMOTE_ADDR'], $name, $family_name, $picture, "google");
    } else {

        if (empty($dealer['name'])) {
            $name = !empty($name) ? $name : 'Default Name'; // Установите значение по умолчанию
            $link->query("UPDATE dealers SET name = '$name' WHERE id = $dealerId");
        }

        if (empty($dealer['family_name'])) {
            $family_name = !empty($family_name) ? $family_name : 'Default Family'; // Установите значение по умолчанию
            $link->query("UPDATE dealers SET family_name = '$family_name' WHERE id = $dealerId");
        }

        if (empty($dealer['picture']) && !empty($picture)) {
            $link->query("UPDATE dealers SET picture = '$picture' WHERE id = $dealerId");
        }
    }

    $_SESSION['user_email'] = $email;
    $_SESSION['user_name'] = $name;


    $_SESSION['timeZoneOffset'] = $dealer["tz"];
    setcookie('timeZoneOffset', $dealer["tz"], time() + 86400, '/');

    if (!empty($timeZoneOffset) && $dealerId) {
        $query = "UPDATE dealers SET tz = ? WHERE id = ?";
        $stmt = $link->prepare($query);
        $stmt->bind_param('ii', $timeZoneOffset, $dealerId);
        $stmt->execute();
        $stmt->close();
    }
      $a=$d=0;
      $s_time=time()+86400;
      if(isset($dealer['a']))
	     {
         setcookie("a",$dealer['a'],$s_time,'/');
	       $a=$dealer['a'];
	     }
      else {
         unset($_COOKIE['a']);
         setcookie("a", "", time() - 86400);
           }
    if(isset($dealer['hash']))
        {$hash=$dealer['hash'];
        setcookie("hsh", $hash,$s_time,'/');}
        setcookie("pp", $dealer['postpaid'],$s_time,'/');
        setcookie("sort",$dealer['t_srt'],$s_time,'/');
        setcookie("i", $dealerId, $s_time, '/');

    ini_set('session.cookie_lifetime', $s_time);
    ini_set('session.gc_maxlifetime', $s_time);

    // Логирование IP
    $ip = $_SERVER['REMOTE_ADDR'];
    $link->query("INSERT INTO ip_log (did, ip, `when`) VALUES ($dealerId, '$ip', NOW())") or die("inserting. Error: " . $link->error_list);
    // $usrname= 	$dealer["user"] ? $dealer["user"] : $name." ".$family_name;
    $usrname = $dealer["user"] ?: trim(($name ? $name : "") . ($family_name ? " " . $family_name : ""));
    cleanMemberSession($usrname,$dealerId,$a,$dealer['hash'],$dealerId,$dealer['currency'],$dealer['rate'],$dealer['postpaid']);
    // Перенаправление
    header("Location: dealer.php");
    exit();
}
?>
