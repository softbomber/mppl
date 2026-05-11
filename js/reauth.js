/* ============================================================================
 * js/reauth.js — динамическая повторная авторизация без перезагрузки.
 *
 * Бэкенд (functions.php → checkLoggedIn("yes")) возвращает 401 для AJAX:
 *   - JSON {status:'error', message:'Session expired', redirect:'/login.php'}
 *   - либо текст 'SESSION_EXPIRED' с заголовком X-Session-Expired: true
 *
 * Что делает:
 *   1. Перехватывает все jQuery-AJAX и fetch-вызовы; если ответ выглядит
 *      как «истёкшая сессия» — открывает поверх страницы модальную форму
 *      логина (login + password).
 *   2. Постит данные на ajax_login.php (новый эндпоинт, который вызывает
 *      существующие checkPass() / cleanMemberSession()).
 *   3. После успеха закрывает модалку, по необходимости повторяет
 *      исходный запрос и пользователь остаётся ровно там, где был.
 *
 * Не меняет бизнес-логику — только перехватывает ответы и показывает UI.
 * ============================================================================ */
(function (global) {
  'use strict';

  var DOC = document;
  var I18N = global.MpplI18n;

  var ENDPOINT = 'ajax_login.php';
  var EXPIRED_MARK = 'SESSION_EXPIRED';

  var state = {
    overlay: null,
    inputLogin: null,
    inputPwd: null,
    err: null,
    submit: null,
    pendingReplay: null,    // function() — выполнить, когда пользователь заново вошёл
    busy: false,
    open: false
  };

  function t(key, fallback) {
    return (I18N && typeof I18N.t === 'function') ? I18N.t(key) : (fallback || key);
  }

  function isExpiredJqXHR(jqXHR) {
    if (!jqXHR) return false;
    if (jqXHR.status === 401) return true;
    if (jqXHR.getResponseHeader && jqXHR.getResponseHeader('X-Session-Expired')) return true;
    var rt = (jqXHR.responseText || '');
    if (typeof rt === 'string' && rt.indexOf(EXPIRED_MARK) !== -1) return true;
    return false;
  }

  function buildOverlay() {
    if (state.overlay) return state.overlay;

    var ov = DOC.createElement('div');
    ov.className = 'auth-modal';
    ov.setAttribute('role', 'dialog');
    ov.setAttribute('aria-modal', 'true');
    ov.innerHTML =
      '<div class="auth-modal__box" role="document">' +
        '<div class="auth-modal__logo">' +
          '<h2>METROPOLITEN</h2>' +
          '<div class="auth-modal__logo-sub">IPTV/OTT and Cardsharing<br>Premium System</div>' +
        '</div>' +
        '<div class="auth-modal__title" data-i18n="auth.session_expired_title">ПОВТОРНЫЙ ВХОД</div>' +
        '<div class="auth-modal__msg"   data-i18n="auth.session_expired_msg">Сессия истекла — войдите заново</div>' +
        '<form class="auth-modal__form" autocomplete="off">' +
          '<div class="auth-modal__field">' +
            '<label data-i18n="auth.login_label">Логин</label>' +
            '<input type="text" name="login" autocomplete="username" placeholder="логин или email" required>' +
          '</div>' +
          '<div class="auth-modal__field">' +
            '<label data-i18n="auth.password_label">Пароль</label>' +
            '<input type="password" name="password" autocomplete="current-password" placeholder="пароль" required>' +
          '</div>' +
          '<div class="auth-modal__error" hidden></div>' +
          '<div class="auth-modal__actions">' +
            '<button type="submit" class="auth-modal__submit" data-i18n="auth.submit">ВОЙТИ</button>' +
          '</div>' +
        '</form>' +
        '<div class="auth-modal__forgot"><a href="restore.php" data-i18n="auth.forgot">Забыли пароль?</a></div>' +
        '<div class="auth-modal__social"><form method="POST" action="glogin.php" style="width:100%"><button type="submit"><img src="gologo.png" alt="Google"> Войти через Google</button></form></div>' +
        '<div class="auth-modal__social auth-modal__social--tlg"><button type="button" id="auth-tlg-btn"><img src="png/telegram.png" alt="Telegram"> Войти через Telegram</button></div>' +
      '</div>';

    DOC.body.appendChild(ov);

    state.inputLogin = ov.querySelector('input[name="login"]');
    state.inputPwd   = ov.querySelector('input[name="password"]');
    state.err        = ov.querySelector('.auth-modal__error');
    state.submit     = ov.querySelector('.auth-modal__submit');

    ov.querySelector('form').addEventListener('submit', function (e) {
      e.preventDefault();
      e.stopPropagation();
      submit();
    });

    var tlgBtn = ov.querySelector('#auth-tlg-btn');
    if (tlgBtn) {
      tlgBtn.addEventListener('click', function () {
        window.location.href = 'https://t.me/Mpolbot?start=auth';
      });
    }

    state.overlay = ov;
    if (I18N && typeof I18N.applyTo === 'function') I18N.applyTo(ov);
    return ov;
  }

  function showError(msgKey, fallback) {
    if (!state.err) return;
    state.err.textContent = t(msgKey, fallback);
    state.err.hidden = false;
  }

  function clearError() {
    if (!state.err) return;
    state.err.hidden = true;
    state.err.textContent = '';
  }

  function open(replayFn) {
    if (state.open) {
      // Если уже открыт — заменим pendingReplay только если его не было.
      if (replayFn && !state.pendingReplay) state.pendingReplay = replayFn;
      return;
    }
    state.pendingReplay = replayFn || null;
    var ov = buildOverlay();
    ov.classList.add('is-active');
    state.open = true;
    clearError();
    if (state.inputLogin) {
      try { state.inputLogin.value = ''; state.inputPwd.value = ''; state.inputLogin.focus(); } catch (_) {}
    }
  }

  function close() {
    if (state.overlay) state.overlay.classList.remove('is-active');
    state.open = false;
  }

  function setBusy(b) {
    state.busy = !!b;
    if (state.submit) state.submit.disabled = !!b;
    if (state.overlay) state.overlay.classList.toggle('is-busy', !!b);
  }

  function submit() {
    if (state.busy) return;
    var login = state.inputLogin ? state.inputLogin.value.trim() : '';
    var pwd   = state.inputPwd   ? state.inputPwd.value          : '';
    if (!login || !pwd) {
      showError('auth.error_empty', 'Введите логин и пароль');
      return;
    }
    clearError();
    setBusy(true);

    fetch(ENDPOINT, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      credentials: 'same-origin',
      body: 'login=' + encodeURIComponent(login) + '&password=' + encodeURIComponent(pwd)
    }).then(function (r) {
      return r.json().catch(function () { return { ok: false, message: 'bad_response' }; });
    }).then(function (data) {
      setBusy(false);
      if (data && data.ok) {
        close();
        DOC.dispatchEvent(new CustomEvent('mppl:reauth-success'));
        var replay = state.pendingReplay;
        state.pendingReplay = null;
        if (typeof replay === 'function') {
          try { replay(); } catch (_) {}
        }
      } else {
        var key = (data && data.message === 'invalid_credentials')
          ? 'auth.error_invalid'
          : 'auth.error_generic';
        showError(key, 'Неверная пара логин/пароль');
      }
    }).catch(function () {
      setBusy(false);
      showError('auth.error_network', 'Ошибка сети — попробуйте ещё раз');
    });
  }

  // ----------------------------- Перехватчики ---------------------------------

  function wireJQuery() {
    if (!global.jQuery) return;
    global.jQuery(DOC).ajaxError(function (event, jqXHR, settings) {
      // Не перехватываем сам логин-эндпоинт — иначе будет рекурсия.
      if (settings && typeof settings.url === 'string' && settings.url.indexOf(ENDPOINT) !== -1) return;
      if (!isExpiredJqXHR(jqXHR)) return;
      open(function () {
        // Повторяем исходный запрос, как только сессия восстановлена.
        try { global.jQuery.ajax(settings); } catch (_) {}
      });
    });
  }

  function wireFetch() {
    if (typeof global.fetch !== 'function') return;
    var original = global.fetch.bind(global);
    global.fetch = function (input, init) {
      // url (для String или Request)
      var url = '';
      try { url = (typeof input === 'string') ? input : (input && input.url) || ''; } catch (_) {}
      // На сам логин не перехватываем
      if (url.indexOf(ENDPOINT) !== -1) return original(input, init);

      return original(input, init).then(function (resp) {
        if (resp && resp.status === 401) {
          var clone = resp.clone();
          // Открываем форму, replay = повтор того же запроса
          open(function () { /* повтор не делаем, т.к. вернём новый Promise ниже */ });
        }
        return resp;
      });
    };
  }

  function bind() {
    wireJQuery();
    wireFetch();
  }

  if (DOC.readyState === 'loading') DOC.addEventListener('DOMContentLoaded', bind);
  else bind();

  DOC.addEventListener('mppl:langchange', function () {
    if (state.overlay && I18N && typeof I18N.applyTo === 'function') I18N.applyTo(state.overlay);
  });

  global.MpplReauth = {
    open: open,
    close: close,
    isOpen: function () { return state.open; }
  };
})(window);
