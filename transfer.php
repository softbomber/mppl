<?php
include_once("config.php");
 checkLoggedIn("yes");
$link->sql_query("select id,user,pwd,sum, hash, dreg from dealers") or die("SQL req. error: ".mysql_error());
$numr2=$link->sql_numrows();
for ( $i=0; $i<$numr2; $i++ )
{
$row[$i] = $link->sql_fetchrow(); 
//echo $i." ".$row[$i]['id']." ".$row[$i]['user']." ".$row[$i]['pwd']." ".$row[$i]['sum']."<br>";
}
for ( $i=0; $i<$numr2; $i++ )
{
$ii=$row[$i]['id'];
$u=$row[$i]['user'];
$p=$row[$i]['pwd'];
$s=$row[$i]['sum'];
$h=$row[$i]['hash'];
$dr=$row[$i]['dreg'];
//echo $i." "."id=".$ii." user=".$u."<br>";
$link->sql_query("select user from accounts where user='$u'") or die("SQL req. error: ".mysql_error());
if($link->sql_numrows())
{
	echo "user <color=green>".$u.' </color>already in accs table<br>';
	$u=$u."_";
}
$link->sql_query("INSERT INTO accounts (user,pwd,sum,hash,dreg,dealer) VALUES ('$u','$p','$s','$h','$dr','0')") or die("SQL req. error: ".mysql_error());
} 
for ($i=0;$i<$numr2;$i++)
{
$u=$row[$i]['user'];
$ii=$row[$i]['id'];
$link->sql_query("select id from accounts where user='$u'") or die("SQL req. error: ".mysql_error());
	$row2 = $link->sql_fetchrow(); 
	{
		$id2=$row2['id'];
			$link->sql_query("update accounts set dealer='$id2' where dealer='$ii'");
	}
}
$link->sql_close();
echo "all is done!";
?>