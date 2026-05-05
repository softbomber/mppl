<?php
include_once("config.php");
 checkLoggedIn("yes");
require_once("Mpolbot/vendor/autoload.php");
$token = "273329925:AAHW5q2GaEqVYH8q0hTAZtsSm_rudbbmOWQ";
$bot = new \TelegramBot\Api\Client($token);
$pp=array();
$dta=array();
$pu=0;
$pb=0;
$dealer=$_SESSION['d'];
$user=$_SESSION['l'];
$hash=$_SESSION['h'];
$cr=$_SESSION['c'];
(!$dealer)?$did=0 : $did=$_SESSION['i'];
$adm=$_SESSION['a'];
$udid=0;
$accid=0; 
$previd=0;
$usr=0;
$pd=0;
$accsum=0;

$nw=time();

if($dealer)
  {
  $link->sql_query("SELECT dealers.`sum`,dealers.`limit`,postpaid,mindays FROM dealers WHERE dealers.id='$did'") or die("3 error: ".mysql_error());
  if($link->sql_numrows()==1)
    {$row = $link->sql_fetchrow();
     $accdsum = $row['sum'];
     $accdlim = $row['limit'];
     (!$row['postpaid'])? $accpp=0 : $accpp=$row['postpaid'];
     $mindays=$row['mindays'];
     }
  }
if(isset($_POST['racc']))
{
 if ($dealer) 
    echo $accdsum;
 exit;
}

if ((isset($_POST["sum"]) && floatval($_POST["sum"]<>0)) && isset($_POST["l"]) && is_numeric($_POST["sum"]))
{ 
  if(is_numeric($_POST["sum"]) && $_POST["sum"]<= $accdsum)
    {
    $sum=mysql_real_escape_string($_POST["sum"]);
    $login= mysql_real_escape_string($_POST["l"]);
    $link->sql_query("select id,sum,req from accounts where user='$login' limit 1");
    if($link->sql_numrows()==1)
        {$row = $link->sql_fetchrow();
         $accid = $row['id'];
         $accsum= $row['sum'];
         $accreq= $row['req'];
       }
  
  if(($_POST["sum"] > 0 && $accdsum >= $_POST["sum"]) || ( $_POST["sum"] < 0 && $accsum >= abs($_POST["sum"]) && isset($accreq)))
        {
        $link->sql_query("UPDATE accounts SET sum = (sum+$sum) WHERE id='$accid'") or die(" error: " . mysql_error());
        $link->sql_query("UPDATE dealers SET sum = (sum-$sum) WHERE id='$did'") or die(" fatal error: " . mysql_error());
        ($sum>0)?$action=1:$action=91;
        $link->sql_query("INSERT INTO bphistory (did, action, uid, pid, time, dend, days, sum, ost, ostafter) VALUES
                                            ($did, $action,$accid, 0, NOW(), 0, 0, $sum, $accsum, $accsum-$sum)") or die("checkPass fatal error: " . mysql_error());
    $dta['id']= mysql_insert_id();
        
   $link->sql_query("SELECT dealers.`sum` FROM dealers WHERE dealers.id='$did' limit 1") or die("3 error: ".mysql_error());
     if($link->sql_numrows()==1)
        {$row = $link->sql_fetchrow();  }
    $dta['d']=$row['sum'];
   $link->sql_query("select sum from accounts where user='$login' limit 1");
    if($link->sql_numrows()==1)
        {$row = $link->sql_fetchrow();}
      $dta['ad']= $row['sum'];
  echo json_encode($dta);
       }
  exit;
    }
   else
    {
    $dta["m"] = "НА БАЛАНСЕ НЕДОСТАТОЧНО СРЕДСТВ";echo json_encode($dta);
    } 
exit;
}

if(isset($_POST['uid']) && isset($_POST['pb']))
  {
  $uid=trim($_POST['uid']);
  $link->sql_query("SELECT id,user,tcid,sum,dealer,paused FROM accounts WHERE id='$uid'") or die("SQL error: ".mysql_error());
 if( $link->sql_numrows()==1 )
   { $r = $link->sql_fetchrow();
     $accid=$r['id'];
     $accsum=$r['sum'];
     $udid=$r['dealer'];
     $user=$r['user'];
     $tcid=$r['tcid'];
     if($r['paused'] == 1)
        { $dta["m"]="АККАУНТ НА ПАУЗЕ";
          echo json_encode($dta);
         exit;
        }
    if($did == $udid || (!$dealer && $user) || $adm)
      $dta["d"] = 1;
   else
      $dta["d"] = 0;
    if(($accpp!=1 && $accdsum>0) || ($accpp==1 && $accdsum>=0))
        {$pb = $_POST['pb'];}
    else if($accdsum==0 && isset($_POST['pb']))
        {$dta["m"] = "НА БАЛАНСЕ НЕДОСТАТОЧНО СРЕДСТВ";echo json_encode($dta);
        $ip=$_SERVER['REMOTE_ADDR'];
        $link->sql_query("INSERT INTO buy_fail (did,ip)  VALUES ($did,$ip)") or die("Req error: " . mysql_error());
        exit;
        }
 $sum=0;
 $notmess=array();
 if($pb)
 {
        for($i=0;$i<count($pb);$i++)
        {
        $pid=$pb[$i][0];
        $link->sql_query("SELECT pname,price,sum,paynet,special,special2 FROM packets WHERE id=$pid") or die("SQL error: ".mysql_error());
        if($link->sql_numrows()==1)
            { $pp = $link->sql_fetchrow();
                $notmess[$i]['pname']=$pp['pname'];
                    if($_SESSION['c']==0 && ($_SESSION['a'] == 0 || $_SESSION['a'] == 1))
                        $calcsum=($pp['price'])/30*$pb[$i][1];
                    else if($_SESSION['a']==2 && $_SESSION['c']==1)
                        $calcsum=($pp['paynet'])/30*$pb[$i][1];
                   else if($_SESSION['a']==3 && $_SESSION['c']==0)
                        $calcsum=($pp['special'])/30*$pb[$i][1];
                   else if($_SESSION['a']==4 && $_SESSION['c']==0)
                        $calcsum=($pp['special2'])/30*$pb[$i][1];
    /*else if($_SESSION['a']==5 && $_SESSION['c']==0)
                        $calcsum=($pp['t'])/30*$pb[$i][1];
    else if($_SESSION['a']==6 && $_SESSION['c']==0)
                        $calcsum=($pp['tdj'])/30*$pb[$i][1];
    else if($_SESSION['a']==7 && $_SESSION['c']==0)
                        $calcsum=($pp['trk'])/30*$pb[$i][1];
    else if($_SESSION['a']==8 && $_SESSION['c']==0)
                        $calcsum=($pp['dollar'])/30*$pb[$i][1];
    else if($_SESSION['a']==9 && $_SESSION['c']==0)
                        $calcsum=($pp['muha'])/30*$pb[$i][1];*/
    //else if($_SESSION['a']==10 && $_SESSION['c']==1)
                        //$calcsum=($pp['olim'])/30*$pb[$i][1];
    /*else if($_SESSION['a']==11 && $_SESSION['c']==1)
                        $calcsum=($pp['borya73'])/30*$pb[$i][1];*/
                    else
                        $calcsum=($pp['sum'])/30*$pb[$i][1];
                    $pb[$i][2]=$calcsum;
            $sum += $calcsum; 
            }
        else
            {
            $dta["m"] = "ПРОИЗОШЛА ОШИБКА";echo json_encode($dta);
            $ip=$_SERVER['REMOTE_ADDR'];
            $link->sql_query("INSERT INTO buy_fail (did,ip)  VALUES ($did,$ip)") or die("Req error: " . mysql_error());   
            exit;
            }
        }
    if($sum<0)
        {$dta["m"] = "НА БАЛАНСЕ НЕДОСТАТОЧНО СРЕДСТВ";echo json_encode($dta);
        $ip=$_SERVER['REMOTE_ADDR'];
        $link->sql_query("INSERT INTO buy_fail (did,ip)  VALUES ($did,$ip)") or die("Req error: " . mysql_error());
        exit;
        }
    if($dealer) {$accsum=$accdsum;}
    if((( (($accsum+$sum)<=$accdlim || !$accdlim) && $accpp) || ($accsum>=$sum && !$accpp))) ////// ????????????????????????????????
    {
    $ee=array();
    $previd=0;
    $rowid=0;
    for($i=0;$i<count($pb);$i++)
    {
        $idd=$pb[$i][0];
        if(($dinterval = $pb[$i][1]) >= $mindays || $_SESSION['a'] == 1)
        {
            $psum=$pb[$i][2];
            $link->sql_query("SELECT pid,dend,UNIX_TIMESTAMP(dend) as udend FROM pdates WHERE user_id = '$accid' AND packet = '$idd'");
            if($link->sql_numrows()==1)
            {
             $pp=$link->sql_fetchrow();
             $pid=$pp['pid'];
             $dend=$pp['dend'];
             $udend=$pp['udend'];
             if($udend>$nw)
                {
                $action=3;
                $link->sql_query("UPDATE pdates SET pdates.dend = DATE_ADD(pdates.dend,INTERVAL $dinterval DAY), pdates.dstart=NOW() WHERE pdates.user_id=$accid AND pdates.packet=$idd AND pid=$pid") or die("Req error: " . mysql_error());
                $link->sql_query("SELECT bphistory.bpid, bphistory.time, bphistory.uid FROM bphistory WHERE bphistory.uid =$accid AND bphistory.pid =$idd AND action IN (2,3) AND (undone IS NULL OR undone=0) ORDER BY bphistory.bpid DESC LIMIT 1") or die("Req error: " . mysql_error());
                if($link->sql_numrows()==1)
                   {
                    $pp=$link->sql_fetchrow();
                    $previd=$pp['bpid'];
                    $dstart=$pp['time'];
                    $uid=$pp['uid'];
                    }
                }
                else  // если пакет просрочен
                {
                  $rowid=$pid;
                  $action=2;
                  $link->sql_query("UPDATE pdates SET pdates.dend = DATE_ADD(NOW(),INTERVAL $dinterval DAY),pdates.dstart=NOW() WHERE pdates.user_id=$accid AND pdates.packet=$idd AND pid=$pid") or die("Req error: " . mysql_error());
                }
            }
            else
            {
                $action=2;
                $link->sql_query("INSERT INTO pdates (user_id,dstart,dend,packet) VALUES ('$accid',NOW(),DATE_ADD(NOW(),INTERVAL $dinterval DAY),'$idd')") or die("Pbuy REQ error: " . mysql_error());
                $rowid = mysql_insert_id();
            }
                $notmess[$i]['action']=$action;
                $notmess[$i]['days']=$dinterval;
                (!$accpp)?$ostafter=$accsum-$sum:$ostafter=$accsum+$sum;
                $rpl=($action==3)? "'".$dend."'": "NOW()";
                $link->sql_query("INSERT INTO bphistory (did, rowid, action,uid,pid,time,dend,days,sum,previd,ost,ostafter,currency,postpaid) VALUES ($did, $rowid, $action, $accid, $idd, NOW(), DATE_ADD($rpl,INTERVAL $dinterval DAY), $dinterval, $psum, $previd, $accsum, $ostafter,$cr,$accpp)") or die("REQ error: " . mysql_error());
                $insrowid = mysql_insert_id();
      //          $ee[$i][1]=$previd;
                $ee[$i][0]=$insrowid;
        }
    else
      continue;
    }
        if($tcid) {
            $tosend = "Была произведена оплата.\nЛогин " . $user . "\n";
            for ($i = 0; $i < count($pb); $i++) {
                $tosend .= "Пакет " . $notmess[$i]['pname'];
                if ($notmess[$i]['action'] == 3)
                    $tosend .= " продлён";
                $tosend .= " на " . $notmess[$i]['days'] . " д.\n";
            }
            $bot->sendMessage($tcid, $tosend, "HTML");
        }
       ($accpp)?$tsum=$accdsum+$sum:$tsum=$accdsum-$sum;
       $dta["sum"]=$tsum;
       $dta["md"]=$mindays;
       if($dealer)
          $link->sql_query("UPDATE dealers SET sum = $tsum WHERE id='$did'") or die("REQ error: " . mysql_error());
    $dta["e"]=$ee;
    }
    else
    {
     ($accpp)? $dta["m"] = "ПРЕВЫШЕН ЛИМИТ" :  $dta["m"] = "НА БАЛАНСЕ НЕДОСТАТОЧНО СРЕДСТВ";
     //echo json_encode($dta);
    }
  echo json_encode($dta);
 }
else
 {
  $dta["m"]="ПОЛЬЗОВАТЕЛЬ С ТАКИМ ЛОГИНОМ НЕ НАЙДЕН";
  echo json_encode($dta);
  exit;
 }
}
}
$link->sql_close();
?>