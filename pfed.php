<?php
include_once("config.php");
checkLoggedIn("yes");
$ps = $eml = $ph = $toph = $wmid = '.';
$row = $accid = $accsum = $accpwd = $accdreg = $acceml = $accph = $rez = '';
$user='';
$demail='';
$dId=0;
$t_fname='';
$t_lname='';
$t_usr='';

if (isset($_POST["get"])) {
   if(isset($_SESSION['l']))	
      $user = $_SESSION['l'];
   if(isset($_SESSION['user_email']))	
      $demail=$_SESSION['user_email'];
   if (isset($_SESSION['t_usr']))
	$t_usr=$_SESSION['t_usr'];
   if (isset($_SESSION['t_fname']))
	$t_fname=$_SESSION['t_fname'];
   if (isset($_SESSION['t_lname']))
	$t_fname=$_SESSION['t_lname'];
    $dId=$_SESSION['d'];
    $res = $link->query("SELECT id,user,sum,a,pwd,DATE_FORMAT(dreg,'%d.%m.%y %H:%i') as dreg,eml,phone,cardnum,t_id,t_fname,t_lname,t_usr,wmid FROM dealers WHERE ((user='$user' or eml='$demail') or (t_fname='$t_fname' or t_lname='$t_lname' 
or t_usr='$t_usr')) and id=$dId") or die("SQL Req. error: " . $link->error_list);
    if ($res->num_rows == 1) {
        $row = $res->fetch_assoc();
        $accid = $row['id'];
        $accsum = $row['sum'];
        $accpwd = $row['pwd'];
        $accdreg = $row['dreg'];
        $acceml = $row['eml'];
        $accph = $row['phone'];
        $cardnum = $row['cardnum'];
        $accwmid = $row['wmid'];
	$t_id = $row['t_id'];
	$t_fname = $row['t_fname'];
	$t_lname = $row['t_lname'];
	$t_usr = $row['t_usr'];
    }
    $did = $_SESSION["i"];
    $res = $link->query("SELECT card,cid,owner,exp from cardslist where did=$did and card!=''") or die("SQL error: " . $link->error_list);
    $numofcards = $res->num_rows;
    for ($i = 0; $i < $numofcards; $i++)
        $cardslst[$i] = $res->fetch_assoc();
?>

<div id="profedit" class="mdl">
    <div class="signin" style="width:85%;max-width:410px">
    <div class="t_subst">
        <div class="title">РЕДАКТИРОВАНИЕ ПРОФИЛЯ</div>
        <div class="clse"></div>
    </div>
        <div class="tabs">
            <div class="tab active" onclick="showTab('main')">Основные</div>
<!--            <div class="tab" onclick="showTab('webmoney')">Webmoney</div> -->
            <div class="tab" onclick="showTab('telegram')">Telegram</div>
        </div>
        <div id="main" class="tab-content active">
<div class="fCont">
<form method="post" id="chpsf" name="chpsf" class="signin">
<label>СТАРЫЙ ПАРОЛЬ</label><input id="op" name="op" type="password">
<label>НОВЫЙ ПАРОЛЬ</label><input id="np" name="np" type="password">
<label>ПОВТОРИТЕ ПАРОЛЬ</label><input id="rp" name="rp" type="password">
<button class="button" id="chpsb">ИЗМЕНИТЬ ПАРОЛЬ</button>
</form>
<form method="post" id="pfedit" name="pfedit" class="signin">
<?php
echo '<label>СПИСОК КАРТ</label>
<div style="border:1px solid #acc4ef;padding:5px;border-radius:5px">
<div class=bubbleslist ccnt='.$numofcards.'>';
if($numofcards)
  {for($i=0;$i<$numofcards;$i++)
  echo '<div class=crdnm><input  changed=0 id='.$cardslst[$i]['cid'].' type="text" value='.$cardslst[$i]['card'].' tmp='.$cardslst[$i]['card'].' data-owner="'.$cardslst[$i]['owner'].'" data-exp="'.$cardslst[$i]['exp'].'" readonly><el class=rm></el></div>';
  }
echo '<div class=butaddcard id="addcards"">+</div></div></div>';
?>
<label>EMAIL</label><input id="eml" name="eml" type="text" value='<?php echo $acceml; ?>'>
<label>НОМЕР ТЕЛЕФОНА</label><input type="text" id="ph" name="ph" size="20" value='<?php echo $accph; ?>'>
<div class="sendto"><input type="checkbox" id="sndtph" name="sndtph">Отправлять оповещения на номер</div>
<div class="clear cell1"><button class="button" type="submit">СОХРАНИТЬ ДАННЫЕ</button></div>
</form>
</div>
</div>
<?php
$res = $link->query("SELECT wmwallets FROM dealers WHERE user = '$_SESSION[l]'") or die($link->error);
$row = $res->fetch_assoc();
$wmz = $wmr = $wme = $wmu = $wmy = '';
$exchange_rates = [];
if ($row['wmwallets']) {
    $purses = explode(',', $row['wmwallets']); // Разделяем строку на массив
    foreach ($purses as $purse) {
        if ($purse) {
            $purse_type = substr($purse, 0, 1);
            $purse_number = substr($purse, 1); // Остальные цифры после первых двух
            switch ($purse_type) {
                case 'Z':
                    $wmz = htmlspecialchars($purse_number);
                    break;
                case 'R':
                    $wmr = htmlspecialchars($purse_number);
                    break;
                case 'E':
                    $wme = htmlspecialchars($purse_number);
                    break;
                case 'U':
                    $wmu = htmlspecialchars($purse_number);
                    break;
                case 'Y':
                    $wmy = htmlspecialchars($purse_number);
                    break;
            }
        }
    }
}
        $sql = "SELECT wmz_ex, wmr_ex, wme_ex, wmu_ex, wmy_ex FROM exchange_rates LIMIT 1";
        $result = $link->query($sql);

        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $exchange_rates['WMZ'] = $row['wmz_ex'];
            $exchange_rates['WMR'] = $row['wmr_ex'];
            $exchange_rates['WME'] = $row['wme_ex'];
            $exchange_rates['WMU'] = $row['wmu_ex'];
            $exchange_rates['WMY'] = $row['wmy_ex'];
        }
 ?>
<!--    <div id="webmoney" class="tab-content">
    <div class="fCont">
        <form method="post" id="webmoneyForm" class="signin">
            <div class="form-group">
            <div class="label_container">
                <label>WMZ кошелёк</label><span class="exchange-rate">Курс: <?php echo $exchange_rates['WMZ']; ?></span>
                </div>
                </div>
                <input type="text" id="WMZ" name="WMZ" maxlength="12" value="<?php echo $wmz; ?>" oninput="validateNumber(this)">
            <div class="form-group">
            <div class="label_container">
                <label>WMR кошелёк</label>
                <span class="exchange-rate">Курс: <?php echo $exchange_rates['WMR']; ?></span>
            </div>
                <input type="text" id="WMR" name="WMR" maxlength="12" value="<?php echo $wmr; ?>" oninput="validateNumber(this)">
            </div>

            <div class="form-group">
            <div class="label_container">
                <label>WME кошелёк</label>
                <span class="exchange-rate">Курс: <?php echo $exchange_rates['WME']; ?></span>
            </div>
                <input type="text" id="WME" name="WME" maxlength="12" value="<?php echo $wme; ?>" oninput="validateNumber(this)">
            </div>

            <div class="form-group">
            <div class="label_container">
                <label>WMU кошелёк</label>
                <span class="exchange-rate">Курс: <?php echo $exchange_rates['WMU']; ?></span>
            </div>
                <input type="text" id="WMU" name="WMU" maxlength="12" value="<?php echo $wmu; ?>" oninput="validateNumber(this)">
            </div>

            <div class="form-group">
            <div class="label_container">
                <label>WMY кошелёк</label>
                <span class="exchange-rate">Курс: <?php echo $exchange_rates['WMY']; ?></span>
            </div>
                <input type="text" id="WMY" name="WMY" maxlength="12" value="<?php echo $wmy; ?>" oninput="validateNumber(this)">
            </div>

            <button type="button" class="button" id="wmSave">Сохранить</button>
        </form>
    </div>
</div> -->
        <div id="telegram" class="tab-content">
        <div class="fCont">
            <form method="post" id="telegramForm" class="signin">
                <label>ID</label>
                <input type="text" id="id" name="id" maxlength="20" oninput="validateText(this)" value="<?php echo $t_id; ?>"><br>
                <label>Username</label>
                <input type="text" id="username" name="username" maxlength="32" oninput="validateText(this)" value="<?php echo $t_usr; ?>"><br>
                <label>First Name</label>
                <input type="text" id="firstName" name="firstName" maxlength="32" oninput="validateText(this)" value="<?php echo $t_fname; ?>"><br>
                <label>Last Name</label>
                <input type="text" id="lastName" name="lastName" maxlength="32" oninput="validateText(this)" value="<?php echo $t_lname; ?>"><br>
                <button type="button" class="button" id="tgSave">Сохранить</button>
            </form>
            </div>
        </div>
    </div>
</div>

<script>
function showTab(tab) {
    document.querySelectorAll('.tab-content').forEach(content => {
        content.classList.remove('active');
    });
    document.querySelectorAll('.tab').forEach(tabElement => {
        tabElement.classList.remove('active');
    });
    document.getElementById(tab).classList.add('active');
    event.target.classList.add('active');
}

function validateNumber(input) {
    input.value = input.value.replace(/[^0-9]/g, '');
    if (input.value.length > 12) {
        input.value = input.value.slice(0, 12);
    }
}

function validateText(input) {
    input.value = input.value.replace(/[^a-zA-Zа-яА-ЯёЁ0-9\s]/g, '');
    if (input.value.length > 20) {
        input.value = input.value.slice(0, 20);
    }
}

$("#pfpsget").validate({});
$("#pfedit").validate({
  submitHandler: function() {
    event.preventDefault();
    pfpass();
    return false;
  },
  focusInvalid: false,
  focusCleanup: true,
  rules: {
    eml: { required: true, email: true },
    wmid: { required: true, digits: true, minlength: 20, maxlength: 20 },
    crdnum: { required: true, digits: true, minlength: 10, maxlength: 16 }
  },
  messages: {
    eml: {
      required: "Введите E-mail!",
      email: "Введите верный e-mail"
    },
    wmid: {
      digits: "Вводите только цифры из вашего WMID",
      minlength: "Минимальное количество цифр 20",
      maxlength: "Мaксимальное количество цифр 20"
    },
    crdnum: {
      required: "Введите номер кредитной карты",
      digits: "Допустимы только цифры",
      minlength: "Минимум 10 цифр",
      maxlength: "Мaкс 16 цифр"
    }
  }
});
$("#chpsf").validate({
submitHandler:function()
{
var arr=$("#chpsf").serializeArray();
var rq=$.ajax({url:"dpch.php",type:"POST",cache:0,dataType:"html",data:arr});
rq.done(function(r){
if(r==1)
{
hMsg.dMsg("ПАРОЛЬ УСПЕШНО СМЕНЁН");
}
else hMsg.dMsg("ВВЕДЁННЫЙ ПАРОЛЬ НЕ СОВПАДАЕТ С ДАННЫМИ ПРОФИЛЯ");});
return false;
},
focusInvalid:0,
focusCleanup:1,
rules:{op:{vNm:1,required:1,minlength:4,maxlength:33,},np:{vNm:1,required:1,minlength:4,maxlength:33,},rp:{vNm:1,required:1,minlength:4,maxlength:33,equalTo:"#np"}},
messages:{op:{required:"Введите текущий пароль",minlength:str,vNm:vnm},np:{required:"Введите новый пароль",minlength:str,vNm:vnm},rp:{required:"Повторите пароль",minlength:str,vNm:vnm,equalTo:"Введите такой же пароль что и выше"},}
});
phms($("#pfedit #ph"));
/*dc.getElementById('wmSave').onclick = function() {
        const wallets = {
        WMZ: document.getElementById('WMZ').value,
        WMR: document.getElementById('WMR').value,
        WME: document.getElementById('WME').value,
        WMU: document.getElementById('WMU').value,
        WMY: document.getElementById('WMY').value,
    };

    for (const key in wallets) {
        if (!wallets[key]) {
            wallets[key] = null; // Устанавливаем значение в null
            document.getElementById(key).value = ''; // Очищаем поле
        } else {
            wallets[key] = key.charAt(2) + wallets[key]; // Добавляем первую букву
        }
    }

    const xhr = new XMLHttpRequest();
    xhr.open("POST", "wmssave.php", true);
    xhr.setRequestHeader("Content-Type", "application/json;charset=UTF-8");
    xhr.onload = function() {
        if (xhr.status >= 200 && xhr.status < 300) {
            console.log('Данные успешно сохранены:', xhr.response);
        } else {
            console.error('Ошибка при сохранении данных:', xhr.response);
        }
    };
    xhr.send(JSON.stringify(wallets));
};*/

document.getElementById('tgSave').addEventListener('click', function() {
    const formData = new FormData(document.getElementById('telegramForm'));
    
    fetch('stlgdata.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
           hMsg.dMsg('Данные успешно сохранены');
        } else {
           hMsg.dMsg('Ошибка при сохранении данных: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Ошибка при выполнении запроса:', error);
    });
});
</script>
<?php
}
if (isset($_POST["pp"]) && (isset($_POST["ph"]) || isset($_POST["eml"]) || isset($_POST["sndtph"]) || isset($_POST["cards"]) || isset($_POST["wmid"]))) {
    {
        $did=$_SESSION["i"];
        $res=$link->query("select pwd from dealers where id=$did limit 1");
        if ($row = $res->fetch_assoc())
        {
            $rop=$row['pwd'];
            $cp=trim($_POST['pp']);
            $parray=array();
            if($cp==$rop)
            {
                if(isset($_POST["wmid"]))
                   $wmid=$link->real_escape_string(trim($_POST["wmid"]));
                if(isset($_POST["eml"]))
                    $eml=$link->real_escape_string(trim($_POST["eml"]));
                if(isset($_POST["ph"]))
                    $ph=$link->real_escape_string(trim($_POST["ph"]));
                if(isset($_POST["sndtph"]))
                    $toph=$link->real_escape_string(trim($_POST["sndtph"]));
                if(isset($_POST["cards"]))
                  {$cards=trim($_POST["cards"]);
                  $parray=cardsinsert(json_decode($cards,1),$did,0,'');
                  }
                  updatedlr('.',$eml,$ph,$toph,$wmid);
                  $parray["success"]=1;
                  echo json_encode($parray);
            }
            else
                echo 0;
        }
    }
   exit();
}
?>
