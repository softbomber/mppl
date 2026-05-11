<?php
include_once("config.php");
 checkLoggedIn("yes");

$user='';
$demail='';
$dId=0;
$dealerId="";
$t_fname='';
$t_lname='';
$t_usr='';

$dId=$_SESSION['d'];


if(isset($_SESSION['user_email']))
    $demail=$_SESSION['user_email'];

if (isset($_SESSION['t_usr']))
	$t_usr=$_SESSION['t_usr'];
if (isset($_SESSION['t_fname']))
	$t_fname=$_SESSION['t_fname'];
if (isset($_SESSION['t_lname']))
	$t_lname=$_SESSION['t_lname'];
if (isset($_SESSION['l']))
        $user=$_SESSION['l'];

if (!empty($t_usr)) {
    $dealerId = $t_usr;
} elseif (!empty($t_fname) && !empty($t_lname)) {
    $dealerId = $t_fname . " " . $t_lname;
} elseif (!empty($t_fname)) {
    $dealerId = $t_fname;
} elseif (!empty($t_lname)) {
    $dealerId = $t_lname;
} else {
    $dealerId = $user;
}
/* if (isset($t_usr)) $dealerId=$t_usr;
 else if (isset($t_fname)) $dealerId=$t_fname;
 else if (isset($user)) $dealerId=$user;*/

$q="SELECT id,user,sum,a,pwd,DATE_FORMAT(dreg,'%d.%m.%y %H:%i') as dreg,eml,phone,fe,postpaid,defserver,currency FROM dealers WHERE ((user='$user' or eml='$demail') or (t_fname='$t_fname' or t_lname='$t_lname' 
or t_usr='$t_usr')) and id=$dId";
file_put_contents("query.log", $q, FILE_APPEND | LOCK_EX);
$res=$link->query($q) or die("SQL Req. error: ".$link->error_list);
if($res->num_rows == 1)
    {$row = $res->fetch_assoc();
     $daccid = $row['id'];
     $accsum = $row['sum'];
     $accpwd = $row['pwd'];
     $accdreg = $row['dreg'];
     $acceml = $row['eml'];
     $accph = $row['phone'];
     $firstenter=$row['fe'];
     $ppd=$row['postpaid'];
     $defserver=$row['defserver'];
     $currency=$row['currency'];
    }
 $res=$link->query("SELECT accounts.id FROM pdates JOIN accounts ON pdates.user_id = accounts.id WHERE accounts.dealer =".$daccid." AND pdates.dend >= NOW()");
 $active=$res->num_rows;
 $intrst=getInterestRate(intval($active));
      $now=time();
?>
<!DOCTYPE html>
<html>
<head>
<meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate" />
<meta http-equiv="cache-control" content="max-age=0" />
<meta http-equiv="Pragma" content="no-cache" />
<meta http-equiv="Expires" content="0" />
<meta http-equiv="Content-Type" content="text/html;charset=UTF-8"/>
<title>Добро пожаловать на Metropoliten</title>
<script src="/jquery-3.7.1.min.js"></script>
<script src="js/jquery.validate.js"></script>
<script src="jquery.inputmask.js"></script>
<script src="jquery.bin-first.js"></script>
<script src="jquery.inputmask-multi.js"></script>
<script src="scripts.js?v=16"></script>
<script src="humanmsg.js"></script>
<script src="js/jquery-ui.min.js"></script>
<link href="https://fonts.googleapis.com/css2?family=PT+Mono&amp;family=Source+Sans+3&amp;display=swap" rel="stylesheet">
<link type="text/css" href="css/theme/jquery-ui.css" rel="stylesheet"/>
<link type="text/css" href="css/pluso_wide_andro7.css?v=5" rel="stylesheet"/>
<link rel="stylesheet" type="text/css" href="css/style_wide_andro7.css?v=7"/>
<link rel="stylesheet" type="text/css" href="css/switcher.css?v=1"/>
<script src="js/ui/i18n/jquery.ui.datepicker-ru.js"></script>
<link rel="stylesheet" type="text/css" href="css/proxima.css"/>
<link rel="stylesheet" type="text/css" href="confirm.css?v=1"/>
<link rel="stylesheet" type="text/css" href="css/app.css?v=3"/>
<?php if($_SESSION['a']) echo '<script src="js/admin.js"></script>';?>
<script src="js/net.js?v=1"   defer></script>
<script src="js/reauth.js?v=1" defer></script>
</head>
<body>
<input type="hidden" id="timeZoneOffsetInput" name="timeZoneOffsetInput">
<div class="popup hidden" id="userDetailsPopup">
<div class="t_subst" ><div class="title">ДАННЫЕ ПО АККАУНТУ <span id=usrinfo></span></div><span class="clse"></span></div>
<form id="userDetailsForm">
<label>Пароль: <input type="password" name="password"><button type="button" class="eye-btn" onclick="togglePassword(this)">🔒</button></label>
<label>Email: <input type="email" name="email"></label>
</form>
</div>
<div id="pfl"></div>
<?php
if ($firstenter) {
    echo '<div class="ppl" id="fed">';
    echo '<div class="v_title">СПАСИБО ЗА ВЫБОР НАШЕГО СЕРВИСА!</div>';
    echo '<p class="gray">На email, указанный вами при регистрации,<br>высланы данные вашего аккаунта<br> А теперь</p>';
    echo '<p class="hlp">1. Создайте новый аккаунт, нажав на соответствующую кнопку слева<br>2. Пополните баланс.<br>3. Введя логин в поле поиска нажмите на лупу и приобретите нужный вам пакет.</p>';
    echo '<p class="enj">ЖЕЛАЕМ ПРИЯТНОГО ВРЕМЯПРЕПРОВОЖДЕНИЯ!</p>';
    echo '<div class="closed"></div>';
    echo '</div>';
    $link->query("update dealers set fe=0 WHERE (user='$user' or eml='$demail') and id=$dId") or die("SQL Req. error: " . $link->error_list);
echo '<script>$(".closed").click(function(){$("#fed").hide(150)});</script>';
}
?>
<!-- <div class=wrapper> --->
<div class="header">
<div class=cellLeft align=center><div>Приветствуем Вас, <?php echo $dealerId ?><br><a href=logout.php>Выход</a></div></div>
<div class=cellMiddle>
<div align=center>
<div id="ss" class="sf">
<div class="sbox">
<select class="sel"><option value=1>ЛОГИН</option><option value=2>Т.НОМЕР</option><option value=3>EMAIL</option><option value=4>ВCЁ</option></select>
<input class="si" placeholder="логин | телефон | email" id=glog></input>
<div class="sb-wrap">
<div class="sb" title="Поиск" type="button" onclick="getuser(0,0)"></div>
<div class="circle hidden"></div>
</div>

</div>
</div>
<div class="s_res-wrap"><table class="s_res"></table></div>
</div> <!-- center end -->

</div>
<div class=cellRight>
<div align=center>
<?php
echo '<TABLE align=center border=0 width="135px">';
echo  '<tr><td align=center class="balance-label">БAЛАНС</td></tr>';
echo '<tr><td align=center onclick="racc()" id="deposit" class="balance-value">';echo sprintf("%.2f",$accsum);echo'</td></tr>';
if ($_SESSION['a'] != 2) {
  echo "<div align=center class='discount-label'>Ваша скидка: <div id='intrst' class='discount-val'> $intrst% </div></div>";
}
  else{
  echo "<div id='intrst' class='hidden'></div>";
}
echo "</table>";
?></div>
</div>
</div>

<div class=mainContent>
<!--<div class="secondBlock"> -->
<div class="cellLeft2" align=center>
<div class="Menu">
<tr><button id="mkusr">СОЗДАТЬ АККАУНТ</button><tr>
<tr><button onclick="userlist()">СПИСОК АККАУНТОВ</button></tr>
<tr><button onclick="loglist()">ИСТОРИЯ ОПЕРАЦИЙ</button></tr>
<tr><button onclick="packetp()">ПАКЕТЫ,ИДЕНТЫ и ЦЕНЫ</button></tr>
<tr><button id="pfedt">ПРОФИЛЬ</button></tr>
<tr><button onclick="bal()">ПОПОЛНЕНИЕ БАЛАНСА</button></tr>
<tr><button onclick="nws()">НОВОСТНОЙ БЛОК</button></tr>
<?php
if ($_SESSION['a'] == 1) {
    $fk_merchant_id = '55712';
    $fk_merchant_key = '=Ww)*xqvXWW[hyP';
    $fk_currency = 'USD';
    $fk_merchant_pl = 16868;
    $fk_email=$acceml;

if (isset($_GET['prepare_once'])) {
    $hash = md5($fk_merchant_id . ':' . $_GET['oa'] . ':' . $fk_merchant_key . ':' . $fk_currency . ':' . $_GET['l']);
    echo '<hash>' . $hash . '</hash>';
    exit;
}
    echo '<tr><button onclick="dlst()">СПИСОК ДИЛЕРОВ</button></tr>
    <tr><button onclick="fkassa()">ПОПОЛНЕНИЕ ЧЕРЕЗ FK</button></tr>';
}
?>
</div>
<div id="fk" class="login-popup popup-center">
<div class="modal-header--fk"><div class=title>ПОПОЛНЕНИЕ БАЛАНСА ЧЕРЕЗ FREEKASSA</div><div class="clse"></div></div>
<div class="tabs" id="tabfkassa">
<div class='fkassa'>
<form id="paymentForm" name="paymentForm"> <!-- onsubmit="openPayment(event)">-->
    <input type="hidden" name="m" value="<?= $fk_merchant_id ?>">
<div class="lbl">СУММА ПОПОЛНЕНИЯ</div>
<div class="fk-currency-wrap">
<input type="text" name="oa" id="sum" onchange="calculate()" onkeyup="calculate()" onfocusout="calculate()" class="required" autocomplete="off">
    <input name="currency" class="fk-currency-input" value="<?= $fk_currency ?>">
</div>
    <input type="hidden" name="s" id="s" value="0">
<div class="lbl">НОМЕР ПЛАТЕЖА</div>
    <input type="text" name="o" id="desc" value="" readonly> 
    <input type="submit" id="submit" value="К ПОПОЛНЕНИЮ" disabled>
</form>
</div>
<div id="fkcntnt">
<iframe id="paymentFrame"></iframe>
</div>
</div>
</div>

<?php
if ($_SESSION['a'] == 1) {
    echo '
    <script>
$.ajaxSetup({
    complete: function(xhr, status) {
        if (xhr.status === 401) {
            hMsg.dMsg("Сессия истекла. Необходима повторная авторизация.");
            setTimeout(function() {
                window.location.href = "/login.php";
            }, 2000); // Задержка 2 секунды
        }
    },
    statusCode: {
        401: function() {
            hMsg.dMsg("Сессия истекла. Необходима повторная авторизация.");
            setTimeout(function() {
                window.location.href = "/login.php";
            }, 2000); // Задержка 2 секунды
        }
    }
});
    document.addEventListener("DOMContentLoaded", function() {
      var fk_merchant_id = "' . $fk_merchant_id . '";
      var fk_currency = "' .$fk_currency . '";
      var fk_email = "' .$fk_email . '";

function generateOpid() {
    let result = "";
    let length = 13;
    let previousChar = "";

    for (let i = 0; i < length; i++) {
        let char;
        do {
            char = Math.floor(Math.random() * 10).toString();
        } while (char === "0" && previousChar === "0");
        result += char;
        previousChar = char;
    }

    if (result.startsWith("0") || result.endsWith("0")) {
        return generateOpid();
    }

    return result;
};
function validateEmail(email) {
    var re = /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/;
    return re.test(email);
}

window.fkassa = function() {
    let opid = generateOpid();
    document.getElementById("desc").value = opid;
    var iframe = document.getElementById("paymentFrame");
    iframe.src = "";
    calculate();
    cntr($("#fk"));
};

window.dlst = function() {
  var xhr = new XMLHttpRequest();
  xhr.open("GET", "dlst.php", true);
  xhr.onreadystatechange = function() {
      if (xhr.readyState === 4 && xhr.status === 200) {
          var rDiv = document.getElementById("result");
          rDiv.innerHTML = xhr.responseText;

          var scripts = rDiv.getElementsByTagName("script");
          for (var i = 0; i < scripts.length; i++) {
              eval(scripts[i].innerHTML);
          }
      }
  };
  xhr.send();
}
$("#paymentForm").validate({
    submitHandler:function(){
    event.preventDefault();
    var sdt=$("#paymentForm").serializeArray();
var params = {};
sdt.forEach(function(item) {
    params[item.name] = item.value;
});

var fk_merchant_id = params["m"];
var sum = params["oa"];
var sign = params["s"];
var fk_currency = params["currency"];
var desc = params["o"];

    var payUrl = "https://pay.freekassa.com/?m=" + fk_merchant_id + "&oa=" + sum + "&s=" + sign + "&currency=" + fk_currency + "&o=" + desc;
    if (fk_email && validateEmail(fk_email)) {
       payUrl += "&em=" + encodeURIComponent(fk_email);
    }
    var frame = document.getElementById("paymentFrame");
    frame.style.display = "block";
    frame.src = payUrl;
    console.log(payUrl);
    return false;
    },
    focusInvalid:0,
    focusCleanup:0,
    rules:{
    oa:{vNm:1,required:1,maxlength:20,},},
    messages:
    {oa:{required:"Введите сумму",},
    }
    });
            window.calculate = function() {
                var re = /[^0-9\\.]/gi;
	    var desc = $("#desc").val();
	    var sum = $("#sum").val();
                var sum = parseFloat(document.getElementById("sum").value.replace(re, "")) || 0;
                var min = 1;
		var submitButton = document.getElementById("submit");
		var error = document.getElementById("error")
/*              if (sum < min) {
                    error.innerHTML = "Введите сумму";
		    error.style.display = "block";
                    //document.getElementById("submit").setAttribute("disabled", "disabled");
		    submitButton.setAttribute("disabled", "disabled");
                    return false;
                } else {
		    submitButton.removeAttribute("disabled");
			error.style.display = "none";
                    document.getElementById("error").innerHTML = "";
                }*/

                var url = window.location.href + "?prepare_once=1&l=" + desc + "&oa=" + sum;
/*              var xhr = new XMLHttpRequest();
                xhr.open("GET", url, true);
                xhr.onreadystatechange = function() {
                    if (xhr.readyState === 4 && xhr.status === 200) {
                        var re_answer = /<hash>([0-9a-z]+)<\/hash>/gi;
                        var match = re_answer.exec(xhr.responseText);
                        if (match) {
                            document.getElementById("s").value = match[1];
                            document.getElementById("submit").removeAttribute("disabled");
                        }
                    }
                };
                xhr.send();*/
    $.get(url, function(data) {
        var re_anwer = /<hash>([0-9a-z]+)<\/hash>/gi;
        var match = re_anwer.exec(data);
        if (match) {
            $("#s").val(match[1]);
            $("#submit").removeAttr("disabled");
        }
    });
            };
        /*    window.openPayment = function(event) {
                event.preventDefault();
                var sum = document.getElementById("sum").value;
                var desc = document.getElementById("desc").value;
                var sign = document.getElementById("s").value;
                var payUrl = "https://pay.freekassa.com/?m=" + fk_merchant_id + "&oa=" + sum + "&s=" + sign + "&currency=" + fk_currency + "&o=" + desc;
                var frame = document.getElementById("paymentFrame");
                frame.style.display = "block";
                frame.src = payUrl;
            };*/
        });
    </script>';
}
?>

<!-- <div class=iblk>
<div class=c_ins>ПОПОЛНЯЯ БАЛАНС<br> -->
     <?php
/*      $res=$link->query("SELECT * FROM bonus") or die("SQL Req. error: ".$link->error_list);
      while ($row = $res->fetch_assoc())
            {
             echo "от $".round($row['from']);
            //if($row['to'] > .01) echo " до $".round($row['to']-1);
             echo ' бонус '.round($row['percent']).'%<br>';
            }*/
    ?>
<!-- при единовременном платеже</div>
</div> -->
</div>
<div class="cellMiddle2">
<div align=center id="txtHint" width="500">

</div>
<div id="result" >
<?PHP
 echo '<div class="nws" id=news>';
 echo '<div class="n_title">НОВОСТНОЙ БЛОК</div>';
 include("cutenews212/show_news.php");
 //include("CuteNews/show_news.php");
 echo "</div>";
?>

</div></div>
<div class="cellRight2">
<div id="uinfo"></div>
</div>
<!--</div>     -->
</div>
<!-- </div> -->
<!-- <div id="footer">© Metropoliten 2005-2023 | <a href="http://">Наш Форум</a><div>ICQ:<em>356362469</em></div></div>-->
<div class="cc hidden">
<div class="clseCC"></div>
<div class="cc__card" data-crdfid=''>
      <form class="cc__form" onsubmit="addCard();return false">
          <fieldset>
              <div class="fieldgroup">
                  <label for="card-number">Номер карты</label>
                  <!-- <input class="cc__card-value cc__card-value--large" id="cardNumber" type="text" tabindex="1" > -->
    <div class=ccnumber><input class="cc__card-value cc__card-value--large" type="text" maxlength="6" id="input1" tabindex="1">
         <div class="cc__card-value--large stars">******</div>
         <input class="cc__card-value cc__card-value--large" type="text" maxlength="4" id="input3" disabled tabindex="2"></div>

              </div>
              <div class="fieldgroup">
                  <label for="cardholder">Владелец карты</label>
                  <input class="cc__card-value" id="cardholder" type="text" tabindex="3">
              </div>
              <div class="fieldgroup fieldgroup--half">
                  <label for="card-exp">Expires</label>
                  <input id="card-exp" type="text" placeholder="MM/YY" tabindex="4">
              </div>
              <input class="button" id="submit-button" type="submit" value="OK" tabindex="5" disabled="disabled">
          </fieldset>
      </form>
      </div>
     
    </div>
    <script>
var offset = new Date().getTimezoneOffset();
document.getElementById('timeZoneOffsetInput').value = offset;

function sendTimezoneOffset() {
    var xhr = new XMLHttpRequest();
    xhr.open("POST", "save_timezone.php", true);
    xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
    var params = "timezoneOffset=" + offset;
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4 && xhr.status === 200) {
            console.log(xhr.responseText); // Для проверки ответа от сервера
        }
    };
    xhr.send(params);
}

window.onload = sendTimezoneOffset;

var form = document.querySelector('.cc__form');
form.addEventListener('input', function() {changed=1});
//dc.getElementById('cardNumber').oninput = function () {
   /*if(this.value.slice(0, 16).length===16)
      {
        this.classList.remove('invalid');
        this.classList.add('valid');
    } else {
        this.classList.remove('valid');
        this.classList.add('invalid');
        }*/
   //this.value = this.value.replace(/[^0-9]/g,'').slice(0,16).replace(/(.{4})/g,'$1 ').trim();
    //chckForm();
//};
    const input1 = document.getElementById('input1');
    const input3 = document.getElementById('input3');

    input1.addEventListener('input', function () {
      const value = this.value;
      const isNumeric = /^\d+$/.test(value);

      if (isNumeric && value.length === 6) {
        input3.removeAttribute('disabled');
        input3.focus();
      } else {
        input3.setAttribute('disabled', 'disabled');
      }
      chckForm();
    });

    input3.addEventListener('input', function () {
      const value = this.value;
      const isNumeric = /^\d+$/.test(value);

      if (!isNumeric || value.length === 0) {
        console.log(value.length);
        input1.focus();
      }
      chckForm();
    });

    input3.addEventListener('keydown', function (e) {
      if (e.key === 'Backspace' && this.value.length === 0) {
        input1.focus();
      }
      chckForm();
    });

dc.getElementById('cardholder').oninput = function () {
    this.value = this.value.replace(/[^A-Za-z0-9 ]/g, '').slice(0, 26).toUpperCase();
    chckForm();
};
  dc.getElementById('card-exp').oninput = function () {
  var cYear = new Date().getFullYear() % 100;
  var cMonth = new Date().getMonth() + 1;
  var nm = this.value.replace(/[^0-9]/gi,'').slice(0, 4);
  var iMonth = parseInt(nm.slice(0, 2));
  if (nm.length === 2) {
    if (iMonth===0 || iMonth>12) {
      this.value = '';
      return;
    }
  }
  if (nm.length===4) {
    var iYear = parseInt(nm.slice(2, 4));
    if (iYear < cYear || iYear === 99 || (iMonth < cMonth && iYear === cYear)) {
      this.value = '';
      return;
    }
    
  }
  if (nm.length >= 2) {
    this.value = nm.replace(/(.{2})/, '$1\/').trim();
  }
  chckForm();
};

 function chckForm() {
  var cardnm1 = dc.getElementById('input1').value.replace(/ /g, '');
  var cardnm3 = dc.getElementById('input3').value.replace(/ /g, '');
    var cardholder = dc.getElementById('cardholder').value;
    var cardExp = dc.getElementById('card-exp').value;

    if ((cardnm1.length === 6 && cardnm3.length === 4) && cardholder &&  cardExp.match(/^\d{2}\/\d{2}$/)) {
        dc.getElementById('submit-button').disabled = false;
    } else {
        dc.getElementById('submit-button').disabled = true;
    }
  }
 
function addCard() {
  var cardnm1 = dc.getElementById('input1').value.replace(/ /g, '');
  var cardnm3 = dc.getElementById('input3').value.replace(/ /g, '');
  var cardNumber=cardnm1+"******"+cardnm3;
  var cardholder = dc.getElementById('cardholder').value;
  var cardExp = dc.getElementById('card-exp').value;
  var f_id = dc.querySelector('.cc').dataset.crdfid;
  formid=dc.querySelector('.cc').dataset.formid;
  var form = dc.getElementById(formid);

  if (form.length)
  {
    if(f_id!=='0') 
    {
    var bubbleslist = form.querySelector('.bubbleslist');
    var inpt = bubbleslist.querySelector('input[id="'+f_id+'"]');
    inpt.value = cardNumber;
    inpt.dataset.owner=cardholder;
    inpt.dataset.exp=cardExp.replace(/\//g,'');
    inpt.setAttribute("changed",changed);
    inpt.style.display="inline-flex";
    }
    else
    {
      if($(form.querySelector('.bubbleslist')).children('input').length<=2)
      $(form.querySelector('.bubbleslist')).append(`<div class=crdnm><input  changed=1 id=0 type="text" value="${cardNumber}" tmp="${cardNumber}" data-owner="${cardholder}" data-exp="${cardExp.replace(/\//g,'')}" readonly><el class="rm"></el></div>`); //.find("input:last").focus();
    }
} else {
    console.error('Элемент не найден');
}
$('.cc').hide();$('#fmask').remove();
  return false;
 
}
</script>

<div id="rgacc" class="mdl">
<form method="post" id="signin" name="signin">
<div class="t_subst"><div class="title">РЕГИСТРАЦИЯ АККАУНТА</div><span class="clse"></span></div>
<div class=signin>
<div class="fCont">
  <div class="reg-row"><label>ЛОГИН</label>
  <div class="reg-iptv-wrap">
  <LABEL>IPTV</LABEL>
  <input class="valign-mid" type="checkbox" id="iptv" name="iptv">
  <label for=iptv class=switcher></label>
  </div>
</div>
 <div>
  <input id="un" name="un" type="text" autocomplete="off" class="required"/>
     <div class='check'>
      <div id="pulsar">
      </div>
     </div>
 </div>
 <div>
  <label class="label--clear">ПАРОЛЬ</label>
  <input id="ps" name="ps" type="password" class="required password"/>
 </div>
 <div>
  <label>СЕРВЕР ПОДКЛЮЧЕНИЯ</label><select id="srv" name='srv'>
        <?php
        if(!$defserver)
     $res=$link->query("SELECT s_id,url,ip,failed FROM server where hide=0") or die("SQL req. error: ".$link->error_list);
  else
      $res=$link->query("SELECT s_id,url,ip,failed FROM server where (s_id=$defserver and hide=1) or (s_id!=$defserver and hide=0)") or die("SQL req. error: ".$link->error_list);
        $rc=$res->num_rows;
        for($i=0;$i<$rc;$i++){$servers[$i]=$res->fetch_assoc();echo "<option value=".$servers[$i]['s_id'].'>'.$servers[$i]['url']." - ".$servers[$i]['ip']."</option>";}
        ?>
 </select>
 </div>
<?PHP
/*if(!$currency)
  echo '<div><input type="checkbox" id="req" name="req"><label for=req class=switcher></label><p style="display:block">Позапросная учётка</p></div>';*/
?>
</div>
<div class="reg-actions">
<button class="submit">ЗАРЕГИСТРИРОВАТЬ</button>
</div>
</div>
</form>
</div>
</div>

<div id="paym" class="login-popup popup-center">
<div class="modal-header"><div class=title>ИНФОРМАЦИЯ О ПЛАТЕЖАХ</div><div class="clse"></div></div>
<!--<p>Для пополнения баланса, переведите сумму на указанные ниже реквизиты для WM в примечании укажите ваш логин,
</br> но перед этим в ПРОФИЛЕ, во вкладке Webmoney укажите ваш Z кошелёк.--><p>Для UZ переводите средства только через PayMe. И уже через 5 минут средства будут зачислены с учётом процентных бонусов.<BR>
</p>

<!-- <div class="tabs" id="uzcrd"><ul class="tabs group">
    <li><a class="active" href="#/webm">Webmoney</a></li>
    <li><a href="#/uzcrd">Карты UzCard</a></li>
  </ul>-->

<div class="tabs" id="webm">
<div class=row>
<?php
$res=$link->query("SELECT name,purse,exch,purse.`desc` FROM purse") or die("SQL req. error: ".$link->error_list);
$rc=$res->num_rows;
for($i=0;$i<$rc;$i++)
    {
    $prs[$i]=$res->fetch_assoc();
    if(strlen($prs[$i]['purse'])>13)
      echo "<el class=p1>Карта ".$prs[$i]['name'];
    else
      echo "<el class=p1>WM";
    echo " ".$prs[$i]['desc'].' '." (1:".$prs[$i]['exch'].') <el class=ps>'.$prs[$i]['purse'].'</el></el>';
    }
?>
</div>
</div>
<div id="cntnt">
<div id=plist class="pluso-list"></div>
</div>
</div>

<div id="rset" class="pluso-box rset--desktop">
<div class="modal-header"><div class="title">НАСТРОЙКИ ПЛАГИНОВ</div><div class="clse"></div></div>
<div class="clear cell1 row"><el class="p1"><select id="tun" onchange="ltuns(this,'pl')"><option>Тюнер</option>
<?php
$res=$link->query("select * from recievers order by rn_id") or die("SQL req. error: ".$link->error_list);
$rc=$res->num_rows;
                for($i=0;$i<$rc;$i++){$rs[$i]=$res->fetch_assoc();echo "<option value=".$rs[$i]['rn_id'].'>'.$rs[$i]['rname']."</option>";}
                ?>
</select></el>
<el class="p1"><select id="pl" disabled="disabled" onchange="ltuns(this,'pr')"><option>Плагин</option></select></el>
<el class="p1"><select id="pr" disabled="disabled" onchange="lrs()"><option>Протокол</option></select></el></div>
<div class="clear cell1"><div id="rsets" class="lst"></div></div>
<el class="p1" id="hlp"></el>
<div class="clear cell1 row">
<el class="p1"><button onclick="stof()">Сохранить в файл</button>
<div id="semail"><button id="bb">Отправить на email</button><form method="post" id="stoemail" name="stoemail">
<div class="pluso-box" id="sedia"><input id="inputeml" name="inputeml" type="text" class="required email">
<button class="submit submit--wide">Отправить</button></div>
</form>
</div>
</el>
</div>
</div>

<div id="classo" class="pluso-box classo--desktop">
<div class="modal-header">
<div class=title>СПИСОК ОПЕРАЦИЙ ПО АККАУНТУ <span id="ullst"></span></div><div class="clse"></div></div>
<div id=ulist class="pluso-list"></div>
</div>

<div id=ued class=mdl>
<form method="post" id="uedit" name="uedit">
<div class="t_subst"><div class="title">РЕДАКТИРОВАНИЕ ДАННЫХ <el id="ue"></el></div><div class="clse"></div></div>
<div class="uedbox">
<div class="clear cell1">
<div class="nfo">Дата регистрации <div id="dr""></div></div></div>
<?php
echo '<div class="text-center">СПИСОК КАРТ</div>
<div class="bubbleslist"></div>';
echo '<div class=butaddcard id="uaddcards">ДОБАВИТЬ КАРТУ</div>';
?>

<div class="clear cell1 lft"><label>Пароль:</label></div><div class="rgt"><input id="psu" name="psu" type="password"></div>
<div class="clear cell1 lft"><label>E-mail:</label></div><div class="rgt"><input id="eml" name="eml" type="email"></div>
<div class="clear cell1 lft"><label>TID:</label></div><input class="rgt" id="tID" name="tID">
<div class="clear cell1 lft"><label>Мобильный #:</label></div><div class="rgt"><input type="text" id="ph" name="ph" size="20">
<div><input type="checkbox" id="snd" name="snd"><label class="switcher" for="snd"></label><el class="sendto">Отправлять оповещения на номер</el></div></div>
<div class="clear cell1"><div class=lft><label>Сервер:</label></div><div class=rgt><select id=srv name=srv>
<?php
if($defserver==0)
$res=$link->query("SELECT s_id,url,ip,failed FROM server where hide=0") or die("SQL req. error: " . $link->error_list);
else
$res=$link->query("SELECT s_id,url,ip,failed FROM server where (s_id=$defserver and hide=1) or (s_id!=$defserver and hide=0)") or die("SQL req. error: " . $link->error_list);
$rc = $res->num_rows;
for ($i = 0; $i < $rc; $i++) {
    $servers[$i] = $res->fetch_assoc();
    echo "<option value=" . $servers[$i]['s_id'] . '>' . $servers[$i]['url'] . " - " . $servers[$i]['ip'] . "</option>";
}
?>
</select></div></div>
<div class="clear cell1"><label>Примечание</label><textarea cols=37 rows=6 id="comment" name="comment"></textarea></div>
<div class="cell1"><button class="button" type="submit">СОХРАНИТЬ</button></div>
</div>
</form></div>
<div class="modal"></div>
<script>
vnm="Символы `!@#$%^&*()+=-[]\\\';,./{}|\\\":<>? пробел и кириллица не допустимы";
str="Минимум 4 символа";
prev='';
$('#mkusr').click(function(){
  var modal = document.getElementById("rgacc");
  modal.style.display = "flex";});

$('#pfedt').click(function(){
  chkfrm($("#pfedit"));
  $(document).ready(function() {
    $('#profedit').css("display","flex");
  });

/*var lB=$(this).attr('href');
var pMT=($(lB).height()+24)/2;
var pML=($(lB).width()+24)/2;
$(lB).css({'margin-top':-pMT,'margin-left':-pML});
$('body').append('<div id="mask"></div>');$('#mask,#pfl').fadeIn(200);return 0*/});
//onclick="pfile();
$(dc).on('click','#mask',function(){$('#mask,.login-popup').fadeOut(200,function(){$('#mask').remove()});return 0});
$(dc).on('click','.clse,.clseCC',function(){$(this).closest('.mdl,.pluso-box, .login-popup, .cc,.popup').hide();$('#mask,#fmask').fadeOut(200,function(){$('#mask,#fmask').remove()}); return 0});
$(document).ready(function(){
$('#glog').keydown(function(e){if(e.keyCode==13){getuser(0,0)}});
$(document).on({ajaxStop:function(){$("body").find("#spin").remove()},
ajaxError:function(){$("body").find("#spin").remove()},
ajaxComplete:function(){$("body").find("#spin").remove()},
ajaxTimeout:function(){$("body").find("#spin").remove()}
});
var lB=$('#firste');
var pMT=($(lB).height()+12)/2;
var pML=($(lB).width()+24)/2;
$(lB).css({'margin-top':-pMT,'margin-left':-pML});
$(lB).show();
$.validator.addMethod('vNm',function(v)
{var rslt=1;var Ch="\`!@#$%^&*()+=[]\\\';,./{}|\\\":<>?"+"абвгдеёжзийклмнопрстуфхцчшщъыьэюяАБВГДЕЁЖЗИЙКЛМНОПРСТУФХЦЧШЩЪЫЬЭЮЯ"+" ";
for (var i=0;i<v.length;i++){if(Ch.indexOf(v.charAt(i))!=-1) return 0;}return rslt;});
$("#stoemail").validate({
submitHandler:function(){var sl,sl1;sl=$("#pl").val();sl1=$("#pr").val();var rq=$.ajax({url:"tuns.php",type:"POST",cache:0,dataType:"html",data:{rs:$("#tun").val(),pl:sl,pr:sl1,u:$("#uname").html(),eml:$("#inputeml").val()}});
rq.success(function(r,a){hMsg.dMsg("ФАЙЛ КОНФИГУРАЦИИ УСПЕШНО ОТПРАВЛЕН");
});
rq.error(function(r){hMsg.dMsg("ПРИ ОТПРАВКЕ E-MAIL ПРОИЗОШЛА ОШИБКА!");});
$('#sedia').toggle(100);
return false;
},
focusInvalid:0,focusCleanup:1,
rules:{inputeml:{required:1,email:1}},
messages:{inputeml:{email:"Введите правильный email"},}
});
$("#signin").validate({
submitHandler:function(){
var sdt=$("#signin").serializeArray();
var rq=$.ajax({url:"reglog.php",type:"POST",cache:0,dataType:"json",data:sdt});
rq.done(function(){$("#signin input[type=text],#signin input[type=password]").val("");hMsg.dMsg("АККАУНТ ЗАРЕГИСТРИРОВАН");});
return false;
},
focusInvalid:0,
focusCleanup:0,
rules:{
un:{vNm:1,required:1,minlength:4,maxlength:20,remote:{url:"cn.php",type:"post"}},
ps:{vNm:1,required:1,minlength:4,maxlength:20,}},
messages:
{un:{required:"Введите логин",minlength:str,vNm:vnm,remote:function(){return "Логин занят";}},
ps:{required:"Введите пароль",minlength:str,vNm:vnm}
}
});
$("#uedit").validate({
submitHandler:function()
{
var arr=[];
if($("#upsw").html()!=$("#uedit #psu").val())
{
$.confirm({'t':'СМЕНА ПАРОЛЯ','m':'ВЫ РЕШИЛИ СМЕНИТЬ ПАРОЛЬ,ВЫ УВЕРЕНЫ?<BR>ЕСЛИ ДА,ТО ТАК ЖЕ НЕ ЗАБУДЬТЕ ПОМЕНЯТЬ ПАРОЛЬ В НАСТРОЙКАХ ПЛАГИНА В ТЮНЕРЕ!','b':{
'ДА':{'class':'blue','action':function(){arr.push({name:'ps',value:$("#uedit #psu").val()});write_users_data(arr);}
},
'НЕТ':{'class':'gray','action':function(){$("#uedit #psu").val($("#upsw").html());write_users_data(arr);}}
}
});
}
else
write_users_data(arr);
},
focusInvalid:0,focusCleanup:1,
rules:{psu:{vNm:1,required:1,minlength:4,maxlength:33,},eml:{email:1},acccrdnum:{digits:1,minlength:10,maxlength:16},},
messages:{psu:{required:"Введите пароль",minlength:str,vNm:vnm},ueml:{email:"Введите правильный email"},acccrdnum:{required:"Введите номер кредитной карты",digits:"Допустимы только цифры",minlength:"Минимум 10 цирф",maxlength:"Мaкс 16 цирф"}}
});
$('#semail #bb').click(function(){if(!$('#rsets').is(':empty')) $('#sedia').toggle(300)});
$.datepicker.setDefaults({dateFormat:"dd.mm.yy",onSelect:function(){UC()},changeMonth:1});
$.datepicker.setDefaults($.datepicker.regional["ru"]);
});
dc.addEventListener('DOMContentLoaded', () => {
  dc.addEventListener('click', (e) => {
    const l = e.target.closest('#usrLst .loginm a');
    if (l) {
      e.stopPropagation();
      const lgn = l.dataset.l;
      getuser(0,lgn);
      return;
    }
    const r=e.target.closest('#usrLst tr[data-l]');
    if (r) {
      const l=r.dataset.l;
      showDetails(l,r);
    }
  });
});
</script>
</div>
</body>
</html>