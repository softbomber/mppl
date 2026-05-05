function getuserAdmin(data, infoHtml, mcHtml) {
    const session = data.data.session;
    const account = data.data.account;
    const dealer = data.data.dealer;
    const iptv = data.data.iptv || {};
    const iptvAccount = iptv.account || {};
    let selectedId = null;
    let t; // Для таймера #dlr
    let adminInfoHtml='';

            // Функция отправки AJAX запроса
            // Если скрипт не выполняется, перенесите эту функцию в ваш основной JS файл
            function saveLocal(id, val) 
            {
              
                // Используем fetch для отправки
                // ЗАМЕНИТЕ 'path/to/update_handler.php' на реальный путь к вашему обработчику
                fetch('islocal.php', { 
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    // Отправляем ID и новое значение (0 или 1)
                    body: 'action=update_islocal&id=' + id + '&val=' + val
                })
                .then(response => {
                    if (!response.ok) throw new Error('Ошибка сети');
                    return response.text(); // или .json(), если сервер возвращает JSON
                })
                .then(data => {
                    console.log('Успешно сохранено:', data);
                    // Здесь можно добавить уведомление об успехе
                })
                .catch(error => {
                    console.error('Ошибка сохранения:', error);
                    alert('Не удалось сохранить изменения в базе!');
                });
            }

    if (session.a == 1) {
        adminInfoHtml = `<tr><td align=right><input type=hidden name="dlrid" value="${dealer.id}">Дилер:</td><td style="width:250px"><b><span id="dlr">${dealer.user}</span><select id="list" style="display:none;font-size:8pt"></select><button id="ok" style="display:none;font-size:4pt">OK</button></b></td></tr>`;
        if(iptv.account)
        {
        adminInfoHtml += `${iptv.account && iptv.account.twin>0
                ? '<tr '
                : '<tr style="display:none" ' 
                } 
                id="showTw"><td align=right><input type=hidden name="twnid" value="${iptv.account.iptvtwin || ''}">StickedTo:</td><td style="width:250px">
                <div style="display: flex; align-items: center;"><div id="twinusr">${iptv.account.b_user || ''}</div>
                <button id="reset-btn" style="background: none; border: none; cursor: pointer; color: red; font-size:14px">✖</button></div></td></tr>
                
                ${iptv.account && iptv.account.c_users && iptv.account.c_users.length > 0 
                    ? iptv.account.c_users.map((c_user, index) => `
                        <tr>
                            <td align="right">Sticker:</td>
                            <td style="width:250px">
                                <div style="display:flex;align-items:center;height:17px">
                                    <div id="twined_${index}">${c_user}</div>
                                    <button id="reset-btn_${index}" style="background: none; border: none; cursor: pointer; color: red; font-size:14px">✖</button>
                                </div>
                            </td>
                        </tr>
                    `).join('')
                    : ''}`

        if (iptv.agent_data && iptv.agent_data.length > 0 || (Object.keys(iptv).length > 0)) {
            adminInfoHtml += `<tr>
                    <td align="right">
                        <input type="hidden" name="agentid" value="${account.twin || ''}">
                        Agent:
                    </td>
                    <td>
                        <div class="custom-select">
                            ${iptv.agent_data.map(agent => {
                                let aTxt = agent.agent;
                                let fbackAgnts = {};
                                let i = agent.date;
                                let fd = i.replace(/^(\d{4})-(\d{2})-(\d{2}) (\d{2}:\d{2}):\d{2}$/, (_, y, m, d, t) => `${d}.${m}.${y.slice(2)} ${t}`);
                                if (aTxt.length > 20) {
                                    //agentText = parseUserAgent(agentText);
                                    aTxt = getFingerprint(aTxt, fbackAgnts)
                                }
                                return `
                                    <div class="custom-option">
                                        ${aTxt}<br>D:${fd}${agent.prov ? `<br>Prv:${agent.prov}` : ''}${agent.ip ? `<br>IP:${provFromIp(agent.ip)}` : ''}
                                    </div>
                                `;
                            }).join('')}
                        </div>
                    </td>
                </tr>
            `;
            const twinHtml = `
                <button id="openBtn">TWIN</button>
                <div id="uLstCntnr">
                    <div id="uList"></div>
                    <button id="sendBtn" style="display:none">STICK IT</button>
                </div>
                <div id="twin"></div>
            `;
            infoHtml = infoHtml.replace('</div>', twinHtml + '</div>');
            // Админский фрагмент для mcHtml (спойлер)
            if (data.data.iptv && iptvAccount.iptvkey && iptvAccount.iptvsdom) {
                    const iptvurl = iptv.iptvurl || '';
                    let adminMcHtml = `<style>
            .switch { position: relative; display: inline-block; width: 40px; height: 20px; vertical-align: middle; margin-left: 10px; }
            .switch input { opacity: 0; width: 0; height: 0; }
            .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #ccc; transition: .4s; border-radius: 34px; }
            .slider:before { position: absolute; content: ""; height: 14px; width: 14px; left: 3px; bottom: 3px; background-color: white; transition: .4s; border-radius: 50%; }
            input:checked + .slider { background-color: #2196F3; }
            input:focus + .slider { box-shadow: 0 0 1px #2196F3; }
            input:checked + .slider:before { transform: translateX(20px); }
            .iptv.switch-row { display: flex; align-items: center; margin-top: 5px; justify-content: space-between; width: 300px;}
        </style>
                        <div class="spoiler">
                            <input type="checkbox" id="spoiler-toggle">
                            <label for="spoiler-toggle">+</label>
                            <div class="spoiler-content">
                                <div class="iptv cdn">КЛЮЧ ДОСТУПА <input id="key" type="text" value="${iptvAccount.iptvkey}"/>
                                    <div class="iptvbtn"><button id="changekey">СМЕНИТЬ</button></div>
                                </div>
                                <div class="iptv dom">Субдомен [DOM] <input id="dom" type="text" value="${iptvAccount.iptvsdom}"/>
                                    <div class="iptvbtn"><button id="savedom" disabled=disabled>СОХРАНИТЬ</button></div>
                                </div>
                                <div class="iptv token">[token] <input id="token" type="text" value="${iptvAccount.token}"/>
                                    <div class="iptvbtn"><button id="savetoken" disabled=disabled>СОХРАНИТЬ</button></div>
                                </div>
                            </div>
                <div class="iptv switch-row">
                    <span>Локальный</span> 
                    <label class="switch">
                        <input type="checkbox" id="isLocalSwitch" data-user="${iptvAccount.user}" ${iptvAccount.islocal == 1 ? 'checked' : ''} >
                        <span class="slider"></span>
                    </label>
                </div>
                        </div>
                    `;

                 mcHtml = mcHtml.replace(/<div class="iptv url">([\s\S]*?)<\/div>/, match => match + adminMcHtml);
                }
            }

            $("#result").off("changed").on("change", "#isLocalSwitch", function() {
    const user = $(this).data('user'); // Получаем user из data-атрибута
    const isChecked = $(this).is(':checked') ? 1 : 0;
    
    // Вызываем вашу локальную функцию
    saveLocal(user, isChecked);
});
                $("#uinfo").on("click", "#openBtn", function() {
                    fetchData();
                });
        
                /*function fetchData() {
                    fetch('get_user_data.php')
                        .then(response => {
                            if (!response.ok) {
                                throw new Error('Network response was not ok');
                            }
                            return response.json();
                        })
                        .then(data => {
                            const uLstCntnr = document.getElementById('uLstCntnr');
                            const userList = document.getElementById('uList');
                            const sendBtn = document.getElementById('sendBtn');
                            userList.innerHTML = '';
        
                            data.forEach(item => {
                                if (item.twin == 0 || item.twin == null) {
                                    const div = document.createElement('div');
                                    div.className = 'list-item';
        
                                    const date = new Date(item.iptvactdate * 1000);
                                    const day = String(date.getUTCDate()).padStart(2, '0');
                                    const month = String(date.getUTCMonth() + 1).padStart(2, '0');
        
                                    const dateAndMonths = document.createElement('div');
                                    dateAndMonths.textContent = day + '.' + month + '-' + item.iptvmonths.split(':')[0];
                                    div.appendChild(dateAndMonths);
        
                                    const userElement = document.createElement('div');
                                    userElement.className = 'user-element';
                                    userElement.textContent = item.user;
                                    div.appendChild(userElement);
        
                                    if (parseInt(item.twin_exists) === 1) {
                                        const greenDot = document.createElement('span');
                                        greenDot.className = 'green-dot';
                                        greenDot.style.cssText = `
                                            display: inline-block;
                                            width: 8px;
                                            height: 8px;
                                            background-color: green;
                                            border-radius: 50%;
                                            margin-left: 5px;
                                        `;
                                        userElement.appendChild(greenDot);
                                    }
        
                                    div.dataset.id = item.id;
                                    div.dataset.user = item.user;
                                    div.onclick = function() {selectItem(this);};
                                    userList.appendChild(div);
                                }
                            });
                            uLstCntnr.style.display = 'block';
                            sendBtn.style.display = 'block';
                        })
                        .catch(error => console.error('Error fetching data:', error));
                }*/
                        function fetchData() {
                            fetch('get_user_data.php')
                                .then(response => {
                                    if (!response.ok) {
                                        throw new Error('Network response was not ok');
                                    }
                                    return response.json();
                                })
                                .then(data => {
                                    const uLstCntnr = document.getElementById('uLstCntnr');
                                    const userList = document.getElementById('uList');
                                    const sendBtn = document.getElementById('sendBtn');
                                    userList.innerHTML = '';
                        
                                    data.forEach(item => {
                                        if (item.twin == 0 || item.twin == null) {
                                            const div = document.createElement('div');
                                            div.className = 'list-item';
                                            div.dataset.id = item.id;
                                            div.dataset.user = item.user;
                        
                                            const date = new Date(item.iptvactdate * 1000);
                                            const day = String(date.getUTCDate()).padStart(2, '0');
                                            const month = String(date.getUTCMonth() + 1).padStart(2, '0');
                        
                                            const dateAndMonths = document.createElement('div');
                                            dateAndMonths.textContent = day + '.' + month + '-' + item.iptvmonths.split(':')[0];
                                            div.appendChild(dateAndMonths);
                        
                                            const userElement = document.createElement('div');
                                            userElement.className = 'user-element';
                                            userElement.textContent = item.user;
                                            div.appendChild(userElement);
                        
                                            if (parseInt(item.twin_exists) === 1) {
                                                const greenDot = document.createElement('span');
                                                greenDot.className = 'green-dot';
                                                greenDot.style.cssText = `
                                                    display: inline-block;
                                                    width: 8px;
                                                    height: 8px;
                                                    background-color: green;
                                                    border-radius: 50%;
                                                    margin-left: 5px;
                                                `;
                                                userElement.appendChild(greenDot);
                                            }
                        
                                            userList.appendChild(div);
                                        }
                                    });
                        
                                    // Делегирование события click для .list-item
                                    userList.onclick = function(event) {
                                        const target = event.target.closest('.list-item');
                                        if (target) {
                                            selectItem(target);
                                        }
                                    };
                        
                                    uLstCntnr.style.display = 'block';
                                    sendBtn.style.display = 'block';
                                })
                                .catch(error => console.error('Error fetching data:', error));
                        }
        
                function updateL(label) {
                    let tI = document.querySelector('input[name="twnid"]');
                    if (tI) {
                        let tN = tI.nextSibling;
                        if (tN && tN.nodeType === 3) {
                            tN.textContent = label;
                        }
                    }
                }


/*function domKeyCh()
{
	$('#dom').on('change', function () {
		var s = $(this);
		var b = $('#savedom');
		b.prop('disabled', s.val() === null || s.val() === '');
		});
		$('#savedom').on('click', function () {
        $(this).addClass('wave-effect');
		var V = $('#dom').val();
		var button = $(this);
		$.ajax({ type: 'POST',url: "cdc.php",cache:0,dataType:"json",async:false,
		data: { dom: V,u: $('#uname').html()},
		success: function (d) {
		hMsg.dMsg(d['m']);
		},
		error: function () {
		console.error('Ошибка при отправке запроса на сервер');
		},
        complete: function() {
            $(this).removeClass('wave-effect');
        }
		});
		});
}*/
                function selectItem(element) {
                    console.log('selectItem called:', element.dataset.id, element.dataset.user);
                    const items = document.querySelectorAll('.list-item');
                    items.forEach(item => item.classList.remove('selected-item'));
                    element.classList.add('selected-item');
                    selectedId = element.dataset.id;
                    const selectedUser = element.dataset.user;
                    console.log('selectedId set to:', selectedId);
                    document.querySelector('input[name="twnid"]').value = selectedId;
                    document.getElementById('twinusr').textContent = selectedUser;
                    updateL('NotSticked:');
                    document.getElementById('showTw').style.display = 'table-row';
                }

                $("#result").on("click", "#changekey", function() {
                    $(this).addClass('wave-effect');
                    $.ajax({
                        type: 'POST',
                        url: 'cdc.php',
                        cache: false,
                        dataType: 'json',
                        async: false,
                        data: { k: $('#key').val(), u: $('#uname').html() },
                        success: function(data) {
                            hMsg.dMsg(data['m']);
                            $('#key').val(data['k']);
                        },
                        error: function() {
                            $(this).removeClass('wave-effect');
                            console.error('Ошибка при отправке запроса на сервер');
                        },
                        complete: function() {
                            $(this).removeClass('wave-effect');
                        }
                    });
                });
        
                // Привязка события для #dom
                $("#result").on("input", "#dom", function(event) {
                    let newValue = '';
                    for (let i = 0; i < this.value.length; i++) {
                        let charCode = this.value.charCodeAt(i);
                        if ((charCode >= 65 && charCode <= 90) || (charCode >= 97 && charCode <= 122) || (charCode >= 48 && charCode <= 57)) {
                            newValue += this.value.charAt(i);
                        } else {
                            let notification = document.getElementById('notification');
                            if (notification) {
                                notification.textContent = 'Пожалуйста, используйте латиницу и цифры';
                                setTimeout(() => { notification.textContent = ''; }, 3000);
                            }
                        }
                    }
                    if (newValue.length > 150) {
                        newValue = newValue.slice(0, 150);
                    }
                    this.value = newValue;
                });

                     //                function domKeyCh()
          //{
                $("#result").on("change", "#dom", function (event) {
                    var select = $(this);
                    var button = $('#savedom');
                    button.prop('disabled', select.val() === null || select.val() === '');
                    });
                $("#result").off('click', '#savedom').on('click', '#savedom', function () {
                    var Value = $('#dom').val();
                    var button = $(this);
                    $.ajax({ type: 'POST',url: "cdc.php",cache:0,dataType:"json",async:false,
                    data: { dom: Value ,u: $('#uname').html()},
                    success: function (data) {
                    hMsg.dMsg(data['m']);
                    },
                    error: function () {
                    console.error('Ошибка при отправке запроса на сервер');
                    }
                    });
                    });
            //}    

    
        function parseUserAgent(userAgent) {
            const parsedData = {};
            const modelMatch = userAgent.match(/Model\/([\w\-\.]+) VIDAA\/([\d\.]+)\s*\(([^;]+);([^;]+);([^;]+);(.*?)\)(.*)/);
            if (modelMatch) {
                parsedData['Model'] = modelMatch[1];
                parsedData['OS Ver'] = modelMatch[2];
                parsedData['Brand'] = modelMatch[3];
                parsedData['DevType'] = modelMatch[4];
                parsedData['TVModel'] = modelMatch[5];
                parsedData['CPU&Soft Ver'] = modelMatch[6];
                parsedData['Other'] = modelMatch[7].trim().replace(/;$/, '');
            } else {
                const patterns = [
                    [/Mozilla\/5\.0 \((.*?)\) (.*)/, (matches) => ({ 'OS': matches[1], 'Browser': matches[2] })],
                    [/Dalvik\/([\d\.]+) \((.*?)\)/, (matches) => ({ 'Dalvik Ver': matches[1], 'OS': matches[2] })],
                    [/Wget\/([\d\.]+) \((.*?)\)/, (matches) => ({ 'Wget Ver': matches[1], 'OS': matches[2] })],
                    [/Player \((.*?)\)/, (matches) => ({ 'OS': matches[1], 'Player': 'Generic' })],
                    [/OTT TV\/([\d\.]+) \((.*?)\)/, (matches) => ({ 'OTT TV Ver': matches[1], 'OS': matches[2] })],
                    [/Mozilla\/5\.0 \((.*?)\) Android/, (matches) => ({ 'OS': matches[1], 'Type': 'Android Device' })],
                    [/(.*?) \((.*?)\)/, (matches) => ({ 'Generic Agent': matches[1], 'OS': matches[2] })]
                ];
                for (let [pattern, callback] of patterns) {
                    const matches = userAgent.match(pattern);
                    if (matches) {
                        Object.assign(parsedData, callback(matches));
                        break;
                    }
                }
            }
            if (Object.keys(parsedData).length > 0) {
                return Object.entries(parsedData).map(([key, value]) => `${key}: ${value}`).join('\n');
            }
            return "User-Agent не удалось распарсить.";
        }
        
        function getFingerprint(ua, fallbackAgents = {}) {
            ua = ua.replace(/;\s*wv\)/i, ')');
        
            const customPatterns = [
                [/Player/i, 'App: Televizo'],
                [/Web0S;\s*Linux\/SmartTV/i, 'SmartTV: LG WebOS'],
                [/Televizo/i, 'App: Televizo'],
                [/PerfectIPTV\/(\d+)/i, 'App: PerfectIPTV v$1'],
                [/WorldIPTV\/([\d\.]+)/i, 'App: WorldIPTV v$1'],
                [/IPTV%20Live\/(\d+)/i, 'App: IPTV Live v$1'],
                [/IPTVPlayer\/(\d+)/i, 'App: IPTV Player v$1'],
                [/IPTV%20Good%20Player\/(\d+)/i, 'App: IPTV Good Player v$1'],
                [/stream_player\/(\d+)/i, 'App: Stream Player v$1'],
                [/OttPlayeriOS\/(\d+)/i, 'App: Ott Player iOS v$1'],
                [/OTT\s+TV\/([\d\.]+)/i, 'App: OTT TV v$1'],
                [/OTT Player\/([\d\.]+)/i, 'App: OTT Player v$1'],
                [/OTT Navigator\/([\d\.]+)/i, 'App: OTT Navigator v$1'],
                [/IPTV%20%D0%BF%D0%BB%D0%B5%D0%B5%D1%80\/(\d+)/i, 'App: IPTV плеер v$1'],
                [/Go-http-client\/([\d\.]+)/i, 'App: Go-http-client v$1'],
                [/m3uIn\/([\d\.]+)/i, 'App: m3uIn v$1'],
                [/M3U IPTV App Android/i, 'App: M3U IPTV'],
                [/m3u-ip.tv\s+([\d\.]+)/i, 'App: m3u-ip.tv v$1'],
            ];
        
            for (const [pattern, label] of customPatterns) {
                const match = ua.match(pattern);
                if (match) {
                    return label.includes('$1') ? label.replace('$1', match[1]) : label;
                }
            }
        
            let match = ua.match(/Android[^;]*;\s*([^\;]+)\s+Build\/([^\)\;]+)/i);
            if (match) {
                const device = match[1].replace(/[^A-Za-z0-9]/g, '').toUpperCase();
                const build = match[2].trim();
                return `Android|${device}:${build}`;
            }
        
            match = ua.match(/(iPhone|iPad).*?OS\s+([\d_]+)/i);
            if (match) {
                return `iOS: ${match[1]}:${match[2].replace(/_/g, '.')}`;
            }
        
            if (ua.toUpperCase().includes('SMART-TV')) {
                match = ua.match(/Tizen[^\d]*([\d\.]+)/i);
                if (match) return `SmartTV: Samsung Tizen ${match[1]}`;
            }
        
            match = ua.match(/NetCast\.TV.*?\/([\d\.]+).*?\(([^,]+),\s*([^\)]+)/i);
            if (match) {
                return `SmartTV: LG NetCast.TV-${match[1]} ${match[3].trim()}`;
            }
        
            match = ua.match(/Windows NT ([\d\.]+)/i);
            if (match) {
                const version = match[1];
                let browser = 'Other';
                if (/Edge/i.test(ua)) browser = 'Edge';
                else if (/Chrome/i.test(ua)) browser = 'Chrome';
                else if (/Firefox/i.test(ua)) browser = 'Firefox';
                return `Windows|${version}|${browser}`;
            }
        
            match = ua.match(/X11; (\w+)/i);
            if (match) {
                return `Unix|${match[1]}`;
            }
        
            match = ua.match(/Android\s+\d+; [^;]+;\s*([^\s]+)\s+Build\/([^\s;]+).*?MIUI\/([^\s\)]+)/i);
            if (match) {
                return `Android: ${match[1]} MIUI ${match[3]}`;
            }
        
            // Fallback SHA-1
            const hash = sha1(ua).substring(0, 10);
            const fallback = 'Other: ' + hash;
            if (!fallbackAgents[fallback]) fallbackAgents[fallback] = [];
            fallbackAgents[fallback].push(ua);
            return fallback;
        }
        
        // Простая реализация SHA-1 на чистом JS
        function sha1(msg) {
            function rotl(n, s) { return n << s | n >>> (32 - s); }
            function toHex(i) { return (i >>> 0).toString(16).padStart(8, '0'); }
        
            let words = [], i;
            const msgUtf8 = unescape(encodeURIComponent(msg));
            for (i = 0; i < msgUtf8.length; i++) {
                words[i >> 2] |= msgUtf8.charCodeAt(i) << ((3 - i % 4) * 8);
            }
            words[i >> 2] |= 0x80 << ((3 - i % 4) * 8);
            words[((i + 8) >> 6 << 4) + 15] = msgUtf8.length * 8;
        
            let h0 = 0x67452301, h1 = 0xEFCDAB89, h2 = 0x98BADCFE, h3 = 0x10325476, h4 = 0xC3D2E1F0;
        
            for (let blockStart = 0; blockStart < words.length; blockStart += 16) {
                const w = words.slice(blockStart, blockStart + 16);
                for (i = 16; i < 80; i++) {
                    w[i] = rotl(w[i - 3] ^ w[i - 8] ^ w[i - 14] ^ w[i - 16], 1);
                }
        
                let a = h0, b = h1, c = h2, d = h3, e = h4;
                for (i = 0; i < 80; i++) {
                    const f = i < 20 ? (b & c | ~b & d) :
                              i < 40 ? (b ^ c ^ d) :
                              i < 60 ? (b & c | b & d | c & d) :
                                       (b ^ c ^ d);
                    const k = i < 20 ? 0x5A827999 :
                              i < 40 ? 0x6ED9EBA1 :
                              i < 60 ? 0x8F1BBCDC :
                                       0xCA62C1D6;
                    const temp = (rotl(a, 5) + f + e + k + w[i]) >>> 0;
                    e = d; d = c; c = rotl(b, 30) >>> 0; b = a; a = temp;
                }
        
                h0 = (h0 + a) >>> 0;
                h1 = (h1 + b) >>> 0;
                h2 = (h2 + c) >>> 0;
                h3 = (h3 + d) >>> 0;
                h4 = (h4 + e) >>> 0;
            }
        
            return toHex(h0) + toHex(h1) + toHex(h2) + toHex(h3) + toHex(h4);
        }
    }
            // Вставляем adminInfoHtml после строки "Баланс"
       const balanceRow = `<tr><td align=right id="accsum">Баланс:</td><td>${parseFloat(account.sum).toFixed(2)}</td></tr>`;
       infoHtml = infoHtml.replace(balanceRow, balanceRow + adminInfoHtml);
 
       function provFromIp(ip) {
            return ip; // Заглушка, замени на реальную реализацию
        }
        // Перенесённые функции и привязка событий
        $("#uinfo").on("click", "#sendBtn", function() {        
            //function sndDta() {
                if (!selectedId) {
                    alert('Please select an item first');
                    return;
                }
                fetch('send_data.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({ tw: dc.querySelector('input[name="twnid"]').value, id: dc.getElementById('uid').innerHTML })
                })
                .then(response => response.json())
                .then(data => {
                    hMsg.dMsg('Data sent successfully');
                    document.getElementById('uLstCntnr').style.display = 'none';
                    document.getElementById('sendBtn').style.display = 'none';
                    updateL('StickedTo:');
                })
                .catch(error => console.error('Error sending data:', error));
            });

        // Привязка событий для #dlr (из предыдущего кода)
        $("#uinfo").on("click", "#dlr", function() {
            let originalText = $(this).html();
            $("#dlr").hide();
            $("#i").remove();
            $("#l").remove();
            $("<input>").attr({ type: "text", id: "i", placeholder: "Введите имя дилера" }).insertAfter("#dlr");

            $("#uinfo").on("input", "#i", function() {
                let value = $(this).val();
                if (value.length < 4) { clearTimeout(t); return; }
                clearTimeout(t);
                t = setTimeout(() => {
                    $.ajax({
                        type: "GET",
                        url: "ajax_d.php",
                        data: { query: value },
                        cache: false,
                        success: function(response) {
                            $("#l").remove();
                            if (!response.length) return;
                            let searchDiv = $("<div>").attr({ id: "l" }).css({ width: "95%", marginTop: "5px" });
                            let ul = $("<ul>").css({ listStyle: "none", margin: 0, padding: 0, maxHeight: "150px", overflowY: "auto", border: "1px solid #ccc", borderRadius: "4px" });
                            response.forEach(d => {
                                $("<li>").text(d.name)
                                    .data("id", d.id)
                                    .css({ padding: "8px", cursor: "pointer", backgroundColor: "#fff" })
                                    .hover(
                                        function() { $(this).css("backgroundColor", "#f0f8ff"); },
                                        function() { $(this).css("backgroundColor", "#fff"); }
                                    )
                                    .appendTo(ul);
                            });
                            ul.appendTo(searchDiv);
                            searchDiv.insertAfter("#i");
                            ul.on("click", "li", function() {
                                handleSelection($(this).data("id"), $(this).text());
                            });
                        }
                    });
                }, 400);
            });

            $("#uinfo").on("keydown", "#i", function(e) {
                if (e.key === "Escape") {
                    $("#dlr").html(originalText).show();
                    $("#i").remove();
                    $("#l").remove();
                }
            });
        });

        function handleSelection(id, name) {
            $("#dlr").html(name).show();
            $("#ok").hide();
            $("#i").remove();
            $("#l").remove();
            $.ajax({
                type: "POST",
                url: "ajax_d.php",
                data: { did: id, uid: $("#uid").html() },
                cache: false,
                success: function() {
                    $("input[name=\"dlrid\"]").val(id);
                }
            });
        }
          $(document).ready(function() {
                 $('#reset-btn').on('click',function() {
                 $('input[name="twnid"]').val('');                         
                 $('#twinusr').text('');
                        
                 fetch('send_data.php',{method: 'POST',headers: {'Content-Type': 'application/json'},body: JSON.stringify({ 
                                        tw: 0,
                                        id: document.getElementById('uid').textContent.trim()})
                })
                .then(response => response.json())
                .then(data => {
                hMsg.dMsg('Data sent successfully');
                })
            .catch(error => console.error('Error sending data:', error));
            });
        });              
    }
    return { infoHtml, mcHtml };
}

