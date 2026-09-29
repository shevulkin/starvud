// «Старвуд-М» — вітрина
(function () {
  var modal = document.getElementById('authModal');
  var loginBtn = document.getElementById('loginBtn');
  if (loginBtn && modal) {
    loginBtn.addEventListener('click', function (e) { e.preventDefault(); modal.classList.add('open'); });
    var close = document.getElementById('authClose');
    if (close) close.addEventListener('click', function () { modal.classList.remove('open'); });
    modal.addEventListener('click', function (e) { if (e.target === modal) modal.classList.remove('open'); });
  }
  var mb = document.getElementById('mobileBtn'), mm = document.getElementById('mobileMenu');
  if (mb && mm) mb.addEventListener('click', function () { mm.classList.toggle('open'); });
  // Іконка пошуку на телефоні: відкриває меню й одразу ставить курсор у поле
  var msb = document.getElementById('mobileSearchBtn');
  if (msb && mm) msb.addEventListener('click', function () {
    mm.classList.add('open');
    var q = document.getElementById('navSearchMobile');
    if (q) { q.focus(); mm.scrollTop = 0; }
  });

  /* Категорії в шапці: стрілка біля «Магазину» відкриває список розділів.
     Кліком, а не наведенням: на телефоні наведення немає, і меню, яке
     розкривається по-різному на різних пристроях, доводиться тримати
     узгодженим двома способами замість одного.

     Сам пункт «Магазин» лишається посиланням у каталог — стрілка окремо. */
  var drops = document.querySelectorAll('[data-nav-drop-btn]');
  Array.prototype.forEach.call(drops, function (btn) {
    var menu = document.getElementById(btn.getAttribute('aria-controls'));
    if (!menu) return;
    function close() {
      menu.setAttribute('hidden', '');
      btn.setAttribute('aria-expanded', 'false');
      if (btn.parentNode) btn.parentNode.classList.remove('is-open');
    }
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      var open = menu.hasAttribute('hidden');
      // спершу закриваємо всі: два відкритих списки поруч перекривають один одного
      Array.prototype.forEach.call(document.querySelectorAll('[data-nav-drop-menu]'), function (m) {
        m.setAttribute('hidden', '');
      });
      Array.prototype.forEach.call(drops, function (b) {
        b.setAttribute('aria-expanded', 'false');
        if (b.parentNode) b.parentNode.classList.remove('is-open');
      });
      if (open) {
        menu.removeAttribute('hidden');
        btn.setAttribute('aria-expanded', 'true');
        if (btn.parentNode) btn.parentNode.classList.add('is-open');
      }
    });
    // Клік повз меню й Esc закривають його: розкритий список, який лишається
    // висіти над сторінкою, доводиться закривати тим самим кліком по стрілці —
    // а людина вже дивиться в інший бік
    document.addEventListener('click', function (e) {
      if (menu.hasAttribute('hidden')) return;
      if (menu.contains(e.target) || btn.contains(e.target)) return;
      close();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !menu.hasAttribute('hidden')) { close(); btn.focus(); }
    });
  });

  var toTop = document.getElementById('toTop');
  if (toTop) {
    window.addEventListener('scroll', function () {
      toTop.classList.toggle('show', window.scrollY > 500);
    }, { passive: true });
    toTop.addEventListener('click', function () { window.scrollTo({ top: 0, behavior: 'smooth' }); });
  }

  // анімація лічильника випускників
  var counter = document.querySelector('[data-count-to]');
  if (counter) {
    var target = parseInt(counter.getAttribute('data-count-to'), 10) || 0;
    var start = null, dur = 1400;
    function tick(t) {
      if (!start) start = t;
      var p = Math.min(1, (t - start) / dur);
      counter.textContent = Math.round(target * (1 - Math.pow(1 - p, 3))).toLocaleString('uk-UA');
      if (p < 1) requestAnimationFrame(tick);
    }
    var seen = false;
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (en.isIntersecting && !seen) { seen = true; requestAnimationFrame(tick); io.disconnect(); }
      });
    });
    io.observe(counter);
  }

  // вибір варіанта товару: ціна, наявність і артикул оновлюються одразу
  var picker = document.getElementById('variantPicker');
  if (picker && window.BOFU_VARIANTS && BOFU_VARIANTS.length) {
    var variants = BOFU_VARIANTS;
    var hidden = document.getElementById('variantId');
    var priceNow = document.getElementById('priceNow');
    var priceOld = document.getElementById('priceOld');
    var note = document.getElementById('variantNote');
    var axisRows = picker.querySelectorAll('[data-axis]');
    var selection = {};

    function findVariant() {
      for (var i = 0; i < variants.length; i++) {
        var ok = true;
        for (var axis in selection) {
          if (String(variants[i].opts[axis]) !== String(selection[axis])) { ok = false; break; }
        }
        if (ok) return variants[i];
      }
      return null;
    }

    // чи існує варіант, якщо в обраному наборі замінити одну вісь
    function combinationExists(axis, value) {
      for (var i = 0; i < variants.length; i++) {
        var v = variants[i], ok = String(v.opts[axis]) === String(value);
        for (var other in selection) {
          if (other === axis) continue;
          if (String(v.opts[other]) !== String(selection[other])) { ok = false; break; }
        }
        if (ok) return true;
      }
      return false;
    }

    function byId(id) {
      for (var i = 0; i < variants.length; i++) if (variants[i].id === +id) return variants[i];
      return null;
    }

    function apply(v) {
      if (!v) {
        if (note) note.textContent = 'Такої комбінації немає — оберіть іншу.';
        return;
      }
      if (hidden) hidden.value = v.id;
      if (priceNow) priceNow.textContent = v.price_fmt;
      if (priceOld) {
        priceOld.textContent = v.old_fmt || '';
        priceOld.hidden = !v.old_fmt;
      }
      // Ціна за 100 г належить фасовці: банка 0,5 і банка 1,5 коштують різне
      // й важать різне. Без ваги рядок ховається — ділити нема на що.
      var priceUnit = document.getElementById('priceUnit');
      if (priceUnit) {
        priceUnit.textContent = v.per_100g || '';
        priceUnit.hidden = !v.per_100g;
      }
      // Вага в характеристиках — теж від фасовки
      var specWeight = document.getElementById('specWeight');
      var specWeightRow = document.getElementById('specWeightRow');
      if (specWeight) specWeight.textContent = v.weight_fmt || '';
      if (specWeightRow) specWeightRow.style.display = v.weight_fmt ? '' : 'none';
      if (note) {
        var bits = [];
        if (v.sku) bits.push('Артикул: ' + v.sku);
        var t = window.BOFU_LOW_STOCK_THRESHOLD;
        // текст «під замовлення» приходить із сервера: для чужого бренду він
        // інший, і дублювати тут правило означало б розʼїхатися з ним
        bits.push(v.qty > 0 ? (t !== null && v.qty <= t ? 'Закінчується' : 'У наявності')
          : (window.BOFU_MADE_TO_ORDER
              ? 'Немає на складі — ' + (window.BOFU_MTO_NOTE || 'під замовлення')
              : 'Немає в наявності'));
        note.textContent = bits.join(' · ');
      }
      updatePhotos(v);
      updateAvailability(v);
      updateAddButton(v);
      // блок торгу теж належить фасовці: у кожної своя розмова й своя ціна
      renderOffer(v.id);
      var op = document.getElementById('offerPrice');
      if (op && v.price) op.placeholder = String(Math.round(v.price * 0.9));
      // гасимо значення, які не поєднуються з поточним вибором
      picker.querySelectorAll('.opt').forEach(function (btn) {
        var axis = btn.dataset.axis;
        btn.classList.toggle('active', String(selection[axis]) === btn.dataset.value);
        btn.classList.toggle('off', !combinationExists(axis, btn.dataset.value));
      });
      picker.querySelectorAll('.opt-plain').forEach(function (btn) {
        btn.classList.toggle('active', +btn.dataset.variant === v.id);
      });
    }

    /**
     * Кнопка «До кошика» для варіанта, якого немає. Сервер відмовить у будь-якому
     * разі — тут ми лише не даємо людині дізнатися про це вже на оформленні.
     * «Виготовимо під замовлення» не обмежуємо: такий товар роблять під клієнта.
     */
    function updateAddButton(v) {
      var btn = document.getElementById('addToCart');
      if (!btn) return;
      var blocked = v.qty <= 0 && !window.BOFU_MADE_TO_ORDER;
      btn.disabled = blocked;
      btn.textContent = blocked ? 'Немає в наявності' : 'До кошика';
      var qtyInput = document.querySelector('.qty-box input[name=qty]');
      if (qtyInput) {
        if (window.BOFU_MADE_TO_ORDER || v.qty <= 0) qtyInput.removeAttribute('max');
        else {
          qtyInput.max = v.qty;
          if (+qtyInput.value > v.qty) qtyInput.value = v.qty;
        }
      }
    }

    /**
     * Галерея обраної фасовки.
     *
     * Список приходить готовим із сервера (свої кадри, далі спільні): порядок
     * і заглушку вирішує Catalog::gallery, і повторювати те саме правило тут
     * означало б завести другу його версію, яка колись розійдеться з першою.
     *
     * Фасовка без своїх кадрів отримує той самий список, що й товар, — тому
     * перебирати галерею можна щоразу, не питаючи, чи є в неї фото.
     */
    function updatePhotos(v) {
      var main = document.getElementById('mainPhoto');
      var photos = v.photos || [];
      if (!main || !photos.length) return;
      main.src = photos[0].full;

      var box = document.getElementById('productThumbs');
      if (box) {
        box.textContent = '';
        // Одне фото — мініатюри не потрібні: вона повторювала б головне фото,
        // а перегляд рахував би один кадр за два.
        (photos.length > 1 ? photos : []).forEach(function (ph, i) {
          var b = document.createElement('button');
          b.type = 'button';
          b.className = 'thumb' + (i ? '' : ' active');
          b.dataset.full = ph.full;
          b.dataset.lbSkip = '';   // своя дія: підмінити головне фото
          b.setAttribute('aria-label', 'Фото ' + (i + 1) + ' з ' + photos.length);
          var im = document.createElement('img');
          im.src = ph.thumb;
          im.alt = main.alt + ' — фото ' + (i + 1);
          im.loading = 'lazy';
          b.appendChild(im);
          box.appendChild(b);
        });
        box.hidden = photos.length < 2;
      }
      // Коли мініатюр немає, список для перегляду складає саме головне фото —
      // те саме правило, що й при першому показі сторінки.
      if (photos.length > 1) main.removeAttribute('data-full');
      else main.dataset.full = photos[0].full;
    }

    function updateAvailability(v) {
      var box = document.getElementById('availability');
      if (!box) return;
      var any = false;
      box.querySelectorAll('[data-store]').forEach(function (el) {
        var map = {};
        try { map = JSON.parse(el.dataset.stock || '{}'); } catch (e) {}
        var qty = +(map[v.id] || 0);
        any = any || qty > 0;
        var txt = el.querySelector('.av-text');
        if (txt) {
          var t = window.BOFU_LOW_STOCK_THRESHOLD;
          var stockTxt = qty > 0 ? (t !== null && qty <= t ? 'закінчується' : 'в наявності') : 'немає';
          // ціна цього магазину для обраного варіанта
          var sp = (v.store_price || {})[el.dataset.store] || '';
          txt.textContent = el.dataset.label + ': ' + stockTxt + (sp ? ' · ціна тут: ' + sp : '');
        }
        el.classList.toggle('yes', qty > 0);
        el.classList.toggle('no', qty <= 0);
      });
      // «Виготовимо під замовлення» стоїть над переліком точок, а не в ньому:
      // це відповідь на питання «то він буде?», і читатись вона має першою,
      // а не після двох рядків «немає».
      var mto = document.getElementById('madeToOrderLead');
      if (mto) mto.style.display = any ? 'none' : '';
      /*
       * Розкладка по точках згорнута, доки її не попросили, — але обрали
       * фасовку, якої немає ніде, і список розкривається сам: тоді це вже не
       * довідка «де забрати», а пояснення, чому кнопка не працює.
       *
       * Назад не згортаємо: людина могла розкрити список сама, і закрити його
       * під нею через перемикання фасовки — забрати те, що вона щойно
       * відкрила.
       */
      var availBox = document.getElementById('availBox');
      if (availBox && !any) availBox.open = true;
      // «Повідомити, коли зʼявиться» має сенс лише для варіанта, якого немає;
      // тримаємо в прихованому полі саме обраний, інакше в чергу очікувань
      // потрапив би той, що стояв на сторінці при завантаженні
      var watch = document.getElementById('watchBox');
      if (watch) watch.style.display = any ? 'none' : '';
      var wv = document.getElementById('watchVariant');
      if (wv) wv.value = v.id;
    }

    if (axisRows.length) {
      var first = byId(picker.dataset.first) || variants[0];
      for (var axis in first.opts) selection[axis] = first.opts[axis];
      picker.querySelectorAll('.opt').forEach(function (btn) {
        btn.addEventListener('click', function () {
          selection[btn.dataset.axis] = btn.dataset.value;
          var v = findVariant();
          // якщо такої комбінації немає — підбираємо найближчу з обраним значенням
          if (!v) {
            for (var i = 0; i < variants.length; i++) {
              if (String(variants[i].opts[btn.dataset.axis]) === btn.dataset.value) {
                selection = {};
                for (var a in variants[i].opts) selection[a] = variants[i].opts[a];
                v = variants[i];
                break;
              }
            }
          }
          apply(v);
        });
      });
      apply(first);
    } else {
      picker.querySelectorAll('.opt-plain').forEach(function (btn) {
        btn.addEventListener('click', function () { apply(byId(btn.dataset.variant)); });
      });
      apply(byId(picker.dataset.first) || variants[0]);
    }
  }

  /* Торг: блок «запропонуйте свою ціну».
   *
   * Розмова ведеться про конкретну фасовку, тому блок міняється разом із
   * ціною: у банки 0,5 може бути погоджена ціна, а в ящика — жодної розмови,
   * і показувати тут спільний стан означало б показувати чужу домовленість.
   *
   * Тексти станів живуть тут, а не приходять із сервера готовими: сервер уже
   * прислав факти (чий хід, які умови, доки діє), а речення з них складається
   * одне й те саме для всіх фасовок.
   */
  function renderOffer(variantId) {
    var box = document.getElementById('offerBox');
    if (!box || !window.BOFU_OFFERS) return;
    var st = window.BOFU_OFFERS[variantId] || window.BOFU_OFFERS[0] || { state: 'none' };
    var stateBox = document.getElementById('offerState');
    var form = document.getElementById('offerForm');
    var text = document.getElementById('offerStateText');
    var acts = document.getElementById('offerStateActions');
    var hidden = document.getElementById('offerVariant');
    if (hidden) hidden.value = variantId || '';
    if (!stateBox || !form || !text || !acts) return;

    /*
     * Блок торгу згорнутий, доки його не попросили, — але розмова, яка вже
     * йде, ховатись не має: там чекає відповідь продавця, і непомічена
     * відповідь дорівнює відмові.
     *
     * Фасовку міняють уже на сторінці, і в кожної своя розмова: обрали ту, де
     * пропозиція лежить у продавця, — блок розкривається сам.
     */
    var sum = box.querySelector('.offer-toggle');
    if (st.state === 'none') {
      stateBox.hidden = true;
      form.hidden = false;
      if (sum) sum.textContent = 'Запропонувати свою ціну';
      // Навмисно НЕ закриваємо: людина могла розкрити блок сама, і згорнути
      // його під нею через перемикання фасовки — забрати те, що вона щойно
      // відкрила
      return;
    }
    stateBox.hidden = false;
    form.hidden = true;
    acts.textContent = '';
    box.open = true;
    if (sum) sum.textContent = 'Ваша пропозиція ціни';

    if (st.state === 'wait') {
      text.textContent = 'Ваша пропозиція в продавця: ' + st.terms + '. Відповімо якнайшвидше.';
      acts.appendChild(offerBtn('Скасувати пропозицію', 'offerCancelForm', 'offerCancelId', st.id, 'btn-line'));
    } else if (st.state === 'yours') {
      text.textContent = 'Магазин пропонує свої умови: ' + st.terms + '. Хід за вами.';
      acts.appendChild(offerBtn('Погодитись', 'offerAcceptForm', 'offerAcceptId', st.id, 'btn-gold'));
      acts.appendChild(offerBtn('Відмовитись', 'offerCancelForm', 'offerCancelId', st.id, 'btn-line'));
      // Своя зустрічна — це просто ще одна пропозиція; форма та сама
      var again = document.createElement('button');
      again.type = 'button';
      again.className = 'btn btn-line btn-sm';
      again.textContent = 'Запропонувати інше';
      again.addEventListener('click', function () { stateBox.hidden = true; form.hidden = false; });
      acts.appendChild(again);
    } else if (st.state === 'deal') {
      text.textContent = 'Домовились: ' + st.terms
        + (st.until ? '. Ціна діє до ' + st.until : '') + '.';
      acts.appendChild(offerBtn('До кошика за домовленою ціною', 'offerCartForm', 'offerCartId', st.id, 'btn-gold'));
    }
  }

  /* Кнопка, що надсилає одну з готових форм. Форми лежать у розмітці разом із
     CSRF-токеном — збирати їх тут означало б розкидати токен по скриптах. */
  function offerBtn(label, formId, fieldId, offerId, cls) {
    var b = document.createElement('button');
    b.type = 'button';
    b.className = 'btn ' + cls + ' btn-sm';
    b.textContent = label;
    b.addEventListener('click', function () {
      var f = document.getElementById(formId), i = document.getElementById(fieldId);
      if (!f || !i) return;
      i.value = offerId;
      f.submit();
    });
    return b;
  }

  /* «Рахувати за всю партію»: людина частіше знає свій бюджет, ніж ціну за
     штуку. Поле одне — міняється лише те, що воно означає. */
  var offerMode = document.getElementById('offerMode');
  if (offerMode) {
    offerMode.addEventListener('change', function () {
      var lbl = document.getElementById('offerPriceLabel');
      if (lbl) lbl.textContent = offerMode.checked ? 'Ваша ціна за всю партію, грн' : 'Ваша ціна за штуку, грн';
    });
  }
  // Товар без фасовок: перемикати нічого, але стан показати треба
  if (document.getElementById('offerBox') && !window.BOFU_VARIANTS) {
    renderOffer(window.BOFU_OFFER_FIRST || 0);
  }

  // додавання в кошик без перезавантаження сторінки
  var cartToast = document.getElementById('cartToast');
  var cartToastTimer = null;
  function showCartToast(name, note) {
    if (!cartToast) return;
    var textEl = cartToast.querySelector('.cart-toast-text');
    // note приходить, коли поклали не всю замовлену кількість — тоді про це
    // й кажемо: «додано в кошик» приховало б, що штук менше, ніж просили
    if (textEl) textEl.textContent = note || ((name ? '«' + name + '»' : 'Товар') + ' — у кошику');
    cartToast.classList.add('show');
    clearTimeout(cartToastTimer);
    cartToastTimer = setTimeout(function () { cartToast.classList.remove('show'); }, 6000);
  }
  if (cartToast) {
    ['cartToastClose', 'cartToastContinue'].forEach(function (id) {
      var b = document.getElementById(id);
      if (b) b.addEventListener('click', function () {
        cartToast.classList.remove('show');
        clearTimeout(cartToastTimer);
      });
    });
  }
  function updateCartBadge(count) {
    var link = document.querySelector('.cart-link');
    if (!link) return;
    var badge = link.querySelector('.cart-badge');
    if (count > 0) {
      if (!badge) {
        badge = document.createElement('span');
        badge.className = 'cart-badge';
        link.appendChild(badge);
      }
      badge.textContent = count;
    } else if (badge) {
      badge.remove();
    }
  }
  /* ── Телефон ────────────────────────────────────────────────────────────────
     Дзеркало AuthTokens::normPhone / normPhoneAny. Сервер лишається джерелом
     істини — тут ми лише кажемо людині заздалегідь те, що він скаже потім.
     Якщо правило на сервері зміниться, міняти треба й тут: розʼїзд цих двох
     місць виглядає для людини як «кнопка не працює».                          */
  function phoneDigits(v) { return String(v || '').replace(/\D/g, ''); }

  function normPhoneUA(v) {
    var d = phoneDigits(v);
    if (d.length === 12 && d.indexOf('380') === 0) return '+' + d;
    if (d.length === 10 && d.charAt(0) === '0') return '+38' + d;
    // нуль попереду означає обрізаний десятизначний, а не номер без коду —
    // див. пояснення в AuthTokens::normPhone()
    if (d.length === 9 && d.charAt(0) !== '0') return '+380' + d;
    return null;
  }
  function normPhoneAny(v) {
    var ua = normPhoneUA(v);
    if (ua) return ua;
    var d = phoneDigits(v);
    if (/^\s*\+/.test(String(v || '')) && d.length >= 10 && d.length <= 15) return '+' + d;
    return null;
  }
  /** +380671234567 → +380 67 123 45 67. Чужі коди лишаємо як є: розбивка в них інша */
  function prettyPhone(norm) {
    var m = /^\+380(\d{2})(\d{3})(\d{2})(\d{2})$/.exec(norm || '');
    return m ? '+380 ' + m[1] + ' ' + m[2] + ' ' + m[3] + ' ' + m[4] : norm;
  }

  /**
   * Чому саме не підходить. «Неправильний номер» не каже, що робити далі, —
   * а людина здебільшого помилилась в одній цифрі або не поставила плюс.
   */
  function phoneProblem(raw) {
    var d = phoneDigits(raw);
    var plus = /^\s*\+/.test(raw);
    var foreign = plus && d.indexOf('380') !== 0;

    if (d.length === 0) return 'Введіть номер цифрами';
    if (foreign) {
      if (d.length < 10) return 'Замало цифр для міжнародного номера: ' + d.length + ' — треба щонайменше 10';
      return 'Забагато цифр: ' + d.length + ' — у міжнародному щонайбільше 15';
    }
    // Багато цифр без плюса — майже завжди закордонний номер, набраний як удома
    if (!plus && d.length > 12) return 'Схоже на іноземний номер — поставте + і код країни: +' + d;
    if (d.length === 10 && d.charAt(0) !== '0') return 'Український номер починається з нуля: 0' + d.slice(0, 2) + ' …';
    if (d.length < 10) return 'Замало цифр: ' + d.length + ' з 10. Приклад: 067 123 45 67';
    return 'Забагато цифр: ' + d.length + '. В українському номері їх 10: 067 123 45 67';
  }

  Array.prototype.forEach.call(document.querySelectorAll('input[type=tel]'), function (inp) {
    // Правило одне на всі форми — checkout, профіль, картка користувача, вхід
    // за номером. Різні правила в різних місцях означали б, що номер, з яким
    // людина замовляла, раптом не годиться для входу.
    var norm = normPhoneAny;
    if (!inp.getAttribute('inputmode')) inp.setAttribute('inputmode', 'tel');
    if (!inp.getAttribute('autocomplete')) inp.setAttribute('autocomplete', 'tel');

    var hint = document.createElement('div');
    hint.className = 'field-hint';
    inp.insertAdjacentElement('afterend', hint);

    function say(state, text) {
      hint.className = 'field-hint' + (state ? ' is-' + state : '');
      hint.textContent = text;
      inp.classList.toggle('is-bad', state === 'bad');
    }
    /** @param strict показувати помилку (після blur і на сабміті), а не лише поки набирають */
    function check(strict) {
      var raw = inp.value.trim();
      if (raw === '') {
        say('', 'Напр. 067 123 45 67. Іноземний — з кодом країни, через +');
        return !inp.required;
      }
      var ok = norm(raw);
      if (ok) { say('ok', 'Приймемо як ' + ok); return true; }
      say(strict ? 'bad' : '', strict ? phoneProblem(raw) : 'Дописуйте — поки що номер неповний');
      return false;
    }

    check(false);
    inp.addEventListener('input', function () { check(false); });
    inp.addEventListener('blur', function () {
      var ok = norm(inp.value.trim());
      if (ok) inp.value = prettyPhone(ok);   // показуємо формат, а не просто приймаємо
      check(true);
    });

    // Сабміт зупиняємо самі: людина має побачити, ЩО не так, ще до перезавантаження
    // сторінки — інакше вона повертається на форму з втраченими полями.
    if (inp.form) {
      inp.form.addEventListener('submit', function (e) {
        if (check(true)) return;
        e.preventDefault();
        inp.focus();
        inp.scrollIntoView({ block: 'center', behavior: 'smooth' });
      });
    }
  });

  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form.matches || !form.matches('.add-cart-form')) return;
    e.preventDefault();
    var btn = form.querySelector('button[type=submit]');
    var data = new FormData(form);
    data.set('ajax', '1');
    if (btn) btn.disabled = true;
    fetch(form.action, { method: 'POST', body: data, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res && res.ok) {
          updateCartBadge(res.count);
          showCartToast(form.dataset.productName, res.note);
          // Кошик змінився без перезавантаження сторінки. Смужка продажу (каса)
          // висить на цій самій сторінці й має оновити свій підсумок — інакше
          // продавець дивиться на суму, якої вже немає. Подія, а не прямий
          // виклик: вітрина нічого не знає про касу й знати не мусить.
          document.dispatchEvent(new CustomEvent('bofu:cart', { detail: { count: res.count } }));
        } else if (res && res.error) {
          showCartToast(null, res.error);
        } else if (res && res.redirect) {
          // сервер каже, що бракує вибору — ведемо туди, де його роблять
          location.href = res.redirect;
        } else {
          form.submit();
        }
      })
      .catch(function () { form.submit(); })
      .finally(function () { if (btn) btn.disabled = false; });
  });
})();

/**
 * Лайтбокс: фото на весь екран.
 *
 * Мед у сотах, віск, шкіряні рукавиці купують очима — а роздивитись їх на
 * сайті було неможливо: мініатюри лише підміняли головне фото тим самим
 * розміром. Тепер будь-яка галерея відкривається на весь екран.
 *
 * Розмітка не переписується під кожну сторінку. Модуль шукає контейнери з
 * `data-lightbox` і збирає всередині все, у чого є `data-full` — повний шлях
 * до великого файлу. Так одна й та сама поведінка дістається і картці товару,
 * і галереї, і будь-чому, що зʼявиться потім.
 *
 * Вікно одне на сторінку й створюється при першому відкритті: більшість
 * відвідувачів фото не збільшує, і платити за це розміткою в кожному
 * документі не варто.
 */
(function () {
  var boxes = document.querySelectorAll('[data-lightbox]');
  if (!boxes.length) return;

  var lb, imgEl, capEl, numEl, prevBtn, nextBtn;
  var items = [], at = 0, opener = null;

  function build() {
    if (lb) return;
    lb = document.createElement('div');
    lb.className = 'lb';
    lb.setAttribute('role', 'dialog');
    lb.setAttribute('aria-modal', 'true');
    lb.setAttribute('aria-label', 'Перегляд фото');
    lb.innerHTML =
      '<button class="lb-x" type="button" aria-label="Закрити">&times;</button>' +
      '<button class="lb-nav lb-prev" type="button" aria-label="Попереднє фото">&#8249;</button>' +
      '<figure class="lb-fig"><img alt=""><figcaption></figcaption></figure>' +
      '<button class="lb-nav lb-next" type="button" aria-label="Наступне фото">&#8250;</button>' +
      '<div class="lb-num" aria-hidden="true"></div>';
    document.body.appendChild(lb);
    imgEl = lb.querySelector('img');
    capEl = lb.querySelector('figcaption');
    numEl = lb.querySelector('.lb-num');
    prevBtn = lb.querySelector('.lb-prev');
    nextBtn = lb.querySelector('.lb-next');

    lb.querySelector('.lb-x').addEventListener('click', close);
    prevBtn.addEventListener('click', function () { go(-1); });
    nextBtn.addEventListener('click', function () { go(1); });
    // клік повз фото — теж закриття: так поводяться всі перегляди фото,
    // і саме цього людина спробує першим, не шукаючи хрестика
    lb.addEventListener('click', function (e) { if (e.target === lb || e.target.classList.contains('lb-fig')) close(); });

    // Свайп на телефоні. Поріг у 40 px відсіює тремтіння пальця, а
    // вертикальний рух не гортає — ним прокручують.
    var x0 = null, y0 = null;
    lb.addEventListener('touchstart', function (e) {
      x0 = e.touches[0].clientX; y0 = e.touches[0].clientY;
    }, { passive: true });
    lb.addEventListener('touchend', function (e) {
      if (x0 === null) return;
      var dx = e.changedTouches[0].clientX - x0, dy = e.changedTouches[0].clientY - y0;
      if (Math.abs(dx) > 40 && Math.abs(dx) > Math.abs(dy)) go(dx < 0 ? 1 : -1);
      x0 = y0 = null;
    });
  }

  function show() {
    var it = items[at];
    imgEl.src = it.full;
    imgEl.alt = it.alt || '';
    capEl.textContent = it.alt || '';
    capEl.hidden = !it.alt;
    numEl.textContent = items.length > 1 ? (at + 1) + ' / ' + items.length : '';
    // одне фото — гортати нема чого, і стрілки лише заважають
    prevBtn.hidden = nextBtn.hidden = items.length < 2;
  }

  function go(step) {
    if (items.length < 2) return;
    at = (at + step + items.length) % items.length;   // по колу
    show();
  }

  function open(list, i, from) {
    build();
    items = list; at = i; opener = from || null;
    show();
    lb.classList.add('is-open');
    // сторінка під вікном не має їхати від прокрутки
    document.body.style.overflow = 'hidden';
    document.addEventListener('keydown', onKey);
    lb.querySelector('.lb-x').focus();
  }

  function close() {
    if (!lb) return;
    lb.classList.remove('is-open');
    document.body.style.overflow = '';
    document.removeEventListener('keydown', onKey);
    // фокус повертається туди, звідки прийшли: інакше клавіатура опиняється
    // на початку сторінки й людина шукає своє місце заново
    if (opener && opener.focus) opener.focus();
    opener = null;
  }

  function onKey(e) {
    if (e.key === 'Escape') { close(); return; }
    if (e.key === 'ArrowLeft') { go(-1); return; }
    if (e.key === 'ArrowRight') go(1);
  }

  boxes.forEach(function (box) {
    /*
     * Список збирається на кліку, а не при завантаженні. На сторінці товару
     * галерея перебирається при виборі фасовки, і список, знятий один раз,
     * показував би кадри, яких на сторінці вже немає.
     */
    function collect() {
      var nodes = Array.prototype.slice.call(box.querySelectorAll('[data-full]'));
      return {
        nodes: nodes,
        list: nodes.map(function (n) {
          var img = n.tagName === 'IMG' ? n : n.querySelector('img');
          return { full: n.dataset.full, alt: (img && img.alt) || n.getAttribute('aria-label') || '' };
        }),
      };
    }

    // Курсор-лупа лишається розміткою: мініатюри перегляду не відкривають
    // (у них своя дія), тож позначаємо все інше — і велике фото над ними.
    box.querySelectorAll('[data-full]').forEach(function (n) {
      if (n.dataset.lbSkip === undefined) n.classList.add('is-zoomable');
    });
    var starter = box.querySelector('[data-lb-open]');
    if (starter) starter.classList.add('is-zoomable');

    box.addEventListener('click', function (e) {
      var n = e.target.closest('[data-full]');
      // мініатюри картки товару вже мають свою дію — підмінити головне фото.
      // Вони складають список, але самі перегляду не відкривають.
      if (n && n.dataset.lbSkip === undefined) {
        e.preventDefault();
        var own = collect();
        open(own.list, Math.max(0, own.nodes.indexOf(n)), n);
        return;
      }
      // Окремий відкривач — велике фото над мініатюрами. Починає з того кадру,
      // який людина зараз бачить, а не з першого: вона вже обрала, на що дивиться.
      var from = e.target.closest('[data-lb-open]');
      if (!from || from.dataset.full !== undefined) return;
      e.preventDefault();
      var all = collect();
      if (!all.nodes.length) return;
      open(all.list, Math.max(0, all.nodes.indexOf(box.querySelector('[data-full].active'))), from);
    });
  });
})();
