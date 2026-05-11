<?php
include_once("config.php");
checkLoggedIn("yes");
if($_SESSION['a'] != 1)
    exit();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IPTV Editor (Search & Insert Position)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
/* ============================================================================
   Дизайн-система: тёмная тема (как в uman.php)
   ========================================================================== */
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

/* Base */
body {
    height: 100vh;
    overflow: hidden;
    background: var(--bg) !important;
    color: var(--text);
    font-family: var(--font);
    font-size: 14px;
    -webkit-font-smoothing: antialiased;
}
.app-container { height: calc(100vh - 56px); }

.col-scroll {
    height: 100%; overflow-y: auto;
    border-right: 1px solid var(--border);
    background: var(--surface) !important;
    display: flex; flex-direction: column;
    padding-bottom: 80px;
}
.col-scroll::-webkit-scrollbar { width: 8px; }
.col-scroll::-webkit-scrollbar-track { background: var(--bg-elevated); }
.col-scroll::-webkit-scrollbar-thumb { background: var(--border-hi); border-radius: 4px; }

/* Убираем bootstrap bg-light */
.col-scroll.bg-light { background: var(--bg-elevated) !important; }

.sticky-head {
    position: sticky; top: 0; z-index: 10;
    background: var(--bg-elevated) !important;
    border-bottom: 1px solid var(--border);
    padding: 12px;
}

.w-20 { flex: 0 0 20%; max-width: 20%; }
.w-40 { flex: 0 0 40%; max-width: 40%; }

/* Navbar overrides (bootstrap) */
.navbar {
    background: rgba(10, 14, 22, 0.85) !important;
    backdrop-filter: saturate(180%) blur(12px);
    border-bottom: 1px solid var(--border);
    color: var(--text) !important;
}
.navbar-brand {
    color: var(--text) !important;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
}
.navbar-brand i { color: var(--primary); }

/* Headings */
h1, h2, h3, h4, h5, h6 { color: var(--text); }
.fw-bold { color: var(--text); }
.text-muted { color: var(--text-dim) !important; }
.text-white { color: var(--text) !important; }

/* Form controls (bootstrap overrides) */
.form-control, .form-select {
    background: var(--bg-elevated) !important;
    border: 1px solid var(--border) !important;
    color: var(--text) !important;
    border-radius: var(--radius);
    transition: var(--transition);
}
.form-control:focus, .form-select:focus {
    background: var(--bg-elevated) !important;
    border-color: var(--primary) !important;
    color: var(--text) !important;
    box-shadow: 0 0 0 2px var(--primary-bg);
}
.form-control::placeholder { color: var(--text-faint); }
.form-control-sm { font-size: 12px; }
.input-group-text {
    background: var(--surface-2) !important;
    border: 1px solid var(--border) !important;
    color: var(--text-dim) !important;
}
.input-group-text.bg-white { background: var(--surface-2) !important; }
.input-group-text.bg-warning { color: var(--warning) !important; background: var(--warning-bg) !important; border-color: var(--warning) !important; }

/* Buttons (bootstrap overrides) */
.btn {
    font-family: inherit;
    border-radius: var(--radius);
    transition: var(--transition);
    font-weight: 500;
}
.btn-primary {
    background: var(--primary) !important;
    border-color: var(--primary) !important;
    color: #fff !important;
}
.btn-primary:hover {
    background: var(--primary-hov) !important;
    border-color: var(--primary-hov) !important;
}
.btn-success {
    background: var(--success) !important;
    border-color: var(--success) !important;
    color: #07140d !important;
    font-weight: 600;
}
.btn-success:hover { filter: brightness(1.1); }
.btn-danger {
    background: var(--danger) !important;
    border-color: var(--danger) !important;
    color: #fff !important;
}
.btn-outline-primary {
    background: transparent !important;
    color: var(--primary) !important;
    border: 1px solid var(--primary) !important;
}
.btn-outline-primary:hover {
    background: var(--primary) !important;
    color: #fff !important;
}
.btn-outline-primary:disabled {
    background: transparent !important;
    color: var(--text-faint) !important;
    border-color: var(--border) !important;
    opacity: 0.5;
}
.btn-outline-secondary {
    background: transparent !important;
    color: var(--text) !important;
    border: 1px solid var(--border) !important;
}
.btn-outline-secondary:hover, .btn-outline-secondary.active, .btn-check:checked + .btn-outline-secondary {
    background: var(--primary) !important;
    color: #fff !important;
    border-color: var(--primary) !important;
}
.btn-outline-dark {
    background: transparent !important;
    color: var(--text) !important;
    border: 1px solid var(--border) !important;
}
.btn-outline-dark:hover, .btn-outline-dark:disabled {
    background: var(--surface-2) !important;
}
.btn-outline-dark:disabled {
    color: var(--text-faint) !important;
    border-color: var(--border) !important;
}
.btn-outline-danger {
    background: transparent !important;
    color: var(--danger) !important;
    border: 1px solid var(--danger) !important;
}
.btn-outline-danger:hover {
    background: var(--danger) !important;
    color: #fff !important;
}
.btn-outline-light {
    background: transparent !important;
    color: var(--text) !important;
    border: 1px solid var(--border-hi) !important;
}
.btn-outline-light:hover {
    background: var(--surface-2) !important;
    border-color: var(--border-hi) !important;
}

/* Card (bootstrap override) */
.card {
    background: var(--surface) !important;
    border: 1px solid var(--border) !important;
    border-radius: var(--radius-lg);
    color: var(--text);
}
.card-body { color: var(--text); }

/* List group (bootstrap override) */
.list-group {
    background: transparent;
}
.list-group-item {
    background: var(--surface) !important;
    border: 1px solid var(--border) !important;
    color: var(--text) !important;
    font-size: 13px;
    transition: var(--transition);
}
.list-group-item-action:hover, .list-group-item-action:focus {
    background: var(--bg-elevated) !important;
    color: var(--text) !important;
}

/* Groups (left column) */
.group-item { cursor: pointer; user-select: none; }
.group-item.active {
    background: var(--primary-bg) !important;
    color: var(--primary) !important;
    border-color: var(--primary) !important;
    box-shadow: inset 3px 0 0 var(--primary);
}

.group-edit-btn {
    opacity: 0.5; cursor: pointer; transition: 0.2s;
    color: var(--text-dim) !important;
}
.group-edit-btn:hover { opacity: 1; color: var(--warning) !important; }
.active .group-edit-btn { color: var(--primary) !important; }

/* Drag & Drop */
.draggable-item {
    cursor: grab;
    background: var(--surface) !important;
    border: 1px solid var(--border) !important;
    color: var(--text) !important;
    transition: background-color 0.1s;
}
.draggable-item:active { cursor: grabbing; }
.draggable-item.dragging {
    opacity: 0.5;
    background: var(--primary-bg) !important;
    border: 2px dashed var(--primary) !important;
}

.group-draggable { cursor: grab; }
.group-draggable:active { cursor: grabbing; }
.group-draggable.dragging {
    opacity: 0.5;
    background: var(--surface-2) !important;
}

.in-group-highlight { background: var(--success-bg) !important; border-color: var(--success) !important; }
.selected-highlight { background: var(--primary-bg) !important; border-color: var(--primary) !important; }

/* Выделение для сортировки/вставки */
.sort-selected {
    background: var(--warning-bg) !important;
    border-color: var(--warning) !important;
}

/* Маркер позиции вставки */
.insert-target { box-shadow: inset 5px 0 0 var(--success) !important; }

/* Unsaved alert */
#unsavedAlert {
    position: fixed; bottom: 30px; left: 50%;
    transform: translateX(-50%);
    z-index: 2000; display: none; min-width: 400px;
    background: var(--surface) !important;
    border: 1px solid var(--warning) !important;
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-2);
}
#unsavedAlert .card-body {
    background: var(--warning-bg) !important;
    color: var(--text);
}
#unsavedAlert .fw-bold { color: var(--warning); }
#unsavedAlert .text-warning { color: var(--warning) !important; }

/* Modal (bootstrap override) */
.modal-content {
    background: var(--surface) !important;
    border: 1px solid var(--border-hi) !important;
    border-radius: var(--radius-lg);
    color: var(--text);
    box-shadow: var(--shadow-2);
}
.modal-header {
    border-bottom: 1px solid var(--border);
    padding: 16px 20px;
}
.modal-title { color: var(--text); font-weight: 600; }
.modal-body { color: var(--text); padding: 20px; }
.modal-footer {
    border-top: 1px solid var(--border);
    padding: 12px 20px;
    background: var(--bg-elevated);
    border-bottom-left-radius: var(--radius-lg);
    border-bottom-right-radius: var(--radius-lg);
}
.btn-close {
    filter: invert(1) grayscale(1) brightness(2);
    opacity: 0.6;
}
.btn-close:hover { opacity: 1; }

/* Badges */
.badge {
    font-weight: 600;
    border-radius: var(--radius-pill);
    padding: 3px 9px;
}
.badge.bg-secondary { background: var(--surface-2) !important; color: var(--text-dim); }

/* Dropdown (bootstrap override) */
.dropdown-menu {
    background: var(--surface-2) !important;
    border: 1px solid var(--border-hi) !important;
    box-shadow: var(--shadow-2);
}
.dropdown-item {
    color: var(--text) !important;
}
.dropdown-item:hover, .dropdown-item:focus {
    background: var(--primary-bg) !important;
    color: var(--primary-hov) !important;
}

/* Form text */
.form-text { color: var(--text-dim) !important; font-size: 11px; }

/* Bootstrap bg-opacity overrides */
.bg-warning.bg-opacity-10,
.bg-warning.bg-opacity-25 {
    background: var(--warning-bg) !important;
    color: var(--warning);
}
    </style>
</head>
<body class="d-flex flex-column">

    <nav class="navbar px-3 py-2 flex-shrink-0">
        <span class="navbar-brand mb-0 h1"><i class="bi bi-broadcast"></i> IPTV Editor</span>
        <div class="d-flex align-items-center">
            <label class="text-white me-2">Playlist ID:</label>
            <div class="input-group input-group-sm" style="width: 200px;">
                <select id="playlistSelect" class="form-select"></select>
                <button class="btn btn-outline-light" type="button" onclick="app.addNewPlaylistId()" title="Создать новый">+</button>
            </div>
        </div>
    </nav>

    <div id="unsavedAlert" class="card border-warning">
        <div class="card-body bg-warning bg-opacity-25 d-flex align-items-center justify-content-between py-2 px-3">
            <div class="d-flex align-items-center">
                <i class="bi bi-pencil-fill text-warning me-2"></i>
                <span class="fw-bold">Есть несохраненные изменения!</span>
            </div>
            <div>
                <button class="btn btn-sm btn-success fw-bold me-1" onclick="app.saveChanges()">Сохранить</button>
                <button class="btn btn-sm btn-outline-dark" onclick="app.revertChanges()">Отмена</button>
            </div>
        </div>
    </div>

    <div class="container-fluid p-0 app-container">
        <div class="row g-0 h-100">
            
            <div class="col-scroll w-20">
                <div class="sticky-head">
                    <h6 class="fw-bold">Группы</h6>
                    <button id="btnCreateGroup" class="btn btn-sm btn-outline-primary w-100 mb-2" onclick="app.createGroupLocal()" disabled>
                        <i class="bi bi-plus-lg"></i> Новая группа
                    </button>
                    <input type="text" id="groupSearch" class="form-control form-control-sm" placeholder="Фильтр...">
                </div>
                <div id="groupsList" class="list-group list-group-flush flex-grow-1"></div>
            </div>

            <div class="col-scroll w-40 bg-light">
                <div class="sticky-head">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <div>
                            <h6 class="fw-bold mb-0" id="activeGroupName">Группа не выбрана</h6>
                            <small class="text-muted" id="activeGroupCount"></small>
                        </div>
                        <button id="btnDeleteGroup" class="btn btn-sm btn-outline-danger d-none" onclick="app.deleteGroupLocal()">
                            <i class="bi bi-trash"></i> Удалить
                        </button>
                    </div>

                    <div class="d-flex gap-2 mb-2">
                        <input type="text" id="activeGroupSearch" class="form-control form-control-sm" placeholder="Найти в группе..." oninput="app.renderActiveGroup()">
                        <div class="input-group input-group-sm" style="width: 160px;" title="Куда вставлять новые каналы">
                            <span class="input-group-text bg-white">Вставка:</span>
                            <input type="number" id="insertPosInput" class="form-control text-center fw-bold" placeholder="Конец" oninput="app.updateButtons()">
                        </div>
                    </div>
                    
                    <div id="manualMovePanel" class="input-group input-group-sm d-none">
                        <span class="input-group-text bg-warning bg-opacity-10">Move selected (<b id="sortSelCount">0</b>) to:</span>
                        <input type="number" id="moveToPosInput" class="form-control" placeholder="№" onkeydown="if(event.key==='Enter') app.moveSelectedChannelsByInput()">
                        <button class="btn btn-outline-secondary" onclick="app.moveSelectedChannelsByInput()">OK</button>
                        <button class="btn btn-outline-danger" onclick="app.clearSortSelection()"><i class="bi bi-x-lg"></i></button>
                    </div>
                </div>

                <div id="activeGroupContent" class="p-2 flex-grow-1">
                    <div class="text-center text-muted mt-5">Выберите группу слева</div>
                </div>
            </div>

            <div class="col-scroll w-40">
                <div class="sticky-head">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="fw-bold mb-0">Источник</h6>
                        <div class="btn-group btn-group-sm">
                            <input type="radio" class="btn-check" name="sortSrc" id="sortName" checked onchange="app.setSort('name')">
                            <label class="btn btn-outline-secondary" for="sortName">Имя</label>
                            <input type="radio" class="btn-check" name="sortSrc" id="sortId" onchange="app.setSort('id')">
                            <label class="btn btn-outline-secondary" for="sortId">ID</label>
                        </div>
                    </div>
                    
                    <div class="d-flex gap-2 mb-2">
                         <input type="text" id="channelSearch" class="form-control form-control-sm" placeholder="Поиск каналов...">
                         <button class="btn btn-sm btn-success" onclick="app.openChannelModal()" title="Добавить в базу"><i class="bi bi-plus-lg"></i></button>
                    </div>
                    
                    <div class="d-flex gap-2">
                        <button class="btn btn-sm btn-primary flex-grow-1 text-truncate" id="btnAddSelected" disabled onclick="app.addSelectedToCurrent()">
                            <i class="bi bi-arrow-left"></i> <span id="btnAddText">Добавить</span> (<span id="selCount">0</span>)
                        </button>
                        <button class="btn btn-sm btn-outline-dark flex-grow-1" id="btnCreateFromSelected" disabled onclick="app.createGroupFromSelectedLocal()">
                            <i class="bi bi-folder-plus"></i> Группа
                        </button>
                    </div>
                </div>
                <div id="allChannelsList" class="list-group list-group-flush flex-grow-1"></div>
            </div>

        </div>
    </div>

<div class="modal fade" id="channelModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title">Канал</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <!-- Скрытое поле для хранения ID при редактировании -->
                    <input type="hidden" id="chEditId">
                    
                    <!-- Поле для ручного ввода ID -->
                    <div class="mb-2">
                        <label>ID канала</label>
                        <div class="input-group">
                            <input type="number" id="chCustomId" class="form-control" placeholder="Авто (если пусто)">
                            <button class="btn btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">Свободные ID</button>
                            <ul class="dropdown-menu dropdown-menu-end" id="freeIdsDropdown" style="max-height: 200px; overflow-y: auto;">
                                <li><span class="dropdown-item">Загрузка...</span></li>
                            </ul>
                        </div>
                        <div class="form-text text-muted" id="idHelpText">Оставьте пустым для авто-назначения.</div>
                    </div>

                    <div class="mb-2"><label>Название</label><input type="text" id="chName" class="form-control"></div>
                    <div class="row">
                        <div class="col-6"><label>Архив (дней)</label><input type="number" id="chRec" class="form-control"></div>
                        <div class="col-6"><label>Качество</label><select id="chRes" class="form-select"><option>SD</option><option>HD</option><option>FHD</option><option>4K</option></select></div>
                    </div>
                </div>
                <div class="modal-footer"><button class="btn btn-primary" onclick="app.saveChannelDB()">Сохранить в БД</button></div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
        const app = {
            state: {
                playlistIds: [],
                channels: [],
                channelsMap: {},
                groups: [],          
                currentPlaylistId: null, 
                
                // --- ГРУППЫ (ЛЕВАЯ КОЛОНКА) ---
                currentGroup: null, 
                selectedGroupIndices: new Set(),
                lastGroupClickIndex: null,       
                
                // --- ИСТОЧНИК (ПРАВАЯ КОЛОНКА) ---
                selectedChannelIds: new Set(),
                
                // --- СОСТАВ ГРУППЫ (СРЕДНЯЯ КОЛОНКА) ---
                activeGroupSelection: new Set(), // Индексы выделенных каналов
                lastActiveChannelClickIndex: null, // "Якорь" для Shift+Click в средней колонке
                insertTargetIndex: null, // Для зеленой полоски (куда вставлять)

                isDirty: false,      
                sortBy: 'name'
            },

            init: async function() {
                await this.loadInitialData();
                this.setupEvents();
            },

            loadInitialData: async function() {
                const res = await fetch('api.php?action=get_initial_data');
                const data = await res.json();
                
                this.updatePlaylistList(data.playlistIds);
                this.updateChannels(data.channels);
                
                if (this.state.playlistIds.length > 0) {
                    this.setPlaylist(this.state.playlistIds[0]);
                } else {
                    this.updatePlaylistList([1]);
                    this.setPlaylist(1);
                }
            },


setPlaylist: async function(id) {
    // Ранее здесь был confirm, который дублировался с revertChanges.
    // Теперь проверка только в revertChanges по требованию пользователя.
    
    id = parseInt(id);
    this.state.currentPlaylistId = id;
    document.getElementById('playlistSelect').value = id;
    document.getElementById('btnCreateGroup').disabled = (id === null || isNaN(id));
    
    this.setDirty(false);
    this.state.currentGroup = null;
    
    // Сброс всех выделений
    this.state.selectedGroupIndices.clear();
    this.state.lastGroupClickIndex = null;
    
    this.state.activeGroupSelection.clear(); 
    this.state.lastActiveChannelClickIndex = null;
    this.state.insertTargetIndex = null;
    
    document.getElementById('insertPosInput').value = '';

    const res = await fetch(`api.php?action=get_groups&playlist_id=${id}`);
    this.state.groups = await res.json();
    
    this.renderUI();
},

            renderUI: function() {
                this.renderGroups();
                this.renderActiveGroup();
                this.renderAllChannels();
                this.updateButtons();
            },

            updatePlaylistList: function(ids) {
                if(ids.length === 0) ids = [1];
                this.state.playlistIds = [...new Set(ids)].sort((a,b)=>a-b);
                const sel = document.getElementById('playlistSelect');
                sel.innerHTML = this.state.playlistIds.map(id => `<option value="${id}">Playlist #${id}</option>`).join('');
                if(this.state.currentPlaylistId) sel.value = this.state.currentPlaylistId;
            },

            updateChannels: function(channels) {
                this.state.channels = channels;
                this.state.channelsMap = {};
                channels.forEach(c => this.state.channelsMap[parseInt(c.id)] = c);
                this.renderAllChannels();
            },

            setDirty: function(val) {
                this.state.isDirty = val;
                document.getElementById('unsavedAlert').style.display = val ? 'block' : 'none';
            },

            // --- ГРУППЫ (ЛЕВАЯ КОЛОНКА) ---

            createGroupLocal: function(initialIds = []) {
                if (!this.state.currentPlaylistId) return;
                const name = prompt("Название группы:");
                if (!name) return;
                
                const newGroup = {
                    id: null,
                    name: name,
                    playlist_id: this.state.currentPlaylistId,
                    channel_ids: initialIds
                };
                
                this.state.groups.push(newGroup);
                const newIdx = this.state.groups.length - 1;
                this.handleGroupClick(newGroup, newIdx, null);
                this.setDirty(true);
            },
            
            renameGroupLocal: function(e, idx) {
                e.stopPropagation(); 
                const group = this.state.groups[idx];
                const newName = prompt("Новое название:", group.name);
                if (newName && newName !== group.name) {
                    group.name = newName;
                    this.setDirty(true);
                    this.renderGroups(); 
                    if(this.state.currentGroup === group) this.renderActiveGroup(); 
                }
            },

            handleGroupClick: function(group, index, event) {
                const state = this.state;
                const filter = document.getElementById('groupSearch').value.toLowerCase();
                const visibleIndices = [];
                this.state.groups.forEach((g, i) => {
                    if (g.name.toLowerCase().includes(filter)) visibleIndices.push(i);
                });

                if (event && event.shiftKey && state.lastGroupClickIndex !== null) {
                    const startPos = visibleIndices.indexOf(state.lastGroupClickIndex);
                    const endPos = visibleIndices.indexOf(index);
                    if (startPos !== -1 && endPos !== -1) {
                        const min = Math.min(startPos, endPos);
                        const max = Math.max(startPos, endPos);
                        if (!event.ctrlKey && !event.metaKey) state.selectedGroupIndices.clear();
                        for (let i = min; i <= max; i++) state.selectedGroupIndices.add(visibleIndices[i]);
                    }
                } 
                else if (event && (event.ctrlKey || event.metaKey)) {
                    if (state.selectedGroupIndices.has(index)) state.selectedGroupIndices.delete(index);
                    else state.selectedGroupIndices.add(index);
                    state.lastGroupClickIndex = index;
                } 
                else {
                    state.selectedGroupIndices.clear();
                    state.selectedGroupIndices.add(index);
                    state.lastGroupClickIndex = index;
                }

                if (state.selectedGroupIndices.has(index)) {
                    state.currentGroup = group;
                } else if (state.selectedGroupIndices.size > 0) {
                    const iterator = state.selectedGroupIndices.values();
                    state.currentGroup = this.state.groups[iterator.next().value];
                } else {
                    state.currentGroup = null;
                }

                // Сброс центральной панели
                state.activeGroupSelection.clear();
                state.lastActiveChannelClickIndex = null;
                state.insertTargetIndex = null;
                document.getElementById('activeGroupSearch').value = '';
                document.getElementById('insertPosInput').value = '';

                this.renderUI();
            },

            deleteGroupLocal: function() {
                const count = this.state.selectedGroupIndices.size;
                if (count === 0) return;
                if (!confirm(`Удалить выбранные группы (${count} шт)?`)) return;

                const indicesToDelete = Array.from(this.state.selectedGroupIndices).sort((a, b) => b - a);
                indicesToDelete.forEach(idx => this.state.groups.splice(idx, 1));

                this.state.selectedGroupIndices.clear();
                this.state.currentGroup = null;
                this.state.lastGroupClickIndex = null;
                this.renderUI();
                this.setDirty(true);
            },

            renderGroups: function() {
                const list = document.getElementById('groupsList');
                const filter = document.getElementById('groupSearch').value.toLowerCase();
                list.innerHTML = '';

                this.state.groups.forEach((g, idx) => {
                    if (!g.name.toLowerCase().includes(filter)) return;

                    const btn = document.createElement('div');
                    const isSelected = this.state.selectedGroupIndices.has(idx);
                    const count = Array.isArray(g.channel_ids) ? g.channel_ids.length : 0;
                    
                    btn.className = `list-group-item list-group-item-action d-flex justify-content-between align-items-center group-item group-draggable ${isSelected ? 'active' : ''}`;
                    btn.draggable = true;
                    btn.dataset.idx = idx; 
                    
                    btn.innerHTML = `
                        <div class="d-flex align-items-center text-truncate flex-grow-1" style="pointer-events: none;">
                            <i class="bi bi-grip-vertical me-2 opacity-50"></i>
                            <span class="me-2 text-truncate">${g.name}</span>
                            <i class="bi bi-pencil-square group-edit-btn" style="pointer-events: auto;" onclick="app.renameGroupLocal(event, ${idx})"></i>
                        </div>
                        <span class="badge bg-secondary rounded-pill bg-opacity-50">${count}</span>
                    `;
                    
                    btn.onclick = (e) => this.handleGroupClick(g, idx, e);
                    
                    btn.addEventListener('dragstart', (e) => {
                        if (e.shiftKey || e.ctrlKey || e.metaKey) { e.preventDefault(); return; }
                        if (!this.state.selectedGroupIndices.has(idx)) this.handleGroupClick(g, idx, null);
                        btn.classList.add('dragging');
                    });
                    
                    btn.addEventListener('dragend', () => {
                        btn.classList.remove('dragging');
                        this.applyGroupOrder(list);
                    });
                    list.appendChild(btn);
                });

                list.ondragover = (e) => {
                    e.preventDefault();
                    const container = list;
                    const afterElement = this.getDragAfterElement(container, e.clientY, '.group-draggable');
                    const draggable = document.querySelector('.group-draggable.dragging');
                    if (!draggable) return;
                    const currentAfter = draggable.nextElementSibling;
                    if (afterElement !== currentAfter) {
                        if (afterElement == null) container.appendChild(draggable);
                        else container.insertBefore(draggable, afterElement);
                    }
                };
            },

            applyGroupOrder: function(container) {
                const newOrder = [];
                container.querySelectorAll('.group-draggable').forEach(el => {
                    newOrder.push(this.state.groups[parseInt(el.dataset.idx)]);
                });
                
                let changed = false;
                if(newOrder.length !== this.state.groups.length) changed = true;
                else {
                    for(let i=0; i<newOrder.length; i++) if (newOrder[i] !== this.state.groups[i]) changed = true;
                }
                
                if (changed) {
                    this.state.groups = newOrder;
                    this.state.selectedGroupIndices.clear();
                    this.state.currentGroup = null;
                    this.setDirty(true);
                    this.renderUI();
                }
            },

            // --- СОСТАВ ГРУППЫ (СРЕДНЯЯ КОЛОНКА) ---
            
            // НОВАЯ ФУНКЦИЯ ДЛЯ ОБРАБОТКИ КЛИКОВ В СРЕДНЕЙ КОЛОНКЕ
            handleActiveChannelClick: function(index, event) {
                const state = this.state;
                const insertInput = document.getElementById('insertPosInput');
                
                // 1. Устанавливаем позицию вставки (всегда на последний клик)
                state.insertTargetIndex = index;
                insertInput.value = index + 1;

                // 2. Вычисляем видимые индексы для Shift+Click (фильтр)
                const searchVal = document.getElementById('activeGroupSearch').value.toLowerCase();
                const groupIds = state.currentGroup.channel_ids || [];
                const visibleIndices = [];
                groupIds.forEach((cid, i) => {
                    const ch = state.channelsMap[cid];
                    // Если канал в базе или если его нет (ID показываем), и он проходит поиск
                    if (!searchVal || (ch && ch.name.toLowerCase().includes(searchVal))) {
                        visibleIndices.push(i);
                    }
                });

                if (event && event.shiftKey && state.lastActiveChannelClickIndex !== null) {
                    // --- SHIFT ---
                    const startPos = visibleIndices.indexOf(state.lastActiveChannelClickIndex);
                    const endPos = visibleIndices.indexOf(index);
                    
                    if (startPos !== -1 && endPos !== -1) {
                        const min = Math.min(startPos, endPos);
                        const max = Math.max(startPos, endPos);
                        
                        if (!event.ctrlKey && !event.metaKey) state.activeGroupSelection.clear();
                        
                        for(let i=min; i<=max; i++) {
                            state.activeGroupSelection.add(visibleIndices[i]);
                        }
                    }
                } 
                else if (event && (event.ctrlKey || event.metaKey)) {
                    // --- CTRL ---
                    if (state.activeGroupSelection.has(index)) state.activeGroupSelection.delete(index);
                    else state.activeGroupSelection.add(index);
                    state.lastActiveChannelClickIndex = index;
                } 
                else {
                    // --- SINGLE ---
                    state.activeGroupSelection.clear();
                    state.activeGroupSelection.add(index);
                    state.lastActiveChannelClickIndex = index;
                }
                
                this.renderActiveGroup();
                this.updateButtons();
            },

            renderActiveGroup: function() {
                const container = document.getElementById('activeGroupContent');
                const title = document.getElementById('activeGroupName');
                const countInfo = document.getElementById('activeGroupCount');
                const btnDel = document.getElementById('btnDeleteGroup');
                const movePanel = document.getElementById('manualMovePanel');
                const sortSelCount = document.getElementById('sortSelCount');
                const searchVal = document.getElementById('activeGroupSearch').value.toLowerCase();
                const insertInput = document.getElementById('insertPosInput');

                const selCount = this.state.selectedGroupIndices.size;

                if (!this.state.currentGroup && selCount === 0) {
                    title.textContent = "Выберите группу"; countInfo.textContent = "";
                    btnDel.classList.add('d-none');
                    movePanel.classList.add('d-none');
                    container.innerHTML = '<div class="text-center text-muted mt-5">Выберите группу слева</div>';
                    return;
                }

                btnDel.classList.remove('d-none');
                if (selCount > 1) {
                    title.innerHTML = `Выбрано групп: ${selCount} <br><small class="text-muted fw-normal fs-6">Просмотр: ${this.state.currentGroup ? this.state.currentGroup.name : '...'}</small>`;
                } else {
                    title.textContent = this.state.currentGroup.name;
                }

                if (!this.state.currentGroup) { container.innerHTML = ''; return; }

                const ids = this.state.currentGroup.channel_ids || [];
                countInfo.textContent = `${ids.length} к.`;
                container.innerHTML = '';

                if (this.state.activeGroupSelection.size > 0) {
                    movePanel.classList.remove('d-none');
                    sortSelCount.textContent = this.state.activeGroupSelection.size;
                } else {
                    movePanel.classList.add('d-none');
                }

                const currentInsertPos = insertInput.value ? parseInt(insertInput.value) : null;

                ids.forEach((cid, idx) => {
                    const ch = this.state.channelsMap[cid];
                    if (searchVal && (!ch || !ch.name.toLowerCase().includes(searchVal))) return;

                    const div = document.createElement('div');
                    
                    const isSelected = this.state.activeGroupSelection.has(idx);
                    const isInsertTarget = currentInsertPos !== null && (currentInsertPos - 1) === idx;

                    div.className = `card mb-2 draggable-item ${isSelected ? 'sort-selected' : ''} ${isInsertTarget ? 'insert-target' : ''}`;
                    div.draggable = !searchVal; 
                    div.dataset.idx = idx; 
                    
                    // Клик -> Новая функция обработки
                    div.onclick = (e) => {
                        if(e.target.closest('button')) return;
                        this.handleActiveChannelClick(idx, e);
                    };

                    let inner = ch 
                        ? `<i class="bi bi-grip-vertical text-muted me-2"></i> <strong>${idx+1}.</strong> ${ch.name} <small class="text-muted ms-1">${ch.resolution}</small>`
                        : `<span class="text-danger">ID:${cid} (Нет в БД)</span>`;

                    div.innerHTML = `
                        <div class="card-body p-2 d-flex justify-content-between align-items-center">
                            <div class="text-truncate" style="pointer-events:none;">${inner}</div>
                            <button class="btn btn-sm text-danger p-0 border-0" onclick="app.removeChannelLocal(${idx})"><i class="bi bi-x-lg"></i></button>
                        </div>
                    `;
                    
                    if (!searchVal) {
                        div.addEventListener('dragstart', (e) => {
                            // Блокируем Drag если нажаты модификаторы
                            if (e.shiftKey || e.ctrlKey || e.metaKey) { e.preventDefault(); return; }
                            
                            // Если перетаскиваем невыделенный, выделяем его одного
                            if (!this.state.activeGroupSelection.has(idx)) {
                                this.handleActiveChannelClick(idx, null);
                            }
                            div.classList.add('dragging');
                        });
                        
                        div.addEventListener('dragend', () => {
                            div.classList.remove('dragging');
                            this.applyChannelOrder(container);
                        });
                    }
                    container.appendChild(div);
                });

                if (!searchVal) {
                    container.ondragover = (e) => {
                        e.preventDefault();
                        const afterElement = this.getDragAfterElement(container, e.clientY, '.draggable-item');
                        const draggable = document.querySelector('.draggable-item.dragging');
                        if (!draggable) return;
                        const currentAfter = draggable.nextElementSibling;
                        if (afterElement !== currentAfter) {
                            if (afterElement == null) container.appendChild(draggable);
                            else container.insertBefore(draggable, afterElement);
                        }
                    };
                }
            },

            clearSortSelection: function() {
                this.state.activeGroupSelection.clear();
                this.renderActiveGroup();
            },

            moveSelectedChannelsByInput: function() {
                const input = document.getElementById('moveToPosInput');
                let targetPos = parseInt(input.value);
                
                if (isNaN(targetPos) || targetPos < 1) return;
                targetPos = targetPos - 1; 
                
                const groupIds = this.state.currentGroup.channel_ids;
                const selectedIndices = Array.from(this.state.activeGroupSelection).sort((a,b) => a-b);
                
                if (selectedIndices.length === 0) return;
                
                const movingIds = selectedIndices.map(i => groupIds[i]);
                const newArr = groupIds.filter((_, i) => !this.state.activeGroupSelection.has(i));
                
                if (targetPos > newArr.length) targetPos = newArr.length;
                
                newArr.splice(targetPos, 0, ...movingIds);
                
                this.state.currentGroup.channel_ids = newArr;
                this.state.activeGroupSelection.clear();
                input.value = '';
                this.setDirty(true);
                this.renderActiveGroup();
            },

            applyChannelOrder: function(container) {
                const newIds = [];
                const oldIds = this.state.currentGroup.channel_ids;
                container.querySelectorAll('.draggable-item').forEach(el => {
                    const idx = parseInt(el.dataset.idx);
                    newIds.push(oldIds[idx]);
                });
                
                if (JSON.stringify(newIds) !== JSON.stringify(oldIds)) {
                    this.state.currentGroup.channel_ids = newIds;
                    this.state.activeGroupSelection.clear(); 
                    this.setDirty(true);
                    this.renderActiveGroup();
                }
            },

            removeChannelLocal: function(index) {
                this.state.currentGroup.channel_ids.splice(index, 1);
                this.state.activeGroupSelection.clear();
                this.renderUI();
                this.setDirty(true);
            },

            getDragAfterElement: function(container, y, selector) {
                const draggableElements = [...container.querySelectorAll(selector + ':not(.dragging)')];
                return draggableElements.reduce((closest, child) => {
                    const box = child.getBoundingClientRect();
                    const offset = y - box.top - box.height / 2;
                    if (offset < 0 && offset > closest.offset) {
                        return { offset: offset, element: child };
                    } else {
                        return closest;
                    }
                }, { offset: Number.NEGATIVE_INFINITY }).element;
            },

            // --- ИСТОЧНИК (ПРАВАЯ КОЛОНКА) ---

            createGroupFromSelectedLocal: function() {
                const ids = Array.from(this.state.selectedChannelIds);
                this.createGroupLocal(ids);
                this.state.selectedChannelIds.clear();
                this.renderAllChannels();
                this.updateButtons();
            },

            addSelectedToCurrent: function() {
                if (!this.state.currentGroup) return;
                
                const newIds = Array.from(this.state.selectedChannelIds);
                if (newIds.length === 0) return;

                if (!Array.isArray(this.state.currentGroup.channel_ids)) this.state.currentGroup.channel_ids = [];
                
                const insertInput = document.getElementById('insertPosInput');
                let pos = insertInput.value ? parseInt(insertInput.value) : null;

                if (pos !== null && !isNaN(pos)) {
                    if (pos > this.state.currentGroup.channel_ids.length) pos = this.state.currentGroup.channel_ids.length;
                    if (pos < 0) pos = 0; 
                    this.state.currentGroup.channel_ids.splice(pos, 0, ...newIds);
                } else {
                    this.state.currentGroup.channel_ids.push(...newIds);
                }
                
                this.state.selectedChannelIds.clear();
                this.renderUI();
                this.setDirty(true);
            },

            setSort: function(mode) {
                this.state.sortBy = mode;
                this.renderAllChannels();
            },

            renderAllChannels: function() {
                const list = document.getElementById('allChannelsList');
                const filter = document.getElementById('channelSearch').value.toLowerCase();
                const usedIds = new Set(this.state.currentGroup ? (this.state.currentGroup.channel_ids||[]) : []);

                list.innerHTML = '';
                
                let sorted = [...this.state.channels];
                if (this.state.sortBy === 'name') {
                    sorted.sort((a,b) => a.name.localeCompare(b.name));
                } else {
                    sorted.sort((a,b) => parseInt(a.id) - parseInt(b.id));
                }

                sorted.forEach(c => {
                    if (!c.name.toLowerCase().includes(filter) && !String(c.id).includes(filter)) return;
                    
                    const cIdInt = parseInt(c.id);
                    const isSel = this.state.selectedChannelIds.has(cIdInt);
                    const inGroup = usedIds.has(cIdInt);
                    
                    const div = document.createElement('div');
                    div.className = `list-group-item d-flex align-items-center ${isSel ? 'selected-highlight' : ''} ${inGroup ? 'in-group-highlight' : ''}`;
                    div.innerHTML = `
                        <input type="checkbox" class="form-check-input me-2" ${isSel ? 'checked' : ''}>
                        <div class="flex-grow-1 text-truncate">
                            <div class="fw-bold">${c.name}</div>
                            <small class="text-muted">ID:${c.id} | ${c.rec}d | ${c.resolution}</small>
                        </div>
                        <div>
                            <button class="btn btn-sm text-primary p-0 me-2" onclick="app.openChannelModal(${c.id})"><i class="bi bi-pencil"></i></button>
                            <button class="btn btn-sm text-danger p-0" onclick="app.deleteChannelDB(${c.id})"><i class="bi bi-trash"></i></button>
                        </div>
                    `;

                    div.querySelector('input').onchange = (e) => {
                        if (e.target.checked) this.state.selectedChannelIds.add(cIdInt);
                        else this.state.selectedChannelIds.delete(cIdInt);
                        this.renderAllChannels();
                        this.updateButtons();
                    };
                    list.appendChild(div);
                });
            },

            updateButtons: function() {
                const count = this.state.selectedChannelIds.size;
                document.getElementById('selCount').textContent = count;
                document.getElementById('btnAddSelected').disabled = !(count > 0 && this.state.currentGroup);
                document.getElementById('btnCreateFromSelected').disabled = count === 0;

                const posVal = document.getElementById('insertPosInput').value;
                const btnText = document.getElementById('btnAddText');
                if (posVal) {
                    btnText.textContent = `Вставить в поз. ${posVal}`;
                } else {
                    btnText.textContent = "Добавить в конец";
                }
            },

            // --- API ---

            saveChanges: async function() {
                const payload = {
                    playlist_id: this.state.currentPlaylistId,
                    groups: this.state.groups.map(g => ({
                        id: g.id, 
                        name: g.name,
                        channel_ids: g.channel_ids
                    }))
                };

                const res = await fetch('api.php?action=sync_playlist', {
                    method: 'POST', body: JSON.stringify(payload)
                });
                const json = await res.json();
                
                if (json.success) {
                    this.updatePlaylistList(json.playlistIds);
                    await this.setPlaylist(this.state.currentPlaylistId);
                    alert("Сохранено!");
                } else {
                    alert("Ошибка: " + json.error);
                }
            },


revertChanges: function() {
    // Теперь именно здесь появляется запрошенное сообщение при нажатии "Отмена"
    if (confirm("Несохраненные изменения будут потеряны. Продолжить?")) {
        this.setPlaylist(this.state.currentPlaylistId);
    }
},
            
            addNewPlaylistId: function() {
                if (this.state.isDirty && !confirm("Сбросить текущие изменения?")) return;
                const maxId = this.state.playlistIds.length > 0 ? Math.max(...this.state.playlistIds) : 0;
                const newId = maxId + 1;
                if (!this.state.playlistIds.includes(newId)) {
                    this.state.playlistIds.push(newId);
                    this.updatePlaylistList(this.state.playlistIds);
                }
                this.setPlaylist(newId);
            },

// ... внутри объекта app ...

            openChannelModal: function(id = null) {
                const isEdit = (id !== null);
                
                // Элементы формы
                const chEditId = document.getElementById('chEditId');
                const chCustomId = document.getElementById('chCustomId');
                const idHelpText = document.getElementById('idHelpText');
                const chName = document.getElementById('chName');
                const chRec = document.getElementById('chRec');
                const chRes = document.getElementById('chRes');

                if (isEdit) {
                    // Режим редактирования
                    const c = this.state.channelsMap[id];
                    chEditId.value = c.id;
                    
                    // Блокируем изменение ID при редактировании существующего канала
                    chCustomId.value = c.id;
                    chCustomId.disabled = true; 
                    idHelpText.textContent = "ID нельзя изменить после создания.";
                    
                    chName.value = c.name;
                    chRec.value = c.rec;
                    chRes.value = c.resolution;
                } else {
                    // Режим создания
                    chEditId.value = ''; // Пусто, значит создание
                    
                    chCustomId.value = '';
                    chCustomId.disabled = false;
                    idHelpText.textContent = "Оставьте пустым для авто-назначения или выберите из списка.";
                    
                    chName.value = '';
                    chRec.value = 0;
                    chRes.value = 'HD';
                    
                    // Загружаем свободные ID только при создании нового
                    this.loadFreeIds(); 
                }
                
                new bootstrap.Modal(document.getElementById('channelModal')).show();
            },

            loadFreeIds: async function() {
                const ul = document.getElementById('freeIdsDropdown');
                ul.innerHTML = '<li><span class="dropdown-item text-muted">Загрузка...</span></li>';
                
                try {
                    const res = await fetch('api.php?action=get_free_ids');
                    const ids = await res.json();
                    
                    ul.innerHTML = '';
                    if (ids.length === 0) {
                        ul.innerHTML = '<li><span class="dropdown-item disabled">Нет свободных ID в диапазоне</span></li>';
                        return;
                    }
                    
                    ids.forEach(id => {
                        const li = document.createElement('li');
                        const a = document.createElement('a');
                        a.className = 'dropdown-item';
                        a.href = '#';
                        a.textContent = id;
                        a.onclick = (e) => {
                            e.preventDefault();
                            document.getElementById('chCustomId').value = id;
                        };
                        li.appendChild(a);
                        ul.appendChild(li);
                    });
                } catch (e) {
                    ul.innerHTML = '<li><span class="dropdown-item text-danger">Ошибка загрузки</span></li>';
                }
            },

            saveChannelDB: async function() {
                const editId = document.getElementById('chEditId').value;
                const customId = document.getElementById('chCustomId').value;
                
                const data = {
                    id: editId, // Если пусто - создание, если есть значение - обновление
                    custom_id: customId, // Используется только при создании
                    name: document.getElementById('chName').value,
                    rec: document.getElementById('chRec').value,
                    resolution: document.getElementById('chRes').value
                };
                
                const res = await fetch('api.php?action=save_channel', { method: 'POST', body: JSON.stringify(data) });
                const json = await res.json();
                
                if (json.success) {
                    this.updateChannels(json.channels);
                    bootstrap.Modal.getInstance(document.getElementById('channelModal')).hide();
                    if(this.state.currentGroup) this.renderActiveGroup();
                } else {
                    alert("Ошибка: " + (json.error || "Неизвестная ошибка"));
                }
            },

            // ... остальные функции (deleteChannelDB, setupEvents и т.д.) ...

            deleteChannelDB: async function(id) {
                if (!confirm("Удалить канал из БАЗЫ? (Необратимо)")) return;
                await fetch('api.php?action=delete_channel', { method: 'POST', body: JSON.stringify({id}) });
                this.state.channels = this.state.channels.filter(c => c.id != id);
                this.updateChannels(this.state.channels);
                this.renderActiveGroup();
            },

            setupEvents: function() {
                document.getElementById('playlistSelect').onchange = (e) => this.setPlaylist(e.target.value);
                document.getElementById('groupSearch').oninput = () => this.renderGroups();
                document.getElementById('channelSearch').oninput = () => this.renderAllChannels();
            }
        };

        document.addEventListener('DOMContentLoaded', () => app.init());
    </script>
</body>
</html>