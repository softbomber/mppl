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
    <title>IPTV Editor</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
/* === Design system (aligned with uman.php) === */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html, body { width: 100%; height: 100vh; overflow: hidden; }
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
    display: flex;
    flex-direction: column;
    -webkit-font-smoothing: antialiased;
    text-rendering: optimizeLegibility;
}

/* ---- Top bar ---- */
.topbar {
    position: sticky; top: 0; z-index: 100;
    background: rgba(10, 14, 22, 0.85);
    backdrop-filter: saturate(180%) blur(12px);
    border-bottom: 1px solid var(--border);
    padding: 8px 16px;
    display: flex; align-items: center; justify-content: space-between;
    flex-shrink: 0;
}
.brand { display: flex; align-items: center; gap: 8px; font-weight: 600; color: var(--text); font-size: 15px; }
.brand-glyph { color: var(--primary); }

/* ---- Buttons ---- */
.btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 6px 12px; border: 1px solid var(--border); border-radius: var(--radius);
    background: var(--surface); color: var(--text); font-size: 12px; font-weight: 500;
    cursor: pointer; transition: var(--transition); font-family: inherit; white-space: nowrap;
}
.btn:hover { background: var(--surface-2); border-color: var(--border-hi); }
.btn:active { transform: translateY(1px); }
.btn:disabled { opacity: 0.4; cursor: not-allowed; }
.btn--primary { background: var(--primary); border-color: var(--primary); color: #fff; }
.btn--primary:hover { background: var(--primary-hov); border-color: var(--primary-hov); }
.btn--success { background: var(--success); border-color: var(--success); color: #07140d; font-weight: 600; }
.btn--success:hover { filter: brightness(1.1); }
.btn--danger  { background: var(--danger); border-color: var(--danger); color: #fff; }
.btn--danger:hover  { filter: brightness(1.1); }
.btn--ghost { background: transparent; border-color: transparent; }
.btn--sm { padding: 4px 8px; font-size: 11px; }

/* ---- Inputs ---- */
.input, select {
    padding: 6px 10px; border: 1px solid var(--border); border-radius: var(--radius);
    background: var(--bg-elevated); color: var(--text); font-size: 13px;
    font-family: inherit; outline: none; transition: border-color var(--transition);
}
.input:focus, select:focus { border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-bg); }
.input::placeholder { color: var(--text-faint); }
.input--sm { padding: 4px 8px; font-size: 12px; }

/* ---- App 3-col layout ---- */
.app-container { display: flex; flex: 1; overflow: hidden; }
.col-panel {
    display: flex; flex-direction: column; overflow: hidden;
    border-right: 1px solid var(--border); background: var(--surface);
}
.col-panel:last-child { border-right: none; }
.col-20 { flex: 0 0 20%; max-width: 20%; }
.col-40 { flex: 0 0 40%; max-width: 40%; }

.panel-head {
    position: sticky; top: 0; z-index: 10;
    background: var(--bg-elevated); border-bottom: 1px solid var(--border);
    padding: 10px 12px;
    flex-shrink: 0;
}
.panel-head h6 { font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-dim); margin-bottom: 8px; }
.panel-body { flex: 1; overflow-y: auto; padding-bottom: 80px; }

/* ---- Groups list ---- */
.group-item {
    padding: 8px 12px; cursor: pointer; user-select: none;
    display: flex; justify-content: space-between; align-items: center;
    border-bottom: 1px solid var(--border); transition: var(--transition);
    color: var(--text);
}
.group-item:hover { background: var(--bg-elevated); }
.group-item.active { background: var(--primary); color: #fff; }
.group-item .group-edit-btn { opacity: 0.4; cursor: pointer; transition: 0.2s; font-size: 13px; }
.group-item .group-edit-btn:hover { opacity: 1; color: var(--warning); }
.group-item.active .group-edit-btn { color: #fff; opacity: 0.7; }
.group-item.active .group-edit-btn:hover { opacity: 1; }
.group-badge {
    font-size: 10px; font-weight: 600; padding: 2px 7px;
    border-radius: var(--radius-pill); background: var(--surface-2); color: var(--text-dim);
}
.group-item.active .group-badge { background: rgba(255,255,255,0.2); color: #fff; }

/* ---- Drag & drop ---- */
.draggable-item {
    cursor: grab; background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--radius); margin-bottom: 4px; transition: background-color 0.1s;
}
.draggable-item:active { cursor: grabbing; }
.draggable-item.dragging { opacity: 0.5; background: var(--primary-bg) !important; border: 2px dashed var(--primary); }
.group-draggable { cursor: grab; }
.group-draggable:active { cursor: grabbing; }
.group-draggable.dragging { opacity: 0.5; background: var(--surface-hi) !important; }

.in-group-highlight { background: var(--success-bg) !important; }
.selected-highlight { background: var(--primary-bg) !important; }
.sort-selected { background: var(--warning-bg) !important; border-color: rgba(245,166,35,0.3) !important; }
.insert-target { border-left: 4px solid var(--success) !important; }

/* ---- Channel card (middle column) ---- */
.ch-card {
    padding: 6px 10px; display: flex; justify-content: space-between; align-items: center;
}
.ch-card .ch-info { display: flex; align-items: center; gap: 6px; flex: 1; min-width: 0; pointer-events: none; }
.ch-card .ch-info strong { color: var(--text); font-size: 13px; }
.ch-card .ch-info small { color: var(--text-faint); font-size: 11px; }
.ch-card .ch-remove { padding: 2px 6px; color: var(--danger); cursor: pointer; background: none; border: none; font-size: 14px; }
.ch-card .ch-remove:hover { color: #ff7070; }

/* ---- Source channel items (right column) ---- */
.src-item {
    padding: 8px 12px; display: flex; align-items: center; gap: 8px;
    border-bottom: 1px solid var(--border); transition: var(--transition);
}
.src-item:hover { background: var(--bg-elevated); }
.src-item .src-check { width: 16px; height: 16px; accent-color: var(--primary); cursor: pointer; }
.src-item .src-info { flex: 1; min-width: 0; }
.src-item .src-info .src-name { font-weight: 600; color: var(--text); font-size: 13px; }
.src-item .src-info .src-meta { font-size: 11px; color: var(--text-faint); }
.src-item .src-actions { display: flex; gap: 4px; }
.src-item .src-actions button { background: none; border: none; cursor: pointer; padding: 2px 4px; font-size: 14px; }
.src-item .src-actions .edit-btn { color: var(--primary); }
.src-item .src-actions .edit-btn:hover { color: var(--primary-hov); }
.src-item .src-actions .del-btn { color: var(--danger); }
.src-item .src-actions .del-btn:hover { color: #ff7070; }

/* ---- Sort toggle ---- */
.sort-toggle { display: flex; gap: 2px; background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-pill); padding: 2px; }
.sort-toggle label { padding: 3px 10px; font-size: 11px; border-radius: var(--radius-pill); cursor: pointer; color: var(--text-dim); transition: var(--transition); }
.sort-toggle input { display: none; }
.sort-toggle input:checked + label { background: var(--primary); color: #fff; }

/* ---- Unsaved alert ---- */
#unsavedAlert {
    position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%);
    z-index: 2000; display: none; min-width: 400px;
    background: var(--surface-2); border: 1px solid var(--warning);
    border-radius: var(--radius-lg); box-shadow: var(--shadow-2);
    padding: 10px 16px;
}
.unsaved-inner { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.unsaved-inner .unsaved-text { display: flex; align-items: center; gap: 8px; font-weight: 600; color: var(--warning); font-size: 13px; }
.unsaved-inner .unsaved-actions { display: flex; gap: 6px; }

/* ---- Modal ---- */
.modal-overlay {
    position: fixed; inset: 0;
    background: rgba(5, 9, 16, 0.7); backdrop-filter: blur(4px);
    z-index: 9999; display: none; align-items: center; justify-content: center; padding: 16px;
}
.modal-overlay.active { display: flex; }
.uman-modal {
    background: var(--surface); border: 1px solid var(--border-hi);
    border-radius: var(--radius-lg); box-shadow: var(--shadow-2);
    width: 100%; max-width: 440px; overflow: hidden;
    animation: scaleIn 180ms ease;
}
@keyframes scaleIn { from { transform: scale(0.96); opacity: 0; } to { transform: scale(1); opacity: 1; } }
.uman-modal .modal-header {
    padding: 14px 20px; border-bottom: 1px solid var(--border);
    display: flex; align-items: center; justify-content: space-between;
    font-weight: 600; color: var(--text);
}
.uman-modal .modal-header .close-btn {
    background: none; border: none; color: var(--text-faint); font-size: 20px;
    cursor: pointer; padding: 4px 8px; border-radius: var(--radius);
}
.uman-modal .modal-header .close-btn:hover { color: var(--text); background: var(--surface-2); }
.uman-modal .modal-body { padding: 16px 20px; }
.uman-modal .modal-footer {
    padding: 12px 20px; border-top: 1px solid var(--border);
    display: flex; justify-content: flex-end; gap: 8px; background: var(--bg-elevated);
}
.form-group { margin-bottom: 12px; }
.form-group label { display: block; font-size: 12px; font-weight: 500; color: var(--text-dim); margin-bottom: 4px; }
.form-group .input { width: 100%; }
.form-group .form-hint { font-size: 11px; color: var(--text-faint); margin-top: 3px; }
.form-row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }

/* ---- Free IDs dropdown ---- */
.free-ids-dropdown { position: relative; display: inline-block; }
.free-ids-list {
    position: absolute; top: 100%; right: 0; z-index: 100;
    background: var(--surface-2); border: 1px solid var(--border-hi); border-radius: var(--radius);
    max-height: 200px; overflow-y: auto; min-width: 120px; display: none;
    box-shadow: var(--shadow-2); margin-top: 4px;
}
.free-ids-list.show { display: block; }
.free-ids-list .fid-item {
    padding: 6px 12px; cursor: pointer; font-size: 13px; color: var(--text);
    border-bottom: 1px solid var(--border); transition: var(--transition);
}
.free-ids-list .fid-item:hover { background: var(--primary-bg); color: var(--primary-hov); }
.free-ids-list .fid-item:last-child { border-bottom: none; }
.free-ids-list .fid-info { padding: 8px 12px; color: var(--text-faint); font-size: 12px; }

/* ---- Move panel ---- */
.move-panel {
    display: none; align-items: center; gap: 6px; margin-top: 8px;
    padding: 6px 8px; background: var(--warning-bg); border-radius: var(--radius); border: 1px solid rgba(245,166,35,0.2);
}
.move-panel.visible { display: flex; }
.move-panel span { font-size: 12px; color: var(--warning); font-weight: 500; }
.move-panel .input { width: 60px; }

/* ---- Scrollbar ---- */
.panel-body::-webkit-scrollbar { width: 6px; }
.panel-body::-webkit-scrollbar-track { background: transparent; }
.panel-body::-webkit-scrollbar-thumb { background: var(--border-hi); border-radius: 3px; }
.panel-body::-webkit-scrollbar-thumb:hover { background: var(--text-faint); }

@media (max-width: 768px) {
    .app-container { flex-direction: column; }
    .col-20, .col-40 { flex: 1; max-width: 100%; }
}
    </style>
</head>
<body>

    <div class="topbar">
        <div class="brand"><span class="brand-glyph"><i class="bi bi-broadcast"></i></span> IPTV Editor</div>
        <div style="display:flex;align-items:center;gap:10px;">
            <span style="font-size:12px;color:var(--text-dim);">Playlist:</span>
            <select id="playlistSelect" class="input input--sm" style="width:120px;"></select>
            <button class="btn btn--sm" type="button" onclick="app.addNewPlaylistId()" title="Создать новый">+</button>
        </div>
    </div>

    <div id="unsavedAlert">
        <div class="unsaved-inner">
            <div class="unsaved-text"><i class="bi bi-pencil-fill"></i> Есть несохраненные изменения!</div>
            <div class="unsaved-actions">
                <button class="btn btn--success btn--sm" onclick="app.saveChanges()">Сохранить</button>
                <button class="btn btn--sm" onclick="app.revertChanges()">Отмена</button>
            </div>
        </div>
    </div>

    <div class="app-container">

        <!-- LEFT: Groups -->
        <div class="col-panel col-20">
            <div class="panel-head">
                <h6>Группы</h6>
                <button id="btnCreateGroup" class="btn btn--primary btn--sm" style="width:100%;margin-bottom:8px;" onclick="app.createGroupLocal()" disabled>
                    <i class="bi bi-plus-lg"></i> Новая группа
                </button>
                <input type="text" id="groupSearch" class="input input--sm" style="width:100%;" placeholder="Фильтр...">
            </div>
            <div id="groupsList" class="panel-body"></div>
        </div>

        <!-- CENTER: Active group -->
        <div class="col-panel col-40">
            <div class="panel-head">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                    <div>
                        <h6 id="activeGroupName" style="margin-bottom:0;">Группа не выбрана</h6>
                        <small style="color:var(--text-faint);font-size:11px;" id="activeGroupCount"></small>
                    </div>
                    <button id="btnDeleteGroup" class="btn btn--danger btn--sm" style="display:none;" onclick="app.deleteGroupLocal()">
                        <i class="bi bi-trash"></i> Удалить
                    </button>
                </div>

                <div style="display:flex;gap:8px;margin-bottom:8px;">
                    <input type="text" id="activeGroupSearch" class="input input--sm" style="flex:1;" placeholder="Найти в группе..." oninput="app.renderActiveGroup()">
                    <div style="display:flex;align-items:center;gap:4px;" title="Куда вставлять новые каналы">
                        <span style="font-size:11px;color:var(--text-faint);white-space:nowrap;">Вставка:</span>
                        <input type="number" id="insertPosInput" class="input input--sm" style="width:60px;text-align:center;font-weight:600;" placeholder="End" oninput="app.updateButtons()">
                    </div>
                </div>

                <div id="manualMovePanel" class="move-panel">
                    <span>Move (<b id="sortSelCount">0</b>) to:</span>
                    <input type="number" id="moveToPosInput" class="input input--sm" style="width:60px;" placeholder="№" onkeydown="if(event.key==='Enter') app.moveSelectedChannelsByInput()">
                    <button class="btn btn--sm" onclick="app.moveSelectedChannelsByInput()">OK</button>
                    <button class="btn btn--danger btn--sm" onclick="app.clearSortSelection()"><i class="bi bi-x-lg"></i></button>
                </div>
            </div>

            <div id="activeGroupContent" class="panel-body" style="padding:8px;">
                <div style="text-align:center;color:var(--text-faint);margin-top:40px;">Выберите группу слева</div>
            </div>
        </div>

        <!-- RIGHT: Source channels -->
        <div class="col-panel col-40">
            <div class="panel-head">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                    <h6 style="margin-bottom:0;">Источник</h6>
                    <div class="sort-toggle">
                        <input type="radio" name="sortSrc" id="sortName" checked onchange="app.setSort('name')">
                        <label for="sortName">Имя</label>
                        <input type="radio" name="sortSrc" id="sortId" onchange="app.setSort('id')">
                        <label for="sortId">ID</label>
                    </div>
                </div>

                <div style="display:flex;gap:8px;margin-bottom:8px;">
                    <input type="text" id="channelSearch" class="input input--sm" style="flex:1;" placeholder="Поиск каналов...">
                    <button class="btn btn--success btn--sm" onclick="app.openChannelModal()" title="Добавить в базу"><i class="bi bi-plus-lg"></i></button>
                </div>

                <div style="display:flex;gap:6px;">
                    <button class="btn btn--primary btn--sm" style="flex:1;" id="btnAddSelected" disabled onclick="app.addSelectedToCurrent()">
                        <i class="bi bi-arrow-left"></i> <span id="btnAddText">Добавить</span> (<span id="selCount">0</span>)
                    </button>
                    <button class="btn btn--sm" style="flex:1;" id="btnCreateFromSelected" disabled onclick="app.createGroupFromSelectedLocal()">
                        <i class="bi bi-folder-plus"></i> Группа
                    </button>
                </div>
            </div>
            <div id="allChannelsList" class="panel-body"></div>
        </div>

    </div>

    <div id="channelModal" class="modal-overlay">
        <div class="uman-modal">
            <div class="modal-header">
                <span style="font-size:15px;">Канал</span>
                <button class="close-btn" onclick="document.getElementById('channelModal').classList.remove('active')">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="chEditId">

                <div class="form-group">
                    <label>ID канала</label>
                    <div style="display:flex;gap:6px;align-items:center;">
                        <input type="number" id="chCustomId" class="input" style="flex:1;" placeholder="Авто (если пусто)">
                        <div class="free-ids-dropdown">
                            <button class="btn btn--sm" type="button" onclick="this.nextElementSibling.classList.toggle('show')">Свободные ID</button>
                            <div id="freeIdsDropdown" class="free-ids-list"></div>
                        </div>
                    </div>
                    <div class="form-hint" id="idHelpText">Оставьте пустым для авто-назначения.</div>
                </div>

                <div class="form-group">
                    <label>Название</label>
                    <input type="text" id="chName" class="input">
                </div>
                <div class="form-row-2">
                    <div class="form-group">
                        <label>Архив (дней)</label>
                        <input type="number" id="chRec" class="input">
                    </div>
                    <div class="form-group">
                        <label>Качество</label>
                        <select id="chRes" class="input"><option>SD</option><option>HD</option><option>FHD</option><option>4K</option></select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn" onclick="document.getElementById('channelModal').classList.remove('active')">Отмена</button>
                <button class="btn btn--primary" onclick="app.saveChannelDB()">Сохранить в БД</button>
            </div>
        </div>
    </div>
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
                    
                    btn.className = `group-item group-draggable ${isSelected ? 'active' : ''}`;
                    btn.draggable = true;
                    btn.dataset.idx = idx; 
                    
                    btn.innerHTML = `
                        <div style="display:flex;align-items:center;gap:6px;flex:1;min-width:0;pointer-events:none;">
                            <i class="bi bi-grip-vertical" style="opacity:0.4;"></i>
                            <span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${g.name}</span>
                            <i class="bi bi-pencil-square group-edit-btn" style="pointer-events:auto;" onclick="app.renameGroupLocal(event, ${idx})"></i>
                        </div>
                        <span class="group-badge">${count}</span>
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
                    btnDel.style.display = 'none';
                    movePanel.classList.remove('visible');
                    container.innerHTML = '<div style="text-align:center;color:var(--text-faint);margin-top:40px;">Выберите группу слева</div>';
                    return;
                }

                btnDel.style.display = '';
                if (selCount > 1) {
                    title.innerHTML = `Выбрано групп: ${selCount} <br><small style="color:var(--text-faint);font-size:11px;">Просмотр: ${this.state.currentGroup ? this.state.currentGroup.name : '...'}</small>`;
                } else {
                    title.textContent = this.state.currentGroup.name;
                }

                if (!this.state.currentGroup) { container.innerHTML = ''; return; }

                const ids = this.state.currentGroup.channel_ids || [];
                countInfo.textContent = `${ids.length} к.`;
                container.innerHTML = '';

                if (this.state.activeGroupSelection.size > 0) {
                    movePanel.classList.add('visible');
                    sortSelCount.textContent = this.state.activeGroupSelection.size;
                } else {
                    movePanel.classList.remove('visible');
                }

                const currentInsertPos = insertInput.value ? parseInt(insertInput.value) : null;

                ids.forEach((cid, idx) => {
                    const ch = this.state.channelsMap[cid];
                    if (searchVal && (!ch || !ch.name.toLowerCase().includes(searchVal))) return;

                    const div = document.createElement('div');
                    
                    const isSelected = this.state.activeGroupSelection.has(idx);
                    const isInsertTarget = currentInsertPos !== null && (currentInsertPos - 1) === idx;

                    div.className = `draggable-item ${isSelected ? 'sort-selected' : ''} ${isInsertTarget ? 'insert-target' : ''}`;
                    div.draggable = !searchVal; 
                    div.dataset.idx = idx; 
                    
                    // Клик -> Новая функция обработки
                    div.onclick = (e) => {
                        if(e.target.closest('button')) return;
                        this.handleActiveChannelClick(idx, e);
                    };

                    let inner = ch 
                        ? `<i class="bi bi-grip-vertical" style="opacity:0.4;"></i> <strong>${idx+1}.</strong> ${ch.name} <small style="color:var(--text-faint);margin-left:4px;">${ch.resolution}</small>`
                        : `<span style="color:var(--danger);">ID:${cid} (Нет в БД)</span>`;

                    div.innerHTML = `
                        <div class="ch-card">
                            <div class="ch-info">${inner}</div>
                            <button class="ch-remove" onclick="app.removeChannelLocal(${idx})"><i class="bi bi-x-lg"></i></button>
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
                    div.className = `src-item ${isSel ? 'selected-highlight' : ''} ${inGroup ? 'in-group-highlight' : ''}`;
                    div.innerHTML = `
                        <input type="checkbox" class="src-check" ${isSel ? 'checked' : ''}>
                        <div class="src-info">
                            <div class="src-name">${c.name}</div>
                            <div class="src-meta">ID:${c.id} | ${c.rec}d | ${c.resolution}</div>
                        </div>
                        <div class="src-actions">
                            <button class="edit-btn" onclick="app.openChannelModal(${c.id})"><i class="bi bi-pencil"></i></button>
                            <button class="del-btn" onclick="app.deleteChannelDB(${c.id})"><i class="bi bi-trash"></i></button>
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
                
                document.getElementById('channelModal').classList.add('active');
            },

            loadFreeIds: async function() {
                const ul = document.getElementById('freeIdsDropdown');
                ul.innerHTML = '<div class="fid-info">Загрузка...</div>';
                
                try {
                    const res = await fetch('api.php?action=get_free_ids');
                    const ids = await res.json();
                    
                    ul.innerHTML = '';
                    if (ids.length === 0) {
                        ul.innerHTML = '<div class="fid-info">Нет свободных ID в диапазоне</div>';
                        return;
                    }
                    
                    ids.forEach(id => {
                        const item = document.createElement('div');
                        item.className = 'fid-item';
                        item.textContent = id;
                        item.onclick = () => {
                            document.getElementById('chCustomId').value = id;
                            ul.classList.remove('show');
                        };
                        ul.appendChild(item);
                    });
                } catch (e) {
                    ul.innerHTML = '<div class="fid-info" style="color:var(--danger);">Ошибка загрузки</div>';
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
                    document.getElementById('channelModal').classList.remove('active');
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

        document.addEventListener('DOMContentLoaded', () => {
            app.init();
            document.getElementById('channelModal').addEventListener('click', function(e) {
                if (e.target === this) this.classList.remove('active');
            });
        });
    </script>
</body>
</html>