/* i18n.js — лёгкий модуль локализации статичных строк UI.
 *
 * Используется в mb.php: добавляет переключатель EN/RU, применяет переводы
 * к элементам с атрибутом data-i18n, помнит выбор в localStorage.
 *
 * Бизнес-логика, AJAX-запросы и сообщения от сервера НЕ переводятся —
 * этот модуль работает только с DOM-разметкой клиента.
 */
(function (global) {
  'use strict';

  var STORAGE_KEY = 'mppl.lang';
  var DEFAULT_LANG = 'ru';

  /* --------------------------- словари --------------------------- */
  var DICT = {
    ru: {
      'menu.create_account': 'СОЗДАТЬ АККАУНТ',
      'menu.account_list': 'СПИСОК АККАУНТОВ',
      'menu.history': 'ИСТОРИЯ ОПЕРАЦИЙ',
      'menu.packets': 'ПАКЕТЫ, ИДЕНТЫ И ЦЕНЫ',
      'menu.profile': 'ПРОФИЛЬ',
      'menu.balance': 'ПОПОЛНЕНИЕ БАЛАНСА',
      'menu.news': 'НОВОСТНОЙ БЛОК',
      'menu.dealers': 'СПИСОК ДИЛЕРОВ',
      'menu.dealers_history': 'ОБОРОТКА ПО ДИЛЕРАМ',
      'menu.fk_top_up': 'ПОПОЛНЕНИЕ ЧЕРЕЗ FK',
      'menu.logout': 'ВЫХОД',
      'greeting': 'Приветствуем Вас, ',
      'search.login_placeholder': 'логин|телефон|email',
      'search.tooltip': 'Поиск',
      'search.opt_login': 'ЛОГИН',
      'search.opt_all': 'ВСЁ',
      'search.opt_phone': 'Т. НОМЕР',
      'search.opt_email': 'EMAIL',
      'menu_btn.aria': 'меню',
      'info_btn.aria': 'инфо',
      'pager.back': 'К списку',
      'pager.hint': 'Свайп влево / вправо для переключения',
      'pager.tab_sharing': 'ШАРИНГ',
      'pager.tab_iptv': 'IPTV',
      'pager.tab_accounts': 'АККАУНТЫ',
      'modal.register_account': 'РЕГИСТРАЦИЯ АККАУНТА',
      'modal.account_ops': 'СПИСОК ОПЕРАЦИЙ ПО АККАУНТУ ',
      'modal.edit_data': 'РЕДАКТИРОВАНИЕ ДАННЫХ ',
      'modal.payments_info': 'ИНФОРМАЦИЯ О ПЛАТЕЖАХ',
      'modal.fk_balance': 'ПОПОЛНЕНИЕ БАЛАНСА ЧЕРЕЗ FREEKASSA',
      'modal.plugin_settings': 'НАСТРОЙКИ ПЛАГИНОВ',
      'form.login': 'ЛОГИН',
      'form.password': 'ПАРОЛЬ',
      'form.iptv': 'IPTV',
      'form.connection_server': 'СЕРВЕР ПОДКЛЮЧЕНИЯ',
      'form.register': 'ЗАРЕГИСТРИРОВАТЬ',
      'form.save': 'СОХРАНИТЬ',
      'form.amount': 'СУММА ПОПОЛНЕНИЯ',
      'form.payment_id': 'НОМЕР ПЛАТЕЖА',
      'form.go_to_payment': 'К ПОПОЛНЕНИЮ',
      'form.email': 'EMAIL',
      'form.mobile': 'МОБИЛЬНЫЙ #',
      'form.server': 'СЕРВЕР',
      'form.comment': 'КОМЕНТ',
      'form.tuner': 'Тюнер',
      'form.plugin': 'Плагин',
      'form.protocol': 'Протокол',
      'form.send_to_file': 'Сохранить в файл',
      'form.send_to_email': 'Отправить на email',
      'form.send': 'Отправить',
      'form.notify_to_phone': 'Отправлять оповещения на номер',
      'cards.list': 'СПИСОК КАРТ',
      'lang.label': 'Язык / Language',
      'lang.ru': 'RU',
      'lang.en': 'EN',

      'net.offline_title': 'Нет соединения с сетью',
      'net.offline_msg': 'Проверьте подключение. Мы автоматически продолжим, как только связь восстановится.',
      'net.restored_title': 'Связь восстановлена',
      'net.restored_msg': 'Соединение восстановлено',
      'net.retry': 'Проверить ещё раз',

      'auth.session_expired_title': 'Сессия истекла',
      'auth.session_expired_msg': 'Войдите заново — мы вернёмся туда же.',
      'auth.login_label': 'Логин',
      'auth.password_label': 'Пароль',
      'auth.submit': 'ВОЙТИ',
      'auth.error_empty': 'Введите логин и пароль',
      'auth.error_invalid': 'Неправильная пара логин/пароль',
      'auth.error_network': 'Ошибка сети — попробуйте ещё раз',
      'auth.error_generic': 'Не удалось войти, попробуйте ещё раз'
    },
    en: {
      'menu.create_account': 'CREATE ACCOUNT',
      'menu.account_list': 'ACCOUNT LIST',
      'menu.history': 'OPERATIONS HISTORY',
      'menu.packets': 'PACKETS, IDENTS & PRICES',
      'menu.profile': 'PROFILE',
      'menu.balance': 'TOP UP BALANCE',
      'menu.news': 'NEWS',
      'menu.dealers': 'DEALERS LIST',
      'menu.dealers_history': 'DEALERS REPORT',
      'menu.fk_top_up': 'TOP UP VIA FK',
      'menu.logout': 'LOGOUT',
      'greeting': 'Welcome, ',
      'search.login_placeholder': 'login | phone | email',
      'search.tooltip': 'Search',
      'search.opt_login': 'LOGIN',
      'search.opt_all': 'ALL',
      'search.opt_phone': 'PHONE',
      'search.opt_email': 'EMAIL',
      'menu_btn.aria': 'menu',
      'info_btn.aria': 'info',
      'pager.back': 'Back to list',
      'pager.hint': 'Swipe left / right to switch',
      'pager.tab_sharing': 'SHARING',
      'pager.tab_iptv': 'IPTV',
      'pager.tab_accounts': 'ACCOUNTS',
      'modal.register_account': 'REGISTER ACCOUNT',
      'modal.account_ops': 'OPERATIONS LIST ',
      'modal.edit_data': 'EDIT DATA ',
      'modal.payments_info': 'PAYMENT INFORMATION',
      'modal.fk_balance': 'TOP UP VIA FREEKASSA',
      'modal.plugin_settings': 'PLUGIN SETTINGS',
      'form.login': 'LOGIN',
      'form.password': 'PASSWORD',
      'form.iptv': 'IPTV',
      'form.connection_server': 'CONNECTION SERVER',
      'form.register': 'REGISTER',
      'form.save': 'SAVE',
      'form.amount': 'TOP-UP AMOUNT',
      'form.payment_id': 'PAYMENT ID',
      'form.go_to_payment': 'PROCEED TO PAYMENT',
      'form.email': 'EMAIL',
      'form.mobile': 'MOBILE #',
      'form.server': 'SERVER',
      'form.comment': 'COMMENT',
      'form.tuner': 'Tuner',
      'form.plugin': 'Plugin',
      'form.protocol': 'Protocol',
      'form.send_to_file': 'Save to file',
      'form.send_to_email': 'Send to email',
      'form.send': 'Send',
      'form.notify_to_phone': 'Send notifications to phone',
      'cards.list': 'CARD LIST',
      'lang.label': 'Language / Язык',
      'lang.ru': 'RU',
      'lang.en': 'EN',

      'net.offline_title': 'No network connection',
      'net.offline_msg': 'Check your connection. We will continue automatically as soon as the network is back.',
      'net.restored_title': 'Network restored',
      'net.restored_msg': 'Connection restored',
      'net.retry': 'Retry',

      'auth.session_expired_title': 'Session expired',
      'auth.session_expired_msg': 'Please log in again — we will keep you on this page.',
      'auth.login_label': 'Login',
      'auth.password_label': 'Password',
      'auth.submit': 'SIGN IN',
      'auth.error_empty': 'Enter login and password',
      'auth.error_invalid': 'Wrong login/password',
      'auth.error_network': 'Network error — please retry',
      'auth.error_generic': 'Could not sign in, please retry'
    }
  };

  /* --------------------------- состояние --------------------------- */
  var current = DEFAULT_LANG;

  function safeStorage() {
    try { return global.localStorage; } catch (_) { return null; }
  }

  function getSavedLang() {
    var s = safeStorage();
    if (!s) return null;
    try { return s.getItem(STORAGE_KEY); } catch (_) { return null; }
  }

  function saveLang(lang) {
    var s = safeStorage();
    if (!s) return;
    try { s.setItem(STORAGE_KEY, lang); } catch (_) { /* noop */ }
  }

  /* --------------------------- API --------------------------- */
  function t(key) {
    var pack = DICT[current] || DICT[DEFAULT_LANG];
    return (pack && pack[key]) || key;
  }

  function applyTo(root) {
    var scope = root || document;
    var nodes = scope.querySelectorAll('[data-i18n]');
    for (var i = 0; i < nodes.length; i++) {
      var el = nodes[i];
      var key = el.getAttribute('data-i18n');
      if (!key) continue;
      el.textContent = t(key);
    }

    var attrNodes = scope.querySelectorAll('[data-i18n-attr]');
    for (var j = 0; j < attrNodes.length; j++) {
      var an = attrNodes[j];
      // формат: "attr:key,attr2:key2"
      var spec = an.getAttribute('data-i18n-attr');
      if (!spec) continue;
      var pairs = spec.split(',');
      for (var k = 0; k < pairs.length; k++) {
        var p = pairs[k].split(':');
        if (p.length !== 2) continue;
        an.setAttribute(p[0].trim(), t(p[1].trim()));
      }
    }

    document.documentElement.setAttribute('lang', current);
  }

  function setLang(lang) {
    if (!DICT[lang]) return;
    current = lang;
    saveLang(lang);
    applyTo(document);
    updateSwitcher();
    document.dispatchEvent(new CustomEvent('mppl:langchange', { detail: { lang: lang } }));
  }

  function updateSwitcher() {
    var btns = document.querySelectorAll('.lang-switch__btn');
    for (var i = 0; i < btns.length; i++) {
      var b = btns[i];
      var on = b.getAttribute('data-lang') === current;
      b.classList.toggle('lang-switch__btn--active', on);
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
    }
  }

  function init() {
    current = getSavedLang() || DEFAULT_LANG;
    applyTo(document);

    document.addEventListener('click', function (e) {
      var btn = e.target.closest && e.target.closest('.lang-switch__btn');
      if (!btn) return;
      e.preventDefault();
      setLang(btn.getAttribute('data-lang'));
    });

    updateSwitcher();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  global.MpplI18n = { t: t, setLang: setLang, applyTo: applyTo, current: function () { return current; } };
})(window);
