<?php

require_once(__DIR__ . '/env_loader.php');
$pdo = new PDO(
    'mysql:host=' . (getenv('DB_HOST') ?: 'localhost') . ';dbname=' . (getenv('DB_NAME') ?: 'mpol') . ';charset=utf8mb4',
    getenv('DB_USER') ?: 'root',
    getenv('DB_PASS') ?: '',
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]
);

$stmt = $pdo->query(
    "SELECT id FROM accounts
     WHERE iptvusr IS NOT NULL
       AND iptvusr <> ''
       AND (token IS NULL OR token = '')"
);


while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "doing update";
    try {
        $token = assignTokenToAccount($pdo, (int)$row['id']);
        echo "Account {$row['id']} -> $token\n";
    } catch (RuntimeException $e) {
        echo $e;
    }
}


/**
 * Генерирует и устанавливает уникальный token
 * для одной записи accounts с заполненным iptvusr
 */
function assignTokenToAccount(PDO $pdo, int $accountId): string
{
    while (true) {
        $token = generateUniqueToken($pdo);

        try {
            $stmt = $pdo->prepare(
                'UPDATE accounts
                 SET token = :token
                 WHERE id = :id
                   AND iptvusr IS NOT NULL
                   AND iptvusr <> \'\'
                   AND (token IS NULL OR token = \'\')'
            );

            $stmt->execute([
                'token' => $token,
                'id'    => $accountId
            ]);

            if ($stmt->rowCount() === 1) {
                return $token;
            }

            throw new RuntimeException('Account does not require token');

        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
        }
    }
}


/**
 * Генерирует и сохраняет уникальный токен
 * Возвращает гарантированно уникальное значение
 */
function generateUniqueToken(PDO $pdo, int $length = 12): string
{
    $alphabet = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    $alphabetLength = strlen($alphabet);

    while (true) {
        // 1. Генерация токена
        $token = '';
        for ($i = 0; $i < $length; $i++) {
            $token .= $alphabet[random_int(0, $alphabetLength - 1)];
        }

        // 2. Проверка уникальности (без INSERT)
        $stmt = $pdo->prepare(
            'SELECT 1 FROM accounts WHERE token = :token LIMIT 1'
        );
        $stmt->execute(['token' => $token]);

        if (!$stmt->fetchColumn()) {
            // токен уникален
            return $token;
        }

        // иначе — коллизия, генерируем заново
    }
}

?>