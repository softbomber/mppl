<?php
include_once("config.php");
 checkLoggedIn("yes");
if($_SESSION['d']==0) checkLoggedIn("no");
$user="";
$pwd="";
$serv="";

$link->sql_query("SELECT XOR(id,123) as id, user, pwd, dscr, phone, DATE_FORMAT(dreg,'%d.%m.%y %H:%i') as dreg, email, paused FROM accounts WHERE user='1223' LIMIT 1");
   if($link->sql_numrows()==1) 
   {
        $row = $link->sql_fetchrow();
        $id=$row['id'];
        $user = $row['user'];
        $pwd = $row['pwd'];
        $serv = "s1.mpol.co";
    }
echo 'id is '.$id.'<br>';
echo $id=gmp_xor($id, 3).'<br>';
echo $id=gmp_xor($id, 3).'<br>';

?>