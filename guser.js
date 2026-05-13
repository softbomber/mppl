const dc=document;
var tmt=0,tmtstr=0,tmtstr2=0,vip,nfirst=0,cpckts,t=0,watches=0,pp=0;
var mass=new Array(0);
var dsbl=new Array(0);
let selectedItems = [];
let totalAmount = 0;
let allPackets = [];
let minDays=30;
let currentUser = null;
let currentRow = null;
var fname='';
var timeout=null;
pp=gck("pp");
var adm=gck("a");
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
if($("#"+elemid+" .crdnm").length <3 || deletedel)	
{
 dc.querySelector('.cc__form').reset();
 e=dc.querySelector(".cc");
 $('body').append('<div id="fmask"></div>');
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
	$('body').append('<div id="fmask"></div>');
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
function pause() {
prl();
$.ajax({url: "pause.php",type: "POST",cache: false,dataType:"json",data:{un:$('#uname').html()}}).done(function(r) {
if (!r.success) {hMsg.dMsg(r.m);return;}
const packets = r.packets;
if ($("#pacc").hasClass('ui-icon-play')){$("#pauseOverlay").hide();
packets.forEach(packet => {const [packetId, startTs, endTs] = packet;
const i=dc.querySelector(`div.sitem[id="${packetId}"]`);
if (!i) return;
const startDate = new Date(startTs * 1000);
const endDate = new Date(endTs * 1000);
const fStart = startDate.toLocaleString('ru-RU', {
    day: '2-digit', month: '2-digit', year: 'numeric',
    hour: '2-digit', minute: '2-digit'
}).replace(',', '');
const fEnd = endDate.toLocaleString('ru-RU', {
    day: '2-digit', month: '2-digit', year: 'numeric',
    hour: '2-digit', minute: '2-digit'
}).replace(',', '');
i.querySelector('#dend').innerHTML = fEnd;
i.querySelector('#activation').innerHTML = fStart;
i.setAttribute('data-timestamp', endTs);
const newDefDate = new Date((endTs + Number(minDays) * 86400) * 1000);
const fNewDefDate = newDefDate.toLocaleDateString('ru-RU', {
    day: '2-digit', month: '2-digit', year: 'numeric'
});
const dpInput = i.querySelector('.datepicker-input');
dpInput.value = fNewDefDate;
$(dpInput).datepicker('option', {
    minDate: newDefDate,
    defaultDate: newDefDate
});
});
spb(0);
hMsg.dMsg("АККАУНТ СНЯТ С ПАУЗЫ");
$("#pacc").unbind("click");
if (!tmtstr2){
tmtstr2 = setTimeout(() => {tmtstr2 = 0;stu('c');}, 7000);
}
} else {$("#pauseOverlay").show();spb(1);$("#Layer1").hide();hMsg.dMsg("АККАУНТ ПОСТАВЛЕН НА ПАУЗУ");
}
});
}
function spb(s){if(s==1) $("#pacc").removeClass("ui-icon-pause").addClass("ui-icon-play"); else $("#pacc").removeClass("ui-icon-play").addClass("ui-icon-pause");}
function iptv(l){var rq=$.ajax({url:"getiptv.php",type:"POST",cache:0,dataType:"html",data:{l:l}});rq.done(function(r){if (!r) {hMsg.dMsg("АККАУНТ С ТАКИМИ ДАННЫМИ НЕ НАЙДЕН")}
else if(r.substr(0,4)=="<tr>")
{$(".s_res").html(r).show();}
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
		data: { cdn: selectedValue ,u: $('#uname').html()},
		success: function (d) {
		hMsg.dMsg(d['m']);
		},
		error: function () {
		console.error('Ошибка при отправке запроса на сервер');
		}
		});
		});
}

function saveJSON(t) {
	var listNumber, selectedItems = [];
	var checkboxes = dc.querySelectorAll('.tabcontent.active-tab .selected');
	var activeTabId = dc.querySelector('.tabcontent.active-tab').id;
	if (activeTabId === 'tab1') {
		listNumber = 1;
	} else if (activeTabId === 'tab2') {
		listNumber = 2;
	} else if (activeTabId === 'tab3') {
		listNumber = 3;
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
$(t).addClass('wave-effect');
var rq=$.ajax({type:'POST',url:"cdc.php",cache:0,dataType:"json", data:{grp:selectedItemsString,u: $('#uname').html(),plnm: listNumber}}).always(function() {$(t).removeClass('wave-effect');});
rq.done(function (d) {hMsg.dMsg(d['m']);});
rq.fail(function (jqXHR, textStatus, errorThrown) {
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
dc.addEventListener('DOMContentLoaded',function(){const unI=dc.getElementById('glog');
function ToLatin(text) {return text.split('').map(char => {const lChar = char.toLowerCase();if (kMap[lChar]) {return char === lChar ? kMap[lChar]:kMap[lChar].toUpperCase();}
return char;}).join('');}unI.addEventListener('input',() =>{unI.value=ToLatin(unI.value);});});
function stu(d)
{
var elcol='';
var l=$("#uname").html();
var rq=$.ajax({url:"status.php",type:"POST",cache:0,dataType:"html",data:{un:l,c:d}});
rq.done(function(r){
{if(d=='c')
    {$("#str").fadeOut(150);$('#stu').html(r);
        if($('#Layer1').css('display')==='none'){$('#Layer1').show();}
        if(!tmtstr) tmtstr = setTimeout(aftergetstatus,15*1000);}
	else $('#sstat').html(r);
	switch($(r).find('el#semafor').attr("class")) 
	{case 'red': elcol="#e15353";break;case 'grn': elcol="#75ee75";break;case 'yel': elcol="#fff779";break}
	$('#Layer1.fin').css('border-bottom-color', elcol);
	}
});
}
function aftergetstatus(){tmtstr=0;$("#str").fadeIn(150);}
function userlist(p=0,c=0,ud=0)
{
if (typeof window.umanExit === 'function') window.umanExit();
if(!p && typeof MpplListCache !== 'undefined' && MpplListCache.hasCached()) {
  MpplListCache.restore();
  return;
}
$(txtHint).html("");
if(!p) $(uinfo).html('');
$.post("getupack.php",{list:1,page:p,s:c,ud:ud},function(r)
{
$(result).html($(r).filter('#lst'));
if(p==0) $(uinfo).html($(r).filter('.box'));
if(typeof MpplListCache !== 'undefined') MpplListCache.clear();
});
}
function loglist(p){if (typeof window.umanExit === 'function') window.umanExit();$(txtHint).html("");$(uinfo).html('');$.post("undo.php",{list:1,page:p},function(r){$(result).html(r);});}
function racc(){$.post("pbuy.php",{racc:1},function(r){if(r) {$("#deposit").text(Number(r.s).toFixed(2));if(r.i!=null)$(intrst).val(r.i + "%")} else hMsg.dMsg("Произошла ошибка!");});}
(function(){if(typeof EventSource==='undefined')return;var es=new EventSource("balance_stream.php");es.onmessage=function(e){try{var r=JSON.parse(e.data);var nv=Number(r.s).toFixed(2);if($("#deposit").text()!==nv){$("#deposit").text(nv);}if(r.i!=null)$(intrst).val(r.i+"%");}catch(ex){}};es.onerror=function(){es.close();};})();
function utj(t){var dn=new Date(t*1000);return addnull(dn.getDate(),dn.getMonth()+1,dn.getFullYear(),dn.getHours(),dn.getMinutes())}
function gck(cnm){var r=dc.cookie.match('(^|;) ?'+cnm+'=([^;]*)(;|$)');if(!r)return null;else return(unescape(r[2]))}
function phms(me){var maskList=$.masksSort($.masksLoad("phone-codes.json"),['#'],/[0-9]|#/,"mask");
var maskOpts={inputmask:{definitions:{'#':{validator:"[0-9]",cardinality:1}},clearIncomplete:1,showMaskOnHover:0,autoUnmask:1},match:/[0-9]/,replace:'#',list:maskList,listKey:"mask",};$(me).inputmasks(maskOpts);}
function userslog(p,u){$.post("undo.php",{lst:1,uid:u,page:p},function(r){$(ulist).html(r)})}
function paylog(p){$.post("payments.php",{lst:1,page:p},function(r){$(plist).html(r)})}
function toz(s){var rq=$.ajax({url:"toz.php",type:"POST",cache:0,dataType:"json",data:{i:$("#uid").html(),s:s}});
rq.done(function(r) {
if(!r.m)
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
rq.done(function(r){
if(r.success==1) hMsg.dMsg("ДАННЫЕ СОХРАНЕНЫ");
vr='';
if(values.length>0){for(i=0;i<r.cards.length;i++)
{vr=vr+'<div class=crdnm> <input changed=0 id=\''+r.cards[i].cid+'\' type="text" value="'+r.cards[i].card+'" tmp="'+r.cards[i].card+'" data-owner="' +r.cards[i].owner + '" data-exp="' +r.cards[i].exp +'"  readonly><span class="rm"></span></div>';}}
$("#uedit .bubbleslist").html(vr);
$("#upsw").html($("#uedit #ps").val());
h=($("#uedit #srv>option:selected").text()).split(' - ');
$("#server").html(h[0]);
if($("#uedit #eml").val()) $('#email').html($("#uedit #eml").val()).parents("tr").show();
if($("#uedit #ph").val())$('#phnm').html($("#uedit #ph").val()).parents("tr").show();
var v=$("#uedit #comment").val();
if(v.length>16){$("#scmnt").attr("data-tooltip",v);v=v.substr(0,13)+'...';}
else
$("#scmnt").removeAttr("data-tooltip");
$("#scmnt").html(v);
(v.length) ? $("#scmnt").parents("tr").show():$("#scmnt").parents("tr").hide();});
return false;
}
}

function wuserdta(row,arr) {
    var vv;
    const form = document.querySelector('#uDtails');
    if (!form) {
        console.error('Форма #uDtails не найдена');
        return false;
    }
    const emailField = form.querySelector('input[name="e_ml"]');
    if (row.dataset.email !== (vv = emailField.value)) {
        arr.push({ name: 'eml', value: vv });
    }
    const phoneField = form.querySelector('input[name="mph"]');
    if (row.dataset.ph !== (vv = phoneField.value)) {
        arr.push({ name: 'ph', value: vv });
    }
    const commentField = form.querySelector('textarea[name="cmmnt"]');
    if (row.dataset.cmnt !== (vv = commentField.value)) {
        arr.push({ name: 'comment', value: vv });
    }
    const passwordField = form.querySelector('input[name="passu"]');
    if (row.dataset.pwd !== (vv = passwordField.value)) {
        arr.push({ name: 'ps', value: vv });
    }
    const serverField = form.querySelector('select[name="msrv"]');
    const selectedServer = serverField.value;
    if (row.dataset.srv !== selectedServer) {
        arr.push({ name: 'srv', value: selectedServer });
    }
    if (form.querySelector('input[name="snd"]:checked')) {
        arr.push({ name: 'snd', value: form.querySelector('input[name="snd"]:checked') ? 'on' : 'off' });
    }
    var values = getcards("#formBox");
    if (values.length > 0) {
        arr.push({ name: 'cards', value: JSON.stringify(values) });
    }
    if (arr.length > 0) {
        arr.push({ name: 'un', value: currentUser });
        var rq = $.ajax({url:"uedit.php",type: "POST",cache: false,dataType: "json",data: arr});

        rq.done(function(r) {
            if (r.success===1) {
                hMsg.dMsg("ДАННЫЕ СОХРАНЕНЫ");
                row.dataset.email = emailField.value;
                row.dataset.ph = phoneField.value;
                row.dataset.cmnt = commentField.value;
                row.dataset.pwd = passwordField.value;
                var vr='';
                if (values.length > 0 && r.cards && r.cards.length > 0) {
                    for (var i=0; i<r.cards.length; i++) {
                        vr += '<div class="crdnm">' +
                              '<input changed="0" id="' + r.cards[i].cid + '" type="text" value="' + r.cards[i].card + '" ' +
                              'tmp="' + r.cards[i].card + '" data-owner="' + r.cards[i].owner + '" data-exp="' + r.cards[i].exp + '" readonly>' +
                              '<span class="rm"></span></div>';
                    }
                    $("#uDtails .bubbleslist").html(vr);
                }
            }
        });

        return false;
    }
    return false;
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
if(r==1 ){$(t).parent().parent().find('a').removeAttr('href').end().find('td:eq(4)').html("Удалён");
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
         if(values[i].card===values[ii].card)
         {
         		values[ii].card="";
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
values=getcards("#pfedit");
if(values.length>0)
	arr.push({name:'cards',value:JSON.stringify(values)});
var rq=$.ajax({url:"pfed.php",type:"POST",cache:0,dataType:"html",data:arr});
rq.done(function(r){
r=JSON.parse(r);
if(r.success==1) {hMsg.dMsg("ДАННЫЕ СОХРАНЕНЫ");
vr='';
for(i=0;i<r.cards.length;i++)
{
vr=vr+'<div class=crdnm> <input changed=0 id=\''+r.cards[i].cid+'\' type="text" value="'+r.cards[i].card+'" tmp="'+r.cards[i].card+'" data-owner="' +r.cards[i].owner + '" data-exp="' +r.cards[i].exp +'"  readonly><span class="rm"></span></div>';
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
$("<div class=shpass>").append(
$("<input id="+$input.attr('id')+"sp type='checkbox' class='showpasswordcheckbox'/><label for="+$input.attr('id')+"sp class=switcher></label> ").click(function(){
var change=$(this).is(":checked") ? "text":"password";
$input.attr('type',change);
})
).append(" Показать пароль").insertAfter($input);
});
})
function cpackets(rslt){i=1;while(rslt.find('#p'+i).length) i++; cpckts=i}


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
function cntr(f){$('body').append('<div id="mask"></div>');f.css("display","flex").fadeIn(200);
$(document).on('click','#mask',function(){$("#mask").fadeOut(200,function(){$('#mask').remove()});f.fadeOut(200)})}
function ued()
{
chkfrm($("#ued"));
$('#ue').html($('#uname').html());
$("#ued #dr").html($("#uname").attr("dreg"));
$("#ued #psu").val($("#upsw").html());
$("#ued #eml").val($('#email').html());
$("#ued #ph").val($('#phnm').html());
$("#uedit #tID").val($('#tID').html());
$('#ued #phone_mask').change();
$("#uedit #srv").val($("#server").attr('s'));
$('#ued #snd').prop('checked', $("#uname").attr("sdnt") === "1");
if(v=$("#scmnt").attr("data-tooltip"))
$("#uedit #comment").val(v);
else
$("#uedit #comment").val($("#scmnt").html());
$("#srvshow").toggle(+$("#rq").attr("rq") === 0);
$('#ued').css("display","flex");
}
function chkfrm(f)
{
if (!f.length)
{var rq=$.ajax({url:"pfed.php",type:"POST",cache:0,dataType:"html",data:{get:1}});
rq.done(function(r){$('#pfl').replaceWith(r);});
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
rq.done(function(d){$('#rsets').html(d.s.split("\n").join("<br />"));
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
function prl(){$("body").prepend("<div id='spin'><span id='spinner' class='spinner'></span><span id='spinner2' class='spinn'> </span></div>").fadeIn(500);}
function packetp(){$(txtHint).html("").hide();$("#uinfo").html();var rq=$.ajax({url:"getupack.php",type:"POST",cache:0,dataType:"html",data:{price:1}});rq.done(function(r){$("#result").html(r).fadeIn();});}
function tOn(e){event=e || window.event;const target = event.target || event.srcElement;const row = $(target).closest("tr");
const checkbox = row.find("input[type='checkbox']");if (checkbox.length && checkbox.prop("disabled")) {
return;}const isSelected = row.hasClass("selected");row.toggleClass("selected", !isSelected);if (checkbox.length) {checkbox.prop("checked", !isSelected);}UC();}

function iptvsign(l) {
    if (tmtstr2) { clearTimeout(tmtstr2); tmtstr2 = 0; }

    $("#txtHint").html("").hide();
    $(".sb, .s_res").hide();
    $(".circle").show();

    var rq = $.ajax({
        url: "iptvsign.php",
        type: "POST",
        cache: false,
        dataType: "json",
        data: { un: l}
    });

    rq.done(function(data) {
        if (data.status === "error") {
            $(".sb").show();
            $(".circle").hide();
            hMsg.dMsg(data.message || "АККАУНТ С ТАКИМИ ДАННЫМИ НЕ НАЙДЕН");
            return;
        }
        else{getuser(0,l);}
        const session = data.data.session || { a: 0 };
      
    });

    rq.fail(function(jqXHR, textStatus, errorThrown) {
        console.error("Ошибка AJAX:", textStatus, errorThrown);
        $(".sb").show();
        $(".circle").hide();
    });
}

function getuser(i, l, p = "") {
    if (typeof window.umanExit === 'function') window.umanExit();
    // Проверяем, передан ли логин напрямую
    const isDirectCall = l && l.trim().length > 0;

    // Если логин не передан, берем из строки поиска
    if (!isDirectCall) {
        l = $("#glog").val().trim();
        if (l.length == 0 || l == "Введите логин") return;
    }

    if (tmtstr2) { clearTimeout(tmtstr2); tmtstr2 = 0; }

    if (typeof MpplListCache !== 'undefined') MpplListCache.save();

    $("#txtHint").html("").hide();
    $(".sb, .s_res").hide();
    $(".circle").show();
    $("#uinfo").html("");
    $("#result").html("");

    // Получаем тип поиска только для вызовов из строки поиска
    const searchType = isDirectCall ? "2" : $(".sel").val(); // "2" (ЛОГИН) для прямых вызовов

    var rq = $.ajax({
        url: "px_.php",
        type: "POST",
        cache: false,
        dataType: "json",
        data: { l: l, p: p, searchType: searchType }
    });

    rq.done(function(data) {
        if (data.status === "error") {
            $(".sb").show();
            $(".circle").hide();
            hMsg.dMsg(data.message || "АККАУНТ С ТАКИМИ ДАННЫМИ НЕ НАЙДЕН");
            return;
        }

        const session = data.data.session || { a: 0 };

        if (data.data.multiple && !isDirectCall) {
            // Группируем результаты по типу (только для поиска)
            let html = "";
            const groups = {
                login: { title: "НАЙДЕНО ПО ЛОГИНУ", results: [] },
                phone: { title: "НАЙДЕНО ПО ТЕЛЕФОНУ", results: [] },
                email: { title: "НАЙДЕНО ПО EMAIL", results: [] }
            };

            // Распределяем результаты по группам
            data.data.results.forEach(result => {
                if (result.type === "login") {
                    groups.login.results.push(result);
                } else if (result.type === "phone") {
                    groups.phone.results.push(result);
                } else if (result.type === "email") {
                    groups.email.results.push(result);
                }
            });

            // Формируем HTML для каждой группы
            ["login", "phone", "email"].forEach(type => {
                if (groups[type].results.length > 0) {
                    html += `<li class="s_res-group-title">${groups[type].title}</li>`;
                    groups[type].results.forEach(result => {
                        let value;
                        if (result.type === "login") {
                            value = result.phone || result.email || ""; // Сначала телефон, если пустой — email
                        } else if (result.type === "phone") {
                            value = result.phone;
                        } else {
                            value = result.email;
                        }
                        html += `<li><a href="javascript:getuser(0,'${result.user}')">${result.user}</a><span class="s_res-value">${value}</span></li>`;
                    });
                }
            });

            // Обновляем список
            $(".s_res-list").html(html);
            $(".s_res").show();
            $(".sb").show();
            $(".circle").hide();

            // Обработчик клика вне списка
            $(document).off("click.s_res");
            setTimeout(() => {
                $(document).on("click.s_res", function(event) {
                    if (!$(event.target).closest(".s_res").length) {
                        $(".s_res").fadeOut(200);
                    }
                });
            }, 0);
        } else {
            // Обработка данных одного аккаунта
            $("#tun").val("Тюнер").change();
            clts();

            const account = data.data.account;
            const dealer = data.data.dealer;
            const iptv = data.data.iptv || {};

            let infoHtml=`<div class="blk finr">
                    <h2>ИНФО ПО <span id="uname" dreg="${account.dreg}" acccardnum="${account.acccardnum}" sdnt="${account.sndnote}">${account.user} </span></h2><table id="tinfo" border="0">
                        <tr><td align=right class="td-id">ID:</td><td id="uid">${account.id}</td></tr>
                        <tr><td align=right id="rq" rq="${(Object.keys(iptv).length > 0 ? "1" : "0")}">Учётка:</td><td>${account.req != 0 ? "Позапросная" : (Object.keys(iptv).length > 0 ? "IPTV" : "Стандартная")}</td></tr>
                        <tr><td align=right id="accsum">Баланс:</td><td>${parseFloat(account.sum).toFixed(2)}</td></tr>`
            if (dealer.user === session.l || session.a == 1 || session.a == 2 || dealer.id === session.i) {
                let descr="";
                if(account.dscr)
                descr=rSpaces(account.dscr);
                infoHtml += `<tr ${!account.phone ? 'class="hidden"' : ''}><td align=right>Тел.#:</td><td id="phnm">${account.phone || ''}</td></tr>
                    <tr><td class="hidden"></td><td id=upsw class="hidden">${account.pwd || ''}</td></tr>
                    <tr ${account.tcid==0 ? 'class="hidden"' : ''}><td align=right>tId:</td><td id="tID">${account.tcid || ''}</td></tr>
                    <tr ${!account.email ? 'class="hidden"' : ''}><td align=right>Email:</td><td id="email">${account.email || ''}</td></tr>
                    <tr ${!descr ? 'class="hidden"' : ''}><td align=right>Комент:</td><td id="scmnt" data-tooltip="${descr || ''}" data-tooltip-position="left">${descr && descr.length > 16 ? descr.substr(0, 13) + '...' : (descr || '')}</td></tr>
                    ${data.data.server ? `<tr><td align="right">Сервер:</td><td id="server" s="${data.data.server.s_id}">${data.data.server.url}</td></tr>`:''}`
                if (session.a == 1 || session.a == 2 || dealer.user === session.l) { infoHtml += `<tr><td align=center colspan=2><button onclick="accop()">ОПЕРАЦИИ ПО АККАУНТУ</button></td></tr>`}
                if (session.a == 1 || dealer.user === session.l) {
                    infoHtml += `<tr><td align=center colspan=2><button onclick="ued()">ИЗМЕНИТЬ ДАННЫЕ</button></td></tr>`;
                    if (session.a == 1) {
                        infoHtml += `<tr><td align=right><input type=checkbox></td><td>C дилера</td></tr>`;
                    }
                }
                if ((session.a == 1 || dealer.user === session.l) && !data.data.iptv) {
                    let showPauseButton = false;
                    const packets = data.data.packets || [];
                    for (const packet of packets) {
                        const dend = packet.unixt;
                        if (dend && dend > Math.floor(Date.now() / 1000)) {
                            const daysDiff = Math.floor((dend - Math.floor(Date.now() / 1000)) / 86400);
                            if (daysDiff > 7) {
                                showPauseButton = true;
                                break;
                            }
                        }
                    }
                    infoHtml += '<tr><td align=center colspan=2><table width=60px align=center border=0 id="stps"><tr align="center">';
                    infoHtml += `<td><div class="rstp"`;
                    if (!showPauseButton) {
                        infoHtml += " class='hidden' ";
                    }
                    infoHtml += ' title="Поставить на паузу/Снять с паузы.\n Данная функция доступна раз в неделю!"><div id="pacc" class="ui-icon ';
                    infoHtml += `${account.paused==1 ? ' ui-icon-play' : 'ui-icon-pause'}`;
                    infoHtml += '"></div></div></td>';
                    infoHtml += '<td><div class="rstp" title="Получить настройки подключения"><div id="rst" class="ui-icon ui-icon-wrench"></div></div></td></tr></table></td></tr>';
                }
            }                   
            infoHtml += `${iptv && (Object.keys(iptv).length > 0 || iptv.packets)
                                    ? `<tr><td align=center colspan=2><button onclick="getuser(0,'${account.user}','s')">ШАРИНГ</button></td></tr>`
                                    : (account.iptvusr != 0
                                        ? `<tr><td align=center colspan=2><button onclick="getuser(0,'${account.user}','i')">IPTV</button></td></tr>`
                                        : `<tr><td align=center colspan=2><button onclick="iptvsign('${account.user}')">ПРИВЯЗАТЬ К IPTV</button></td></tr>`
                                    )
                                }`;
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
		const playlistIds = playlists ? Object.keys(playlists).map(Number).sort((a, b) => a - b) : [];
                mcHtml += `
                    <div id="mc" class="fin">
                        <style>
                            .tabcontent { display: none; padding-top: 10px; padding-bottom: 20px; border-radius: 8px; }
                            .active-tab { display: block; margin-top: 12px; margin-bottom: 23px; border: 1px solid #284040; }
                            .selected { background-color: #99ccdd; }
                        </style>
                        <div class="iptvcntr"><div class="actbutt"><div id="activateButton" onclick="actIptv(event)">
                                ${iptvenddate && iptvenddate >= Date.now() / 1000 ? 'ПРОДЛИТЬ' : 'АКТИВИРОВАТЬ'} ПОДПИСКУ НА
                            </div>
                            <div class="info-row" onclick="tDrpd()">
                                <div class="selnum">1</div>
                                <div class="mnths"> МЕС</div>
                            </div>
                            </div>
                                <div class="dropdown-content" id="myDropdown">
                                    ${[1,2,3,4,5,6,7,8,9,10,11,12].map(m => `<a onclick="sMn(${m})">${m} Мес</a>`).join('')}
                                </div>
                            </div>
                        <div class="info-meta">
                            <div class="iptv url">URL <input id="url" type="text" value="${iptvurl}"/><button id="copyButton" onclick="cpUrl()"><img src="/copy.png" alt="Копировать"></button></div>
                            <div class="iptv cdn"><div class="iptvsrv">СЕРВЕР ПОДКЛЮЧЕНИЯ</div>
                                <select id="iptvsrv" name="iptvsrv">
                                    ${locations.map(loc => `<option value="${loc.option_value}" ${loc.option_value == iptvAccount.iptvcdn ? 'selected' : ''}>${loc.option_text}</option>`).join('')}
                                </select>
                                <div class="iptvbtn"><button id="changecdn" disabled=disabled>СМЕНИТЬ</button></div>
                            </div>
                            <div class="iptv"><div class="endd">Дата окончания подписки<div>
                                <input id="enddate" type="text" value="${iptvenddate && iptvenddate >= Date.now() / 1000 ? mkdt(iptvenddate*1000,0,1) : 'Не активен'}" disabled/>
                            </div></div></div>
                        </div>
			<div class="pprc">Пакет ${packets[0]?.pname || ''}, стоимость ${packets[0] ? packets[0].tarrif : ''}</div> 
                        <div class="playlists" id="40">
                                ${playlistIds.map(id => `
                                    <button 
                                        id="tabbut${id}" 
                                        class="tablinks ${iptvAccount.grpvariant == id ? 'acttab' : ''}" 
                                        onclick="openTab(this.id, 'tab${id}')"
                                    >
                                        Плейлист ${id}
                                    </button>
                                `).join('')}
                                    ${playlistIds.map(id => `
                                    <div id="tab${id}" class="tabcontent ${iptvAccount.grpvariant == id ? 'active-tab' : ''}">
                                        <button class="invert-button" onclick="invertSelection(this)">Отметить всё | Инвертировать</button>
                                        <ul id="tab${id}-list">
                                            ${playlists[id]?.map(grp => 
                                                `<li value="${grp.grpid}" ${grp.selected ? "class='selected'" : ""}>${grp.grpname}</li>`
                                            ).join('') || ''}
                                        </ul>
                                    </div>
                                `).join('')         }
                            <button onclick="saveJSON(this)">СОХРАНИТЬ</button>
                        </div>
                    </div>
                `;
                if (typeof getuserAdmin === 'function') {
                    const updatedHtml = getuserAdmin(data, infoHtml, mcHtml);
                    infoHtml = updatedHtml.infoHtml;
                    mcHtml = updatedHtml.mcHtml;
                    spb($("#psd").val());
                    if ((timer = $("#psd").attr("ptmt")) <= 0) hish_pause();
                    else {
                        if (tmt != 0) clearTimeout(tmt);
                        tmt = setTimeout(hish_pause(0), timer * 1000);
                    }
                }
            } else if (data.data.packets) {
                updateUserInfo(data, account, infoHtml);
            }  
            if (!data.data.packets) {
                $("#uinfo").html(infoHtml).fadeIn();
                $("#result").html(mcHtml).fadeIn(function() {
                    $(".sb").show();
                    $(".circle").hide();
                    actEl();
                    setupListItemDelegation();
                    dc.querySelectorAll('.tabcontent').forEach(tab => {
                        updateButtonText(tab);});
                });
            }
        }
    });

    rq.fail(function(jqXHR, textStatus, errorThrown) {
        console.error("Ошибка AJAX:", textStatus, errorThrown);
        $(".sb").show();
        $(".circle").hide();
    });
}

async function updateUserInfo(data, account, infoHtml) {
    let mcHtml = '';
    if (data.data.packets) {
        mcHtml += `
            <div id="mc" class="fin">
                <input type=hidden id="psd" value="${account.paused}" ptmt="${data.data.tmt}">
            </div><div id="pauseOverlay" class="pause-overlay${account.paused != 1 ? ' hidden':''}">
            <button id="playButton" class="plb" onclick="pause()"></button></div>`;
        const subscriptionsHtml = await loadSubscriptions(account.user);
        mcHtml += subscriptionsHtml;
    }
    const paused = account.paused;
    const packets = data.data.packets || [];
    const hasActiveSubscription = packets.some(packet => Number(packet.unixt) >= Date.now() / 1000);
    infoHtml += `<div id="Layer1" class="fin${(hasActiveSubscription != true|| Number(paused)) ? ' hidden' : ''}">`;
    infoHtml += '<h2>СТАТУС ПОДКЛЮЧЕНИЯ<span id="str" class="str" onclick="stu(\'c\')"></span></h2>';
    infoHtml += '<div id="stu">';
    const cards = data.data.cards || [];
    const numOfCards = cards.length;
    let crds='';

    if (numOfCards > 0) {
        infoHtml += '<div id="Cardslst" class="hidden">';
        cards.forEach(card => {
            crds += `
                <div class="crdnm">
                    <input changed=0 id="${card.cid}" type="text" value="${card.card}" tmp="${card.card}" data-owner="${card.owner}" data-exp="${card.exp}" readonly>
                    <span class="rm"></span>
                </div>
            `;
        });
        infoHtml += cards;
        infoHtml += '</div>';
    } else {
        infoHtml += '<div id="Cardslst" class="hidden"></div>';
    }
    infoHtml += '</div>';
    infoHtml += '</div>';

    if (typeof getuserAdmin === 'function') {
        const updatedHtml = getuserAdmin(data, infoHtml, mcHtml);
        infoHtml = updatedHtml.infoHtml;
        mcHtml = updatedHtml.mcHtml;
    }

    $("#ued .bubbleslist").html(crds);
    $("#uinfo").html(infoHtml).fadeIn();
    $("#result").html(mcHtml).fadeIn();
    $(".sb").show();
    $(".circle").hide();
    
    initializeDatepickers(data.data.mindays);
    attachEventListeners();
/*    if (account.paused == 1) {
        $("#playButton").on("click", function() {
            pause(); // Вызываем функцию pause()
        });
    }*/
}
function checkPacketsActivity() {
    const items = dc.querySelectorAll('.sitem');
    let activeCount = 0;
    for (const item of items) {
        const dendElement = item.querySelector('#dend');
        const dendTimestamp = dendElement ? parseInt(dendElement.getAttribute('data-timestamp')) : 0;
        if (dendTimestamp && dendTimestamp > Math.floor(Date.now() / 1000)) {
            activeCount++;
        }
    }
    return activeCount > 0 ? 1 : 0;
}
function hish_pause(r){
if($("#rq").attr("rq")==1 || checkPacketsActivity() || r)
{if($('#psd').attr('ptmt')<=0)$('#pacc:not(.bnd)').addClass('bnd').click(function(){pause()}).parent().show();
$("#rst").on('click',function(){rset()});}
else
{$("#pacc,#rst").removeClass('bnd').unbind("click");}
}

function updatePayButton() {
    dc.getElementById('totalAmount').textContent = `${totalAmount.toFixed(2)}`;
}

function updateItemStates(clickedItem, isInitial = false) {
    const clickedId = clickedItem.id;
    const clickedPacket = allPackets.find(p => p.id == clickedId);
    const isSelected = clickedItem.classList.contains('selected');
    const isActive = clickedPacket.is_active && clickedPacket.dend > Math.floor(Date.now() / 1000);

    allPackets.forEach(packet => {
        const item = dc.getElementById(packet.id);
        if (!item) return;

        // Пропускаем сам кликнутый пакет всегда
        if (packet.id === clickedId) return;

        let shouldDisable = false;

// Логика для выделения пакета (при клике, когда пакет становится selected)
if (isSelected) {
    // 1. Если кликнутый пакет имеет компоненты и текущий пакет входит в них
    if (clickedPacket.components && clickedPacket.components.length > 0) {
        if (clickedPacket.components.includes(packet.id)) {
            shouldDisable = true; // Отключаем компоненты кликнутого пакета
        }
    }
    // 2. Если текущий пакет имеет компоненты и кликнутый пакет входит в них
    if (packet.components && packet.components.includes(clickedId)) {
        shouldDisable = true; // Отключаем пакеты, зависящие от кликнутого
    }
    // 3. Проверка пересечения компонентов
    if (clickedPacket.components && clickedPacket.components.length > 0) {
        if (packet.components && packet.components.length > 0 && packet.id !== clickedId) {
            const hasIntersection = clickedPacket.components.some(id => packet.components.includes(id));
            if (hasIntersection) {
                shouldDisable = true; // Отключаем при пересечении компонентов
            }
        }
    }
}
if (!isSelected && isActive && packet.components && packet.components.includes(clickedId)) {
    shouldDisable = true; // Отключаем пакеты, зависящие от активного пакета, если снято выделение
}
// Логика для инициализации: блокируем зависимые пакеты активного пакета при загрузке
if (isInitial && isActive && packet.components && packet.components.includes(clickedId)) {
    shouldDisable = true; // Отключаем зависимые пакеты при начальной загрузке
}
        if (shouldDisable) {
            item.classList.remove('selected');
            item.classList.add('disabled');
            removeFromSelected(packet.id);
        } else if (!isConflicting(packet.id) && !item.classList.contains('selected')) {
            item.classList.remove('disabled');
        }
    });
}

function isConflicting(packetId) {
    return allPackets.some(otherPacket => {
        const packet = allPackets.find(p => p.id == packetId);
        const isOtherActive = otherPacket.is_active && otherPacket.dend > Math.floor(Date.now() / 1000);

        if (isOtherActive) {
            // Если другой пакет активен и текущий зависит от него
            if (packet.components && packet.components.includes(otherPacket.id)) {
                return true;
            }
            // Если другой пакет активен и включает текущий как компонент
            if (otherPacket.components && otherPacket.components.includes(packetId)) {
                return true;
            }
            // Проверка пересечения компонентов
            if (otherPacket.components && packet.components) {
                return otherPacket.components.some(id => packet.components.includes(id));
            }
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

async function loadSubscriptions(l) {
    return await fetchSubscriptions(l);
}

async function fetchSubscriptions(l) {
    try {
        const r = await fetch(`/get_subscriptions.php?l=${encodeURIComponent(l)}`);
        if (!r.ok) {
            throw new Error(`HTTP error! Status: ${r.status}`);
        }
        const data = await r.json();
        let html = `<div class="pay-button-container"><button class="pay-button" id="payButton">ОПЛАТИТЬ <span id="totalAmount">0</span></button>
        <div id=selm onclick="tml.call(this)">
        <div class="slide-content" id="myDropdown"> ${[1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12].map(m => `<li><a onclick="sMn(${m},1)">${m}</a></li>`).join('')} </div>
        <div class="selnum">1</div>
        <div class="mnths"> МЕС</div>
        </div>

        </div>`;
        selectedItems = [];
        totalAmount = 0;
        allPackets = data.price_list;

        minDays = data.mindays || 30;
        console.log("minDays из ответа:", minDays);

        data.price_list.forEach(packet => {
            let isActive = packet.is_active;
            let itemClass = `sitem ${isActive ? 'selected' : ''}`;
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
                    <div class="flex-container">
                        <div class="flex items-center space-x-3">
                            <!--<img src="/logos/${packet.pname.toLowerCase()}.png" alt="${packet.pname}" class="logo-image">-->
                        </div>
                        <div class="packet_name">
                            <div>${packet.pname}</div>
                        </div>
<button class="stopButton${(!isActive && !packet.dstart && !packet.dend) || (packet.dend && (packet.dend * 1000 - 15 * 24 * 60 * 60 * 1000 <= Date.now())  && adm !=1) ? ' hidden' : ''}"> Стоп </button>
                        <div class="price">${packet.price}</div>
                    </div>
                    <div class="mt-3 space-y-1${!isActive && !packet.dstart && !packet.dend ? ' hidden' : ''}">
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

        return html; // Возвращаем HTML напрямую
    } catch (error) {
        console.error("Ошибка загрузки данных:", error);
        return '<div>Ошибка загрузки подписок</div>'; // Фолбэк HTML
    }
}

function calcTAmount() {
    totalAmount = 0;

    selectedItems.forEach(item => {
        const packet = allPackets.find(p => p.id === item.id);
        const domItem = dc.getElementById(item.id);
        if (!packet || !domItem) return;

        const pricePerDay = parseFloat(packet.price) / 30; // Цена в день
        const dpnpt = domItem.querySelector('.datepicker-input');
        const selectedDateStr = dpnpt.value;

        const [day, month, year] = selectedDateStr.split('.');
        const selectedDate = new Date(`${year}-${month}-${day}`);
        selectedDate.setHours(0, 0, 0, 0);

        let bDt;
        let daysDiff;

        // Если пакет активен, базовая дата — это dend
        if (packet.is_active && packet.dend > Math.floor(Date.now() / 1000)) {
            bDt = new Date(packet.dend * 1000);
        } else {
            // Если пакет неактивен, базовая дата — текущая дата
            bDt = new Date();
        }
        bDt.setHours(0, 0, 0, 0);

        daysDiff = Math.ceil((selectedDate - bDt) / (1000 * 60 * 60 * 24));
        const packetCost = pricePerDay * daysDiff;
        totalAmount += packetCost >= 0 ? packetCost : 0;
    });

    updatePayButton();
}

function initializeDatepickers(minDays) {    
    dc.querySelectorAll('.datepicker-input').forEach(input => {
        const parentDiv = input.closest('.sitem');
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
                calcTAmount();
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
}
function attachEventListeners(){dc.querySelectorAll('.stopButton').forEach(button =>{button.addEventListener('click',function(e){e.stopPropagation();});});
dc.querySelectorAll('.sitem').forEach(item => {
        item.addEventListener('click', function(e) {
            if (this.classList.contains('disabled')) return;

            this.classList.toggle('selected');
            const packet = allPackets.find(p => p.id == this.id);
/*            if (this.classList.contains('selected')) {
                selectedItems.push({ id: this.id, price: parseFloat(this.dataset.price) });*/
            if (this.classList.contains('selected')) {
                const packet = allPackets.find(p => p.id === this.id);
                selectedItems.push({ id: this.id, price: parseFloat(packet.price) });                
            } else {
                removeFromSelected(this.id);
            }

            // Обновляем состояния зависимых пакетов
            updateItemStates(this);
            calcTAmount();
        });
    });

    // Блокируем зависимые пакеты для активных при загрузке
    allPackets.forEach(packet => {
        if (packet.is_active && packet.dend > Math.floor(Date.now() / 1000)) {
            const item = dc.getElementById(packet.id);
            if (item) {
                updateItemStates(item, true); // Вызываем с флагом isInitial
            }
        }
    });

    const payButton = dc.getElementById('payButton');
    if (payButton) {
        if (tmtstr2) { clearTimeout(tmtstr2); tmtstr2 = 0; }
        dc.getElementById('payButton').addEventListener('click', function() {
            const uid = dc.getElementById('uid').textContent.trim();
            let snd = [];
            dc.querySelectorAll('.sitem.selected').forEach(item => {
                const packetId = item.id;
                const dpnpt = item.querySelector('.datepicker-input');
                const selectedDateStr = dpnpt.value;
                const dendElement = item.querySelector('#dend');
                const isActive = dendElement && dendElement.getAttribute('data-timestamp') !== '';
        
                const [day, month, year] = selectedDateStr.split('.');
                const fullYear = year.length === 2 ? `20${year}` : year;
                const selectedDate = new Date(`${fullYear}-${month}-${day}`);
                selectedDate.setHours(0, 0, 0, 0);
        
                let bDt;
                let daysDiff;
        
                if (isActive) {
                    const dendTimestamp = parseInt(dendElement.getAttribute('data-timestamp')) * 1000;
                    bDt = new Date(dendTimestamp);
                    bDt.setHours(0, 0, 0, 0);
                    daysDiff = Math.ceil((selectedDate - bDt) / (1000 * 60 * 60 * 24));
                } else {
                    bDt = new Date();
                    bDt.setHours(0, 0, 0, 0);
                    daysDiff = Math.ceil((selectedDate - bDt) / (1000 * 60 * 60 * 24));
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
            .then(r => {
	        if(r.success==0)
                    hMsg.dMsg(r.m);
                else{
                $("#deposit").text(Number(r.sum).toFixed(2));		 
                const currentDate = new Date();
                const formattedCurrentDate = currentDate.toLocaleString('ru-RU', {
                    day: '2-digit',
                    month: '2-digit',
                    year: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                }).replace(',', '');
                minDays = r.md || 30;
                snd.forEach(([packetId, daysDiff]) => {
                    const item = dc.getElementById(packetId);
                    if (!item) return;
        
                    const packetIndex = allPackets.findIndex(p => p.id === packetId);
                    console.log(packetIndex);
                    allPackets[packetIndex].is_active = true;

                    const dendSpan = item.querySelector('#dend');
                    const dpnpt = item.querySelector('.datepicker-input');
                    const stopButton = item.querySelector('.stopButton');

                    var selectedDateStr = dpnpt.value;
       
                    const [day, month, year] = selectedDateStr.split('.');
                    const fullYear = year.length === 2 ? `20${year}` : year;
                    const selectedDate = new Date(`${fullYear}-${month}-${day}`);
                    const now = new Date();
                    selectedDate.setHours(now.getHours(), now.getMinutes(), now.getSeconds(), now.getMilliseconds());

                    const newDendDate = new Date(selectedDate);
                    newDendDate.setDate(newDendDate.getDate());
                    allPackets[packetIndex].dend = Math.floor(newDendDate.getTime() / 1000);
                    console.log(allPackets[packetIndex].dend);
                    allPackets[packetIndex].dstart = Math.floor(currentDate.getTime() / 1000);
                    console.log(allPackets[packetIndex].dstart);
                    const activationSpan = item.querySelector('#activation');
                    if (activationSpan) {
                        activationSpan.textContent = formattedCurrentDate;
                    }
        
                    const formattedNewDend = newDendDate.toLocaleString('ru-RU', {
                        day: '2-digit',
                        month: '2-digit',
                        year: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit'
                    }).replace(',', '');
                    dendSpan.textContent = formattedNewDend;
                    dendSpan.setAttribute('data-timestamp', allPackets[packetIndex].dend);
        
                    const newDefaultDate = new Date((allPackets[packetIndex].dend + Number(minDays) * 86400) * 1000);
                    const formattedNewDefaultDate = newDefaultDate.toLocaleDateString('ru-RU', {
                        day: '2-digit',
                        month: '2-digit',
                        year: 'numeric'
                    });
                    dpnpt.value = formattedNewDefaultDate;
        
                    $(dpnpt).datepicker('option', {
                        minDate: newDefaultDate,
                        defaultDate: newDefaultDate
                    });
        
                    const activateLabel = item.querySelector('span[for="months"]');
                    if (activateLabel) {
                        activateLabel.textContent = 'Продлить до:';
                    }
        
                    if (stopButton) {
                        stopButton.style.display = 'block';
                    }
        
                    const dateBlock = item.querySelector('.mt-3.space-y-1');
                    if (dateBlock) {
                        dateBlock.style.display = 'flex';
                    }
        
                    item.classList.add('selected');
                    updateItemStates(item);
                });
                calcTAmount();
                if(!tmtstr2) tmtstr2=setTimeout(function(){tmtstr2=0;stu('c');},7*1000);
                hish_pause();
                hMsg.dMsg(r.m);
		}
            })
            .catch(error => {
                console.error("Ошибка:", error);
            });
        });
    }

    dc.querySelectorAll('.stopButton').forEach(button => {
        button.addEventListener('click', function() {
            const item = button.closest('.sitem');
            const packetId = item.id;
            const uid = dc.getElementById('uid').textContent.trim();

            $.ajax({
                url: "undo.php",
                type: "POST",
                cache: false,
                dataType: "json",
                data: { uid: uid, stop: packetId }
            })
            .then(r => {
                console.log("Успех (Stop):", r);
                
                const activationSpan = item.querySelector('#activation');
                const dendSpan = item.querySelector('#dend');
                const dpnpt = item.querySelector('.datepicker-input');
                const stopButton = item.querySelector('.stopButton');
                minDays = r.md || 30;

                if (r.s === "1" && r.s) {
                    const activationDate = new Date(r.r.s * 1000);
                    const endDate = new Date(r.r.e * 1000);

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

                        //                    if (activationSpan) {
                        activationSpan.textContent = formattedActivation;
                        //                    }
                    if (dendSpan) {
                        dendSpan.textContent = formattedEnd;
                        dendSpan.setAttribute('data-timestamp', r.r.e);
                    }

                    const daysDiff = 1;
                    const daysInMs = (Number(daysDiff) + Number(minDays)) * 86400;
                    const newDefaultDate = new Date((r.r.e + daysInMs) * 1000);
                    const formattedNewDefaultDate = newDefaultDate.toLocaleDateString('ru-RU', {
                        day: '2-digit',
                        month: '2-digit',
                        year: 'numeric'
                    });
                    dpnpt.value = formattedNewDefaultDate;

                    $(dpnpt).datepicker('option', {
                        minDate: newDefaultDate,
                        defaultDate: newDefaultDate
                    });

                    const activateLabel = item.querySelector('span[for="months"]');
                    if (activateLabel) {
                        activateLabel.textContent = 'Продлить до:';
                    }
                } else if (r.s === "0" && r.r && r.r.e === 0) {
                    if (stopButton) {
                        stopButton.style.display = 'none';
                    }
                    const dateBlock = item.querySelector('.mt-3.space-y-1');
                    if (dateBlock) {
                        dateBlock.style.display = 'none';
                    }
                    const dendElement = item.querySelector('#dend');
                    dendElement.setAttribute('data-timestamp','');

                    const currentDate = new Date();
                    const daysInMs = Number(minDays) * 86400;
                    const newDefaultDate = new Date((Math.floor(currentDate.getTime() / 1000) + daysInMs) * 1000);
                    const formattedNewDefaultDate = newDefaultDate.toLocaleDateString('ru-RU', {
                        day: '2-digit',
                        month: '2-digit',
                        year: 'numeric'
                    });
                    console.log("formattedNewDefaultDate",formattedNewDefaultDate);
                    dpnpt.value = formattedNewDefaultDate;

                    $(dpnpt).datepicker('option', {
                        minDate: currentDate,
                        defaultDate: newDefaultDate
                    });

                    const activateLabel = item.querySelector('span[for="months"]');
                    if (activateLabel) {
                        activateLabel.textContent = 'Активировать до:';
                    }
                   // Удаляем пакет из selectedItems
                    removeFromSelected(packetId);
                    item.classList.remove('selected');
                    // Активируем (снимаем disabled) зависимые пакеты
                    updateDependentPackets(packetId);
                    calcTAmount();
                }

                const depositElement = dc.getElementById('deposit');
                if (depositElement && r.sum !== undefined) {
                    depositElement.textContent = `${r.sum} $`;
                }
//                calcTAmount();
               console.log("pstop - pause: ",checkPacketsActivity());
            })
            .catch(error => {
                console.error("Ошибка (Stop):", error.status, error.statusText);
                console.error("Текст ответа:", error.responseText);
            });
        });
    });
    hish_pause();
    calcTAmount();
    updatePayButton();
}

function updateDependentPackets(stoppedPacketId) {
    const stoppedPacket = allPackets.find(p => p.id === stoppedPacketId);
    if (!stoppedPacket) return;

    // Обновляем статус остановленного пакета в allPackets
    const stoppedPacketIndex = allPackets.findIndex(p => p.id === stoppedPacketId);
    if (stoppedPacketIndex !== -1) {
        allPackets[stoppedPacketIndex].is_active = false;
        allPackets[stoppedPacketIndex].dend = 0;
    }

    allPackets.forEach(packet => {
        const item = dc.getElementById(packet.id);
        if (!item || packet.id === stoppedPacketId) return;

        const dependsOnStopped = packet.components && packet.components.includes(stoppedPacketId);
        const isComponentOfStopped = stoppedPacket.components && stoppedPacket.components.includes(packet.id);

        // Проверяем, связан ли текущий пакет с остановленным
        if (dependsOnStopped || isComponentOfStopped) {
            // Проверяем конфликты с другими активными пакетами
            if (!isConflicting(packet.id) && !item.classList.contains('selected')) {
                item.classList.remove('disabled');
                console.log(`Пакет ${packet.id} разблокирован после остановки ${stoppedPacketId}`);
            } else {
                item.classList.add('disabled');
                console.log(`Пакет ${packet.id} остается заблокированным из-за других активных пакетов`);
            }
        }
    });
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
    if (c == 1 && a == 14) return packet.zamir;
    return packet.sum;
}
function table_row_format(i, active_packet) {
    if (i & 1) {
        if (active_packet == 1) return "ra3";
        else if (active_packet == 2) return "rd1";
        else if (active_packet == 3) return "psd2";
        else return "rw1";
    } else {
        if (active_packet == 1) return "ra4";
        else if (active_packet == 2) return "rd2";
        else if (active_packet == 3) return "psd1";
        else return "rw2";
    }
}
function cpUrl(){var iF=dc.getElementById("url");iF.select();dc.execCommand("copy");hMsg.dMsg("Ссылка на плейлиcт скопирована",1);}
function updateButtonText(tC) {
const b=tC.querySelector('.invert-button');
const i=tC.querySelectorAll('li');
const selectedCount = Array.from(i).filter(el => el.classList.contains('selected')).length;
const totalCount = i.length;

if (selectedCount === 0) {
  b.textContent = 'ВЫДЕЛИТЬ ВСЁ';
  b.classList.remove('toggled','deselect');
  b.classList.add('invert','no-effects');
} else if (selectedCount === totalCount) {
  b.textContent = 'СНЯТЬ ВЫДЕЛЕНИЕ';
  b.classList.remove('toggled');
  b.classList.add('no-effects');
  b.classList.remove('invert');
  b.classList.add('deselect');
} else {
  b.textContent = 'ИНВЕРТИРОВАТЬ';
  b.classList.remove('no-effects','deselect','invert');
  }
}
function invertSelection(b) {
const tC=b.closest('.tabcontent');
const lI=tC.querySelectorAll('li');
lI.forEach(i=>{i.classList.toggle('selected');});
updateButtonText(tC);
if(b.textContent == 'ИНВЕРТИРОВАТЬ' ){b.classList.toggle('toggled');}
}

function selectListItem(i) {
i.classList.toggle('selected');
updateButtonText(i.closest('.tabcontent'));
}

function setupListItemDelegation(tC=null) {
const ts=tC ? [tC] : dc.querySelectorAll('.tabcontent');
ts.forEach(tb=>{const l=tb.querySelector('ul');
l.removeEventListener('click', handleListClick);
l.addEventListener('click', handleListClick);
updateButtonText(tb);
const observer = new MutationObserver(()=>{updateButtonText(tb);});observer.observe(l,{childList:true,subtree:true});});
}
function handleListClick(e){const l=e.target.closest('li');if (l) selectListItem(l);}
function refreshTabButton(tabId) {
const tC=dc.getElementById(tabId);
if (tC) {
      updateButtonText(tCt);
      setupListItemDelegation(tC);
} else {
      console.warn(`Tab с ID ${tabId} не найден`);
}
}
function openTab(id,tabName) {
	var tabbuts = dc.querySelectorAll('.tablinks');
	for (var i = 0; i < tabbuts.length; i++) {
		tabbuts[i].classList.remove('acttab');
	}
	dc.getElementById(id).classList.add('acttab');
	var playlists = dc.querySelectorAll('.tabcontent');
	for (var i = 0; i < playlists.length; i++) {
		playlists[i].classList.remove('active-tab');
	}
	dc.getElementById(tabName).classList.add('active-tab');
}
/*function selectListItem(item) {
	var isSelected = item.classList.contains('selected');
	if (isSelected) {
		item.classList.remove("selected");
	} else {
		item.classList.add("selected");
	}
	var checkbox = item.querySelector('input[type="checkbox"]');
	checkbox.checked = !checkbox.checked;
}*/
function tml()
{
const selm = dc.getElementById("selm");
if (selm.classList.contains("active")) {
    selm.classList.remove("active");
} else {
    selm.classList.add("active");
}
}
function tDrpd(){dc.getElementById("myDropdown").classList.toggle("show");}
function sMn(m,s=0) {
dc.querySelector(".selnum").innerHTML = m;
dc.getElementById("myDropdown").classList.remove("show");
if (s==1) {addM(m);calcTAmount()}
}

function addM(m) {
dc.querySelectorAll('.sitem').forEach(e=>{
const i = e.querySelector('input.datepicker-input');
if (!i) return;
let t = e.dataset.timestamp;
if (!t) {const ts = e.querySelector('#dend')?.dataset.timestamp;
t = ts && !isNaN(+ts) ? +ts * 1000 : (() => {
const d = i.dataset.originalDate;
if (!d) return Date.now();
const [day, mon, yr] = d.split('.').map(Number);
return Date.UTC(yr, mon - 1, day);
})();
if (isNaN(t)) return;
i.dataset.timestamp = t;
} else {t = +t;}
const d = new Date(t + m * 2592e6);
const s = d.toISOString().slice(8, 10) + '.' + d.toISOString().slice(5, 7) + '.' + d.toISOString().slice(0, 4);
i.value = s;
$(i).datepicker?.('setDate', s);
});
}

function showDetails(user, row) {
    const rDiv = dc.getElementById('result');
    const fbx = dc.getElementById('formBox');
    if (!rDiv || !fbx) {
        console.error('Не найден resultDiv или popup');
        return;
    }
    //dc.getElementById('usrinfo').innerHTML=user;
    const email = row.dataset.email;
    const pwd = row.dataset.pwd;
    const form = fbx.querySelector('#uDtails');
    form.querySelector('input[name="e_ml"]').value = email || '';
    form.querySelector('input[name="mph"]').value = row.dataset.ph || '';
    form.querySelector('textarea[name="cmmnt"]').value = row.dataset.cmnt || '';
    form.querySelector('div[id="regd"]').innerHTML = row.dataset.dreg || '';
    form.querySelector('#msnd').checked = row.dataset.snd === "1";
    const pwdInput = form.querySelector('input[name="passu"]');
    pwdInput.value = pwd || '';

    fbx.classList.add('active');
    /*
    pwdInput.classList.remove('visible');
    pwdInput.type = 'password';*/
    /*const eyeBtn = form.querySelector('.eye-btn');
        eyeBtn.textContent = '🔒';*/

    //popup.style.display = 'block';
    /*const rowRect = row.getBoundingClientRect();
    const popupRect = popup.getBoundingClientRect();
    const viewportWidth = window.innerWidth;
    const viewportHeight = window.innerHeight;
    let topPosition = rowRect.bottom + window.scrollY;
    if (topPosition + popupRect.height > viewportHeight + window.scrollY) {
        topPosition = rowRect.top + window.scrollY - popupRect.height;
    }
    const leftPosition = (viewportWidth - popupRect.width) / 2;

    popup.style.left = `${leftPosition}px`;
    popup.style.top = `${topPosition}px`;*/
    currentUser = user;
    currentRow = row;
}
    function togglePassword(btn) {
    const pwdInput = btn.previousElementSibling;
    const isVisible = pwdInput.type === 'password';
    pwdInput.type = isVisible ? 'text' : 'password';
    btn.textContent = isVisible ? '👁️' : '🔒';
}

function rSpaces(s) {
    let r = s.replace(/&nbsp;/g, ' ');
    r = r.replace(/\u00A0/g, ' ');
    r = r.replace(/\s+/gu, ' ').trim();
    return r;
  }
