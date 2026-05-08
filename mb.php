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
      $now=time();
      $res=$link->query("SELECT accounts.id FROM pdates JOIN accounts ON pdates.user_id = accounts.id WHERE accounts.dealer =".$daccid." AND pdates.dend >= NOW()");
      $active=$res->num_rows;
      $intrst=getInterestRate(intval($active));
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate, max-age=0" />
<meta http-equiv="Pragma" content="no-cache" />
<meta http-equiv="Expires" content="0" />
<meta http-equiv="Content-Type" content="text/html;charset=UTF-8"/>
<title>Добро пожаловать на Metropoliten</title>
<script src="/jquery-3.7.1.min.js"></script>
<script src="js/ui/i18n/jquery.ui.datepicker-ru.js"></script>
<script src="js/jquery.validate.js"></script>
<script src="jquery.inputmask.js"></script>
<script src="jquery.bin-first.js"></script>
<script src="jquery.inputmask-multi.js"></script>
<script src="guser.js?v=28"></script>
    <?php if(isset($_SESSION['a']) && $_SESSION['a'] == 1) {echo '<script src="adaptive_admin.js?v=12'; echo "></script>";}?>
<script src="humanmsg.js"></script>
<script src="js/jquery-ui.min.js"></script>
<script src="js/i18n.js?v=3"></script>
<script src="js/listcache.js?v=1"></script>
<script src="js/net.js?v=1"   defer></script>
<script src="js/reauth.js?v=1" defer></script>
<script src="js/pager.js?v=1"  defer></script>
<link href="https://fonts.googleapis.com/css2?family=PT+Mono&amp;family=Source+Sans+3&amp;display=swap" rel="stylesheet">
<link type="text/css" href="css/theme/jquery-ui.css" rel="stylesheet"/>
<link type="text/css" href="css/pluso_wide_andro7.css?v=10" rel="stylesheet"/>
<link rel="stylesheet" type="text/css" href="css/adaptive.css?v=14"/>
<link rel="stylesheet" type="text/css" href="css/switcher.css"/>
<link rel="stylesheet" type="text/css" href="css/proxima.css"/>
<link rel="stylesheet" type="text/css" href="confirm.css?v=1"/>
<link rel="stylesheet" type="text/css" href="css/app.css?v=3"/>
<?php if($_SESSION['a']) echo '<script src="js/admin.js"></script>';?>
</head>
<body>
<input type="hidden" id="timeZoneOffsetInput" name="timeZoneOffsetInput">
<div id="pfl"></div>
<header class="header">
    <div class="header__center">
<div id="ss" class="sf">
<div class="sbox">
<select class="sel">
  <option value=2 data-i18n="search.opt_login">ЛОГИН</option>
  <option value=1 data-i18n="search.opt_all">ВCЁ</option>
  <option value=3 data-i18n="search.opt_phone">Т.НОМЕР</option>
  <option value=4 data-i18n="search.opt_email">EMAIL</option>
</select>
<input class="si" placeholder="логин|телефон|email" id=glog data-i18n-attr="placeholder:search.login_placeholder"></input>
<div style="width:25px">
<div class="sb" title="Поиск" data-i18n-attr="title:search.tooltip" type="button" onclick="getuser(0,0)"></div>
<div class="circle" style="display:none" ></div>
</div>

</div>
</div>
<div style='position:relative'><div class="s_res" style="display: none;">
    <ul class="s_res-list"></ul>
</div></div>
</div> <!-- center end -->
    <!-- <div class="search">
      <input type="text" placeholder="Поиск..." aria-label="Поиск">
      <button class="clear-btn" aria-label="Очистить поиск">✖</button>
    </div>
    <div class="balance"></div>-->
  </header>
 
  <button class="menu-toggle" aria-label="меню" data-i18n-attr="aria-label:menu_btn.aria">☰</button>
  <button class="header-back" aria-label="Назад" style="display:none"></button>
  <button class="info-toggle" aria-label="инфо" data-i18n-attr="aria-label:info_btn.aria">ℹ</button>
  <div class="overlay-menu"></div>
  <div class="overlay-info"></div>
  <nav class="side-menu">
  <span class="side-menu__greeting"><span data-i18n="greeting">Приветcтвуем Вас, </span><span><?php echo $dealerId ?><br></span></span>
    <ul>
      <li><a href="#" id="mkusr" data-i18n="menu.create_account">СОЗДАТЬ АККАУНТ</a></li>
      <li><a href="#" id="userlist" data-i18n="menu.account_list">СПИСОК АККАУНТОВ</a></li>
      <li><a href="#" id="loglist" data-i18n="menu.history">ИСТОРИЯ ОПЕРАЦИЙ</a></li>
      <li><a href="#" id="packetp" data-i18n="menu.packets">ПАКЕТЫ,ИДЕНТЫ и ЦЕНЫ</a></li>
      <li><a href="#" id="pfedt" data-i18n="menu.profile">ПРОФИЛЬ</a></li>
      <li><a href="#" id="bal" data-i18n="menu.balance">ПОПОЛНЕНИЕ БАЛАНСА</a></li>
      <li><a href="#" id="nws" data-i18n="menu.news">НОВОСТНОЙ БЛОК</a></li>
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
    echo '<li><a href="#" id="dlst" data-i18n="menu.dealers">СПИСОК ДИЛЕРОВ</a></li>
    <li><a href="#" id="dlrhst" data-i18n="menu.dealers_history">ОБОРОТКА ПО ДИЛЕРАМ</a></li>
    <li><a href="#" id="fkassa" data-i18n="menu.fk_top_up">ПОПОЛНЕНИЕ ЧЕРЕЗ FK</a></li>
    <li><a href="#" id="uman">МОНИТОР</a></li>';

echo '</div>
<div id="fk" class="login-popup" style="left:50%;top:50%;transform:translate(-50%,-50%)">
<div style="display:flex;background:#465b6e"><div class=title data-i18n="modal.fk_balance">ПОПОЛНЕНИЕ БАЛАНСА ЧЕРЕЗ FREEKASSA</div><div class="clse"></div></div>
<div class="tabs" id="tabfkassa">
<div class="fkassa">
<form id="paymentForm" name="paymentForm"> <!-- onsubmit="openPayment(event)">-->
    <input type="hidden" name="m" value="<?= $fk_merchant_id ?>">
<div class="lbl" data-i18n="form.amount">СУММА ПОПОЛНЕНИЯ</div>
<div style="display:inline-block;position:relative">
<input type="text" name="oa" id="sum" onchange="calculate()" onkeyup="calculate()" onfocusout="calculate()" class="required" autocomplete="off">
    <input name="currency" style="width:30px;background:0;color:aliceblue;position:absolute;right:1px;top:19%;text-align:center" value="<?= $fk_currency ?>">
</div>
    <input type="hidden" name="s" id="s" value="0">
<div class="lbl" data-i18n="form.payment_id">НОМЕР ПЛАТЕЖА</div>
    <input type="text" name="o" id="desc" value="" readonly> 
    <input type="submit" id="submit" value="К ПОПОЛНЕНИЮ" disabled data-i18n-attr="value:form.go_to_payment">
</form>
</div>
<div id="fkcntnt" style="min-height:450px;min-width:450px;display:flex;justify-content:center;align-items:center;width:100%;overflow:hidden;box-sizing:border-box">
<iframe id="paymentFrame" style="width:100%; height:450px; display: none; border: none;"></iframe>
</div>
</div>
</div>';
}
?>

<?php
if ($_SESSION['a'] == 1) {
    echo '
    <script>
    dc.addEventListener("DOMContentLoaded", function() {
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
    dc.getElementById("desc").value = opid;
    var iframe = dc.getElementById("paymentFrame");
    iframe.src = "";
    calculate();
    cntr($("#fk"));
};

window.dlst = function() {
  var xhr = new XMLHttpRequest();
  xhr.open("GET", "dlst.php", true);
  xhr.onreadystatechange = function() {
      if (xhr.readyState === 4 && xhr.status === 200) {
          var rDiv = dc.getElementById("result");
          rDiv.innerHTML = xhr.responseText;

          var scripts = rDiv.getElementsByTagName("script");
          for (var i = 0; i < scripts.length; i++) {
              eval(scripts[i].innerHTML);
          }
      }
  };
  xhr.send();
};

window._umanHeaderSaved = null;
window._umanActive = false;

window.uman = function() {
  var xhr = new XMLHttpRequest();
  xhr.open("GET", "uman.php?embed", true);
  xhr.onreadystatechange = function() {
      if (xhr.readyState === 4 && xhr.status === 200) {
          var tmp = dc.createElement("div");
          tmp.innerHTML = xhr.responseText;

          var tabsEl = tmp.querySelector("#uman-tabs");
          var headerCenter = dc.querySelector(".header__center");
          if (headerCenter && tabsEl) {
              window._umanHeaderSaved = headerCenter.innerHTML;
              headerCenter.innerHTML = "";
              headerCenter.appendChild(tabsEl);
          }

          tabsEl && tabsEl.remove();

          var rDiv = dc.getElementById("result");
          var uinfo = dc.getElementById("uinfo");
          if (uinfo) uinfo.innerHTML = "";

          var styles = tmp.querySelectorAll("style");
          var scripts = tmp.querySelectorAll("script");

          rDiv.innerHTML = "";
          for (var s = 0; s < styles.length; s++) {
              rDiv.appendChild(styles[s]);
          }
          var contentNodes = tmp.childNodes;
          while (contentNodes.length) {
              if (contentNodes[0].tagName === "SCRIPT") {
                  contentNodes[0].remove();
                  continue;
              }
              rDiv.appendChild(contentNodes[0]);
          }

          window._umanActive = true;
          if (typeof MpplPager !== "undefined") MpplPager.setOrigin("uman");

          for (var i = 0; i < scripts.length; i++) {
              var scr = dc.createElement("script");
              scr.textContent = "(function(){" + scripts[i].textContent + "})();";
              rDiv.appendChild(scr);
          }
      }
  };
  xhr.send();
};

window.umanExit = function() {
  if (!window._umanActive) return;
  window._umanActive = false;
  var headerCenter = dc.querySelector(".header__center");
  if (headerCenter && window._umanHeaderSaved !== null) {
      headerCenter.innerHTML = window._umanHeaderSaved;
      window._umanHeaderSaved = null;
  }
  if (typeof MpplPager !== "undefined") MpplPager.setOrigin(null);
};
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
    var frame = dc.getElementById("paymentFrame");
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
                var sum = parseFloat(dc.getElementById("sum").value.replace(re, "")) || 0;
                var min = 1;
                var submitButton = dc.getElementById("submit");
                var error = dc.getElementById("error");
                if (sum < min) {
                    error.textContent = MpplI18n.t(\'validate.enter_sum\');
                    error.style.display = "block";
                    submitButton.setAttribute("disabled", "disabled");
                    return false;
                } else {
                    submitButton.removeAttribute("disabled");
                    error.style.display = "none";
                    error.textContent = "";
                }

                var url = window.location.href + "?prepare_once=1&l=" + desc + "&oa=" + sum;
                $.get(url, function(data) {
                    var re_answer = /<hash>([0-9a-z]+)<\/hash>/gi;
                    var match = re_answer.exec(data);
                    if (match) {
                        $("#s").val(match[1]);
                        $("#submit").removeAttr("disabled");
                    }
                });
            };
            window.openPayment = function(event) {
                event.preventDefault();
                var sum = dc.getElementById("sum").value;
                var desc = dc.getElementById("desc").value;
                var sign = dc.getElementById("s").value;
                var payUrl = "https://pay.freekassa.com/?m=" + fk_merchant_id + "&oa=" + sum + "&s=" + sign + "&currency=" + fk_currency + "&o=" + desc;
                var frame = dc.getElementById("paymentFrame");
                frame.style.display = "block";
                frame.src = payUrl;
            };
        });
        //dc.querySelectorAll(\'.ulnk\').forEach(l=>{l.addEventListener(\'click\',function(e){e.stopPropagation();getuser(0,this.textContent);});});
    </script>';
    $jsCode = <<<EOD
    window.dlrhst = function() {
        var xhr = new XMLHttpRequest();
        xhr.open("GET", "gallh.php", true);
        xhr.onreadystatechange = function() {
            if (xhr.readyState === 4 && xhr.status === 200) {
                var rDiv = dc.getElementById("result");
                if (!rDiv) {
                    return;
                }
                rDiv.innerHTML = xhr.responseText;
    
                var tempDiv = dc.createElement("div");
                tempDiv.innerHTML = xhr.responseText;
                var scripts = tempDiv.getElementsByTagName("script");
                for (var i = 0; i < scripts.length; i++) {
                    var script = dc.createElement("script");
                    script.textContent = scripts[i].textContent;
                    dc.body.appendChild(script);
                    dc.body.removeChild(script);
                }
                initializeForm();
                dc.removeEventListener("submit", formSubmitHandler);
                dc.addEventListener("submit", formSubmitHandler);
            }
        };
        xhr.send();
        return false;
    };
    function initializeForm() {
        function todayStr() {
            var now = new Date();
            now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
            return now.toISOString().split("T")[0];
        }
        var startDateInput = dc.getElementById("start_date");
        var endDateInput = dc.getElementById("end_date");
        if (startDateInput && endDateInput) {
            var today = todayStr();
            // При загрузке: обе даты = текущая
            endDateInput.value = today;
            startDateInput.value = today;
            endDateInput.max = today;
            startDateInput.max = today;

            endDateInput.addEventListener("change", function() {
                // Макс. дата для начальной = конечная дата
                startDateInput.max = endDateInput.value || today;
                // Если начальная > конечной → выровнять
                if (startDateInput.value > endDateInput.value) {
                    startDateInput.value = endDateInput.value;
                }
            });
            startDateInput.addEventListener("change", function() {
                // Если начальная > конечной → выровнять
                if (startDateInput.value > endDateInput.value) {
                    startDateInput.value = endDateInput.value;
                }
            });
        }
        var dealerTimeout;
        var dealerInput = dc.getElementById("dealer");
        var suggestions = dc.getElementById("dealer-suggestions");
        if (dealerInput && suggestions) {
            dealerInput.removeEventListener("input", handleDealerInput);
            dealerInput.addEventListener("input", handleDealerInput);
    
            function handleDealerInput() {
                var query = dealerInput.value.trim();
                if (query.length >= 4) {
                    clearTimeout(dealerTimeout);
                    dealerTimeout = setTimeout(function() {
                        fetch("allhst.php?dealer_search=" + encodeURIComponent(query))
                            .then(function(response) { return response.json(); })
                            .then(function(data) {
                                suggestions.innerHTML = "";
                                if (data.length > 0) {
                                    suggestions.classList.add("show");
                                    data.forEach(function(dealer) {
                                        var div = dc.createElement("div");
                                        div.textContent = dealer;
                                        div.addEventListener("click", function() {
                                            dealerInput.value = dealer;
                                            suggestions.innerHTML = "";
                                            suggestions.classList.remove("show");
                                        });
                                        suggestions.appendChild(div);
                                    });
                                } else {
                                    suggestions.classList.remove("show");
                                }
                            })
                            .catch(function(error) { console.error("Ошибка автодополнения:", error); });
                    }, 300);
                } else {
                    suggestions.classList.remove("show");
                }
            }
        } else {
            console.error("Поля dealer или dealer-suggestions не найдены");
        }
    }
    function formSubmitHandler(event) {
        if (event.target.matches("#date-filter-form")) {
            event.preventDefault();
            var startDate = dc.getElementById("start_date").value;
            var endDate = dc.getElementById("end_date").value;
            var dealer = dc.getElementById("dealer").value;
    
            fetch("allhst.php?start_date=" + encodeURIComponent(startDate) + "&end_date=" + encodeURIComponent(endDate) + "&dealer=" + encodeURIComponent(dealer))
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    var tbody = dc.querySelector("#data-table tbody");
                    tbody.innerHTML = "";
                    data.forEach(function(row) {
                        var tr = dc.createElement("tr");
                        tr.innerHTML = "<td>" + (row.dealer_user || "") + "</td>" +
                                       "<td>" + (row.adesc || "") + "</td>" +
                                       "<td>" + (row.pname ? row.pname : "") + "</td>" +
                                       "<td>" + (row.account_user ? row.account_user : "") + "</td>" +
                                       "<td>" + toDate(row.time) + "</td>" +
                                       "<td>" + (row.account_user ? toDate(row.dend) : "") + "</td>" +
                                       "<td>" + (row.account_user ? row.days : "") + "</td>" +
                                       "<td>" + (row.ost || "") + "</td>" +
                                       "<td>" + (row.sum || "") + "</td>" +
                                       "<td>" + (row.ostafter || "") + "</td>";
                        tbody.appendChild(tr);
                    });
                })
                .catch(function(error) { console.error("Ошибка fetch:", error); });
        }
    }
 EOD;
$jsCode = str_replace("'", "\\'", $jsCode);
echo "<script>\n" . $jsCode . "\n</script>";
}
?>
     <li><a href="logout.php" data-i18n="menu.logout">ВЫХОД</a></li>
    </ul>
    <div class="lang-switch__wrap">
      <div class="lang-switch" role="group" aria-label="Language / Язык">
        <button type="button" class="lang-switch__btn" data-lang="ru" data-i18n="lang.ru">RU</button>
        <button type="button" class="lang-switch__btn" data-lang="en" data-i18n="lang.en">EN</button>
      </div>
    </div>
  </nav>
  <main>
  <div class="u-text-center" id="txtHint"></div>
      <div id="result">
      <?PHP
      echo '<div class="nws" id=news>';
      echo '<div class="n_title">НОВОСТНОЙ БЛОК</div>';
      include("cutenews212/show_news.php");
      echo "</div>";
      ?>
      </div>    
  </main>
    <aside class="info-panel">
    <span><span class="info-panel__label" data-i18n="info.balance">Баланс</span>
    <div class="info-panel__deposit" onclick="racc()" id="deposit"><?php echo sprintf("%.2f",$accsum) ?></div></span>
    <?php
    if ($_SESSION['a'] != 2) {
      echo "<div class='info-panel__discount'><span data-i18n='info.discount'>Ваша скидка:</span> <span id='intrst' class='info-panel__discount-val'> $intrst% </span></div>";
    }
      else{
      echo "<div id='intrst' style='display:none'></div>";
    }
    ?>
    <div id="uinfo"></div>
  </aside>

  <div class="cc" style="display:none">
<div class="clseCC"></div>
<div class="cc__card" data-crdfid=''>
   <form class="cc__form" onsubmit="addCard();return false">
     <fieldset>
        <div class="fieldgroup">
            <label for="card-number" data-i18n="cards.number">Номер карты</label>
             <!-- <input class="cc__card-value cc__card-value--large" id="cardNumber" type="text" tabindex="1" > -->
  <div class=ccnumber><input class=" cc__card-value--large" type="text" maxlength="6" id="input1" tabindex="1">
     <div class="cc__card-value--large stars">******</div>
        <input class="cc__card-value--large" type="text" maxlength="4" id="input3" disabled tabindex="2"></div>
       </div>
      <div class="fieldgroup">
         <label for="cardholder" data-i18n="cards.holder">Владелец карты</label>
         <input class="" id="cardholder" type="text" tabindex="3">
      </div>
      <div class="fieldgroup fieldgroup--half">
         <label for="card-exp" data-i18n="cards.expires">Годен до</label>
         <input id="card-exp" type="text" placeholder="MM/YY" tabindex="4">
      </div>
         <input class="button" id="submit-button" type="submit" value="OK" tabindex="5" disabled="disabled">
      </fieldset>
     </form>
    </div>
  </div>
<script>
var offset = new Date().getTimezoneOffset();
dc.getElementById('timeZoneOffsetInput').value = offset;

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

var form = dc.querySelector('.cc__form');
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
    const input1 = dc.getElementById('input1');
    const input3 = dc.getElementById('input3');

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
      $(form.querySelector('.bubbleslist')).append(`<div class=crdnm><input  changed=1 id=0 type="text" value="${cardNumber}" tmp="${cardNumber}" data-owner="${cardholder}" data-exp="${cardExp.replace(/\//g,'')}" readonly><span class="rm"></span></div>`); //.find("input:last").focus();
    }
} else {
    console.error('Элемент не найден');
}
$('.cc').hide();$('#fmask').remove();
  return false;
}
vnm=MpplI18n.t('validate.bad_chars');
str=MpplI18n.t('validate.min_chars');
prev='';
$('#mkusr').click(function(){var modal = dc.getElementById("rgacc");modal.style.display = "flex";});
$('#pfedt').click(function(){chkfrm($("#pfedit"));$(dc).ready(function() {$('#profedit').css("display","flex");});});
$(dc).on('click','#mask',function(){$('#mask,.login-popup,.popup').fadeOut(200,function(){$('#mask').remove()});return 0});
$(dc).on('click','.clse,.clseCC',function(){$(this).closest('.mdl,.pluso-box,.login-popup,.cc,.popup').hide();$('#mask,#fmask').fadeOut(200,function(){$('#mask,#fmask').remove()}); return 0});
$(dc).ready(function(){
phms($('#uDtails #mph'));
phms($('#uedit #ph'));
$('#glog').keydown(function(e){if(e.keyCode==13){getuser(0,0)}});
$(dc).on({ajaxStop:function(){$("body").find("#spin").remove()},
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
rq.success(function(r,a){hMsg.dMsg(MpplI18n.t('msg.config_sent'));
});
rq.error(function(r){hMsg.dMsg(MpplI18n.t('msg.email_error'));});
$('#sedia').toggle(100);
return false;
},
focusInvalid:0,focusCleanup:1,
rules:{inputeml:{required:1,email:1}},
messages:{inputeml:{email:MpplI18n.t('validate.valid_email')}}
});
$("#signin").validate({
submitHandler:function(){
var sdt=$("#signin").serializeArray();
var rq=$.ajax({url:"reglog.php",type:"POST",cache:0,dataType:"json",data:sdt});
rq.done(function(){$("#signin input[type=text],#signin input[type=password]").val("");hMsg.dMsg(MpplI18n.t('msg.account_registered'));});
return false;
},
focusInvalid:0,
focusCleanup:0,
rules:{
un:{vNm:1,required:1,minlength:4,maxlength:20,remote:{url:"cn.php",type:"post"}},
ps:{vNm:1,required:1,minlength:4,maxlength:20,}},
messages:
{un:{required:MpplI18n.t('validate.enter_login'),minlength:str,vNm:vnm,remote:function(){return MpplI18n.t('validate.login_taken');}},
ps:{required:MpplI18n.t('validate.enter_password'),minlength:str,vNm:vnm}
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

$("#uDtails").validate({
    submitHandler:function(form,event)
    {
      event.preventDefault();
      console.log("checking");
    var arr=[];
    if(currentRow.dataset.pwd!=$("#uDtails #passu").val())
    {
    $.confirm({'t':'СМЕНА ПАРОЛЯ','m':'ВЫ РЕШИЛИ СМЕНИТЬ ПАРОЛЬ,ВЫ УВЕРЕНЫ?<BR>ЕСЛИ ДА,ТО ТАК ЖЕ НЕ ЗАБУДЬТЕ ПОМЕНЯТЬ ПАРОЛЬ В НАСТРОЙКАХ ПЛАГИНА В ТЮНЕРЕ!','b':{
    'ДА':{'class':'blue','action':function(){arr.push({name:'ps',value:$("#uDtails #passu").val()});wuserdta(currentRow,arr);}
    },
    'НЕТ':{'class':'gray','action':function(){$("#uDtails #passu").val($("#upsw").html());wuserdta(currentRow,arr);}}
    }
    });
    }
    else
    wuserdta(currentRow,arr);
    },
    focusInvalid:0,focusCleanup:1,
    rules:{passu:{vNm:1,required:1,minlength:4,maxlength:33,},e_ml:{email:1},acccrdnum:{digits:1,minlength:10,maxlength:16},},
    messages:{passu:{required:"Введите пароль",minlength:str,vNm:vnm},e_ml:{email:"Введите правильный email"},acccrdnum:{required:"Введите номер кредитной карты",digits:"Допустимы только цифры",minlength:"Минимум 10 цирф",maxlength:"Мaкс 16 цирф"}}
    });

$('#semail #bb').click(function(){if(!$('#rsets').is(':empty')) $('#sedia').toggle(300)});
$.datepicker.setDefaults({dateFormat:"dd.mm.yy",onSelect:function(){UC()},changeMonth:1});
$.datepicker.setDefaults($.datepicker.regional["ru"]);
});
<?php
if ($_SESSION['a'] == 1) {
echo '$(document).ready(function() {
    $(\'#reset-btn\').click(function() {
        $(\'input[name="twnid"]\').val(\'\');
        $(\'#twinusr\').text(\'\');
        fetch(\'send_data.php\', {
            method: \'POST\',
            headers: {
                \'Content-Type\': \'application/json\'
            },
            body: JSON.stringify({ 
                tw: 0,
                id: dc.getElementById(\'uid\').textContent.trim()
            })
        })
        .then(response => response.json())
        .then(data => {
            hMsg.dMsg(\'Data sent successfully\');
        })
        .catch(error => console.error(\'Error sending data:\', error));
    });
});';
};
?>
function actIptv(e) {
    e.stopPropagation();
    let actButton = dc.querySelector('.actbutt');
    if (actButton) actButton.classList.add('wave-effect');

    let snd = $('.playlists').attr('id');
    
    try {
        let selNum = dc.querySelector(".selnum");
        if (!selNum) throw new Error("Элемент .selnum не найден!");

        let dataToSend = {
            uid: $("#uid").html(),
            pb: snd,
            m: selNum.innerHTML
        };

        <?php
        if ($_SESSION['a'] == 1) {
            echo 'dataToSend.tw = dc.querySelector(\'input[name="twnid"]\')?.value || "";';
        }
        ?>

        $.ajax({
            url: "cdc.php",
            type: "POST",
            cache: false,
            dataType: "json",
            data: dataToSend
        })
        .done(function (r) {
            if (actButton) actButton.classList.remove('wave-effect');
            
            if (r === "n_a") {
                se();
            } else if (r.sum !== undefined && r.e !== undefined) {
                $("#deposit").html(parseFloat(r.sum).toFixed(2));
                $("#activateButton").text("ПРОДЛИТЬ ПОДПИСКУ НА ");
                $("#enddate").val(mkdt(r.e * 1000, 0, 1));
                hMsg.dMsg(r.m + " ПАКЕТА ПРОШЛА УСПЕШНО");
            } else if (r.m) {
                hMsg.dMsg(r.m);
            } else {
                console.error("Неправильный формат ответа от сервера:", r);
            }
        })
        .fail(function (jqXHR, textStatus, errorThrown) {
            const cookieA = dc.cookie.split('; ').find(row => row.startsWith('a='))?.split('=')[1];
            const cookieI = dc.cookie.split('; ').find(row => row.startsWith('i='))?.split('=')[1];

            if (cookieA === '1' && cookieI === '46') {
                alert(`Ошибка запроса:\nStatus: ${textStatus}\nError: ${errorThrown}\nResponse: ${jqXHR.responseText}`);
            } else {
                console.error("Ошибка запроса:", textStatus, errorThrown);
            }
        });

    } catch (err) {
        hMsg.dMsg("ОШИБКА: " + err.message);
    }
}
</script>
<div id="rgacc" class="mdl">
<form method="post" id="signin" name="signin">
<div class="t_subst"><div class="title" data-i18n="modal.register_account">РЕГИСТРАЦИЯ АККАУНТА</div><span class="clse"></span></div>
<div class=signin>
<div class="fCont">
  <div style="display: flex; justify-content:space-between; margin-right:10px"><label data-i18n="form.login">ЛОГИН</label>
  <div style="display:flex">
  <LABEL data-i18n="form.iptv">IPTV</LABEL>
  <input style="vertical-align:middle" type="checkbox" id="iptv" name="iptv">
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
  <label style='clear:both' data-i18n="form.password">ПАРОЛЬ</label>
  <input id="ps" name="ps" type="password" class="required password"/>
 </div>
 <div>
  <label data-i18n="form.connection_server">СЕРВЕР ПОДКЛЮЧЕНИЯ</label><select id="srv" name='srv'>
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
<div style="padding:9px">
<button class="submit" data-i18n="form.register">ЗАРЕГИСТРИРОВАТЬ</button>
</div>
</div>
</form>
</div>
</div>

<div class="m_edbox" id="formBox">
<form id=uDtails>
<div class="cell1"><button class="button" type="submit"></button>
<div class="nfo" data-i18n="info.reg_date">Дата регистрации</div><div id="regd"></div></div>

<div class="u-flex-end"></div>
<div class="cell"><div class=frow><input id="passu" name="passu" type="password"><button type="button" class="eye-btn" onclick="togglePassword(this)">🔒</button><label data-i18n="form.password">ПАРОЛЬ</label></div>
<div class=frow><input id="e_ml" name="e_ml" type="email"><label data-i18n="form.email">EMAIL</label></div></div>
<div class="cell">
  <div>
    <div class=frow><input type="text" id="mph" name="mph" size="20"><label data-i18n="form.mobile">МОБИЛЬНЫЙ #</label></div>
    <div class="u-inline-flex-center"><input type="checkbox" id="msnd" name="snd"><label class="switcher" for="msnd"></label><span class="sendto" data-i18n="form.notify_to_phone">Отправлять оповещения на номер</span></div>
  </div>

<div class=frow><select id=msrv name=msrv>
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
</select><label data-i18n="form.server">СЕРВЕР</label></div></div>
<div class="cell1 frow"><textarea rows=1 id="cmmnt" name="cmmnt"></textarea><label data-i18n="form.comment">КОМЕНТ</label></div>
<?php
echo '<div style="border:1px solid #1d2f35;padding:5px;position:relative">
<div class="bubbleslist"><div style="position:absolute;left:0;top:0;transform:translateY(-90%);font-size:9px;font-weight:700">СПИСОК КАРТ</div><div class=butaddcard id="uaddcards">+</div></div>';
echo '</div>';
?>

</form>
</div>

<div id="paym" class="login-popup" style="left:50%;top:50%;transform:translate(-50%,-50%)">
<div style="display:flex"><div class=title data-i18n="modal.payments_info">ИНФОРМАЦИЯ О ПЛАТЕЖАХ</div><div class="clse"></div></div>
<!--<p>Для пополнения баланса, переведите сумму на указанные ниже реквизиты для WM в примечании укажите ваш логин,
</br> но перед этим в ПРОФИЛЕ, во вкладке Webmoney укажите ваш WMID.--><p>Для UZ переводите средства только через PayMe и уже через 5 минут средства будут зачислены. <br>!!! При переводе, укажите в примечании "d <?php echo $dealerId?>" !!!<BR>
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
      echo "<span class=p1>Карта ".$prs[$i]['name'];
    else
      echo "<span class=p1>WM";
    echo " ".$prs[$i]['desc'].' <span class=ps>'.$prs[$i]['purse']."</span> курс (1:".$prs[$i]['exch'].')</span>';
    }
?>
</div>
</div>
<div id="cntnt">
<div id=plist class="pluso-list"></div>
</div>
</div>

<div id="rset" class="pluso-box" style="top:50%;left:50%;display:none;width:100%;transform: translate(-50%, -50%)">
<div style="display:flex"><div class="title" data-i18n="modal.plugin_settings">НАСТРОЙКИ ПЛАГИНОВ</div><div class="clse"></div></div>
<div class="clear cell1 row"><span class="p1"><select id="tun" onchange="ltuns(this,'pl')"><option data-i18n="form.tuner">Тюнер</option>
<?php
$res=$link->query("select * from recievers order by rn_id") or die("SQL req. error: ".$link->error_list);
$rc=$res->num_rows;
                for($i=0;$i<$rc;$i++){$rs[$i]=$res->fetch_assoc();echo "<option value=".$rs[$i]['rn_id'].'>'.$rs[$i]['rname']."</option>";}
                ?>
</select></span>
<span class="p1"><select id="pl" disabled="disabled" onchange="ltuns(this,'pr')"><option data-i18n="form.plugin">Плагин</option></select></span>
<span class="p1"><select id="pr" disabled="disabled" onchange="lrs()"><option data-i18n="form.protocol">Протокол</option></select></span></div>
<div class="clear cell1"><div id="rsets" class="lst"></div></div>
<span class="p1" id="hlp"></span>
<div class="clear cell1 row">
<span class="p1"><button onclick="stof()" data-i18n="form.send_to_file">Сохранить в файл</button>
<div id="semail"><button id="bb" data-i18n="form.send_to_email">Отправить на email</button><form method="post" id="stoemail" name="stoemail" enctype="multipart/form-data">
<div class="pluso-box" id="sedia" style="display:none;position:absolute"><input id="inputeml" name="inputeml" type="text" class="required email">
<button class="submit" style="width:100%" data-i18n="form.send">Отправить</button></div>
</form>
</div>
</span>
</div>
</div>

<div id="classo" class="pluso-box" style="position:fixed;top:50%;left:50%;display:none;width:95%;max-width:650px;height:calc(60dvh);min-height:350px;max-height:calc(60dvh);transform:translate(-50%, -50%);
 margin:0;overflow-y:auto;box-sizing: border-box">
<div style="display:flex">
<div class=title><span data-i18n="modal.account_ops">СПИСОК ОПЕРАЦИЙ ПО АККАУНТУ </span><span id="ullst"></span></div><div class="clse"></div></div>
<div id=ulist class="pluso-list"></div>
</div>

<div id=ued class=mdl>
<form method="post" id="uedit" name="uedit">
<div class="t_subst"><div class="title"><span data-i18n="modal.edit_data">РЕДАКТИРОВАНИЕ ДАННЫХ </span><span id="ue"></span></div><div class="clse"></div></div>
<div class="uedbox">
<div class="cell1 u-flex-center">
<div class="nfo" data-i18n="info.reg_date">Дата регистрации</div><div id="dr"></div></div>
<?php
echo '<div class="cards-block">
<div class="cards-block__title" data-i18n="cards.list">СПИСОК КАРТ</div><div class=butaddcard id="uaddcards">+</div><div class="bubbleslist"></div>';
echo '</div>';
?>
<div class="cell1"><div class="lft"><label data-i18n="edit.password">Пароль:</label></div><div class="rgt"><input id="psu" name="psu" type="password"></div></div>
<div class="cell1"><div class="lft"><label data-i18n="edit.email">E-mail:</label></div><div class="rgt"><input id="eml" name="eml" type="email"></div></div>
<div class="cell1"><div class="lft"><label data-i18n="edit.tid">TID:</label></div><div class="rgt"><input id="tID" name="tID"></div></div>
<div class="cell1"><div class="lft"><label data-i18n="edit.mobile">Мобильный #:</label></div><div class="rgt"><input type="text" id="ph" name="ph" size="20">
<div><input type="checkbox" id="snd" name="snd"><label class="switcher" for="snd"></label><span class="sendto" data-i18n="edit.notify_phone">Отправлять оповещения на номер</span></div></div></div>
<div id="srvshow" class="cell1"><div class=lft><label data-i18n="edit.server">Сервер:</label></div><div class=rgt><select id=srv name=srv>
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
<div class="cell1"><label data-i18n="edit.comment">Комент</label></div><div class="cell1"><textarea rows=2 id="comment" name="comment"></textarea></div>
<div class="cell1"><button class="button" type="submit" data-i18n="form.save">СОХРАНИТЬ</button></div></form></div>

<div class="modal"></div>
   <script>
/* Legacy $.ajaxSetup 401 handler removed — reauth.js now intercepts
   expired-session responses and shows a login modal without reload. */

const menuToggle = dc.querySelector('.menu-toggle');
const sideMenu = dc.querySelector('.side-menu');
const overlayMenu = dc.querySelector('.overlay-menu');
const infoToggle = dc.querySelector('.info-toggle');
const infoPanel = dc.querySelector('.info-panel');
const overlayInfo = dc.querySelector('.overlay-info');
const formBox = dc.getElementById('formBox');

function toggleMenu() {
  const isOpen = sideMenu.classList.toggle('open');
  overlayMenu.classList.toggle('active');
  menuToggle.textContent = isOpen ? '✖' : '☰';
  menuToggle.classList.toggle('open');
  if (infoPanel.classList.contains('open')) closeInfoPanel();
}

function closeMenu() {
  sideMenu.classList.remove('open');
  overlayMenu.classList.remove('active');
  menuToggle.textContent = '☰';
  menuToggle.classList.remove('open');
}

function toggleInfoPanel() {
  const isOpen = infoPanel.classList.toggle('open');
  overlayInfo.classList.toggle('active');
  infoToggle.textContent = isOpen ? '✖' : 'ℹ';
  infoToggle.classList.toggle('open');
  if (sideMenu.classList.contains('open')) closeMenu();
}

function closeInfoPanel() {
  infoPanel.classList.remove('open');
  overlayInfo.classList.remove('active');
  infoToggle.textContent = 'ℹ';
  infoToggle.classList.remove('open');
}

menuToggle?.addEventListener('click', toggleMenu);
overlayMenu?.addEventListener('click', closeMenu);
infoToggle?.addEventListener('click', toggleInfoPanel);
overlayInfo?.addEventListener('click', closeInfoPanel);

dc.addEventListener('keydown', (event) => {
  if (event.key === 'Escape') {
    if (sideMenu.classList.contains('open')) closeMenu();
    if (infoPanel.classList.contains('open')) closeInfoPanel();
  }
});


// Краевые свайпы для открытия/закрытия бокового меню и информационной панели.
// Свайп-навигация внутри #result (список ↔ шаринг ↔ iptv) реализована в js/pager.js.
const SWIPE_MIN_PX = 35;
const SWIPE_MAX_MS = 500;
let touchStartX = 0;
let touchStartY = 0;
let touchStartTime = 0;
let isHorizontalEdgeSwipe = false;

dc.addEventListener('touchstart', (e) => {
  const t = e.changedTouches[0];
  touchStartX = t.screenX;
  touchStartY = t.screenY;
  touchStartTime = Date.now();
  isHorizontalEdgeSwipe = false;
}, { passive: true });

dc.addEventListener('touchend', (e) => {
  if (Date.now() - touchStartTime > SWIPE_MAX_MS) return;

  const t = e.changedTouches[0];
  const dx = t.screenX - touchStartX;
  const dy = t.screenY - touchStartY;
  const absX = Math.abs(dx);
  const absY = Math.abs(dy);
  const screenWidth = window.innerWidth;
  const edgeZone = Math.max(50, screenWidth * 0.1);

  // Только сильные горизонтальные свайпы засчитываем как edge-swipe.
  if (absX > absY && absX > SWIPE_MIN_PX) {
    isHorizontalEdgeSwipe = true;
    if (dx > 0 && touchStartX < edgeZone && !sideMenu.classList.contains('open') && !infoPanel.classList.contains('open')) {
      toggleMenu();
    } else if (dx < 0 && touchStartX > screenWidth - edgeZone && !sideMenu.classList.contains('open') && !infoPanel.classList.contains('open')) {
      toggleInfoPanel();
    } else if (dx < 0 && sideMenu.classList.contains('open')) {
      closeMenu();
    } else if (dx > 0 && infoPanel.classList.contains('open')) {
      closeInfoPanel();
    }
  } else if (formBox && dy > SWIPE_MIN_PX && formBox.classList.contains('active')) {
    formBox.classList.remove('active');
  }
}, { passive: true });
dc.addEventListener('DOMContentLoaded', () => {
  dc.addEventListener('click', (e) => {
    const l = e.target.closest('#usrLst .loginm a');
    if (l) {
      e.stopPropagation();
      const lgn = l.dataset.l;
      fB=dc.getElementById('formBox');
      if(fB.classList.contains('active'))  fB.classList.remove('active');
      if (typeof MpplPager !== 'undefined') MpplPager.setOrigin('userlist');
      getuser(0,lgn);
      return;
    }
    // Клик по логину в ИСТОРИИ ОПЕРАЦИЙ → переход к карточке аккаунта
    const hLogin = e.target.closest('#result .login');
    if (hLogin) {
      const lgn = (hLogin.textContent || '').trim();
      if (lgn && lgn !== 'Paybot') {
        if (typeof MpplPager !== 'undefined') MpplPager.setOrigin('loglist');
        getuser(0, lgn);
        return;
      }
    }
    const r=e.target.closest('#usrLst tr[data-l]');
    if (r) {
      const l=r.dataset.l;
      showDetails(l,r);
    }
  });
});

dc.querySelector('.sbox').addEventListener('click', (e) => {
  if (formBox && formBox.classList.contains('active')) {
    formBox.classList.remove('active');
    console.log('Click on .sbox: closing formBox');
  }
});
  dc.querySelectorAll('.side-menu a').forEach(link => {
  link.addEventListener('click', (e) => {

    // Удаляем класс active у всех div
    dc.querySelectorAll('div.active').forEach(div => {
      div.classList.remove('active');
    });
    closeMenu();closeInfoPanel();
    if (link.id !== 'uman' && typeof window.umanExit === 'function') window.umanExit();
    const a = {
      'userlist': userlist,
      'loglist': loglist,
      'packetp': packetp,
      'bal': bal,
      'nws': nws,
     <?php if ($_SESSION['a'] == 1)  echo "'dlrhst': dlrhst,'fkassa': fkassa,'dlst': dlst,'uman': uman,"; ?>
    };
    if (a[link.id]) a[link.id]();
  });
});
</script>
</body>
</html>