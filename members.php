<?php
include_once("config.php");
 checkLoggedIn("yes");
	$accdealer="";
 $query="SELECT id,dealer FROM accounts WHERE user='$_SESSION[login]'";
 $result=mysql_query($query, $link)
     or die("checkPass fatal error: ".mysql_error());

 if(mysql_num_rows($result)==1)
     $id=mysql_fetch_array($result,MYSQL_ASSOC);
 
 $accid=$id['id'];
 if ($id['dealer']!="")
 { $query="SELECT dlogin FROM dealers WHERE id='$id[dealer]'";
 $result=mysql_query($query, $link)
     or die("checkPass fatal error: ".mysql_error());

 if(mysql_num_rows($result)==1)
    { $id=mysql_fetch_array($result);
	 $accdealer=$id[0];
	}
  	}

?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html>
    <head>
        <title>Добро пожаловать на сайт Metropoliten</title>
        <meta http-equiv="Content-Type" content="text/html; charset=UTF-8"/>
        <link rel="stylesheet" type="text/css" href="css/style.css" />
	<script type="text/javascript" src="sum.js"></script>
	<style type="text/css">@import url(css/calendar-win2k-1.css);</style>
	<script type="text/javascript" src="calendar.js"></script>
	<script type="text/javascript" src="lang/calendar-en.js"></script>
	<script type="text/javascript" src="calendar-setup.js"></script>
     </head>
<body>
<div class="mainContent">
<div id="pageTitle">
<h1>Metropoliten</h1>
</div>
<TABLE align="left" border="0"'>
<tr> 
<?php
echo "<td>"."Добро пожаловать <b>".$_SESSION["login"]."</b>"."</td><tr>";
?>
</table>
</div>
<TABLE align="right" border="0"
<tr>
<td><a href=logout.php>Выход</a></td>
<tr>
<?php
echo  "<td>"."Ваш пароль: <b>".$_SESSION["password"]."</b>\n"."</td><tr>";
echo  "<td>"."Ваш ID: <b>".$accid."</b>\n"."</td><tr>";
if ($accdealer!="")
	echo  "<td>"."Ваш дилер: <b>".$accdealer."</b>\n"."</td><tr>";
?>
</table>
<div class="secondBlock"> 
<div class="cellLeft" > </div>
<div class="cellMiddle">
<?php
 $query="SELECT id,pname,price FROM packets";
  $result = mysql_query($query, $link);

 $numr=mysql_num_rows($result);
  for ($i=0;$i<$numr;$i++)
    $pckts[$i] = mysql_fetch_row($result); 

 $query="SELECT pk.id,pk.pname,pd.dend,pk.price FROM packets as pk, pdates as pd WHERE pd.packet=pk.id AND pd.user_id='$accid' AND NOW()<=pd.dend";
 $result = mysql_query($query, $link);
    
if (!$result) 
{
    $message  = 'Неверный запрос: ' . mysql_error() . "\n";
    $message .= 'Запрос целиком: ' . $query;
    die($message);
}

$numr2=mysql_num_rows($result);
  for ($i=0;$i<$numr2;$i++)
    $pdts[$i] = mysql_fetch_row($result);

$f=0;
echo '<TABLE style="margin-left: auto; margin-right: auto;" border="0" class="box"';
echo '<tr>    <td width="20" align="center"></td>
	      <td width="135" align="center"><b>Пакет</b></td>
	      <td width="105" align="center"><b>Дата окончания</b></td>
	      <td width="50" align="center"><b>Цена</b></td> </tr>';

for ($i=0;$i<$numr;$i++)
 {
   $f=0;
    echo "<td><input type=\"checkbox\" id='p".($i+1)."' value=\"".$pckts[$i][2]."\" onclick=\"UpdateCost()\"></td>";
    echo '<td><div align="top">'.$pckts[$i][1]."</td>"; // Название пакета

    for ($ii=0;$ii<$numr2;$ii++)
    {

    if ($pckts[$i][0]==$pdts[$ii][0])	
        {
        echo '<td id="pdid'.$pdts[$ii][0].'"><div align="center">'.$pdts[$ii][2]."</td>"; // Дата окончания
        $f=1;
        }
    }
    if (!$f)
       {   
        echo '<td></td>'; 
       }
    echo '<td><div align="right"> <b>'.$pckts[$i][2]."</b></td>"; // Цена пакета
    
echo "</tr>";
}
?>
<td></td><td></td><td></td><td><input type="text" readonly="readonly" id="totalcost" value style="text-align: right; width: 60px;"></td>
<td></td><td></td>  <a href="javascript:void(0);" onclick="sedate(1)">1 мес</a>,
                <a href="javascript:void(0);" onclick="sedate(2)">2 мес</a>,
                <a href="javascript:void(0);" onclick="sedate(3)">3 мес</a>,
                <a href="javascript:void(0);" onclick="sedate(4)">4 мес</a>,
                <a href="javascript:void(0);" onclick="sedate(5)">5 мес</a>,
                <a href="javascript:void(0);" onclick="sedate(6)">6 мес</a>,
                <a href="javascript:void(0);" onclick="sedate(12)">12 мес</a>
</td>
</td></table>
</div>
<td><input type="text" id="date" readonly="readonly" value style="text-align: right; width: 65px;"></td>
<td><button id="trigger">...</button></td>
<script type="text/javascript">
  Calendar.setup(
    {
      inputField  : "date",         // ID of the input field
      ifFormat    : "%d.%m.%Y",    // the date format
      button      : "trigger"       // ID of the button
    }
  );
</script>
<div class="cellRight"></div>
</div>
</body>
</html>