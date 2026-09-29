// Форма товару: характеристики зі словника, варіанти, генератор комбінацій
(function () {
  var wrap = document.getElementById('attrRows');
  if (!wrap) return;

  var base = ((window.BOFU && BOFU.base) || '/').replace(/\/$/, '');
  var dict = (window.BOFU_DICT || []).map(function (a) {
    return {
      id: +a.id, name: a.name, type: a.type, unit: a.unit || '',
      filterable: +a.filterable, category_ids: (a.category_ids || []).map(Number),
      values: (a.values || []).map(function (v) { return { id: +v.id, value: v.value, color: v.color || '' }; })
    };
  });
  var catSel = document.getElementById('catSelect');
  var form = document.getElementById('productForm');

  function catId() { return catSel ? +catSel.value : 0; }
  function byId(id) { for (var i = 0; i < dict.length; i++) if (dict[i].id === +id) return dict[i]; return null; }
  // характеристика доречна, якщо вона загальна або прив'язана до категорії товару
  function relevant(a) { return !a.category_ids.length || a.category_ids.indexOf(catId()) >= 0; }

  // --- характеристики ---------------------------------------------------

  function fillAttrSelect(sel, selectedId) {
    sel.innerHTML = '';
    sel.add(new Option('— оберіть характеристику —', ''));
    [['Для цієї категорії', dict.filter(relevant)],
     ['Інші характеристики', dict.filter(function (a) { return !relevant(a); })]
    ].forEach(function (g) {
      if (!g[1].length) return;
      var og = document.createElement('optgroup');
      og.label = g[0];
      g[1].forEach(function (a) { og.appendChild(new Option(a.name + (a.unit ? ', ' + a.unit : ''), a.id)); });
      sel.appendChild(og);
    });
    sel.add(new Option('➕ Нова характеристика…', '__new'));
    sel.value = selectedId ? String(selectedId) : '';
    if (sel.selectedIndex < 0) sel.value = '';
  }

  function renderValue(row, data) {
    var cell = row.querySelector('.gr-val');
    var aid = row.querySelector('.a-sel').value;
    var a = aid && aid !== '__new' ? byId(aid) : null;
    cell.innerHTML = '';

    if (!a && aid !== '__new') {
      cell.innerHTML = '<span class="dim">спершу оберіть характеристику</span>';
      return;
    }
    var type = a ? a.type : 'select';

    if (a && (type === 'select' || type === 'color') && !(data && data.freeText)) {
      var sel = document.createElement('select');
      sel.className = 'v-sel';
      sel.add(new Option('— оберіть значення —', ''));
      a.values.forEach(function (v) { sel.add(new Option(v.value, v.id)); });
      sel.add(new Option('➕ інше значення…', '__new'));
      sel.value = data && data.value_id ? String(data.value_id) : '';
      if (sel.selectedIndex < 0) sel.value = '';
      sel.addEventListener('change', function () {
        if (sel.value === '__new') renderValue(row, { freeText: true, value: '' });
        reindex();
      });
      cell.appendChild(sel);
      return;
    }

    var inp = document.createElement('input');
    inp.type = type === 'number' ? 'number' : 'text';
    if (type === 'number') inp.step = 'any';
    inp.className = 'v-txt';
    inp.placeholder = a && a.unit ? 'значення, ' + a.unit : 'значення';
    inp.value = (data && data.value) || '';
    cell.appendChild(inp);

    // для списочних характеристик даємо змогу повернутись до вибору зі словника
    if (a && (type === 'select' || type === 'color')) {
      var back = document.createElement('button');
      back.type = 'button';
      back.className = 'btn btn-line btn-xs';
      back.style.marginTop = '6px';
      back.textContent = '← обрати зі списку';
      back.title = 'Нове значення додасться у словник після збереження';
      back.addEventListener('click', function () { renderValue(row, {}); reindex(); });
      cell.appendChild(back);
    }
    inp.addEventListener('input', reindex);
  }

  function addAttrRow(data) {
    data = data || {};
    var row = document.createElement('div');
    row.className = 'grid-row attr-row';
    row.innerHTML =
      '<div class="gr-main">' +
        '<select class="a-sel"></select>' +
        '<input type="text" class="a-new" placeholder="Назва нової характеристики" style="display:none;margin-top:6px">' +
      '</div>' +
      '<div class="gr-val"></div>' +
      '<button type="button" class="btn btn-danger btn-xs row-del" title="Прибрати характеристику">✕</button>';

    var sel = row.querySelector('.a-sel');
    var newName = row.querySelector('.a-new');
    fillAttrSelect(sel, data.attribute_id);

    function syncNew() { newName.style.display = sel.value === '__new' ? '' : 'none'; }
    sel.addEventListener('change', function () { syncNew(); renderValue(row, {}); reindex(); });
    newName.addEventListener('input', reindex);
    row.querySelector('.row-del').addEventListener('click', function () { row.remove(); reindex(); });

    wrap.appendChild(row);
    syncNew();
    renderValue(row, { value_id: data.value_id, value: data.value, freeText: data.attribute_id && !data.value_id });
    reindex();
    return row;
  }

  function setName(row, sel, name) {
    var el = row.querySelector(sel);
    if (el) el.name = name;
  }

  // імена полів = позиція рядка, тому порядок у формі = порядок на сайті
  function reindex() {
    var i = 0;
    wrap.querySelectorAll('.attr-row').forEach(function (row) {
      var n = i++;
      setName(row, '.a-sel', 'attr[' + n + '][attribute_id]');
      setName(row, '.a-new', 'attr[' + n + '][new_name]');
      setName(row, '.v-sel', 'attr[' + n + '][value_id]');
      setName(row, '.v-txt', 'attr[' + n + '][value]');
    });
  }

  (window.BOFU_PRODUCT_ATTRS || []).forEach(function (r) { addAttrRow(r); });
  if (!wrap.children.length) addAttrRow({});
  document.getElementById('attrAdd').addEventListener('click', function () { addAttrRow({}); });

  // --- варіанти ---------------------------------------------------------

  var vRows = document.getElementById('variantRows');
  var newVariantSeq = 0;

  if (vRows) {
    vRows.querySelectorAll('.variant-row').forEach(bindVariantDelete);
    document.getElementById('variantAdd').addEventListener('click', function () {
      var k = 'new' + (++newVariantSeq);
      var row = document.createElement('div');
      row.className = 'grid-row variant-row';
      row.innerHTML =
        '<div class="gr-main"><input type="text" name="variant[' + k + '][name]" placeholder="Назва варіанта" required></div>' +
        '<input type="number" step="0.01" name="variant[' + k + '][price]" placeholder="ціна">' +
        '<input type="text" name="variant[' + k + '][sku]" placeholder="артикул">' +
        '<input type="text" name="variant[' + k + '][barcode]" placeholder="штрихкод" inputmode="numeric" autocomplete="off">' +
        // Вага й стеля знижки належать фасовці так само, як ціна: рядок без них
        // мав би менше колонок, ніж наявні варіанти, і сітка розʼїхалась би
        '<input type="text" name="variant[' + k + '][weight]" placeholder="вага, кг">' +
        '<input type="text" name="variant[' + k + '][max_discount]" placeholder="стеля, %">' +
        '<label class="checkbox"><input type="checkbox" name="variant[' + k + '][active]" checked> вкл.</label>' +
        '<button type="button" class="btn btn-danger btn-xs row-del" title="Прибрати">✕</button>';
      row.querySelector('.row-del').addEventListener('click', function () { row.remove(); });
      vRows.appendChild(row);
      row.querySelector('input').focus();
    });
  }

  // наявний варіант не зникає одразу — позначається на видалення до збереження
  function bindVariantDelete(row) {
    var btn = row.querySelector('.row-del');
    var flag = row.querySelector('input[name$="[_delete]"]');
    if (!btn || !flag) return;
    btn.addEventListener('click', function () {
      var on = !row.classList.contains('deleted');
      row.classList.toggle('deleted', on);
      flag.disabled = !on;
      flag.value = on ? '1' : '';
      btn.textContent = on ? '↩' : '✕';
      btn.title = on ? 'Скасувати видалення' : 'Видалити варіант';
      row.querySelectorAll('input:not([name$="[_delete]"]), select').forEach(function (el) { el.disabled = on; });
    });
  }

  // --- генератор комбінацій --------------------------------------------

  var genAxes = document.getElementById('genAxes');

  function renderGen() {
    if (!genAxes) return;
    var list = dict.filter(function (a) {
      return (a.type === 'select' || a.type === 'color') && a.values.length && relevant(a);
    });
    if (!list.length) {
      genAxes.innerHTML = '<p class="dim">Для цієї категорії немає характеристик зі списком значень. ' +
        '<a href="' + base + '/admin/attributes">Додайте їх у словник</a>, і тут з\'явиться генератор.</p>';
      return;
    }
    genAxes.innerHTML = list.map(function (a) {
      return '<div class="gen-axis"><b>' + esc(a.name) + '</b><div class="pick-grid">' +
        a.values.map(function (v) {
          return '<label class="checkbox"><input type="checkbox" name="gen[' + a.id + '][]" value="' + v.id + '"> ' +
            (v.color ? '<i class="swatch" style="background:' + esc(v.color) + '"></i> ' : '') + esc(v.value) + '</label>';
        }).join('') + '</div></div>';
    }).join('');
  }

  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  renderGen();

  // зміна категорії одразу оновлює доречні характеристики й генератор
  if (catSel) catSel.addEventListener('change', function () {
    wrap.querySelectorAll('.attr-row').forEach(function (row) {
      var sel = row.querySelector('.a-sel');
      fillAttrSelect(sel, sel.value && sel.value !== '__new' ? sel.value : '');
    });
    renderGen();
    reindex();
  });

  /*
   * Категорії — лише ті, що відповідають типу товару.
   *
   * Курс у категорії «Мед» нічого не ламає на вітрині (там він однаково не
   * зʼявиться), але ламає облік: фільтр за категорією перестає відповідати на
   * питання «скільки в мене курсів». Тому список звужується під обраний тип.
   *
   * Пункти саме ховаються, а не видаляються: тип міняють туди й назад, і
   * відновлювати викинуті з DOM довелося б із памʼяті.
   *
   * Забороняє все одно сервер (Products::categoryTypeError) — адреса
   * збереження відома, і category_id підставляється в POST руками. Тут лише
   * зручність: не пропонувати те, що потім не збережеться.
   */
  var typeSel = document.querySelector('select[name="type"]');
  var catHint = document.getElementById('catTypeHint');
  var TYPE_LABELS = { product: 'Товар', service: 'Послуга', video: 'Відео', course: 'Курс' };
  function syncCategories() {
    if (!typeSel || !catSel) return;
    var want = typeSel.value || 'product';
    var fit = 0, current = null;
    Array.prototype.forEach.call(catSel.options, function (o) {
      var ok = (o.dataset.type || 'product') === want;
      o.hidden = !ok;
      o.disabled = !ok;
      if (ok) { fit++; if (current === null) current = o; }
      if (o.selected && !ok) o.selected = false;
    });
    // Обрана категорія не підійшла — беремо першу придатну, щоб форма не
    // лишалась із порожнім вибором, який мовчки провалиться при збереженні
    if (fit && !catSel.selectedOptions.length && current) current.selected = true;
    if (catHint) {
      if (fit === 0) {
        catHint.hidden = false;
        catHint.textContent = 'Для типу «' + (TYPE_LABELS[want] || want)
          + '» ще немає жодної категорії. Створіть її в розділі «Категорії» — інакше товар не збережеться.';
      } else {
        catHint.hidden = true;
      }
    }
  }
  if (typeSel) typeSel.addEventListener('change', syncCategories);
  syncCategories();

  // вимкнені поля не відправляються — знімаємо блокування перед сабмітом
  if (form) form.addEventListener('submit', function () {
    vRows && vRows.querySelectorAll('.variant-row.deleted').forEach(function (row) {
      var flag = row.querySelector('input[name$="[_delete]"]');
      if (flag) flag.disabled = false;
    });
  });
})();
