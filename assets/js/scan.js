// Сканування штрихкодів камерою — спільне вікно для каси й для екрана кодів.
//
// Два шляхи до одного результату. На Android у браузері є вбудований
// BarcodeDetector — швидкий і апаратний, беремо його. Де його немає (а на
// Windows його немає ніде) — читаємо кадр самі, barcode.js. Той, хто сканує,
// різниці не бачить.
//
// Розмітку вікна будуємо тут, а не в шаблонах: сторінок, яким воно потрібне,
// уже дві, і копія розмітки в кожній розійшлася б із першою ж правкою.
window.BofuScan = (function () {
  'use strict';

  var box = null, video, msgEl, diagEl, stream = null, raf = null, detector = null;
  var busy = false, frame = 0, handler = null, manual = null, lastCode = '', lastAt = 0;
  var startedAt = 0, hinted = false, lastDiag = 0;
  var canvas = document.createElement('canvas');
  var ctx = canvas.getContext('2d', { willReadFrequently: true });

  function build() {
    if (box) return;
    box = document.createElement('div');
    box.className = 'pos-cam';
    box.hidden = true;
    box.innerHTML =
      '<div class="pos-cam-inner">' +
      '<div class="pos-cam-aim" aria-hidden="true"></div>' +
      '<p class="pos-cam-msg"></p>' +
      '<p class="pos-cam-diag" hidden></p>' +
      '<div class="pos-cam-btns">' +
      '<button type="button" class="btn btn-line btn-sm" data-cam-manual>Ввести код руками</button>' +
      '<button type="button" class="btn btn-gold btn-sm" data-cam-close>Готово</button>' +
      '</div></div>';
    // Відео створюємо окремо й вмикаємо властивостями, а не атрибутами в
    // innerHTML: muted/playsinline, виставлені розміткою на щойно створеному
    // елементі, Chrome не завжди підхоплює — і кадр не йде взагалі, а на екрані
    // лишається чорний прямокутник.
    video = document.createElement('video');
    video.muted = true;
    video.autoplay = true;
    video.playsInline = true;
    video.setAttribute('playsinline', '');   // старі iOS читають саме атрибут
    box.querySelector('.pos-cam-inner').insertBefore(video, box.querySelector('.pos-cam-aim'));
    msgEl = box.querySelector('.pos-cam-msg');
    diagEl = box.querySelector('.pos-cam-diag');
    document.body.appendChild(box);
    box.querySelector('[data-cam-close]').addEventListener('click', close);
    // Код на етикетці надрукований цифрами під смужками — коли камера не дає
    // ради (пожмакана коробка, блік, стерта етикетка), його швидше набрати,
    // ніж воювати з фокусом. Кнопка веде саме туди, де його чекають.
    box.querySelector('[data-cam-manual]').addEventListener('click', function () {
      var cb = manual;
      close();
      if (cb) cb();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && box && !box.hidden) close();
    });
  }

  function say(text) { if (msgEl) msgEl.textContent = text; }

  /**
   * «Пік» на впізнаний код.
   *
   * Продавець дивиться на товар, а не в екран, і має чути, що код зчитано, —
   * інакше він водитиме етикеткою далі й просканує її двічі. Вібрація є лише
   * на телефоні, тож звук робимо самі: короткий тон через WebAudio, без файлів
   * і без мережі.
   */
  var audio = null;
  function beep(ok) {
    try {
      var AC = window.AudioContext || window.webkitAudioContext;
      if (!AC) return;
      audio = audio || new AC();
      if (audio.state === 'suspended') audio.resume();
      var osc = audio.createOscillator(), gain = audio.createGain();
      osc.type = 'square';
      osc.frequency.value = ok ? 1760 : 220;    // знайшли — високо, не знайшли — низько
      gain.gain.setValueAtTime(0.09, audio.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.0001, audio.currentTime + 0.14);
      osc.connect(gain).connect(audio.destination);
      osc.start();
      osc.stop(audio.currentTime + 0.15);
    } catch (e) { /* звук — приємність, а не умова роботи */ }
  }

  var api = { say: say, close: close, beep: beep };

  /**
   * Увімкнути камеру. onCode(code, api) викликається на кожен упізнаний код —
   * далі вирішує сама сторінка: каса лишає вікно відкритим і сканує наступний
   * товар, екран кодів закриває його одразу.
   */
  function open(onCode, opts) {
    opts = opts || {};
    var fail = opts.onError || function (m) { window.alert(m); };

    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      fail('Цей браузер не дає доступу до камери. Скористайтесь USB-сканером або введіть код руками.');
      return;
    }
    // Камеру браузер дає лише на https (localhost вважається безпечним). З
    // телефона по локальній http-адресі вона не увімкнеться — і сказати про це
    // треба зараз, а не показати порожнє вікно.
    if (!window.isSecureContext) {
      fail('Камера доступна лише за адресою https:// (або на localhost). Зараз сторінка відкрита без https.');
      return;
    }

    build();
    handler = onCode;
    manual = opts.onManual || null;
    box.querySelector('[data-cam-manual]').hidden = !manual;
    lastCode = '';
    box.hidden = false;
    say('Вмикаємо камеру…');

    navigator.mediaDevices.getUserMedia({
      // Просимо якнайбільше пікселів: код читається за шириною смужки, і на
      // 640×480 одна смужка виходить у 1–2 пікселі — не читається нічим.
      video: { facingMode: 'environment', width: { ideal: 1920 }, height: { ideal: 1080 } },
      audio: false
    }).then(function (s) {
      stream = s;
      video.srcObject = s;
      // play() повертає обіцянку й цілком може відмовити (політика автозапуску,
      // згорнута вкладка). Мовчазна відмова — це і є той самий чорний прямокутник,
      // тож про неї треба сказати, а не сподіватись.
      var p = video.play();
      if (p && p.catch) p.catch(function (err) {
        say('Камера не запустилась: ' + (err && err.message ? err.message : 'браузер не дав відтворити відео'));
      });
      // Постійний автофокус: без нього камера ловить різкість один раз при
      // старті, і піднесена ближче етикетка лишається розмитою — саме тоді
      // «камера не бачить код». Підтримують не всі, тому мовчки best-effort.
      try {
        var track = s.getVideoTracks()[0];
        if (track && track.applyConstraints) {
          track.applyConstraints({ advanced: [{ focusMode: 'continuous' }] }).catch(function () {});
        }
      } catch (e) { /* камера без керування фокусом — не біда */ }

      if ('BarcodeDetector' in window) {
        try {
          detector = new window.BarcodeDetector({
            formats: ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128']
          });
          // Детектор може існувати, але не вміти саме магазинних форматів
          // (набір залежить від системи). Тоді свій декодер кращий за нього.
          if (window.BarcodeDetector.getSupportedFormats) {
            window.BarcodeDetector.getSupportedFormats().then(function (list) {
              if (list && list.indexOf('ean_13') === -1) detector = null;
            }).catch(function () {});
          }
        } catch (e) { detector = null; }
      }
      say(opts.title || 'Наведіть камеру на штрихкод');
      startedAt = Date.now();
      hinted = false;
      raf = requestAnimationFrame(tick);
    }).catch(function (e) {
      box.hidden = true;
      var why = e && e.name === 'NotAllowedError' ? 'ви заборонили доступ до камери в браузері'
        : (e && e.name === 'NotFoundError' ? 'камери не знайдено' : (e && e.message) || 'невідома причина');
      fail('Не вдалося увімкнути камеру: ' + why);
    });
  }

  function close() {
    if (raf) cancelAnimationFrame(raf);
    raf = null;
    if (stream) stream.getTracks().forEach(function (t) { t.stop(); });
    stream = null; detector = null; busy = false; handler = null; manual = null;
    if (box) box.hidden = true;
  }

  /**
   * Чому не читається — числами, а не здогадками.
   *
   * «Не читається» однакове і для темряви, і для задалекого коду, і для збитої
   * цифри, тому саме на цьому кроці всі й застрягають. Рахуємо три речі, які
   * розрізняють причини, і кажемо людині ту дію, що справді допоможе:
   *
   *   різкість — чи видно межі смужок узагалі (розмито = камера не навелась);
   *   ширина   — скільки пікселів припадає на код (замало = піднесіть ближче);
   *   крок     — де зупинилось читання (знайшли роздільник? дочитали цифри?).
   */
  function diagnose(img, st) {
    var now = Date.now();
    if (now - lastDiag < 700) return;    // перемальовувати частіше нема сенсу
    lastDiag = now;

    /**
     * Різкість міряємо по САМИХ межах, а не по всьому рядку.
     *
     * Середній перепад по рядку майже нічого не каже: етикетка здебільшого
     * біла, і рівні ділянки тягнуть середнє вниз — різкий, але невеликий
     * штрихкод виглядав за цим числом «розмитим», і порада виходила
     * протилежною до потрібної. Тому беремо лише сильні перепади: якими вони
     * є, такі й межі смужок.
     */
    var d = img.data, w = img.width, h = img.height;
    var maxD = 0, sum = 0, cnt = 0, min = 255, max = 0, r, i, y, a, b, diff;
    for (r = 0; r < 3; r++) {
      y = ((h * (0.35 + r * 0.15)) | 0) * w * 4;
      for (i = 0; i < w - 1; i++) {
        a = d[y + i * 4 + 1]; b = d[y + (i + 1) * 4 + 1];
        diff = a > b ? a - b : b - a;
        if (diff > maxD) maxD = diff;
        if (a < min) min = a;
        if (a > max) max = a;
      }
    }
    for (r = 0; r < 3; r++) {
      y = ((h * (0.35 + r * 0.15)) | 0) * w * 4;
      for (i = 0; i < w - 1; i++) {
        a = d[y + i * 4 + 1]; b = d[y + (i + 1) * 4 + 1];
        diff = a > b ? a - b : b - a;
        if (diff >= maxD * 0.4) { sum += diff; cnt++; }
      }
    }
    var sharp = cnt ? sum / cnt : 0;
    var contrast = max - min;

    var where = st.read ? 'код прочитано, контрольна цифра не зійшлась'
      : st.left ? 'пів коду читається'
      : st.guard ? 'смужки видно, цифри не складаються'
      : st.runs ? 'смужок не видно як коду'
      : 'кадр порожній або дуже темний';

    if (diagEl) {
      diagEl.hidden = false;
      diagEl.textContent = video.videoWidth + '×' + video.videoHeight
        + ' · межі ' + sharp.toFixed(0)
        + ' · контраст ' + contrast
        + (st.unit ? ' · ' + st.unit + 'px на смужку' : '')
        + ' · ' + where;
    }

    // Порада має бути одна й та, що справді змінить справу.
    // Ширина смужки — головне число: під 4 пікселі код майже не читається,
    // і єдиний спосіб її збільшити — піднести етикетку ближче.
    if (contrast < 40) say('Замало світла або камера дивиться повз етикетку.');
    else if (st.unit && st.unit < 4) say('Ближче: на смужку припадає лише ' + st.unit + ' пікселя. Хай штрихкод заповнить рамку по ширині.');
    else if (sharp < 25) say('Розмито — камера не навелась. Відсуньте на 25–30 см і дайте їй мить навестись.');
    else if (!st.guard) say('Наведіть на сам штрихкод — так, щоб він заповнив рамку по ширині.');
    else if (st.read) say('Код читається, але контрольна цифра не сходиться — етикетка пошкоджена. Введіть код руками.');
    else say('Майже: тримайте рівніше й без відблиску.');
  }

  /** Код упізнано: та сама етикетка в кадрі щомиті, тож повтори притримуємо */
  function hit(code) {
    var now = Date.now();
    if (code === lastCode && now - lastAt < 1800) return;
    lastCode = code; lastAt = now;
    hinted = true;                                  // код читається — підказка вже не про це
    startedAt = now;
    beep(true);
    if (navigator.vibrate) navigator.vibrate(60);   // на телефоні — ще й відчутно
    if (handler) handler(code, api);
  }

  function tick() {
    raf = requestAnimationFrame(tick);

    // Кадру немає — камера не віддає зображення. Мовчати тут не можна: на
    // екрані просто чорний прямокутник, і незрозуміло, чи це поламано, чи
    // просто темно.
    if (!video.videoWidth) {
      if (Date.now() - startedAt > 4000 && !hinted) {
        hinted = true;
        say('Камера не дає зображення. Перевірте, чи не зайнята вона іншою програмою, і чи дозволений доступ у браузері.');
      }
      return;
    }


    // Читаємо рівно те, що обведено рамкою (трохи ширше — під поля обабіч
    // коду). Рамка вужча за кадр навмисно: вона показує, наскільки великим має
    // бути штрихкод. Найчастіша причина «не читається» — код задалеко, і на
    // смужку лишається два пікселі.
    var sw = Math.floor(video.videoWidth * 0.80);
    var sh = Math.floor(video.videoHeight * 0.40);
    var sx = Math.floor((video.videoWidth - sw) / 2);
    var sy = Math.floor((video.videoHeight - sh) / 2);
    // Стискаємо якомога менше: кожен втрачений піксель — це втрачена ширина
    // смужки, а саме з неї й читається код
    var scale = Math.min(1, 1600 / sw);
    canvas.width = Math.floor(sw * scale);
    canvas.height = Math.floor(sh * scale);
    ctx.drawImage(video, sx, sy, sw, sh, 0, 0, canvas.width, canvas.height);

    if (detector) {
      if (busy) return;
      busy = true;
      detector.detect(canvas).then(function (res) {
        busy = false;
        if (res && res.length && res[0].rawValue) hit(res[0].rawValue);
      }).catch(function () { busy = false; });
      return;
    }
    // Свій декодер важчий за апаратний, тож не на кожному кадрі. Кожен другий —
    // це ~30 спроб на секунду: людина не встигає піднести етикетку швидше.
    if (++frame % 2 !== 0 || !window.BofuBarcode) return;
    var img = ctx.getImageData(0, 0, canvas.width, canvas.height);
    var st = {};
    var code = window.BofuBarcode.decode(img, st);
    if (code) { hit(code); return; }
    diagnose(img, st);
  }

  return { open: open, close: close, say: say, beep: beep };
})();
