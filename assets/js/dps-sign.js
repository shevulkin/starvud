/**
 * ПРРО ДПС: підпис чеків ключем касира просто в браузері.
 *
 * Це наш «Device Manager». У Вчасно ключ лежить у програмі на ПК; тут — у
 * пам'яті вкладки: касир один раз на зміну обирає файл ключа (з флешки чи з
 * пам'яті телефона) або захищений носій і вводить пароль. Ключ і пароль не
 * покидають пристрій: бібліотека ІІТ (та сама, що в кабінеті ДПС) працює у
 * web worker, а на сайт іде лише готовий підпис.
 *
 * Сторінка нічого не знає про формат чека: вона питає сервер «що підписати»
 * (/admin/fiscal/dps/step), підписує байти й віддає (/admin/fiscal/dps/submit),
 * доки сервер не скаже «готово». Послідовність — звірити стан каси, відкрити
 * зміну, чек, Z-звіт — веде сервер (DpsFlow).
 *
 * Два режими контейнера [data-dps-signer]:
 *   kasa  — сторінка «Каса»: тримає ключ і сама забирає всі документи цієї
 *           людини, поки відкрита (як запущена програма каси);
 *   order — картка замовлення: показує стан чека; якщо вкладки з ключем немає —
 *           пропонує підписати тут.
 */
(function () {
  'use strict';
  var box = document.querySelector('[data-dps-signer]');
  if (!box || !window.BOFU) return;

  var base = String(window.BOFU.base || '/').replace(/\/$/, '');
  var csrf = window.BOFU.csrf || '';
  var mode = box.getAttribute('data-mode') || 'order';
  var parentId = box.getAttribute('data-parent') || '';
  var lib = {
    script: box.getAttribute('data-lib'),
    worker: box.getAttribute('data-worker'),
    cas: box.getAttribute('data-cas'),
    certs: box.getAttribute('data-certs'),
    proxy: box.getAttribute('data-proxy')
  };
  var tab = (Date.now().toString(36) + Math.random().toString(36).slice(2, 10));

  var $ = function (sel) { return box.querySelector(sel); };
  var statusEl = $('[data-dps-status]');
  var logEl = $('[data-dps-log]');
  var keyForm = $('[data-dps-keyform]');
  var ownerBox = $('[data-dps-owner]');

  var eu = null, euType = -1, keyOwner = null, busy = false, stopped = false;

  function say(text, kind) {
    if (!statusEl) return;
    statusEl.hidden = false;
    statusEl.textContent = text;
    statusEl.className = 'fiscal-runner is-' + (kind || 'work');
  }
  function log(text, kind) {
    if (!logEl) return;
    var li = document.createElement('li');
    li.className = 'is-' + (kind || 'work');
    li.textContent = new Date().toLocaleTimeString('uk-UA', { hour: '2-digit', minute: '2-digit' }) + ' · ' + text;
    logEl.insertBefore(li, logEl.firstChild);
    while (logEl.children.length > 12) logEl.removeChild(logEl.lastChild);
  }

  function post(path, data) {
    var body = new FormData();
    Object.keys(data).forEach(function (k) { body.append(k, data[k]); });
    return fetch(base + path, {
      method: 'POST', body: body, credentials: 'same-origin',
      headers: { 'X-CSRF-Token': csrf, 'X-Requested-With': 'fetch', 'Accept': 'application/json' }
    }).then(function (r) {
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.json();
    });
  }

  // ─────────────────────────────────────────────────────────── бібліотека

  function loadScript(url) {
    return new Promise(function (resolve, reject) {
      if (window.EndUser) { resolve(); return; }
      var s = document.createElement('script');
      s.src = url; s.async = false;
      s.onload = function () { resolve(); };
      s.onerror = function () { reject(new Error('Не вдалося завантажити бібліотеку підпису')); };
      document.head.appendChild(s);
    });
  }

  function consts() { return window.EndUserConstants || {}; }

  /**
   * Екземпляр бібліотеки потрібного типу: 0 — файловий ключ (JS, працює й на
   * телефоні), 1 — захищений носій (потребує розширення й програми ІІТ на ПК).
   * Ініціалізація важка (воркер ~17 МБ), тому один раз на вкладку.
   */
  function library(type) {
    if (eu && euType === type) return Promise.resolve(eu);
    say(type === 0 ? 'Завантажуємо бібліотеку підпису… (перший раз — до хвилини)' : 'Підключаємо бібліотеку для захищених носіїв…');
    return loadScript(lib.script).then(function () {
      return Promise.all([
        fetch(lib.cas, { cache: 'no-cache' }).then(function (r) { return r.json(); }),
        fetch(lib.certs, { cache: 'no-cache' }).then(function (r) { return r.arrayBuffer(); })
      ]);
    }).then(function (data) {
      var c = consts();
      var types = c.EndUserLibraryType || { JS: 0, SW: 1 };
      var inst = new window.EndUser(type === 0 ? lib.worker : null, type === 0 ? types.JS : types.SW);
      var ready = type === 1
        ? inst.GetLibraryInfo().then(function (info) {
            if (!info.supported) throw new Error('Цей браузер не підтримує роботу із захищеними носіями ІІТ.');
            if (!info.loaded || info.isNativeLibraryNeedUpdate) {
              var url = info.isWebExtensionInstalled ? (info.nativeLibraryInstallURL || info.helpURL) : (info.webExtensionInstallURL || info.helpURL);
              var e = new Error('Для токена потрібні розширення браузера й програма ІІТ «Користувач ЦСК». Встановіть: ' + (url || 'iit.com.ua/downloads') + ' — і оновіть сторінку.');
              e.installUrl = url;
              throw e;
            }
          })
        : Promise.resolve();
      return ready.then(function () {
        return inst.Initialize({
          language: 'uk', encoding: 'UTF-8',
          // Усе до ЦСК — через наш проксі: політика безпеки сайту пускає
          // запити браузера лише на наш домен
          httpProxyServiceURL: lib.proxy, directAccess: false,
          CAs: data[0], CACertificates: new Uint8Array(data[1])
        });
      }).then(function () {
        // Податкова вимагає CAdES із позначкою часу (signature-time-stamp)
        var st = (c.EndUserSignType && c.EndUserSignType.CAdES_T) || 4;
        return inst.SetRuntimeParameter(c.EU_SIGN_TYPE_PARAMETER || 'SignType', st);
      }).then(function () {
        eu = inst; euType = type;
        fillCAs(data[0]);
        return inst;
      });
    });
  }

  function fillCAs(cas) {
    var sel = $('[data-dps-ca]');
    if (!sel || sel.options.length > 1) return;
    cas.map(function (c) { return c.issuerCNs[0]; })
      .sort(function (a, b) { return a.localeCompare(b, 'uk'); })
      .forEach(function (cn) {
        var o = document.createElement('option');
        o.value = cn; o.textContent = cn;
        sel.appendChild(o);
      });
  }

  function err(e) {
    var m = (e && (e.message || e.description)) || String(e);
    return m.replace(/\s+/g, ' ').slice(0, 300);
  }

  // ─────────────────────────────────────────────────────────────── ключ

  function readFileKey() {
    var file = $('[data-dps-file]').files[0];
    var pass = $('[data-dps-pass]').value;
    var ca = ($('[data-dps-ca]') || {}).value || null;
    if (!file) { say('Оберіть файл ключа — з флешки чи з пам’яті телефона.', 'bad'); return; }
    if (!pass) { say('Введіть пароль до ключа.', 'bad'); return; }
    var bytes;
    file.arrayBuffer().then(function (buf) {
      bytes = new Uint8Array(buf);
      return library(0);
    }).then(function (inst) {
      say('Зчитуємо ключ…');
      // Ключі ПриватБанку — JKS-контейнер із кількома ключами: беремо ключ підпису (не печатку)
      if (/\.jks$/i.test(file.name)) {
        return inst.GetJKSPrivateKeys(bytes).then(function (keys) {
          var k = keys.filter(function (x) { return !x.digitalStamp; })[0] || keys[0];
          if (!k) throw new Error('У контейнері не знайдено ключа');
          return inst.ReadPrivateKeyBinary(k.privateKey, pass, k.certificates.map(function (c) { return c.data; }), ca);
        });
      }
      return inst.ReadPrivateKeyBinary(bytes, pass, null, ca);
    }).then(keyReady).catch(function (e) {
      say('Ключ не зчитано: ' + err(e), 'bad');
    }).then(function () {
      $('[data-dps-pass]').value = '';
    });
  }

  function findTokens() {
    var sel = $('[data-dps-media]');
    library(1).then(function (inst) {
      say('Шукаємо захищені носії…');
      return inst.GetKeyMedias();
    }).then(function (list) {
      sel.innerHTML = '';
      list.forEach(function (km, i) {
        var o = document.createElement('option');
        o.value = String(i); o.textContent = km.visibleName || (km.type + ' · ' + km.device);
        sel.appendChild(o);
      });
      sel.hidden = !list.length;
      sel._medias = list;
      $('[data-dps-token-read]').hidden = !list.length;
      say(list.length ? 'Оберіть носій, введіть пароль і натисніть «Зчитати з носія».' : 'Жодного носія не знайдено — вставте токен і спробуйте ще раз.', list.length ? 'work' : 'bad');
    }).catch(function (e) { say(err(e), 'bad'); });
  }

  function readToken() {
    var sel = $('[data-dps-media]');
    var km = sel._medias && sel._medias[+sel.value];
    var pass = $('[data-dps-pass]').value;
    if (!km) return;
    if (!pass) { say('Введіть пароль до носія.', 'bad'); return; }
    km.password = pass;
    say('Зчитуємо ключ із носія…');
    eu.ReadPrivateKey(km, null, ($('[data-dps-ca]') || {}).value || null)
      .then(keyReady)
      .catch(function (e) { say('Ключ не зчитано: ' + err(e), 'bad'); })
      .then(function () { $('[data-dps-pass]').value = ''; });
  }

  function keyReady(owner) {
    keyOwner = owner || {};
    if (keyForm) keyForm.hidden = true;
    if (ownerBox) {
      ownerBox.hidden = false;
      var n = ownerBox.querySelector('[data-dps-owner-name]');
      if (n) n.textContent = (keyOwner.subjCN || keyOwner.subjFullName || 'касир') +
        (keyOwner.issuerCN ? ' · ' + keyOwner.issuerCN : '');
    }
    say('Ключ зчитано. Документи підписуватимуться автоматично, поки ця вкладка відкрита.', 'ok');
    log('Ключ завантажено: ' + (keyOwner.subjCN || ''), 'ok');
    kick();
  }

  function unload() {
    var inst = eu;
    keyOwner = null;
    if (ownerBox) ownerBox.hidden = true;
    if (keyForm) keyForm.hidden = false;
    say('Ключ вивантажено з цієї вкладки.', 'work');
    if (inst) inst.ResetPrivateKey().catch(function () {});
  }

  // ────────────────────────────────────────────────────────────── підпис

  function b64(bytesB64) {
    var bin = atob(bytesB64), out = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
    return out;
  }

  /** Провести один документ до кінця: сервер каже, що підписати, ми підписуємо */
  function run(job) {
    say((job.label || 'Документ') + ': готуємо…');
    var step = function (r) {
      if (r.action === 'sign') {
        say((r.label || 'Документ') + ': підписуємо…');
        return eu.SignDataInternal(true, b64(r.data), true).then(function (signed) {
          say((r.label || 'Документ') + ': надсилаємо в ДПС…');
          return post('/admin/fiscal/dps/submit', { id: job.id, tab: tab, purpose: r.purpose, signed: signed });
        }).then(step);
      }
      return r;
    };
    return post('/admin/fiscal/dps/step', { id: job.id, tab: tab }).then(step).then(function (r) {
      if (r.action === 'done' && r.state === 'done') {
        log((r.label || job.label) + (r.number ? ' — № ' + r.number : '') + (r.test ? ' (тестовий)' : ''), 'ok');
        return true;
      }
      if (r.action === 'done') { log((r.label || job.label) + ': ' + (r.error || 'відмова'), 'bad'); return true; }
      if (r.action === 'wait') { log((job.label || '') + ': ' + (r.error || 'чекаємо звʼязку'), 'bad'); return false; }
      return true; // busy — документ веде інша вкладка
    }).catch(function (e) {
      log((job.label || 'Документ') + ': ' + err(e), 'bad');
      return false;
    });
  }

  /** Забрати й провести все, що чекає (послідовно — у ДПС наскрізна нумерація) */
  function drain() {
    if (busy || !keyOwner || stopped) return Promise.resolve(0);
    busy = true;
    var done = 0;
    return post('/admin/fiscal/dps/jobs', { signer: mode === 'kasa' ? 1 : 0, parent_id: mode === 'order' ? parentId : '' })
      .then(function (res) {
        var jobs = (res && res.jobs) || [];
        var chain = Promise.resolve();
        jobs.forEach(function (j) { chain = chain.then(function () { return run(j).then(function () { done++; }); }); });
        return chain;
      })
      .then(function () {
        busy = false;
        if (done) say('Готово. Ключ завантажено — нові документи підпишуться самі.', 'ok');
        return done;
      })
      .catch(function (e) { busy = false; say('Сайт не відповів: ' + err(e), 'bad'); return 0; });
  }

  var kicked = null;
  function kick() {
    if (mode === 'kasa') {
      drain();
      if (!kicked) kicked = setInterval(drain, 7000);
    } else {
      drain().then(function (n) { if (n) setTimeout(function () { location.reload(); }, 900); });
    }
  }

  // ────────────────────────────────────────── картка замовлення: хто підпише

  function watchOrder() {
    post('/admin/fiscal/dps/status', { parent_id: parentId }).then(function (s) {
      if (!s.left && !s.busy) {
        if (box.getAttribute('data-had') === '1') location.reload();
        box.hidden = true;
        return;
      }
      box.setAttribute('data-had', '1');
      box.hidden = false;
      if (keyOwner) return;
      if (s.signer) {
        say('Чек підписує ваша вкладка «Каса» з ключем — за мить зʼявиться тут.', 'work');
        if (keyForm) keyForm.hidden = true;
      } else {
        say('Чек чекає на підпис: відкрийте вкладку «Каса» з ключем або підпишіть тут.', 'bad');
        if (keyForm) keyForm.hidden = false;
      }
      setTimeout(watchOrder, 3000);
    }).catch(function () { setTimeout(watchOrder, 6000); });
  }

  // ───────────────────────────────────────────────────────────── запуск

  var bind = function (sel, fn) { var b = $(sel); if (b) b.addEventListener('click', function (e) { e.preventDefault(); fn(); }); };
  bind('[data-dps-load]', readFileKey);
  bind('[data-dps-token]', findTokens);
  bind('[data-dps-token-read]', readToken);
  bind('[data-dps-unload]', unload);
  var pass = $('[data-dps-pass]');
  if (pass) pass.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); readFileKey(); } });

  // Закриваючи вкладку з ключем, попереджаємо: після неї чеки чекатимуть
  // Перелік ЦСК — одразу, з легкого CAs.json: обраний ЦСК робить зчитування
  // ключа набагато швидшим, ніж перебір усіх
  if ($('[data-dps-ca]')) {
    fetch(lib.cas, { cache: 'no-cache' }).then(function (r) { return r.json(); }).then(fillCAs).catch(function () {});
  }

  if (mode === 'kasa') {
    window.addEventListener('beforeunload', function (e) {
      if (keyOwner) { e.preventDefault(); e.returnValue = ''; }
    });
    say('Завантажте ключ касира — і ця вкладка підписуватиме чеки, поки відкрита.', 'work');
  } else {
    watchOrder();
  }
})();
