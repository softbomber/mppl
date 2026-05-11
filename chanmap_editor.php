<?php
/**
 * Chanmap Editor — Редактирование chanmap.json
 * По умолчанию работает с локальным файлом /etc/chanmap.json
 */

include_once("config.php");
checkLoggedIn("yes");
if($_SESSION['a'] != 1) exit();


// ============================================================================
// КОНФИГУРАЦИЯ
// ============================================================================

$config = [
    // Локальный файл (основной вариант)
    'file' => '/etc/chanmap.json',
    
    // Резервные копии
    'backup_dir' => '/etc/chanmap_backups/',
    'backup_count' => 10,  // Хранить последние 10 копий
    
    // Кэширование
    'cache_ttl' => 60,  // Секунд
    'cache_file' => '/tmp/chanmap_cache.json',
];

// ============================================================================
// ПРОВЕРКА ПРАВ ДОСТУПА
// ============================================================================

if (!is_writable($config['file']) && !is_writable(dirname($config['file']))) {
    die('<h1 style="color:#EF4444;background:#1A1A2E;padding:20px;border-radius:8px;">⚠️ Ошибка прав доступа!</h1>' .
        '<p style="color:#A1A1AA;background:#18181B;padding:15px;border-radius:8px;margin:10px 0;">Файл ' . htmlspecialchars($config['file']) . ' недоступен для записи.</p>' .
        '<pre style="background:#18181B;padding:15px;border-radius:8px;color:#22C55E;">chmod 664 ' . htmlspecialchars($config['file']) . "\n" . 'chown www-data:www-data ' . htmlspecialchars($config['file']) . '</pre>');
}

// Создание директории для резервных копий
if (!is_dir($config['backup_dir'])) {
    @mkdir($config['backup_dir'], 0755, true);
}

// ============================================================================
// ФУНКЦИИ ДЛЯ РАБОТЫ С CHANMAP
// ============================================================================

/**
 * Получение chanmap из локального файла
 */
function getChanmap($config) {
    $cache_file = $config['cache_file'];
    $cache_ttl = $config['cache_ttl'];
    
    // Проверяем кэш
    if (file_exists($cache_file) && (time() - filemtime($cache_file)) < $cache_ttl) {
        $cached = json_decode(file_get_contents($cache_file), true);
        if ($cached) return $cached;
    }
    
    // Читаем из файла
    $json = @file_get_contents($config['file']);
    $data = json_decode($json, true);
    
    // Сохраняем в кэш
    if ($data) {
        file_put_contents($cache_file, $json);
    }
    
    return $data ?: [];
}

/**
 * Сохранение chanmap с резервной копией
 */
function saveChanmap($config, $data) {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_FORCE_OBJECT);

    // Резервная копия
    $backup_file = $config['backup_dir'] . 'chanmap.bak.' . time();
    @copy($config['file'], $backup_file);

    // Очистка старых резервных копий
    $backup_files = glob($config['backup_dir'] . 'chanmap.bak.*');
    if (count($backup_files) > $config['backup_count']) {
        sort($backup_files);
        for ($i = 0; $i < count($backup_files) - $config['backup_count']; $i++) {
            @unlink($backup_files[$i]);
        }
    }

    // Запись нового файла
    file_put_contents($config['file'], $json);

    // Очистка кэша
    @unlink($config['cache_file']);
}

/**
 * Добавление/обновление канала
 */
/**
 * Добавление/обновление канала
 */
function updateChannel($config, $channel_id, $data) {
    $chanmap = getChanmap($config);
    
    $normalized = [
        'name' => $data['name'] ?? '',
    ];
    
    if (!empty($data['url'])) {
        $normalized['url'] = trim($data['url']);
    }
    if (!empty($data['quality'])) {
        $normalized['quality'] = trim($data['quality']);
    }
    if (!empty($data['agent'])) {
        $normalized['agent'] = trim($data['agent']);
    }
    // >>> НОВОЕ: Referer <<<
    if (!empty($data['referer'])) {
        $normalized['referer'] = trim($data['referer']);
    }
    if (!empty($data['allow'])) {
        $normalized['allow'] = trim($data['allow']);
    }
    if (!empty($data['disallow'])) {
        $normalized['disallow'] = trim($data['disallow']);
    }
    if (empty($data['url']) && !empty($data['id'])) {
        $normalized['id'] = trim($data['id']);
    }
    
    $chanmap[$channel_id] = $normalized;
    saveChanmap($config, $chanmap);
    
    return true;
}

/**
 * Удаление канала
 */
function deleteChannel($config, $channel_id) {
    $chanmap = getChanmap($config);
    unset($chanmap[$channel_id]);
    saveChanmap($config, $chanmap);
    return true;
}

// ============================================================================
// WEB ИНТЕРФЕЙС
// ============================================================================

// Единое подключение к базе данных
$pdo = new PDO("mysql:host=localhost;dbname=mpol;charset=utf8", "root", "uiF5bcaw8");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Обработка POST запросов
$message = '';
$message_type = '';

// Обработка AJAX запросов (поиск каналов)
if (isset($_GET['action']) && $_GET['action'] === 'search_channels') {
try {
    header('Content-Type: application/json');

    $type = $_GET['type'] ?? 'id';
    $query = $_GET['query'] ?? '';

    // Получаем текущие каналы из chanmap чтобы исключить их из поиска
    $chanmap = getChanmap($config);
    $existingIds = array_keys($chanmap);

    $results = [];

    if ($type === 'id') {
        // Поиск по ID (после 2 цифр)
        if (strlen($query) >= 2 && ctype_digit($query)) {
            // Исключаем уже существующие ID
            $placeholders = implode(',', array_fill(0, count($existingIds), '?'));
            $sql = "SELECT id, name FROM channels WHERE id LIKE ? AND id NOT IN ($placeholders) LIMIT 10";
            $params = array_merge([$query . '%'], $existingIds);

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } else if ($type === 'name') {
        // Поиск по названию (после 3 символов)
        if (strlen($query) >= 3) {
            // Исключаем уже существующие названия
            $existingNames = array_column($chanmap, 'name');
            $placeholders = implode(',', array_fill(0, count($existingNames), '?'));
                $sql = "SELECT id, name FROM channels WHERE name LIKE ? AND name NOT IN ($placeholders) LIMIT 10";
                $params = array_merge(['%' . $query . '%'], $existingNames);
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }
        
        echo json_encode(['results' => $results]);
    } catch (Exception $e) {
        echo json_encode(['results' => [], 'error' => $e->getMessage()]);
    }
    exit;
}

// Обработка AJAX запросов (получение списка User-Agent)
if (isset($_GET['action']) && $_GET['action'] === 'get_agents') {
    header('Content-Type: application/json');

    // Получаем уникальные User-Agent из таблицы agents
    $stmt = $pdo->query("SELECT DISTINCT agent FROM agents WHERE agent IS NOT NULL AND agent != '' ORDER BY agent");
    $agents = $stmt->fetchAll(PDO::FETCH_COLUMN);

    echo json_encode(['agents' => $agents]);
    exit;
}

// Обработка сохранения накопленных изменений
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_changes') {
    try {
        if (!empty($_POST['changes'])) {
            $changes = json_decode($_POST['changes'], true);
            if (!is_array($changes)) {
                throw new Exception('Неверный формат изменений');
            }
            
            $chanmap = getChanmap($config);
            $count = 0;
            
            foreach ($changes as $change) {
                if (!isset($change['id'])) continue;
                
                $channel_id = $change['id'];
                
                if ($change['action'] === 'delete') {
                    // Удаление
                    if (isset($chanmap[$channel_id])) {
                        unset($chanmap[$channel_id]);
                        $count++;
                    }
                } else {
                    // Добавление/обновление
                    $normalized = ['name' => $change['name'] ?? ''];
                    
                    if (!empty($change['url'])) {
                        $normalized['url'] = trim($change['url']);
                    }
                    if (!empty($change['quality'])) {
                        $normalized['quality'] = trim($change['quality']);
                    }
                    if (!empty($change['agent'])) {
                        $normalized['agent'] = trim($change['agent']);
                    }
                    if (!empty($change['allow'])) {
                        $normalized['allow'] = trim($change['allow']);
                    }
                    if (!empty($change['disallow'])) {
                        $normalized['disallow'] = trim($change['disallow']);
                    }
                    if (empty($change['url']) && !empty($change['tvclub_id'])) {
                        $normalized['id'] = trim($change['tvclub_id']);
                    }
		    if (!empty($change['referer'])) {
		        $normalized['referer'] = trim($change['referer']);
		    }
                    
                    $chanmap[$channel_id] = $normalized;
                    $count++;
                }
            }
            
            saveChanmap($config, $chanmap);
            $message = "Сохранено изменений: $count";
            $message_type = 'success';
        }
    } catch (Exception $e) {
        $message = 'Ошибка: ' . $e->getMessage();
        $message_type = 'error';
    }
}

// Обработка одиночных операций (для совместимости)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && !in_array($_POST['action'], ['save_changes'])) {
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'add':
                case 'edit':
                    if (empty($_POST['id']) && empty($_POST['url'])) {
                        throw new Exception('Укажите URL или ID для канала');
                    }
                    updateChannel($config, $_POST['channel_id'], $_POST);
                    $message = 'Канал ' . ($_POST['action'] === 'add' ? 'добавлен' : 'обновлён');
                    $message_type = 'success';
                    break;
                    
                case 'delete':
                    deleteChannel($config, $_POST['channel_id']);
                    $message = 'Канал удалён';
                    $message_type = 'success';
                    break;
            }
        }
    } catch (Exception $e) {
        $message = 'Ошибка: ' . $e->getMessage();
        $message_type = 'error';
    }
}

// Получение данных
$chanmap = getChanmap($config);
$editing = null;

if (isset($_GET['edit'])) {
    $editing = $chanmap[$_GET['edit']] ?? null;
}

// ============================================================================
// СОРТИРОВКА И ФИЛЬТРАЦИЯ
// ============================================================================

// Сортировка
$sort_by = $_GET['sort'] ?? 'id';
$sort_order = $_GET['order'] ?? 'asc';

// Индивидуальные фильтры по столбцам
$filters = [
    'id' => $_GET['filter_id'] ?? '',
    'name' => $_GET['filter_name'] ?? '',
    'url' => $_GET['filter_url'] ?? '',
    'allow' => $_GET['filter_allow'] ?? '',
    'type' => $_GET['filter_type'] ?? '',
];

// Преобразование в массив для сортировки
$chanmap_array = [];
foreach ($chanmap as $id => $channel) {
    // Преобразуем в объект если это массив (чтобы пустые каналы были {})
    $channel_obj = is_array($channel) ? (object) $channel : $channel;
    $chanmap_array[] = array_merge(['_id' => $id], (array) $channel_obj);
}

// Применение индивидуальных фильтров
$chanmap_array = array_filter($chanmap_array, function($c) use ($filters) {
    // Фильтр по ID
    if (!empty($filters['id']) && stripos($c['_id'], $filters['id']) === false) {
        return false;
    }
    
    // Фильтр по Названию
    if (!empty($filters['name']) && stripos($c['name'] ?? '', $filters['name']) === false) {
        return false;
    }
    
    // Фильтр по Типу
    if (!empty($filters['type'])) {
        $actual_type = !empty($c['url']) ? 'url' : (!empty($c['id']) ? 'id' : 'empty');
        if ($actual_type !== $filters['type']) {
            return false;
        }
    }
    
    // Фильтр по URL/ID
    if (!empty($filters['url'])) {
        $url_or_id = $c['url'] ?? ($c['id'] ?? '');
        if (stripos($url_or_id, $filters['url']) === false) {
            return false;
        }
    }
    
    // Фильтр по Allow
    if (!empty($filters['allow']) && stripos($c['allow'] ?? '', $filters['allow']) === false) {
        return false;
    }
    
    return true;
});

// Применение сортировки
usort($chanmap_array, function($a, $b) use ($sort_by, $sort_order) {
    $field_map = [
        'id' => '_id',
        'name' => 'name',
        'url' => 'url',
        'allow' => 'allow',
        'type' => 'url'  // Сортировка по типу
    ];
    
    $field = $field_map[$sort_by] ?? '_id';
    $val_a = $a[$field] ?? '';
    $val_b = $b[$field] ?? '';
    
    // Для типа: url > id > empty
    if ($sort_by === 'type') {
        $type_a = !empty($a['url']) ? 2 : (!empty($a['id']) ? 1 : 0);
        $type_b = !empty($b['url']) ? 2 : (!empty($b['id']) ? 1 : 0);
        $val_a = $type_a;
        $val_b = $type_b;
    }
    
    // Числовая сортировка для ID
    if ($sort_by === 'id') {
        $val_a = intval($val_a);
        $val_b = intval($val_b);
    }
    
    $cmp = is_numeric($val_a) && is_numeric($val_b) 
        ? $val_a - $val_b 
        : strcasecmp($val_a, $val_b);
    
    return $sort_order === 'desc' ? -$cmp : $cmp;
});

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chanmap Editor</title>
    <style>
/* ============================================================================
   Дизайн-система: тёмная тема (как в uman.php)
   ========================================================================== */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html, body { width: 100%; min-height: 100vh; }
:root {
    --bg:           #0a0e16;
    --bg-elevated:  #0f1520;
    --surface:      #131a26;
    --surface-2:    #1a2333;
    --surface-hi:   #22304a;
    --border:       #243043;
    --border-hi:    #324562;
    --text:         #e6edf3;
    --text-dim:     #8b96a8;
    --text-faint:   #5b6678;
    --primary:      #4d8eff;
    --primary-hov:  #6ba1ff;
    --primary-bg:   rgba(77, 142, 255, 0.12);
    --success:      #3ecf8e;
    --success-bg:   rgba(62, 207, 142, 0.12);
    --warning:      #f5a623;
    --warning-bg:   rgba(245, 166, 35, 0.12);
    --danger:       #ef4444;
    --danger-bg:    rgba(239, 68, 68, 0.12);
    --radius:       10px;
    --radius-lg:    14px;
    --radius-pill:  999px;
    --shadow-1:     0 1px 2px rgba(0,0,0,.3), 0 4px 12px rgba(0,0,0,.2);
    --shadow-2:     0 10px 30px rgba(0,0,0,.45);
    --transition:   150ms ease;
    --font:         -apple-system, BlinkMacSystemFont, "Segoe UI", "Inter", Roboto, "Helvetica Neue", Arial, sans-serif;
    --mono:         "SF Mono", "JetBrains Mono", Menlo, Consolas, monospace;
}
body {
    font-family: var(--font);
    background: var(--bg);
    color: var(--text);
    font-size: 14px;
    line-height: 1.5;
    overflow-x: hidden;
    -webkit-font-smoothing: antialiased;
    padding: 20px;
}
.container { max-width: 1600px; margin: 0 auto; }

/* ---- Topbar (хедер) ---- */
.topbar {
    position: sticky; top: 0; z-index: 100;
    background: rgba(10, 14, 22, 0.85);
    backdrop-filter: saturate(180%) blur(12px);
    border: 1px solid var(--border);
    padding: 12px 20px; display: flex; flex-wrap: wrap; gap: 12px;
    align-items: center; justify-content: space-between;
    margin-bottom: 16px; border-radius: var(--radius-lg);
}
.brand {
    display: flex; align-items: center; gap: 10px;
    font-weight: 600; color: var(--text); font-size: 18px;
}
.brand-glyph { color: var(--primary); font-size: 22px; }

/* ---- Info grid (stat cards) ---- */
.info-grid {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 10px; margin-bottom: 16px;
}
.info-item {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--radius); padding: 12px 14px;
}
.info-item .lbl {
    display: block; font-size: 11px; text-transform: uppercase;
    letter-spacing: 0.05em; color: var(--text-dim); margin-bottom: 4px;
}
.info-item .val { font-size: 20px; font-weight: 600; color: var(--text); }
.info-item .val.mono { font-family: var(--mono); font-size: 16px; }
.info-item .val.success { color: var(--success); }
.info-item .val.info { color: var(--primary); }
.info-item .val.muted { color: var(--text-dim); }

/* ---- View actions ---- */
.view-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }

/* ---- Card (для таблицы) ---- */
.card {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--radius-lg); box-shadow: var(--shadow-1); overflow: hidden;
}
.table-wrap {
    overflow-x: auto; -webkit-overflow-scrolling: touch;
    max-height: calc(100vh - 260px); overflow-y: auto;
}
.table-wrap::-webkit-scrollbar { width: 8px; height: 8px; }
.table-wrap::-webkit-scrollbar-track { background: var(--bg-elevated); }
.table-wrap::-webkit-scrollbar-thumb { background: var(--border-hi); border-radius: 4px; }

/* ---- Tables ---- */
table.data { width: 100%; border-collapse: collapse; font-size: 13px; }
table.data th, table.data td {
    padding: 10px 14px; text-align: left;
    border-bottom: 1px solid var(--border); vertical-align: top;
}
table.data thead th {
    background: var(--bg-elevated); color: var(--text-dim);
    font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em;
    font-weight: 600; user-select: none; position: sticky; top: 0; z-index: 1;
}
table.data tbody tr { transition: background var(--transition); }
table.data tbody tr:hover { background: var(--bg-elevated); }
table.data td.num { font-family: var(--mono); font-weight: 500; }
.col-id { width: 90px; }
.col-name { width: 200px; }
.col-name .name-cell { color: var(--text); word-wrap: break-word; }
.col-type { width: 120px; }
.col-url { min-width: 320px; }
.col-url small { word-break: break-all; display: block; color: var(--text-dim); font-family: var(--mono); font-size: 12px; }
.col-allow { width: 130px; }
.col-actions { width: 100px; text-align: right; }

/* ---- Badges ---- */
.badge {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 2px 8px; border-radius: var(--radius-pill);
    font-size: 11px; font-weight: 600; line-height: 1.6;
}
.badge--info { background: var(--primary-bg); color: var(--primary-hov); }
.badge--success { background: var(--success-bg); color: var(--success); }
.badge--warning { background: var(--warning-bg); color: var(--warning); }
.badge--danger { background: var(--danger-bg); color: var(--danger); }
.badge--muted { background: var(--surface-2); color: var(--text-dim); }

/* ---- Sort link & filter input in th ---- */
.sort-link {
    color: var(--text-dim); text-decoration: none; cursor: pointer;
    font-weight: 600; transition: var(--transition);
    display: inline-flex; align-items: center; gap: 4px;
}
.sort-link:hover { color: var(--text); }
.sort-link.active { color: var(--primary); }
.sort-arrow { font-size: 10px; }
.filter-input {
    width: 100%; margin-top: 6px;
    padding: 4px 8px; border: 1px solid var(--border);
    border-radius: 6px; font-size: 11px;
    background: var(--bg-elevated); color: var(--text);
    font-family: var(--mono);
}
.filter-input:focus {
    outline: none; border-color: var(--primary);
    box-shadow: 0 0 0 2px var(--primary-bg);
}

/* ---- Buttons ---- */
.btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 14px; border: 1px solid var(--border);
    border-radius: var(--radius); background: var(--surface);
    color: var(--text); font-size: 13px; font-weight: 500;
    cursor: pointer; transition: var(--transition); font-family: inherit;
}
.btn:hover { background: var(--surface-2); border-color: var(--border-hi); }
.btn:active { transform: translateY(1px); }
.btn:disabled { opacity: 0.5; cursor: not-allowed; }
.btn--primary { background: var(--primary); border-color: var(--primary); color: #fff; }
.btn--primary:hover { background: var(--primary-hov); border-color: var(--primary-hov); }
.btn--success { background: var(--success); border-color: var(--success); color: #07140d; font-weight: 600; }
.btn--success:hover { filter: brightness(1.1); }
.btn--danger { background: var(--danger); border-color: var(--danger); color: #fff; }
.btn--danger:hover { filter: brightness(1.1); }
.btn--ghost { background: transparent; }
.btn--sm { padding: 5px 9px; font-size: 12px; }

/* ---- Message ---- */
.message {
    padding: 12px 16px; border-radius: var(--radius);
    margin-bottom: 16px; font-size: 13px;
    border: 1px solid transparent;
}
.message.success {
    background: var(--success-bg); color: var(--success);
    border-color: var(--success);
}
.message.error {
    background: var(--danger-bg); color: var(--danger);
    border-color: var(--danger);
}

/* ---- Row states ---- */
tr.editing {
    background: var(--primary-bg) !important;
    box-shadow: inset 3px 0 0 var(--primary);
}
tr.pending-change {
    background: var(--warning-bg) !important;
    box-shadow: inset 3px 0 0 var(--warning);
}
tr.pending-change[data-pending="delete"] {
    background: var(--danger-bg) !important;
    box-shadow: inset 3px 0 0 var(--danger);
    opacity: 0.7;
}

/* ---- Pending count ---- */
#pending-count {
    padding: 6px 12px; background: var(--warning-bg);
    color: var(--warning); border-radius: var(--radius-pill);
    font-size: 12px; font-weight: 600;
}

/* ---- Modal ---- */
.modal-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(5, 9, 16, 0.7);
    backdrop-filter: blur(4px); z-index: 9999;
    align-items: center; justify-content: center; padding: 16px;
}
.modal-overlay.active { display: flex; }
.modal-content {
    background: var(--surface); border: 1px solid var(--border-hi);
    border-radius: var(--radius-lg); box-shadow: var(--shadow-2);
    width: 100%; max-width: 700px; max-height: 90vh; overflow-y: auto;
}
.modal-header {
    padding: 16px 20px; border-bottom: 1px solid var(--border);
    display: flex; align-items: center; justify-content: space-between;
}
.modal-header h2 { margin: 0; color: var(--text); font-size: 18px; font-weight: 600; }
.modal-body { padding: 20px; }
.modal-footer {
    padding: 12px 20px; border-top: 1px solid var(--border);
    display: flex; justify-content: flex-end; gap: 8px;
    background: var(--bg-elevated);
}
.close-btn {
    font-size: 22px; color: var(--text-dim);
    cursor: pointer; border: none; background: none;
    transition: color 0.2s;
}
.close-btn:hover { color: var(--text); }

/* ---- Form ---- */
.form-row {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px; margin-bottom: 16px;
}
.form-group { display: flex; flex-direction: column; position: relative; }
.form-group label {
    margin-bottom: 6px; font-weight: 500;
    color: var(--text); font-size: 13px;
}
.form-group input,
.form-group select,
.form-group textarea,
.form-group input[list] {
    padding: 10px 12px; border: 1px solid var(--border);
    border-radius: var(--radius); font-size: 13px;
    background: var(--bg-elevated); color: var(--text);
    font-family: inherit;
}
.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
    outline: none; border-color: var(--primary);
    box-shadow: 0 0 0 2px var(--primary-bg);
}
.form-group small {
    margin-top: 4px; color: var(--text-dim); font-size: 11px;
}

/* ---- Autocomplete ---- */
.autocomplete-container { position: relative; }
.autocomplete-results {
    position: absolute; top: 100%; left: 0; right: 0;
    background: var(--surface-2); border: 1px solid var(--border-hi);
    border-radius: var(--radius); max-height: 200px; overflow-y: auto;
    z-index: 1000; display: none; margin-top: 4px;
    box-shadow: var(--shadow-2);
}
.autocomplete-results.show { display: block; }
.autocomplete-item {
    padding: 8px 12px; cursor: pointer;
    border-bottom: 1px solid var(--border);
    color: var(--text); font-size: 12px;
}
.autocomplete-item:hover { background: var(--primary-bg); }
.autocomplete-item:last-child { border-bottom: none; }
.autocomplete-item strong { color: var(--primary); font-family: var(--mono); }
.autocomplete-item span { color: var(--text-dim); margin-left: 8px; }
.autocomplete-loading { padding: 10px; text-align: center; color: var(--text-dim); font-size: 12px; }

/* ---- Form URL textarea ---- */
#form-url {
    min-height: 40px; height: auto; resize: vertical;
    overflow-y: hidden; font-family: inherit; line-height: 1.4;
    transition: min-height 0.15s ease;
}
#form-url::placeholder { color: var(--text-faint); }
#form-url:focus { min-height: 60px; }

/* ---- Responsive ---- */
@media (max-width: 768px) {
    body { padding: 10px; }
    .topbar { padding: 10px 14px; }
    .brand { font-size: 15px; }
    .info-grid { grid-template-columns: repeat(2, 1fr); }
    .info-item .val { font-size: 16px; }
    table.data th, table.data td { padding: 8px 10px; font-size: 12px; }
    #form-url { min-height: 50px; }
}
    </style>
</head>
<body>
    <div class="container">
        <div class="topbar">
            <div class="brand">
                <span class="brand-glyph">📺</span>
                <span>Chanmap Editor</span>
            </div>
            <div class="view-actions">
                <span id="pending-count" style="display: none;">
                    ⚠️ <span id="pending-number">0</span> изменений
                </span>
                <button id="save-btn" class="btn btn--success" style="display: none;" onclick="saveChanges()">💾 Сохранить</button>
                <button id="discard-btn" class="btn btn--ghost" style="display: none;" onclick="discardChanges()">✖ Отмена</button>
                <?php if (array_filter($filters)): ?>
                    <button class="btn btn--ghost" onclick="clearAllFilters()" title="Сбросить фильтры">🔄 Сброс</button>
                <?php endif; ?>
                <button class="btn btn--primary" onclick="openAddModal()">+ Добавить</button>
            </div>
        </div>

        <div class="info-grid">
            <div class="info-item">
                <span class="lbl">Всего</span>
                <span class="val"><?= count($chanmap) ?></span>
            </div>
            <div class="info-item">
                <span class="lbl">С URL</span>
                <span class="val success"><?= count(array_filter($chanmap, fn($c) => !empty($c['url']))) ?></span>
            </div>
            <div class="info-item">
                <span class="lbl">TVClub</span>
                <span class="val info"><?= count(array_filter($chanmap, fn($c) => empty($c['url']) && !empty($c['id']))) ?></span>
            </div>
            <div class="info-item">
                <span class="lbl">Пустые</span>
                <span class="val muted"><?= count(array_filter($chanmap, fn($c) => empty($c['url']) && empty($c['id']))) ?></span>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="message <?= $message_type ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <!-- Таблица каналов -->
        <div class="card">
            <div class="table-wrap">
                <table class="data">
                <thead>
                    <tr>
                        <th class="col-id">
                            <a href="?<?= http_build_query(array_merge($_GET, ['sort' => 'id', 'order' => $sort_by === 'id' && $sort_order === 'asc' ? 'desc' : 'asc'])) ?>"
                               class="sort-link <?= $sort_by === 'id' ? 'active' : '' ?>">
                                ID <span class="sort-arrow"><?= $sort_by === 'id' ? ($sort_order === 'asc' ? '↑' : '↓') : '' ?></span>
                            </a>
                            <input type="text" class="filter-input" placeholder="Фильтр ID..."
                                   value="<?= htmlspecialchars($_GET['filter_id'] ?? '') ?>"
                                   onchange="applyFilter('id', this.value)">
                        </th>
                        <th class="col-name">
                            <a href="?<?= http_build_query(array_merge($_GET, ['sort' => 'name', 'order' => $sort_by === 'name' && $sort_order === 'asc' ? 'desc' : 'asc'])) ?>"
                               class="sort-link <?= $sort_by === 'name' ? 'active' : '' ?>">
                                Название <span class="sort-arrow"><?= $sort_by === 'name' ? ($sort_order === 'asc' ? '↑' : '↓') : '' ?></span>
                            </a>
                            <input type="text" class="filter-input" placeholder="Фильтр Название..."
                                   value="<?= htmlspecialchars($_GET['filter_name'] ?? '') ?>"
                                   onchange="applyFilter('name', this.value)">
                        </th>
                        <th class="col-url">
                            <a href="?<?= http_build_query(array_merge($_GET, ['sort' => 'url', 'order' => $sort_by === 'url' && $sort_order === 'asc' ? 'desc' : 'asc'])) ?>"
                               class="sort-link <?= $sort_by === 'url' ? 'active' : '' ?>">
                                URL / ID <span class="sort-arrow"><?= $sort_by === 'url' ? ($sort_order === 'asc' ? '↑' : '↓') : '' ?></span>
                            </a>
                            <input type="text" class="filter-input" placeholder="Фильтр URL/ID..."
                                   value="<?= htmlspecialchars($_GET['filter_url'] ?? '') ?>"
                                   onchange="applyFilter('url', this.value)">
                        </th>
                        <th class="col-allow">
                            <a href="?<?= http_build_query(array_merge($_GET, ['sort' => 'allow', 'order' => $sort_by === 'allow' && $sort_order === 'asc' ? 'desc' : 'asc'])) ?>"
                               class="sort-link <?= $sort_by === 'allow' ? 'active' : '' ?>">
                                Allow <span class="sort-arrow"><?= $sort_by === 'allow' ? ($sort_order === 'asc' ? '↑' : '↓') : '' ?></span>
                            </a>
                            <input type="text" class="filter-input" placeholder="Фильтр Allow..."
                                   value="<?= htmlspecialchars($_GET['filter_allow'] ?? '') ?>"
                                   onchange="applyFilter('allow', this.value)">
                        </th>
                        <th class="col-type">
                            <a href="?<?= http_build_query(array_merge($_GET, ['sort' => 'type', 'order' => $sort_by === 'type' && $sort_order === 'asc' ? 'desc' : 'asc'])) ?>"
                               class="sort-link <?= $sort_by === 'type' ? 'active' : '' ?>">
                                Тип <span class="sort-arrow"><?= $sort_by === 'type' ? ($sort_order === 'asc' ? '↑' : '↓') : '' ?></span>
                            </a>
                            <select class="filter-input" onchange="applyFilter('type', this.value)">
                                <option value="">Все типы</option>
                                <option value="url" <?= ($_GET['filter_type'] ?? '') === 'url' ? 'selected' : '' ?>>Direct URL</option>
                                <option value="id" <?= ($_GET['filter_type'] ?? '') === 'id' ? 'selected' : '' ?>>TVClub ID</option>
                                <option value="empty" <?= ($_GET['filter_type'] ?? '') === 'empty' ? 'selected' : '' ?>>Пустые</option>
                            </select>
                        </th>
                        <th class="col-actions">Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($chanmap_array as $channel):
                        $id = $channel['_id'];
                    ?>
                        <tr data-id="<?= htmlspecialchars($id) ?>">
                            <td class="col-id num"><strong><?= htmlspecialchars($id) ?></strong></td>
                            <td class="col-name"><span class="name-cell"><?= htmlspecialchars($channel['name'] ?? '-') ?></span></td>
                            <td class="col-url">
                                <?php if (!empty($channel['url'])): ?>
                                    <small><?= htmlspecialchars($channel['url']) ?></small>
                                <?php elseif (!empty($channel['id'])): ?>
                                    <small>ID: <?= htmlspecialchars($channel['id']) ?></small>
                                <?php else: ?>
                                    <small>-</small>
                                <?php endif; ?>
                            </td>
                            <td class="col-allow"><?= htmlspecialchars($channel['allow'] ?? '-') ?></td>
                            <td class="col-type">
                                <?php if (!empty($channel['url'])): ?>
                                    <span class="badge badge--info">Direct URL</span>
                                <?php elseif (!empty($channel['id'])): ?>
                                    <span class="badge badge--success">TVClub</span>
                                <?php else: ?>
                                    <span class="badge badge--muted">Пустой</span>
                                <?php endif; ?>
                            </td>
                            <td class="col-actions">
                                <button type="button" class="btn btn--sm" onclick='openEditModal("<?= htmlspecialchars($id) ?>", <?= json_encode($channel, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>✏️</button>
                                <button type="button" class="btn btn--sm btn--danger" onclick="queueDeleteChannel('<?= htmlspecialchars($id) ?>')">🗑️</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Модальное окно редактирования/добавления -->
    <div id="edit-modal" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modal-title">Редактирование канала</h2>
                <button class="close-btn" onclick="closeModal()">&times;</button>
            </div>
            <form onsubmit="handleFormSubmit(event)">
                <input type="hidden" id="form-action" value="add">
                <input type="hidden" id="form-channel-id" value="">

                <div class="modal-body">
                    <div class="form-row">
                        <div class="form-group autocomplete-container">
                            <label>ID канала *</label>
                            <input type="number" id="form-id-input" required oninput="handleIdInput(this.value)" autocomplete="off">
                            <div id="id-autocomplete" class="autocomplete-results"></div>
                            <small>Введите ID (поиск после 2 цифр) или название (поиск после 3 букв)</small>
                        </div>
                        <div class="form-group autocomplete-container">
                            <label>Название *</label>
                            <input type="text" id="form-name" required oninput="handleNameInput(this.value)" autocomplete="off">
                            <div id="name-autocomplete" class="autocomplete-results"></div>
                            <small>Введите название для поиска в базе</small>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>URL потока</label>
                            <textarea id="form-url" placeholder="http://..." rows="1"></textarea>
                            <small>Если не указан — используется ID для tvclub</small>
                        </div>
                        <div class="form-group">
                            <label>ID для tvclub</label>
                            <input type="text" id="form-tvclub-id" placeholder="8150">
                            <small>Обязательно если нет URL</small>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Quality</label>
                            <input type="text" id="form-quality" placeholder="1920x1080">
                        </div>
                        <div class="form-group">
                            <label>User-Agent</label>
                            <input list="user-agents-list" id="form-agent" placeholder="Выберите или введите свой" autocomplete="on">
                            <datalist id="user-agents-list"></datalist>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Referer</label>
                            <input type="url" id="form-referer" placeholder="https://example.com/">
                            <small>Заголовок Referer для запросов (опционально)</small>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Allow (CDN)</label>
                            <input type="text" id="form-allow" placeholder="77.110.105.57">
                        </div>
                        <div class="form-group">
                            <label>Disallow (CDN)</label>
                            <input type="text" id="form-disallow" placeholder="51.254.135.10">
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn" onclick="closeModal()">Отмена</button>
                    <button type="submit" class="btn btn--success">В очередь</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // === УПРАВЛЕНИЕ НАКОПИТЕЛЬНЫМИ ИЗМЕНЕНИЯМИ ===
        
        // Загрузка pending изменений из localStorage
        function getPendingChanges() {
            const stored = localStorage.getItem('chanmap_pending_changes');
            return stored ? JSON.parse(stored) : [];
        }
        
        // Сохранение pending изменений в localStorage
        function savePendingChanges(changes) {
            localStorage.setItem('chanmap_pending_changes', JSON.stringify(changes));
            updatePendingUI();
        }
        
        // Добавление изменения
        function addChange(change) {
            const changes = getPendingChanges();
            
            // Удаляем предыдущее изменение для этого канала если есть
            const existingIndex = changes.findIndex(c => c.id === change.id);
            if (existingIndex !== -1) {
                changes.splice(existingIndex, 1);
            }
            
            changes.push(change);
            savePendingChanges(changes);
        }
        
        // Обновление UI (кнопки и счётчик)
        function updatePendingUI() {
            const changes = getPendingChanges();
            const count = changes.length;
            
            const pendingCount = document.getElementById('pending-count');
            const pendingNumber = document.getElementById('pending-number');
            const saveBtn = document.getElementById('save-btn');
            const discardBtn = document.getElementById('discard-btn');
            
            if (count > 0) {
                pendingCount.style.display = 'inline';
                pendingNumber.textContent = count;
                saveBtn.style.display = 'inline-block';
                discardBtn.style.display = 'inline-block';
            } else {
                pendingCount.style.display = 'none';
                saveBtn.style.display = 'none';
                discardBtn.style.display = 'none';
            }
        }
        
        // Проверка и очистка после сохранения
        function checkAndClearPending() {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('saved')) {
                localStorage.removeItem('chanmap_pending_changes');
                // Очищаем URL параметр
                window.history.replaceState({}, document.title, window.location.pathname);
            }
            updatePendingUI();
        }
        
        // Сохранение изменений на сервер
        function saveChanges() {
            const changes = getPendingChanges();
            if (changes.length === 0) return;
            
            if (!confirm(`Сохранить ${changes.length} изменений?`)) return;
            
            const form = document.createElement('form');
            form.method = 'POST';
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'save_changes';
            
            const changesInput = document.createElement('input');
            changesInput.type = 'hidden';
            changesInput.name = 'changes';
            changesInput.value = JSON.stringify(changes);
            
            form.appendChild(actionInput);
            form.appendChild(changesInput);
            document.body.appendChild(form);
            form.submit();
            
            // Очищаем localStorage сразу после отправки
            localStorage.removeItem('chanmap_pending_changes');
            updatePendingUI();
        }
        
        // Отмена изменений
        function discardChanges() {
            if (!confirm('Отменить все несохранённые изменения?')) return;
            localStorage.removeItem('chanmap_pending_changes');
            updatePendingUI();
        }
        
        // === ФУНКЦИИ ДЛЯ ИЗМЕНЕНИЙ ===
        
        // Открытие модального окна для редактирования
        function openEditModal(id, channelData) {
            document.getElementById('form-action').value = 'edit';
            document.getElementById('form-channel-id').value = id;
            document.getElementById('form-id-input').value = id;
            document.getElementById('form-id-input').readOnly = true;  // Readonly при редактировании
            document.getElementById('form-name').value = channelData.name || '';
            document.getElementById('form-url').value = channelData.url || '';
            document.getElementById('form-tvclub-id').value = channelData.id || '';
            document.getElementById('form-quality').value = channelData.quality || '';

    // >>> После заполнения — подстраиваем высоту <<<
    setTimeout(() => {
        const urlField = document.getElementById('form-url');
        if (urlField && urlField.value) {
            autoResizeTextarea(urlField);
        }
    }, 10);
            // Устанавливаем User-Agent
            const agentValue = channelData.agent || '';
            document.getElementById('form-agent').value = agentValue;
            //document.getElementById('form-agent-custom').value = agentValue;
	    document.getElementById('form-referer').value = channelData.referer || '';

            document.getElementById('form-allow').value = channelData.allow || '';
            document.getElementById('form-disallow').value = channelData.disallow || '';

            document.getElementById('modal-title').textContent = 'Редактирование канала ' + id;
            document.getElementById('edit-modal').classList.add('active');
            highlightRow(id);
            // Загружаем агенты если ещё не загружены
        if (document.getElementById('user-agents-list').children.length === 0) {
                loadAgents();
            }
        }

        // Открытие модального окна для добавления
        function openAddModal() {
            document.getElementById('form-action').value = 'add';
            document.getElementById('form-channel-id').value = '';
            document.getElementById('form-id-input').value = '';
            document.getElementById('form-id-input').readOnly = false;
            document.getElementById('form-name').value = '';
            document.getElementById('form-url').value = '';
	    document.getElementById('form-url').style.height = '40px';
            document.getElementById('form-tvclub-id').value = '';
            document.getElementById('form-quality').value = '';
            document.getElementById('form-agent').value = '';
            document.getElementById('form-referer').value = '';
            document.getElementById('form-allow').value = '';
            document.getElementById('form-disallow').value = '';

            document.getElementById('modal-title').textContent = 'Добавление канала';
            document.getElementById('edit-modal').classList.add('active');

            // Загружаем агенты если ещё не загружены
            if (document.getElementById('user-agents-list').children.length === 0) {
                loadAgents();
            }
        }

        // Закрытие модального окна
        function closeModal() {
            document.getElementById('edit-modal').classList.remove('active');
        }

        // Закрытие по клику вне модального окна
        document.getElementById('edit-modal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });

        // === АВТОДОПОЛНЕНИЕ ===
        let searchTimeout = null;

        // Загрузка списка User-Agent
        function loadAgents() {
            fetch('?action=get_agents')
                .then(r => r.json())
                .then(data => {
                    if (data.agents && data.agents.length > 0) {
                        const datalist = document.getElementById('user-agents-list');
                        datalist.innerHTML = '';
                        data.agents.forEach(agent => {
                            const option = document.createElement('option');
                            option.value = agent;
                            datalist.appendChild(option);
                        });
                    }
                });
        }

        // Поиск по ID
        function handleIdInput(value) {
	    document.getElementById('form-channel-id').value = value; // синхронизируем
            clearTimeout(searchTimeout);
            const resultsDiv = document.getElementById('id-autocomplete');

            if (value.length < 2) {
                resultsDiv.classList.remove('show');
                return;
            }

            searchTimeout = setTimeout(() => {
                fetch('?action=search_channels&type=id&query=' + encodeURIComponent(value))
                    .then(r => r.json())
                    .then(data => {
                        if (data.results && data.results.length > 0) {
                            resultsDiv.innerHTML = data.results.map(item =>
                                `<div class="autocomplete-item" onclick="selectChannel('${item.id}', '${item.name.replace(/'/g, "\\'")}')">
                                    <strong>${item.id}</strong>
                                    <span>${item.name}</span>
                                </div>`
                            ).join('');
                            resultsDiv.classList.add('show');
                        } else {
                            resultsDiv.innerHTML = '<div class="autocomplete-loading">Ничего не найдено</div>';
                            resultsDiv.classList.add('show');
                        }
                    });
            }, 300);
        }

        // Поиск по названию
        function handleNameInput(value) {
            clearTimeout(searchTimeout);
            const resultsDiv = document.getElementById('name-autocomplete');

            if (value.length < 3) {
                resultsDiv.classList.remove('show');
                return;
            }

            searchTimeout = setTimeout(() => {
                fetch('?action=search_channels&type=name&query=' + encodeURIComponent(value))
                    .then(r => r.json())
                    .then(data => {
                        if (data.results && data.results.length > 0) {
                            resultsDiv.innerHTML = data.results.map(item =>
                                `<div class="autocomplete-item" onclick="selectChannel('${item.id}', '${item.name.replace(/'/g, "\\'")}')">
                                    <strong>${item.id}</strong>
                                    <span>${item.name}</span>
                                </div>`
                            ).join('');
                            resultsDiv.classList.add('show');
                        } else {
                            resultsDiv.innerHTML = '<div class="autocomplete-loading">Ничего не найдено</div>';
                            resultsDiv.classList.add('show');
                        }
                    });
            }, 300);
        }

        // Выбор канала из autocomplete
        function selectChannel(id, name) {
            document.getElementById('form-id-input').value = id;
	    document.getElementById('form-channel-id').value = id; // синхронизируем
            document.getElementById('form-name').value = name;
            document.getElementById('id-autocomplete').classList.remove('show');
            document.getElementById('name-autocomplete').classList.remove('show');
        }

        // Закрытие autocomplete при клике вне
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.autocomplete-container')) {
                document.getElementById('id-autocomplete').classList.remove('show');
                document.getElementById('name-autocomplete').classList.remove('show');
            }
        });

        // Обработка отправки формы
        function handleFormSubmit(event) {
            event.preventDefault();

            const action = document.getElementById('form-action').value;
            const id = document.getElementById('form-channel-id').value;
            const name = document.getElementById('form-name').value;
            const url = document.getElementById('form-url').value;
            const tvclubId = document.getElementById('form-tvclub-id').value;
            const quality = document.getElementById('form-quality').value;
            const agent = document.getElementById('form-agent').value;
	    const referer = document.getElementById('form-referer').value;
            const allow = document.getElementById('form-allow').value;
            const disallow = document.getElementById('form-disallow').value;

    if (url && !/^https?:\/\/.+/.test(url)) {
        alert('Неверный формат URL. Должен начинаться с http:// или https://');
        document.getElementById('form-url').focus();
        return;
    }
            
            if (!id || !name) {
                alert('ID и Название обязательны');
                return;
            }
            
            if (!url && !tvclubId) {
                alert('Укажите URL или ID для tvclub');
                return;
            }
            
            const change = {
                action: action === 'edit' ? 'edit' : 'add',
                id: id || Date.now().toString(),
                name: name,
                url: url,
                tvclub_id: tvclubId,
                quality: quality,
                agent: agent,
		referer: referer,
                allow: allow,
                disallow: disallow
            };
            
            addChange(change);
	    applyPendingChangeToTable(change);
            closeModal();
        }
        
        function queueAddChannel(data) {
            addChange({ action: 'add', ...data });
        }
        
        function queueUpdateChannel(id, data) {
            addChange({ action: 'edit', id, ...data });
        }
        
        function queueDeleteChannel(id) {
            if (!confirm('Добавить удаление канала ' + id + ' в очередь?')) return;
            addChange({ action: 'delete', id });
        }
        
        function applyFilter(column, value) {
            const url = new URL(window.location.href);
            url.searchParams.set('filter_' + column, value);
            window.location.href = url.toString();
        }
        
        function clearAllFilters() {
            const url = new URL(window.location.href);
            Object.keys(url.searchParams).forEach(key => {
                if (key.startsWith('filter_')) {
                    url.searchParams.delete(key);
                }
            });
            window.location.href = url.toString();
        }

// Подсветка строки при редактировании
function highlightRow(id) {
    // Снимаем подсветку со всех
    document.querySelectorAll('tr.editing').forEach(row => {
        row.classList.remove('editing');
    });
    
    // Подсвечиваем текущую
    const row = document.querySelector(`tbody tr[data-id="${id}"]`);
    if (row) {
        row.classList.add('editing');
        // Прокрутка к строке если не видна
        row.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
}

// Снятие подсветки при закрытии модального окна
function closeModal() {
    document.getElementById('edit-modal').classList.remove('active');
    // Снимаем подсветку через небольшую задержку (после анимации)
    setTimeout(() => {
        document.querySelectorAll('tr.editing').forEach(row => {
            row.classList.remove('editing');
        });
    }, 200);
}


function discardChanges() {
    if (!confirm('Отменить все несохранённые изменения?')) return;
    
    localStorage.removeItem('chanmap_pending_changes');
    
    // >>> Восстановление визуала таблицы <<<
    document.querySelectorAll('tr.pending-change').forEach(row => {
        row.classList.remove('pending-change');
        row.style.opacity = '';
        row.style.textDecoration = '';
        delete row.dataset.pending;
    });
    
    updatePendingUI();
    
    // Перезагружаем страницу для полного сброса (опционально)
    // window.location.reload();
}
// === LIVE PREVIEW: Обновление таблицы при накопленных изменениях ===

// Применение изменения к строке таблицы (визуально)
function applyPendingChangeToTable(change) {
    const row = document.querySelector(`tbody tr[data-id="${change.id}"]`);
    
    if (change.action === 'delete') {
        // Помечаем строку как удалённую
        if (row) {
            row.style.opacity = '0.5';
            row.style.textDecoration = 'line-through';
            row.dataset.pending = 'delete';
            row.classList.add('pending-change');
        }
        return;
    }
    
    // Для add/edit обновляем содержимое
    if (row) {
        // Обновляем ячейки
        const cells = row.querySelectorAll('td');
        if (cells[1]) cells[1].innerHTML = `<span class="name-cell">${escapeHtml(change.name || '-')}</span>`;
        
        if (cells[2]) {
            if (change.url) {
                cells[2].innerHTML = `<small>${escapeHtml(change.url)}</small>`;
            } else if (change.tvclub_id) {
                cells[2].innerHTML = `<small>ID: ${escapeHtml(change.tvclub_id)}</small>`;
            }
        }
        
        if (cells[3] && change.allow) {
            cells[3].textContent = change.allow;
        }
        
        // Обновляем бейдж типа
        if (cells[4]) {
            if (change.url) {
                cells[4].innerHTML = '<span class="badge badge--info">Direct URL</span>';
            } else if (change.tvclub_id) {
                cells[4].innerHTML = '<span class="badge badge--success">TVClub</span>';
            } else {
                cells[4].innerHTML = '<span class="badge badge--muted">Пустой</span>';
            }
        }
        
        row.dataset.pending = 'edit';
        row.classList.add('pending-change');
    } else if (change.action === 'add') {
        // Для новых каналов можно добавить визуальный индикатор в хедер
        // или просто обновить счётчик
    }
    
    // Подсветка строки как изменённой
    if (row) {
        row.classList.add('pending-change');
    }
}

// Экранирование HTML для безопасности
function escapeHtml(text) {
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return String(text).replace(/[&<>"']/g, m => map[m]);
}

// Обновление всех строк при загрузке страницы (восстановление визуала)
function applyAllPendingChanges() {
    const changes = getPendingChanges();
    changes.forEach(change => applyPendingChangeToTable(change));
}
        
        document.addEventListener('DOMContentLoaded', function() {
            checkAndClearPending();
	applyAllPendingChanges();
        });
// === Авто-расширение textarea URL ===
/*function autoResizeTextarea(el) {
    el.style.height = 'auto';
    el.style.height = Math.min(el.scrollHeight, 120) + 'px'; // Макс. 3 строки (~120px)
} */

function autoResizeTextarea(el) {
    const startHeight = el.scrollHeight;
    el.style.height = 'auto';
    const endHeight = Math.min(el.scrollHeight, 200) + 'px';
    
    // Плавная анимация (опционально)
    if (typeof anime !== 'undefined') {
        anime({
            targets: el,
            height: endHeight,
            duration: 150,
            easing: 'easeOutQuad'
        });
    } else {
        el.style.transition = 'height 0.15s ease';
        el.style.height = endHeight;
    }
}

// Инициализация авто-расширения для URL
function initUrlAutoResize() {
    const urlField = document.getElementById('form-url');
    if (!urlField) return;
    
    // При вводе
    urlField.addEventListener('input', function() {
        autoResizeTextarea(this);
    });
    
    // При фокусе — сразу подстраиваем
    urlField.addEventListener('focus', function() {
        autoResizeTextarea(this);
    });
    
    // При открытии модалки — подстраиваем под существующее значение
    const observer = new MutationObserver(function() {
        if (document.getElementById('edit-modal').classList.contains('active')) {
            autoResizeTextarea(urlField);
        }
    });
    observer.observe(document.getElementById('edit-modal'), { attributes: true, attributeFilter: ['class'] });
}

// Вызов при загрузке страницы
document.addEventListener('DOMContentLoaded', function() {
    // ... существующий код ...
    initUrlAutoResize();  // <<< ДОБАВИТЬ
});
    </script>
</body>
</html>
