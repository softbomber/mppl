<?php
include_once("config.php");
 checkLoggedIn("yes");
$accdealer="";
$dealer=$_SESSION['i'];
$user=$_SESSION['l'];
$adm=$_SESSION['a'];
$num_elements=44;
$doru=$_SESSION["d"];
$p=1;
$d=0;
$actv=0;
$active_packet=0;
$active=0;
$now=time();
$desc='ASC';
$tz=$_SESSION['timeZoneOffset'];
//------------------- list start

if (isset($_POST["list"]))
{
    if( (isset($_POST["s"]) && ((int)$_POST["s"])>0))
    {
    if((isset($_POST["s"]) && ((int)$_POST["s"])>0) && (int)$_COOKIE['sort']!=(int)$_POST["s"])
      {
        $srt=(int)$_POST["s"];
        ((int)$_POST["ud"])?$desc="ASC":$desc="DESC";
        $link->query("update dealers set t_srt='$srt' where id='$dealer'");
        setcookie('sort',$srt,time()+3600,'/');
      }
    else
      {
      $link->query("UPDATE dealers set dsc = if(dsc=0,1,0) WHERE id='$dealer' limit 1");
      //((int)$row['dscc'])?$desc="ASC":$desc="DESC";
      }
  }
  $res=$link->query("SELECT t_srt, CASE WHEN dsc=0 THEN 'ASC' ELSE 'DESC' END as dscc from dealers where id='$dealer' limit 1");
      $row = $res->fetch_assoc();
      $srt=$row['t_srt']; //0
      $desc=$row['dscc']; //ASC
//echo 'srt='.$srt." desc=".$desc;
    if(!isset($_POST['page']))
	{$num_pages=1; 
	$p = 1; }
    else
	{
  	$p = addslashes(strip_tags(trim($_POST['page'])));
  	if($p <= 1) $p = 1;
	}
$res=$link->query("SELECT SQL_CALC_FOUND_ROWS * FROM accounts WHERE dealer='$dealer' and deleted='0'");
$num=$res->num_rows;
if($num)
{
$num_pages = ceil($num / $num_elements);
if ($p > $num_pages) $p = $num_pages;
$start = ($p - 1) * $num_elements;
}
else {$start=1;}
//if (isset($_POST["uinfo"]))
   //{
if($_POST['page']==0)
	{
    $res=$link->query("SELECT accounts.id FROM pdates JOIN accounts ON pdates.user_id = accounts.id WHERE accounts.dealer =".$dealer." AND pdates.dend >= NOW()");
    $active=$res->num_rows;
	echo '<div class="box ut-wrap"><div class="ut-info-grid">';
	echo '<div class="ut-info-item"><span class="ut-info-lbl">Общее кол-во</span><span class="ut-info-val">'.$num.'</span></div>';
	echo '<div class="ut-info-item"><span class="ut-info-lbl">Активных</span><span class="ut-info-val">'.$active.'</span></div>';
	echo '<div class="ut-info-item"><span class="ut-info-lbl">Не активных</span><span class="ut-info-val">'.($num-$active).'</span></div>';
	echo '</div></div>';
   }
//	}
$q="SELECT id, user, pwd, dscr, phone,  DATE_FORMAT(dreg,'%d.%m.%y') as dreg,UNIX_TIMESTAMP(dreg) as sdreg, email, paused,pdate,(iptvactdate+(iptvmonths*2592000)) as iptvenddate,iptvusr,sndnote FROM accounts WHERE dealer='$dealer' and deleted='0' ORDER BY 
   $srt  $desc LIMIT $start,$num_elements";  
$res=$link->query($q); //ORDER BY CAST($srt AS UNSIGNED),
  $count=$res->num_rows;
  for ($i=0;$i<$count;$i++)
   $usrlst[$i] = $res->fetch_assoc();

for ($i=0;$i<$count;$i++)
	{
	$usrlid=$usrlst[$i]['id'];
	$res=$link->query("SELECT pdates.dend FROM pdates WHERE user_id='$usrlid' AND pdates.dend >= NOW() LIMIT 1");
   	$haveactivedt[$i]=$res->fetch_assoc();
	}
echo '<div id=lst class="ut-wrap">';
echo '<div class="ut-card"><div class="ut-table-wrap">';
/*if($num_pages>1)
{
echo '<div align=center><table class=nb><td>';
echo NavPan($p, $num_pages,"userlist");
echo '</td></table></div><div align=center>';
}*/
/*if((int)$_POST['page']==0)
{
echo '<script>
$(document).delegate(".ip","click", function(){
if(!$(this).has("input").length){
v=$(this).html();
$(".ip").each(function(){$(this).removeClass().addClass("ne")});
id=this.id;
var i=$("<input/>",{"type":"text","value":v,"width":"70px","id":"psw","name":"psw",
keyup:function(e){if(e.keyCode==27){$("#pswrd").remove();$(".ne").each(function(){$(this).removeClass().addClass("ip")});$("#"+id).html(v)}}});
$(this).empty().append(\'<form class="pswrd" id="pswrd" name="pswrd"></form>\');
vnm=\'Символы `!@#$%^&*()+=-[]\\\';,./{}|":<>? не допустимы\';
str="Минимум 4 символа";';
echo '$("#pswrd").append(i).validate({submitHandler:function(){if(v!=(vv=$("#psw").val()))$.post("reglog.php",{u:id,p:vv});$("#pswrd").remove();$(".ne").each(function(){$(this).removeClass().addClass("ip")});';
echo '$("#"+id).html(vv);},rules:{psw:{vNm:1,required:1,minlength:4,maxlength:33}},messages:{psw:{required:"Введите пароль",minlength:str,vNm:vnm}}});
}});</script>';
}*/

?>
<table class="ut-data" id="usrLst">
    <thead><tr>
        <th style="width:35px" class="text-center">#</th>
        <th style="width:20px"></th>
        <?php
        $sortClass = ($desc === "DESC") ? "ut-sortable ut-sort-desc" : "ut-sortable ut-sort-asc";
        $sortToggle = ($desc === "DESC") ? 1 : 0;
        
        $headers = [
            ['width' => 90, 'text' => 'ЛОГИН', 'sort' => 2],
            ['width' => 75, 'text' => 'СТАТУС'],
            ['width' => 50, 'text' => 'ТЕЛЕФОН', 'sort' => 5],
            ['width' => 75, 'text' => 'КОМЕНТ'],
            ['width' => 45, 'text' => 'РЕГДАТА', 'sort' => 7]
        ];

        foreach ($headers as $header) {
            echo '<th style="width:' . $header['width'] . 'px"';
            if (isset($header['sort'])) {
                echo ' class="ut-sortable' . ($srt == $header['sort'] ? ($desc === 'DESC' ? ' ut-sort-desc' : ' ut-sort-asc') : '') . '"';
                echo ' onclick="userlist(0,' . $header['sort'] . ',' . $sortToggle . ')"';
            }
            echo '>' . $header['text'];
            if (isset($header['sort']) && $srt == $header['sort']) echo '<span class="ut-sort-arrow"></span>';
            echo '</th>';
        }
        ?>
    </tr></thead>
    <tbody>

    <?php
    for ($i = 0; $i < $count; $i++) {
        $rowClass = table_row_format($i, 0);
        $user = $usrlst[$i]['user'];
        $isInactive = ($haveactivedt[$i]['dend'] ?? false) || 
                     ($usrlst[$i]['paused'] ?? 0) == 1 || 
                     ($usrlst[$i]['iptvenddate'] ?? false);
        
//      echo "<tr class='$rowClass' onclick='showDetails(\"$user\", this)' data-email='{$usrlst[$i]['email']}' data-pwd='{$usrlst[$i]['pwd']}' data-cmnt='".restSpaces($usrlst[$i]['dscr'])."' data-ph='{$usrlst[$i]['phone']}' data-snd='{$usrlst[$i]['sndnote']}' data-dreg='{$usrlst[$i]['dreg']}'>";
	        echo "<tr class='$rowClass' data-l='{$user}' data-email='{$usrlst[$i]['email']}' data-pwd='{$usrlst[$i]['pwd']}' data-cmnt='".restSpaces($usrlst[$i]['dscr'])."' data-ph='{$usrlst[$i]['phone']}' data-snd='{$usrlst[$i]['sndnote']}' data-dreg='{$usrlst[$i]['dreg']}'>";
        echo '<td align="center">' . ($start + $i + 1) . '</td>';
        echo '<td>' . ($isInactive ? '' : "<div onclick='udel(\"$user\",this); event.stopPropagation()' class='ui-icon ui-icon-trash'></div>") . '</td>';
        
        echo '<td style="padding-left:6px;position:relative">';
        if ($usrlst[$i]['iptvusr']) echo '<div class="iptvm">I</div>';
        echo '<div class="sharem">S</div><div class="loginm"><a href="#" data-l="'.$user.'">'.$user.'</a></div></td>'; // onclick="getuser(0,\''.$user.'\'); event.stopPropagation();"
//        echo '<div class="sharem">S</div><div class="loginm"><a href="#" onclick="getuser(0, \'' . $user . '\'); event.stopPropagation();">' . $user . '</a></div></td>';
        $status = '<div class="tdcontainer">';
        if ($usrlst[$i]["iptvenddate"] >= $now) {
            $status .= "<div class='iptva tdleft-div'>IPTV:</div><div class='worda tdright-div'>Актив</div><div class='clear'></div>";
        }
        if ($usrlst[$i]['paused']) {
            $status .= "<div class='sata tdleft-div'>SAT:</div><div class='wordp tdright-div'>Пауза</div><div class='clear'></div></div>";
        } elseif ($haveactivedt[$i]['dend'] ?? false) {
            $status .= "<div class='sata tdleft-div'>SAT:</div><div class='worda tdright-div'>Актив</div><div class='clear'></div></div>";
        }
        echo "<td align='center'>$status</td>";
        echo '<td style="padding-right:5px" align="right">' . $usrlst[$i]['phone'] . '</td>';
        
        $desc = str_ireplace(' ', ' ', $usrlst[$i]['dscr']??"");
        $comment = (strlen(trim($desc)) > 100) ? mb_substr($desc, 0, 85, "UTF-8") . '...' : $desc;
	echo '<td>' . restSpaces($comment) . '</td>';
        echo '<td align="center">' . $usrlst[$i]['dreg'] . '</td></tr>';
    }
echo "</tbody></table>";
echo '</div>'; // ut-table-wrap
    $link->close();
if($num_pages>1)
{echo '<div class="ut-pager">';
echo NavPan($p, $num_pages,"userlist");
echo '</div>';}
echo '</div>'; // ut-card
echo '</div>'; // ut-wrap
exit();
}
//------------ end of list  ------

// ------------- uinfo end----- start search
if (isset($_POST["l"]))
{
$user=$link->real_escape_string(trim($_POST["l"]));
	if($adm==1 || $adm==2)
	{
        $res=$link->query("SELECT user,req FROM accounts WHERE user='$user' and deleted='0'") or die("sql error: ".$link->error_list);
	}
	else
	{
        $res=$link->query("SELECT user,req FROM accounts WHERE user='$user' and dealer='$dealer' and deleted='0'") or die("sql error: ".$link->error_list);
	}
   if ($res->num_rows==1)
    {
     $row = $res->fetch_assoc();
	 $user=$row['user'];
     $reqacc=$row['req'];
     }
    else if(ctype_digit($user))
    {
        $qu="SELECT user,phone FROM accounts WHERE phone like '%$user%' and deleted='0'";
        if( $adm != '1' )   $qu .= " and dealer='$dealer'";
        $res=$link->query($qu) or die("sql error: ".$link->error_list);
        $nr=$res->num_rows;
        if ($nr==1)
        {
         $row = $res->fetch_assoc();
         $user=$row['user'];
        }
        else if($nr>1)
        {
            echo '<tr><td colspan=2 align=center class=title>НАЙДЕНО ПО НОМЕРУ ТЕЛЕФОНА</td></tr>';
            while($row = $res->fetch_assoc())
                {
                $htext=hlight_string($row['phone'],$user);
                echo '<tr><td><a href="javascript:getuser(0,\''.$row['user'].'\')">'.$row['user'].'</a></td><td align=right>'.$htext.'</td></tr>';
                }
            exit;
        }
        else
            {echo FALSE;exit;}
    }
    else if(is_string($user))
    {
        $qu="SELECT user,email,phone FROM accounts WHERE (user like '%$user%' or email like '%$user%') and deleted='0' ORDER BY user,email  ";
        if( $adm != '1' )   $qu .= " and dealer='$dealer'";
        $res=$link->query($qu) or die("sql error: ".$link->error_list);
        $nr=$res->num_rows;
        if ($nr==1)
        {
         $row = $res->fetch_assoc();
         $user= $row['user'];
        }
        else if($nr>1)
        {
            echo '<tr><td colspan=2 align=center>НАЙДЕНО ПО EMAIL</td></tr>';
            while($row = $res->fetch_assoc())
                {$htext=hlight_string($row['email'],$user);
                    echo '<tr><td><a href="javascript:getuser(0,\''.$row['user'].'\')">'.$row['user'].'</a></td><td align=right>'.$htext.'</td><td></td></tr>';}
            exit;
        }
        else
            {echo FALSE;exit;}
    }
    else
     {
        echo false;
	 exit;
	}
    if(isset($_POST['sw']))
    {
        ($reqacc)?$reqacc=0:$reqacc=1;
        $link->query("UPDATE accounts set req='$reqacc' where user='$user'") or die("SQL req. error: " . $link->error_list);
    }
 }
 // ------------ l end -------- start


if ((isset($_POST["l"])) || ((isset($_POST['i']) )) || (!$doru))
{
$r=$link->query("SELECT * from accounts where dealer=$dealer and user='$user'");
if (!$r->num_rows && !$_SESSION['a'])
{
exit;
}
$res=$link->query("SELECT id,user,pwd,dealer,sum,dscr,phone,server,paused,UNIX_TIMESTAMP(pdate) as pdt,email,DATE_FORMAT(dreg,'%d.%m.%y %H:%i') as dreg, sndnote,req,acccardnum,tcid,iptvusr 
 	       FROM accounts WHERE user='$user' and deleted='0' limit 1") or die("SQL error: ".$link->error_list);
if ($res->num_rows == 1){
    $row = $res->fetch_assoc();
    $accuser = $row['user'];
    $accpwd = $row['pwd'];
    $accid = $row['id'];
    $dreg = $row['dreg'];
    $accsum = $row['sum'];
    $accdea = $row['dealer'];
    $accdesc = $row['dscr'];
    $phone = $row['phone'];
    $acccardnum= $row['acccardnum'];
    $accserver = $row['server'];
    $email = $row['email'];
    $paused = $row['paused'];
    $pdate = $row['pdt'];
    $sndnote = $row['sndnote'];
    $req = $row['req'];
    $tID=$row['tcid'];
    $isiptv=$row['iptvusr'];
    $res=$link->query("SELECT url,ip FROM server WHERE s_id='$accserver'") or die("SQL error: ".$link->error_list);
    if ($res->num_rows == 1) {
        $row = $res->fetch_assoc();
        $accurl = $row['url'];
        $accip = $row['ip'];
    }
}
   if ($accdea != "") {
    $res=$link->query("SELECT user FROM dealers WHERE id='$accdea'") or die("SQL error: ".$link->error_list);
    if ($res->num_rows == 1) {
        $row = $res->fetch_assoc();
        $accdealer = $row['user'];
    }
}
if ($accdea != "") {
    $res=$link->query("SELECT mindays FROM dealers WHERE id='$dealer'") or die("SQL req. error: " . $link->error_list);
        if ($res->num_rows == 1) {
            $row = $res->fetch_assoc();
            $mindays=$row['mindays'];
        }
    }

echo '<div class="blk finr"><h2>ИНФО ПО <el id="uname" dreg="'.$dreg.'" acccardnum="'.$acccardnum.'">'.$accuser.'</el></h2><TABLE id=tinfo border=0">';
echo '<tr><td align=right>ID:</td><td id="uid">' . $accid . '</td></tr>';
if ($accdealer == $_SESSION['i'] || $_SESSION['a'] || $accdea == $dealer) {
    echo "<tr> <td align=right id=rq rq=".$req.">Учётка:</td><td>"; if($req) echo "Позапросная";else echo "Стандартная";
/*    echo "</td></tr><tr colspan=2><button id=req rq=".(($req)?1:0).' onclick="swreq()">';
    echo "СМЕНИТЬ УЧЁТКУ</button></tr>";*/
    echo "<tr> <td align=right id=accsum>Баланс:</td><td>".round($accsum,2)."</td></tr>";
    if ($_SESSION['a'] == 1)
        echo '<tr><td align=right><input type=hidden name=dlrid value="' . $accdea . '">Дилер:</td><td style="width:250px"><b><span id="dlr">' . $accdealer . '</span><select id="list" style="display:none;font-size:8pt"></select><button id="ok" style="display:none;font-size:4pt">OK</button></b></td></tr>';
    if ($accdealer == $_SESSION['i'] || $_SESSION['a'] == 1 || $accdea == $dealer) {
        echo '<tr><td align=right style="display:none">Пароль:</td><td id=upsw style="display:none;font-weight:700">' . $accpwd . "</td></tr>";
        echo '<tr';
        if (!$phone) echo ' style="display:none"';
        echo '><td align=right>Тел.#:</td><td id=phnm>' . $phone . '</td></tr>';
        echo '<tr';
        if (!$tID) echo ' style="display:none"';
        echo '><td align=right>tId:</td><td id=tID>' . $tID . '</td></tr>';
        echo '<tr';
        if (!$email) echo ' style="display:none"';
        echo '><td align=right>Email:</td><td id=email>' . $email . '</td></tr>';
        $comment = '';
        if ($accdesc) {
            $accdesc = str_ireplace('&nbsp;', ' ', $accdesc);
            (strlen($accdesc) > 16) ? $comment = mb_substr($accdesc, 0, 13, "UTF-8") . '...' : $comment = $accdesc;
        }
        echo '<tr';
        if (!$accdesc) echo ' style="display:none"';
        echo '><td align=right>Комент:</td>';
        echo '<td id=scmnt ';
        //if(strlen($accdesc) > 16)
        echo 'data-tooltip="' . restSpaces($accdesc) . '" data-tooltip-position="left"';
        echo '>';
        echo $comment . '</td></tr>';
    }
    echo "<tr><td align=right>Сервер:</td>";
    echo "<td id=server s='" . $accserver . "'>" . $accurl . "</td></tr>";
if($req)
{
    echo "<tr><td align=right>Баланс:</td><td><b><el id=udeposit>".$accsum."</el></b>";
    echo '<div id="ftrsum" style="display:inline"><button id="ts" onclick="showpay();">+-</button>
<form method="post" id="transferm" name="transferm" enctype="multipart/form-data" style="display:none;position:absolute" onsubmit="return ssum()">
<div class="d-box"><div style="display:inline-flex"> <div id=sign onclick="chs()">+</div><input id="trsum" placeholder="Введите сумму..." name="trsum" class="inp" type="text"/></div><button class="subm" style="width:100%">ПЕРЕВЕСТИ</button></div></form></div></div></td></tr>';
echo '<script>function showpay() {$("#transferm").toggle();}</script>';
echo '<tr><td align=center colspan=2>Остановка при нуле<input type=checkbox id=tozerop> <label class="switcher" for="tozerop"></label></td></tr>';
   }

    echo '<tr><td align=center colspan=2 ><button onclick="accop()">ОПЕРАЦИИ ПО АККАУНТУ</button></td></tr>';
    if ($_SESSION['a'] == 1 || $accdea == $dealer ) {
        echo '<tr><td align=center colspan=2 ><button onclick="ued()">ИЗМЕНИТЬ ДАННЫЕ</button></td></tr>';
        if ($_SESSION['a'] == 1)
            echo '<tr><td align=right><input type=checkbox></td><td>C дилера</td></tr>';
/*             $(document).on('mouseenter', '#udeposit', function () {
                    $(this).find("p").toggle();
                }).on('mouseleave', '#udeposit', function () {
                    $(this).find("p").toggle();
                });*/
    }
    if($isiptv)
        echo '<tr><td align=center colspan=2><button onclick="iptv(\''.$accuser.'\')";>IPTV</button></td></tr>';
}
echo "</table>";
if ($_SESSION['a'] == 1 || $accdea == $dealer) {
    ?>
<table width=60px align=center border=0 id=stps><tr align="center">
<?php
    $stmt = $link->prepare("SELECT UNIX_TIMESTAMP(dend) as dend FROM pdates WHERE user_id = ? AND dend > NOW()");
    $stmt->bind_param("s", $accid);
    $stmt->execute();
    $res = $stmt->get_result();
    $showPauseButton = false;
    if ($res->num_rows > 0) {
        while ($row = $res->fetch_assoc()) {
            $dend = $row['dend'];
            $daysDiff = floor(($dend - time()) / 86400);
            if ($daysDiff > 7) {
                $showPauseButton = true;
                break;
            }
        }
    }
    if ($paused==1 || $showPauseButton) {
        echo '<td><div class="rstp" title="Поставить Аккаунт на паузу. Данная функция доступна раз в неделю!">';
        echo '<div id="pacc" class="ui-icon ui-icon-pause"></div></div></td>';
    }
    ?>
<td><div class='rstp' title="Получить настройки подключения"><div id="rst" class="ui-icon ui-icon-wrench"></div></div></td></tr></table>
<?php
}
/*function chs(){if($("#sign").html()=="+") {$("#sign").html("-");$(".subm").html("СНЯТЬ")} else {$("#sign").html("+");$(".subm").html("ПЕРЕВЕСТИ")}}
function ssum(){
//            $("#deposit").html($("#deposit").html()-$("#trsum").val());
        //$("#udeposit").val()+Number($("#trsum").val());
        mtransf();
    return false;
}*/
?>
<style>
#i {
    width: 95%;
    margin-top: 5px;
    padding: 8px;
    font-size: 14px;
    border: 1px solid #ccc;
    border-radius: 4px;
    box-sizing: border-box;
    transition: border-color 0.3s ease;
}

#i:focus {
    border-color: #007bff;
    outline: none;
    box-shadow: 0 0 5px rgba(0, 123, 255, 0.25);
}

#l {
    width: 95%;
    margin-top: 5px;
    border: 1px solid #ccc;
    border-radius: 4px;
    background-color: #fff;
    overflow: hidden;
}

#l ul {
    list-style: none;
    margin: 0;
    padding: 0;
    max-height: 150px;
    overflow-y: auto;
}

#l li {
    padding: 8px;
    cursor: pointer;
    font-size: 14px;
    transition: background-color 0.3s ease;
}

#l li:hover {
    background-color: #f0f8ff;
}
</style>
    <script type="text/javascript">
        $("select#srv option:eq(<? echo $accserver - 1;?>)").attr('selected', 1);
        <?php if ($sndnote == 1) echo "$('#snd').attr('checked',1);";
        if ($_SESSION['a'] == 1)
        {
        ?>
$(document).ready(function () {
    $("#trsum").on("input blur", function (e) {
    var str = $(this).val(),
        reg = /[\d\.]/,
        str = str.replace(",", ".").replace(/^\./, "0.").replace(/^0(\d)/, "$1"),
        len = 15 < str.length ? 15 : str.length,
        b = 0;
    for (; b < len && reg.test(str.charAt(b)); b++) "." == str.charAt(b) && (reg = /\d/, len = b + 7);
    e.type == "blur" && (str = str.replace(/\.$/, ""))
    $(this).val(str.slice(0, b))
});
    let t, o;
    $("#dlr").click(function () {
        o = $(this).html();
        $("#dlr").hide();
        $("#i").remove();
        $("#l").remove();
        $("<input>").attr({ type: "text", id: "i", placeholder: "Введите имя дилера" }).insertAfter("#dlr");
        $("#i").on("input", function () {
            let v = $(this).val();
            if (v.length < 4) { clearTimeout(t); return; }
            clearTimeout(t);
            t = setTimeout(() => {
                $.ajax({
                    type: "GET", url: "ajax_d.php", data: { query: v }, cache: false, success: r => {
                        $("#l").remove();
                        if (!r.length) return;
                        let s = $("<div>").attr({ id: "l" }).css({ width: "95%", marginTop: "5px" });
                        let u = $("<ul>").css({ listStyle: "none", margin: 0, padding: 0, maxHeight: "150px", overflowY: "auto", border: "1px solid #ccc", borderRadius: "4px" });
                        r.forEach(d => {
                            $("<li>").text(d.name)
                                .data("id", d.id)
                                .css({ padding: "8px", cursor: "pointer", backgroundColor: "#fff" })
                                .hover(
                                    function () { $(this).css("backgroundColor", "#f0f8ff"); },
                                    function () { $(this).css("backgroundColor", "#fff"); }
                                )
                                .appendTo(u);
                        });
                        u.appendTo(s);
                        s.insertAfter("#i");
                        u.on("click", "li", function () {
                            h($(this).data("id"), $(this).text());
                        });
                    }
                });
            }, 400);
        });
        $("#i").on("keydown", e => { if (e.key === "Escape") { $("#dlr").html(o).show(); $("#i").remove(); $("#l").remove(); } });
    });

    function h(i, n) {
        $("#dlr").html(n).show();
        $("#ok").hide();
        $("#i").remove();
        $("#l").remove();
        $.ajax({ type: "POST", url: "ajax_d.php", data: { did: i, uid: $('#uid').html() }, cache: false, success: () => $('input[name="dlrid"]').val(i) });
    }
});
    <?php
}
    echo "</script></div>";
//exit();
//}
$res=$link->query("SELECT t1.id,t1.pname,
                DATE_FORMAT(t2.dend,'%d.%m.%Y %H:%i') as dend,
                DATE_FORMAT(DATE_ADD(t2.dend,INTERVAL 30 DAY),'%d.%m.%Y') as dtto,
                t1.price,t1.sum,t1.paynet,t1.special,t1.olim,DATE_FORMAT(t2.dend,'%d/%m/%Y') as dnd,
                DATE_FORMAT(t2.dstart,'%d.%m.%Y %H:%i') as dstart,
		UNIX_TIMESTAMP(t2.dstart) as dstartunix,
                t2.paused,
                UNIX_TIMESTAMP(t2.dend) as unixt,
                t1.dsbled
                FROM packets AS t1 LEFT JOIN pdates AS t2 ON t2.packet = t1.id AND t2.user_id='$accid' AND (t2.dend>=NOW() or t2.paused=1) where t1.dsbled!=1
                ORDER BY t1.id ASC") or die("SQL error: " . $link->error_list);
    $numr2 = $res->num_rows;
    for ($i = 0; $i < $numr2; $i++)
        $pdts[$i] = $res->fetch_assoc();
    $tmt = ($pdate+7*86400 <= $now || $paused) ? 0 : ($pdate + 7 * 86400) - $now;
    echo '<div id="mc" class=fin>';
    echo '<input type=hidden id="psd" value="'.$paused.'" ptmt="'.$tmt.'">';

if(!$req)
{
    echo '<div class="scrollable"><table class=m border=0> <tr><td><a onclick="sd(1)">1 м</a><a onclick="sd(2)">2 м</a><a onclick="sd(3)">3 м</a><a onclick="sd(4)">4 м</a><a onclick="sd(5)">5 м</a><a onclick="sd(6)">6 м</a><a onclick="sd(7)">7 м</a><a onclick="sd(8)">8 м</a><a onclick="sd(9)">9 м</a><a onclick="sd(10)">10 м</a><a onclick="sd(11)">11 м</a><a onclick="sd(12)">На год</a></td></tr></table></div>';
    echo '<button ';
    if ($paused) echo " style='display:none' ";
    echo 'id="buy2" onclick="buyp()">ОПЛАТИТЬ <span id=tsum2></span></button>';
    echo '<table id="prcst" class="prcst"><thead><th width=40></th><th align=center width=100>ПАКЕТ</th>';
//<?php
//	if ($dealer==$accdea || $adm==1)
    echo '<th width=15 align=center></th>';
    echo '<th width=100 align=center>АКТИВИРОВАН</th><th width=100 align=center>ОТКЛЮЧИТСЯ</th><th width=60 align=center>ЦЕНА</th><th width="67" align=center>ОПЛАТИТЬ до</th></thead>';
    for ($i = 0; $i < $numr2; $i++) {
        if ($pdts[$i]['unixt'] >= $now && $pdts[$i]['dstart'] != "00.00.0000 00:00" && !$pdts[$i]['dsbled'])
            $active_packet = 1;
        else if ($pdts[$i]['dsbled'])
            $active_packet = 2;
        if ($pdts[$i]['paused']) {
            $active_packet = 3;
        }
        $row_class = table_row_format($i, $active_packet);
        if($active_packet==1)
            $row_class =$row_class." selected";
        $active_packet = 0;
        echo '<tr class="' . $row_class . '" id=r' . ($i+1) . '>';
        $t = "";
        if ($pdts[$i]['unixt'] > $now && !$pdts[$i]['dsbled'])
            $t = "checked";
        else if ($pdts[$i]['dsbled'])
            $t = "disabled";
        echo '<td align=center><input ' . $t . " type=\"checkbox\" id='p" . ($i+1) . "' name=\"ptol\" value=\"" . $pdts[$i]['id'] . "\" onclick=\"tOn()\" style=\"width:35px\"><label class=\"switcher\" for=\"p" . ($i+1) . "\"></label></td>";
        echo '<td id="pname' . ($i+1) . '">' . $pdts[$i]['pname'] . "</td>"; // Название пакета
        $t = '><span id="pas' . ($i+1) . '"></span>';

        if ($dealer == $accdea || $adm == 1 || !$doru) {
            $hide = '';
            if ($pdts[$i]['paused'])
                $hide = ' style="display:none"';
            if (($pdts[$i]['unixt'] - 4*86400 ) >= $now)
                echo '<td id="pa' . ($i+1) . '" align=center><div title="Остановить пакет" id="pas' . ($i+1) . '" onclick="stop(' . $pdts[$i]['id'] . ',this,event)" class="rstp stpb"' . $hide . '><span class="ui-icon ui-icon-stop"></span></div></td>';
            else
                echo '<td id="pa' . ($i+1) . '"></td>';
        } else
            echo '<td id="pa' . ($i+1) . '"></td>';
        if (!($pdts[$i]['dstart']) && !($pdts[$i]['dend']) && !($pdts[$i]['paused']))
            echo '<td id="dto' . ($i+1) . '" align=center colspan=2>Не активен</td>';
        else {
            echo '<td id="dtf' . ($i+1) . '" align=center>' . tzDate($pdts[$i]['dstartunix'],$tz,true) . '</td><td id="dto' . ($i+1) . '" align=center>' . tzDate($pdts[$i]['unixt'],$tz,true)  . "</td>"; // Дата начала
        }
        echo '<td align=right id="price' . ($i+1) . '">';
        if ($_SESSION['c'] == 0 && ($_SESSION['a'] == 1 || $_SESSION['a'] == 0 ))
            echo $pdts[$i]['price'] . "</td>"; // Цена пакета
        else if ($_SESSION['c'] == 1 && $_SESSION['a'] == 2)
            echo $pdts[$i]['paynet'] . "</td>"; // Цена пакета
        else if ($_SESSION['c'] == 0 && $_SESSION['a'] == 3)
            echo $pdts[$i]['special'] . "</td>"; // Цена пакета
        else if ($_SESSION['c'] == 0 && $_SESSION['a'] == 4)
            echo $pdts[$i]['special2'] . "</td>"; // Цена пакета
        else if ($_SESSION['c'] == 0 && $_SESSION['a'] == 5)
            echo $pdts[$i]['t'] . "</td>"; // Цена пакета
        else if ($_SESSION['c'] == 0 && $_SESSION['a'] == 6)
            echo $pdts[$i]['tdj'] . "</td>"; // Цена пакета
        else if ($_SESSION['c'] == 0 && $_SESSION['a'] == 7)
            echo $pdts[$i]['trk'] . "</td>"; // Цена пакета
        else if ($_SESSION['c'] == 0 && $_SESSION['a'] == 8)
            echo $pdts[$i]['dollar'] . "</td>"; // Цена пакета
        else if ($_SESSION['c'] == 0 && $_SESSION['a'] == 9)
            echo $pdts[$i]['muha'] . "</td>"; // Цена пакета
        else if ($_SESSION['c'] == 1 && $_SESSION['a'] == 10)
            echo $pdts[$i]['olim'] . "</td>"; // Цена пакета
        else if ($_SESSION['c'] == 1 && $_SESSION['a'] == 11)
            echo $pdts[$i]['borya73'] . "</td>"; // Цена пакета
        else if ($_SESSION['c'] == 1 && $_SESSION['a'] == 14)
            echo $pdts[$i]['zamir'] . "</td>"; // Цена пакета
        else
            echo $pdts[$i]['sum'] . "</td>"; // Цена пакета
        if (!$pdts[$i]['dsbled']) {
            if (!($t = $pdts[$i]['dtto']) || $pdts[$i]['unixt'] < $now) {
                $t = date("d.m.Y", mktime(0, 0, 0, date("m"), date("d") + 30, date("Y")));
            }
//		if ($pdts[$i]['unixt'] >= $now)
//			$actv=1;
            echo '<td align=center><input type="text" value="' . $t . '" id="dtol' . ($i+1) . '" readonly="readonly"></td>';
        } else echo '<td></td>';
        echo "</tr>";
    }
//echo '<tfoot><tr style="font-size:15px;color:#24305F;height:26px"><th></th><th colspan=4 align=left>К оплате:</th><th align=center id="tcst"></th><th></th></tr>';
//echo '<tr><td colspan=7 align=right>';
//echo '</tfoot>';
    echo '</table><button ';
    if ($paused == 1) echo " style='display:none' ";
    echo 'id="buy" onclick="buyp()">ОПЛАТИТЬ <span id=tsum></span></button>';
    echo '<div class="scrollable"><table class=m border=0> <tr><td><a onclick="sd(1)">1 м</a><a onclick="sd(2)">2 м</a><a onclick="sd(3)">3 м</a><a onclick="sd(4)">4 м</a><a onclick="sd(5)">5 м</a><a onclick="sd(6)">6 м</a><a onclick="sd(7)">7 м</a><a onclick="sd(8)">8 м</a><a onclick="sd(9)">9 м</a><a onclick="sd(10)">10 м</a><a onclick="sd(11)">11 м</a><a onclick="sd(12)">На год</a></td></tr></table></div>';

//echo '</td></tr>';
    echo '<script>';
    for ($i = 0; $i < $numr2; $i++) {
        echo '$("#dtol' . ($i+1) . '").datepicker({minDate:';
        if (!$pdts[$i]['dend'] || $pdts[$i]['unixt'] < $now) {
            /*if (!($adm))
                $d = 10; //mindays
            else $d = 1;*/
            $dt = date('d,m,Y', mktime(0, 0, 0, date("m"), date("d") + $mindays, date("Y")));
            $split = explode(",", $dt);
            echo "new Date(" . $split[2] . "," . ($split[1] - 1) . "," . $split[0] . ")";
        } else {
            $split = explode("/", $pdts[$i]['dnd']);
//            if (!($adm))
//                $d = 10; else $d = 1;
            echo "new Date(" . $split[2] . "," . ($split[1] - 1) . "," . $split[0] . "+$mindays)";
        }
        //echo ',';
        echo '});';
    }
    echo '$(document).ready(function (){UC();$(".rw1,.rw2,.ra3,.ra4").on("click",tOn);$("input.hasDatepicker").on("click",function(e){e.stopPropagation();});});</script></div>';
}
else // позапроска
{
/*$res=$link->query("SELECT packets.pname, packets.ncmdport, packets.cs357x, packets.cs378x, packets.cccam, packets.ident
    FROM packets WHERE packets.id !=1 AND packets.dsbled=0 order by packets.id") or die("SQL error: " . $link->error_list);
    $numr2 = $res->num_rows;
    for ($i = 0; $i < $numr2; $i++)
        $pdts[$i] = $$res->fetch_assoc();
    echo '<table class="box" style="margin-left:auto;margin-right:auto;line-height:21px;width:100%" cellspacing=0 id="prcst">
    <thead style="line-height:27px"></th><th align=center>ПАКЕТ</th>';
    echo '<th width=115 align=center>Идент</th>';
    echo '<th width=50 align=center>Newcamd</th>';
    echo '<th width=50 align=center>cs357x</th><th width=50 align=center>cs378x</th><th width=50 align=center>cccam</th></thead>';
    for ($i = 1; $i < $numr2; $i++)
    {
        $row_class = table_row_format($i, $active_packet);
//        $active_packet = 1;
        echo '<tr class=' . $row_class . ' id=r' . ($i) . '>';
        $t = "";
        echo '<td id="pname'.($i).'">'.$i.".   ".$pdts[$i]['pname']."</td>"; // Название пакета
        echo '<td>' . $pdts[$i]['ident'] . "</td>";
        echo '<td>' . $pdts[$i]['ncmdport'] . "</td>";
        if($i==1)
           {echo '<td rowspan='.($numr2-1).'>'.$pdts[$i]['cs357x'] . "</td>";
            echo '<td rowspan='.($numr2-1).'>'.$pdts[$i]['cs378x'] . "</td>";
            echo '<td rowspan='.($numr2-1).'>'.$pdts[$i]['cccam'] . "</td>";
           }
           echo "</tr>";
    }
    echo '</table>';*/
    $rows=0;
    $query="select ident,pname,ncmd FROM caids where disabled=0 order by ncmd ";
    $res=$link->query($query) or die("MySQL query Error: ".$link->error_list);
    $rc=$res->num_rows;
   for ($i = 0; $i < $rc; $i++)
       $packets[$i] = $res->fetch_assoc();
echo '<table class="box" style="margin:auto;line-height:21px;width:100%" cellspacing=0 id="prcst" class="prcst">
    <thead style="line-height:27px"></th><th align=center>ПАКЕТ</th>';
    echo '<th width=115 align=center>Идент</th>';
    echo '<th width=50 align=center>Newcamd</th>';
    echo '<th width=50 align=center>cs357x</th><th width=50 align=center>cs378x</th><th width=50 align=center>cccam</th></thead>';
$i=0;
    for ($i = 0; $i < $rc; $i++)
    {
     $row_class = table_row_format($i, $active_packet);
     echo '<tr class=' . $row_class . ' id=r' . ($i) . '>';
     if((substr($packets[$i]['ident'],0,4))=="0500" && !$rows)
     {
      $rows++;
      do{$rows++;}while((substr($packets[$i+$rows]['ident'],0,4))=="0500");
      echo "<td>".$packets[$i]['pname']."</td><td>".$packets[$i]['ident']."</td><td align=right rowspan=".$rows.">".$packets[$i]['ncmd']."</td>";
      echo "</tr>";

     }
     else
      echo "<td>".$packets[$i]['pname']."</td><td>".$packets[$i]['ident']."</td>";
    if((substr($packets[$i]['ident'],0,4))!="0500")
      echo "<td align=right>".$packets[$i]['ncmd']."</td>";
if(!$i)
        echo "<td align=right rowspan=".$rc." style='background:#dffbf1'>"."10000"."</td>"."<td align=right rowspan=".$rc." style='background:#f7fded'>"."10001"."</td>"."<td align=right rowspan=".$rc." style='background:#fff5f0'>"."40000"."</td>";
      echo "</tr>";
    }
   echo "</table>";

echo "<div id=sstat>";
    echo '<table class="box" style="margin:auto;line-height:21px;width:100%" cellspacing=0>
    <thead style="line-height:12px"><th align=center colspan=4>СТАТИСТИКА ЗАПРОСОВ ПО СЕРВЕРАМ<span id="sstu" class="str" onclick="stu(\'all\')"></span></th></thead>';
    echo '<thead style="line-height:14px"><th align=center>Cервер</th><th colspan=2 align=center>Кол-во запросов</th><th align=center>Дата подключения</th></thead>';
    $res=$link->query("SELECT server.url, cwslog.cwok, cwslog.lastcon, server.s_id FROM server Inner Join cwslog ON cwslog.s_id = server.s_id WHERE cwslog.uid ='$accid' AND hide!=1") or die("SQL error: " . $link->error_list);
    $numr2 = $res->num_rows;
    for ($i = 0; $i < $numr2; $i++)
        $cws[$i] = $res->fetch_assoc();

for ($i = 0; $i < $numr2; $i++)
{
    $row_class = table_row_format($i, $active_packet);
        echo '<tr class='.$row_class.'>';
        echo "<td>".($i+1).".   ".$cws[$i]['url']."</td>";
        echo "<td align=right width=56px id=cw".$cws[$i]['s_id'].">".$cws[$i]['cwok'].'</td><td align=center><button id="tz" title="Обнулить Счётчик" onclick="toz('.$cws[$i]['s_id'].');">>0<</button></td>';
        echo "<td align=center>".$cws[$i]['lastcon'].'</td>';
   echo "</tr>";
}
echo "</table>";
echo "</div>";
echo '</div>';

}
    $rs=$link->query("SELECT pdates.dend FROM pdates WHERE user_id='$accid' AND pdates.dend >= NOW() LIMIT 1");
    $getrows = $rs->num_rows;
    echo '<div id="Layer1" class="fin"';
    if ($getrows==0 || $paused)// && !$req)
        echo ' style="display:none"';
    echo '>';
    echo '<h2>СТАТУС ПОДКЛЮЧЕНИЯ<span id="str" class="str" onclick="stu(\'c\')"></span></h2>';
    echo '<div id="stu">';
    if ($getrows) 
    {
        $sqlr = "SELECT id,server FROM accounts WHERE";
        if ($_SESSION['a'] != 1 && $_SESSION['a'] != 2)
            $sqlr .= " dealer='" . $dealer . "' and";
        $sqlr .= " (deleted='0') and user='" . $user . "'";
//echo $sqlr;
        $res=$link->query($sqlr);
//$count=$res->num_rows;
        $row = $res->fetch_assoc();
        $id = $row['id'];
        $server = $row['server'];
        $res=$link->query("SELECT ip,port FROM server WHERE s_id='$server' LIMIT 1");
        $row = $res->fetch_assoc();
        $ip = $row['ip'];
        $port = $row['port'];
        $url = 'http://'.$ip.':'.$port.'/oscamapi.html?part=userstats&label='.$user;
        //$xml = curl_get_file_contents($url);
        /*if ($xml != false || $xml != '403 Forbidden' || $xml != '')
        {
            $oscam = new SimpleXMLElement($xml);
            if (($oscam->error[0] != 'Invalid client ' . $user) && $oscam->users[0]->user[0]->stats[0]->cwok)
            {
                if ($oscam->users[0]->user[0]->stats[0]->cwok != '')
                {
                    echo '<div>Статус: ' . $oscam->users[0]->user['status'] . "</div>";
                    if (strcmp($oscam->users[0]->user['status'], "offline"))
                        echo 'Текущий канал<div>' . $oscam->users[0]->user[0]->stats[0]->lastchannel . "</div>" . 'IP: ' . $oscam->users[0]->user['ip'] . '<br>Протокол<div>' . $oscam->users[0]->user['protocol'] . '</div>';
                    echo 'DWOK: ' . ($oscam->users[0]->user[0]->stats[0]->cwok + $oscam->users[0]->user[0]->stats[0]->cwcache) . ' DWNOK: ' . ($oscam->users[0]->user[0]->stats[0]->cwtimeout + $oscam->users[0]->user[0]->stats[0]->cwnok);
                }
            }
        }*/

     }
    
   
     $res=$link->query("SELECT card,cid,owner,exp from cardslist where uid=$accid and card!='' and did=0") or die("SQL error: ".$link->error_list);
     $numofcards=$res->num_rows;
     if($numofcards)
      {echo '<div id=Cardslst style="visibility:hidden">';
      for($i=0;$i<$numofcards;$i++)
      $cardslst[$i] = $res->fetch_assoc();
      for($i=0;$i<$numofcards;$i++)
          echo '<div class=crdnm><input  changed=0 id='.$cardslst[$i]['cid'].' type="text" value='.$cardslst[$i]['card'].' tmp='.$cardslst[$i]['card'].' data-owner="'.$cardslst[$i]['owner'].'" data-exp="'.$cardslst[$i]['exp'].'" readonly><el class=rm></el></div>';
      echo "</div>";
      }
      else
      {echo '<div id=Cardslst style="visibility:hidden"></div>';}
    
}

// --------- список пакетов и их цена
if (isset($_POST["price"]))
{
    $res=$link->query("SELECT packets.pname, packets.ident, packets.price, packets.ncmdport, packets.`sum`, packets.paynet, packets.special,
packets.special2, packets.t, packets.tdj, packets.trk, packets.dollar, packets.muha, packets.olim,packets.p FROM packets WHERE packets.dsbled <> '1'
ORDER BY packets.id ASC") or die("SQL error: " . $link->error_list);
       $numr2 = $res->num_rows;
    for ($i = 0; $i < $numr2; $i++)
        $pdts[$i] = $res->fetch_assoc();

echo "<div class=title>ЦЕНА ПАКЕТОВ (Стандартная учётка)</div>";
    echo '<table class="prcst">';
    echo '<thead><th width=70>ПАКЕТ</th>';
    echo '<th width=50 align="center">ЦЕНА</th>';
    echo '<th width=45 align="center">Порт newcamd</th>';
    echo '<th width=250>Идент</th> </thead>';

    for ($i = 0; $i < $numr2; $i++) {
        $row_class = table_row_format($i, $active_packet);
        echo '<tr class='.$row_class.'>';
        echo '<td id="pname' . ($i+1) . '">' . $pdts[$i]['pname'] . "</td>"; // Название пакета
        echo '<td align=right id="price' . ($i+1) . '">';
        if ($_SESSION['c'] == 0 && ($_SESSION['a'] == 1 || $_SESSION['a'] == 0 ))
            echo $pdts[$i]['price'] . "</td>"; // Цена пакета
        else if ($_SESSION['c'] == 1 && $_SESSION['a'] == 2)
            echo $pdts[$i]['paynet'] . "</td>"; // Цена пакета
        else if ($_SESSION['c'] == 0 && $_SESSION['a'] == 3)
            echo $pdts[$i]['special'] . "</td>"; // Цена пакета
        else if ($_SESSION['c'] == 0 && $_SESSION['a'] == 4)
            echo $pdts[$i]['special2'] . "</td>"; // Цена пакета
        else if ($_SESSION['c'] == 0 && $_SESSION['a'] == 5)
            echo $pdts[$i]['t'] . "</td>"; // Цена пакета
        else if ($_SESSION['c'] == 0 && $_SESSION['a'] == 6)
            echo $pdts[$i]['tdj'] . "</td>"; // Цена пакета
        else if ($_SESSION['c'] == 0 && $_SESSION['a'] == 7)
            echo $pdts[$i]['trk'] . "</td>"; // Цена пакета
        else if ($_SESSION['c'] == 0 && $_SESSION['a'] == 8)
            echo $pdts[$i]['dollar'] . "</td>"; // Цена пакета
        else if ($_SESSION['c'] == 0 && $_SESSION['a'] == 9)
            echo $pdts[$i]['muha'] . "</td>"; // Цена пакета
        else if ($_SESSION['c'] == 1 && $_SESSION['a'] == 10)
            echo $pdts[$i]['olim'] . "</td>"; // Цена пакета
        else if ($_SESSION['c'] == 1 && $_SESSION['a'] == 11)
            echo $pdts[$i]['borya73'] . "</td>"; // Цена пакета
        else if ($_SESSION['c'] == 1 && $_SESSION['a'] == 14)
            echo $pdts[$i]['zamir'] . "</td>"; // Цена пакета
        else
            echo $pdts[$i]['sum'] . "</td>"; // Цена пакета
        echo "<td width=20>".$pdts[$i]['ncmdport'] . "</td>";
        echo "<td width=80>".$pdts[$i]['ident'] . "</td>";
        echo "</tr>";
    }
    echo '</table>';
//echo "<div class=title>ЦЕНА ПАКЕТОВ (позапросная учётка) - 0,00045$</div>";
?>
     <div class=title>Порты для просмотра по протоколу camd3xx и Cccam</div>
    <table class="prcst">
        <thead>
        <th width=70> Протокол </th>
        <th width=50 align="center"> Порт </th>
        </thead>
    <tbody style="background: #b2c6db">  
      <tr><td>cs357x/camd35</td><td align="center">10000</td> </tr>
      <tr><td>cs378x</td><td align="center">10001</td></tr>
      <tr><td>cccam</td><td align="center">40000</td></tr>
      </tbody>
    </table>
    <?php
}


$link->close();
//echo '</div>';
//echo '</div>';

function hlight_string($spath,$sstr)
{
    $spath2=$spath;
    $nc=strrpos($spath,$sstr);
           if($nc!==false)
        return $spath2=substr($spath,0,$nc)."<el class=grn>".$sstr."</el>".substr($spath,$nc+(strlen($sstr)));
}
