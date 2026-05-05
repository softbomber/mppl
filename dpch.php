<?php
include_once("config.php");
checkLoggedIn("yes");

if(isset($_POST['op']) && isset($_POST['np']) &&isset($_POST['rp']))
{
    $op=mysql_real_escape_string(trim($_POST['op']));
    $np=mysql_real_escape_string(trim($_POST['np']));
    $did=$_SESSION["i"];
    $link->sql_query("select pwd from dealers where id='$did' limit 1");
    if ($row = $link->sql_fetchrow())
    {
        $rop=$row['pwd'];
        if($op==$rop)
        {
            if($np==mysql_real_escape_string(trim($_POST['rp']))) {
               $link->sql_query("update dealers set pwd='$np' where id='$did' and pwd='$op'");
                echo 1;
            }
        }
        else
            echo 0;
    }
    exit();
}
?>