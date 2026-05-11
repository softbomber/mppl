<?php
include_once("config.php");
 checkLoggedIn("yes");

if(isset($_POST["un"]) && isset($_POST["ps"])  && isset($_POST["srv"]) || isset($_POST["iptv"]))
{
 $iptv=0;
 $req=0;
   $stmt=$link->prepare("SELECT user FROM accounts WHERE user=?");
   $stmt->bind_param('s', $_POST["un"]);
   $stmt->execute();
   $res=$stmt->get_result();
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

if(isset($_POST["d"])) {
    $stmt=$link->prepare("SELECT id FROM dealers WHERE user=?");
    $stmt->bind_param('s', $_POST['d']);
    $stmt->execute();
    $res=$stmt->get_result();
} else {
    $stmt=$link->prepare("SELECT id FROM accounts WHERE user=?");
    $stmt->bind_param('s', $_POST['u']);
    $stmt->execute();
    $res=$stmt->get_result();
}
		 if($res->num_rows==1)
		   {
		    $p = $res->fetch_assoc();
		    $i=$p['id'];
            $password=trim($_POST['p']);
            if(isset($_POST["d"])) {
                $stmt=$link->prepare("UPDATE dealers SET pwd=? WHERE id=?");
                $stmt->bind_param('si', $password, $i);
                $stmt->execute();
            } else {
                $stmt=$link->prepare("UPDATE accounts SET pwd=? WHERE id=?");
                $stmt->bind_param('si', $password, $i);
                $stmt->execute();
            }
                $p["s"]=1;
		    }    
/*     else
        $p["success"]=0;*/
echo json_encode($p);
}
?>