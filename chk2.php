<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>User Information</title>
<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>
<style>
    .user-info {
        display: none;
        position: fixed;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        background-color: white;
        padding: 20px;
        border: 1px solid #ccc;
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        z-index: 9999;
        width: 300px;
        max-width: 80%;
    }
    .close-btn {
        position: absolute;
        top: 10px;
        right: 10px;
        background-color: red;
        color: white;
        border: none;
        padding: 5px 10px;
        cursor: pointer;
    }
</style>
</head>
<body>
<?php

// Подсчет активных и неактивных записей
foreach ($data['data'] as $item) {
    if (strpos($item['bundle_state'], 'активен до') !== false) {
        $active_count++;
    } else {
        $inactive_count++;
    }
}

echo "Active records: " . $active_count . "<br>";
echo "Inactive records: " . $inactive_count . "<br>";

echo "Total records: " . $data['recordsTotal'] . "<br>";
echo "Filtered records: " . $data['recordsFiltered'] . "<br>";

echo "<table border='1'>";
echo "<tr><th>Username</th><th>Access Key</th><th>Bundle State</th><th>Ref Balance</th><th>Ref Remark</th><th>More Info</th></tr>";
foreach ($data['data'] as $item) {
    echo "<tr>";
    echo "<td>" . $item['refLinkUName']['username'] . "</td>";
    echo "<td>" . $item['access_key'] . "</td>";
    echo "<td>" . $item['bundle_state'] . "</td>";
    echo "<td>" . $item['refBalance'] . "</td>";
    echo "<td>" . $item['refRemark']['note'] . "</td>";
    echo "<td><button class='info-btn' data-username='" . $item['refLinkUName']['username'] . "'>More Info</button></td>";
    echo "</tr>";
}
echo "</table>";
?>

<div class="user-info">
    <button class="close-btn">Close</button>
    <h2>User Information</h2>
    <p id="user-details"></p>
</div>

<script>
$(document).ready(function(){
    $(".info-btn").click(function(){
        var username = $(this).data("username");
        $.ajax({
            url: "ilchkacc.php",
            type: "GET",
            data: {username: username},
            success: function(response){
                $("#user-details").html(response);
                $(".user-info").fadeIn();
            }
        });
    });

    $(".close-btn").click(function(){
        $(".user-info").fadeOut();
    });
});
</script>

</body>
</html>
