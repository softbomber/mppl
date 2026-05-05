<?php
include_once("config.php");
 checkLoggedIn("yes");
date_default_timezone_set('Asia/Tashkent');
$dealer=$_SESSION['i'];
$doru=$_SESSION['d'];
$num_elements=16;
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

if (!$doru) $dealer=0; // залогинились под пользователем

// --------------------------- СПИСОК ОПЕРАЦИЙ -----------------------------------

if (isset($_POST['lst']))
{
$uid=$dealer;
if(!isset($_POST['page'])) $p=1; else {$p = addslashes(strip_tags(trim($_POST['page']))); if($p < 1) $p = 1;}

$res=$link->query("SELECT COUNT(bpid) FROM bphistory WHERE did=$uid and action='1'");
$num=$res->num_rows;
$num_pages = ceil($num / $num_elements);
if ($p > $num_pages) $p = $num_pages;
$start = ($p - 1) * $num_elements;

$res=$link->query("SELECT payments.pursesrc,payments.pursedst,bphistory.currency,payments.`sum`,payments.`bonus`,DATE_FORMAT(payments.dateupd,'%d.%m.%y %H:%i') as date,
dealers.`user`,bphistory.`sum` as exch,bphistory.exchange FROM payments Inner Join bphistory ON payments.payid = bphistory.rowid Inner Join dealers ON bphistory.uid = dealers.id
WHERE action=1 and bphistory.did = $uid ORDER BY payid ASC LIMIT $start,$num_elements");

    $cnt=$res->num_rows;
    for ($i=0;$i<$cnt;$i++)
    {
        $lst[$i] = $res->fetch_assoc();
    }
    ?>
    <TABLE id="paymlst" border=0 cellpadding=0 cellspacing=0 width=100%>
    <tr class=t_header><td width=55><td width=360 align=center padding-right=6px>Действие</td><td width=75 align=center>Дата</td></tr>
    <?php
    for ($i=0;$i<$cnt;$i++)
    {
        echo '<tr><td>'.$lst[$i]['user'].'</td><td> Пополнение на сумму '.$lst[$i]['sum'];
        if(strlen($lst[$i]['pursesrc'])>13)
            echo ' c карты ';
        else
            echo ' с кошелька ';
     
        echo ' <el class=ps>'.$lst[$i]['pursesrc'].".</el>  Зачислено ";
        if($lst[$i]['currency']==0)
         {
            if($lst[$i]['exchange']>0)
            {
            if($lst[$i]['exchange']>2)
                echo $lst[$i]['sum']."/";
            else echo $lst[$i]['sum']."*";
             echo number_format($lst[$i]['exchange'],2)."=".number_format($lst[$i]['exch']+$lst[$i]['bonus'],2);
            }
        }
        else if($lst[$i]['currency']==1)
        { echo $lst[$i]['sum']."*";
            echo number_format($lst[$i]['exchange'],2)."=".number_format($lst[$i]['exch']+$lst[$i]['bonus'],2);}
        else if($lst[$i]['bonus']!=0)
            echo "<br>c учётом бонуса ".$lst[$i]['sum'].'*'.number_format((($lst[$i]['bonus']/$lst[$i]['exch'])*100),2).'%='.$lst[$i]['bonus'].'+'.$lst[$i]['sum'].'='.number_format($lst[$i]['bonus']+$lst[$i]['sum'],2).'</td>';
            echo $lst[$i]['currency'] ? " сум" : "$";
        echo '<td align=center>'.$lst[$i]['date'].'</td></tr>';
    }
  if($num_pages>1)
    {	echo '</table>';
        echo	'<div align=center><table><td>';
        echo NavPan($p, $num_pages,"paylog",$uid);
        echo	'</td><table></div>';
    }
}

$link->close();
    /*<div>
    <iframe frameborder="0" allowtransparency="true" scrolling="no" src="https://money.yandex.ru/quickpay/shop-widget?account=410015238305219&quickpay=shop&payment-type-choice=on&mobile-payment-type-choice=on&writer=buyer&targets-hint=%D0%92%D0%B2%D0%B5%D0%B4%D0%B8%D1%82%D0%B5+%D1%81%D0%B2%D0%BE%D0%B9+%D0%BB%D0%BE%D0%B3%D0%B8%D0%BD&default-sum=100&button-text=01&successURL=https%3A%2F%2Fmpol.co%2Fyandex_pay.php" width="450" height="210"></iframe>
    </div>*/
?>
