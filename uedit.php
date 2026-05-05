<?php
include_once("config.php");
 checkLoggedIn("yes");
$ps=$eml=$ph=$comment=$srv=$acrdnum='.';
$snd=$uid="";
$parray=array();

if(isset($_POST["ps"]) || isset($_POST["ph"]) || isset($_POST["eml"]) || isset($_POST["comment"]) || isset($_POST["srv"]) || isset($_POST["cards"])){
     if (isset($_POST['comment']))  $_POST['comment'] = str_ireplace(' ','&nbsp;',trim($_POST['comment']));
if(isset($_POST["un"]))
{
$uname=$link->real_escape_string($_POST["un"]);

$rs=$link->query("select id from accounts where user='$uname' limit 1");
        if ($rw = $rs->fetch_assoc())
        {
            $uid=$rw['id'];
        }
     }
     if(isset($_POST["snd"]))
        $snd=$link->real_escape_string($_POST["snd"]);
     if(isset($_POST["ps"]))
        $ps=$link->real_escape_string($_POST["ps"]);
     if(isset($_POST["eml"]))
        $eml=$link->real_escape_string($_POST["eml"]);
     if(isset($_POST["ph"]))
        $ph=$link->real_escape_string($_POST["ph"]);
     if(isset($_POST["comment"]))
      $comment=$link->real_escape_string($_POST["comment"]);
     if(isset($_POST["srv"]) && $_POST["srv"] !="" )
      $srv=$link->real_escape_string($_POST["srv"]);
     if(isset($_POST["cards"]))
                  {
                  $cards=trim($_POST["cards"]);
                  $parray=cardsinsert(json_decode($cards,1),0,$uid,'');
                  }
     updateUser($uname,$ps,$eml,$ph,$comment,$srv,$snd);
     $parray["success"]=1;
     echo json_encode($parray);
}

?>