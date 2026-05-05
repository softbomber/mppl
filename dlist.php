<script type="text/javascript" src="js110/jquery-1.9.1.js"></script>
<script type="text/javascript">
$(document).ready(function()
{
$("#dlr").click(function()
{

$.ajax
({
type: "GET",
url: "ajax_d.php",
data: false,
cache: false,
success: function(html)
{
$("#dlr").hide();
$lselelem=$("#dlr").html();
//$("#list" $1selelem).attr("selected", "selected");
document.getElementById("ok").style.display = "";
$("#list").html(html);
//$('select[name='+featurenumber+'] option:eq('+indeximgnum+')').attr('selected','selected');
$('select#list option:eq(40)').attr('selected','selected');
$('select#list').show();
}
});
});

$("#ok").click(function()
{
var stxt = $("select#list option:selected").text();
$("#dlr").html(stxt).show();
document.getElementById("ok").style.display = "none";
$('select#list').hide();
}
);
    });
</script>

<table>
<tr><td><span id="dlr">schamen</span><select id="list" style="display:none"></select><button id="ok" style="display:none">OK</button></td></tr>
</table>

