<?php
include_once("config.php");
checkLoggedIn("yes");
//date_default_timezone_set('Asia/Tashkent');
$dealer=$_SESSION['i'];
$doru=$_SESSION['d'];
$num_elements=52;
$p=1;
$adm=$_SESSION['a'];
$resarray=array('s'=>'0','r'=>array('e'=>0));
$now=time();
$edatep=$dstart=0;
$daystob=0;
$dayscount=0;
$pausediff=0;
$lastbpdate=0;
$tmpdate=0;
$dpp=0;
$tz=$_SESSION['timeZoneOffset'];

if ($doru==0) $dealer=0; // залогинились под пользователем
// ------------------------- ОСТАНОВКА ПАКЕТА И ВОЗВРАТ СУММЫ
if (isset($_POST['stop']) && isset($_POST['uid'])) //isset($_POST['unid']) ||
{
    $res=$link->query("SELECT stop_disable from dealers where id=$dealer");
    $res=$res->fetch_assoc();
    if($res['stop_disable']==1)
        exit();
    /*if( isset($_POST['unid']))
   $unid=mysqli_real_escape_string($_POST['unid']);*/
  if (isset($_POST['stop']) && isset($_POST['uid']))
    {
        $pidtostop=$link->real_escape_string($_POST['stop']);
        $uid=$link->real_escape_string($_POST['uid']);
        $did=0;
        if ($doru!=0)
        {
            $r="SELECT bpid FROM bphistory WHERE uid=$uid AND pid=$pidtostop AND (undone IS NULL OR undone=0) AND `action` IN (1,2,3) AND dend";
            if($adm != 1) $r .= " - INTERVAL 15 DAY "; else  $r .= " >= NOW() ";
            $r .="ORDER BY bpid DESC LIMIT 1";
            $res=$link->query($r);
        }
        if($res->num_rows==1)
        { $pp = $res->fetch_assoc();

            $unid=$pp['bpid'];
            $resarray['r']['i']=$unid;
            
/*            if($did!=$dealer && !$adm)
            echo 3; exit();*/
        }
        else {echo 3;exit();}
    }
    /*$res=$link->query("SELECT server from accounts WHERE user='$uid' LIMIT 1") or die("SQL error: ".$link->error_list);
    if($res->num_rows==1)
    {
     $row = $res->fetch_assoc();
     $active_server = $row['server'];
    }*/
// echo "undo id is ".$unid;
$r="SELECT rowid,`action`, did, uid, pid, UNIX_TIMESTAMP(DATE_ADD(`time`,INTERVAL 1 HOUR)) as time, UNIX_TIMESTAMP(dend) as edate, UNIX_TIMESTAMP(`time`) as tstart,days, `sum`, previd, currency,postpaid 
    FROM bphistory  WHERE bpid = $unid and (undone IS NULL OR undone=0) and dend";
    if($adm != 1) $r .= " - INTERVAL 15 DAY"; else  $r .= " >= NOW()";
    $res=$link->query($r) or die("SQL error: ".$link->error_list); //  and dend > NOW()
    //logQuery($r);
    if($res->num_rows==1)
    {
        $row = $res->fetch_assoc();
        $rowid = $row['rowid'];
// echo "rowid ".$rowid;
        $act = $row['action'];
// echo "sel action ".$act;
        $did = $row['did'];
        $uid = $row['uid'];
        $pid = $row['pid'];
        $time = $row['time'];
        $dend = $row['edate'];
        $days = $row['days'];
        $sum = $row['sum'];
        $previd = $row['previd'];
        $tstart = $row['tstart'];
        $curr = $row['currency'];
        $postpaid = $row['postpaid'];
        $summa_provodki = $sum;
        $previd2=$previd;
        $enddatecalc=$tstart;
            if($act == 3) //  подсчёт фактической конечной даты продлённого пакета
            {
                $dayscount += $days;
                $dstart=0;
                do {
                        if ($previd2 == 0) {
                        $previd2 = $row['bpid'];
                        }
                        $res = $link->query("SELECT action, days, `time`, UNIX_TIMESTAMP(`time`) as dstart, previd, bpid, undone FROM bphistory WHERE bpid='$previd2' OR (UNIX_TIMESTAMP(`dend`) IS NULL)");
                            if (!$res || $res->num_rows != 1) {
                                error_log("Ошибка в запросе или данных для bpid=$previd2");
                                break;
                            }
                            $row = $res->fetch_assoc();
                                $dayscount += $row['days'];
                                $previd2 = $row['previd']; 
                        $dstart=$row['dstart'];
                        } while ($row['action'] != 2 && $previd2 != 0);
                        $enddatecalc = $dstart + ($dayscount * 86400); //  фактическая конечная дата продлённого пакета
                        $lastbpdate = $row['time'];
                   }
                   else
                    {$enddatecalc += $days*86400;}
        // ПОДСЧЕТ КОНЕЧНОЙ ДАТЫ ПОСЛЕ ПАУЗЫ
        $res=$link->query("SELECT bphistory.bpid, bphistory.did, bphistory.uid, (UNIX_TIMESTAMP(bphistory.`time`)-UNIX_TIMESTAMP(bphistory.`pausedate`)) as psd
                          FROM bphistory
                          WHERE bphistory.pausedate>FROM_UNIXTIME($tstart) AND bphistory.`action` = '95' AND bphistory.uid = '$uid' ORDER BY bpid DESC");
        //if ($res->num_rows == 1) {
        while ($row = $res->fetch_assoc())
        {
            $pausediff += $row['psd'];
        }
       $enddatecalc += $pausediff; // конечная дата остановки пакета с учётом паузы
//-Расчёт возврата суммы при условии если текущая дата больше окончания пакета
        $r=1;
       $res=$link->query("SELECT sum,postpaid,mindays FROM dealers WHERE id='$did'");
        if($res->num_rows==1)
        { $pp = $res->fetch_assoc();
            $dsum=$pp['sum'];
            $dpp=$pp['postpaid'];
            $mindays=$pp['mindays'];
        }
   }
      if(($act == 3 || $act == 2))// && $dend-$days*86400 < $now)
        {
        if($_SESSION['c']==1 && $curr==0)
            $r=$_SESSION['rate'];
        //echo "enddatecalc={$enddatecalc},days={$days},enddatecalc={($sum*$r)}";
          $ssum = sumret($enddatecalc,$days,(float)$sum*(float)$r);
        //  echo "ssum={$ssum}";
        }
       (!$postpaid) ? $depsum=$dsum+$ssum:$depsum=$dsum-$ssum;
       if($did==$dealer)
            $resarray['sum']=$depsum;
        else
        {
            $resarray['sum']=$depsum;           //     $pp['sum'];
        }
     //   exit();
        if($act==1)
        { $res=$link->query("SELECT bpid FROM bphistory WHERE uid =$uid AND bpid=$unid AND (undone IS NULL OR undone=0)") or die("SQL error: ".$link->error_list);
            if($res->num_rows==1)
            { $pp = $res->fetch_assoc();
                $bpid=$pp['bpid'];
                $link->query("INSERT INTO bphistory (did,action,uid,pid,time,days,sum,previd,ost,ostafter,unxd) VALUES ('$dealer', 91, '$uid', '$pid', NOW(),'$exphours','$sum', '$previd', '$dsum', '$dsum + $sum', '$daystob')")  or die("SQL error: ".$link->error_list);
                if($did || $adm)
                    $link->query("UPDATE dealers SET sum = $ssum WHERE id='$did'") or die("SQL error: " . $link->error_list);
                else
                    $link->query("UPDATE accounts SET sum = $ssum WHERE id='$uid'") or die("SQL error: ".$link->error_list);
                $link->query("UPDATE bphistory SET undone=TRUE,undot=NOW() WHERE bpid='$unid'") or die("SQL error: ".$link->error_list);
                echo TRUE;
                exit;
            }
        }
        $resarray['md']=$mindays;
        switch($act)
        {
        case 2: // buying packet
            $res=$link->query("SELECT bpid FROM bphistory WHERE uid=$uid AND bpid =$unid AND undone=0 ORDER BY bpid DESC LIMIT 1")  or die("SQL req. error: ".$link->error_list);
            if($res->num_rows==1)
            { $pp = $res->fetch_assoc();
              $bpid=$pp['bpid'];
              
              $link->query("INSERT INTO bphistory (did, action,  uid,  pid,  time,  days, sum,  previd, ost, ostafter,undone,unxd) 
                                              VALUES ('$dealer', 92,'$uid', '$pid', NOW(), 0,'$ssum', '$previd','$dsum',$depsum,1,'$daystob')") or die("SQL error: ".$link->error_list);
              $link->query("DELETE LOW_PRIORITY FROM pdates WHERE pid='$rowid' LIMIT 1") or die("SQL req. error: ".$link->error_list);
                      header('Content-Type:application/json;charset=utf-8');
              echo json_encode($resarray);
            }
            break;
        case 3: // prolong
          if($enddatecalc-$days*86400 > $now)  // тeкущая дата меньше общего кол-ва дней самого пакета то отнимаем кол-во дней пакета
            {
              $res=$link->query("SELECT bpid,UNIX_TIMESTAMP(`time`) as tm,UNIX_TIMESTAMP(DATE_ADD(`time`,INTERVAL days DAY)) as edate
                              FROM bphistory
                              WHERE bpid = '$previd'
                              AND pid ='$pid' AND undone=0 AND dend > NOW()
                              ORDER BY bpid DESC LIMIT 1")  or die("SQL error: ".$link->error_list); //                               AND (UNIX_TIMESTAMP(DATE_ADD(DATE_ADD(`time`,INTERVAL days DAY),INTERVAL $pausediff SECOND)) > NOW())
              if($res->num_rows==1)
                {$row = $res->fetch_assoc();
                 $enddatecalc -= $days*86400;
                 $dstartp = $row['tm'];
                 $resarray['s'] = '1';
                 $resarray['r']['s']=$row['tm'];
                 $resarray['r']['e']=$enddatecalc;
                }
            $link->query("UPDATE pdates SET pdates.dend = FROM_UNIXTIME($enddatecalc) WHERE pdates.user_id='$uid' AND pdates.packet='$pid'") or die("SQL req. error: " . $link->error_list);
            }
            else
            {
              $link->query("DELETE LOW_PRIORITY FROM pdates WHERE pdates.user_id='$uid' AND pdates.packet='$pid' LIMIT 1") or die("SQL req. error: ".$link->error_list);
            }
//            (!$postpaid)?$depsum=$dsum+$sum:$depsum=$dsum-$sum;
            $link->query("INSERT INTO bphistory (did, action,  uid,  pid,  time,  days, sum,  previd,ost,ostafter,undone,unxd)
            VALUES ('$dealer', 93, '$uid',' $pid', NOW(),0,'$ssum', '$previd','$dsum',$depsum,1,'$daystob')")  or die("SQL error: ".$link->error_list);
                    header('Content-Type:application/json;charset=utf-8');
            echo json_encode($resarray);
            break;
        }
        if($did || $adm)
            {$rq="UPDATE dealers SET sum = sum";
                (!$postpaid) ? $rq.= "+" : $rq.="-";
                 $rq.="$ssum WHERE id='$did'";
            $link->query($rq) or die("SQL req. error: ".$link->error_list);
            }
        else
            {$rq="UPDATE accounts SET sum = sum + $ssum WHERE id='$uid'";
            $link->query($rq) or die("SQL error: ".$link->error_list);
            }
        $link->query("UPDATE bphistory SET undone=TRUE,undot=NOW() WHERE bpid='$unid'") or die("SQL req. error: ".$link->error_list);
        //$link->query("UPDATE server set need_update=1 where s_id='$active_server'") or die("SQL req. error: ".$link->error_list);

    exit();
}
// ------------------------- КОНЕЧНАЯ ТОЧКА  - ОСТАНОВКА ПАКЕТА И ВОЗВРАТ СУММЫ

//--------------------------------- ИСТОРИЯ ОПЕРАЦИЙ ПО ДИЛЕРУ ------------------------------
if (isset($_POST['list'])) {
    $num_pages=1;
    $p = isset($_POST['page']) ? max(1, (int) addslashes(strip_tags(trim($_POST['page'])))) : 1;

    $res = $link->query("SELECT SQL_CALC_FOUND_ROWS * FROM bphistory WHERE did = $dealer");
    $num = $res->num_rows;
 if($num>0)
    {$num_pages = ceil($num / $num_elements);
    if ($p > $num_pages) $p = $num_pages;
    $start = ($p - 1) * $num_elements;
    }
    else
    {$start=1;}
    $res = $link->query("SELECT
        bphistory.uid,
        paused,
        bphistory.bpid,
        bphistory.pid,
        bphistory.action,
        UNIX_TIMESTAMP(`time`) AS utime,
        bphistory.days,
        bphistory.`sum`,
        bphistory.undone,
        t2.pname AS pname,
        t3.`user`,
        t4.adesc,
        bphistory.ost,
        bphistory.ostafter
    FROM
        bphistory
    LEFT JOIN packets AS t2 ON pid = t2.id
    LEFT JOIN accounts AS t3 ON uid = t3.id
    LEFT JOIN actiondsc AS t4 ON t4.actionid = `action`
    WHERE did = $dealer
    ORDER BY bpid DESC
    LIMIT $start, $num_elements");

    $cnt = $res->num_rows;
    $lst = [];
    for ($i = 0; $i < $cnt; $i++) {
        $lst[$i] = $res->fetch_assoc();
    }

    $res = $link->query("SELECT postpaid FROM dealers WHERE id = '$dealer'");
    $dpp = $res->num_rows === 1 ? $res->fetch_assoc()['postpaid'] : 0;
    echo '<div class="ut-wrap"><div class="ut-card">';
    if ($num_pages > 1) {
        echo '<div class="ut-pager">';
        echo NavPan($p, $num_pages, "loglist", 0);
        echo '</div>';
    }
    ?>
<div class="ut-table-wrap">
<table class="ut-data">
    <thead><tr>
        <th style="width:60px">Время</th>
        <th style="width:110px">ЛОГИН</th>
        <th>ДЕЙСТВИЕ</th>
        <th style="width:90px">ОСТАТОК до</th>
        <th style="width:90px">СУММА</th>
        <th style="width:90px">ОСТАТОК</th>
    </tr></thead>
    <tbody>
<?php
$current_date = '';
for ($i = 0; $i < $cnt; $i++) {
    $row_class = table_row_format($i, 0);
    $date = tzDate($lst[$i]['utime'], $tz,0,'d.m.Y');
    $time = tzDate($lst[$i]['utime'], $tz,0,'H:i');

    if ($current_date !== $date) {
        $current_date = $date;
        echo "<tr class='ut-date-row'><td colspan='6'>{$date}</td></tr>";
    }

    echo "<tr class='{$row_class}'>";
    
    if (in_array($lst[$i]['action'], ['1', '91'])) {
        echo "<td align='center'>{$time}</td>";
        echo "<td style='padding:6px'><el class='login'>" . 
             ($lst[$i]['uid'] == "99999999" ? "Paybot" : $lst[$i]['user']) . 
             "</el></td>";
        echo "<td>{$lst[$i]['adesc']} баланса</td>";
        echo "<td align='right'>{$lst[$i]['ost']}</td>";
        echo "<td class='bl'>+{$lst[$i]['sum']}</td>";
        echo "<td align='right'>{$lst[$i]['ostafter']}</td>";
    }
    elseif (in_array($lst[$i]['action'], ['6', '7'])) {
        echo "<td align='center'>{$time}</td><td></td>";
        echo "<td>{$lst[$i]['adesc']}</td>";
        echo "<td align='right'>{$lst[$i]['ost']}</td>";
	$class = ($lst[$i]['action'] == '6' || $lst[$i]['action'] == '7'  ? ($dpp ? 'bl' : 'sum') : 
                 ((int)$lst[$i]['sum'] > 0 ? 'bl' : 'sum'));
        $sign = ($class == 'bl' ? '-' : '');
        echo "<td class='{$class}'>{$sign}{$lst[$i]['sum']}</td>";
        echo "<td align='right'>{$lst[$i]['ostafter']}</td>";
    }
    else {
        echo "<td align='center'>{$time}</td>";
        echo "<td ><el class='login'>{$lst[$i]['user']}</el></td>";
        
        if (in_array($lst[$i]['action'], ['5', '95'])) {
            echo "<td colspan=5>";
        }
        else
            echo "<td>";
        if ($lst[$i]['undone'] == 1 && in_array($lst[$i]['action'], ['2', '3'])) {
            echo "ОТМЕНЁН. ";
        }
        echo "{$lst[$i]['adesc']} <el class='pn'>{$lst[$i]['pname']}</el>";
        
        if ($lst[$i]['sum'] != '0.00' && in_array($lst[$i]['action'], ['2', '3', '92', '93'])) {
            if (in_array($lst[$i]['action'], ['2', '3'])) {
                echo " на {$lst[$i]['days']}" . 
                     (in_array($lst[$i]['pid'], [40, 41]) ? ' м.' : ' д.');
            }
            echo "</td>";
            echo "<td align='right'>{$lst[$i]['ost']}</td>";
//            $class = ($dpp ? ($lst[$i]['action'] == '92' ? 'sum' : 'bl') : ($lst[$i]['action'] == '92' ? 'bl' : 'sum'));
            $class = ($dpp ? (($lst[$i]['action'] == '92' || $lst[$i]['action'] == '93') ? 'sum' : 'bl') : (($lst[$i]['action'] == '92' || $lst[$i]['action'] == '93') ? 'bl' : 'sum'));
            $sign = ($class == 'bl' ? '+' : '-');
            echo "<td class='{$class}'>{$sign}{$lst[$i]['sum']}</td>";
            echo "<td align='right'>{$lst[$i]['ostafter']}</td>";
        }
	 elseif (in_array($lst[$i]['action'], ['5', '95'])) {
            echo "</td>";
        }
        else {
            echo "</td><td></td>";
        }
    }
    echo "</tr>";
}
echo "</tbody></table>";
echo '</div>'; // ut-table-wrap

    if ($num_pages > 1) {
        echo '<div class="ut-pager">';
        echo NavPan($p, $num_pages, "loglist", 0);
        echo '</div>';
    }
    echo '</div></div>'; // ut-card, ut-wrap
    exit();
}


// --------------------------- СПИСОК ОПЕРАЦИЙ -----------------------------------

if (isset($_POST['lst']) && isset($_POST['uid']))
{
$uid=$_POST['uid'];
if(!isset($_POST['page']))
    $p=1;
else
{$p = addslashes(strip_tags(trim($_POST['page'])));
    if($p < 1) $p = 1;}

$res=$link->query("SELECT COUNT(bpid) FROM bphistory WHERE uid=$uid");
$num=$res->num_rows;
$num_pages = ceil($num / $num_elements);
if ($p > $num_pages) $p = $num_pages;
$start = ($p - 1) * $num_elements;

$res=$link->query("SELECT bphistory.bpid, bphistory.`sum`, UNIX_TIMESTAMP(`dend`) AS utime, UNIX_TIMESTAMP(`time`) AS atime, actiondsc.adesc,
                          bphistory.days, dealers.`user`, bphistory.`action`, bphistory.pid, UNIX_TIMESTAMP(bphistory.pausedate) as paused,
                          packets.pname, bphistory.undone, bphistory.exchange, bphistory.rowid, bphistory.bonus
                    FROM bphistory Inner Join actiondsc ON bphistory.`action` = actiondsc.actionid
                        Inner Join dealers ON bphistory.did = dealers.id Left Join packets ON bphistory.pid = packets.id
                   WHERE bphistory.uid = $uid ORDER BY bphistory.`time` DESC,bphistory.bpid DESC LIMIT $start,$num_elements");
    $cnt=$res->num_rows;
    for ($i=0;$i<$cnt;$i++)
    {
        $lst[$i] = $res->fetch_assoc();
    }
    echo '<div class="ut-wrap"><div class="ut-card">';
    if($num_pages>1)
    {   echo '<div class="ut-pager">';
        echo NavPan($p, $num_pages,"userslog",$uid);
        echo '</div>';
    }
    ?>
    <div class="ut-table-wrap">
    <table class="ut-data">
    <thead><tr><th style="width:125px">Дата</th><th>Действие</th></tr></thead>
    <tbody>
    <?php
    for ($i=0;$i<$cnt;$i++)
    {
             echo '<tr><td align=center>'.u_time_c(tzDateCorr($lst[$i]['atime'],$tz)).'</td>';
        $who=$lst[$i]['user'];
        if($who=="ssmm")
            $who='Админ';
        elseif($who=='')
            $who="Вы";
        echo '<td><el class="login">'.$who.'</el> ';
        echo $lst[$i]['adesc'].' <el class=yel>'.$lst[$i]['pname'].'</el>';
        $action=$lst[$i]['action'];
        if ($action == '95')
               { if(($dtcr=u2c($lst[$i]['atime'] - $lst[$i]['paused']))!="")
                  echo "с корректировкой даты на ".$dtcr;
           }
            /*else if ($action != '92' && $action != '93')
                echo " ".$lst[$i]['days']." д. ";*/
        if( $action == '2' || $action == '3' )
        {
         echo ' до '.u_time_c(tzDateCorr($lst[$i]['utime'],$tz));
         echo ', сумма <el class=red>' .$lst[$i]['sum'];
         echo '</el> на '.$lst[$i]['days'];
         if ($lst[$i]['pid']==40 || $lst[$i]['pid']==41) 
         echo ' м.</td>'; else echo ' д.</td>';
        } else if ($action == '92' || $action == '93')
        { echo ", возврат остатка <el class=grn>".$lst[$i]['sum'];}
        else if($action == '1')
        {
            $rq="SELECT pursesrc from payments where payid=".$lst[$i]['rowid'];
            $rs=$link->query($rq);
            if($rs->num_rows)
                {$rw=$rs->fetch_assoc();
                 $pursesrc=$rw['pursesrc'];
                }

                echo ' на сумму '.$lst[$i]['sum'];
            if(strlen($pursesrc)>13)
                echo ' c карты ';
            else
                echo ' с кошелька ';
            echo ' <el class=ps>'.$pursesrc."</el>.  Зачислено ";
   
            if($lst[$i]['exchange']>0)    
            {
                if($lst[$i]['exchange']>2)
                echo "/";
                else echo "*";
                echo number_format($lst[$i]['exchange'],2)."=".number_format($lst[$i]['exch']+$lst[$i]['bonus'],2);
            }
            else if($lst[$i]['bonus']!=0)
                echo "<br>c учётом бонуса ".$lst[$i]['sum'].'*'.number_format((($lst[$i]['bonus']/$lst[$i]['exch'])*100),2).'%='.$lst[$i]['bonus'].'+'.$lst[$i]['sum'].'='.number_format($lst[$i]['bonus']+$lst[$i]['sum'],2).'</td>';
                echo $lst[$i]['sum']." сум</tr>";
//            echo '<td align=center>'.$lst[$i]['date'].'</td></tr>';
        }
      echo '</el></td></tr>';
      }

  echo '</tbody></table>';
  echo '</div>'; // ut-table-wrap
  if($num_pages>1)
    {
        echo '<div class="ut-pager">';
        echo NavPan($p, $num_pages,"userslog",$uid);
        echo '</div>';
    }
  echo '</div></div>'; // ut-card, ut-wrap
}

$link->close();

function sumret($EDate,$Days,$Amount) {
    $elapsed_time = time() - ($EDate - $Days*24*60*60);
     if ($elapsed_time < 0) {
        return $Amount;
    }
    $elapsed_hours = floor($elapsed_time/3600);
    $thours = $Days * 24;
    $remaining_hours = $thours-$elapsed_hours;
    if ($remaining_hours < 0) {
        return 0;
    }
    $hourly_rate = $Amount / $thours;
    $remaining_amount = $hourly_rate * $remaining_hours;
    
    return  round($remaining_amount, 2);
}
?>
