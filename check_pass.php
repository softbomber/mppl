<?
$request_name = trim($_REQUEST['name']);
$request_pass = trim($_REQUEST['password']);
/*$get_users_e = mysql_query("SELECT `password` FROM `users` WHERE `name`='".$request_name."' LIMIT 1");
$num_users_e = mysql_num_rows($get_users_e);
if ($num_users_e==0) echo 'false';
else
{
	list($passw_us) = mysql_fetch_array($get_users_e);
	if ($passw_us == $request_pass) echo 'true';
	else echo 'false';
}*/
echo 'true';
?>