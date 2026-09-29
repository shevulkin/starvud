// Адмінка: PWA + Web Push
(function () {
  var base = (window.BOFU && BOFU.base) || '/';

  // Service worker (PWA)
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register(base.replace(/\/$/, '') + '/sw.js').catch(function(){});
  }

  // Кнопка встановлення PWA
  var deferred = null, btn = document.getElementById('pwaInstall');
  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault(); deferred = e;
    if (btn) btn.style.display = 'block';
  });
  if (btn) btn.addEventListener('click', function () {
    if (deferred) { deferred.prompt(); deferred = null; btn.style.display = 'none'; }
  });

  // Web Push підписка (працює лише на HTTPS або localhost)
  var pushBtn = document.getElementById('pushEnable');
  if (pushBtn && 'PushManager' in window && (window.BOFU && BOFU.vapid)) {
    pushBtn.style.display = 'block';
    pushBtn.addEventListener('click', function () {
      Notification.requestPermission().then(function (perm) {
        if (perm !== 'granted') return;
        navigator.serviceWorker.ready.then(function (reg) {
          return reg.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: urlB64(BOFU.vapid)
          });
        }).then(function (sub) {
          return fetch(base.replace(/\/$/, '') + '/api/push/subscribe', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-CSRF-Token': (window.BOFU && BOFU.csrf) || ''
            },
            body: JSON.stringify(sub)
          });
        }).then(function () {
          pushBtn.textContent = '🔔 Пуші увімкнено';
        }).catch(function (e) { console.warn('push', e); });
      });
    });
  }
  // Налаштування: перевірка інтеграцій тим, що зараз у полях (нічого не зберігає)
  var checkBtn = document.getElementById('checkBtn');
  if (checkBtn) {
    var box = document.getElementById('checkResult');
    var icons = { ok: '✅', warn: '⚠️', bad: '❌', off: '—' };
    checkBtn.addEventListener('click', function () {
      var form = checkBtn.closest('form');
      var data = new FormData();
      // лише поля інтеграцій: перевірка не має відношення до перемикачів і текстів.
      // acq[…] — реквізити еквайрингу: вони в іншій групі форми, але перевіряються
      // так само незбереженими, інакше ключ довелось би зберігати наосліп
      Array.prototype.forEach.call(form.querySelectorAll('[name^="text["], [name^="acq["]'), function (i) {
        data.append(i.name, i.value);
      });
      checkBtn.disabled = true;
      box.hidden = false;
      box.innerHTML = '<div class="dim">Питаємо…</div>';
      fetch(base.replace(/\/$/, '') + '/admin/settings/check', {
        method: 'POST', body: data,
        headers: { 'X-CSRF-Token': (window.BOFU && BOFU.csrf) || '' }
      })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (!res || !res.rows) throw new Error('bad response');
          box.innerHTML = '';
          res.rows.forEach(function (row) {
            var el = document.createElement('div');
            el.className = 'check-row is-' + row.state;
            var note = row.note ? '<div class="check-note">' + esc(row.note) + '</div>' : '';
            el.innerHTML = '<span class="check-icon">' + (icons[row.state] || '') + '</span>'
              + '<div><b>' + esc(row.name) + '</b> — ' + esc(row.text) + note + '</div>';
            box.appendChild(el);
          });
        })
        .catch(function () { box.innerHTML = '<div class="check-row is-bad">Не вдалося виконати перевірку. Спробуйте ще раз.</div>'; })
        .finally(function () { checkBtn.disabled = false; });
    });
  }
  function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

  // Кнопки «показати значення» більше немає, і повернути її нема куди:
  // збережені ключі в HTML сторінки не потрапляють узагалі, тож показувати в
  // полі просто нічого. Звірити збережений ключ із кабінетом сервісу дає
  // підпис біля назви поля — довжина й чотири останні символи.

  // Налаштування: головний вимикач сповіщень гасить канальні. Стан галок не
  // чіпаємо — вимкнули головний, увімкнули назад, і все на місці (сервер їх
  // теж не переписує, поки головний вимкнений).
  var master = document.querySelector('[data-master]');
  if (master) {
    var group = document.querySelector('[data-group]');
    var note = group && group.querySelector('.toggle-group-note');
    var noChan = document.querySelector('[data-nochan]');
    var kids = document.querySelectorAll('[data-child]');
    function sync() {
      if (group) group.classList.toggle('is-off', !master.checked);
      if (note) note.hidden = master.checked;
      var any = false;
      Array.prototype.forEach.call(kids, function (c) {
        c.disabled = !master.checked;
        if (c.checked) any = true;
      });
      // головний увімкнено, а каналів жодного — виглядає налаштованим і мовчить
      if (noChan) noChan.hidden = !(master.checked && !any);
    }
    master.addEventListener('change', sync);
    Array.prototype.forEach.call(kids, function (c) { c.addEventListener('change', sync); });
  }

  // Користувачі: магазини існують лише разом із роллю продавця (Users::saveStores).
  // Знімають галку — селектор гасне й вибір скидається одразу, щоб було видно,
  // що доступ відкликається, а не «просто не збережеться».
  Array.prototype.forEach.call(document.querySelectorAll('[data-seller-role]'), function (role) {
    var form = role.form;
    if (!form) return;
    var boxes = form.querySelectorAll('[data-seller-stores]');
    var hint = form.querySelector('[data-seller-hint]');
    if (!boxes.length) return;
    role.addEventListener('change', function () {
      // міняємо ТЕКСТ, а не видимість: зникома підказка міняла висоту блоку,
      // і від кліку по ролі вся картка стрибала
      if (hint) hint.textContent = role.checked ? hint.dataset.on : hint.dataset.off;
      Array.prototype.forEach.call(boxes, function (b) {
        b.disabled = !role.checked;
        if (!role.checked) b.checked = false;
      });
    });
  });

  // Панель збереження: показує, що є незбережені зміни, і не дає піти мовчки.
  // Втратити півгодини правок у таблиці на сорок рядків — найдорожча помилка
  // в адмінці, і робиться вона одним кліком по пункту меню.
  Array.prototype.forEach.call(document.querySelectorAll('.admin-save'), function (bar) {
    var form = bar.closest('form');
    if (!form) return;
    var note = bar.querySelector('.admin-save-note');
    var saved = note ? note.textContent : '';
    var dirty = false;

    function mark() {
      if (dirty) return;
      dirty = true;
      bar.classList.add('is-dirty');
      if (note) note.textContent = 'Є незбережені зміни';
    }
    form.addEventListener('input', mark);
    form.addEventListener('change', mark);
    form.addEventListener('submit', function () { dirty = false; });
    window.addEventListener('beforeunload', function (e) {
      if (!dirty) return;
      e.preventDefault();
      e.returnValue = '';
    });

    // Видалення позначають галкою, а виконується воно аж при збереженні —
    // тому перепитуємо саме тут і називаємо поіменно, що зникне.
    form.addEventListener('submit', function (e) {
      var del = form.querySelectorAll('.js-del:checked');
      if (!del.length) return;
      var names = Array.prototype.map.call(del, function (box) {
        var row = box.closest('tr'), field = row && row.querySelector('input[type=text]');
        return field && field.value ? field.value : (row ? row.cells[0].textContent.trim() : '');
      }).filter(Boolean);
      var text = 'Буде видалено назавжди (' + del.length + '):\n· ' + names.join('\n· ') + '\n\nПродовжити?';
      if (!window.confirm(text)) { e.preventDefault(); dirty = true; }
    });
    if (note && saved === '') note.textContent = 'Усі зміни збережено';
  });

  // Списки на телефоні: таблиця розкладається на стос карток.
  //
  // Вісім колонок на 390px давали горизонтальну прокрутку з nowrap: щоб
  // побачити статус замовлення, продавець тягнув таблицю вбік, і жодного рядка
  // не було видно цілком. Картка показує той самий рядок згори вниз —
  // прокрутка лишається одна, вертикальна.
  //
  // Підписи беремо з шапки таблиці, тому шаблонів це не торкається взагалі:
  // додали колонку — вона підпишеться сама. Саме перемикання робить CSS у
  // медіазапиті, тут лише розмітка; на ширшому екрані таблиця лишається
  // таблицею, і ці атрибути ні на що не впливають.
  //
  // Таблиці-матриці (магазин × варіант, масове редагування) так розкладати не
  // можна: там клітинка означає перетин двох заголовків, і підпис з одного
  // бреше. Впізнаємо їх за обʼєднаними клітинками в шапці або другим її
  // ярусом — окремий клас проставляти в шаблонах не доводиться.
  Array.prototype.forEach.call(document.querySelectorAll('table.tbl'), function (tbl) {
    if (tbl.classList.contains('tbl-grid')) return;

    // Рядок шапки — той, де всі клітинки <th>. Саме «всі»: рядок-підзаголовок
    // усередині тіла теж має <th>, і ховати його не можна.
    function isHead(row) {
      if (!row || !row.cells.length) return false;
      for (var i = 0; i < row.cells.length; i++) if (row.cells[i].tagName !== 'TH') return false;
      return true;
    }
    var rows = Array.prototype.slice.call(tbl.rows);
    var head = null, headAt = -1;
    for (var r = 0; r < rows.length; r++) if (isHead(rows[r])) { head = rows[r]; headAt = r; break; }
    if (!head) return;
    if (isHead(rows[headAt + 1])) return;                  // двоярусна шапка — матриця
    for (var h = 0; h < head.cells.length; h++) {
      var c = head.cells[h];
      if (c.colSpan > 1 || c.rowSpan > 1) return;          // згруповані колонки — теж матриця
    }

    var labels = Array.prototype.map.call(head.cells, function (th) { return th.textContent.trim(); });
    head.setAttribute('data-head', '');
    tbl.setAttribute('data-cards', '');

    rows.forEach(function (row) {
      if (row === head || isHead(row)) return;
      var titled = false;
      Array.prototype.forEach.call(row.cells, function (td, i) {
        var label = labels[i] || '';
        // Роль вирішує, де клітинка стане в картці. Порядок у розмітці для
        // таблиці правильний, а для картки — ні: кнопка «Відкрити» стоїть
        // останньою колонкою, і в картці їй теж місце внизу, але на всю
        // ширину, а статус має бути вгорі, а не між сумою й датою.
        var role = '';
        // Саме прямий нащадок. Пілюля трапляється й усередині інших колонок —
        // у списку замовлень колонка «Магазини» показує стан кожної точки
        // окремо, — і за простим пошуком углиб вона видавала б себе за статус
        // усього замовлення: втрачала підпис і лізла нагору картки.
        if (td.querySelector(':scope > .status-pill')) role = 'status';
        else if (label === '' && td.querySelector('.btn')) role = 'action';
        else if (label === '' && td.querySelector('img') && td.textContent.trim() === '') role = 'media';
        else if (!titled && label !== '') { role = 'title'; titled = true; }

        if (role) td.setAttribute('data-role', role);
        // Підписуємо все, крім того, що говорить саме за себе: статус-пілюля,
        // фото й кнопка підпису не потребують, а «Статус: Нове» — це шум.
        if (label !== '' && role !== 'status' && role !== 'title') td.setAttribute('data-label', label);
      });
    });
  });

  // Режим підказок. «?» угорі вмикає його, далі клік по будь-якому полі з
  // data-help пояснює, що це поле робить. Розмітці досить двох речей:
  // кнопки з [data-help-toggle] і атрибутів data-help на полях.
  //
  // У режимі підказок клік лише пояснює й нічого не робить — інакше питання
  // «а що робить ця кнопка?» відповідалося б її натисканням.
  var helpToggle = document.querySelector('[data-help-toggle]');
  // Кнопка живе в спільному layout, тож трапляє й на сторінки, де підказок ще
  // немає. Там її просто прибираємо: «?», що нічого не пояснює, дратує більше,
  // ніж його відсутність.
  if (helpToggle && !document.querySelector('[data-help]')) {
    helpToggle.parentNode.removeChild(helpToggle);
    var emptyBar = document.querySelector('.help-bar');
    if (emptyBar) emptyBar.parentNode.removeChild(emptyBar);
    helpToggle = null;
  }
  if (helpToggle) {
    var pop = null;

    // Місце кнопки — поруч із заголовком сторінки (.admin-head розсовує їх по
    // краях сам). Заголовок малює кожна сторінка окремо, тому переносимо звідси,
    // а не дублюємо розмітку в двох десятках шаблонів.
    var headEl = document.querySelector('.admin-head');
    var barEl = document.querySelector('.help-bar');
    if (headEl) {
      headEl.appendChild(helpToggle);
      if (barEl) headEl.parentNode.insertBefore(barEl, headEl.nextSibling);
    }
    helpToggle.hidden = false;

    function helpOn() { return document.body.classList.contains('help-on'); }

    function closePop() { if (pop) { pop.parentNode.removeChild(pop); pop = null; } }

    var idleTitle = helpToggle.getAttribute('title') || 'Підказки';

    function setMode(on) {
      document.body.classList.toggle('help-on', on);
      helpToggle.classList.toggle('is-on', on);
      helpToggle.setAttribute('aria-pressed', on ? 'true' : 'false');
      // Підпис міняється разом зі станом: у режимі підказок кнопка вже не
      // «показати довідку», а єдиний очевидний вихід із нього
      helpToggle.setAttribute('title', on ? 'Вимкнути підказки (Esc)' : idleTitle);
      helpToggle.setAttribute('aria-label', on ? 'Вимкнути підказки' : 'Показати підказки');
      if (!on) closePop();
    }

    function showPop(host) {
      closePop();
      pop = document.createElement('div');
      pop.className = 'help-pop';
      var title = host.getAttribute('data-help-title');
      if (title) {
        var h = document.createElement('span');
        h.className = 'help-pop-title';
        h.textContent = title;
        pop.appendChild(h);
      }
      pop.appendChild(document.createTextNode(host.getAttribute('data-help')));
      document.body.appendChild(pop);

      // під полем, але не за краєм екрана — інакше підказка до правої колонки
      // з'їжджає за межі сторінки й читати її нічим
      var r = host.getBoundingClientRect();
      var docEl = document.documentElement;
      // Ширину ріжемо саме по clientWidth: у CSS довелося б писати 100vw, а він
      // враховує смугу прокрутки, і на вузькому екрані підказка вилазила б за край
      pop.style.maxWidth = (docEl.clientWidth - 28) + 'px';
      var maxLeft = window.pageXOffset + docEl.clientWidth - pop.offsetWidth - 14;
      var left = Math.max(window.pageXOffset + 14, Math.min(r.left + window.pageXOffset, maxLeft));
      var top = r.bottom + window.pageYOffset + 8;
      // не влазить донизу — показуємо над полем
      if (r.bottom + pop.offsetHeight + 20 > docEl.clientHeight && r.top > pop.offsetHeight + 20) {
        top = r.top + window.pageYOffset - pop.offsetHeight - 8;
      }
      pop.style.left = left + 'px';
      pop.style.top = top + 'px';
    }

    helpToggle.addEventListener('click', function (e) {
      e.preventDefault();
      setMode(!helpOn());
    });

    // Гасимо дію ДО того, як вона станеться: focus, нативний список у <select>
    // і галка в чекбоксі народжуються на mousedown, а не на click
    document.addEventListener('mousedown', function (e) {
      if (!helpOn() || !e.target.closest) return;
      if (e.target.closest('[data-help-toggle]') || e.target.closest('.help-pop')) return;
      if (e.target.closest('[data-help]')) e.preventDefault();
    }, true);

    document.addEventListener('click', function (e) {
      if (!helpOn() || !e.target.closest) return;
      if (e.target.closest('[data-help-toggle]') || e.target.closest('.help-pop')) return;
      var host = e.target.closest('[data-help]');
      if (host) {
        e.preventDefault();
        e.stopPropagation();
        showPop(host);
        return;
      }
      closePop(); // клік мимо — ховаємо підказку, але з режиму не виходимо
    }, true);

    // Форма не має зберегтися від того, що в неї тицьнули з питанням
    document.addEventListener('submit', function (e) { if (helpOn()) e.preventDefault(); }, true);

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && helpOn()) setMode(false);
    });
    window.addEventListener('resize', closePop);
  }

  function urlB64(s) {
    var pad = '='.repeat((4 - s.length % 4) % 4);
    var b64 = (s + pad).replace(/-/g, '+').replace(/_/g, '/');
    var raw = atob(b64), arr = new Uint8Array(raw.length);
    for (var i = 0; i < raw.length; i++) arr[i] = raw.charCodeAt(i);
    return arr;
  }

  /*
   * Поле файлу всередині звичайної форми.
   *
   * Нічого не відправляє: людина обирає файл і зберігає форму цілком, як і
   * решту полів. Уся робота тут — показати назву обраного файлу, бо системне
   * поле ми сховали, і без підпису людина не знає, чи вибір зарахувався.
   */
  Array.prototype.forEach.call(document.querySelectorAll('.file-field'), function (field) {
    var input = field.querySelector('input[type=file]');
    var name = field.querySelector('.file-name');
    var btn = field.querySelector('[data-file-btn]');
    if (!input || !btn) return;
    btn.addEventListener('click', function () { input.click(); });
    input.addEventListener('change', function () {
      var f = input.files && input.files[0];
      if (!name) return;
      name.textContent = f ? f.name : (name.dataset.empty || 'Файл не обрано');
      name.classList.toggle('has-file', !!f);
    });
  });
})();

/*
 * Зона перетягування для завантаження фото.
 *
 * Окремим модулем, а не всередині сторінки: тим самим користуються
 * медіа-бібліотека й вікно вибору фото, і розійтись їм не можна — це те саме
 * завантаження в ту саму бібліотеку.
 *
 * Чому не просто кнопка. «Завантажити фото» — одна дія в голові людини, а
 * системне поле розкладає її на дві: обрати й надіслати. Перетягування ж
 * узагалі не потребує ані першого, ані другого — файл тягнуть із теки, і це
 * той рефлекс, з яким приходять із будь-якого сучасного інструмента.
 *
 * Файли йдуть ПО ЧЕРЗІ, а не всі разом. Паралельне надсилання десяти фото з
 * телефона забиває канал так, що перше з них доходить пізніше, ніж дійшли б
 * усі десять поспіль, — а на екрані при цьому не рухається нічого.
 */
window.BofuDrop = (function () {
  function attach(zone, opts) {
    if (!zone || zone.dataset.bound) return null;
    zone.dataset.bound = '1';

    var input = zone.querySelector('input[type=file]');
    var note = zone.querySelector('.dropzone-note');
    var busy = false;

    function say(text, kind) {
      if (!note) return;
      note.textContent = text || '';
      note.className = 'dropzone-note' + (kind ? ' is-' + kind : '');
    }

    // Клік по зоні відкриває провідник — той самий сценарій для тих, хто не
    // перетягує. Клік по самому input не ловимо: він усередині й дав би
    // нескінченну рекурсію
    zone.addEventListener('click', function (e) {
      if (busy || e.target === input) return;
      input.click();
    });
    if (input) input.addEventListener('change', function () {
      send(Array.prototype.slice.call(input.files));
      input.value = '';          // щоб той самий файл можна було обрати вдруге
    });

    ['dragenter', 'dragover'].forEach(function (ev) {
      zone.addEventListener(ev, function (e) {
        e.preventDefault(); e.stopPropagation();
        if (!busy) zone.classList.add('is-over');
      });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
      zone.addEventListener(ev, function (e) {
        e.preventDefault(); e.stopPropagation();
        zone.classList.remove('is-over');
      });
    });
    zone.addEventListener('drop', function (e) {
      if (busy) return;
      var dt = e.dataTransfer;
      send(dt && dt.files ? Array.prototype.slice.call(dt.files) : []);
    });

    function send(files) {
      // Тягнуть у вікно й теки, і pdf, і будь-що: беремо лише зображення, а
      // про відкинуте кажемо — мовчазна пропажа виглядає як поломка
      var images = files.filter(function (f) { return /^image\//.test(f.type); });
      var skipped = files.length - images.length;
      if (!images.length) {
        say(skipped ? 'Це не зображення — беремо лише фото' : '', skipped ? 'bad' : '');
        return;
      }
      busy = true;
      zone.classList.add('is-busy');
      var done = 0, failed = 0;

      (function next() {
        if (!images.length) {
          busy = false;
          zone.classList.remove('is-busy');
          var msg = done ? ('Додано фото: ' + done) : '';
          if (failed) msg += (msg ? ', ' : '') + 'не вдалося: ' + failed;
          if (skipped) msg += (msg ? ', ' : '') + 'пропущено не-фото: ' + skipped;
          say(msg, failed ? 'bad' : 'ok');
          if (opts.onAll) opts.onAll(done);
          return;
        }
        var file = images.shift();
        say('Завантажую ' + file.name + '…' + (images.length ? ' (лишилось ' + (images.length + 1) + ')' : ''));
        var cell = opts.onStart ? opts.onStart(file) : null;

        var fd = new FormData();
        fd.append('_csrf', opts.csrf);
        fd.append('_action', 'upload');
        fd.append('format', 'json');
        fd.append(opts.field || 'image', file);

        fetch(opts.url, { method: 'POST', body: fd, credentials: 'same-origin' })
          .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
          .then(function (d) {
            if (d && d.ok) { done++; if (opts.onDone) opts.onDone(d, cell); }
            else { failed++; if (opts.onFail) opts.onFail(cell, file); }
            next();
          })
          .catch(function () { failed++; if (opts.onFail) opts.onFail(cell, file); next(); });
      })();
    }

    return { say: say };
  }

  /** Превʼю того самого файлу, поки він летить на сервер — щоб екран не мовчав */
  function preview(file) {
    return URL.createObjectURL(file);
  }

  /** Шлях до зменшеної копії — те саме правило, що в Images::thumbPath */
  function thumbOf(path) {
    return path.replace(/\.(\w+)$/, '-thumb.$1');
  }

  // Password toggle function to show/hide hidden key values when typing/editing
  function initPasswordToggles() {
    var inputs = document.querySelectorAll('input[type="password"]');
    Array.prototype.forEach.call(inputs, function (input) {
      if (input.dataset.hasToggle) return;
      input.dataset.hasToggle = 'true';

      var wrapper = document.createElement('div');
      wrapper.className = 'password-toggle-wrapper';
      input.parentNode.insertBefore(wrapper, input);
      wrapper.appendChild(input);

      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'password-toggle-btn';
      btn.setAttribute('aria-label', 'Показати пароль');
      btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>';

      wrapper.appendChild(btn);

      btn.addEventListener('click', function (e) {
        e.preventDefault();
        
        // Populate the field with saved value if it is empty and has a saved value
        if (input.value === '' && input.dataset.saved) {
          input.value = input.dataset.saved;
        }

        if (input.type === 'password') {
          input.type = 'text';
          btn.setAttribute('aria-label', 'Приховати пароль');
          btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>';
        } else {
          input.type = 'password';
          btn.setAttribute('aria-label', 'Показати пароль');
          btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>';
        }
      });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initPasswordToggles);
  } else {
    initPasswordToggles();
  }

  return { attach: attach, preview: preview, thumbOf: thumbOf };
})();
