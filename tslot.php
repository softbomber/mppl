<?php
include_once("config.php");
 checkLoggedIn("yes");

$res=getSlot(3);
echo "ID: ".$res['id']." User: ".$res['user'];

?>