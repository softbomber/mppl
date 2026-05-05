<?php
include_once("config.php"); // Подключение к базе данных
 checkLoggedIn("yes");
if ($_SESSION['a'] == 1) {
$input = file_get_contents('php://input');
$data = json_decode($input, true);

$response = ['success' => false, 'message' => '', 'data' => null];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($data['action'])) {
    switch ($data['action']) {
        case 'getDealerData':
            $user = $data['un'];
            $response = ['success' => false, 'message' => '', 'data' => null];

            $query = "SELECT dealer, pwd FROM accounts WHERE user = ?";
            $stmt = $link->prepare($query);
            $stmt->bind_param("s", $user);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                $row = $result->fetch_assoc();
                $d = $row['dealer'];
                $p = $row['pwd'];

                $dealerQuery = "SELECT user,name,t_id,id FROM dealers WHERE id = ?";
                $dealerStmt = $link->prepare($dealerQuery);
                $dealerStmt->bind_param("i", $d);
                $dealerStmt->execute();
                $dealerResult = $dealerStmt->get_result();

                if ($dealerResult->num_rows > 0) {
                    $dealerRow = $dealerResult->fetch_assoc();
                    $response['success'] = true;
                    $response['data'] = [
                        'dealerUser' => $dealerRow['user'],
                        'dealerId' => $dealerRow['id'],
                        'dealerName' => $dealerRow['name'],
                        'dealertId' => $dealerRow['t_id']
                    ];
                } else {
                    $response['message'] = 'Дилер не найден!';
                }
            } else {
                $response['message'] = 'Пользователь не найден!';
            }
            break;

case 'updateData':
    $user = $data['un']; // Имя пользователя
    $did = $data['did'];
    $grpvar = 1; // Значение по умолчанию для grpvariant
    $ids = []; // Массив для хранения grpid

    $escapedUser = mysqli_real_escape_string($link, $user);
    $escapedDid = mysqli_real_escape_string($link, $did);
    $dealerQuery = "SELECT dealer FROM accounts WHERE user = '$escapedUser'";
    $dealerResult = $link->query($dealerQuery);

    if ($dealerResult->num_rows > 0) {
        $dealerRow = $dealerResult->fetch_assoc();
        $d = $dealerRow['dealer']; // Получаем did
    } else {
        $response['message'] = 'Дилер не найден!';
        $response['success'] = false;
        break;
    }

    $pwdQuery = "SELECT pwd FROM accounts WHERE user = '$escapedUser'";
    $pwdResult = $link->query($pwdQuery);

    if ($pwdResult->num_rows > 0) {
        $pwdRow = $pwdResult->fetch_assoc();
        $p = $pwdRow['pwd'];
    } else {
        $response['message'] = 'Пользователь не найден в таблице accounts!';
        $response['success'] = false;
        break;
    }

    $q = "SELECT grpid FROM subgroups WHERE playlstid = $grpvar";
    $res = $link->query($q);

    if ($res->num_rows > 0) {
        while ($rw = $res->fetch_assoc()) {
            $ids[] = $rw['grpid'];
        }
        $inList = implode(',', $ids);
    } else {
        $inList = '';
    }

    if (strlen($p) <= 5) {
        $p .= "12";
    }

    $iptvusr = "mp" . $d . "_" . $user;
    $escapedIptvusr = mysqli_real_escape_string($link, $iptvusr);
    $escapedP = mysqli_real_escape_string($link, $p);
    $escapedInList = mysqli_real_escape_string($link, $inList);
    $plName=genRstr($login);
    $updateQuery = "UPDATE accounts 
                    SET iptvusr = '$escapedIptvusr', 
                        iptvpwd = '$escapedP', 
                        iptvcdn = 1,
			plname='$plName',
                        iptvplaylist = '$escapedInList', 
                        grpvariant = $grpvar 
                    WHERE user = '$escapedUser'";

    if ($link->query($updateQuery)) {
        ilookCreateAcc($user, $p, $d,$plName);

        $response['message'] = 'Пользователь успешно обновлен!';
        $response['success'] = true;
    } else {
        $response['message'] = 'Ошибка при обновлении данных: ' . $link->error;
        $response['success'] = false;
    }

    break;
    }
echo json_encode($response);
exit();
}
}
else
exit();

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Привязка IPTV пользователя</title>
    <style>
*,*::before,*::after{box-sizing:border-box}
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f9;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
        }
        .form-container {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
            width: 350px;
            text-align: center;
        }
        input[type="text"] {
            width: 100%;
            padding: 10px;
            margin: 10px 0;
            border: 1px solid #ccc;
            border-radius: 5px;
text-align:center;
        }
        button {
            width: 100%;
            padding: 10px;
            background-color: #007BFF;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }
        button:hover {
            background-color: #0056b3;
        }
        .dealer-info {
            margin-top: 20px;
            text-align: left;
        }
        .dealer-info p {
            margin: 5px 0;
        }
h1 {
font-size:24px;
color:#333;
margin-bottom:20px;
}
    </style>
</head>
<body>
    <div class="form-container">
        <h1>Привязка IPTV пользователя</h1>
        <form id="mainForm">
            <label for="un">Имя пользователя:</label>
            <input type="text" id="un" name="un" placeholder="имя пользователя" required>
            <button type="button" id="actionButton">Обновить</button>
        </form>
        <div id="dealerInfo" class="dealer-info" style="display: none;">
            <h3>Данные дилера:</h3>
            <p><strong>User:</strong> <span id="dealerUser"></span></p>
            <p><strong>Name:</strong> <span id="dealerName"></span></p>
            <p><strong>tId:</strong> <span id="tId"></span></p>
            <p><strong>ID:</strong> <span id="dealerId"></span></p>
        </div>
    </div>

    <script>
        const form = document.getElementById('mainForm');
        const uN = document.getElementById('un');
        const actionButton = document.getElementById('actionButton');
        const dealerInfo = document.getElementById('dealerInfo');
        const dealerUser = document.getElementById('dealerUser');
        const dealerName = document.getElementById('dealerName');
        const dealertId = document.getElementById('tId');
        const dealerId = document.getElementById('dealerId');
        let isCreateMode = false;
        const kM={'й': 'q','ц':'w','у':'e','к':'r','е':'t','н':'y','г':'u','ш':'i','щ':'o','з':'p','ф':'a','ы':'s','в':'d','а':'f','п':'g','р':'h','о':'j','л':'k','д':'l','я':'z','ч':'x','с':'c','м':'v','и':'b','т':'n','ь':'m',
            'ё':'`','ж':'[','э':']','б':',','ю':'.','ъ':';'};
        function ToL(text) {
            return text.split('').map(char => {
                const lowerChar = char.toLowerCase();
                if (kM[lowerChar]) {
                    return char === lowerChar ? kM[lowerChar]:kM[lowerChar].toUpperCase();
                }
                return char;
            }).join('');
        }
       uN.addEventListener('input', () => {
            uN.value = ToL(uN.value);
            if (isCreateMode) {
                actionButton.textContent = 'Обновить';
                isCreateMode = false;
                dealerInfo.style.display = 'none';
            }
        });
        uN.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                actionButton.click();
            }
        });
        actionButton.addEventListener('click', async () => {
            const un = uN.value.trim();
            if (!un) {
                alert('Введите имя пользователя!');
                return;
            }

            if (!isCreateMode) {
                const response = await fetch('craccform.php', {
                    method: 'POST',
                    headers: { 'Content-Type':'application/json' },
                    body: JSON.stringify({ action: 'getDealerData', un })
                });
                const result = await response.json();
                if (result.success) {
                    dealerUser.textContent = result.data.dealerUser;
                    dealerId.textContent = result.data.dealerId;
                    dealertId.textContent = result.data.dealertId;
                    dealerName.textContent = result.data.dealerName;
                    dealerInfo.style.display = 'block';
                    actionButton.textContent = 'Создать';
                    isCreateMode = true;
                } else {
                    alert(result.message || 'Ошибка при получении данных дилера.');
                }
            } else {
                const response = await fetch('craccform.php', {
                    method: 'POST',
                    headers: { 'Content-Type':'application/json' },
                    body: JSON.stringify({
                        action: 'updateData',
                        un,
                        did: dealerId.textContent,
                        pwd: ''
                    })
                });
                const result = await response.json();
                if (result.success) {
                    alert(result.message);
                    uN.value = '';
                    dealerInfo.style.display = 'none';
                    actionButton.textContent = 'Обновить';
                    isCreateMode = false;
                } else {
                    alert('Ошибка при обновлении данных.');
                }
            }
        });
        uN.addEventListener('input', () => {
            if (isCreateMode) {
                actionButton.textContent = 'Обновить';
                isCreateMode = false;
                dealerInfo.style.display = 'none';
            }
        });
    </script>
</body>
</html>