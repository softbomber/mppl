<?php
include_once("config.php");
checkLoggedIn("yes");
$accdealer="";
$dealer=$_SESSION['i'];
$user=$_SESSION['l'];
$adm=$_SESSION['a'];
$doru=$_SESSION["d"];
$d=0;
$actv=0;
$active_packet=0;
$active=0;

if (isset($_POST['un']) && $_POST['c']=='c')
{
$user=trim($_POST['un']);
    $sqlr="SELECT id,server,req FROM accounts WHERE";
    if ($_SESSION['a']!=1)
        $sqlr .= " dealer='".$dealer."' and";
    $sqlr .= " deleted='0' and user='".$user."'";
    $res=$link->query($sqlr);
$row=$res->fetch_assoc();
$id=$row['id'];
$server=$row['server'];
$req=$row['req'];
$res=$link->query("SELECT pdates.dend FROM pdates WHERE user_id='$id' AND pdates.dend >= NOW() LIMIT 1");  
if($res->num_rows || $req)
{
//$link->query("SET time_zone = '+00:00'");
  $res=$link->query("SELECT ip,port FROM server WHERE s_id='$server' LIMIT 1");
$row=$res->fetch_assoc();
$ip=$row['ip'];
$port=$row['port'];
$url='http://'.$ip.':'.$port.'/oscamapi.json?part=userstats&label='.$user;
$res=$link->query("SELECT server.url, cwslog.cwok, UNIX_TIMESTAMP(CONVERT_TZ(cwslog.lastcon, '+00:00', '+05:00')) as unxtm FROM server Inner Join cwslog ON cwslog.s_id = server.s_id WHERE cwslog.uid ='$id' AND hide!=1 AND server.ip='$ip'") or die("SQL error: " . $link->error_list);
$numr2 = $res->num_rows;
for ($i = 0; $i < $numr2; $i++)
     $cws[$i] = $res->fetch_assoc();

/*$res=curl_get_file_contents($url);
if($res != false || $res != '403 Forbidden')
  {
   $o = json_decode($res);
   $ustat=$o->oscam->users[0]->user->stats;
   $cstat=$o->oscam->users[0]->user;
  if(($o->error[0]!='Invalid client '.$user) && $ustat->cwok!='')
    {
    echo '<div class=att>Статус: <el id=semafor class=';
    $constat=$cstat->status;
    if (!strcmp($constat,"offline"))
       echo 'red';
    else if (!strcmp($constat,"connected"))
       echo 'yel';
    else
        echo 'grn';
      echo ">".$constat."</el>";
   if (!strcmp($constat,"online"))
    {
      $ip=$cstat->ip;
      if($ip != '')
        {
        echo '<div>IP: '.$ip.'</div>';
        }
      if (($lchan=$cstat->lastchannel)!='')
      {
        echo '<div>Канал: <el style="word-break:break-all">'.$lchan."</el></div>";
      }
      $proto=$cstat->protocol;
      if($proto != '')
      {
      echo '<div>Прото: <el style="word-break:break-all">'.$proto."</el></div></div>";
      }
    }
    echo "<div><h2>СТАТИСТИКА ЗАПРОСОВ</h2><h3>ТEКУЩЯЯ СЕССИЯ</h3><div class=att>DWOK: <el id=dwok>";
    echo ($ustat->cwok + $ustat->cwcache).'</el> DWNOK: <el id=dwnok>'.($ustat->cwtimeout + $ustat->cwnok)."</el></div>";
  if(!$req)
    {
for ($i = 0; $i < $numr2; $i++)
      {
        echo "<h3>Сервер ".$cws[$i]['url']."</h3>";
        echo "<div class=att>DWOK: <el id=cwok>".$cws[$i]['cwok']."</el> ";
        echo "<div>Последний запрос</div>";
        echo u_time_c($cws[$i]['unxtm'])."</div></div>";
      }
    }
    }
  }*/

$res = curl_get_file_contents($url);

if ($res !== false && $res !== '403 Forbidden') {
//    $res = "{" . $res . "}";
    $o = json_decode($res);
//file_put_contents("status_get", json_encode($o, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    if (isset($o->users[0]->user)) {
        $userStats = $o->users[0]->user->stats;
        $userInfo = $o->users[0]->user;

        if (!isset($o->error[0]) || $o->error[0] !== 'Invalid client ' . $user) {
            echo '<div class="att">Статус: <el id="semafor" class="';
            $connectionStatus = $userInfo->status ?? 'unknown';

            if ($connectionStatus === "offline") {
                echo 'red';
            } elseif ($connectionStatus === "connected") {
                echo 'yel';
            } else {
                echo 'grn';
            }
            echo "\">" .$connectionStatus. "</el>";

            if ($connectionStatus === "online") {
                $ip = $userInfo->ip ?? '';
                if (!empty($ip)) {
                    echo '<div>Пров: '.provFromip($ip). '</div>';
                }

                $lastChannel = $userInfo->lastchannel ?? '';
                if (!empty($lastChannel)) {
                    echo '<div>Канал: <el style="word-break:break-all">' . $lastChannel . "</el></div>";
                }

                $protocol = $userInfo->protocol ?? '';
                if (!empty($protocol)) {
                    echo '<div>Прото: <el style="word-break:break-all">' . $protocol . "</el></div>";
                }
            }

            echo '<div><h2>СТАТИСТИКА ЗАПРОСОВ</h2><h3>ТEКУЩЯЯ СЕССИЯ</h3>';
            echo '<div class="att">DWOK: <el id="dwok">';
            $dwok = ($userStats->cwok ?? 0) + ($userStats->cwcache ?? 0);
            $dwnok = ($userStats->cwtimeout ?? 0) + ($userStats->cwnok ?? 0);
            echo $dwok . '</el> DWNOK: <el id="dwnok">' . $dwnok . "</el></div>";

            if (!$req) {
                for ($i = 0; $i < $numr2; $i++) {
                    echo "<h3>Сервер " . $cws[$i]['url'] . "</h3>";
                    echo '<div class="att">DWOK: <el id="cwok">' .$cws[$i]['cwok']. "</el>";
                    echo "<div>Последний запрос</div>";
                    echo u_time_c($cws[$i]['unxtm']) . "</div></div>";
                }
            }
        }
    }
}


}
exit();
}

if(isset($_POST['un']) && $_POST['c']=='all')
{
$user=trim($_POST['un']);
    $sqlr="SELECT id FROM accounts WHERE";
    if ($_SESSION['a']!=1)
        $sqlr .= " dealer='".$dealer."' and";
    $sqlr .= " deleted='0' and user='".$user."'";
    $res=$link->query($sqlr);
$row=$res->fetch_assoc();
$accid=$row['id'];
    echo '<table class="box" style="margin-left:auto;margin-right:auto;line-height:21px;width:100%" cellspacing=0 >
    <thead style="line-height:12px"><th align=center colspan=3>СТАТИСТИКА ЗАПРОСОВ ПО СЕРВЕРАМ</th></thead>';
    echo '<thead style="line-height:12px"><th align=center>Cервер</th><th align=center>Кол-во запросов</th><th align=center>Дата подключения</th></thead>';
    $res=$link->query("SELECT server.url, cwslog.cwok, cwslog.lastcon FROM server Inner Join cwslog ON cwslog.s_id = server.s_id WHERE cwslog.uid ='$accid' AND hide!=1") or die("SQL error: " . $link->error_list);
    $numr2 = $res->num_rows;
    for ($i = 0; $i < $numr2; $i++)
        $cws[$i] = $res->fetch_assoc();
for ($i = 0; $i < $numr2; $i++)
{
    $row_class = table_row_format($i, $active_packet);
        echo '<tr class='.$row_class.'><td>'.($i+1).".   ".$cws[$i]['url']."</td><td align=right>".$cws[$i]['cwok']."</td><td align=center>".$cws[$i]['lastcon']."</td></tr>";
}
echo "</table></div>";
exit();
}
?>