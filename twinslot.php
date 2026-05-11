<?php
function getBestAccountId($monthsInput) {
    // Текущая дата в UNIX timestamp
    $currentDate = time();
    
    // Подключение к базе данных
    require_once(__DIR__ . '/env_loader.php');
    $pdo = new PDO(
        "mysql:host=" . (getenv('DB_HOST') ?: 'localhost') . ";dbname=" . (getenv('DB_NAME') ?: 'mpol'),
        getenv('DB_USER') ?: 'root',
        getenv('DB_PASS') ?: ''
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // SQL запрос с добавлением accounts.user
    $query = "
        SELECT
            accounts.id,
            accounts.user,
            accounts.iptvactdate,
            accounts.iptvmonths
        FROM
            agent_dates
        INNER JOIN
            accounts
        ON
            agent_dates.account_id = accounts.id
        WHERE
            (accounts.twin = 0 OR accounts.twin IS NULL)
            AND agent_dates.date BETWEEN NOW() - INTERVAL 15 DAY AND NOW()
        ORDER BY
            accounts.iptvactdate DESC
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $candidates = [];
    $processedIds = [];
    
    // Собираем всех кандидатов, исключая дубликаты accounts.id
    foreach ($results as $row) {
        $accountId = $row['id'];
        
        // Пропускаем, если этот ID уже обработан
        if (in_array($accountId, $processedIds)) {
            continue;
        }
        
        // Парсим iptvmonths
        $iptvMonthsParts = explode(':', $row['iptvmonths']);
        $accountMonths = (int)$iptvMonthsParts[0];
        
        // Вычисляем дату окончания подписки
        $activationDate = (int)$row['iptvactdate'];
        $endDate = strtotime("+$accountMonths months", $activationDate);
        
        // Вычисляем разницу с текущей датой
        $dateDiff = abs($currentDate - $endDate);
        
        $candidates[] = [
            'id' => $accountId,
            'user' => $row['user'],
            'iptvactdate' => $activationDate,
            'months' => $accountMonths,
            'endDate' => $endDate,
            'dateDiff' => $dateDiff
        ];
        
        $processedIds[] = $accountId;
    }
    
    if (empty($candidates)) {
        return null;
    }
    
    // Находим лучший вариант
    $bestCandidate = null;
    $bestDateDiff = PHP_INT_MAX;
    
    foreach ($candidates as $candidate) {
        $isBetterCandidate = false;
        
        if ($bestCandidate === null) {
            $isBetterCandidate = true;
        } else {
            // Приоритет - точное совпадение с сегодняшней датой
            $isCurrentBestToday = ($bestCandidate['endDate'] == $currentDate);
            $isNewToday = ($candidate['endDate'] == $currentDate);
            
            if ($isNewToday && !$isCurrentBestToday) {
                $isBetterCandidate = true;
            } elseif ($isNewToday === $isCurrentBestToday) {
                // Если обе даты равно близки к сегодня, сравниваем месяцы и разницу дат
                if ($candidate['months'] >= $monthsInput && $bestCandidate['months'] < $monthsInput) {
                    $isBetterCandidate = true;
                } elseif ($candidate['months'] >= $bestCandidate['months'] && 
                         $candidate['dateDiff'] < $bestDateDiff) {
                    $isBetterCandidate = true;
                }
            }
        }
        
        // Обновляем лучший вариант, если нашли более подходящий
        if ($isBetterCandidate && 
            ($candidate['months'] >= $monthsInput || 
             ($bestCandidate === null || $candidate['months'] > $bestCandidate['months']))) {
            $bestCandidate = $candidate;
            $bestDateDiff = $candidate['dateDiff'];
        }
    }
    
    // Возвращаем массив с id и user, если кандидат найден
    return $bestCandidate ? [
        'id' => $bestCandidate['id'],
        'user' => $bestCandidate['user']
    ] : null;
}

// Пример использования
$requiredMonths = 2;
$result = getBestAccountId($requiredMonths);

if ($result) {
    echo "Best account ID: " . $result['id'] . ", Username: " . $result['user'];
} else {
    echo "Not found";
}
?>