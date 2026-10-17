/*
 * Зона перетягування для завантаження фото (BofuDrop) — окремим файлом.
 * Підключається і в адмінпанелі (admin.js), і в режимі редагування на сайті (вікно вибору фото),
 * бо на сайті admin.js не завантажується, і без цього файлу клац по зоні нічого не відкриває.
 */
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
