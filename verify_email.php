<?php
/**
 * Email verification endpoint.
 *
 * Handles:
 *  - GET  ?token=...           — auto-verify via link click
 *  - POST code=... &dealer=... — manual code entry
 *  - POST action=resend &dealer=... &email=... — resend to a new email (attempt 2)
 *  - POST action=cancel &dealer=... — user cancels, delete account if unverified
 */
include_once("config.php");
require_once(__DIR__ . '/email_verify.php');

$result  = null;
$error   = null;
$success = false;
$deleted = false;
$showResendForm = false;
$dealerId = null;

// ---- Link click: GET ?token=... ----
if (isset($_GET['token'])) {
    $row = verifyEmailByToken($link, $_GET['token']);
    if ($row) {
        $success  = true;
        $dealerId = $row['dealer_id'];
    } else {
        // Token failed — try to extract dealer_id so the code form works
        $dealerId = getDealerByToken($link, $_GET['token']);
        $error = "Ссылка недействительна. Введите код из письма вручную.";
    }
}

// ---- Manual code submit ----
if (isset($_POST['code']) && !isset($_POST['action'])) {
    $code     = trim($_POST['code']);
    $dealerId = (int)($_POST['dealer'] ?? 0);

    $row = verifyEmailByCode($link, $code, $dealerId);
    if ($row) {
        $success  = true;
        $dealerId = $row['dealer_id'];
    } else {
        $attempt = getVerificationAttempt($link, $dealerId);
        if ($attempt >= 2) {
            // 2nd failed attempt — delete account
            deleteUnverifiedDealer($link, $dealerId);
            $deleted = true;
            $error   = "Email не подтверждён дважды. Аккаунт удалён. Пожалуйста, зарегистрируйтесь заново.";
        } else {
            $error = "Неверный код. Проверьте правильность ввода или запросите новый код.";
            $showResendForm = true;
        }
    }
}

// ---- Resend with new email ----
if (isset($_POST['action']) && $_POST['action'] === 'resend') {
    $dealerId = (int)($_POST['dealer'] ?? 0);
    $newEmail = trim($_POST['email'] ?? '');

    if ($dealerId <= 0 || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
        $error = "Укажите корректный email.";
        $showResendForm = true;
    } else {
        $attempt = getVerificationAttempt($link, $dealerId);
        $nextAttempt = $attempt + 1;

        if ($nextAttempt > 2) {
            deleteUnverifiedDealer($link, $dealerId);
            $deleted = true;
            $error   = "Превышено количество попыток. Аккаунт удалён.";
        } else {
            // Update email in dealers
            $stmt = $link->prepare("UPDATE dealers SET eml = ? WHERE id = ? AND email_verified = 0");
            $stmt->bind_param('si', $newEmail, $dealerId);
            $stmt->execute();
            $stmt->close();

            sendVerificationEmail($link, $dealerId, $newEmail, $nextAttempt);
            $error = null;
            $result = "Новый код отправлен на <b>" . htmlspecialchars($newEmail) . "</b>. Это последняя попытка.";
        }
    }
}

// ---- Cancel registration ----
if (isset($_POST['action']) && $_POST['action'] === 'cancel') {
    $dealerId = (int)($_POST['dealer'] ?? 0);
    if ($dealerId > 0) {
        deleteUnverifiedDealer($link, $dealerId);
        $deleted = true;
    }
}

// If verified successfully, log the user in and redirect
if ($success && $dealerId) {
    $stmt = $link->prepare(
        "SELECT id, user, a, hash, currency, rate, postpaid, t_srt, t_fname, t_lname, t_usr
         FROM dealers WHERE id = ?"
    );
    $stmt->bind_param('i', $dealerId);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows === 1) {
        $row = $res->fetch_assoc();
        $hash = gensessionhash();
        $stU = $link->prepare("UPDATE dealers SET hash = ? WHERE id = ?");
        $stU->bind_param('si', $hash, $dealerId);
        $stU->execute();
        $stU->close();

        $s_time = time() + 86400;
        setcookie("a", $row['a'] ?? 0, $s_time, '/');
        setcookie("i", $row['id'], $s_time, '/');
        setcookie("hsh", $hash, $s_time, '/');

        ini_set('session.cookie_lifetime', 86400);
        ini_set('session.gc_maxlifetime', 86400);
        if (isset($row['t_fname'])) $_SESSION['t_fname'] = $row['t_fname'];
        if (isset($row['t_lname'])) $_SESSION['t_lname'] = $row['t_lname'];
        if (isset($row['t_usr']))   $_SESSION['t_usr']   = $row['t_usr'];

        cleanMemberSession(
            $row['user'], $row['id'], $row['a'] ?? 0, $hash,
            $row['id'], $row['currency'] ?? 0, $row['rate'] ?? 0, $row['postpaid'] ?? 0
        );

        if (isMobileDevice()) {
            header("Location: mb.php");
        } else {
            header("Location: dealer.php");
        }
        exit;
    }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Подтверждение Email — Metropoliten</title>
<link href="https://fonts.googleapis.com/css2?family=PT+Mono&family=Source+Sans+3&display=swap" rel="stylesheet">
<link rel="stylesheet" type="text/css" href="css/mb.css?v=1" />
<style>
body { background: #0a0e16; color: #c9d1d9; font-family: 'Source Sans 3', sans-serif; margin: 0; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
.verify-card { background: #131a26; border-radius: 14px; padding: 40px 32px; max-width: 420px; width: 100%; box-shadow: 0 8px 32px rgba(0,0,0,.4); }
.verify-card h1 { color: #4d8eff; font-size: 20px; text-align: center; margin: 0 0 8px; }
.verify-card .subtitle { text-align: center; color: #8b949e; margin-bottom: 24px; font-size: 14px; }
.verify-card .msg { padding: 12px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; }
.verify-card .msg.error { background: rgba(239,68,68,.15); color: #ef4444; border: 1px solid rgba(239,68,68,.3); }
.verify-card .msg.info { background: rgba(77,142,255,.1); color: #4d8eff; border: 1px solid rgba(77,142,255,.3); }
.verify-card input[type="text"],
.verify-card input[type="email"] { width: 100%; padding: 12px; background: #0d1117; border: 1px solid #30363d; border-radius: 8px; color: #c9d1d9; font-size: 18px; text-align: center; letter-spacing: 8px; box-sizing: border-box; margin-bottom: 12px; }
.verify-card input[type="email"] { letter-spacing: 0; font-size: 14px; }
.verify-card .btn { display: block; width: 100%; padding: 12px; border: none; border-radius: 8px; font-size: 15px; cursor: pointer; margin-bottom: 8px; box-sizing: border-box; }
.verify-card .btn-primary { background: #4d8eff; color: #fff; }
.verify-card .btn-primary:hover { background: #3a7be0; }
.verify-card .btn-danger { background: rgba(239,68,68,.15); color: #ef4444; border: 1px solid rgba(239,68,68,.3); }
.verify-card .btn-danger:hover { background: rgba(239,68,68,.25); }
.verify-card .btn-secondary { background: #21262d; color: #8b949e; }
.verify-card .btn-secondary:hover { background: #30363d; }
.verify-card .divider { text-align: center; color: #484f58; margin: 16px 0; font-size: 13px; }
</style>
</head>
<body>
<div class="verify-card">
    <h1>METROPOLITEN</h1>
    <div class="subtitle">Подтверждение Email</div>

    <?php if ($error): ?>
        <div class="msg error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($result): ?>
        <div class="msg info"><?= $result ?></div>
    <?php endif; ?>

    <?php if ($deleted): ?>
        <a href="join.php" class="btn btn-primary" style="text-align:center; text-decoration:none; display:block; margin-top:16px;">Зарегистрироваться заново</a>
    <?php elseif (!$success): ?>

        <!-- Code entry form -->
        <form method="POST" action="verify_email.php">
            <input type="hidden" name="dealer" value="<?= (int)($dealerId ?? ($_GET['dealer'] ?? ($_POST['dealer'] ?? 0))) ?>">
            <input type="text" name="code" placeholder="000000" maxlength="6" pattern="[0-9]{6}" required autofocus>
            <button type="submit" class="btn btn-primary">Подтвердить код</button>
        </form>

        <?php if ($showResendForm || isset($_GET['dealer'])): ?>
        <div class="divider">— или укажите другой email —</div>
        <form method="POST" action="verify_email.php">
            <input type="hidden" name="action" value="resend">
            <input type="hidden" name="dealer" value="<?= (int)($dealerId ?? ($_GET['dealer'] ?? ($_POST['dealer'] ?? 0))) ?>">
            <input type="email" name="email" placeholder="Новый email" required>
            <button type="submit" class="btn btn-secondary">Отправить код на новый email</button>
        </form>
        <?php endif; ?>

        <div class="divider">—</div>
        <form method="POST" action="verify_email.php">
            <input type="hidden" name="action" value="cancel">
            <input type="hidden" name="dealer" value="<?= (int)($dealerId ?? ($_GET['dealer'] ?? ($_POST['dealer'] ?? 0))) ?>">
            <button type="submit" class="btn btn-danger" onclick="return confirm('Аккаунт будет удалён. Продолжить?')">Отменить регистрацию</button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
