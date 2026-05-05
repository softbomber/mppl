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

if ((isset($_POST["l"])) || ((isset($_POST['i']) )) || (!$doru))
{
 $u=$_POST["l"];
 $q="SELECT * from accounts where dealer=$dealer and user='$u'";
 $r=$link->query($q);
logQuery("{$r->num_rows} && {$_SESSION['a']}");
if ($r->num_rows==0 && $_SESSION['a']!=1)
    {
    exit;
    }
//    $r="SELECT id,`user`,req, pwd, dealer, sum, dscr, dreg, phone, email, sndnote, tcid, iptvcdn, iptvkey, iptvurl, iptvsdom, iptvactdate,iptvmonths,grpvariant,iptvplaylist,twin FROM accounts
//    WHERE `user` = '$u' AND deleted = '0' LIMIT 1";
$r="SELECT 
    a.id,
    a.`user`,
    a.req,
    a.pwd,
    a.dealer,
    a.sum,
    a.dscr,
    a.dreg,
    a.phone,
    a.email,
    a.sndnote,
    a.tcid,
    a.iptvcdn,
    a.iptvkey,
    a.iptvurl,
    a.iptvsdom,
    a.iptvactdate,
    a.iptvmonths,
    a.grpvariant,
    a.iptvplaylist,
    a.twin,
    a.plname,
    b.`user` AS b_user,
    c.`user` AS c_user,
    dealers.tz
FROM 
    accounts a
LEFT JOIN 
    accounts b ON a.twin = b.id
LEFT JOIN 
    accounts c ON a.id=c.twin
INNER JOIN
	dealers
	ON 
	a.dealer = dealers.id
WHERE 
    a.`user` = '$u' 
    AND a.deleted = '0'
GROUP BY 
    a.id, a.`user`, a.req, a.pwd, a.dealer, a.sum, a.dscr, a.dreg, a.phone, a.email, 
    a.sndnote, a.tcid, a.iptvcdn, a.iptvkey, a.iptvurl, a.iptvsdom, a.iptvactdate,a.plname, 
    a.iptvmonths, a.grpvariant, a.iptvplaylist, a.twin, b.`user`,c.`user`,dealers.tz LIMIT 1;
";
$res=$link->query($r) or die("SQL error: ".$link->error_list);
if ($res->num_rows == 1){
    $row = $res->fetch_assoc();
    $accuser = $row['user'];
    $req = $row['req'];
    $accpwd = $row['pwd'];
    $accsum = $row['sum'];
    $accdea = $row['dealer'];
    $accdesc = $row['dscr'];
    $phone = $row['phone'];
    $email = $row['email'];
    $accid = $row['id'];
    $dreg = $row['dreg'];
    $sndnote = $row['sndnote'];
    $tID=$row['tcid'];
    $iptvcdn=$row['iptvcdn'];
    $iptvkey=$row['iptvkey'];
    $iptvurl=$row['iptvurl'];
    $iptvsdom=$row['iptvsdom'];
    $iptvactdate=$row['iptvactdate'];
    $iptvmonths=$row['iptvmonths'];
    $twin = $row['twin'] ?? 0;
    $plName = $row['plname'] ?? "";
    $twinusr= $row['b_user'];
    $twined= $row['c_user'];
    $plnum=$row['grpvariant'];
    $tz=$row['tz'];
    $grplst=explode(",", $row['iptvplaylist']);
    $agentQuery = "SELECT ad.date,ag.agent,ad.ip FROM agent_dates ad JOIN agents ag ON ad.agent_id = ag.id JOIN accounts ac ON ac.id = ad.account_id WHERE ac.user = '$u'
 		order by ad.date DESC";
    $agentResult = $link->query($agentQuery);
    $agentData = [];
    if ($agentResult && $agentResult->num_rows > 0) {
        while ($row = $agentResult->fetch_assoc()) {
            $agentData[] = $row;
        }
    }

    echo '<div id="mc" class=fin>';   
    ?>
    <style>
    .tabcontent {
        display: none;
        padding-top: 10px;
        padding-bottom: 20px;
        border-radius: 8px;
    }
.active-tab {
 display:block;
 margin-top: 12px;
 margin-bottom: 23px;
 border: 1px solid #284040;
}
.selected {
 background-color: #99ccdd;
}
.custom-select {
 border: 1px solid #ccc;
 padding: 2px;
 max-width: 200px;
 background-color: #b5cfdd;
 cursor: pointer;
 position: relative;
color:#2b3136
}
.custom-option {
    border-bottom: 1px solid #ddd;
    white-space: pre-wrap;
    cursor: pointer;
}
.custom-option:last-child {
    border-bottom: none;
}
.custom-select:hover .custom-option {
    display: block;
}
.custom-option {
    display: none;
}
.custom-option:hover{background:rgb(65 88 110);color:aliceblue}
.list-item{display:inline-flex;cursor:pointer;width:100%}
.list-item:hover{background:black}
.user-element{padding:0 5px}
.wave-effect {position: relative;overflow: hidden;}
.wave-effect::before{content:'';position: absolute;top:0;left:-100%;width:100%;height:100%;background: rgb(76 128 223 / 34%);animation: wave-move 1.5s linear infinite}
@keyframes wave-move { 0% { left: -100%; } 25% { left: 100%; } 50% { left: 100%; } 75% { left: -100%; } 100% { left: -100%; }
}
</style>
<?php
    echo '<div style="display: flex; align-content: center; flex-wrap: wrap; flex-direction:row;justify-content: flex-start; color: #ffffff;">';
    //echo '<div class=iptv> KEY <input id=key type="text" value="'.$iptvkey.'"/><div class=dom>DOM <input id=dom type="text" value="'.$iptvsdom.'"/></div>
//    echo '<div class=iptv>DOM <input id=dom type="text" value="'.$iptvsdom.'"/></div>';
//    $iptvurl="http://pl.mpol.co/p/".$iptvkey.".m3u8";
    $iptvurl = "http://pl.mpol.co/p/" . (!empty($plName) ? $plName : $iptvkey) . ".m3u8";
    echo '<div class="iptv url">URL <input id=url type="text" value="'.$iptvurl.'"/><button  id="copyButton"><img src="/copy.png" alt="Копировать"></button></div>';
    //<button id="sendButton"><img src="/send.png" alt="Отправить"></button></div>';
    if ($_SESSION['a'] == 1)
    {   echo '<div class="spoiler">
        <input type="checkbox" id="spoiler-toggle">
        <label for="spoiler-toggle">+</label>
        <div class="spoiler-content">
<div class="iptv cdn"> КЛЮЧ ДОСТУПА <input id=key type="text" value="'.$iptvkey.'"/>
    <div class=iptvbtn><button id=changekey>СМЕНИТЬ</button></div></div>
         <div class="iptv dom">Субдомен [DOM] <input id=dom type="text" value="'.$iptvsdom.'"/><div class=iptvbtn><button id=savedom disabled=disabled>СОХРАНИТЬ</button></div></div><div id="notification" style="color: red;"></div></div></div>';
    }
    echo '<div class="iptv cdn"><div class=iptvsrv>СЕРВЕР ПОДКЛЮЧЕНИЯ</div><select id="iptvsrv" name="iptvsrv">';
if($iptvcdn)
 {
     $res=$link->query("SELECT locations.option_value, locations.option_text FROM locations where locations.active=1 order by option_text") or die("SQL req. error: ".$link->error_list);
          while ($row = $res->fetch_assoc()) {
                $selected = ($row['option_value'] == $iptvcdn) ? 'selected' : '';
            echo '<option value="' . $row['option_value'] . '" ' . $selected . '>' . $row['option_text'] . '</option>';
        }
  }
 echo "</select><div class=iptvbtn><button id=changecdn disabled=disabled>СМЕНИТЬ</button></div></div>";
 echo '<div class=iptv> <div class=endd>Дата окончания подписки<div><input id=enddate';
 echo ' type="text" value="';
 if($iptvactdate && ($iptvenddate=addMonths($iptvactdate,explode(":",$iptvmonths)[0])) >= time())
 {
//    $server_unix_time = time();
//    $tz_offset_seconds = $tz * 60;
    echo u_time_c($iptvenddate-($tz*60),0,1);
 } 
 else
 {
     echo 'Не активен"';
 }
 
 echo '"/></div></div></div>';

 echo '<div class=iptvcntr> <div class=actbutt><div id="activateButton">';
 if($iptvactdate && $iptvenddate >= time()) 
  {
    echo "ПРОДЛИТЬ ";
  } 
  else
  {
    echo "АКТИВИРОВАТЬ ";
  }
  echo 'ПОДПИСКУ НА </div><div style="display: flex;align-items: center;" onclick="tDrpd()">
 <div class="selnum">1</div>
 <div class="mnths"> МЕС</div>
</div> 
</div>
 <div class="dropdown-content" id="myDropdown">
 <a onclick="sMn(1)">1</a>
 <a onclick="sMn(2)">2</a>
 <a onclick="sMn(3)">3</a>
 <a onclick="sMn(4)">4</a>
 <a onclick="sMn(5)">5</a>
 <a onclick="sMn(6)">6</a>
 <a onclick="sMn(7)">7</a>
 <a onclick="sMn(8)">8</a>
 <a onclick="sMn(9)">9</a>
 <a onclick="sMn(10)">10</a>
 <a onclick="sMn(11)">11</a>
 <a onclick="sMn(12)">12</a>
</div></div>';
 echo '</div>';
}
?>
<div class="playlists" id="40"> <!-- id="playlists"> -->
        <button id="tabbut1" class="tablinks 
        <?php
        if($plnum==1) 
            echo ' acttab';
        ?>
        " onclick="openTab(this.id,'tab1')">Плейлист 1</button>
        <button id="tabbut2" class="tablinks
        <?php
        if($plnum==2) 
            echo ' acttab';
        ?>
        " onclick="openTab(this.id,'tab2')">Плейлист 2</button>
<?php
$res=$link->query("SELECT packets.pname, packets.price, packets.trk, packets.tdj, packets.t, packets.special2, packets.special, packets.sum, packets.paynet, packets.tdjk2, packets.dollar, 
packets.muha, packets.olim, packets.borya73, packets.`user`, packets.rustam FROM packets WHERE packets.id = 40") or die("SQL error: " . $link->error_list);
$n = $res->num_rows;
for ($i = 0; $i < $n; $i++)
    {$pdts[$i] = $res->fetch_assoc();

  echo "<div class=pprc>Пакет ".$pdts[$i]['pname'];
  echo ", стоимость ";
if ($_SESSION['c'] == 0 && ($_SESSION['a'] == 1 || $_SESSION['a'] == 0 ))
            echo $pdts[$i]['price'];
        else if ($_SESSION['c'] == 1 && $_SESSION['a'] == 2)
            echo $pdts[$i]['paynet'];
        else if ($_SESSION['c'] == 0 && $_SESSION['a'] == 3)
            echo $pdts[$i]['special'];
        else if ($_SESSION['c'] == 0 && $_SESSION['a'] == 4)
            echo $pdts[$i]['special2'];
        else if ($_SESSION['c'] == 0 && $_SESSION['a'] == 5)
            echo $pdts[$i]['t'];
        else if ($_SESSION['c'] == 0 && $_SESSION['a'] == 6)
            echo $pdts[$i]['tdj'];
        else if ($_SESSION['c'] == 0 && $_SESSION['a'] == 7)
            echo $pdts[$i]['trk'];
        else if ($_SESSION['c'] == 0 && $_SESSION['a'] == 8)
            echo $pdts[$i]['dollar'];
        else if ($_SESSION['c'] == 0 && $_SESSION['a'] == 9)
            echo $pdts[$i]['muha'];
        else if ($_SESSION['c'] == 1 && $_SESSION['a'] == 10)
            echo $pdts[$i]['olim'];
        else if ($_SESSION['c'] == 1 && $_SESSION['a'] == 11)
            echo $pdts[$i]['borya73'];
        else if ($_SESSION['c'] == 1 && $_SESSION['a'] == 14)
            echo $pdts[$i]['zamir'];
        else
            echo $pdts[$i]['sum'];
    }
    echo  '</div><div id="tab1" class="tabcontent';
    if($plnum==1) 
        echo ' active-tab';
    echo '">';
?>
    <button class="invert-button" onclick="invertSelection(this)">
        Отметить всё | Инвертировать
    </button>
            <ul id="tab1-list">
                <?php
                    $sql1 = "SELECT grpid, grpname FROM subgroups WHERE playlstid = 1";
                    $result1 = $link->query($sql1);
                    while($row = $result1->fetch_assoc()) {
                        $in=0;
                        $grpid=$row['grpid'];
                        if($plnum==1) 
                        {
                            if (in_array($grpid, $grplst))
                                $in=1;
                        }
                        echo "<li onclick='selectListItem(this)' value='" . $grpid . "' " . ($in ? "class='selected'" : "") . ">" . $row['grpname'] . "</li>";
                    }
                ?>
            </ul>
        </div>
<?php
    echo  '<div id="tab2" class="tabcontent';
    if($plnum==2) 
        echo ' active-tab';
    echo '">';
?>
    <button class="invert-button" onclick="invertSelection(this)">
        Отметить всё | Инвертировать
    </button>
            <ul id="tab2-list">
                <?php
                    $sql2 = "SELECT grpid, grpname FROM subgroups WHERE playlstid = 2";
                    $result2 = $link->query($sql2);
                    while($row = $result2->fetch_assoc()) {
                        $in=0;
                        $grpid=$row['grpid'];
                        if($plnum==2) 
                        {
                            if (in_array($grpid, $grplst))
                                $in=1;
                        }
		       echo "<li onclick='selectListItem(this)' value='" . $grpid . "' " . ($in ? "class='selected'" : "") . ">" . $row['grpname'] ."</li>";
                    }
                ?>
            </ul>
        </div>
        <button onclick="saveJSON()">СОХРАНИТЬ</button>
   </div>
    <script>

document.getElementById("copyButton").addEventListener("click", function() {
    var inputField = document.getElementById("url");
    inputField.select();
    document.execCommand("copy");
    hMsg.dMsg("Ссылка на плейлиcт скопирована",1);
});

function invertSelection(button) {
    const tabContent = button.closest('.tabcontent');
    const listItems = tabContent.querySelectorAll('li');

    listItems.forEach(item => {
        if (item.classList.contains('selected')) {
            item.classList.remove('selected');
        } else {
            item.classList.add('selected');
        }
    });
}

$(document).ready(function() {
    $('#reset-btn').click(function() {
        $('input[name="twnid"]').val('');
        $('#twinusr').text('');

        fetch('send_data.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ 
                tw: 0,  // Укажите значение для ключа tw, например '0'
                id: document.getElementById('uid').textContent.trim() // Используйте textContent и trim() для получения значения
            })
        })
        .then(response => response.json())
        .then(data => {
            hMsg.dMsg('Data sent successfully');
        })
        .catch(error => console.error('Error sending data:', error));
    });
});
document.getElementById("activateButton").addEventListener("click", function(e) {
e.stopPropagation();
document.querySelector('.actbutt').classList.add('wave-effect');
var snd=[];
snd=$('.playlists').attr('id');
try {
var dataToSend = {
    uid: $("#uid").html(),
    pb: snd,
    m: document.querySelector(".selnum").innerHTML
};
<?php
if ($_SESSION['a'] == 1) {
    echo 'dataToSend.tw = document.querySelector(\'input[name="twnid"]\').value;';
}
?>
$.ajax({url:"cdc.php", type: "POST",cache: 0,dataType: "json",data: dataToSend}).done(function(r) {
  document.querySelector('.actbutt').classList.remove('wave-effect');
  if (r === "n_a") {se();} else {
      if (r.sum !== undefined && r.e !== undefined) {
          $("#deposit").html(parseFloat(r.sum).toFixed(2));
	  $("#activateButton").text("ПРОДЛИТЬ ПОДПИСКУ НА ");
          $("#enddate").val(mkdt(r.e * 1000, 0, 1));
	  hMsg.dMsg(r.m+" ПАКЕТА ПРОШЛА УСПЕШНО");
      } 
	else if(r.m) {hMsg.dMsg(r.m);}
	else {console.error("Неправильный формат ответа от сервера:",r);}
  }
}).fail(function(jqXHR, textStatus, errorThrown) {
    const cookieA = document.cookie.split('; ').find(row => row.startsWith('a='))?.split('=')[1];
    const cookieI = document.cookie.split('; ').find(row => row.startsWith('i='))?.split('=')[1];

    // Проверяем условия
    if (cookieA === '1' && cookieI === '46') {
        alert(`Ошибка запроса:\nStatus: ${textStatus}\nError: ${errorThrown}\nResponse: ${jqXHR.responseText}`);
    } else {
        console.error("Ошибка запроса:", textStatus, errorThrown);
    }
});

} catch (e) {hMsg.dMsg("ОШИБКА" + e.message);}
});

        function openTab(id,tabName) {
            var tabbuts = document.querySelectorAll('.tablinks');
            for (var i = 0; i < tabbuts.length; i++) {
                tabbuts[i].classList.remove('acttab');
            }
            document.getElementById(id).classList.add('acttab');
            var playlists = document.querySelectorAll('.tabcontent');
            for (var i = 0; i < playlists.length; i++) {
                playlists[i].classList.remove('active-tab');
            }
            document.getElementById(tabName).classList.add('active-tab');
        }
        function selectListItem(item) {
            var isSelected = item.classList.contains('selected');
            if (isSelected) {
                item.classList.remove("selected");
            } else {
                item.classList.add("selected");
            }
            var checkbox = item.querySelector('input[type="checkbox"]');
            checkbox.checked = !checkbox.checked;
        }
        function tDrpd() {
    var drpdwn = document.getElementById("myDropdown");
    drpdwn.classList.toggle("show");
}

function sMn(month) {
    document.querySelector(".selnum").innerHTML = month;
    document.getElementById("myDropdown").style.display = "none";
}
function iptvact(e)
{

}
<?php

if ($_SESSION['a'] == 1) include_once("ascrpts.php");

?>
    </script>

<?php

echo '</div>';
echo '</div>';
echo '<div class="blk finr"><h2>ИНФО ПО <el id="uname" dreg="'.$dreg.'" acccardnum="'.$acccardnum.'">'.$accuser.'</el></h2><TABLE id=tinfo border=0">';
echo '<tr><td align=right style="width:25%">ID:</td><td id="uid">' . $accid . '</td></tr>';
if ($accdea != "") {
    $res=$link->query("SELECT user FROM dealers WHERE id='$accdea'") or die("SQL error: ".$link->error_list);
    if ($res->num_rows == 1) {
        $row = $res->fetch_assoc();
        $accdealer = $row['user'];
    }
if ($accdealer == $_SESSION['i'] || $_SESSION['a'] || $accdea == $dealer) {
    echo "<tr> <td align=right id=rq >Учётка:</td><td>"; echo "IPTV";

    echo "<tr> <td align=right id=accsum>Баланс:</td><td>".round($accsum,2)."</td></tr>";
    if ($_SESSION['a'] == 1)
	{
        echo '<tr><td align=right><input type=hidden name=dlrid value="' . $accdea . '">Дилер:</td><td style="width:250px"><b><span id="dlr">' . $accdealer . '</span><select id="list" style="display:none;font-size:8pt"></select><button id="ok" style="display:none;font-size:4pt">OK</button></b></td></tr>';
/*        if($twin)
	echo '<tr><td align=right><input type=hidden name=twnid value="' . $twin . '">StickedTo:</td><td style="width:250px"><div style="display: flex; align-items: center;"><div id="twinusr">' . $twinusr . '</div><button id="reset-btn" style="background: none; border: none; cursor: pointer; color: red; font-size:14px">✖</button></div></td></tr>';
	else if($twined)
	echo '<tr><td align=right>Sticker:</td><td style="width:250px"><div style="display: flex; align-items: center;"><div id="twined">' . $twined . '</div><button id="reset-btn" style="background: none; border: none; cursor: pointer; color: red; font-size:14px">✖</button></div></td></tr>';		
echo '<tr><td align="right"><input type="hidden" name="agentid" value="' . htmlspecialchars($twin) . '">Agent:</td><td><div class="custom-select">';*/

echo '<tr style="' . (!$twin ? 'display:none;' : '') . '" id="showTw"><td align=right><input type="hidden" name="twnid" value="' . htmlspecialchars($twin ?? '', ENT_QUOTES, 'UTF-8') . '">StickedTo:</td>';
echo '<td style="width:250px">';
echo '<div style="display: flex; align-items: center;">';
echo '<div id="twinusr">'.htmlspecialchars($twinusr ?? '', ENT_QUOTES, 'UTF-8') . '</div>';
echo '<button id="reset-btn" style="background: none; border: none; cursor: pointer; color: red; font-size:14px">✖</button>';
echo '</div></td></tr>';

if ($twined) {
    echo '<tr><td align=right>Sticker:</td>';
    echo '<td style="width:250px">';
    echo '<div style="display: flex; align-items: center;">';
    echo '<div id="twined">' . htmlspecialchars($twined ?? '', ENT_QUOTES, 'UTF-8') . '</div>';
    echo '<button id="reset-btn" style="background: none; border: none; cursor: pointer; color: red; font-size:14px">✖</button>';
    echo '</div></td></tr>';
}
echo '<tr><td align="right"><input type="hidden" name="agentid" value="' . htmlspecialchars($twin ?? '', ENT_QUOTES, 'UTF-8') . '">Agent:</td>';
echo '<td><div class="custom-select">';

/*foreach ($agentArray as $agentId) {
    if (strlen($agentId) > 20) {
        $agentId = preg_replace('/\) /', "\n", $agentId, 1);
    }
    $formattedAgentId = htmlspecialchars($agentId);
    echo '<div class="custom-option">';
    echo nl2br($formattedAgentId);
    echo '</div>';
} */
function parseUserAgent($userAgent) {
    // Массив для хранения частей строки
    $parsedData = [];

    // Проверка на наличие "Model/..." в строке и детальный разбор модели устройства
    if (preg_match('/Model\/([\w\-\.]+) VIDAA\/([\d\.]+)\s*\(([^;]+);([^;]+);([^;]+);(.*?)\)(.*)/', $userAgent, $matches)) {
        $parsedData['Model'] = $matches[1];                          // Модель устройства
        $parsedData['OS Ver'] = $matches[2];                            // Версия ОС VIDAA
        $parsedData['Brand'] = $matches[3];                                 // Бренд (например, TOSHIBA)
        $parsedData['DevType'] = $matches[4];                           // Тип устройства (SmartTV)
        $parsedData['TVModel'] = $matches[5];                              // Модель телевизора
        $parsedData['CPU&Soft Ver'] = $matches[6];        // Процессор и версия ПО
        $parsedData['Other'] = trim($matches[7], ";");              // Дополнительные детали (например, UHD;65C350ME)
    } else {
        // Основные паттерны для разбора строк User-Agent
        $patterns = [
            '/Mozilla\/5\.0 \((.*?)\) (.*)/',                     // Стандартный формат Mozilla
            '/Dalvik\/([\d\.]+) \((.*?)\)/',                      // Формат Dalvik
            '/Wget\/([\d\.]+) \((.*?)\)/',                        // Формат Wget
            '/Player \((.*?)\)/',                                 // Формат Player
            '/OTT TV\/([\d\.]+) \((.*?)\)/',                      // Формат OTT TV
            '/Mozilla\/5\.0 \((.*?)\) Android/',                  // Формат Mozilla на Android
            '/(.*?) \((.*?)\)/',                                  // Общий формат для любых систем (последний)
        ];

        // Проходим по каждому паттерну, чтобы найти совпадение
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $userAgent, $matches)) {
                // Определяем тип и парсим строку
                switch ($pattern) {
                    case '/Mozilla\/5\.0 \((.*?)\) (.*)/':
                        $parsedData['OS'] = $matches[1];
                        $parsedData['Browser'] = $matches[2];
                        break;

                    case '/Dalvik\/([\d\.]+) \((.*?)\)/':
                        $parsedData['Dalvik Ver'] = $matches[1];
                        $parsedData['OS'] = $matches[2];
                        break;

                    case '/Wget\/([\d\.]+) \((.*?)\)/':
                        $parsedData['Wget Ver'] = $matches[1];
                        $parsedData['OS'] = $matches[2];
                        break;

                    case '/Player \((.*?)\)/':
                        $parsedData['OS'] = $matches[1];
                        $parsedData['Player'] = "Generic";
                        break;

                    case '/OTT TV\/([\d\.]+) \((.*?)\)/':
                        $parsedData['OTT TV Ver'] = $matches[1];
                        $parsedData['OS'] = $matches[2];
                        break;

                    case '/Mozilla\/5\.0 \((.*?)\) Android/':
                        $parsedData['OS'] = $matches[1];
                        $parsedData['Type'] = 'Android Device';
                        break;

                    case '/(.*?) \((.*?)\)/':
                        $parsedData['Generic Agent'] = $matches[1];
                        $parsedData['OS'] = $matches[2];
                        break;
                }
                break;
            }
        }
    }

    if (!empty($parsedData)) {
        $output = "";
        foreach ($parsedData as $key => $value) {
            $output .= "$key: $value\n";
        }
        return $output;
    }

    return "User-Agent не удалось распарсить.";
}

foreach ($agentData as $agent) {
    if (strlen($agent['agent']) > 20) {
        $agent['agent'] = parseUserAgent($agent['agent']);//preg_replace('/\) /', "\n", $agent['agent'], 1);
    }
    echo '<div class="custom-option">';
        echo  $agent['agent'] . "<br>D: " . $agent['date'];
	if($agent['ip']) echo  "<br>Prv: " . provFromip($agent['ip']); 		//$agent['ip'] ;
    echo '</div>';
    
}

echo '   </div>
    </td>
</tr>';

	}
    if ($accdealer == $_SESSION['i'] || $_SESSION['a'] == 1 || $accdea == $dealer) {
      //  echo '<tr><td align=right style="display:none">Пароль:</td><td id=upsw style="display:none;font-weight:700">' . $accpwd . "</td></tr>";
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
        echo 'data-tooltip="' . $accdesc . '" data-tooltip-position="left"';
        echo '>';
        echo $comment . '</td></tr>';
    }
    echo '<tr><td align=center colspan=2 ><button onclick="accop()">ОПЕРАЦИИ ПО АККАУНТУ</button></td></tr>';
        if ($_SESSION['a'] == 1)
            echo '<tr><td align=right><input type=checkbox></td><td>C дилера</td></tr>';
/*             $(document).on('mouseenter', '#udeposit', function () {
                    $(this).find("p").toggle();
                }).on('mouseleave', '#udeposit', function () {
                    $(this).find("p").toggle();
                });*/
                echo '<tr><td align=center colspan=2><button onclick="getuser(0,\''.$accuser.'\',\'s\')">ШАРИНГ</button></td></tr>';

    }
}
echo "</table>";
  /*   $res=$link->query("SELECT card,cid,owner,exp from cardslist where uid=$accid and card!='' and did=0") or die("SQL error: ".$link->error_list);
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
      {echo '<div id=Cardslst style="visibility:hidden"></div>';}*/
}

if ($_SESSION['a'] == 1)
{
echo '<button id="openBtn">TWIN</button>
<div id="uLstCntnr">
    <div id="uList"></div>
    <button id="sendBtn" style="display:none" onclick="sendData()">STICK IT</button>
</div>
<div id="twin">
</div>';
 echo '<script>
$(document).ready(function () {
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
        $.ajax({ type: "POST", url: "ajax_d.php", data: { did: i, uid: $("#uid").html()}, cache: false, success: () => $("input[name=\"dlrid\"]").val(i) });
    }
});    </script>';
   }

$link->close();
?>