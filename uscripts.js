﻿
var mass=new Array(0);
var dsbl=new Array(0);
var vip,nfirst=0;
adm=gck("adm");
function UC()
{
var sum=summa();
var dep=Number(document.getElementById('deposit').innerHTML);
$("#txtHint").html("").fadeOut();
if(dep<sum)
{
$("#txtHint").html("На счету недостаточно "+(sum-dep).toFixed(2)+" сум. <br> пополните депозит").fadeIn();
document.getElementById("buy").disabled=1
}
else if(sum==0)
{document.getElementById("buy").disabled=1}
else
{
document.getElementById("buy").disabled=0;
$("#txtHint").html("").fadeIn();
}
document.getElementById('tcst').value = sum.toFixed(2);
}
function summa()
{
var sum=0,id=1;
now=new Date();
ttoc=now.getTime();
if(!vip)nfirst=0;
while(chck=document.getElementById('p'+id))
{
if(chck.checked==true && id==1){vip=1; nfirst=1}
else if(chck.checked==false && id==1)
{vip=0;}
if(vip && id>1)
    {
    mass[id]=chck.checked;
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
{pprice=document.getElementById('price'+id);
    ge=$("#dto"+id).html();
now2=new Date(parseInt(ge.substr(6,4),10),parseInt(ge.substr(3,2),10)-1,parseInt(ge.substr(0,2),10),0,0);
ttoc2=now2.getTime();1
if(ge.length<11 || ttoc2<=ttoc)
{ge=mkdt(0,0);}
sum+=Number((pprice.innerHTML)/30*(ddiff(document.getElementById('dtol'+id).value,ge))); 
}
id++;
}
return sum;
}
function sd(n)
{
var i=1;
nw=new Date();ttoc=nw.getTime();
while(ge=$("#dto"+i).html())
{
nw2=new Date(parseInt(ge.substr(6,4),10),parseInt(ge.substr(3,2),10)-1,parseInt(ge.substr(0,2),10),parseInt(ge.substr(11,2),10),parseInt(ge.substr(14,2),10));
if(ge.length<12 || nw2.getTime()<=nw)
ge=mkdt((n*30)*1440,0);
else
ge=pdt($("#dto"+i).html(),n*30,1)
$("#dtol"+i).val(ge);
i++;
}
UC();
}
function mkdt(n,hm)
{
var d=new Date();
if (!n) n=0;
if (!hm) hm=0;
d.setFullYear(d.getFullYear());
d.setMonth(d.getMonth(),d.getDate());
d.setHours(d.getHours());
d.setMinutes(d.getMinutes()+n);
if (hm) return addnull(d.getDate(),d.getMonth()+1,d.getFullYear(),d.getHours(),d.getMinutes());
return addnull(d.getDate(),d.getMonth()+1,d.getFullYear(),0,0);
}
function addnull(d,m,y,h,mn)
{
var d0='',m0='',h0='',mn0='';
if (d<10) d0='0';
if (m<10) m0='0';	
if (h<10 || h==0) h0='0';
if (mn<10 || mn==0) mn0='0';
if (!h && !mn)
return d0+d+'.'+m0+m+'.'+y;
return d0+d+'.'+m0+m+'.'+y+' '+h0+h+':'+mn0+mn;
}
function ddiff(d1,d2)
{
var dt1=new Date(parseInt(d1.substr(6,4),10),parseInt(d1.substr(3,2),10)-1,parseInt(d1.substr(0,2),10)); 
var dt2=new Date(parseInt(d2.substr(6,4),10),parseInt(d2.substr(3,2),10)-1,parseInt(d2.substr(0,2),10)); 
return ((dt1-dt2)/86400000)
}
function pdt(date,n,t)
{var d=new Date(parseInt(date.substr(6,4),10),parseInt(date.substr(3,2),10)-1,parseInt(date.substr(0,2),10),parseInt(date.substr(11,2),10),parseInt(date.substr(14,2),10));
d.setFullYear(d.getFullYear());
d.setMonth(d.getMonth(),d.getDate());
d.setHours(d.getHours());
d.setMinutes(d.getMinutes()+(n*1440));
if(!t)
return addnull(d.getDate(),d.getMonth()+1,d.getFullYear(),d.getHours(),d.getMinutes());
else
return addnull(d.getDate(),d.getMonth()+1,d.getFullYear());
}
function buyp()
{
var sndpu=[],sndpb =[];
var ge,id=1;
now=new Date();
ttoc=now.getTime();
while(chck=document.getElementById('p'+id))
{if(chck.checked==true) 
{var ge=$("#dto"+id).html();
now2=new Date(parseInt(ge.substr(6,4),10),parseInt(ge.substr(3,2),10)-1,parseInt(ge.substr(0,2),10),parseInt(ge.substr(11,2),10),parseInt(ge.substr(14,2),10));
if(ge.length>11)    
{
if(now2.getTime()<=ttoc)
{days=ddiff($("#dtol"+id).val(),mkdt(0));
sndpb.push([document.getElementById('p'+id).value,days]);}
else
{days=ddiff($("#dtol"+id).val(),ge);
sndpu.push([document.getElementById('p'+id).value,days]);} 
}
else
{days=ddiff($("#dtol"+id).val(),mkdt(0));
sndpb.push([document.getElementById('p'+id).value,days]); 
}
}
id++;
}
uid=$('input[name="uid"]').val();
$.post("pbuy.php",{uid:uid,pu:sndpu,pb:sndpb},function(r) 
{(adm==1)?dtp=0:dtp=7;
dlr=r.substr(0,1);
ids=r.substr(1,r.length-1);
ids=ids.replace(/\s/g,'').split('],[');
for(j=0,lj=ids.length;j<lj;j++)
{ids[j]=ids[j].replace("[",'');var t=ids[j].replace("]",'');ids[j]=t.split(',')}
var dep=Number($("#deposit").html())
$("#deposit").html((dep-summa()).toFixed(2));
len=sndpb.length;
for(i=0;i<len;i++)
{
id=sndpb[i][0];
days=sndpb[i][1];
ge=mkdt(days*1440,1);
t="<td align=center id='dt";
row=$("#r"+id);
nsb='<td id="pa'+id+'"></td>';
sb='<td id="pa'+id+'" onclick="stop('+id+',this)" align=center title="Остановить пакет"><button id="pas'+id+'" class="stopb ui-icon ui-icon-stop"></button></td>';
est=t+"f"+id+"'>"+mkdt(0,1)+t+"o"+id+"'>"+mkdt(days*1440,1)+"</td>";
if($("#dto"+id).html()=="Не активен")
{(dlr==1 || adm==1)?sb+=est:sb=nsb+est;
$("#dto"+id).remove();
$("#pa"+id).replaceWith(sb);
}
else
{$("#dtf"+id).html(mkdt(0,1));
$("#dto"+id).html(ge);
$("#dtf"+id).parent().find("td:eq(2)").replaceWith(sb)
}
$("#dtol"+id).datepicker("option","minDate",new Date(parseInt(ge.substr(6,4),10),parseInt(ge.substr(3,2),10)-1,parseInt(ge.substr(0,2),10)+dtp));
$("#dtol"+id).val(pdt(ge,dtp,1));
(row.index()%2)?row.removeClass().addClass("row4"):row.removeClass().addClass("row3");
}
len=sndpu.length;
for(i=0;i<len;i++)
{
id=sndpu[i][0];
days=sndpu[i][1];
var ge=$("#dto"+id).html();
nw2=new Date(parseInt(ge.substr(6,4),10),parseInt(ge.substr(3,2),10)-1,parseInt(ge.substr(0,2),10),parseInt(ge.substr(11,2),10),parseInt(ge.substr(14,2),10));
if (nw2.getTime()<=ttoc)
    {ge=mkdt(days*1440,1);$("#pas"+id).addClass("stopb ui-icon ui-icon-stop");}
else
     ge=pdt($("#dto"+id).html(),days,0);
     $("#dtf"+id).html(mkdt(0,1));
     $("#dto"+id).html(ge);
     $("#dtol"+id).datepicker("option","minDate",new Date(parseInt(ge.substr(6,4),10),parseInt(ge.substr(3,2),10)-1,parseInt(ge.substr(0,2),10)+dtp));
     $("#dtol"+id).val(pdt(ge,dtp,1,0));
}
UC();
});
}
function pause(idd)
{
var ge,id = 1;
uid=$('input[name="uid"]').val();
$.post("pause.php",{p:idd,id:uid},function(res) 
{
if($("#pas"+idd).hasClass('ui-icon ui-icon-play'))
	{
	$("#pas"+idd).removeClass("ui-icon ui-icon-play").addClass("ui-icon ui-icon-pause");
	$("#dto"+idd).html(mkdt(Number(res),1));
	}
else if($("#pas"+idd).hasClass('ui-icon ui-icon-pause'))
	$("#pas"+idd).removeClass("ui-icon ui-icon-pause").addClass("ui-icon ui-icon-play");
});
}


function mtransf()
{
var l=$('#tolog').val();
var s=Number($("#sumtransf").val());
$.post("pbuy.php",{l:l,sum:s},function(r)
{
if(r)
{	
var dp=Number($('#deposit').html());
$(deposit).html((dp-s).toFixed(2));
$(txtHint).html("Счёт успешно пополнен");
}
else
$(txtHint).html("Произошла ошибка!!!");
});
}
function stop(i,e)
{
(adm==1)?dtp=0:dtp=7;
var nw=new Date().getTime();
uid=$('input[name="uid"]').val();
var rq=$.ajax({url:"undo.php",type:"POST",cache:false,dataType:"json",data:{stop:i,uid:uid}});
rq.done(function(r){alert(r.s);
if(r.s=="0")
{rw=$(e).parents('tr'); 
(rw.index()%2)?rw.removeClass().addClass("row2"):rw.removeClass().addClass('row1');
$(e).find('span').removeClass("stopb ui-icon ui-icon-stop").remove();
$(e).parent().find("td:eq(3)").remove().end().find('td:eq(3)').remove();
id=$(e).parent().find("input").val();
$(e).parent().find("td:eq(2)").replaceWith("<td id='pa"+id+"'></td><td id='dto"+id+"'colspan=2 align=center>Не активен</td>");
nw=new Date();
$("#dtol"+i).datepicker("option","minDate",dtp).val(mkdt(dtp*1440,0))
}
else
{
if (r.r.e<nw)$(e).find('span').removeClass("stopb ui-icon ui-icon-stop").remove();
$("#dtf"+i).html(utj(r.r.s));
$("#dto"+i).html(utj(r.r.e));
var ge=$("#dto"+i).html();
$("#dtol"+i).datepicker("option","minDate",new Date(parseInt(ge.substr(6,4),10),parseInt(ge.substr(3,2),10)-1,parseInt(ge.substr(0,2),10)+dtp)).val(pdt(ge,dtp,0));
}
return false
});
}
function loglist(p)
{$(txtHint).html("");
$.post("undo.php",{list:1,page:p},function(r) 
{
$(result).html(r);
});
}
function racc(){$.post("pbuy.php",{racc:1},function(r) 
{if(r)
{alert(r);
$(deposit).html(r);}
else
$(txtHint).html("Произошла ошибка!!!");
});}
function idprt()
{$(result).load("/ids&prts.htm");$('#uinfo').html('');}
function utj(t)
{var d=new Date();
d.setTime(t*1000);
return addnull(d.getDate(),d.getMonth()+1,d.getFullYear(),d.getHours(),d.getMinutes())}
function gck(cnm){var r=document.cookie.match('(^|;) ?'+cnm+'=([^;]*)(;|$)');if(!r)return null;else return(unescape(r[2]))}
function userslog(p,u){$(txtHint).html('');
$.post("undo.php",{lst:1,uid:u,page:p},function(r){$(result).html(r)})}	
function pfile()
{$(profile).fadeIn(300);
var popMargTop=($(profile).height()+4)/2; 
var popMargLeft=($(profile).width()+3)/2; 
$(profile).css({'margin-top':-popMargTop,'margin-left':-popMargLeft});
$('body').append('<div id="mask"></div>');
$('#mask').fadeIn(300);
$(document).on('click','#mask',function(){$("#profile, #mask").fadeOut(300,function(){$('#mask').remove()})});
}
function spwdu()
{v=$('#pwd').val();alert(v)}