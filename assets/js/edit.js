/**
 * Режим редагування сайту.
 *
 * Блоки позначені в шаблонах атрибутом data-ce (ключ блоку) і, де це можливо,
 * data-cef (яке саме поле показує цей елемент). Клік по зоні відкриває панель
 * із людськими підписами полів; збереження йде одним запитом і, якщо є куди,
 * оновлює текст просто на сторінці — без перезавантаження, щоб було одразу
 * видно, що вийшло.
 *
 * Там, де підмінити текст на місці не можна (списки, лічильник з анімацією,
 * адреса в атрибуті), сторінка перезавантажується. Це навмисно: показати
 * «збережено» і лишити на екрані старе — гірше за секунду очікування.
 */
(function () {
  var CFG = window.BOFU_EDIT;
  if (!CFG) return;

  var base = CFG.base.replace(/\/$/, '');
  var panel = document.getElementById('cePanel');
  var body = document.getElementById('cePanelBody');
  var labelEl = document.getElementById('cePanelLabel');
  var whereEl = document.getElementById('cePanelWhere');
  var noteEl = document.getElementById('cePanelNote');
  var saveBtn = document.getElementById('ceSave');
  var current = null;   // опис відкритого блоку з сервера
  var dirty = false;

  // ---------- дрібні помічники ----------

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text != null) n.textContent = text;
    return n;
  }

  function note(text, isError) {
    noteEl.textContent = text || '';
    noteEl.classList.toggle('is-error', !!isError);
  }

  function assetUrl(path) { return base + '/assets/' + String(path).replace(/^\//, ''); }

  // ---------- підсвітка зон ----------

  function zones() {
    return Array.prototype.slice.call(document.querySelectorAll('[data-ce]'));
  }

  var labelOf = {};

  /** Список блоків цієї сторінки у смужці + підпис на самій зоні */
  function buildList() {
    var menu = document.getElementById('ceListMenu');
    var countEl = document.getElementById('ceListCount');
    var seen = {};
    var items = [];
    zones().forEach(function (z) {
      var key = z.dataset.ce;
      if (seen[key]) return;
      seen[key] = true;
      items.push({ key: key, node: z });
    });
    countEl.textContent = String(items.length);
    menu.innerHTML = '';
    if (!items.length) {
      menu.appendChild(el('div', 'ce-bar-menu-empty', 'На цій сторінці редагованих блоків немає'));
      return;
    }
    items.forEach(function (it) {
      // поки не приїхали назви — показуємо ключ: список має працювати
      // і тоді, коли запит за підписами не дійшов
      var b = el('button', 'ce-bar-menu-item', labelOf[it.key] || it.key);
      b.type = 'button';
      b.dataset.key = it.key;
      b.addEventListener('click', function () {
        menu.hidden = true;
        it.node.scrollIntoView({ block: 'center', behavior: 'smooth' });
        open(it.key);
      });
      menu.appendChild(b);
    });
  }

  // ---------- значки на зонах ----------

  /**
   * Кожна редагована зона дістає значок олівця. Значки лежать окремим шаром
   * над сторінкою, а не всередині зон: на <img> псевдоелемент не намалюєш, а
   * вкладати щось усередину чужої розмітки — ламати вирівнювання там, де його
   * ніхто не просив. Шар нічого не ловить (pointer-events:none), тож клац по
   * значку відкриває зону, як і клац будь-де в ній.
   */
  var pinLayer = null;
  var pins = [];

  function buildPins() {
    if (!pinLayer) {
      pinLayer = el('div', 'ce-pins');
      document.body.appendChild(pinLayer);
    }
    pinLayer.innerHTML = '';
    pins = zones().map(function (z) {
      var p = el('span', 'ce-pin', '✎');
      p.title = 'Редагувати цей блок';
      // Значок стоїть на межі зони й наполовину виступає назовні — клац по
      // ньому інакше падав би повз зону, у порожнє місце поруч. Тому він і
      // сам відкриває блок: те, що виглядає кнопкою, має нею бути.
      p.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        z.scrollIntoView({ block: 'center', behavior: 'smooth' });
        open(z.dataset.ce);
      });
      pinLayer.appendChild(p);
      return { node: z, pin: p };
    });
    placePins();
  }

  /** Розставити значки по зонах. Синхронно — див. schedulePins() */
  function placePins() {
    var vh = window.innerHeight, vw = window.innerWidth;
    pins.forEach(function (it) {
      var r = it.node.getBoundingClientRect();
      // зону не видно (прокрутили повз, схована в мобільному меню) — ховаємо
      // й значок: інакше він висить над порожнім місцем
      var off = !r.width || !r.height || r.bottom < 0 || r.top > vh;
      it.pin.classList.toggle('is-off', off);
      if (off) return;
      // тримаємо значок у межах екрана: у широкої зони правий край може
      // виявитись за ним, і зона виглядала б непозначеною
      it.pin.style.left = Math.max(4, Math.min(vw - 26, r.right - 11)) + 'px';
      it.pin.style.top = Math.max(4, Math.min(vh - 26, r.top - 5)) + 'px';
    });
  }

  /**
   * Те саме, але не частіше разу на кадр — для прокрутки й зміни розміру.
   * Перше розставляння йде повз rAF навмисно: у прихованій вкладці кадрів
   * немає взагалі, і значки лишалися б купкою в кутку до першого показу.
   */
  var placing = false;
  function schedulePins() {
    if (placing || !pins.length) return;
    placing = true;
    requestAnimationFrame(function () { placing = false; placePins(); });
  }

  window.addEventListener('scroll', schedulePins, { passive: true });
  window.addEventListener('resize', schedulePins);
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) placePins();
  });

  /** Назви блоків — одним запитом на сторінку */
  function loadLabels() {
    return fetch(base + '/edit/blocks', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || !d.ok) return;
        labelOf = d.labels || {};
        zones().forEach(function (z) {
          if (labelOf[z.dataset.ce]) z.setAttribute('data-ce-label', labelOf[z.dataset.ce]);
        });
      })
      .catch(function () {});
  }

  // ---------- панель ----------

  function open(key) {
    fetch(base + '/edit/block?key=' + encodeURIComponent(key), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || !d.ok) { alert((d && d.error) || 'Не вдалося відкрити блок'); return; }
        current = d;
        labelOf[d.key] = d.label;
        render();
      })
      .catch(function () { alert('Немає звʼязку з сайтом. Спробуйте ще раз.'); });
  }

  function close() {
    if (dirty && !confirm('Закрити без збереження? Зміни в цьому блоці зникнуть.')) return;
    panel.hidden = true;
    panel.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('ce-panel-open');
    document.querySelectorAll('.ce-active').forEach(function (n) { n.classList.remove('ce-active'); });
    current = null;
    dirty = false;
  }

  function render() {
    labelEl.textContent = current.label;
    whereEl.textContent = current.where;
    note('');
    body.innerHTML = '';
    dirty = false;
    current.fields.forEach(function (f) { body.appendChild(fieldRow(f)); });

    panel.hidden = false;
    panel.setAttribute('aria-hidden', 'false');
    document.body.classList.add('ce-panel-open');

    document.querySelectorAll('.ce-active').forEach(function (n) { n.classList.remove('ce-active'); });
    document.querySelectorAll('[data-ce="' + cssEscape(current.key) + '"]').forEach(function (n) {
      n.classList.add('ce-active');
    });
    var first = body.querySelector('input, textarea');
    if (first) first.focus();
  }

  function cssEscape(s) { return String(s).replace(/"/g, '\\"'); }

  function fieldRow(f) {
    var wrap = el('div', 'ce-field');
    wrap.appendChild(el('label', null, f.label));
    var input;

    if (f.type === 'image') {
      input = imageField(f);
    } else if (f.type === 'pairs') {
      input = listField(f, 'Питання', 'Відповідь');
    } else if (f.type === 'gallery') {
      input = galleryField(f);
    } else if (f.type === 'textarea' || f.type === 'lines') {
      input = el('textarea');
      input.rows = f.type === 'lines' ? 4 : 6;
      input.value = f.value || '';
    } else {
      input = el('input');
      input.type = f.type === 'url' ? 'url' : 'text';
      input.value = f.value || '';
      if (f.type === 'url') input.placeholder = 'https://';
    }

    input.classList.add('ce-input');
    input.dataset.field = f.name;
    input.dataset.type = f.type;
    input.addEventListener('input', function () { dirty = true; note(''); });
    wrap.appendChild(input);
    if (f.hint) wrap.appendChild(el('p', 'ce-hint', f.hint));
    return wrap;
  }

  /** Фото: показуємо поточне й даємо вибрати інше з медіа-бібліотеки */
  function imageField(f) {
    var box = el('div', 'ce-image');
    box.dataset.value = f.value || '';
    var img = el('img');
    img.src = f.value ? assetUrl(f.value) : '';
    img.alt = '';
    box.appendChild(img);

    if (f.readonly || !CFG.canImages) {
      box.appendChild(el('p', 'ce-hint', 'Змінити фото може лише той, кому відкрита медіа-бібліотека.'));
      return box;
    }
    var btn = el('button', 'btn btn-line btn-xs', 'Обрати інше фото');
    btn.type = 'button';
    btn.addEventListener('click', function () {
      window.MediaPicker.open(function (path) {
        box.dataset.value = path;
        img.src = assetUrl(path);
        dirty = true;
        note('');
      });
    });
    box.appendChild(btn);
    return box;
  }

  /** Список пар: питання/відповідь */
  function listField(f, labelA, labelB) {
    var box = el('div', 'ce-list');
    (f.value || []).forEach(function (row) { box.appendChild(pairRow(row[0], row[1], labelA, labelB)); });
    var add = el('button', 'btn btn-line btn-xs', '+ Додати');
    add.type = 'button';
    add.addEventListener('click', function () {
      box.insertBefore(pairRow('', '', labelA, labelB), add);
      dirty = true;
    });
    box.appendChild(add);
    return box;
  }

  function pairRow(a, b, labelA, labelB) {
    var row = el('div', 'ce-list-row');
    var qa = el('input');
    qa.type = 'text'; qa.value = a || ''; qa.placeholder = labelA; qa.className = 'ce-list-a';
    var ans = el('textarea');
    ans.rows = 2; ans.value = b || ''; ans.placeholder = labelB; ans.className = 'ce-list-b';
    var del = el('button', 'ce-list-del', '×');
    del.type = 'button';
    del.title = 'Прибрати';
    del.addEventListener('click', function () { row.remove(); dirty = true; });
    row.appendChild(qa); row.appendChild(ans); row.appendChild(del);
    return row;
  }

  /** Галерея: підпис + фото, порядок як у списку */
  function galleryField(f) {
    var box = el('div', 'ce-list ce-gallery');
    var thumbs = (current && current.gallery_thumbs) || {};
    (f.value || []).forEach(function (row) { box.appendChild(galleryRow(row[0], row[1], thumbs)); });
    var add = el('button', 'btn btn-line btn-xs', '+ Додати фото');
    add.type = 'button';
    add.addEventListener('click', function () {
      window.MediaPicker.open(function (path) {
        box.insertBefore(galleryRow('', path, thumbs), add);
        dirty = true;
      });
    });
    if (CFG.canImages) box.appendChild(add);
    return box;
  }

  function galleryRow(title, path, thumbs) {
    var row = el('div', 'ce-list-row ce-gallery-row');
    row.dataset.path = path;
    var img = el('img');
    img.src = thumbs[path] || assetUrl(path);
    img.alt = '';
    var cap = el('input');
    cap.type = 'text'; cap.value = title || ''; cap.placeholder = 'Підпис під фото'; cap.className = 'ce-list-a';
    var del = el('button', 'ce-list-del', '×');
    del.type = 'button';
    del.title = 'Прибрати з галереї';
    del.addEventListener('click', function () { row.remove(); dirty = true; });
    row.appendChild(img); row.appendChild(cap); row.appendChild(del);
    return row;
  }

  // ---------- збереження ----------

  function collect() {
    var values = {};
    Array.prototype.forEach.call(body.querySelectorAll('.ce-input'), function (node) {
      var type = node.dataset.type;
      var name = node.dataset.field;
      if (type === 'image') {
        values[name] = node.dataset.value || '';
      } else if (type === 'pairs') {
        values[name] = Array.prototype.map.call(node.querySelectorAll('.ce-list-row'), function (r) {
          return [r.querySelector('.ce-list-a').value, r.querySelector('.ce-list-b').value];
        });
      } else if (type === 'gallery') {
        values[name] = Array.prototype.map.call(node.querySelectorAll('.ce-list-row'), function (r) {
          return [r.querySelector('.ce-list-a').value, r.dataset.path];
        });
      } else {
        values[name] = node.value;
      }
    });
    return values;
  }

  /**
   * Підмінити текст на сторінці. Повертає false, якщо якесь зі змінених полів
   * ніде на сторінці не показане окремим елементом — тоді чесніше перезавантажити,
   * ніж лишити людину з думкою, що зміна не зберіглась.
   */
  function applyInPlace(key, display) {
    var ok = true;
    Object.keys(display || {}).forEach(function (field) {
      var sel = '[data-ce="' + cssEscape(key) + '"][data-cef="' + cssEscape(field) + '"]';
      var nodes = document.querySelectorAll(sel);
      if (!nodes.length) { ok = false; return; }
      nodes.forEach(function (n) {
        if (n.tagName === 'IMG') n.src = display[field];
        else n.textContent = display[field];
      });
    });
    return ok;
  }

  function save() {
    if (!current) return;
    var key = current.key;
    var values = collect();
    var hasList = current.fields.some(function (f) { return f.type === 'pairs' || f.type === 'gallery'; });
    saveBtn.disabled = true;
    note('Зберігаю…');

    fetch(base + '/edit/save', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CFG.csrf },
      body: JSON.stringify({ key: key, values: values })
    })
      .then(function (r) { return r.json().then(function (d) { return { status: r.status, data: d }; }); })
      .then(function (res) {
        if (!res.data || !res.data.ok) {
          note((res.data && res.data.error) || 'Не вдалося зберегти', true);
          return;
        }
        dirty = false;
        current = res.data;
        // порожнє поле означає, що на сайті знову зʼявиться типовий текст із
        // шаблону, а його ми тут не знаємо — такий випадок теж перемальовує сервер
        var hasEmpty = Object.keys(res.data.display || {}).some(function (f) { return res.data.display[f] === ''; });
        if (hasList || hasEmpty || !applyInPlace(key, res.data.display)) {
          sessionStorage.setItem('ceSaved', key);
          location.reload();
          return;
        }
        note('Збережено ✓');
        placePins();   // текст змінив висоту — значки нижче з'їхали разом із ним
        setTimeout(function () { if (current && current.key === key) note(''); }, 2500);
      })
      .catch(function () { note('Немає звʼязку з сайтом. Спробуйте ще раз.', true); })
      .finally(function () { saveBtn.disabled = false; });
  }

  // ---------- події ----------

  // Перехоплюємо клік у фазі занурення: усередині зон є посилання й кнопки,
  // і без цього клік по «Записатись» пішов би на сторонній сайт замість
  // відкриття блоку.
  document.addEventListener('click', function (e) {
    if (panel.contains(e.target) || document.getElementById('ceBar').contains(e.target)) return;
    if (e.target.closest('.modal-back')) return;             // вікно вибору фото
    var zone = e.target.closest('[data-ce]');
    if (!zone) return;
    e.preventDefault();
    e.stopPropagation();
    open(zone.dataset.ce);
  }, true);

  document.getElementById('ceClose').addEventListener('click', close);
  document.getElementById('ceCancel').addEventListener('click', close);
  saveBtn.addEventListener('click', save);

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !panel.hidden && !document.querySelector('.modal-back.open')) close();
  });

  var hi = document.getElementById('ceHighlight');
  function applyHighlight() { document.body.classList.toggle('ce-show', hi.checked); }
  hi.addEventListener('change', function () {
    applyHighlight();
    try { localStorage.setItem('ceShow', hi.checked ? '1' : '0'); } catch (err) {}
  });
  try { if (localStorage.getItem('ceShow') === '0') hi.checked = false; } catch (err) {}
  applyHighlight();

  var listBtn = document.getElementById('ceListBtn');
  var listMenu = document.getElementById('ceListMenu');
  listBtn.addEventListener('click', function () {
    listMenu.hidden = !listMenu.hidden;
    listBtn.setAttribute('aria-expanded', listMenu.hidden ? 'false' : 'true');
  });
  document.addEventListener('click', function (e) {
    if (!listMenu.hidden && !listMenu.contains(e.target) && e.target !== listBtn) listMenu.hidden = true;
  });

  document.body.classList.add('ce-on');
  loadLabels().then(buildList);
  buildPins();
  // фото й шрифти доїжджають після onload і зсувають усе нижче за собою
  window.addEventListener('load', placePins);

  // після перезавантаження показуємо, що саме збереглося — інакше зміна
  // виглядає так, ніби сторінка просто моргнула
  var saved = sessionStorage.getItem('ceSaved');
  if (saved) {
    sessionStorage.removeItem('ceSaved');
    var target = document.querySelector('[data-ce="' + cssEscape(saved) + '"]');
    if (target) {
      target.classList.add('ce-just-saved');
      setTimeout(function () { target.classList.remove('ce-just-saved'); }, 2200);
    }
  }
})();
