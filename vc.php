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
$dayscount=0;

$link->sql_query("SELECT rowid,`action`, did, uid, pid, 
                  UNIX_TIMESTAMP(DATE_ADD(`time`,INTERVAL 1 HOUR)) as time, 
		          UNIX_TIMESTAMP(DATE_ADD(`time`,INTERVAL days DAY)) as edate, 
                  UNIX_TIMESTAMP(`time`) as tm, 
		          days, `sum`, previd FROM bphistory WHERE bpid = 7472") or die("SQL error: ".mysql_error());
if($link->sql_numrows()==1)
	{
	     $row = $link->sql_fetchrow();
	     $rowid = $row['rowid'];
	     $act = $row['action'];
	     $did = $row['did'];
	     $uid = $row['uid'];
	     $pid = $row['pid'];
	     $time = $row['time'];
	     $edate = $row['edate'];
	     $days = $row['days'];
	     $sum = $row['sum'];
	     $previd = $row['previd'];
	     $tm=$row['tm'];
	     $summa_provodki=$sum;
         $dend=$edate;
         
 if ( $act == 3 )
    {
    $dayscount+=$days;
       do
        {
        if ( $previd == 0 ){ $previd = $row['bpid']; }
        $link->sql_query("SELECT action,`sum`,days,UNIX_TIMESTAMP(`time`) as dstart,previd,bpid FROM bphistory WHERE undone IS NULL AND bpid='$previd'") or die("SQL error:".mysql_error());
           if( $link->sql_numrows() == 1 )
	           {$row = $link->sql_fetchrow();
                $dayscount+=$row['days'];
                $previd = $row['previd'];}
            } while ( $row['action'] != 2 );
        $dend=$row['dstart']+($dayscount*86400);
        
        
        
        if ( ($dend - ($days*86400)) > $now )
            {   $daystob=$dend-$now;
              //  $sum = sumret($daystob,$days,$sum);
             //   echo "sum=".$sum."<br>"; 
            }
    echo u_time_c($dend);
     }
elseif ( $act == 2 )
    {
        
    /*$link->sql_query("SELECT UNIX_TIMESTAMP(dstart) as dstart,UNIX_TIMESTAMP(dend) as dend FROM pdates WHERE pid = '$rowid'") or die("SQL error: ".mysql_error());      
    if($link->sql_numrows()==1)
	{
	     $row = $link->sql_fetchrow();
	     $dstart = $row['dstart'];
	     $dend   = $row['dend'];
    }
 
   $dcorr=0;
        $link->sql_query("SELECT bphistory.days FROM bphistory WHERE bphistory.uid=$uid AND bphistory.bpid =$unid ORDER BY bphistory.bpid DESC LIMIT 1")  or die("SQL req. error: ".mysql_error());        
        	 if($link->sql_numrows()==1)
	           {
                 $pp = $link->sql_fetchrow();
                }
        $dcorr=$pp['days'];*/
     }
     
     
   }
?>