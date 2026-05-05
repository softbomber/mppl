<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Dealers List</title>
    <style>
        table {
            width: 100%;
            border-collapse: collapse;
        }
        table, th, td {
            border: 1px solid black;
        }
        th, td {
            padding: 8px;
            text-align: left;
        }
        .dropdown-content {
            display: none;
            position: absolute;
            background-color: #f9f9f9;
            min-width: 160px;
            box-shadow: 0px 8px 16px 0px rgba(0,0,0,0.2);
            z-index: 1;
        }
        .dropdown-btn:hover .dropdown-content {
            display: block;
        }
        .sum-field {
            cursor: pointer;
        }
        .password-field {
            position: relative;
        }
        .show-pwd-btn {
            cursor: pointer;
        }
    </style>
</head>
<body>

<div id="dealersList">
<?php
include_once("config.php");

$query = "SELECT user, pwd, sum, eml, mindays, currency, rate, stop_disable FROM dealers";
$result = $link->query($query);

if ($result->num_rows > 0) {
    echo '<table id="dealersTable">';
    echo '<tr><th>User</th><th>Password</th><th>Sum</th><th>Email</th><th>Min Days</th><th>Currency</th><th>Rate</th><th>Access</th><th>Actions</th></tr>';
    while($row = $result->fetch_assoc()) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($row['user']) . '</td>';
        echo '<td class="password-field" data-pwd="' . htmlspecialchars($row['pwd']) . '"><button class="show-pwd-btn">Show Password</button></td>';
        echo '<td class="sum-field">' . htmlspecialchars($row['sum']) . '</td>';
        echo '<td>' . htmlspecialchars($row['eml']) . '</td>';
        echo '<td>' . htmlspecialchars($row['mindays']) . '</td>';
        echo '<td>' . htmlspecialchars($row['currency']) . '</td>';
        echo '<td>' . htmlspecialchars($row['rate']) . '</td>';
        echo '<td><input type="checkbox" class="toggle-access" ' . ($row['stop_disable'] ? 'checked' : '') . ' data-user="' . htmlspecialchars($row['user']) . '"></td>';
        echo '<td><button class="dropdown-btn">Drop Down Menu</button><div class="dropdown-content"><button class="toggle-access-btn" data-user="' . htmlspecialchars($row['user']) . '">' . ($row['stop_disable'] ? 'Enable Access' : 'Disable Access') . '</button></div></td>';
        echo '</tr>';
    }
    echo '</table>';
} else {
    echo "No records found.";
}
?>
</div>

<!-- Modal Window -->
<div id="modal" style="display:none;">
    <div id="modalContent">
        <label for="amountInput">Enter Amount:</label>
        <input type="number" id="amountInput">
        <button id="checkpointBtn" disabled>Checkpoint</button>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
    $(document).ready(function() {
        // Toggle Password Visibility
        $('.show-pwd-btn').on('click', function() {
            var pwdField = $(this).parent();
            var pwd = pwdField.data('pwd');
            pwdField.html('<input type="text" class="pwd-edit" value="' + pwd + '">');
        });

        // Enable Editing of Password
        $(document).on('blur', '.pwd-edit', function() {
            var newPwd = $(this).val();
            var user = $(this).closest('tr').find('td:first').text();
            $.ajax({
                url: 'update_password.php',
                method: 'POST',
                data: {user: user, pwd: newPwd},
                success: function(response) {
                    alert('Password updated successfully!');
                },
                error: function() {
                    alert('Error updating password.');
                }
            });
        });

        // Show Modal for Adding Sum
        $('.sum-field').on('click', function() {
            var currentSumField = $(this);
            var user = currentSumField.closest('tr').find('td:first').text();
            $('#modal').show();

            $('#checkpointBtn').on('click', function() {
                var amount = $('#amountInput').val();
                if (amount !== '') {
                    $.ajax({
                        url: 'add_sum.php',
                        method: 'POST',
                        data: {user: user, amount: amount},
                        success: function(response) {
                            currentSumField.text(response.newSum);
                            $('#modal').hide();
                            alert('Sum updated successfully!');
                        },
                        error: function() {
                            alert('Error updating sum.');
                        }
                    });
                }
            });
        });

        // Enable Checkpoint Button when Amount is Entered
        $('#amountInput').on('input', function() {
            if ($(this).val() !== '') {
                $('#checkpointBtn').prop('disabled', false);
            } else {
                $('#checkpointBtn').prop('disabled', true);
            }
        });

        // Toggle Access
        $('.toggle-access').on('change', function() {
            var user = $(this).data('user');
            var stopDisable = $(this).is(':checked') ? 1 : 0;
            $.ajax({
                url: 'toggle_access.php',
                method: 'POST',
                data: {user: user, stop_disable: stopDisable},
                success: function(response) {
                    alert('Access updated successfully!');
                },
                error: function() {
                    alert('Error updating access.');
                }
            });
        });
    });
</script>

</body>
</html>
