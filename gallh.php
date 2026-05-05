<?php
include_once("config.php");
checkLoggedIn("yes");
?>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
body {
    color: #b3c5d5;
    flex-direction: column;
}
table {
    width: 100%;
    border-collapse: collapse;
}
th, td {
    border: 1px solid #ccc;
    padding: 8px;
    text-align: left;
}
th {
    background-color: #5b5b5b;
}
#dealer {
    width: 200px;
    padding: 8px;
    font-size: 14px;
    margin-bottom: 10px;
}
#dealer-suggestions {
    border: 1px solid #ccc;
    max-height: 200px;
    overflow-y: auto;
    position: absolute;
    background-color: white;
    width: 200px;
    z-index: 1000;
    display: none;
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
    border-radius: 5px;
    margin-top: 2px;
}
#dealer-suggestions div {
    padding: 8px;
    font-size: 14px;
    cursor: pointer;
}
#dealer-suggestions div:hover {
    background-color: #f0f0f0;
}
#dealer-suggestions.show {
    display: block;
}
input{
border:1px solid #284040;
border-radius:3px;
background: #13212d;
text-align: center;
color: #bec1d0;
font-family:"PT Mono", monospace;
height:27px;
}
.fgrp{display:flex;justify-content:space-between;align-items:center;padding:0 7px}
    </style>
</head>
<body>
<div style="width:100%;text-align:center">ОБОРОТКА ПО ДИЛЕРАМ</div>
<form id="date-filter-form">
 <div class=fgrp><div><label for="start_date">Начальная дата</label>
     <input type="date" id="start_date" name="start_date" required></div>
     <div><label for="end_date">Конечная дата</label>
     <input type="date" id="end_date" name="end_date" required></div></div>
     <div class=fgrp><div><label for="dealer">Дилер:</label>
     <input type="text" id="dealer" name="dealer" placeholder="Введите имя дилера">
     <div id="dealer-suggestions"></div></div>
    <div><button type="submit">Фильтровать</button></div></div>
</form>
<div style="overflow:auto">
    <table id="data-table">
        <thead>
            <tr><th>Dealer</th><th>Action</th><th>Packet</th><th>Account User</th><th>Time</th><th>Dend</th><th>Days</th><th>Ost</th><th>Sum</th><th>Ost After</th></tr>
        </thead>
        <tbody>
        </tbody>
    </table>
</div>
<script>
function toDate(d) {return new Date(d * 1000).toLocaleString("ru-RU",{day:"2-digit",month:"2-digit",year:"2-digit",hour:"2-digit",minute:"2-digit"}).replace(",", "");}
</script>
</body>
</html>