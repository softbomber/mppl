<?php
include_once("config.php");
date_default_timezone_set('Asia/Tashkent');
$dealer=$_SESSION['i'];
$doru=$_SESSION['d'];
$num_elements=30;
$p=1;
$adm=$_SESSION['a'];
$resarray=array('s'=>'0','r'=>array('e'=>0));
$now=time();
$edatep=$dstart=0;
$daystob=0;

$link->sql_query("SELECT
dealers.`user`,
actiondsc.adesc,
packets.pname,
bphistory.days,
bphistory.`sum`,
bphistory.action,
UNIX_TIMESTAMP(bphistory.`time`) AS utime,
UNIX_TIMESTAMP(bphistory.`time`) AS atime,
bphistory.pid
FROM
bphistory,
packets,
dealers,
actiondsc
WHERE
dealers.id =  bphistory.did AND
actiondsc.actionid =  bphistory.`action` AND
packets.id =  bphistory.pid AND
bphistory.uid = 1786
AND bphistory.pid = 11
ORDER BY
bphistory.`time` DESC");
$cnt=$link->sql_numrows();
for ($i=0;$i<$cnt;$i++)
	{
	   $lst[$i] = $link->sql_fetchrow(); 
	}
?>
<TABLE border=0 cellpadding=0 cellspacing=0 style="margin-right:auto;margin-left:auto;height:14px">
<tr class=t_header><td width=115 align=center><b>Дата</b></td>

<td width=450 align=center><b>Действие</b></td>
<td width=30 align=center><b>Дней</b></td>
<td width=115 align=center><b>До</b></td>
<td width=50 align=center><b>Сумма</b></td></tr>
<?php
   for ($i=0;$i<$cnt;$i++)
    {
       if($lst[$i]['action'] == 3)
            {
            echo "<br>i=".$i."<br>";
            for($ii=$i+1;$ii<=$cnt-1;$ii++)
            {
               if( $lst[$ii]['pid'] == $lst[$i]['pid'] ) 
                {
                 if($lst[$ii]['action'] == 2  || $lst[$ii]['action'] == 3)
                    {
                        echo " action=".$lst[$ii]['action']."<br> dayslst[i]=".$lst[$i]['days']." date=".u_time_c($lst[$i]['utime'])." date+=".u_time_c($lst[$i]['utime']+($lst[$i]['days']+90)*86400)."<br>";
                    $lst[$i]['utime'] = $lst[$i]['utime'] + ($lst[$ii]['days']*86400);
                    echo u_time_c($lst[$i]['utime']+($lst[$i]['days'])*86400);
                    if ($lst[$ii]['action'] == 2)
                        {break;}
                    }   
                }
            }
            }
        echo '<tr><td align=center>'.u_time_c($lst[$i]['atime']).'</td>'; 
        $who=$lst[$i]['user'];
        if($who=="schamen")
            $who='Администратор';
        elseif($who=='')
            $who="Вы";
        echo '<td align=left style="padding-left:6px;padding-right:6px">'.$who.': ';
        echo $lst[$i]['adesc'].' '.$lst[$i]['pname'].'</td>';
        echo '<td align=center>'.$lst[$i]['days'].'</td>';
        echo '<td align=center>'.u_time_c($lst[$i]['utime']+($lst[$i]['days'])*86400).'</td>'; 
        echo '<td align=right>'.$lst[$i]['sum'].'</td></tr>'; 
    }
?>