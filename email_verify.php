<?php
/**
 * Email verification helpers.
 *
 * Flow:
 *  1. After registration, call sendVerificationEmail($dealerId, $email).
 *  2. User receives a 6-digit code + clickable link.
 *  3. verify_email.php validates the code/token.
 *  4. If email bounces / code not confirmed, user can re-enter a new email
 *     via resendVerification($dealerId, $newEmail).
 *  5. On the 2nd failed attempt the account is deleted.
 */

require_once(__DIR__ . '/env_loader.php');

/**
 * Generate a 6-digit code and a URL-safe token, store in DB,
 * send the verification email.
 *
 * @return array{code:string, token:string}
 */
function sendVerificationEmail(mysqli $link, int $dealerId, string $email, int $attempt = 1): array
{
    $code  = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
    $token = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', time() + 3600); // 1 hour

    // Invalidate previous codes for this dealer
    $stmt = $link->prepare("UPDATE email_verifications SET used = 1 WHERE dealer_id = ? AND used = 0");
    $stmt->bind_param('i', $dealerId);
    $stmt->execute();
    $stmt->close();

    // Insert new verification record
    $stmt = $link->prepare(
        "INSERT INTO email_verifications (dealer_id, email, code, token, attempt, expires_at)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param('isssis', $dealerId, $email, $code, $token, $attempt, $expiresAt);
    $stmt->execute();
    $stmt->close();

    // Build verification link
    $baseUrl = getenv('APP_URL') ?: ('https://' . ($_SERVER['HTTP_HOST'] ?? 'mpol.co'));
    $verifyLink = $baseUrl . '/verify_email.php?token=' . urlencode($token);

    $subject = "Подтверждение email — Metropoliten";
    $body  = "Здравствуйте!<br><br>";
    $body .= "Ваш код подтверждения: <b style='font-size:20px'>$code</b><br><br>";
    $body .= "Или перейдите по ссылке для автоматического подтверждения:<br>";
    $body .= "<a href='$verifyLink'>$verifyLink</a><br><br>";
    $body .= "Код действителен в течение 1 часа.<br><br>";
    if ($attempt >= 2) {
        $body .= "<b style='color:red'>Внимание!</b> Это последняя попытка. Если email не будет подтверждён, аккаунт будет удалён.<br><br>";
    }
    $body .= "С уважением, администрация Metropoliten";

    send_mime_mail(
        "POSTBOT",
        "noreply@mpol.co",
        "",
        $email,
        "UTF-8",
        "UTF-8",
        $subject,
        $body,
        'html'
    );

    return ['code' => $code, 'token' => $token];
}

/**
 * Verify a code or token. Returns the verification row on success, false on failure.
 *
 * @return array|false
 */
function verifyEmailCode(mysqli $link, string $codeOrToken): array|false
{
    // Try by token first (link click)
    $stmt = $link->prepare(
        "SELECT id, dealer_id, email, code, token, attempt
         FROM email_verifications
         WHERE token = ? AND used = 0 AND expires_at > NOW()
         LIMIT 1"
    );
    $stmt->bind_param('s', $codeOrToken);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $stmt->close();
        // Try by code (manual entry) — get the latest unused
        $stmt = $link->prepare(
            "SELECT id, dealer_id, email, code, token, attempt
             FROM email_verifications
             WHERE code = ? AND used = 0 AND expires_at > NOW()
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->bind_param('s', $codeOrToken);
        $stmt->execute();
        $result = $stmt->get_result();
    }

    if ($result->num_rows === 0) {
        $stmt->close();
        return false;
    }

    $row = $result->fetch_assoc();
    $stmt->close();

    // Mark as used
    $stmt = $link->prepare("UPDATE email_verifications SET used = 1 WHERE id = ?");
    $stmt->bind_param('i', $row['id']);
    $stmt->execute();
    $stmt->close();

    // Mark dealer as verified
    $stmt = $link->prepare("UPDATE dealers SET email_verified = 1 WHERE id = ?");
    $stmt->bind_param('i', $row['dealer_id']);
    $stmt->execute();
    $stmt->close();

    return $row;
}

/**
 * Get the current verification attempt number for a dealer.
 */
function getVerificationAttempt(mysqli $link, int $dealerId): int
{
    $stmt = $link->prepare(
        "SELECT MAX(attempt) as max_attempt FROM email_verifications WHERE dealer_id = ?"
    );
    $stmt->bind_param('i', $dealerId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return (int)($row['max_attempt'] ?? 0);
}

/**
 * Delete a dealer account and all related verification records.
 * Called when the 2nd email verification attempt fails.
 */
function deleteUnverifiedDealer(mysqli $link, int $dealerId): void
{
    $stmt = $link->prepare("DELETE FROM email_verifications WHERE dealer_id = ?");
    $stmt->bind_param('i', $dealerId);
    $stmt->execute();
    $stmt->close();

    $stmt = $link->prepare("DELETE FROM dealers WHERE id = ? AND email_verified = 0");
    $stmt->bind_param('i', $dealerId);
    $stmt->execute();
    $stmt->close();
}
