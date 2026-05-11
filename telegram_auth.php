<?php
/**
 * Shared Telegram Login Widget verification.
 * Used by login.php and lgn.php.
 */
function verifyTelegramAuth(array $data, string $botToken): bool
{
    $check_hash = $data['hash'];
    unset($data['hash']);

    ksort($data);

    $data_check_arr = [];
    foreach ($data as $key => $value) {
        $data_check_arr[] = $key . '=' . $value;
    }

    $data_check_string = implode("\n", $data_check_arr);
    $secret_key = hash('sha256', $botToken, true);
    $hash = hash_hmac('sha256', $data_check_string, $secret_key);

    if (!hash_equals($hash, $check_hash)) {
        throw new Exception('Data is NOT from Telegram');
    }

    if ((time() - $data['auth_date']) > 86400) {
        throw new Exception('Data is outdated');
    }

    return true;
}
