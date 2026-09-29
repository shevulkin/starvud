/**
 * Каса на цьому пристрої: вкладка продавця сама несе чек у Device Manager.
 *
 * Потрібне лише в маршруті «device», де ключ підпису лежить у DM на тій самій
 * машині, де відкрита адмінка. Наш сервер до localhost продавця не достукається
 * ніколи — тому запит несе браузер: бере готове тіло з сайту, кладе його на
 * касу й повертає відповідь як є. Ні про формат чека, ні про постачальника
 * ПРРО він не знає.
 *
 * Обірваний звʼязок не страшний: у завдання незмінна мітка (tag), і повтор із
 * нею каса впізнає як ту саму спробу. Тому тут можна сміливо перезавантажувати
 * сторінку — другого чека з цього не вийде.
 */
(function () {
  var box = document.querySelector('[data-fiscal-runner]');
  var probeBtn = document.querySelector('[data-fiscal-probe]');
  if ((!box && !probeBtn) || !window.BOFU) return;

  var base = String(window.BOFU.base || '/').replace(/\/$/, '');
  var csrf = window.BOFU.csrf || '';
  var parentId = box.getAttribute('data-parent') || '';
  var statusEl = box.querySelector('[data-fiscal-status]');
  var left = 0;

  function say(text, kind) {
    if (!statusEl) return;
    statusEl.textContent = text;
    box.className = 'fiscal-runner is-' + (kind || 'work');
  }

  function post(path, data) {
    var body = new FormData();
    Object.keys(data).forEach(function (k) { body.append(k, data[k]); });
    // Токен заголовком, як і решта запитів адмінки (див. admin.js): у формі
    // він теж приймається, але заголовок не заважає читати тіло запиту.
    return fetch(base + path, {
      method: 'POST', body: body, credentials: 'same-origin',
      headers: { 'X-CSRF-Token': csrf, 'X-Requested-With': 'fetch' }
    }).then(function (r) { return r.json(); });
  }

  /**
   * Запит на касу. Таймаут свій, бо каса при проблемах із ДПС не відмовляє, а
   * дотискає запит — їхня документація радить не менше 20 секунд.
   */
  function toKasa(job) {
    var ctrl = typeof AbortController !== 'undefined' ? new AbortController() : null;
    var timer = setTimeout(function () { if (ctrl) ctrl.abort(); }, (job.timeout || 25) * 1000);
    return fetch(job.url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(job.body),
      signal: ctrl ? ctrl.signal : undefined
    })
      .then(function (r) { return r.json(); })
      .then(function (json) { clearTimeout(timer); return json; })
      .catch(function () {
        clearTimeout(timer);
        // Порожня відповідь — навмисно: сайт позначить чек непевним і лишить
        // можливість перепитати. Вигадувати помилку тут не можна: ми не знаємо,
        // чи каса його не пробила.
        return null;
      });
  }

  function run(job) {
    say('Пробиваємо чек на касі…');
    return toKasa(job)
      .then(function (answer) {
        return post('/admin/fiscal/done', {
          id: job.id,
          response: JSON.stringify(answer === null ? {} : answer)
        });
      })
      .then(function (res) {
        if (res.state === 'done') { say('Чек пробито: ' + res.number, 'ok'); return true; }
        if (res.state === 'error') { say('Каса відмовила: ' + res.error, 'bad'); return false; }
        say('Каса не відповіла. Перевірте, чи запущено Device Manager, і натисніть «Перепитати».', 'bad');
        return false;
      })
      .catch(function () {
        say('Не вдалося звʼязатися із сайтом. Оновіть сторінку.', 'bad');
        return false;
      });
  }

  function tick() {
    post('/admin/fiscal/next', { parent_id: parentId })
      .then(function (res) {
        var jobs = (res && res.jobs) || [];
        if (!jobs.length) {
          // Нічого не лишилось: якщо ми щось пробили — показуємо результат
          // свіжою сторінкою, бо блок чека малює сервер
          if (left > 0) setTimeout(function () { window.location.reload(); }, 900);
          else box.hidden = true;
          return;
        }
        left = jobs.length;
        var chain = Promise.resolve(true);
        jobs.forEach(function (job) {
          chain = chain.then(function (okSoFar) {
            return run(job).then(function (ok) { return okSoFar && ok; });
          });
        });
        chain.then(function (allOk) {
          if (allOk) setTimeout(function () { window.location.reload(); }, 900);
        });
      })
      .catch(function () { box.hidden = true; });
  }

  /**
   * Перевірка каси на цьому пристрої.
   *
   * Найцінніше тут — РОЗРІЗНИТИ дві різні біди, які для fetch виглядають
   * однаково: каса не запущена й каса запущена, але браузер не пускає до неї
   * сторінку сайту (для звертань у локальну мережу він вимагає окремого
   * дозволу). Розрізняємо повторним запитом у режимі no-cors: якщо він
   * проходить, значить, до каси ми достукались, і річ саме в дозволах.
   */
  if (probeBtn) {
    var out = document.querySelector('[data-fiscal-probe-out]');
    var show = function (text, kind) {
      if (!out) return;
      out.textContent = text;
      out.className = 'fiscal-runner is-' + (kind || 'work');
      out.hidden = false;
    };

    probeBtn.addEventListener('click', function () {
      show('Питаємо касу…');
      post('/admin/fiscal/probe', {})
        .then(function (job) {
          if (!job.ok) { show(job.error, 'bad'); return; }
          return fetch(job.url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(job.body)
          })
            .then(function (r) { return r.json(); })
            .then(function (answer) {
              var info = answer && answer.info;
              if (answer && answer.res === 0 && info) {
                show('Каса відповідає: ПРРО ' + (info.fisid || '?')
                  + ', зміна ' + (info.shift_status === 1 ? 'відкрита' : 'закрита')
                  + (info.isFis === 0 ? ' · ТЕСТОВА, чеки без юридичної сили' : ''), 'ok');
              } else {
                show('Каса відповіла помилкою: ' + ((answer && answer.errortxt) || 'без пояснення'), 'bad');
              }
            })
            .catch(function () {
              // Не дійшли. Тепер зʼясовуємо, чи справа в дозволах браузера
              return fetch(job.url, { method: 'POST', mode: 'no-cors', body: '{}' })
                .then(function () {
                  show('Device Manager працює, але браузер не пускає до нього сторінку сайту. '
                    + 'Це обмеження браузера на звертання в локальну мережу, а не помилка каси. '
                    + 'Тоді залишається маршрут «Каса точки» з агентом на ПК.', 'bad');
                })
                .catch(function () {
                  show('Device Manager за адресою ' + job.url + ' не відповідає. Перевірте, '
                    + 'що застосунок запущено на цьому ж пристрої та що назва каси збігається.', 'bad');
                });
            });
        })
        .catch(function () { show('Сайт не відповів. Оновіть сторінку.', 'bad'); });
    });
  }

  if (box) tick();
})();
