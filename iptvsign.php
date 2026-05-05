<?php
include_once("config.php");
 checkLoggedIn("yes");


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['un']))
{
$user = $_POST['un'];

    $grpvar = 3;
    $ids = [];

    $escapedUser = mysqli_real_escape_string($link, $user);

    $dealerQuery = "SELECT dealer FROM accounts WHERE user = '$escapedUser'";
    $dealerResult = $link->query($dealerQuery);

    if ($dealerResult->num_rows > 0) {
        $dealerRow = $dealerResult->fetch_assoc();
        $d = $dealerRow['dealer']; // Получаем did
    $pwdQuery = "SELECT pwd FROM accounts WHERE user = '$escapedUser'";
    $pwdResult = $link->query($pwdQuery);

    if ($pwdResult->num_rows > 0) {
        $pwdRow = $pwdResult->fetch_assoc();
        $p = $pwdRow['pwd'];
    
    $q = "SELECT grpid FROM subgroups WHERE playlstid = $grpvar";
    
    $res = $link->query($q);

    if ($res->num_rows > 0) {
        while ($rw = $res->fetch_assoc()) {
            $ids[] = $rw['grpid'];
        }
        $inList = implode(',', $ids);
    } else {
        $inList = '';
    }

    if (strlen($p) <= 5) {
        $p .= "12";
    }

    $iptvusr = "mp" . $d . "_" . $user;
    $escapedIptvusr = mysqli_real_escape_string($link, $iptvusr);
    $escapedP = mysqli_real_escape_string($link, $p);
    $escapedInList = mysqli_real_escape_string($link, $inList);
    $plName=genRstr($escapedUser);
    $token = generateUniqueToken();
    $updateQuery = "UPDATE accounts 
                    SET iptvusr = '$escapedIptvusr', 
                        iptvpwd = '$escapedP', 
                        iptvcdn = 1,
			plname='$plName',
                        iptvplaylist = '$escapedInList', 
                        grpvariant = $grpvar,
			token = '$token'
                    WHERE user = '$escapedUser'";

    if ($link->query($updateQuery)) {
        //$res=ilookCreateAcc($user, $p, $d,$plName);

        $response['message'] = 'Пользователь успешно обновлен!';
        $response['success'] = true;
    } else {
        $response['message'] = 'Ошибка при обновлении данных: ' . $link->error;
        $response['success'] = false;
    }
    } else {
        $response['message'] = 'Дилер не найден!';
        $response['success'] = false;
      
    }
    
    } else {
        $response['message'] = 'Аккаунт не найден!';
        $response['success'] = false;
        
    }
 
echo json_encode($response);
}  
?>