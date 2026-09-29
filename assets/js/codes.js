// Екран «Коди й штрихкоди»: камера, свій код, друк наліпок, виправлення описок.
//
// Рахує все сервер: і сам код (він виводиться з номера позиції — див.
// Barcode::make у PHP), і його малюнок. Тут лише те, що має статися без
// перезавантаження сторінки, бо коди проставляють підряд і чекати нікому.
(function () {
  var form = document.querySelector('form[action*="codes"]');
  if (!form || !window.CODES) return;

  /** Поле штрихкоду того рядка, у якому натиснули кнопку */
  function fieldOf(el) {
    var cell = el.closest('.code-cell');
    return cell ? cell.querySelector('[data-code-input]') : null;
  }

  function fresh(field) {
    field.classList.add('is-fresh');
    field.style.borderColor = '';
    var bad = field.closest('.code-cell').querySelector('.code-bad');
    if (bad) bad.remove();
  }

  document.addEventListener('click', function (e) {
    // ── свій код для однієї позиції ──────────────────────────────────────
    var gen = e.target.closest('[data-code-gen]');
    if (gen) {
      var cell = gen.closest('.code-cell');
      var field = fieldOf(gen);
      if (!field) return;
      if (field.value.trim() !== '' &&
          !window.confirm('У цій позиції вже є код ' + field.value.trim() + '. Замінити своїм?')) return;
      gen.disabled = true;
      fetch(CODES.genUrl + '?gen=' + encodeURIComponent(cell.getAttribute('data-code-row')))
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (!d || !d.code) return;
          field.value = d.code;
          fresh(field);
          // Малюнок з'явиться після збереження — його малює сервер, і показувати
          // наліпку для коду, якого ще немає в базі, було б обіцянкою наперед
          field.closest('.code-cell').classList.add('is-unsaved');
        })
        .catch(function () { window.alert('Не вдалося створити код — оновіть сторінку.'); })
        .finally(function () { gen.disabled = false; });
      return;
    }

    // ── виправити описку ─────────────────────────────────────────────────
    var fix = e.target.closest('[data-code-fix]');
    if (fix) {
      var f1 = fieldOf(fix);
      if (!f1) return;
      f1.value = fix.getAttribute('data-code-fix');
      fresh(f1);
      return;
    }

    // ── перенести код товару в його першу фасовку ────────────────────────
    var move = e.target.closest('[data-code-move]');
    if (move) {
      var row = move.closest('tr');
      var from = row.querySelector('[data-code-input]');
      var next = row.nextElementSibling;
      var to = next && next.classList.contains('variant-sub') ? next.querySelector('[data-code-input]') : null;
      if (!from || !to) return;
      to.value = from.value;
      fresh(to);
      from.value = '';
      move.parentNode.remove();
      return;
    }

    // ── друк наліпок ─────────────────────────────────────────────────────
    var pr = e.target.closest('[data-code-print]');
    if (pr) { printLabels(pr.closest('.code-cell')); return; }

    // ── камера ───────────────────────────────────────────────────────────
    var cam = e.target.closest('[data-code-scan]');
    if (!cam) return;
    var input = fieldOf(cam);
    if (!window.BofuScan || !input) return;
    window.BofuScan.open(function (code, ui) {
      input.value = code;
      fresh(input);
      ui.close();
      input.focus();
    }, {
      title: 'Наведіть камеру на штрихкод товару',
      onError: function (m) { window.alert(m); },
      onManual: function () { input.focus(); }
    });
  });

  /**
   * Наліпки на друк.
   *
   * Малюнок коду вже намальовано сервером у цьому ж рядку — беремо його як є й
   * розмножуємо на аркуш. Тому друкувати можна лише збережений код: поки він
   * не в базі, малюнка немає, і вигадувати його тут означало б другу копію
   * креслення штрихкоду в браузері.
   */
  function printLabels(cell) {
    var svg = cell.querySelector('.code-pic svg');
    if (!svg) {
      window.alert('Спершу збережіть код — тоді зʼявиться малюнок, який можна друкувати.');
      return;
    }
    var title = cell.getAttribute('data-code-title') || '';
    var one = '<div class="lbl"><div class="nm">' + esc(title) + '</div>' + svg.outerHTML + '</div>';
    var w = window.open('', '_blank');
    if (!w) { window.alert('Браузер заблокував вікно друку — дозвольте спливаючі вікна.'); return; }
    w.document.write(
      '<!DOCTYPE html><html lang="uk"><head><meta charset="utf-8"><title>Наліпки · ' + esc(title) + '</title>' +
      '<style>' +
      'body{font:13px system-ui,sans-serif;margin:12px;background:#fff;color:#000}' +
      '.bar{margin-bottom:12px}.bar button{font:inherit;padding:6px 12px;margin-right:6px;cursor:pointer}' +
      '.sheet{display:flex;flex-wrap:wrap;gap:6px}' +
      '.lbl{border:1px dashed #bbb;padding:6px;text-align:center;width:150px}' +
      '.nm{font-size:10px;line-height:1.2;margin-bottom:3px;height:24px;overflow:hidden}' +
      '.lbl svg{max-width:100%;height:auto}' +
      '@media print{.bar{display:none}.lbl{border-color:#eee}}' +
      '</style></head><body>' +
      '<div class="bar">Скільки наліпок: ' +
      [6, 12, 24, 48].map(function (n) { return '<button onclick="fill(' + n + ')">' + n + '</button>'; }).join('') +
      '<button onclick="window.print()">🖨 Друк</button></div>' +
      '<div class="sheet" id="sheet"></div>' +
      '<script>var one=' + JSON.stringify(one) + ';' +
      'function fill(n){document.getElementById("sheet").innerHTML=Array(n).fill(one).join("");}' +
      'fill(12);<\/script></body></html>');
    w.document.close();
  }

  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }
})();
