<?php
include_once("config.php");
 checkLoggedIn("yes");
if($_SESSION['d']!=0)
checkLoggedIn("no");

$accdealer="";
$dealer=$_SESSION['i'];
$user=$_SESSION['l'];

 $link->sql_query("SELECT acc.id,acc.pwd,acc.dealer,acc.sum,acc.dscr,acc.phone,acc.email,DATE_FORMAT(acc.dreg,'%d.%m.%Y %H:%i') as dreg,s.url,s.ip FROM accounts as acc, server as s WHERE acc.user='$user' and s.s_id=server") or die("SQL req. error: ".mysql_error());
	 if($link->sql_numrows()==1)
	   {
	     $row = $link->sql_fetchrow();
		 $accid = $row['id'];
		 $accsum = $row['sum'];
		 $accdscr = $row['dscr'];
		 $accphone = $row['phone'];
         $accip = $row['ip'];
         $accurl = $row['url'];
         $accemail = $row['email'];
         $accdreg = $row['dreg'];
         $accpwd = $row['pwd'];
	   }
if ($row['dealer']!="")
 		{ $link->sql_query("SELECT user FROM dealers WHERE id='$row[dealer]'") or die("SQL req. error: ".mysql_error());
		      if($link->sql_numrows()==1)
		    	{$row = $link->sql_fetchrow();
			 $accdealer=$row['user'];
			}
		 }
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html><head>
<title>Добро пожаловать на Metropoliten</title>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8"/>
<script type="text/javascript" src="js/jquery-1.10.1.min.js"></script>
<script type="text/javascript" src="js/jquery-ui-1.10.3.custom.min.js"></script>
<link type="text/css" href="css/theme/jquery-ui-1.10.3.custom.min.css" rel="stylesheet"/>
<script src="ui/i18n/jquery.ui.datepicker-ru.js"></script>
<script type="text/javascript" src="uscripts.js"></script>
<link rel="stylesheet" type="text/css" href="css/style.css"/>
</head><body>
<script>$(document).ready(function(){$.get("getupack.php",{},function(r){$('#result').html(r)})});
$(document).on({ajaxStart:function(){$("body").addClass("ldng");},ajaxStop:function(){$("body").removeClass("ldng");},
ajaxTimeout:function(){$("body").removeClass("ldng");}})
</script>
<div id=wrapper>
<div class="mainContent">
<div class="container">
<div class="cellLeft" align=center>
<div>Приветствуем Вас, <br><?php echo strtoupper(substr($user,0,1)).substr($user,1,strlen($user))?></div>
</div>
<div class="cellMiddle"></div>
<div class="cellRight">
	<div align="center" >	
		<?php
		echo '<TABLE align="left" border="0"><tr><td><a href=logout.php>Выход</a></td></tr>';
		echo  '<tr><td>На депозите:</td>';
$color="#6f0";
if ($accsum<10000)
$color="#B00";
echo '<td><div onclick="racc()" id="deposit" style="font-weight:700;color:'.$color.'">'.$accsum."</div></td></tr>";
		echo '<input type=hidden name=uid value="'.$accid.'">';
        echo  "<tr><td>Ваш ID: <b>".$accid."</b>\n"."</td></tr>";
		if ($accdealer!="")
		echo  "<tr><td>"."Ваш дилер: <b>".$accdealer."</b>\n"."</td></tr>";
		echo  "</table>";
		?>
	</div>
</div>
</div>
<div class="secondBlock"><div class="cellLeft2" >
<div id="Menu">
<h2>Меню</h2>
<div class="c_ins">
<table align=center border=0 width=97px>
<tr><td><button id="pbuy" onclick='$.get("getupack.php",{},function(response){$("#result").html(response)});'>ПОКУПКА ПАКЕТА</button></td><tr>
<tr><td><button id="usrlist" onclick="userslog(0,'<?php echo $accid?>')">ИСТОРИЯ ОПЕРАЦИЙ</button></td><tr>
<tr><td><button onclick="idprt()">ИДЕНТЫ И ПОРТЫ</button></td><tr>
<tr><td><button onclick="pfile()">ПРОФИЛЬ</button></td><tr>
<tr><td><button onclick="">НАСТРОЙКИ</button></td><tr>
<tr><td><button onclick="">ПОПОЛНЕНИЕ СЧЁТА</button></td><tr>
</table></div></div>
<div class="blk">
<h2>Бонусы</h2>
<div class="c_ins">Пополняя баланс на<br/>15$ бонус 5%<br/>30$ бонус 10%<br/>50$ бонус 15%<br/>65$ бонус 20%<br/>80$ бонус 25%<br/>100$ бонус 30%<br/>130$ и более бонус 35%<br/>при единовременном платеже</div></div>
</div><div class="cellMiddle2"><div align="center" id="txtHint" width="500"></div>
<div id="result" align="center"></div></div>
<div class="cellRight2"></div>
</div>
<div id="footer">© Metropoliten 2005-2013</div>
</div>
</div>
<div id="profile" style="top:50%;left:50%;display:none">
<table class="FrmLayout" >
<colgroup>
<col style="WIDTH:336px"></col>
</colgroup>
<tbody>
<tr>
<td class="TCell">
<h1>ЛОГИН: <?php echo strtoupper(substr($user,0,1)).substr($user,1,strlen($user))?></h1>
<div>Дата регистрации:<span class="xdTextBox" style="WIDTH:100%"><?php echo $accdreg ?></span>
</div>
<div>Сумма на депозите:<?php echo $accsum ?></div>
<div>
<span>
<ol style="LIST-STYLE-TYPE:none; MARGIN-TOP:0px; MARGIN-BOTTOM:0px">
<li><span style="WIDTH:100%"></span>
</li>
</ol>
</span>
</div>
</td>
</tr>
<tr>
<td>
<div>
<table style="WIDTH:315px;BORDER-COLLAPSE:collapse">
<colgroup>
<col style="WIDTH:212px"></col><col style="WIDTH:91px"></col>
</colgroup>
<tbody vAlign="top">
<tr>
<td vAlign="top" class="TblCellCompnent">
<h4>Пароль:</h4>
<div><span class="xdTextBox" style="WIDTH:186px;HEIGHT:20px"><input type=text id="pwd" value="<?php echo $accpwd?>" onkeyup="$('#ps').prop('disabled',0)"/>
</span>
</div>
</td>
<td class="CllCmpntR">
<h4><input style="MARGIN:1px; WIDTH:81px" class="langFont" value="Сохранить" type="button" onclick="spwdu()" id="ps" disabled/>
</h4>
</td>
</tr>
<tr>
<td vAlign="top" class="TblCellCompnent">
<h4>E-Mail:</h4>
<div><span class="xdTextBox" style="WIDTH:186px;HEIGHT:20px"><input type=text id="eml" value="<?php echo $accemail?>"/>
</span>
</div>
</td>
<td class="CllCmpntR">
<h4><input style="MARGIN:1px; WIDTH:81px" class="langFont" value="Сохранить" type="button" disabled/>
</h4>
</td>
</tr>
<tr >
<td vAlign="top" class="TblCellCompnent">
<h4><span class="lbl">Телефон:</span></h4>
<h4><span class="xdTextBox" style="WIDTH:186px;HEIGHT:20px"><input type=text id="phone" value="<?php echo $accphone ?>"/>
</span>
</h4>
</td>
<td class="CllCmpntR">
<h4>
<span class="lbl"><input style="MARGIN:1px;WIDTH:81px" class="langFont" value="Сохранить" type="button" disabled/>
</span>
</h4>
</td>
</tr>
<tr>

<td class="TblCellCompnent">
<h4>
<div><span class="lbl">Сменить сервер:</span></h4><select style="WIDTH:190px;HEIGHT:23px" >
<option><?php echo $accurl.' - '.$accip ?></option>
</select>
</div>
</td>
<td class="CllCmpntR">
<div><input style="MARGIN:1px;WIDTH:81px" class="langFont" value="Сохранить" type="button" />
</div>
</td>
</tr>
<tr>
<td colSpan="2" vAlign="top" class="TblCellCompnent">
<h4>
<span class="lbl">Примечание:<span class="RTBx" style="WIDTH:280px;HEIGHT:51px"><textarea id="memo" cols="32" maxlength="256"><?php echo $accdscr ?></textarea>
</span>
</span>
</h4>
<h4>
<span class="lbl"><input style="MARGIN:1px; WIDTH:140px" class="langFont" value="Сохранить" type="button" /><input style="MARGIN:1px;WIDTH:144px" class="langFont" value="Отмена" type="button" />
</span>
</h4>
</td>
</tr>
</tbody>
</table>
</div>
</td>
</tr>
</tbody>
</table>
</div>
</body>
</html>
