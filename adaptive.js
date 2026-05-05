const dc=document;
var tmt=0,tmtstr=0,tmtstr2=0,vip,nfirst=0,cpckts,t=0,watches=0,pp=0;
var mass=new Array(0);
var dsbl=new Array(0);

var fname='';
var timeout=null;
pp=gck("pp");
var adm=gck("a");
if (pp==null || adm==null) se();
var rmelem=new Array(0);
var changed = 0;

$(dc).on("click","#addcards,#uaddcards",function()
{
changed = 0;
deletedel=0;
delement='';
elemid=$(this).closest("form").attr("id");
$("#"+elemid+" .crdnm").each(function(){
if(this.value==='')
{
deletedel=this.id;
return;}
});
if($("#"+elemid+" .crdnm").size()<3 || deletedel)	
{
 dc.querySelector('.cc__form').reset();
 e=dc.querySelector(".cc");
 $('body').append('<div style="display:block" id="fmask"></div>');
 $(dc).on('click','#fmask',function(){$("#fmask").fadeOut(200,function(){$('#fmask').remove()});e.style.display="none";});
 e.style.top = '50%';
 e.style.left = '50%';
 e.style.transform = 'translate(-50%, -50%)';
 e.style.display = 'flex';
 e.dataset.formid=elemid;
 if(deletedel)
 elid=deletedel;
 else elid=0;
 e.dataset.crdfid=elid;
}});
$(dc).delegate(".crdnm","click", function() 
{
	changed = 0;
	e=dc.querySelector(".cc");
	crdnm1=dc.getElementById("input1");
	crdnm2=dc.getElementById("input3");
	inelem=this.querySelector('input');
	valtosplit=inelem.value;
	dc.getElementById('submit-button').disabled = true;
	crdnm1.value=valtosplit.slice(0,6);
	crdnm2.value=valtosplit.slice(12,16);
	crdnm2.removeAttribute('disabled');
	//crdnm.value = crdnm.value.replace(/[^0-9]/g,'').replace(/(.{4})/g,'$1 ');
	elemid=$(this).closest("form").attr("id");
	e.dataset.formid=elemid;
	e.dataset.crdfid=inelem.id;
	crdh=dc.getElementById("cardholder");
	crdh.value=inelem.dataset.owner;
	exp=dc.getElementById("card-exp");
	exp.value=inelem.dataset.exp.replace(/(.{2})/, '$1\/');
	$('body').append('<div style="display:block" id="fmask"></div>');
	$(dc).on('click','#fmask',function(){$("#fmask").fadeOut(200,function(){$('#fmask').remove()});e.style.display="none";});
  	e.style.top = '50%';
	e.style.left = '50%';
	e.style.transform = 'translate(-50%, -50%)';
	e.style.display = 'flex';
});

$(dc).delegate(".rm","click", function(e){
	e.stopPropagation();
	if($(this).parent(".crdnm").find("input").attr('id') === '0')
		$(this).parent(".crdnm").remove();	
		else
		$(this).parent(".crdnm").hide().find("input").attr("value","").attr("changed",1);
		});
function UC()
{
var sum=summa();
var dep=Number(dc.getElementById('deposit').innerHTML);
$("#txtHint").html("").fadeOut();
if(dep<sum && !pp)
{
$("#txtHint").html("На счету недостаточно "+(sum-dep).toFixed(2)+" у.е.<br> пополните депозит").fadeIn();
dc.getElementById("buy").disabled=1;
dc.getElementById("buy2").disabled=1;
}
else if(sum==0 && !pp)
{dc.getElementById("buy").disabled=1;
dc.getElementById("buy2").disabled=1;
}
else
{
dc.getElementById("buy").disabled=0;
dc.getElementById("buy2").disabled=0;
$("#txtHint").html("").fadeIn();
}
if((ts=sum.toFixed(2))=='0.00')
{ts="";
dc.getElementById("buy").disabled=1;
dc.getElementById("buy2").disabled=1;}
dc.getElementById('tsum').innerHTML=ts;
dc.getElementById('tsum2').innerHTML=ts;
}
function summa()
{var sum=0,id=1,t=parseInt($('#st').attr('tm'));
ttoc=new Date(t).getTime();
mass = [];
dsbl = [];
if (!vip) nfirst=0;
while(chck=dc.getElementById('p'+id))
{
if(chck.checked==true && id==1){vip=1; nfirst=1}
else if(chck.checked==false && id==1)
{vip=0;}
if(vip && id>1)
{mass[id]=chck.checked;
dsbl[id]=chck.disabled;
chck.checked=false;
chck.disabled=true;
}
else if(!vip && id>1 && nfirst==1)
{
chck.disabled=dsbl[id];
chck.checked=mass[id];
}
if(chck.checked==true)
{pprice=dc.getElementById('price'+id);
ge=$("#dto"+id).html();
ttoc2=new Date(parseInt(ge.substr(6,4),10),parseInt(ge.substr(3,2),10)-1,parseInt(ge.substr(0,2),10),0,0).getTime();
if(ge.length<11 || ttoc2<=ttoc)
{ge=mkdt(0,0,0);}
sum+=Number((pprice.innerHTML)/30*(ddiff(dc.getElementById('dtol'+id).value,ge)));
}
id++;
}
return sum;
}
function sd(n)
{
var i=1;
nw=new Date();
while(ge=$("#dto"+i).html())
{
nw2=new Date(parseInt(ge.substr(6,4),10),parseInt(ge.substr(3,2),10)-1,parseInt(ge.substr(0,2),10),parseInt(ge.substr(11,2),10),parseInt(ge.substr(14,2),10));
if(ge.length<12 || nw2.getTime()<=nw)
ge=mkdt(0,(n*30)*1440,0);
else
ge=pdt($("#dto"+i).html(),n*30,1)
$("#dtol"+i).val(ge);
i++;
}
UC();
}
function mkdt(dt,n,hm)
{
if(dt)
{
var dtn=new Date(dt);
}
else
var dtn=new Date()
if (!n) n=0;
if (!hm) hm=0;
dtn.setFullYear(dtn.getFullYear());
dtn.setMonth(dtn.getMonth(),dtn.getDate());
dtn.setHours(dtn.getHours());
dtn.setMinutes(dtn.getMinutes()+n);
if (hm) return addnull(dtn.getDate(),dtn.getMonth()+1,dtn.getFullYear(),dtn.getHours(),dtn.getMinutes());
return addnull(dtn.getDate(),dtn.getMonth()+1,dtn.getFullYear(),0,0);
}
function addnull(dd,m,y,h,mn){var d0='',m0='',h0='',mn0='';if (dd<10) d0='0';if (m<10) m0='0';if (h<10) h0='0';if (mn<10) mn0='0';if (h || mn)return d0+dd+'.'+m0+m+'.'+y+' '+h0+h+':'+mn0+mn;return d0+dd+'.'+m0+m+'.'+y;}
function ddiff(d1,d2){var dt1=new Date(parseInt(d1.substr(6,4),10),parseInt(d1.substr(3,2),10)-1,parseInt(d1.substr(0,2),10));var dt2=new Date(parseInt(d2.substr(6,4),10),parseInt(d2.substr(3,2),10)-1,parseInt(d2.substr(0,2),10));return ((dt1-dt2)/86400000)}
function pdt(date,n,t){var dn=new Date(parseInt(date.substr(6,4),10),parseInt(date.substr(3,2),10)-1,parseInt(date.substr(0,2),10),parseInt(date.substr(11,2),10),parseInt(date.substr(14,2),10));dn.setFullYear(dn.getFullYear());
dn.setMonth(dn.getMonth(),dn.getDate());
dn.setHours(dn.getHours());
dn.setMinutes(dn.getMinutes()+(n*1440));
if(!t)
return addnull(dn.getDate(),dn.getMonth()+1,dn.getFullYear(),dn.getHours(),dn.getMinutes());
else
return addnull(dn.getDate(),dn.getMonth()+1,dn.getFullYear());
}
function buyp()
{
var snd=[],ge,id=1,uid=0,dtp=0;
var t=parseInt($('#st').attr('tm'));
ttoc=new Date(t).getTime();
while(chck=dc.getElementById('p'+id))
{
if(chck.checked==true)
{var ge=$("#dto"+id).html();
//snd[0]=chck.value;
//snd[1]=ddiff($("#dtol"+id).val(),mkdt(0))
//snd[2]=id;
nw2=new Date(parseInt(ge.substr(6,4),10),parseInt(ge.substr(3,2),10)-1,parseInt(ge.substr(0,2),10),parseInt(ge.substr(11,2),10),parseInt(ge.substr(14,2),10));
if(ge.length<=10 || nw2.getTime()>=ttoc)
{
snd.push([chck.value,ddiff($("#dtol"+id).val(),mkdt(0)),id]);
}
else {
snd.push([chck.value,ddiff($("#dtol"+id).val(),ge),id]);
}
}
id++;
}
prl();
var rq=$.ajax({url:"pbuy.php",type:"POST",cache:0,dataType:"json",async:false,data:{uid:$("#uid").html(),pb:snd}});
rq.done(function(r) {
if(r=="n_a") se();
else if(!r.m)
{
dtp=parseInt(r.md);
$("#deposit").html(parseFloat(r.sum).toFixed(2))
for (i=1;i<=snd.length;i++){
p_id=snd[i-1][0];
id=snd[i-1][2];
days=snd[i-1][1];
ge=mkdt(0,days*1440,1);
t="<td align=center id='dt";
rw=$("#r"+id);
nsb='<td id="pa'+id+'"></td>';
if(r.sd!=0)
sb='<td>';
else
sb='<td id="pa'+id+'" align=center><div class="rstp stpb" title="Остановить пакет" id="pas'+id+'" onclick="stop('+p_id+',this)"><span class=" ui-icon ui-icon-stop"></span></div></td>';
est=t+"f"+id+"'>"+mkdt(0, 0, 1)+"</td>"+t+"o"+id+"'>"+mkdt(0,days*1440,1)+"</td>";
if($("#dto"+id).html()=="Не активен"){
(r.d==1 || adm==1)?sb += est:sb=nsb+est;
$("#dto"+id).remove();
$("#pa"+id).replaceWith(sb);
(rw.index()%2)?ds="ra3":ds="ra4";
rw.removeClass().addClass(ds);
porb="Покупка";
}
else
{
$("#dtf"+id).html(mkdt(0,0,1));
var ge=$("#dto"+id).html();
nw2=new Date(parseInt(ge.substr(6,4),10),parseInt(ge.substr(3,2),10)-1,parseInt(ge.substr(0,2),10),parseInt(ge.substr(11,2),10),parseInt(ge.substr(14,2),10));
if(nw2.getTime()<=ttoc)
{
ge=mkdt(0,days*1440,1);
$("#pas"+id).addClass("rstp ui-icon ui-icon-stop");
}
else
ge=pdt($("#dto"+id).html(),days,0);
$("#dtf"+id).html(mkdt(0,0,1));
$("#dto"+id).html(ge);
porb="Продление";
}
$("#dtol"+id).datepicker("option","minDate",new Date(parseInt(ge.substr(6,4),10),parseInt(ge.substr(3,2),10)-1,parseInt(ge.substr(0,2),10)+dtp));
hPan.dMsg('<b>'+mkdt(0,0,1)+'  ID: '+r.e[i-1][0]+" "+porb+':</b><span class="indent"> пакет <b>'+$('#pname'+id).html()+'</b>'+' логин '+$('#uname').html()+' на <b>'+days+' д. до '+ge+' на сумму '+($('#price'+id).html()/30*days).toFixed(2)+'</b></span>');
}
if(!tmtstr2) tmtstr2=setTimeout(function(){tmtstr2=0;stu('c');},7*1000);
hish_pause(0);UC();
}
else
{hMsg.dMsg(r.m);}
});
rq.fail(function(r) {hMsg.dMsg("ОШИБКА");});
}
function stop(i,e,t)
{
const event = t || window.event;
    if (event && typeof event.stopPropagation === "function") {
        event.stopPropagation();
    } else {
        console.error("Объект события не определен или не содержит метод stopPropagation");
    }
var nw=new Date();
prl();
console.log("отправляем запрос");
var rq=$.ajax({url:"undo.php",type:"POST",cache:0,dataType:"json",data:{stop:i,uid:$('#uid').html()}}); //dc.getElementById('p'+i).value
rq.done(function(r){
dtp=r.md;
if(r=="n_a") se();
else if(r.s=="0")
{rw=$(e).parents('tr');
(rw.index()%2)?rw.switchClass("rw2"):rw.switchClass('rw1');
rw.removeClass('stb').find('span').removeClass("ui-icon ui-icon-stop").remove();
rw.find("td:eq(3)").remove().end().find('td:eq(3)').remove();
rw.find("td:eq(2)").replaceWith("<td id='pa"+ rw.find("input").val() +"'></td><td id='dto"+i+"'colspan=2 align=center>Не активен</td>");
$("#dtol"+i).val(mkdt(0,dtp*1440,0)).datepicker("option","minDate",new Date(nw.setTime( nw.getTime() + dtp * 86400000 )));
}
else
{
var ge=utj(r.r.e);
fel=$(e).parent().next().attr("id");
sel=$(e).parent().next().next().attr("id");
$("td#"+fel).html(utj(r.r.s));
$("td#"+sel).html(ge);
//$("#dto"+i).html();
$("#dtol"+i) .val(pdt(ge,dtp,0)).datepicker("option","minDate",new Date(parseInt(ge.substr(6,4),10),parseInt(ge.substr(3,2),10)-1,parseInt(ge.substr(0,2),10)+dtp));
}
$("#deposit").html(parseFloat(r.sum).toFixed(2));
hPan.dMsg('<b>'+mkdt(0,0,1)+' Отмена:</b><span class="indent"> ID операции  <b>'+r.r.i+'</b></span>');
hish_pause(0);
});
}
function pause()
{
uid=$('#uid').html();
prl();
var rq=$.ajax({url:"pause.php",type:"POST",cache:0,dataType:"html",data:{id:uid}});
rq.done(function(r)
{
if($("#pacc").hasClass('ui-icon-play'))
{
st=r.substr(0,1);dts=r.substr(1,r.length-1);dts=dts.split('],[');
ln=dts.length;
for(j=0,lj=ln;j<lj;j++)
{dts[j]=dts[j].replace("[",'');var t=dts[j].replace("]",'');dts[j]=t.split(',')}
for(i=0;i<ln;i++)
{$("#dto"+dts[i][0]).html(dts[i][2]);
$("#dtf"+dts[i][0]).html(dts[i][1])}
$(".stpb").each(function(){$(this).show();});
$('tr[class^=psd]').each(function(){($(this).index()%2)?$(this).removeClass().addClass('ra4'):$(this).removeClass().addClass('ra3')});
spb(0);
hMsg.dMsg("АККАУНТ СНЯТ С ПАУЗЫ");
$("#pacc").unbind("click");
$("#buy,#buy2").bind("click");
if(!tmtstr2) tmtstr2=setTimeout(function() {tmtstr2=0;stu('c');},7*1000);
}
else
{
spb(1);
$('tr[class^=ra]').each(function(){($(this).index()%2)?$(this).removeClass().addClass('psd1'):$(this).removeClass().addClass('psd2')});$(".stpb").each(function(){$(this).hide();});
$("#buy,#buy2").unbind("click");
$("#Layer1").hide();
hMsg.dMsg("АККАУНТ ПОСТАВЛЕН НА ПАУЗУ");
}
});
}
function spb(s){if(s==1) $("#pacc").removeClass("ui-icon-pause").addClass("ui-icon-play"); else $("#pacc").removeClass("ui-icon-play").addClass("ui-icon-pause");}
function iptv(l){
	var rq=$.ajax({url:"getiptv.php",type:"POST",cache:0,dataType:"html",data:{l:l}});
	rq.done(function(r){
		if (!r) {hMsg.dMsg("АККАУНТ С ТАКИМИ ДАННЫМИ НЕ НАЙДЕН")}
		else if(r.substr(0,4)=="<tr>")
		{$(".s_res").html(r).show();
			console.log("we're here");
			}
		else
		{clts();
		mc=$(r).filter('#mc');
		nfo=$(r).filter('.blk');
		crds=$(r).find('#Cardslst').html();
		$(r).find('#Cardslst').remove();
		sts=$(r).filter('#Layer1');
		$("#uinfo").html(nfo).fadeIn();
		$("#result").html(mc).fadeIn();
		$("#uedit .bubbleslist").html(crds);
		$(".circle").hide();
		actEl();
		domKeyCh();
		}});
};

function actEl()
{
	$('#iptvsrv').on('change', function () {
		var select = $(this);
		var button = $('#changecdn');
		button.prop('disabled', select.val() === null || select.val() === '');
		});
		$('#changecdn').on('click', function () {
		var select = $('#iptvsrv');
		var button = $(this);
		var selectedValue = select.val();
		$.ajax({ type: 'POST',url: "cdc.php",cache:0,dataType:"json",async:false,
		//contentType: 'application/json',
		data: { cdn: selectedValue ,u: $('#uname').html()},
		success: function (data) {
		hMsg.dMsg(data['m']);
		},
		error: function () {
		console.error('Ошибка при отправке запроса на сервер');
		}
		});
		});
}

function domKeyCh()
{
	$('#dom').on('change', function () {
		var select = $(this);
		var button = $('#savedom');
		button.prop('disabled', select.val() === null || select.val() === '');
		});
		$('#savedom').on('click', function () {
		var Value = $('#dom').val();
		var button = $(this);
console.log("value: " + Value);
		$.ajax({ type: 'POST',url: "cdc.php",cache:0,dataType:"json",async:false,
		//contentType: 'application/json',
		data: { dom: Value ,u: $('#uname').html()},
		success: function (data) {
		console.log(data);
		hMsg.dMsg(data['m']);
		},
		error: function () {
		console.error('Ошибка при отправке запроса на сервер');
		}
		});
		});
}


function saveJSON() {
	var listNumber, selectedItems = [];
	var checkboxes = document.querySelectorAll('.tabcontent.active-tab .selected');
	var activeTabId = document.querySelector('.tabcontent.active-tab').id;
	if (activeTabId === 'tab1') {
		listNumber = 1;
	} else if (activeTabId === 'tab2') {
		listNumber = 2;
	}
	var selectedItemsString = '';
    checkboxes.forEach(function(checkbox, index) {
    selectedItemsString += checkbox.value;
    if (index < checkboxes.length - 1) {
        selectedItemsString += ',';
    }
});
if(selectedItemsString)
{
var rq=$.ajax({type:'POST',url:"cdc.php",cache:0,dataType:"json", data:{grp:selectedItemsString,u: $('#uname').html(),plnm: listNumber}});
rq.done(function (d) {hMsg.dMsg(d['m']);});
rq.error(function (jqXHR, textStatus, errorThrown) {
        console.error("Ошибка запроса:");
        console.error("Статус: " + textStatus);
        console.error("Ошибка: " + errorThrown);
        console.error("Данные об ошибке: " + JSON.stringify(jqXHR)); // Печать объекта
    });
}
else
{hMsg.dMsg("Хотя бы одна группа каналов должна быть выбрана")}
}
const kMap={'й':'q','ц':'w','у':'e', 'к':'r','е':'t','н':'y','г':'u','ш':'i','щ':'o','з':'p','ф':'a','ы':'s','в':'d','а':'f','п':'g','р':'h','о':'j','л':'k','д':'l','я':'z','ч':'x','с':'c','м':'v','и':'b','т':'n','ь':'m',
'ё':'`','ж':'[','э':']','б':',','ю':'.','ъ':';'};
document.addEventListener('DOMContentLoaded', function () {
const unI = document.getElementById('glog');
function ToLatin(text) {return text.split('').map(char => {const lChar = char.toLowerCase();if (kMap[lChar]) {return char === lChar ? kMap[lChar]:kMap[lChar].toUpperCase();}
return char;}).join('');}unI.addEventListener('input',() =>{unI.value=ToLatin(unI.value);});});

/*function getuser(i,l,p=""){if(!l)var l=$("#glog").val().trim();if(l.length==0 || l=="Введите логин")return;if(tmtstr2) {clearTimeout(tmtstr2);tmtstr2=0;}
$(txtHint).html("").hide();
$(".sb,.s_res").hide();
$(".circle").show();
var rq=$.ajax({url:"px.php",type:"POST",cache:0,dataType:"html",data:{l:l,p:p}});
rq.done(function(r){
if (!r) {hMsg.dMsg("АККАУНТ С ТАКИМИ ДАННЫМИ НЕ НАЙДЕН")}
else if(r.substr(0,4)=="<tr>")
{$(".s_res").html(r).show();}
else
{$("#tun").val("Тюнер").change();clts();
//mc=$(r).filter('#mc');
mc=renderSubscriptions(l);
nfo=$(r).filter('.blk');
crds=$(r).find('#Cardslst').html();
$(r).find('#Cardslst').remove();
sts=$(r).filter('#Layer1');
$("#uinfo").html(nfo).fadeIn();
$("#result").html(mc).fadeIn();
$("#uedit .bubbleslist").html(crds);
//actEl();
//domKeyCh();
spb($("#psd").val());
if((timer=$("#psd").attr("ptmt"))<=0) hish_pause(0);
else {
if(tmt!=0) clearTimeout(tmt);
tmt=setTimeout(hish_pause(0),timer*1000);
}
}
if(sts)$("#uinfo .blk").after(sts);
$(".sb").show();
$(".circle").hide();
});
mass=[];vip=0;nfirst=1;dsbl=[];
rq.fail(function(){$(".sb").show();
$(".circle").hide();});
}*/

function stu(d)
{
var elcol='';
var l=$("#uname").html();
var rq=$.ajax({url:"status.php",type:"POST",cache:0,dataType:"html",data:{un:l,c:d}});
rq.done(function(r){
if(r=="n_a") se();
else {if(d=='c'){$("#str").fadeOut(150);$('#stu').html(r);if($('#Layer1').css('display')==='none'){$('#Layer1').show();}if(!tmtstr) tmtstr = setTimeout(aftergetstatus,15*1000);}
	else $('#sstat').html(r);
	switch($(r).find('el#semafor').attr("class")) 
	{case 'red': elcol="#e15353";break;case 'grn': elcol="#75ee75";break;case 'yel': elcol="#fff779";break}
	$('#Layer1.fin').css('border-bottom-color', elcol);
	}
});
}
function aftergetstatus(){tmtstr=0;$("#str").fadeIn(150);}

function mtransf()
{
var l=$('#uname').html();
s=Number($("#trsum").val());
if($("#sign").html()!="+")s-=(s*2);
var rq=$.ajax({url:"pbuy.php",type:"POST",cache:0,dataType:"json",data:{l:l,sum:s}});
rq.done(function(r) {
if(r=="n_a") se();
else
if(!r.m)
{
$("#deposit").html(parseFloat(r.d).toFixed(6));
$("#udeposit").html(parseFloat(r.ad).toFixed(6));
if(s>0)
{hMsg.dMsg("ПЕРЕВОД УСПЕШЕН");
}
else
{hMsg.dMsg("СУММА СНЯТА С БАЛАНСА АККАУНТА");
}
hPan.dMsg('<b>'+mkdt(0,0,1)+'  ID: '+r.id+' Перевод:</b><span> сумма <b>'+s+'</b> логин: <b>'+l+' </b></span>');
}
else hMsg.dMsg(r.m);
});
}
function se(){setTimeout(function(){hMsg.dMsg("Время сессии вышло,<br>Пож. пройдите авторизацию повторно!"),32000});
    setTimeout(function(){window.location.replace("/login.php")},3000);}
function userlist(p=0,c=0,ud=0)
{$(txtHint).html("");
if(!p) $(uinfo).html('');
$.post("getupack.php",{list:1,page:p,s:c,ud:ud},function(r)
{
$(result).html($(r).filter('#lst'));
if(p==0) $(uinfo).html($(r).filter('.box'));
});
}
function loglist(p){$(txtHint).html("");$(uinfo).html('');$.post("undo.php",{list:1,page:p},function(r){$(result).html(r);});}
function racc(){$.post("pbuy.php",{racc:1},function(r){if(r) $(deposit).html(r);else hMsg.dMsg("Произошла ошибка!");});}
function utj(t){var dn=new Date(t*1000);return addnull(dn.getDate(),dn.getMonth()+1,dn.getFullYear(),dn.getHours(),dn.getMinutes())}
function gck(cnm){var r=dc.cookie.match('(^|;) ?'+cnm+'=([^;]*)(;|$)');if(!r)return null;else return(unescape(r[2]))}
function phms(me){var maskList=$.masksSort($.masksLoad("phone-codes.json"),['#'],/[0-9]|#/,"mask");
var maskOpts={inputmask:{definitions:{'#':{validator:"[0-9]",cardinality:1}},clearIncomplete:1,showMaskOnHover:0,autoUnmask:1},match:/[0-9]/,replace:'#',list:maskList,listKey:"mask",};$(me).inputmasks(maskOpts);}
function userslog(p,u){$.post("undo.php",{lst:1,uid:u,page:p},function(r){$(ulist).html(r)})}
function paylog(p){$.post("payments.php",{lst:1,page:p},function(r){$(plist).html(r)})}
function toz(s){var rq=$.ajax({url:"toz.php",type:"POST",cache:0,dataType:"json",data:{i:$("#uid").html(),s:s}});
rq.done(function(r) {
if(r=="n_a") se();
else if(!r.m)
{
$("#cw"+s).html(0);
}
});
}
function write_users_data(arr)
{var vv;if($("#phnm").html()!=(vv=$("#uedit #ph").val())) arr.push({name:'ph',value:vv});
if($("#email").html()!=(vv=$("#uedit #eml").val())) arr.push({name:'eml',value:vv});
if($("#scmnt").attr("data-tooltip") != (vv=$("#uedit #comment").val())) arr.push({name:'comment',value:vv});
arr.push({name:'srv',value:$("#uedit #srv>option:selected").val()});
arr.push({name:'snd',value:$("#uedit #snd:checked").val()})
var values=[];
values=getcards("#uedit");
if(values.length>0)
arr.push({name:'cards',value:JSON.stringify(values)});
if(arr!='')
{
arr.push({name:'un',value:$('#uname').html()});
var rq=$.ajax({url:"uedit.php",type:"POST",cache:0,dataType:"json",data:arr});
rq.done(function(r){if(r=="n_a") se();
if(r.success==1) hMsg.dMsg("ДАННЫЕ СОХРАНЕНЫ");
vr='';
if(values.length>0){for(i=0;i<r.cards.length;i++)
{vr=vr+'<div class=crdnm> <input changed=0 id=\''+r.cards[i].cid+'\' type="text" value="'+r.cards[i].card+'" tmp="'+r.cards[i].card+'" data-owner="' +r.cards[i].owner + '" data-exp="' +r.cards[i].exp +'"  readonly><el class="rm"></el></div>';}}
$("#uedit .bubbleslist").find.html(vr);
$("#upsw").html($("#uedit #ps").val());
h=($("#uedit #srv>option:selected").text()).split(' - ');
$("#server").html(h[0]);
if($('#email'))$('#email').html($("#uedit #eml").val()).parents("tr").show();
if($('#phnm'))$('#phnm').html($("#uedit #ph").val()).parents("tr").show();
var v=$("#uedit #comment").val();
if(v.length>16){$("#scmnt").attr("data-tooltip",v);v=v.substr(0,13)+'...';}
else
$("#scmnt").removeAttr("data-tooltip");
$("#scmnt").html(v);
(v.length) ? $("#scmnt").parents("tr").show():$("#scmnt").parents("tr").hide();});
return false;
}
}

function udel(u,t)
{
$.confirm({
't':'ПОДТВЕРЖДЕНИЕ УДАЛЕНИЯ',
'm':'ВЫ РЕШИЛИ УДАЛИТЬ АККАУНТ '+u+'.<BR/>ПОСЛЕ УДАЛЕНИЯ ЕГО НЕЛЬЗЯ БУДЕТ ВОССТАНОВИТЬ!<BR>ПРОДОЛЖАЕМ?',
'b':{
'ДА':{
'class':'blue',
'action':function(){
var rq=$.ajax({url:"udel.php",type:"POST",cache:0,dataType:"json",data:{u:u}});
rq.done(function(r){
if(r==1 && r!="n_a"){$(t).parent().parent().find('a').removeAttr('href').end().find('td:eq(4)').html("Удалён");
$(t).parent().find('div').removeClass('ui-icon-trash').toggleClass('ui-icon-close').removeAttr('onclick')}
});
}
},
'НЕТ':{'class':'gray','action':function(){}}
}
});
}
(function($){
$.confirm = function(p){
if($('#confirmOverlay').length){return 0}
var buttonHTML='';
$.each(p.b,function(name,obj){
buttonHTML += '<a href="#" class="but '+obj['class']+'">'+name+'</a>';
if(!obj.action){obj.action=function(){};}
});
var markup=[
'<div id="confirmOverlay">',
'<div id="confirmBox">',
'<h1>',p.t,'</h1>',
'<p>',p.m,'</p>',
'<div id="confirmButtons">',
buttonHTML,
'</div></div></div>'
].join('');
$(markup).hide().appendTo('body').fadeIn(200);
var buttons = $('#confirmBox .but'),
i=0;
$.each(p.b,function(name,obj){
buttons.eq(i++).click(function(){obj.action();$.confirm.hide();return 0;});
});
}
$.confirm.hide = function(){$('#confirmOverlay').fadeOut(function(){$(this).remove();});
}})(jQuery);


function getcards(formid)
{
var values=[];
	$(formid+' .crdnm').find("input").each(function()
	{
		if($(this).attr('changed')==1)// || (this.value.length===0 && $(this).attr('id')!==0))
			{ //if(this.value.length==10 && $(this).attr('changed')==1 && $(this).attr('id')!=0 || (this.value.length==0 && $(this).attr('id')!=0))
				//console.log(this.value.length);
				values.push({"id":this.id,"card":this.value,"owner":this.dataset.owner,"exp":this.dataset.exp});
			}
		//else if(this.value.length<16) {$(this).next(".rm").remove();this.remove();
	    //}
});

for(let i=0;i<values.length-1;i++)
{
 for(let ii=i+1;ii<=values.length-1;ii++)
       {
  			console.log("i=" + i +" ii="+ii+" i.card="+values[i].card+" ii.card"+values[ii].card);
         if(values[i].card===values[ii].card)
         {
         		values[ii].card="";
         		console.log("id"+values[ii].id + " " + values[ii].card);
         }
       }

}
  return values;
}


function pfpass()
{
$.confirm({
't':'ВВОД ПАРОЛЯ',
'm':'<label>ВВЕДИТЕ ПАРОЛЬ ПРОФИЛЯ</label><input id="pfps" name="pfps" type="password" class="required password"/>',
'b':{
'ПРОДОЛЖИТЬ':{
'class':'blue',
'action':function(){
if((pfps=$("#pfps").val())!=''){
var values=[];
var arr=$("#pfedit").serializeArray();
arr.push({name:'pp',value:pfps});
/*$('.crdnm').each(function()
{
if(this.value.length==10 || $(this).attr('changed')==1 && $(this).attr('id')!=0 || (this.value.length==0 && $(this).attr('id')!=0))
{
//	console.log($(this).attr('changed'));
	values.push({"id":this.id,"card":this.value});
}
else if(this.value.length<10) {$(this).next(".rm").remove();this.remove(); }
});

for(let i=0;i<values.length-1;i++)
{
 for(let ii=i+1;ii<=values.length-1;ii++)
     {
     console.log("i=" + i +" ii="+ii+" i.card="+values[i].card+" ii.card"+values[ii].card);
        if(values[i].card===values[ii].card)
         {
 	 values[ii].card="";
	console.log("id"+values[ii].id + " " + values[ii].card);
         }
      }
}*/
/*arr=values;
for(let i = 0; i < arr.length;i++) {
         // compare the first and last index of an element
         if(arr.indexOf(arr[i].card) !== arr.lastIndexOf(arr[i].card)) {
          console.log("id"+arr[i].id + " " + arr[i].card);
            // terminate the loop
            break;
         }
      }*/

//const dedupThings = Array.from(values.reduce((m, t) => m.set(t.card, t), new Map()).values());
//console.log(JSON.stringify(dedupThings, null, 4));
values=getcards("#pfedit");
if(values.length>0)
	arr.push({name:'cards',value:JSON.stringify(values)});
var rq=$.ajax({url:"pfed.php",type:"POST",cache:0,dataType:"html",data:arr});
rq.done(function (r) {if(r=="n_a") se();
r=JSON.parse(r);
if(r.success==1) {hMsg.dMsg("ДАННЫЕ СОХРАНЕНЫ");
vr='';
for(i=0;i<r.cards.length;i++)
{
	vr=vr+'<div class=crdnm> <input changed=0 id=\''+r.cards[i].cid+'\' type="text" value="'+r.cards[i].card+'" tmp="'+r.cards[i].card+'" data-owner="' +r.cards[i].owner + '" data-exp="' +r.cards[i].exp +'"  readonly><el class="rm"></el></div>';
}
$("#pfedit .bubbleslist").html(vr);

}
else hMsg.dMsg("ВВЕДЁННЫЙ ПАРОЛЬ</br>НЕ СООТВЕТСТВУЕТ ТЕКУЩЕМУ ПАРОЛЮ");
});
}
}
},
'ОТМЕНА':{'class':'gray','action':function(){}}
}
});
}
$(function(){
$("#ps,#psu").each(function(index,input){
var $input=$(input);
$("<div class=shpass/>").append(
$("<input id="+$input.attr('id')+"sp type='checkbox' class='showpasswordcheckbox'/><label for="+$input.attr('id')+"sp class=switcher></label> ").click(function(){
var change=$(this).is(":checked") ? "text":"password";
$input.attr('type',change);
})
).append(" Показать пароль").insertAfter($input);
});
})
function cpackets(rslt){i=1;while(rslt.find('#p'+i).length) i++; cpckts=i}
function hish_pause(r){

if($("#rq").attr("rq")==1)
{
if($('#psd').attr('ptmt')<=0)$('#pacc:not(.bnd)').addClass('bnd').click(function(){pause()});
$("#rst").click(function(){rset()});
}
else
{
rslt=$(document);
if(r.length) rslt=r;
cpackets(rslt);c=cpckts;show=0;
while(--c){if(rslt.find('#dto'+c).html()!="Не активен") show=1;}
if(show==1){
if($('#psd').attr('ptmt')<=0)$('#pacc:not(.bnd)').addClass('bnd').click(function(){pause()});
$("#rst").click(function(){rset()});
}
else
{
$("#pacc,#rst").removeClass('bnd').unbind("click");
}
}
}
function setupTimer(){if (t==0) t=parseInt($('#st').attr('tm'));$("#st").html(mkdt(t*1000,0,1));if(watches) clearTimeout(watches);watches = setTimeout(setupTimer,60*1000)}
function watch(){
id=1;
while(chck=dc.getElementById('dto'+id))
{
setInterval(function(){t=t+60000;$("#st").html(mkdt(t*1000,0,1));},60000);
}
}
function pfile(){chkfrm($("#pfedit"));cntr($("#pfl"))}
function bal(){paylog(1);cntr($("#paym"))}
function rset(){cntr($('#rset'))}
function cntr(f){$('body').append('<div style="display:flex" id="mask"></div>');f.css("display","flex").fadeIn(200);
$(document).on('click','#mask',function(){$("#mask").fadeOut(200,function(){$('#mask').remove()});f.fadeOut(200)})}
function ued()
{
chkfrm($("#ued"));
phms($('#uedit #ph'));
$('#ue').html($('#uname').html());
$("#ued #dr").html($("#uname").attr("dreg"));
$("#ued #psu").val($("#upsw").html());
$("#ued #eml").val($('#email').html());
$("#ued #ph").val($('#phnm').html());
$("#uedit #tID").val($('#tID').html());
$('#ued #phone_mask').change();
$("#uedit #srv").val($("#server").attr('s'));
if(v=$("#scmnt").attr("data-tooltip"))
$("#uedit #comment").val(v);
else
$("#uedit #comment").val($("#scmnt").html());
$('#ued').css("display","flex");
}
function chkfrm(f)
{
if (!f.length)
{	var rq=$.ajax({url:"pfed.php",type:"POST",cache:0,dataType:"html",data:{get:1}});
	rq.done(function(r){
if(r=="n_a") se();
	else
	 $('#pfl').replaceWith(r); $("#signin #eml").val($(r).find("#eml").attr("value")); });
}
else
{
	$("#signin #eml").val($("#pfedit #eml").val());
}
}

function accop(){$('#ullst').html($('#uname').html());cntr($("#classo"));userslog(0,$('#uid').html())}
var hMsg={setup:function(aTo,lN,mO){hMsg.msgID='hMsg';if(aTo == undefined)aTo = 'body';hMsg.mO=1;if(mO!=undefined)hMsg.mO=parseFloat(mO);$(aTo).append('<div id="'+hMsg.msgID+'" class="hMsg"></div>')},
dMsg:function(msg){if(msg=='')return;clearTimeout(hMsg.t2);$('#'+hMsg.msgID).html(msg);$('#'+hMsg.msgID).show().animate({opacity:hMsg.mO},200);hMsg.t1=setTimeout("hMsg.bindEvents()",700);
hMsg.t2=setTimeout("hMsg.rMsg()",5000);},
bindEvents:function() {$(window).mousemove(hMsg.rMsg).click(hMsg.rMsg).keypress(hMsg.rMsg);},
rMsg:function(){$(window).unbind('mousemove',hMsg.rMsg).unbind('click',hMsg.rMsg).unbind('keypress',hMsg.rMsg)
if(Number($('#'+hMsg.msgID).css('opacity')).toFixed(2)==hMsg.mO)$('#'+hMsg.msgID).animate({opacity:0},200,function(){$(this).hide()})
}
}

function ltuns(s,n) {
var s;
if (n=='pl') e=$("#pl");
if (n=='pr') e=$("#pr");
e.html('');
if(n=="pl"){dch("#pr","Выберите Протокол");dch("#pl","Выберите Плагин");}
if(n=="pr") dch("#pr","Выберите Протокол");
if(!isNaN(parseInt(s.value)))
{$.getJSON('tuns.php',{act:n,v:s.value},	function (pL) {
		$.each(pL,function (i){e.append('<option value="'+pL[i].id+'">'+pL[i].name+'</option>');
			e.prop("disabled",false);
		});
	});}
}
function clts(){dch("#pr","Выберите Протокол");	dch("#pl","Выберите Плагин");$("#rsets").html('')}
function dch(e,s){$(e).html('<option>'+s+'</option>').prop("disabled",true)}

function lrs()
{var rq=$.ajax({url:"tuns.php",type:"POST",cache:0,dataType:"json",data:{rs:$("#tun").val(),pl:$("#pl").val(),pr:$("#pr").val(),u:$("#uname").html()}});
	rq.done(function(d){	$('#rsets').html(d.s.split("\n").join("<br />"));
	fname=d.f;
		if(d.h) {$('#hlp').html(d.h).show();} else $("#hlp").hide();
	})}
function rtmt(){setTimeout(rtmt(),0);T=($("#hMsg").height()+4)/2;L=($("#hMsg").width()+3)/2;$("#hMsg").css({'margin-top':-T,'margin-left':-L})}

$(document).ready(function(){
hMsg.setup();setupTimer();
$("#trsum").on("input blur",function(e){var str=$(this).val(),reg = /[\d\.]/,str=str.replace(",", ".").replace(/^\./, "0.").replace(/^0(\d)/, "$1"),
len=15<str.length?15:str.length,
b=0;
for (; b < len && reg.test(str.charAt(b)); b++) "." == str.charAt(b) && (reg = /\d/, len = b + 3);
e.type=="blur" && (str=str.replace(/\.$/,""))
$(this).val(str.slice(0,b))
});
})
var svAs=svAs||function(e){"use strict";if(typeof e==="undefined"||typeof navigator!=="undefined"&&/MSIE [1-9]\./.test(navigator.userAgent)){return}var t=e.document,n=function(){return e.URL||e.webkitURL||e},r=t.createElementNS("http://www.w3.org/1999/xhtml","a"),o="download"in r,i=function(e){var t=new MouseEvent("click");e.dispatchEvent(t)},a=/constructor/i.test(e.HTMLElement),f=/CriOS\/[\d]+/.test(navigator.userAgent),u=function(t){(e.setImmediate||e.setTimeout)(function(){throw t},0)},d="application/octet-stream",s=1e3*40,c=function(e){var t=function(){if(typeof e==="string"){n().revokeObjectURL(e)}else{e.remove()}};setTimeout(t,s)},l=function(e,t,n){t=[].concat(t);var r=t.length;while(r--){var o=e["on"+t[r]];if(typeof o==="function"){try{o.call(e,n||e)}catch(i){u(i)}}}},p=function(e){if(/^\s*(?:text\/\S*|application\/xml|\S*\/\S*\+xml)\s*;.*charset\s*=\s*utf-8/i.test(e.type)){return new Blob([String.fromCharCode(65279),e],{type:e.type})}return e},v=function(t,u,s){if(!s){t=p(t)}var v=this,w=t.type,m=w===d,y,h=function(){l(v,"writestart progress write writeend".split(" "))},S=function(){if((f||m&&a)&&e.FileReader){var r=new FileReader;r.onloadend=function(){var t=f?r.result:r.result.replace(/^data:[^;]*;/,"data:attachment/file;");var n=e.open(t,"_blank");if(!n)e.location.href=t;t=undefined;v.readyState=v.DONE;h()};r.readAsDataURL(t);v.readyState=v.INIT;return}if(!y){y=n().createObjectURL(t)}if(m){e.location.href=y}else{var o=e.open(y,"_blank");if(!o){e.location.href=y}}v.readyState=v.DONE;h();c(y)};v.readyState=v.INIT;if(o){y=n().createObjectURL(t);setTimeout(function(){r.href=y;r.download=u;i(r);h();c(y);v.readyState=v.DONE});return}S()},w=v.prototype,m=function(e,t,n){return new v(e,t||e.name||"download",n)};if(typeof navigator!=="undefined"&&navigator.msSaveOrOpenBlob){return function(e,t,n){t=t||e.name||"download";if(!n){e=p(e)}return navigator.msSaveOrOpenBlob(e,t)}}w.abort=function(){};w.readyState=w.INIT=0;w.WRITING=1;w.DONE=2;w.error=w.onwritestart=w.onprogress=w.onwrite=w.onabort=w.onerror=w.onwriteend=null;return m}(typeof self!=="undefined"&&self||typeof window!=="undefined"&&window||this.content);if(typeof module!=="undefined"&&module.exports){module.exports.saveAs=saveAs}else if(typeof define!=="undefined"&&define!==null&&define.amd!==null){define([],function(){return saveAs})}
function stof()
{ if(!$('#rsets').is(':empty')) {
var elHtml=br2nl($('#rsets').html());
var blob=new Blob([elHtml], {type: "text/plain;charset=utf-8"});
svAs(blob,fname);
}
}
function nws(){clr();var rq=$.ajax({url:"news.php",type:"POST",cache:0,dataType:"html"});rq.done(function(d){$(result).html(d);})}
function clr(){$(uinfo,result).html('')}
function br2nl(str){return str.replace(/<br\s*\/?>/mg,"\n")}
function swreq(){
$.confirm({
't':'СМЕНИТЬ РЕЖИМ УЧЁТКИ',
'm':'ВЫ РЕШИЛИ СМЕНИТЬ РЕЖИМ УЧЁТКИ, ВЫ УВЕРЕНЫ?',
'b':{
'ДА':{
'class':'blue',
'action':function(){
	var rq=$.ajax({url:"getupack.php",type:"POST",cache:0,dataType:"html",data:{l:$('#uname').html(),sw:1}});
rq.done(function(r){
if (!r) {hMsg.dMsg("АККАУНТ С ТАКИМ ЛОГИНОМ НЕ НАЙДЕН!")}
else
{$("#tun").val("Тюнер").change();clts();
mc=$(r).filter('#mc');
nfo=$(r).filter('.blk');
sts=$(r).filter('#Layer1');
$("#uinfo").html(nfo).fadeIn();
$("#result").html(mc).fadeIn();
if(sts)$("#uinfo .blk").after(sts);
spb($("#psd").val());
if((timer=$("#psd").attr("ptmt"))<=0) hish_pause(0);
else {
if(tmt!=0) clearTimeout(tmt);
tmt=setTimeout(hish_pause(0),timer*1000);
}
}
$(".sb").show();
});

}
},
'НЕТ':{'class':'gray','action':function(){alert("NO!");}}
}
});
}
function prl(){$("body").prepend("<div id='spin'style='z-index:1000;width:100%;height:100%;position:fixed'><span id='spinner' class='spinner' style='top:45%;left:50%'></span><span id='spinner2' class='spinn' style='top:45%;left:50%'> </span></div>").fadeIn(500);}
function packetp()
{
$(txtHint).html("").hide();
$("#uinfo").html();
var rq=$.ajax({url:"getupack.php",type:"POST",cache:0,dataType:"html",data:{price:1}});
rq.done(function(r){$("#result").html(r).fadeIn();});
}
function tOn(e){event=e || window.event;const target = event.target || event.srcElement;const row = $(target).closest("tr");
const checkbox = row.find("input[type='checkbox']");if (checkbox.length && checkbox.prop("disabled")) {
return;}const isSelected = row.hasClass("selected");row.toggleClass("selected", !isSelected);if (checkbox.length) {checkbox.prop("checked", !isSelected);}UC();}

function getuser(i, l, p = "") {
    if (!l) l = $("#glog").val().trim();
    if (l.length == 0 || l == "Введите логин") return;
    if (tmtstr2) { clearTimeout(tmtstr2); tmtstr2 = 0; }

    $("#txtHint").html("").hide();
    $(".sb, .s_res").hide();
    $(".circle").show();

    var rq = $.ajax({
        url: "px.php",
        type: "POST",
        cache: false,
        dataType: "json",
        data: { l: l, p: p }
    });

    rq.done(function(data) {
        if (data.status === "error") {
            hMsg.dMsg(data.message || "АККАУНТ С ТАКИМИ ДАННЫМИ НЕ НАЙДЕН");
            return;
        }

        const session = data.data.session || { a: 0 };

        if (data.data.multiple) {
            let html = '<tr><td colspan=2 align=center class=title>НАЙДЕНО ПО НОМЕРУ ТЕЛЕФОНА или EMAIL</td></tr>';
            data.data.results.forEach(result => {
                html += `<tr><td><a href="javascript:getuser(0,'${result.user}')">${result.user}</a></td><td align=right>${result.phone || result.email}</td></tr>`;
            });
            $(".s_res").html(html).show();
        } else {
            $("#tun").val("Тюнер").change();
            clts();

            const account = data.data.account;
            const dealer = data.data.dealer;
            const iptv = data.data.iptv || {};

            let infoHtml = `
                <div class="blk finr">
                    <h2>ИНФО ПО <el id="uname" dreg="${account.dreg}" acccardnum="${account.acccardnum}">${account.user}</el></h2>
                    <table id="tinfo" border="0">
                        <tr><td align=right style="width:25%">ID:</td><td id="uid">${account.id}</td></tr>
                        <tr><td align=right id="rq">Учётка:</td><td>${account.req != 0 ? "Позапросная" : (iptv ? "IPTV" : "Стандартная")}</td></tr>
                        <tr><td align=right id="accsum">Баланс:</td><td>${parseFloat(account.sum).toFixed(2)}</td></tr>
            `;

            if (dealer.user === session.i || session.a || dealer.id === session.i) {
                infoHtml += `
                    <tr ${!account.phone ? 'style="display:none"' : ''}><td align=right>Тел.#:</td><td id="phnm">${account.phone || ''}</td></tr>
                    <tr ${!account.tcid ? 'style="display:none"' : ''}><td align=right>tId:</td><td id="tID">${account.tcid || ''}</td></tr>
                    <tr ${!account.email ? 'style="display:none"' : ''}><td align=right>Email:</td><td id="email">${account.email || ''}</td></tr>
                    <tr ${!account.dscr ? 'style="display:none"' : ''}><td align=right>Комент:</td><td id="scmnt" data-tooltip="${account.dscr || ''}" data-tooltip-position="left">${account.dscr && account.dscr.length > 16 ? account.dscr.substr(0, 13) + '...' : (account.dscr || '')}</td></tr>
                    <tr><td align=center colspan=2><button onclick="accop()">ОПЕРАЦИИ ПО АККАУНТУ</button></td></tr>
                    ${Object.keys(iptv).length > 0 ? `<tr><td align=center colspan=2><button onclick="getuser(0,'${account.user}','s')">ШАРИНГ</button></td></tr>` 
                                                  : `<tr><td align=center colspan=2><button onclick="iptv('${account.user}')">IPTV</button></td></tr>`}
                `;
            }
            infoHtml += `</table></div>`;

            let mcHtml = '';
            if (data.data.iptv) {
                const iptvAccount = iptv.account || {};
                const iptvurl = iptv.iptvurl || '';
                const locations = iptv.locations || [];
                const packets = iptv.packets || [];
                const playlists = iptv.playlists || {};
                const iptvenddate = iptv.iptvenddate || 0;
                const tz = iptv.tz || 0;

                mcHtml += `
                    <div id="mc" class="fin">
                        <style>
                            .tabcontent { display: none; padding-top: 10px; padding-bottom: 20px; border-radius: 8px; }
                            .active-tab { display: block; margin-top: 12px; margin-bottom: 23px; border: 1px solid #284040; }
                            .selected { background-color: #99ccdd; }
                        </style>
                        <div style="display: flex; align-content: center; flex-wrap: wrap; flex-direction: row; justify-content: flex-start; color: #ffffff;">
                            <div class="iptv url">URL <input id="url" type="text" value="${iptvurl}"/><button id="copyButton"><img src="/copy.png" alt="Копировать"></button></div>
                            <div class="iptv cdn"><div class="iptvsrv">СЕРВЕР ПОДКЛЮЧЕНИЯ</div>
                                <select id="iptvsrv" name="iptvsrv">
                                    ${locations.map(loc => `<option value="${loc.option_value}" ${loc.option_value == iptvAccount.iptvcdn ? 'selected' : ''}>${loc.option_text}</option>`).join('')}
                                </select>
                                <div class="iptvbtn"><button id="changecdn" disabled=disabled>СМЕНИТЬ</button></div>
                            </div>
                            <div class="iptv"><div class="endd">Дата окончания подписки<div>
                                <input id="enddate" type="text" value="${iptvenddate && iptvenddate >= Date.now() / 1000 ? mkdt((iptvenddate - (tz * 60)) * 1000, 0, 1) : 'Не активен'}"/>
                            </div></div></div>
                            <div class="iptvcntr"><div class="actbutt"><div id="activateButton">
                                ${iptvenddate && iptvenddate >= Date.now() / 1000 ? 'ПРОДЛИТЬ' : 'АКТИВИРОВАТЬ'} ПОДПИСКУ НА
                            </div>
                            <div style="display: flex; align-items: center;" onclick="tDrpd()">
                                <div class="selnum">1</div>
                                <div class="mnths"> МЕС</div>
                            </div></div>
                            <div class="dropdown-content" id="myDropdown">
                                ${[1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12].map(m => `<a onclick="sMn(${m})">${m}</a>`).join('')}
                            </div></div>
                        </div>
                        <div class="playlists" id="40">
                            <button id="tabbut1" class="tablinks ${iptvAccount.grpvariant == 1 ? 'acttab' : ''}" onclick="openTab(this.id,'tab1')">Плейлист 1</button>
                            <button id="tabbut2" class="tablinks ${iptvAccount.grpvariant == 2 ? 'acttab' : ''}" onclick="openTab(this.id,'tab2')">Плейлист 2</button>
                            <div class="pprc">Пакет ${packets[0]?.pname || ''}, стоимость ${packets[0] ? calculatePrice(packets[0], session) : ''}</div>
                            <div id="tab1" class="tabcontent ${iptvAccount.grpvariant == 1 ? 'active-tab' : ''}">
                                <button class="invert-button" onclick="invertSelection(this)">Отметить всё | Инвертировать</button>
                                <ul id="tab1-list">
                                    ${playlists[1]?.map(grp => `<li onclick="selectListItem(this)" value="${grp.grpid}" ${grp.selected ? "class='selected'" : ""}>${grp.grpname}</li>`).join('') || ''}
                                </ul>
                            </div>
                            <div id="tab2" class="tabcontent ${iptvAccount.grpvariant == 2 ? 'active-tab' : ''}">
                                <button class="invert-button" onclick="invertSelection(this)">Отметить всё | Инвертировать</button>
                                <ul id="tab2-list">
                                    ${playlists[2]?.map(grp => `<li onclick="selectListItem(this)" value="${grp.grpid}" ${grp.selected ? "class='selected'" : ""}>${grp.grpname}</li>`).join('') || ''}
                                </ul>
                            </div>
                            <button onclick="saveJSON()">СОХРАНИТЬ</button>
                        </div>
                    </div>
                `;
            } else if (data.data.packets) {
                mcHtml += `
                    <div id="mc" class="fin">
                        <input type=hidden id="psd" value="${account.paused}" ptmt="${data.data.tmt}">
                    </div>
                `;
                // Вызов функции рендеринга подписок
                renderSubscriptions(account.user);
            }

            // Вызов админских фрагментов до вставки в DOM
            if (typeof getuserAdmin === 'function') {
                const updatedHtml = getuserAdmin(data, infoHtml, mcHtml);
                infoHtml = updatedHtml.infoHtml;
                mcHtml = updatedHtml.mcHtml;
            }

            $("#uinfo").html(infoHtml).fadeIn();
            $("#result").html(mcHtml).fadeIn();

            $(".sb").show();
            $(".circle").hide();
        }
    });

    rq.fail(function(jqXHR, textStatus, errorThrown) {
        console.error("Ошибка AJAX:", textStatus, errorThrown);
        $(".sb").show();
        $(".circle").hide();
    });
}

// Глобальные переменные для подписок
let selectedItems = [];
let totalAmount = 0;
let allPackets = [];

function updatePayButton() {
    document.getElementById('totalAmount').textContent = `${totalAmount.toFixed(2)} $`;
}

function updateItemStates(clickedItem) {
    const clickedId = clickedItem.id;
    const isSelected = clickedItem.classList.contains('selected');
    const clickedPacket = allPackets.find(p => p.id == clickedId);

    allPackets.forEach(packet => {
        const item = document.getElementById(packet.id);
        if (!item) return;

        if (packet.id === clickedId && isSelected) return;

        let shouldDisable = false;

        if (isSelected && clickedPacket.components && clickedPacket.components.length > 0) {
            if (clickedPacket.components.includes(packet.id)) {
                shouldDisable = true;
            }
        }

        if (isSelected && packet.components && packet.components.includes(clickedId)) {
            shouldDisable = true;
        }

        if (isSelected && clickedPacket.components && clickedPacket.components.length > 0) {
            if (packet.components && packet.components.length > 0 && packet.id !== clickedId) {
                const hasIntersection = clickedPacket.components.some(id => packet.components.includes(id));
                if (hasIntersection) {
                    shouldDisable = true;
                }
            }
        }

        if (shouldDisable) {
            item.classList.remove('selected');
            item.classList.add('disabled');
            item.querySelector('.checkmark').style.display = 'none';
            removeFromSelected(packet.id);
        } else if (!isConflicting(packet.id) && !item.classList.contains('selected')) {
            item.classList.remove('disabled');
        }
    });
}

function isConflicting(packetId) {
    return selectedItems.some(selected => {
        const selectedPacket = allPackets.find(p => p.id == selected.id);
        if (selectedPacket.components && selectedPacket.components.includes(packetId)) {
            return true;
        }
        const packet = allPackets.find(p => p.id == packetId);
        if (packet.components && packet.components.includes(selected.id)) {
            return true;
        }
        if (selectedPacket.components && packet.components) {
            return selectedPacket.components.some(id => packet.components.includes(id));
        }
        return false;
    });
}

function removeFromSelected(id) {
    const index = selectedItems.findIndex(item => item.id === id);
    if (index !== -1) {
        totalAmount -= selectedItems[index].price;
        selectedItems.splice(index, 1);
    }
}

async function fetchSubscriptions(l) {
    try {
        let response = await fetch(`/get_subscriptions.php?l=${encodeURIComponent(l)}`);
        let data = await response.json();
        let html = '<div class="pay-button-container"><button class="pay-button" id="payButton">ОПЛАТИТЬ <span id="totalAmount">0 $</span></button></div>';
        allPackets = data.price_list;

        const minDays = data.mindays || 30;
        console.log("minDays из ответа:", minDays);

        data.price_list.forEach(packet => {
            let isActive = packet.is_active;
            let itemClass = `subscription-item ${isActive ? 'selected' : ''}`;
            let price = parseFloat(packet.price);

            if (isActive) {
                totalAmount += price;
                selectedItems.push({ id: packet.id, price: price });
            }

            let formattedDstart = '';
            let formattedDend = '';
            if (isActive && packet.dstart && packet.dend) {
                const dstartDate = new Date(packet.dstart * 1000);
                const dendDate = new Date(packet.dend * 1000);

                formattedDstart = dstartDate.toLocaleString('ru-RU', {
                    day: '2-digit',
                    month: '2-digit',
                    year: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                }).replace(',', '');

                formattedDend = dendDate.toLocaleString('ru-RU', {
                    day: '2-digit',
                    month: '2-digit',
                    year: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                }).replace(',', '');
            }

            html += `
                <div id="${packet.id}" class="${itemClass}" data-price="${packet.price}">
                    <span class="checkmark" style="display: ${isActive ? 'block' : 'none'}">✔</span>
                    <div class="flex-container">
                        <div class="flex items-center space-x-3">
                            <!--<img src="/logos/${packet.pname.toLowerCase()}.png" alt="${packet.pname}" class="logo-image">-->
                        </div>
                        <div class="packet_name">
                            <div>${packet.pname}</div>
                        </div>
                        <button class="stopButton" ${!isActive && !packet.dstart && !packet.dend ? 'style="display:none"' : ''}>Стоп</button>
                        <div class="price">${packet.price} $</div>
                    </div>
                    <div class="mt-3 space-y-1" ${!isActive && !packet.dstart && !packet.dend ? 'style="display:none"' : ''}>
                        <div class="flex justify-between-end text-xs text-gray-600 actendinfo">
                            <span class="activation">Дата активации:</span>
                            <span class="font-medium" id="activation">${formattedDstart}</span>
                        </div>
                        <div class="flex justify-between-end text-xs text-gray-600 actendinfo">
                            <span class="dend">Отключится:</span>
                            <span class="font-medium" id="dend" data-timestamp="${packet.dend}">${formattedDend}</span>
                        </div>
                    </div>
                    <div class="flex justify-between-end text-xs text-gray-600">
                        <span for="months" class="block text-xs">
                            ${isActive && packet.dstart && packet.dend ? 'Продлить до:' : 'Активировать до:'}
                        </span>
                        <input class="font-medium enddate datepicker-input" name="end_date" readonly="readonly">
                    </div>
                </div>
            `;
        });
        return { html, minDays };
    } catch (error) {
        console.error("Ошибка загрузки данных:", error);
        return { html: '<div>Ошибка загрузки подписок</div>', minDays: 30 };
    }
}

function calculateTotalAmount() {
    totalAmount = 0;
    dc.querySelectorAll('.subscription-item.selected').forEach(item => {
        const pricePerDay = parseFloat(item.getAttribute('data-price'));
        const datepickerInput = item.querySelector('.datepicker-input');
        const selectedDateStr = datepickerInput.value;
        const isActive = item.classList.contains('selected') && item.querySelector('#dend');

        const [day, month, year] = selectedDateStr.split('.');
        const selectedDate = new Date(`${year}-${month}-${day}`);
        selectedDate.setHours(0, 0, 0, 0);

        let baseDate;
        let daysDiff;

        if (isActive) {
            const dendElement = item.querySelector('#dend');
            let dendTimestamp;

            if (dendElement.getAttribute('data-timestamp') !== '') {
                dendTimestamp = parseInt(dendElement.getAttribute('data-timestamp')) * 1000;
            } else {
                const dendText = dendElement.textContent.trim();
                const [datePart, timePart] = dendText.split(' ');
                const [dendDay, dendMonth, dendYear] = datePart.split('.');
                baseDate = new Date(`${dendYear}-${dendMonth}-${dendDay}`);
                if (isNaN(baseDate)) {
                    baseDate = new Date();
                }
                dendTimestamp = baseDate.getTime();
            }
            baseDate = new Date(dendTimestamp);
        } else {
            baseDate = new Date();
        }
        baseDate.setHours(0, 0, 0, 0);
        daysDiff = Math.ceil((selectedDate - baseDate) / (1000 * 60 * 60 * 24));
        const packetCost = (pricePerDay / 30) * daysDiff;
        totalAmount += packetCost >= 0 ? packetCost : 0;
    });
    dc.getElementById('totalAmount').textContent = `${totalAmount.toFixed(2)} $`;
}

async function renderSubscriptions(login) {
    selectedItems = [];
    totalAmount = 0;
    allPackets = [];

    const { html, minDays } = await fetchSubscriptions(login);
    const listContainer = document.getElementById('result');
    listContainer.innerHTML = html;

    dc.querySelectorAll('.datepicker-input').forEach(input => {
        const parentDiv = input.closest('.subscription-item');
        const isActive = parentDiv.classList.contains('selected');
        let defaultDate;

        if (isActive) {
            const dendElement = parentDiv.querySelector('#dend');
            const dendTimestamp = dendElement ? parseInt(dendElement.getAttribute('data-timestamp')) : null;

            if (dendTimestamp) {
                const dendDate = new Date(dendTimestamp * 1000);
                if (isNaN(dendDate)) {
                    defaultDate = new Date();
                } else {
                    defaultDate = new Date(dendDate);
                    defaultDate.setTime(defaultDate.getTime() + minDays * 24 * 60 * 60 * 1000);
                }
            } else {
                defaultDate = new Date();
                defaultDate.setTime(defaultDate.getTime() + minDays * 24 * 60 * 60 * 1000);
            }
        } else {
            defaultDate = new Date();
            defaultDate.setTime(defaultDate.getTime() + minDays * 24 * 60 * 60 * 1000);
        }

        const formattedDefaultDate = defaultDate.toLocaleDateString('ru-RU', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric'
        });
        input.value = formattedDefaultDate;

        if ($(input).data('ui-datepicker')) {
            return;
        }

        const datepickerInstance = $(input).datepicker({
            dateFormat: 'dd.mm.yy',
            minDate: defaultDate,
            defaultDate: defaultDate,
            showOn: 'focus',
            onSelect: function(dateText, inst) {
                calculateTotalAmount();
            },
            beforeShow: function(input, inst) {
                $(inst.dpDiv).on('click', function(e) {
                    e.stopPropagation();
                });
            }
        });

        $(input).on('click', function(e) {
            e.stopPropagation();
        });
    });

    dc.querySelectorAll('.stopButton').forEach(button => {
        button.addEventListener('click', function(e) {
            e.stopPropagation();
        });
    });

    dc.querySelectorAll('.subscription-item').forEach(item => {
        item.addEventListener('click', function(e) {
            if (this.classList.contains('disabled')) return;

            this.classList.toggle('selected');
            let checkmark = this.querySelector('.checkmark');
            checkmark.style.display = this.classList.contains('selected') ? 'block' : 'none';

            if (this.classList.contains('selected')) {
                selectedItems.push({ id: this.id, price: parseFloat(this.dataset.price) });
            } else {
                removeFromSelected(this.id);
            }
            updateItemStates(this);
            calculateTotalAmount();
        });
    });

    allPackets.forEach(packet => {
        if (packet.is_active) {
            updateItemStates(document.getElementById(packet.id));
        }
    });

    dc.getElementById('payButton').addEventListener('click', function() {
        const uid = dc.getElementById('uid').textContent.trim();
        let snd = [];
        dc.querySelectorAll('.subscription-item.selected').forEach(item => {
            const packetId = item.id;
            const datepickerInput = item.querySelector('.datepicker-input');
            const selectedDateStr = datepickerInput.value;
            const dendElement = item.querySelector('#dend');
            const isActive = dendElement && dendElement.getAttribute('data-timestamp') !== '';

            const [day, month, year] = selectedDateStr.split('.');
            const fullYear = year.length === 2 ? `20${year}` : year;
            const selectedDate = new Date(`${fullYear}-${month}-${day}`);
            selectedDate.setHours(0, 0, 0, 0);

            let baseDate;
            let daysDiff;

            if (isActive) {
                const dendTimestamp = parseInt(dendElement.getAttribute('data-timestamp')) * 1000;
                baseDate = new Date(dendTimestamp);
                baseDate.setHours(0, 0, 0, 0);
                daysDiff = Math.ceil((selectedDate - baseDate) / (1000 * 60 * 60 * 24));
            } else {
                baseDate = new Date();
                baseDate.setHours(0, 0, 0, 0);
                daysDiff = Math.ceil((selectedDate - baseDate) / (1000 * 60 * 60 * 24));
            }
            snd.push([packetId, daysDiff, 0]);
        });

        $.ajax({
            url: "pbuy.php",
            type: "POST",
            cache: false,
            dataType: "json",
            data: { uid: uid, pb: snd }
        })
        .then(response => {
            console.log("Успех:", response);

            const currentDate = new Date();
            const formattedCurrentDate = currentDate.toLocaleString('ru-RU', {
                day: '2-digit',
                month: '2-digit',
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            }).replace(',', '');

            const minDays = response.md || 30;

            const depositElement = dc.getElementById('deposit');
            if (depositElement && response.sum !== undefined) {
                depositElement.textContent = `${response.sum} $`;
            }

            snd.forEach(([packetId, daysDiff]) => {
                const item = dc.getElementById(packetId);
                if (!item) return;

                const activationSpan = item.querySelector('#activation');
                if (activationSpan) {
                    activationSpan.textContent = formattedCurrentDate;
                }

                const dendSpan = item.querySelector('#dend');
                const datepickerInput = item.querySelector('.datepicker-input');
                const stopButton = item.querySelector('.stopButton');
                let newDendDate;

                const wasInactive = !dendSpan || dendSpan.getAttribute('data-timestamp') === '';

                if (dendSpan && dendSpan.getAttribute('data-timestamp') !== '') {
                    const currentDendTimestamp = parseInt(dendSpan.getAttribute('data-timestamp')) * 1000;
                    newDendDate = new Date(currentDendTimestamp);
                    newDendDate.setDate(newDendDate.getDate() + daysDiff);
                } else {
                    const [day, month, year] = datepickerInput.value.split('.');
                    const fullYear = year.length === 2 ? `20${year}` : year;
                    newDendDate = new Date(`${fullYear}-${month}-${day}`);
                    newDendDate.setHours(currentDate.getHours(), currentDate.getMinutes(), 0, 0);
                }
                const formattedNewDend = newDendDate.toLocaleString('ru-RU', {
                    day: '2-digit',
                    month: '2-digit',
                    year: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                }).replace(',', '');
                dendSpan.textContent = formattedNewDend;
                const newDendUnix = Math.floor(newDendDate.getTime() / 1000);
                dendSpan.setAttribute('data-timestamp', newDendUnix);
                const newDefaultDate = new Date((newDendUnix + Number(minDays) * 86400) * 1000);
                const formattedNewDefaultDate = newDefaultDate.toLocaleDateString('ru-RU', {
                    day: '2-digit',
                    month: '2-digit',
                    year: 'numeric'
                });
                datepickerInput.value = formattedNewDefaultDate;

                $(datepickerInput).datepicker('option', {
                    minDate: newDefaultDate,
                    defaultDate: newDefaultDate
                });
                if (wasInactive) {
                    const activateLabel = item.querySelector('span[for="months"]');
                    if (activateLabel) {
                        activateLabel.textContent = 'Продлить до:';
                    }
                }

                if (wasInactive && stopButton) {
                    stopButton.style.display = 'block';
                }

                const dateBlock = item.querySelector('.mt-3.space-y-1');
                if (dateBlock) {
                    dateBlock.style.display = 'block';
                }

                item.classList.add('selected');
                const checkmark = item.querySelector('.checkmark');
                if (checkmark) {
                    checkmark.style.display = 'block';
                }
            });

            calculateTotalAmount();
        })
        .catch(error => {
            console.error("Ошибка:", error.status, error.statusText);
            console.error("Текст ответа:", error.responseText);
        });
    });

    dc.querySelectorAll('.stopButton').forEach(button => {
        button.addEventListener('click', function() {
            const item = button.closest('.subscription-item');
            const packetId = item.id;
            const uid = dc.getElementById('uid').textContent.trim();

            $.ajax({
                url: "undo.php",
                type: "POST",
                cache: false,
                dataType: "json",
                data: { uid: uid, stop: packetId }
            })
            .then(response => {
                console.log("Успех (Stop):", response);

                const activationSpan = item.querySelector('#activation');
                const dendSpan = item.querySelector('#dend');
                const datepickerInput = item.querySelector('.datepicker-input');
                const stopButton = item.querySelector('.stopButton');
                const checkmark = item.querySelector('.checkmark');
                const minDays = response.md || 30;

                if (response.s === "1" && response.r) {
                    const activationDate = new Date(response.r.s * 1000);
                    const endDate = new Date(response.r.e * 1000);

                    const formattedActivation = activationDate.toLocaleString('ru-RU', {
                        day: '2-digit',
                        month: '2-digit',
                        year: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit'
                    }).replace(',', '');
                    const formattedEnd = endDate.toLocaleString('ru-RU', {
                        day: '2-digit',
                        month: '2-digit',
                        year: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit'
                    }).replace(',', '');

                    if (activationSpan) {
                        activationSpan.textContent = formattedActivation;
                    }
                    if (dendSpan) {
                        dendSpan.textContent = formattedEnd;
                        dendSpan.setAttribute('data-timestamp', response.r.e);
                    }

                    const daysDiff = 1;
                    const daysInMs = (Number(daysDiff) + Number(minDays)) * 86400;
                    const newDefaultDate = new Date((response.r.e + daysInMs) * 1000);
                    const formattedNewDefaultDate = newDefaultDate.toLocaleDateString('ru-RU', {
                        day: '2-digit',
                        month: '2-digit',
                        year: 'numeric'
                    });
                    datepickerInput.value = formattedNewDefaultDate;

                    $(datepickerInput).datepicker('option', {
                        minDate: newDefaultDate,
                        defaultDate: newDefaultDate
                    });

                    const activateLabel = item.querySelector('span[for="months"]');
                    if (activateLabel) {
                        activateLabel.textContent = 'Продлить до:';
                    }
                } else if (response.s === "0" && response.r && response.r.e === 0) {
                    if (checkmark) {
                        checkmark.style.display = 'none';
                    }
                    if (stopButton) {
                        stopButton.style.display = 'none';
                    }

                    const currentDate = new Date();
                    const daysInMs = Number(minDays) * 86400;
                    const newDefaultDate = new Date((Math.floor(currentDate.getTime() / 1000) + daysInMs) * 1000);
                    const formattedNewDefaultDate = newDefaultDate.toLocaleDateString('ru-RU', {
                        day: '2-digit',
                        month: '2-digit',
                        year: 'numeric'
                    });
                    datepickerInput.value = formattedNewDefaultDate;

                    $(datepickerInput).datepicker('option', {
                        minDate: currentDate,
                        defaultDate: newDefaultDate
                    });

                    const activateLabel = item.querySelector('span[for="months"]');
                    if (activateLabel) {
                        activateLabel.textContent = 'Активировать до:';
                    }
                }

                const depositElement = dc.getElementById('deposit');
                if (depositElement && response.sum !== undefined) {
                    depositElement.textContent = `${response.sum} $`;
                }

                calculateTotalAmount();
            })
            .catch(error => {
                console.error("Ошибка (Stop):", error.status, error.statusText);
                console.error("Текст ответа:", error.responseText);
            });
        });
    });

    calculateTotalAmount();
    updatePayButton();
}



function parseUserAgent(userAgent) {
    const parsedData = {};

    // Проверка на наличие "Model/..." и детальный разбор
    const modelMatch = userAgent.match(/Model\/([\w\-\.]+) VIDAA\/([\d\.]+)\s*\(([^;]+);([^;]+);([^;]+);(.*?)\)(.*)/);
    if (modelMatch) {
        parsedData['Model'] = modelMatch[1];
        parsedData['OS Ver'] = modelMatch[2];
        parsedData['Brand'] = modelMatch[3];
        parsedData['DevType'] = modelMatch[4];
        parsedData['TVModel'] = modelMatch[5];
        parsedData['CPU&Soft Ver'] = modelMatch[6];
        parsedData['Other'] = modelMatch[7].trim().replace(/;$/, '');
    } else {
        // Основные паттерны
        const patterns = [
            [/Mozilla\/5\.0 \((.*?)\) (.*)/, (matches) => ({ 'OS': matches[1], 'Browser': matches[2] })],
            [/Dalvik\/([\d\.]+) \((.*?)\)/, (matches) => ({ 'Dalvik Ver': matches[1], 'OS': matches[2] })],
            [/Wget\/([\d\.]+) \((.*?)\)/, (matches) => ({ 'Wget Ver': matches[1], 'OS': matches[2] })],
            [/Player \((.*?)\)/, (matches) => ({ 'OS': matches[1], 'Player': 'Generic' })],
            [/OTT TV\/([\d\.]+) \((.*?)\)/, (matches) => ({ 'OTT TV Ver': matches[1], 'OS': matches[2] })],
            [/Mozilla\/5\.0 \((.*?)\) Android/, (matches) => ({ 'OS': matches[1], 'Type': 'Android Device' })],
            [/(.*?) \((.*?)\)/, (matches) => ({ 'Generic Agent': matches[1], 'OS': matches[2] })]
        ];

        for (let [pattern, callback] of patterns) {
            const matches = userAgent.match(pattern);
            if (matches) {
                Object.assign(parsedData, callback(matches));
                break;
            }
        }
    }

    if (Object.keys(parsedData).length > 0) {
        return Object.entries(parsedData).map(([key, value]) => `${key}: ${value}`).join('\n');
    }
    return "User-Agent не удалось распарсить.";
}

// Функция provFromIp (предполагается, что она определена где-то ещё)
function provFromIp(ip) {
    // Реализация зависит от твоей логики, например, запрос к API или локальная база
    return ip; // Заглушка, замени на реальную функцию
}

// Вспомогательные функции
function calculatePrice(packet, session) {
    const c = parseInt(session.c);
    const a = parseInt(session.a);

    if (c == 0 && (a == 1 || a == 0)) return packet.price;
    if (c == 1 && a == 2) return packet.paynet;
    if (c == 0 && a == 3) return packet.special;
    if (c == 0 && a == 4) return packet.special2;
    if (c == 0 && a == 5) return packet.t;
    if (c == 0 && a == 6) return packet.tdj;
    if (c == 0 && a == 7) return packet.trk;
    if (c == 0 && a == 8) return packet.dollar;
    if (c == 0 && a == 9) return packet.muha;
    if (c == 1 && a == 10) return packet.olim;
    if (c == 1 && a == 11) return packet.borya73;
    return packet.sum;
}

function table_row_format(i, active_packet) {
    if (i & 1) { // Нечетное i (аналог $row_counter & 1 в PHP)
        if (active_packet == 1) return "ra3";
        else if (active_packet == 2) return "rd1";
        else if (active_packet == 3) return "psd2";
        else return "rw1";
    } else { // Четное i
        if (active_packet == 1) return "ra4";
        else if (active_packet == 2) return "rd2";
        else if (active_packet == 3) return "psd1";
        else return "rw2";
    }
}

/*function buyp() {
    const selectedPackets = $("input[name='ptol']:checked").map(function() {
        return { id: $(this).val(), dtto: $(this).closest('tr').find('input[id^="dtol"]').val() };
    }).get();
    $.ajax({
        url: "cdc.php",
        type: "POST",
        dataType: "json",
        data: { uid: $("#uid").text(), packets: selectedPackets },
        success: function(r) {
            if (r.sum !== undefined) {
                $("#accsum").text(parseFloat(r.sum).toFixed(2));
                hMsg.dMsg("ОПЛАТА ПРОШЛА УСПЕШНО");
                getuser(0, $("#uname").text());
            } else {
                hMsg.dMsg(r.m || "Ошибка оплаты");
            }
        }
    });
}*/

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

