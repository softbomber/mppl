<?php
include_once("config.php");
 checkLoggedIn("yes");
$dealer=$_SESSION['d'];
$adm=$_SESSION['a'];
$resarray=array('s'=>'0','r'=>array('e'=>0));
if ( isset($_POST['u']) && (($dealer) || ($adm==1) ) )
{
if($username=trim($_POST['u']))
{
$res=$link->query("SELECT dealer,id from accounts where user='$username' and (deleted=0 or deleted is null)");
if($res->num_rows==1)
	{
	$pp = $res->fetch_assoc();
    $dlr=$pp['dealer'];
    $uid=$pp['id'];
    $res=$link->query("SELECT pdates.dend FROM pdates WHERE user_id='$uid' AND pdates.dend >= NOW() LIMIT 1");
    if($res->num_rows!=1 && ($dlr==$dealer || $adm==1) )
    {
    $link->query("update accounts set deleted=1, deldate=NOW() where user='$username'");
    echo 1;
    }
	}
}
}
?>