/* ============================================================================
 * js/net.js — мониторинг доступности сети.
 *
 * Что делает:
 *   - слушает события `online`/`offline` у window;
 *   - при потере связи показывает модалку с сообщением (EN/RU через i18n);
 *   - при восстановлении связи — короткая модалка-тост с подтверждением;
 *   - дополнительно периодически проверяет связность лёгким fetch'ем,
 *     если браузер не сгенерировал событие (известная редкая ситуация).
 *
 * Не модифицирует никакие существующие функции; работает поверх DOM.
 * ============================================================================ */
(function (global) {
  'use strict';

  var DOC = document;
  var I18N = global.MpplI18n;

  var PING_URL = 'islocal.php';     // лёгкий PHP, отдаёт быстрый ответ
  var PING_INTERVAL_MS = 30000;     // фоновая проверка раз в 30 c
  var PING_TIMEOUT_MS = 5000;
  var RESTORED_TOAST_MS = 2200;

  var state = {
    online: typeof navigator !== 'undefined' ? !!navigator.onLine : true,
    pinging: false,
    timer: null,
    overlay: null,
    toast: null
  };

  function t(key, fallback) {
    return (I18N && typeof I18N.t === 'function') ? I18N.t(key) : (fallback || key);
  }

  function buildOverlay() {
    if (state.overlay) return state.overlay;

    var ov = DOC.createElement('div');
    ov.className = 'net-modal';
    ov.setAttribute('role', 'alertdialog');
    ov.setAttribute('aria-live', 'assertive');
    ov.innerHTML =
      '<div class="net-modal__box">' +
        '<div class="net-modal__title" data-i18n="net.offline_title">Нет соединения с сетью</div>' +
        '<div class="net-modal__msg"   data-i18n="net.offline_msg">Проверьте подключение. Мы автоматически продолжим, как только связь восстановится.</div>' +
        '<div class="net-modal__spinner" aria-hidden="true"></div>' +
        '<button type="button" class="net-modal__btn" data-net-retry data-i18n="net.retry">Проверить ещё раз</button>' +
      '</div>';
    DOC.body.appendChild(ov);

    ov.querySelector('[data-net-retry]').addEventListener('click', function () { ping(true); });

    state.overlay = ov;
    if (I18N && typeof I18N.applyTo === 'function') I18N.applyTo(ov);
    return ov;
  }

  function buildToast() {
    if (state.toast) return state.toast;
    var el = DOC.createElement('div');
    el.className = 'net-toast';
    el.innerHTML =
      '<span class="net-toast__dot" aria-hidden="true"></span>' +
      '<span class="net-toast__msg" data-i18n="net.restored_msg">Соединение восстановлено</span>';
    DOC.body.appendChild(el);
    state.toast = el;
    if (I18N && typeof I18N.applyTo === 'function') I18N.applyTo(el);
    return el;
  }

  function showOffline() {
    if (state.online) return;
    var ov = buildOverlay();
    ov.classList.add('is-active');
  }

  function hideOffline() {
    if (state.overlay) state.overlay.classList.remove('is-active');
  }

  function flashRestored() {
    var el = buildToast();
    el.classList.add('is-active');
    setTimeout(function () { el.classList.remove('is-active'); }, RESTORED_TOAST_MS);
  }

  function setOnline(value, opts) {
    var prev = state.online;
    state.online = !!value;
    if (state.online === prev) return;

    if (state.online) {
      hideOffline();
      if (!(opts && opts.silent)) flashRestored();
      DOC.dispatchEvent(new CustomEvent('mppl:netonline'));
    } else {
      showOffline();
      DOC.dispatchEvent(new CustomEvent('mppl:netoffline'));
    }
  }

  function ping(force) {
    if (state.pinging && !force) return;
    state.pinging = true;

    var done = false;
    var timer = setTimeout(function () {
      if (done) return;
      done = true;
      state.pinging = false;
      setOnline(false);
    }, PING_TIMEOUT_MS);

    var url = PING_URL + (PING_URL.indexOf('?') === -1 ? '?' : '&') + 'ts=' + Date.now();
    fetch(url, { method: 'HEAD', cache: 'no-store', credentials: 'same-origin' })
      .then(function (r) {
        if (done) return;
        done = true;
        clearTimeout(timer);
        state.pinging = false;
        // 401 — это всё ещё «сеть жива», просто сессия истекла
        setOnline(r && r.status > 0);
      })
      .catch(function () {
        if (done) return;
        done = true;
        clearTimeout(timer);
        state.pinging = false;
        setOnline(false);
      });
  }

  function startMonitor() {
    global.addEventListener('online',  function () { setOnline(true); });
    global.addEventListener('offline', function () { setOnline(false); });

    // Если грузимся уже офлайн — сразу показать.
    if (typeof navigator !== 'undefined' && navigator.onLine === false) {
      state.online = false;
      showOffline();
    }

    if (state.timer) clearInterval(state.timer);
    state.timer = setInterval(function () {
      // Пингуем, только если по флагу мы офлайн — лишний трафик не нужен.
      if (!state.online) ping(false);
    }, PING_INTERVAL_MS);
  }

  // Перевод обновляется без перерисовки модалок — просто перевешиваем тексты.
  DOC.addEventListener('mppl:langchange', function () {
    if (state.overlay && I18N && typeof I18N.applyTo === 'function') I18N.applyTo(state.overlay);
    if (state.toast   && I18N && typeof I18N.applyTo === 'function') I18N.applyTo(state.toast);
  });

  if (DOC.readyState === 'loading') {
    DOC.addEventListener('DOMContentLoaded', startMonitor);
  } else {
    startMonitor();
  }

  global.MpplNet = {
    isOnline: function () { return state.online; },
    forcePing: function () { ping(true); },
    showOffline: showOffline,
    hideOffline: hideOffline
  };
})(window);
