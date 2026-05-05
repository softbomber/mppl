<?php
// ==========================================
// 1. КОНФИГУРАЦИЯ И БЭКЕНД
// ==========================================
include_once("config.php");
checkLoggedIn("yes");
if($_SESSION['a'] != 1) exit();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>CDN Monitor Pro</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-zoom"></script>
    <style>
        body { font-family: sans-serif; background:black; color: #e0e0e0; padding:9px; }
        #container { display: grid; grid-template-columns: repeat(auto-fit, minmax(450px, 1fr)); gap: 20px; }
        .card {/*background: #1e1e1e; padding: 15px;*/ border-radius: 6px; border: 1px solid #0b1114; height:420px; display: flex; flex-direction: column; }
        h3 { color:#4a658f; margin:10px 0 2px 13px; font-size: 1.04em}
        .controls { /*background: #1e1e1e;*/ padding:10px; margin-bottom: 20px; border-radius:5px; border: 1px solid #132127; display: flex; align-items: center; flex-wrap: wrap; gap:6px;color:#fff9e0}
        input {
    background: #0c0b0b;
    color: #fff;
    border: 1px solid #231f1f;
    padding: 5px;
    border-radius: 4px;
    width: 50px;
}
        button { background: #2979ff; color: white; border: none; padding: 8px 15px; border-radius: 4px; cursor: pointer; }
        button:hover { background: #1565c0; }
        .peak-log { margin-top:5px;/* background: #000;*/ font-family: monospace; font-size: 0.85em; flex-grow: 1; overflow-y: auto; padding:5px; color: #88be88 }
        .peak-item { border-bottom: 1px dotted #333; padding: 2px 0; }
        canvas { cursor: grab; min-height: 250px; }
    </style>
</head>
<body>

<div class="controls">
    <div>
        <label>Обновление (мс): </label>
        <input type="number" id="updateInterval" value="1000" step="500" min="500">
    </div>
    <div>
        <label>Порог пика (Mbps): </label>
        <input type="number" id="threshold" value="100">
    </div>
    <button onclick="resetZoomAll()">Сброс зума</button>
    <button onclick="clearAllData()" style="background:#d32f2f">Очистить историю</button>
    <span id="status" style="color:#aaa"></span>
</div>

<div id="container"></div>

<script>
    // --- ПЕРЕМЕННЫЕ СОСТОЯНИЯ ---
    const charts = {};
    // Загружаем сохраненные данные из памяти браузера
    let maxValues = JSON.parse(localStorage.getItem('maxValues')) || {};
    let peakLogs = JSON.parse(localStorage.getItem('peakLogs')) || {};
    let currentInterval = parseInt(localStorage.getItem('currentInterval')) || 1000;
    let threshold = parseInt(localStorage.getItem('threshold')) || 100;
    let updateTimer = null;

    // Инициализация полей ввода
    document.getElementById('updateInterval').value = currentInterval;
    document.getElementById('threshold').value = threshold;

    // --- ЛОГИКА СОХРАНЕНИЯ ---
    function saveToStorage() {
        localStorage.setItem('maxValues', JSON.stringify(maxValues));
        localStorage.setItem('peakLogs', JSON.stringify(peakLogs));
        localStorage.setItem('currentInterval', currentInterval);
        localStorage.setItem('threshold', threshold);
    }

    function clearAllData() {
        if(confirm("Очистить все сохраненные пики и настройки?")) {
            localStorage.clear();
            location.reload();
        }
    }

    // --- РАБОТА С ГРАФИКАМИ ---
    function createChart(ip) {
        const container = document.getElementById('container');
        const div = document.createElement('div');
        div.className = 'card';
        const safeIp = ip.replace(/\./g, '-');
        
        div.innerHTML = `
            <h3>Server: ${ip}</h3>
            <div style="flex: 1; min-height: 250px;"><canvas id="chart-${safeIp}"></canvas></div>
            <div class="peak-log" id="log-${safeIp}"></div>
        `;
        container.appendChild(div);

        const ctx = document.getElementById(`chart-${safeIp}`).getContext('2d');
        charts[ip] = new Chart(ctx, {
            type: 'line',
            data: {
                labels: [],
                datasets: [
                    { label: 'TX', borderColor: '#00e676', data: [], tension: 0.3, pointRadius: 0 },
                    { label: 'RX', borderColor: '#2979ff', data: [], tension: 0.3, pointRadius: 0 }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false, animation: false,
                scales: {
                    x: { type: 'category', grid: { color: '#333' }, ticks: { color: '#888' } },
                    y: { beginAtZero: true, grid: { color: '#333' }, ticks: { color: '#888' } }
                },
                plugins: {
                    zoom: { pan: { enabled: true, mode: 'x' }, zoom: { wheel: { enabled: true }, mode: 'x' } },
                    tooltip: { mode: 'index', intersect: false }
                }
            }
        });
        updateLogUI(ip);
    }

function updateChart(ip, tx, rx) {
    const chart = charts[ip];
    if (!chart) return;

    const time = new Date().toLocaleTimeString();
    
    // Получаем ЧИСТЫЕ числа без округления
    const valTX = parseFloat(tx) || 0;
    const valRX = parseFloat(rx) || 0;
    
    // Находим максимальное значение из двух каналов в текущем пакете данных
    const currentPointMax = Math.max(valTX, valRX);

    // 1. ПРОВЕРКА ПОРОГА И ФИКСАЦИЯ ПИКА
    const currentThreshold = parseFloat(document.getElementById('threshold').value) || 0;

    if (currentPointMax >= currentThreshold) {
        // Если текущий максимум больше того, что мы видели раньше — запоминаем его
        if (!maxValues[ip] || currentPointMax > maxValues[ip]) {
            maxValues[ip] = currentPointMax;
            
            // Логируем реальные значения без модификаций
            if (!peakLogs[ip]) peakLogs[ip] = [];
            peakLogs[ip].unshift({ time, tx: valTX, rx: valRX });
            if (peakLogs[ip].length > 20) peakLogs[ip].pop();
            updateLogUI(ip);
        }
    }

    // 2. УСТАНОВКА ШКАЛЫ Y
    // Берем либо исторический рекорд (maxValues), либо текущий порог (threshold)
    // Никаких умножений на 1.1. Только реальный максимум.
    const limitY = Math.max(maxValues[ip] || 0, currentThreshold);
    
    // Устанавливаем предел шкалы. 
    // Если хотите видеть дробные значения точно (например 139.6), можно убрать Math.ceil
    chart.options.scales.y.max = limitY > 0 ? limitY : 10; 

    // 3. ДОБАВЛЕНИЕ ДАННЫХ
    chart.data.labels.push(time);
    chart.data.datasets[0].data.push(valTX);
    chart.data.datasets[1].data.push(valRX);

    // Очистка истории (храним 500 точек)
    if (chart.data.labels.length > 500) {
        chart.data.labels.shift();
        chart.data.datasets[0].data.shift();
        chart.data.datasets[1].data.shift();
    }

    // Смещение X (Live-окно на 30 точек)
    const total = chart.data.labels.length;
    chart.options.scales.x.min = Math.max(0, total - 30);
    chart.options.scales.x.max = total - 1;

    chart.update('none');
    saveToStorage();
}

    function updateLogUI(ip) {
        const logBox = document.getElementById(`log-${ip.replace(/\./g, '-')}`);
        if (logBox && peakLogs[ip]) {
            logBox.innerHTML = peakLogs[ip].map(p => 
                `<div class="peak-item">[${p.time}] <b>PEAK</b> TX: ${p.tx} | RX: ${p.rx}</div>`
            ).join('');
        }
    }

    function resetZoomAll() {
        Object.keys(charts).forEach(ip => charts[ip].resetZoom());
    }

    // --- МОНИТОРИНГ ---
    async function fetchData() {
        try {
            const response = await fetch('balproxy.php');
            const servers = await response.json();
            servers.forEach(server => {
                if (!charts[server.ip]) createChart(server.ip);
                updateChart(server.ip, server.tx, server.rx);
            });
        } catch (e) { console.error("Fetch error", e); }
    }

    function startMonitoring() {
        if (updateTimer) clearInterval(updateTimer);
        updateTimer = setInterval(fetchData, currentInterval);
        document.getElementById('status').innerText = `Цикл: ${currentInterval/1000}с`;
    }

    // Обработчики настроек
    document.getElementById('updateInterval').addEventListener('change', (e) => {
        currentInterval = Math.max(500, parseInt(e.target.value) || 1000);
        saveToStorage();
        startMonitoring();
    });

    document.getElementById('threshold').addEventListener('change', (e) => {
        threshold = parseInt(e.target.value) || 0;
        saveToStorage();
    });

    document.addEventListener('DOMContentLoaded', startMonitoring);
</script>
</body>
</html>