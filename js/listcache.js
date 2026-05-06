/* listcache.js — кэш состояния списка аккаунтов.
 *
 * При переходе на страницу аккаунта (getuser) текущее содержимое #result
 * и #uinfo сохраняется. При возврате к списку оно восстанавливается
 * без повторного AJAX-запроса, сохраняя позицию прокрутки.
 *
 * API:
 *   MpplListCache.save()        — сохранить текущее состояние списка
 *   MpplListCache.restore()     — восстановить; возвращает true если удалось
 *   MpplListCache.clear()       — очистить кэш
 *   MpplListCache.hasCached()   — есть ли что восстанавливать
 */
(function (global) {
  'use strict';

  var cache = null;

  function getScrollY() {
    return global.scrollY || global.pageYOffset || 0;
  }

  function save() {
    var result = document.getElementById('result');
    var uinfo  = document.getElementById('uinfo');
    if (!result) return;

    cache = {
      resultHTML: result.innerHTML,
      uinfoHTML:  uinfo ? uinfo.innerHTML : '',
      scrollY:    getScrollY()
    };
  }

  function restore() {
    if (!cache) return false;

    var result = document.getElementById('result');
    var uinfo  = document.getElementById('uinfo');
    if (!result) return false;

    result.innerHTML = cache.resultHTML;
    if (uinfo) uinfo.innerHTML = cache.uinfoHTML;

    var savedY = cache.scrollY;
    requestAnimationFrame(function () {
      global.scrollTo(0, savedY);
    });

    cache = null;
    return true;
  }

  function clear() {
    cache = null;
  }

  function hasCached() {
    return cache !== null;
  }

  global.MpplListCache = {
    save:      save,
    restore:   restore,
    clear:     clear,
    hasCached: hasCached
  };
})(window);
