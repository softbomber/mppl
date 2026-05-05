<?php
include_once("config.php");
 checkLoggedIn("yes");

if(isset($_POST["un"]) && isset($_POST["ps"])  && isset($_POST["srv"]) || isset($_POST["iptv"]))
{
 $iptv=0;
 $req=0;
   $q="SELECT user FROM accounts WHERE user='".$_POST["un"]."'";
   $res=$link->query($q);
   if(!$res){$p["s"] = 0;}
   if(!$res->num_rows && $res)
   {
    if(isset($_POST["req"]) && $_POST["req"]=="on")
          $req=1;
    if(isset($_POST["iptv"]) && $_POST["iptv"]=="on")
          $iptv=1;
    newUser($_POST["un"], $_POST["ps"],"","",$_POST["srv"],$req,$iptv);
    $p["s"]=1;
    }
echo json_encode($p);
exit();
}

if(isset($_POST["p"]) && isset($_POST["u"]) || isset($_POST["d"]))
{
    if(isset($_POST["d"]))
        $login=$_POST["d"];
    else $login=$_POST["u"];
    field_validator("login",$login,"alphanumeric", 4, 33);
    field_validator("password", $_POST["p"], "alphanumeric", 4, 33);
$p["s"]=0;

if(isset($_POST["d"]))
$res=$link->query("select id from dealers where user='$_POST[d]'") or die("MySQL query $query failed.  Error if any: ".mysql_error());
else
$res=$link->query("select id from accounts where user='$_POST[u]'") or die("MySQL query $query failed.  Error if any: ".mysql_error());
		 if($res->num_rows==1)
		   {
		    $p = $res->fetch_assoc();
		    $i=$p['id'];
            $password=trim($_POST['p']);
            if(isset($_POST["d"]))
                $link->query("update dealers set pwd='$password' where id='$i'") or die("MySQL query $query failed.  Error if any: ".mysql_error());      
            else
                $link->query("update accounts set pwd='$password' where id='$i'") or die("MySQL query $query failed.  Error if any: ".mysql_error());
                $p["s"]=1;
		    }    
/*     else
        $p["success"]=0;*/
echo json_encode($p);
}
?>