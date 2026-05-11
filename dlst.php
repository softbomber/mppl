<?php
include_once("config.php");
checkLoggedIn("yes");
include_once("functions.php");
$adm = $_SESSION['a'];
if (!$adm) exit();

$query = "SELECT id, user, pwd, sum, eml, mindays, currency, rate, stop_disable, `limit`,lu, postpaid,name,t_id,t_fname FROM dealers order by (sum > 0) DESC, lu DESC, sum DESC";
$result = $link->query($query) or die("Ошибка SQL: " . $link->error_list);

$dealers = [];
while ($row = $result->fetch_assoc()) {
    $dealers[] = $row;
}
?>
<div class="dlst-title">Dealers List</div>

<input type="text" id="searchUser" placeholder="Поиск по пользователю">

<table id="dlrlist">
    <thead>
        <tr>
            <th width=153>User</th>
            <th width=153>Name</th>
            <th>Password</th>
            <th>Sum</th>
            <th width=105>Limit</th>
            <th width=240>Email</th>
            <th>Min Days</th>
            <th>Currency</th>
            <th>Rate</th>
            <th>Access</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody id="dealersTable">
        <?php foreach ($dealers as $dealer): ?>
        <tr data-id="<?= $dealer['id'] ?>" data-user="<?= $dealer['user'] ?>" data-sum="<?= sprintf("%.2f",$dealer['sum']) ?>" data-limit="<?= sprintf("%.2f",$dealer['limit']) ?>">
            <td><?= $dealer['user'] ?></td>
            <td><?= $dealer['name'] ?></td>
            <td>
                <div class="dlst-pwd">
                    <input type="password" value="<?= $dealer['pwd'] ?>" readonly>
                    <button class="toggle-password">&#128065;&#8205</button>
                </div>
            </td>
            <td class="amount">
                <div class="sum-input"> <?=  sprintf("%.2f", $dealer['sum']) ?> </div>
                <div class="update-sum-btn">Обновить</div>
            </td>
            <td class="limit"><?= ($dealer["postpaid"]) ?   sprintf("%.2f",$dealer['limit']):"" ?></td>
            <td><?= htmlspecialchars($dealer['eml']) ?></td>
            <td><?= htmlspecialchars($dealer['mindays']) ?></td>
            <td><?= ($dealer['currency']) ? "SUM":"USD" ?></td>
            <td><?= $dealer['rate'] ?></td>
            <td>
                <label class="access-toggle">
                    <input type="checkbox" <?= $dealer['stop_disable'] ? 'checked' : '' ?>>
                    <?= $dealer['stop_disable'] ? 'Без Доступа' : 'Доступ' ?>
                </label>
            </td>

            <td class="tcell">
                <button class="ellipsis-button">&#x2026;</button>
                <div class="drpdn-cntnt">
                    <a href="#" class="add-sum">CHECKPOINT</a>
                    <a href="#" class="edit-limit">Изменить лимит</a>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>


<div id="modal" class="dlst-modal">
    <span class="close-modal">&times;</span>
    <h2>Checkpoint для <span id="modal-user-name"></span></h2>
    <input type="number" id="new-sum" placeholder="Введите сумму">
    <button id="checkpoint" disabled>CHECKPOINT</button>
</div>


<div id="modal-limit" class="dlst-modal">
    <span class="close-modal">&times;</span>
    <h2>Изменить лимит для <span id="modal-user-name-limit"></span></h2>
    <input type="number" id="new-limit" placeholder="Введите сумму">
    <button id="save-limit" disabled>Изменить лимит</button>
</div>

<script>

    document.querySelectorAll('.add-sum').forEach(function(link) {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            let row = this.closest('tr');
            let dealerId = row.dataset.id;
            let dealerUser = row.dataset.user;
            let dealerSum = row.dataset.sum;

            let modal = document.getElementById('modal');
            modal.dataset.dealerId = dealerId;
            document.getElementById('modal-user-name').textContent = dealerUser;
            document.getElementById('new-sum').value = dealerSum;
            modal.style.display = 'block';
        });
    });
    document.querySelector('#modal .close-modal').addEventListener('click', function() {
        document.getElementById('modal').style.display = 'none';
    });
    document.getElementById('new-sum').addEventListener('input', function() {
        let checkpointButton = document.getElementById('checkpoint');
        checkpointButton.disabled = !this.value;
    });

var sTimeout;
document.getElementById('searchUser').addEventListener('input', function() {
    clearTimeout(sTimeout);
    sTimeout = setTimeout(() => {
        let filter = this.value.toLowerCase();
        let rows = document.querySelectorAll('#dealersTable tr');
        rows.forEach(row => {
            let user = row.dataset.user.toLowerCase();
            row.style.display = user.includes(filter) ? '' : 'none';
        });
    }, 500);
});

document.querySelectorAll('.toggle-password').forEach(function(button) {
    button.addEventListener('click', function() {
        let container = this.closest('.dlst-pwd');
        let passwordField = container.querySelector('input[type="password"], input[type="text"]');
        if (passwordField) {
            if (passwordField.type === 'password') {
                passwordField.type = 'text';
                this.innerHTML = '&#128065;';
            } else {
                passwordField.type = 'password';
                this.innerHTML = '&#128065;&#8205';
            }
        }
    });
});

document.querySelectorAll('.ellipsis-button').forEach(function(button) {
    button.addEventListener('click', function(e) {
        e.stopPropagation();
        let ddownCntnt = this.nextElementSibling;
        ddownCntnt.style.display = ddownCntnt.style.display === 'block' ? 'none' : 'block';
    });
});

document.addEventListener('click', function() {
    document.querySelectorAll('.drpdn-cntnt').forEach(function(dropdown) {
        dropdown.style.display = 'none';
    });
});

// Открытие модального окна для обновления суммы
document.querySelectorAll('.update-sum-btn').forEach(function(button) {
    button.addEventListener('click', function() {
        let row = this.closest('tr');
        let dealerId = row.dataset.id;
        let sumField = row.querySelector('.sum-input');

        let currentSum = parseFloat(sumField.value);

        let modal = document.getElementById('modal');
        modal.dataset.dealerId = dealerId;
        document.getElementById('modal-user-name').textContent = row.dataset.user;
        document.getElementById('new-sum').value = '';
        modal.style.display = 'block';
    });
});

document.getElementById('new-sum').addEventListener('input', function() {
    let checkpointButton = document.getElementById('checkpoint');
    checkpointButton.disabled = !this.value;
});

document.getElementById('checkpoint').addEventListener('click', function() {
    let dealerId = document.getElementById('modal').dataset.dealerId;
    let newSum = parseFloat(document.getElementById('new-sum').value);

    fetch('updsum.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `id=${dealerId}&sum=${newSum}`
    }).then(response => response.json()).then(data => {
        if (data.success) {
            document.querySelector(`tr[data-id="${dealerId}"] .sum-input`).innerHTML = data.newSum;
            hMsg.dMsg('Cумма успешно обновлена');
            document.getElementById('modal').style.display = 'none';
        } else {
           hMsg.dMsg('Ошибка при обновлении суммы');
        }
    });
});

document.getElementById('save-limit').addEventListener('click', function() {
    let dealerId = document.getElementById('modal-limit').dataset.dealerId;
    let newLimit = document.getElementById('new-limit').value;

    fetch('updlimit.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `id=${dealerId}&limit=${newLimit}`
    }).then(response => response.json()).then(data => {
        if (data.success) {
            document.querySelector(`tr[data-id="${dealerId}"] .limit`).innerText = data.newLimit;
            hMsg.dMsg('Лимит успешно обновлен');
            document.getElementById('modal-limit').style.display = 'none';
        } else {
            hMsg.dMsg('Ошибка при обновлении лимита');
        }
    });
});

document.querySelectorAll('.edit-limit').forEach(function(link) {
    link.addEventListener('click', function(e) {
        e.preventDefault();
        let row = this.closest('tr');
        let dealerId = row.dataset.id;
        let dealerUser = row.dataset.user;
        let dealerLimit = row.dataset.limit;

        let modal = document.getElementById('modal-limit');
        modal.dataset.dealerId = dealerId;
        document.getElementById('modal-user-name-limit').textContent = dealerUser;
        document.getElementById('new-limit').value = dealerLimit;
        modal.style.display = 'block';
    });
});

document.querySelector('#modal-limit .close-modal').addEventListener('click', function() {
    document.getElementById('modal-limit').style.display = 'none';
});

document.getElementById('new-limit').addEventListener('input', function() {
    let saveLimitButton = document.getElementById('save-limit');
    saveLimitButton.disabled = !this.value;
});

document.querySelector('#modal .close-modal').addEventListener('click', function() {
    document.getElementById('modal').style.display = 'none';
});

document.querySelectorAll('.access-toggle input[type="checkbox"]').forEach(function(checkbox) {
      checkbox.addEventListener('change', function() {
           let dealerId = this.closest('tr').dataset.id;
            let accessState = this.checked ? 1 : 0;
            fetch('taccess.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `id=${dealerId}&stop_disable=${accessState}`
            }).then(response => response.text()).then(data => {
                this.nextSibling.nodeValue = this.checked ?  'Без Доступа' : 'Доступ';
                hMsg.dMsg(data);
            });
        });
});
</script>
