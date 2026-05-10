/* pager.js — свайп-навигация в личном кабинете.
 *
 * Идея: над существующим контентом #result строится «палуба» с состояниями:
 *   - list      — отображён список аккаунтов / новости
 *   - sharing   — карточка аккаунта в режиме шаринга
 *   - iptv      — карточка аккаунта в режиме IPTV
 *
 * Pager НЕ переписывает business-логику. Он:
 *   - Слушает изменения #result (MutationObserver), определяет состояние;
 *   - Анимирует входящий/уходящий контент;
 *   - На свайп вызывает существующие функции getuser(login,'s'|'i') и
 *     userlist() для возврата к списку.
 *
 * Свайпы (стандартные mobile-конвенции):
 *   - finger ⟵ (swipe-left)  : перейти к следующей странице
 *   - finger ⟶ (swipe-right) : перейти к предыдущей странице
 *   - без зацикливания.
 */
(function (global) {
  'use strict';

  var SWIPE_PX = 45;          // минимальная горизонтальная дистанция
  var SWIPE_MAX_TIME = 600;   // мс
  var SWIPE_AXIS_RATIO = 1.4; // |dx| должна быть в N раз больше |dy|

  var state = {
    page: 'list',
    currentLogin: null,
    canSharing: false,
    canIptv: false,
    pendingDirection: null,
    origin: null          // 'userlist' | 'loglist' | 'uman' — откуда пришли
  };

  var dom = {};

  /* --------------------------------------------------------------------- */
  /*  Утилиты обнаружения текущего состояния                                */
  /* --------------------------------------------------------------------- */

  function detectPage() {
    var result = dom.result;
    var uinfo = dom.uinfo;
    if (!result) return 'list';

    if (result.querySelector('.iptvcntr')) return 'iptv';

    // Если в #uinfo есть карточка аккаунта — мы в card-режиме (шаринг)
    var hasInfo = uinfo && uinfo.querySelector('.blk.finr');
    if (hasInfo && (result.querySelector('.fin') || result.querySelector('#mc'))) {
      return 'sharing';
    }
    return 'list';
  }

  function detectAvailability() {
    var uinfo = dom.uinfo;
    if (!uinfo) return;

    // Логин аккаунта
    var nameEl = uinfo.querySelector('#uname');
    if (nameEl) state.currentLogin = (nameEl.textContent || '').trim();

    // По кнопкам в инфо-панели понимаем что доступно
    var hasIptvBtn   = !!uinfo.querySelector('button[onclick*="getuser(0,"][onclick*=",\'i\')"]');
    var hasShareBtn  = !!uinfo.querySelector('button[onclick*="getuser(0,"][onclick*=",\'s\')"]');
    var hasIptvLink  = !!uinfo.querySelector('button[onclick*="iptvsign"]');

    // Если мы сейчас на iptv-странице → там есть кнопка ШАРИНГ → шаринг доступен.
    // Если мы на sharing-странице → там есть кнопка IPTV → iptv доступен.
    if (state.page === 'iptv') {
      state.canSharing = hasShareBtn;
      state.canIptv = true;
    } else if (state.page === 'sharing') {
      state.canSharing = true;
      state.canIptv = hasIptvBtn;
    } else {
      state.canSharing = false;
      state.canIptv = false;
    }

    // Линковка iptv (ПРИВЯЗАТЬ К IPTV) — здесь не считается доступной страницей,
    // т.к. это переход на форму регистрации, а не страница с пэйджером.
    void hasIptvLink;
  }

  /* --------------------------------------------------------------------- */
  /*  Анимация перехода                                                    */
  /* --------------------------------------------------------------------- */

  function animateSlide(direction) {
    // direction: 'forward' | 'backward'
    var stage = dom.stage;
    if (!stage || !direction) return;
    stage.classList.remove('deck--slide-forward', 'deck--slide-backward');
    // принудительный reflow, чтобы анимация перезапустилась
    void stage.offsetWidth;
    stage.classList.add(direction === 'forward' ? 'deck--slide-forward' : 'deck--slide-backward');
  }

  function clearSlide() {
    if (!dom.stage) return;
    dom.stage.classList.remove('deck--slide-forward', 'deck--slide-backward');
  }

  /* --------------------------------------------------------------------- */
  /*  Навигация                                                            */
  /* --------------------------------------------------------------------- */

  function pagesAvailable() {
    var pages = ['list'];
    if (state.canSharing || state.page === 'sharing') pages.push('sharing');
    if (state.canIptv || state.page === 'iptv') pages.push('iptv');
    return pages;
  }

  function indexOf(page) {
    var pages = pagesAvailable();
    return pages.indexOf(page);
  }

  function goNext() {
    if (state.origin === 'uman') return;
    var pages = pagesAvailable();
    var i = pages.indexOf(state.page);
    if (i < 0 || i >= pages.length - 1) return;
    goTo(pages[i + 1]);
  }

  function goPrev() {
    if (state.origin === 'uman') return;
    var pages = pagesAvailable();
    var i = pages.indexOf(state.page);
    if (i <= 0) return;
    var target = pages[i - 1];
    // Если пришли из поиска (origin не установлен) — не позволяем свайпом вернуться к списку
    if (target === 'list' && !state.origin) return;
    goTo(target);
  }

  function goTo(target) {
    if (target === state.page) return;
    var direction = indexOf(target) > indexOf(state.page) ? 'forward' : 'backward';

    state.pendingDirection = direction;

    if (target === 'list') {
      state.origin = null;
      if (typeof global.userlist === 'function') {
        global.userlist(0, 0, 0);
      }
      return;
    }

    var login = state.currentLogin;
    if (!login) { state.pendingDirection = null; return; }

    if (target === 'sharing' && typeof global.getuser === 'function') {
      global.getuser(0, login, 's');
      return;
    }

    if (target === 'iptv' && typeof global.getuser === 'function') {
      global.getuser(0, login, '');
      return;
    }
    state.pendingDirection = null;
  }

  /* --------------------------------------------------------------------- */
  /*  Точечные индикаторы                                                  */
  /* --------------------------------------------------------------------- */

  function renderNav() {
    if (!dom.nav) return;
    dom.nav.innerHTML = '';
    if (state.origin === 'uman') return;
    var pages = pagesAvailable();
    var labels = {
      list: dataI18n('pager.tab_accounts'),
      sharing: dataI18n('pager.tab_sharing'),
      iptv: dataI18n('pager.tab_iptv')
    };
    if (pages.length < 2) return;

    pages.forEach(function (p) {
      var dot = document.createElement('button');
      dot.type = 'button';
      dot.className = 'deck__dot' + (p === state.page ? ' deck__dot--active' : '');
      dot.setAttribute('aria-label', labels[p] || p);
      dot.setAttribute('data-page', p);
      dot.addEventListener('click', function () { goTo(p); });
      dom.nav.appendChild(dot);
    });
  }

  function dataI18n(key) {
    if (global.MpplI18n && typeof global.MpplI18n.t === 'function') {
      return global.MpplI18n.t(key);
    }
    return key;
  }

  /* --------------------------------------------------------------------- */
  /*  Жесты                                                                */
  /* --------------------------------------------------------------------- */

  function isEdgeZone(clientX) {
    var w = window.innerWidth;
    var edge = Math.max(50, w * 0.1);
    return clientX < edge || clientX > w - edge;
  }

  function bindSwipe(target) {
    var sx = 0, sy = 0, st = 0, tracking = false;

    target.addEventListener('touchstart', function (e) {
      if (e.touches.length !== 1) return;
      var t = e.touches[0];
      // Свайпы из краёв экрана оставляем для меню / инфо-панели
      if (isEdgeZone(t.clientX)) { tracking = false; return; }
      sx = t.clientX; sy = t.clientY; st = Date.now(); tracking = true;
    }, { passive: true });

    target.addEventListener('touchend', function (e) {
      if (!tracking) return;
      tracking = false;
      var t = e.changedTouches[0];
      var dx = t.clientX - sx;
      var dy = t.clientY - sy;
      var dt = Date.now() - st;
      if (dt > SWIPE_MAX_TIME) return;
      if (Math.abs(dx) < SWIPE_PX) return;
      if (Math.abs(dx) < Math.abs(dy) * SWIPE_AXIS_RATIO) return;
      if (dx < 0) goNext(); else goPrev();
    }, { passive: true });

    // mouse-перетаскивание (для desktop)
    var mDown = false, mx = 0, my = 0, mt = 0;
    target.addEventListener('mousedown', function (e) {
      if (e.button !== 0) return;
      mDown = true; mx = e.clientX; my = e.clientY; mt = Date.now();
    });
    document.addEventListener('mouseup', function (e) {
      if (!mDown) return;
      mDown = false;
      var dx = e.clientX - mx;
      var dy = e.clientY - my;
      var dt = Date.now() - mt;
      if (dt > SWIPE_MAX_TIME) return;
      if (Math.abs(dx) < SWIPE_PX) return;
      if (Math.abs(dx) < Math.abs(dy) * SWIPE_AXIS_RATIO) return;
      if (dx < 0) goNext(); else goPrev();
    });
  }

  /* --------------------------------------------------------------------- */
  /*  Обновление состояния по факту изменения контента                     */
  /* --------------------------------------------------------------------- */

  function refreshState() {
    var prev = state.page;
    state.page = detectPage();
    detectAvailability();
    renderNav();
    if (dom.back && dom.menuToggle) {
      if (state.origin === 'uman') {
        dom.back.classList.add('hidden');
        dom.menuToggle.classList.remove('hidden');
      } else if (state.page === 'list' && !state.origin) {
        dom.back.classList.add('hidden');
        dom.menuToggle.classList.remove('hidden');
      } else if (state.origin && dom.back.classList.contains('hidden')) {
        dom.back.classList.remove('hidden');
        dom.menuToggle.classList.add('hidden');
      }
    }
    if (dom.stage) {
      dom.stage.setAttribute('data-page', state.page);
    }
    if (state.page !== prev) {
      animateSlide(state.pendingDirection);
      state.pendingDirection = null;
    }
  }

  /* --------------------------------------------------------------------- */
  /*  Сборка DOM-обвязки                                                   */
  /* --------------------------------------------------------------------- */

  function build() {
    dom.result = document.getElementById('result');
    dom.uinfo  = document.getElementById('uinfo');
    var main = dom.result && dom.result.parentNode;
    if (!main) return false;

    // Если уже обёрнут — повторно не строим
    if (dom.result.parentNode.classList.contains('deck__stage')) return true;

    var stage = document.createElement('div');
    stage.className = 'deck__stage';
    stage.setAttribute('data-page', 'list');

    main.insertBefore(stage, dom.result);
    stage.appendChild(dom.result);

    var nav = document.createElement('div');
    nav.className = 'deck__nav';
    nav.setAttribute('aria-label', 'Навигация по карточке аккаунта');
    main.insertBefore(nav, stage);

    // Кнопка «назад» в шапке (уже в DOM из mb.php)
    var back = document.querySelector('.header-back');
    var menuToggle = document.querySelector('.menu-toggle');
    if (back) {
      back.addEventListener('click', function () { goTo('list'); });
    }

    dom.stage = stage;
    dom.nav = nav;
    dom.back = back;
    dom.menuToggle = menuToggle;

    bindSwipe(stage);
    return true;
  }

  function start() {
    if (!build()) return;

    var observer = new MutationObserver(function () { refreshState(); });
    observer.observe(dom.result, { childList: true, subtree: true });
    if (dom.uinfo) {
      observer.observe(dom.uinfo, { childList: true, subtree: true });
    }
    refreshState();

    if (dom.stage) {
      dom.stage.addEventListener('animationend', clearSlide);
    }

    document.addEventListener('mppl:langchange', function () {
      renderNav();
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }

  // Публикуем минимальное API на случай отладки.
  global.MpplPager = {
    goTo: goTo,
    goNext: goNext,
    goPrev: goPrev,
    setOrigin: function (o) { state.origin = o; },
    state: function () { return Object.assign({}, state); }
  };
})(window);
