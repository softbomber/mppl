<?php
include_once("config.php");
 checkLoggedIn("yes");
$pp=array();
$dta=array();
$dealer=$_SESSION['d'];
$user=$_SESSION['l'];
(!$dealer) ? $did=0 : $did=$_SESSION['i'];
$adm=$_SESSION['a'];


if (is_numeric($_POST["s"]) && isset($_POST["i"]))
{ 
    $s_id=mysql_real_escape_string($_POST["s"]);
    $uid=mysql_real_escape_string($_POST["i"]);
    
    $link->sql_query("update cwslog set cwok=0 where uid='$uid' and s_id='$s_id' and  did='$did' limit 1");
$dta['m']='';
    echo json_encode($dta);
}
?>