<?php
include_once("config.php");
 checkLoggedIn("yes");
$ps=$eml=$ph=$toph='.';
$row=$accid=$accsum=$accpwd=$accdreg=$acceml=$accph=$rez='';

if (isset($_POST["get"]))
{
$user=$_SESSION['l'];
$res=$link->query("SELECT id,user,sum,a,pwd,DATE_FORMAT(dreg,'%d.%m.%y %H:%i') as dreg,eml,phone,cardnum,wmid FROM dealers WHERE user='$user'") or die("SQL Req. error: ".$link->error_list);
	 if($res->num_rows==1)
	    {$row = $res->fetch_assoc();
		 $accid = $row['id'];
		 $accsum = $row['sum'];
         $accpwd = $row['pwd'];
         $accdreg = $row['dreg'];
         $acceml = $row['eml'];
         $accph = $row['phone'];
         $cardnum = $row['cardnum'];
         $accwmid = $row['wmid'];
 	    }
$did=$_SESSION["i"];
$res=$link->query("SELECT card,cid,owner,exp from cardslist where did=$did and card!=''") or die("SQL error: ".$link->error_list);
   $numofcards=$res->num_rows;
   for($i=0;$i<$numofcards;$i++)
    $cardslst[$i] = $res->fetch_assoc();
   //  echo '<form method="post" id="chpass" name="chpass" enctype="multipart/form-data">';
?>
<div id="profedit" class=mdl>
<!-- <div class="fCont"> -->
<form method="post" id="chpsf" name="chpsf" class="signin">
<div class="t_subst"><div class="title">РЕДАКТИРОВАНИЕ ПРОФИЛЯ</div><div class="clse"></div></div>
<div class=signin>
<div class="fCont">
    <label>СТАРЫЙ ПАРОЛЬ</label><input id="op" name="op" type="password">
    <label>НОВЫЙ ПАРОЛЬ</label><input id="np" name="np" type="password">
    <label>ПОВТОРИТЕ ПАРОЛЬ</label><input id="rp" name="rp" type="password">
    <button class="button" id=chpsb>ИЗМЕНИТЬ ПАРОЛЬ</button>
  </form>
    <form method="post" id="pfedit" name="pfedit" class="signin">
<?php
echo '<label style="text-align:center">СПИСОК КАРТ</label>
<div class=bubbleslist ccnt='.$numofcards.'>';
if($numofcards)
  {for($i=0;$i<$numofcards;$i++)
  echo '<div class=crdnm><input  changed=0 id='.$cardslst[$i]['cid'].' type="text" value='.$cardslst[$i]['card'].' tmp='.$cardslst[$i]['card'].' data-owner="'.$cardslst[$i]['owner'].'" data-exp="'.$cardslst[$i]['exp'].'" readonly><el class=rm></el></div>';
  }
echo '</div><div class=butaddcard id="addcards"">ДОБАВИТЬ КАРТУ</div>';
//<input id="crdnum" name="crdnum" style="text-align:center;font-size:18px;color:#2a9f31" type="text" value='.$cardnum.'>';
echo '<label>WMID <div class=sendto>Для оплаты через Webmoney</div></label><input type="text" id="wmid" name="wmid" size="20" value='.$accwmid.'>';
echo '<label>EMAIL</label><input id="eml" name="eml" type="text" value='.$acceml.'>';
echo '<label>НОМЕР ТЕЛЕФОНА</label><input type="text" id="ph" name="ph" size="20" value='.$accph.'><div class=sendto><input type="checkbox" id="sndtph" name="sndtph">Отправлять оповещения на номер</div>';
?>
<div class="clear cell1"><button class="button" id="upd">СОХРАНИТЬ ДАННЫЕ</button></div></form></div></div></div></div> <!-- </div> -->
<?php
echo '<script>
$("#pfpsget").validate({});
$("#pfedit").validate({submitHandler:function()
{pfpass();return false;},
focusInvalid:0,
focusCleanup:1,
rules:{
   eml:{required:1,email:1},
   wmid:{required:1,digits:1,minlength:20,maxlength:20},
crdnum:{digits:1,minlength:10,maxlength:16}
      },
messages:{
   eml:{required:"Введите E-mail!",email:"Введите верный e-mail"},
   wmid:{digits:"Вводите только цифры из вашего WMID",minlength:"Минимальное количество цифр 20",maxlength:"Мaксимальное количество цифр 20"},
crdnum:{required:"Введите номер кредитной карты",digits:"Допустимы только цифры",minlength:"Минимум 10 цифр",maxlength:"Мaкс 16 цифр"}}
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
phms($("#pfl #ph"));
</script>';
exit();
}

if( isset($_POST["pp"]) && (isset($_POST["ph"]) || isset($_POST["eml"]) || isset($_POST["sndtph"]) || isset($_POST["cards"]) || isset($_POST["wmid"])))
{
    if(isset($_POST["pp"]))
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