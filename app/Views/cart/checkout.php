<?php
  // Обрана за замовчуванням збережена адреса підставляється просто в поля —
  // так вона працює й без JS, і покупцеві нічого не треба натискати
  $selDelivery = $sel['delivery'] ?? 'np';
  $selCity = $sel['city'] ?? '';
  $selRef = $sel['city_ref'] ?? '';
  $selOffice = $sel['np_office'] ?? '';
  $selOfficeRef = $sel['np_office_ref'] ?? '';
  $selType = ($sel['np_type'] ?? 'warehouse') === 'courier' ? 'courier' : 'warehouse';
  $selStreet = $sel['np_street'] ?? '';
  $selStreetRef = $sel['np_street_ref'] ?? '';
  $selHouse = $sel['np_house'] ?? '';
  $selFlat = $sel['np_flat'] ?? '';
  $selAddress = $sel['address'] ?? '';
  $canSave = !empty($auth_user);
  // «2 товари» читається як конкретна річ, «Ваше замовлення» — як абстракція.
  // Форму слова рахує спільний plural_n() — той самий, що підписує лічильники
  // на головній; двох правил узгодження на сайт бути не повинно.
  $units = array_sum(array_map(fn($r) => (int)$r['qty'], $rows));
  $goods = plural_n($units, 'товар', 'товари', 'товарів');
  $saved = fn(array $t) => 'ви заощадили ' . price_fmt($t['promo_discount'] ?? $t['discount']);
?>
<section class="section" style="padding-top:44px">
  <div class="container co-wrap">
    <div class="kicker">Оформлення</div>
    <h2>Замовлення</h2>
    <form method="post" action="<?= e(url('/checkout/submit')) ?>" class="co-grid" id="checkoutForm">
      <?= Csrf::field() ?>
      <div aria-hidden="true" style="position:absolute;left:-9999px;top:-9999px;height:0;overflow:hidden">
        <label>Не заповнюйте це поле<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
      </div>

      <div>
      <?php /* Кроки нумеруються лічильником, а не вручну: крок «Оплата»
               з'являється лише там, де еквайринг налаштований, і сталі числа
               давали б на половині магазинів послідовність 1, 2, 4. */ ?>
      <?php $step = 0; ?>
      <div class="co-step">
        <h3 class="co-step-h"><span class="co-num"><?= ++$step ?></span><?= !empty($digital) ? 'Доступ' : 'Доставка' ?></h3>

        <?php /* Збережені адреси цифровому замовленню теж ні до чого */ ?>
        <?php if ($addresses && empty($digital)): ?>
          <div class="field">
            <label>Мої адреси</label>
            <div class="variants" id="addrChips">
              <?php foreach ($addresses as $a): ?>
                <label class="chip<?= $sel && (int)$sel['id'] === (int)$a['id'] ? ' active' : '' ?>">
                  <input type="radio" name="address_id" value="<?= (int)$a['id'] ?>" hidden
                         <?= $sel && (int)$sel['id'] === (int)$a['id'] ? 'checked' : '' ?>
                         data-delivery="<?= e($a['delivery']) ?>" data-city="<?= e($a['city']) ?>"
                         data-ref="<?= e($a['city_ref']) ?>" data-office="<?= e($a['np_office']) ?>"
                         data-office-ref="<?= e($a['np_office_ref'] ?? '') ?>"
                         data-type="<?= e($a['np_type'] ?? 'warehouse') ?>"
                         data-street="<?= e($a['np_street'] ?? '') ?>" data-street-ref="<?= e($a['np_street_ref'] ?? '') ?>"
                         data-house="<?= e($a['np_house'] ?? '') ?>" data-flat="<?= e($a['np_flat'] ?? '') ?>"
                         data-address="<?= e($a['address']) ?>">
                  <?= e(Addresses::title($a)) ?>
                </label>
              <?php endforeach; ?>
              <label class="chip"><input type="radio" name="address_id" value="" hidden>+ Інша адреса</label>
            </div>
            <p class="dim" style="margin:8px 0 0;font-size:13px">Адреси зберігаються без отримувача — його вказуєте щоразу.
              Керувати списком: <a href="<?= e(url('/profile')) ?>">у профілі</a>.</p>
          </div>
        <?php endif; ?>

        <?php
        /*
         * Курс нікуди не їде. Питати в того, хто купує відео, місто, відділення
         * й номер будинку — питати нізащо, і кожне таке питання ще й виглядає
         * як помилка сайту («навіщо їм моя адреса?»).
         *
         * Ховаємо блок цілком, а не робимо «ще один варіант доставки»: вибору
         * тут немає, і показувати перемикач з єдиним пунктом означало б удавати
         * вибір. Спосіб проставить сервер за вмістом кошика (Checkout::place).
         */
        ?>
        <?php if (!empty($digital)): ?>
          <div class="field">
            <label>Доступ до курсу</label>
            <p class="dim" style="margin:6px 0 0">Везти нічого не треба: після оплати курс
              відкриється у вашому кабінеті, а на пошту прийде лист із посиланням.</p>
          </div>
        <?php else: ?>
        <div class="field">
          <label>Спосіб доставки</label>
          <div class="variants" id="deliveryChips">
            <label class="chip<?= $selDelivery === 'np' ? ' active' : '' ?>"><input type="radio" name="delivery" value="np"<?= $selDelivery === 'np' ? ' checked' : '' ?> hidden>Нова Пошта</label>
            <label class="chip"><input type="radio" name="delivery" value="pickup" hidden>Самовивіз з магазину</label>
            <label class="chip<?= $selDelivery === 'other' ? ' active' : '' ?>"><input type="radio" name="delivery" value="other"<?= $selDelivery === 'other' ? ' checked' : '' ?> hidden>Інше (узгодимо)</label>
          </div>
        </div>

        <div id="npFields"<?= $selDelivery === 'np' ? '' : ' style="display:none"' ?>>
          <?php /* Куди саме везти. Це не «спосіб доставки» (він вище), а вибір
                   усередині Нової Пошти: у відділення чи курʼєром додому. Різниця
                   для нас істотна — курʼєрська накладна вимагає вулиці з довідника,
                   а не просто адреси рядком. */ ?>
          <div class="field">
            <div class="variants" id="npTypeChips">
              <label class="chip<?= $selType === 'warehouse' ? ' active' : '' ?>"><input type="radio" name="np_type" value="warehouse"<?= $selType === 'warehouse' ? ' checked' : '' ?> hidden>У відділення або поштомат</label>
              <label class="chip<?= $selType === 'courier' ? ' active' : '' ?>"><input type="radio" name="np_type" value="courier"<?= $selType === 'courier' ? ' checked' : '' ?> hidden>Курʼєром на адресу</label>
            </div>
          </div>
          <div class="form-grid">
            <?php /* autocomplete="new-password" — єдине, що глушить автопідстановку адрес
                      у Chrome: "off" він для адресних полів свідомо ігнорує і накриває наш
                      список своїм. Імʼя поля теж не "city" — інакше евристика впізнає його
                      за назвою. data-* — те саме для менеджерів паролів. */ ?>
            <div class="field"><label>Місто</label><input type="text" name="np_city" id="npCity" value="<?= e($selCity) ?>" placeholder="Почніть вводити місто…" autocomplete="new-password" data-lpignore="true" data-1p-ignore data-form-type="other" spellcheck="false"></div>
            <div class="field" id="npOfficeField"><label>Відділення / поштомат</label><input type="text" name="np_office" id="npOffice" value="<?= e($selOffice) ?>" placeholder="Номер, вулиця або «поштомат»" autocomplete="new-password" data-lpignore="true" data-1p-ignore data-form-type="other" spellcheck="false"></div>
          </div>
          <div class="form-grid" id="npCourierFields"<?= $selType === 'courier' ? '' : ' style="display:none"' ?>>
            <div class="field" style="grid-column:1/-1"><label>Вулиця</label><input type="text" name="np_street" id="npStreet" value="<?= e($selStreet) ?>" placeholder="Почніть вводити назву вулиці…" autocomplete="new-password" data-lpignore="true" data-1p-ignore data-form-type="other" spellcheck="false"></div>
            <div class="field"><label>Будинок</label><input type="text" name="np_house" id="npHouse" value="<?= e($selHouse) ?>" placeholder="12А" maxlength="20"></div>
            <div class="field"><label>Квартира <span class="dim">(якщо є)</span></label><input type="text" name="np_flat" id="npFlat" value="<?= e($selFlat) ?>" placeholder="45" maxlength="20"></div>
          </div>
          <?php /* Ref-и з довідника НП: людина бачить назви, а накладну потім
                   створюють саме за цими посиланнями */ ?>
          <input type="hidden" name="city_ref" id="npCityRef" value="<?= e($selRef) ?>">
          <input type="hidden" name="np_office_ref" id="npOfficeRef" value="<?= e($selOfficeRef) ?>">
          <input type="hidden" name="np_street_ref" id="npStreetRef" value="<?= e($selStreetRef) ?>">
        </div>

        <div id="pickupFields" style="display:none">
          <div class="field"><label>Магазин</label>
            <select name="store_id" id="pickupStore">
              <?php /* Магазин один — він і обраний: зайвий клік на шляху до замовлення */ ?>
              <?php if (count($stores) !== 1): ?><option value="">— оберіть магазин —</option><?php endif; ?>
              <?php foreach ($stores as $s): $sid = (int)$s['id']; $miss = $missing[$sid] ?? []; ?>
                <option value="<?= $sid ?>" data-missing="<?= e(implode("\n", $miss)) ?>"<?= count($stores) === 1 ? ' selected' : '' ?>>
                  <?= e($s['name'] . ($s['city'] ? ', ' . $s['city'] : '') . ($s['address'] ? ', ' . $s['address'] : '')) ?>
                  <?= $miss ? ' — немає частини позицій' : ' — все є в наявності' ?>
                </option>
              <?php endforeach; ?>
            </select>
            <p class="dim" id="pickupNote" style="margin:8px 0 0;white-space:pre-line"></p>
          </div>
          <?php /* Замість карти — посилання на маршрут до обраної точки.
                   Карта відповідала на питання «яка ближча», але коштувала
                   чужого скрипта на сторінці оформлення, тобто саме там, де
                   людина вводить телефон і адресу. Посилання відповідає на те
                   саме питання, а на телефоні навіть краще: відкриється рідна
                   карта з навігацією. Адресу точки видно тут же, у списку. */ ?>
          <?php if ($map_points): ?>
            <p class="dim" style="margin:-6px 0 14px">
              <a id="pickupRoute" href="#" target="_blank" rel="noopener" style="display:none">Прокласти маршрут до цієї точки →</a>
            </p>
          <?php endif; ?>
        </div>

        <div id="otherFields"<?= $selDelivery === 'other' ? '' : ' style="display:none"' ?>>
          <div class="field"><label>Адреса / побажання</label><input type="text" name="address" id="otherAddress" value="<?= e($selAddress) ?>" placeholder="Опишіть, як вам зручно отримати"></div>
        </div>

        <?php if ($canSave): ?>
          <label class="checkbox" id="saveAddrRow" style="margin-bottom:18px<?= $selDelivery === 'pickup' ? ';display:none' : '' ?>">
            <input type="checkbox" name="save_address" value="1" checked>
            <span>Запамʼятати цю адресу — наступного разу не доведеться вводити</span>
          </label>
        <?php endif; ?>
        <?php endif; /* /не цифровий кошик */ ?>
      </div><!-- /крок 1 -->

      <div class="co-step">
        <h3 class="co-step-h"><span class="co-num"><?= ++$step ?></span>Отримувач</h3>
        <p class="co-note">Нова Пошта видає посилку лише тому, чиї імʼя й телефон вказані в накладній.
          Якщо забираєте самі — поставте галку, і дані підставляться з профілю.</p>

        <?php if ($pre['name'] !== '' || $pre['phone'] !== ''): ?>
          <label class="checkbox" id="meRow" style="margin-bottom:16px">
            <input type="checkbox" id="meRecipient">
            <span>Я отримувач</span>
          </label>
        <?php endif; ?>

        <div class="form-grid">
          <div class="field"><label>Отримувач *</label><input type="text" name="name" id="ordName" value="" required placeholder="Імʼя та прізвище"></div>
          <div class="field"><label>Телефон отримувача *</label><input type="tel" name="phone" id="ordPhone" value="" required placeholder="+380 __ ___ ____"></div>
        </div>
        <div class="field"><label>Email <span class="dim">(необовʼязково)</span></label><input type="email" name="email" id="orderEmail" value="<?= e($pre['email']) ?>" placeholder="надішлемо підтвердження замовлення"></div>

        <label class="checkbox" id="newsletterRow" style="align-items:flex-start;margin-bottom:18px;<?= $pre['email'] === '' ? 'display:none' : '' ?>">
          <input type="checkbox" name="newsletter" value="1" style="margin-top:3px"<?= $subscribed ? ' checked' : '' ?>>
          <span>Хочу отримувати новини та акції на цей email. Відписатись можна будь-коли — посиланням у листі або в профілі.</span>
        </label>
      </div><!-- /крок 2 -->

      <?php /* Оплата.
               Крок з'являється лише тоді, коли еквайринг справді працює: вибір
               із одного варіанта — це не вибір, а зайвий екран між покупцем і
               кнопкою. Типовим лишається «при отриманні»: так магазин працював
               досі, і мовчазна зміна звички на «спершу заплатіть» відлякує
               більше людей, ніж приваблює зручність картки.

               Обидва варіанти описані наслідками, а не назвами способів:
               питання покупця тут не «яка платіжна система», а «коли з мене
               спишуть гроші й що буде далі». */ ?>
      <?php if ($card_enabled): ?>
      <div class="co-step">
        <h3 class="co-step-h"><span class="co-num"><?= ++$step ?></span>Оплата</h3>
        <div class="field">
          <div class="variants" id="payChips">
            <label class="chip active"><input type="radio" name="payment" value="later" checked hidden>При отриманні</label>
            <label class="chip"><input type="radio" name="payment" value="card" hidden>Карткою онлайн</label>
          </div>
        </div>
        <p class="co-note" id="payNoteLater">Оплатите під час отримання — у відділенні перевізника
          або в магазині при самовивозі. Зараз нічого не списується.</p>
        <p class="co-note" id="payNoteCard" style="display:none">Після підтвердження замовлення ви перейдете
          на захищену сторінку банку: Visa, Mastercard, Apple&nbsp;Pay або Google&nbsp;Pay.
          Дані картки вводяться там і на наш сайт не потрапляють.
          <?php if ($card_test): ?><br><b>Увага:</b> зараз увімкнено тестовий шлюз — справжні гроші не рухаються.<?php endif; ?></p>
      </div><!-- /оплата -->
      <?php endif; ?>

      <div class="co-step">
        <h3 class="co-step-h"><span class="co-num"><?= ++$step ?></span>Побажання <span class="dim" style="font-size:14px;font-family:var(--sans)">— необовʼязково</span></h3>
        <div class="field"><label>Коментар до замовлення</label>
          <textarea name="comment" rows="3" placeholder="Необовʼязково: зручний час дзвінка, побажання до пакування"></textarea></div>
      </div>
      </div><!-- /ліва колонка -->

      <aside class="co-sum">
       <details id="coFold" open>
        <summary class="co-sum-h">Ваше замовлення <span class="dim" style="font-size:14px">· <?= e($goods) ?></span>
          <b class="co-fold-total" id="foldTotal"><?= e(price_fmt($totals['total'])) ?></b></summary>
        <div class="co-items">
          <?php foreach ($rows as $r): $cut = (float)($r['cut'] ?? 0); ?>
            <div class="co-item" data-key="<?= e($r['key']) ?>">
              <img src="<?= e(asset($r['photo'])) ?>" alt="" loading="lazy">
              <div style="min-width:0">
                <div class="co-item-name"><?= e($r['product']['name']) ?></div>
                <div class="dim">
                  <?= $r['variant'] ? e($r['variant']['name']) . ' · ' : '' ?><?= (int)$r['qty'] ?> × <?= e(price_fmt($r['price'])) ?>
                  <?php /* Ціна вже оптова — без підпису вона виглядає як помилка,
                           бо не збігається з тією, що покупець бачив у каталозі */ ?>
                  <?php if (($r['wholesale'] ?? 0) > 0): ?>
                    · <span style="color:var(--gold)">опт −<?= e(QtyDiscounts::pct((float)$r['wholesale'])) ?>%</span>
                  <?php endif; ?>
                  <?php /* Те саме й тут: ціна, про яку домовились, не збігається
                           ні з каталогом, ні з жодною знижкою, і без підпису
                           читалась би як помилка в останню мить перед оплатою */ ?>
                  <?php if (!empty($r['offer_id'])): ?>
                    · <span style="color:var(--gold)">домовлена ціна</span>
                  <?php endif; ?>
                </div>
              </div>
              <div class="co-item-sum">
                <?php if ($cut > 0): ?><s class="co-old"><?= e(price_fmt($r['sum'])) ?></s><?php endif; ?>
                <span class="co-now"><?= e(price_fmt($r['final'] ?? (($r['sum'] ?? 0) - $cut))) ?></span>
              </div>
            </div>
          <?php endforeach; ?>
        </div>

        <p style="margin:0 0 14px"<?= $promo ? ' hidden' : '' ?> id="promoToggleRow">
          <button type="button" class="co-promo-link" id="promoToggle">Маю промокод</button>
        </p>
        <div class="field" id="promoBox" style="margin-bottom:16px<?= $promo ? '' : ';display:none' ?>">
          <label>Промокод</label>
          <div class="co-promo">
            <input type="text" name="promo_code" id="promoInput" value="<?= e($promo['code'] ?? '') ?>" autocomplete="off" spellcheck="false">
            <button class="btn btn-line" type="button" id="promoBtn"><?= $promo ? 'Прибрати' : 'Застосувати' ?></button>
          </div>
          <?php $promoNote = Promo::note($promo, $rows); ?>
          <p class="field-hint<?= $promo ? ' is-ok' : '' ?>" id="promoHint">
            <?= $promo ? e('✓ Код ' . $promo['code'] . ' діє — ' . $saved($totals)
                           . ($promoNote !== '' ? '. ' . $promoNote : '')) : '' ?>
          </p>
        </div>

        <div class="totals">
          <div class="row"><span class="muted">Товари:</span><span id="sumSubtotal"><?= e(price_fmt($totals['subtotal'])) ?></span></div>
          <?php /* Набір — окремим рядком і поіменно. Знижка за кількість
                   видно з ціни позиції, а знижка за поєднання — ні: вона
                   виникає з того, ЩО лежить поруч, і без підпису читається
                   як помилка в рахунку. */ ?>
          <?php foreach ($totals['bundles'] ?? [] as $hit): ?>
            <div class="row"><span class="muted"><?= e(Bundles::label($hit)) ?>:</span>
              <span>−<?= e(price_fmt($hit['cut'])) ?></span></div>
          <?php endforeach; ?>
          <div class="row" id="sumDiscountRow"<?= ($totals['promo_discount'] ?? 0) > 0 ? '' : ' style="display:none"' ?>>
            <span class="muted" id="sumDiscountLabel"><?= $promo ? e(Promo::label($promo)) : 'Знижка' ?>:</span>
            <span id="sumDiscount">−<?= e(($totals['promo_discount'] ?? 0) > 0 ? price_fmt($totals['promo_discount']) : '0 грн') ?></span>
          </div>
          <?php /* Доставка окремим рядком, хай навіть без суми.
                   «До сплати: 600 грн» без згадки про доставку читається як
                   повна сума, а на відділенні покупець платить перевізнику ще
                   раз — і це та несподіванка, після якої не повертаються.
                   Ціну не вигадуємо: тариф рахує перевізник за вагою й
                   напрямком, і назвати її наперед ми чесно не можемо. */ ?>
          <div class="row" id="sumShipRow"><span class="muted">Доставка:</span>
            <span class="dim" id="sumShip">за тарифами перевізника</span></div>
          <div class="row grand"><span>До сплати за товар:</span><span id="sumTotal"><?= e(price_fmt($totals['total'])) ?></span></div>
        </div>

        <ul class="co-trust">
          <li>Оплата при отриманні або за домовленістю</li>
          <li>Продавець зателефонує, щоб підтвердити замовлення</li>
          <li id="trustPay">Нічого не спишеться зараз — це не оплата</li>
        </ul>
        <button class="btn btn-gold co-submit" type="submit" style="width:100%"
                data-submit-label="Підтвердити замовлення" data-pay-label="Перейти до оплати">Підтвердити замовлення</button>
        <?php /* Згода з умовами — під кнопкою, звичайним текстом, а не галкою.
                 Оформлення замовлення і є прийняттям оферти (ст. 11 Закону
                 «Про електронну комерцію»), тож окрема галка нічого не додає
                 юридично, зате додає ще один клік. Але сказати про це й дати
                 посилання ми зобовʼязані. */ ?>
        <p class="dim co-terms">Підтверджуючи замовлення, ви погоджуєтесь із
          <a href="<?= e(url('/offer')) ?>" target="_blank" rel="noopener">умовами оферти</a> та
          <a href="<?= e(url('/privacy')) ?>" target="_blank" rel="noopener">політикою конфіденційності</a>.</p>
        <p class="dim" style="margin:14px 0 0;font-size:12.5px">
          <a href="<?= e(url('/cart')) ?>">← Повернутись до кошика</a></p>
       </details>
      </aside>

      <!-- телефон: сума й кнопка завжди під рукою, без гортання через усю форму -->
      <div class="co-bar">
        <div class="co-bar-total"><span><?= e($goods) ?> · до сплати</span><b id="barTotal"><?= e(price_fmt($totals['total'])) ?></b></div>
        <button class="btn btn-gold" type="submit"
                data-submit-label="Підтвердити" data-pay-label="Оплатити">Підтвердити</button>
      </div>
    </form>
  </div>
</section>
<?php if ($np_enabled) echo View::partial('partials/np_autocomplete'); ?>
<script>
(function(){
  var np = document.getElementById('npFields'), pk = document.getElementById('pickupFields'), ot = document.getElementById('otherFields');
  var saveRow = document.getElementById('saveAddrRow');
  var nameInput = document.getElementById('ordName'), phoneInput = document.getElementById('ordPhone');
  var me = <?= json_js(['name' => $pre['name'], 'phone' => $pre['phone']]) ?>;
  var npWidget = window.npAutocomplete
    ? window.npAutocomplete({city: 'npCity', office: 'npOffice', ref: 'npCityRef',
                             officeRef: 'npOfficeRef', street: 'npStreet', streetRef: 'npStreetRef'}) : null;

  // Відділення чи курʼєр: показуємо рівно ті поля, які потрібні обраному
  // способу. Приховане поле лишається заповненим — повернувшись до відділення,
  // людина побачить те, що вже обрала, а не порожнечу.
  var npTypeChips = document.querySelectorAll('#npTypeChips .chip');
  var courierFields = document.getElementById('npCourierFields');
  var officeField = document.getElementById('npOfficeField');
  function showNpType(v){
    if (courierFields) courierFields.style.display = v === 'courier' ? '' : 'none';
    if (officeField) officeField.style.display = v === 'courier' ? 'none' : '';
  }
  npTypeChips.forEach(function(ch){
    ch.addEventListener('click', function(){
      npTypeChips.forEach(function(c){ c.classList.remove('active'); c.querySelector('input').checked = false; });
      ch.classList.add('active');
      var i = ch.querySelector('input');
      i.checked = true;
      showNpType(i.value);
    });
  });
  function npType(){
    var on = document.querySelector('#npTypeChips input:checked');
    return on ? on.value : 'warehouse';
  }
  showNpType(npType());

  function showFor(v){
    // У цифровому замовленні блоку доставки на сторінці немає взагалі —
    // ховати нічого, і звертання до відсутніх вузлів зупинило б увесь скрипт
    // разом із рештою чекауту
    if (!np || !pk || !ot) return;
    np.style.display = v === 'np' ? '' : 'none';
    pk.style.display = v === 'pickup' ? '' : 'none';
    ot.style.display = v === 'other' ? '' : 'none';
    // самовивіз зберігати нічого: адреса магазину і так у довіднику
    if (saveRow) saveRow.style.display = v === 'pickup' ? 'none' : '';
  }
  // «Я отримувач»: одні й ті самі поля, просто заповнені з профілю
  var meBox = document.getElementById('meRecipient');
  function applyMe(){
    if (meBox.checked) {
      nameInput.value = me.name; phoneInput.value = me.phone;
      nameInput.readOnly = phoneInput.readOnly = true;
    } else {
      nameInput.value = phoneInput.value = '';
      nameInput.readOnly = phoneInput.readOnly = false;
      nameInput.focus();
    }
  }
  if (meBox) meBox.addEventListener('change', applyMe);

  /* Спосіб оплати. Перемикач міняє не лише позначку, а й підпис кнопки:
     «Підтвердити замовлення» і «Перейти до оплати» — різні обіцянки, і людина
     має знати, що станеться після натискання, ще до натискання. */
  var payChips = document.querySelectorAll('#payChips .chip');
  payChips.forEach(function(ch){
    ch.addEventListener('click', function(){
      payChips.forEach(function(c){ c.classList.remove('active'); c.querySelector('input').checked = false; });
      ch.classList.add('active');
      var input = ch.querySelector('input');
      input.checked = true;
      var card = input.value === 'card';
      var later = document.getElementById('payNoteLater'), now = document.getElementById('payNoteCard');
      if (later) later.style.display = card ? 'none' : '';
      if (now) now.style.display = card ? '' : 'none';
      document.querySelectorAll('[data-submit-label]').forEach(function(b){
        b.textContent = card ? b.dataset.payLabel : b.dataset.submitLabel;
      });
      var trust = document.getElementById('trustPay');
      if (trust) trust.textContent = card
        ? 'Оплата карткою на захищеній сторінці банку'
        : 'Нічого не спишеться зараз — це не оплата';
    });
  });

  var chips = document.querySelectorAll('#deliveryChips .chip');
  chips.forEach(function(ch){
    ch.addEventListener('click', function(){
      chips.forEach(function(c){c.classList.remove('active')});
      ch.classList.add('active');
      var v = ch.querySelector('input').value;
      showFor(v);
      // забираєте самі — отримувач очевидний, тож підставляємо себе одразу
      if (v !== 'np' && meBox && !meBox.checked && !nameInput.value && !phoneInput.value) {
        meBox.checked = true; applyMe();
      }
    });
  });

  // вибір збереженої адреси
  document.querySelectorAll('#addrChips .chip').forEach(function(ch){
    ch.addEventListener('click', function(){
      document.querySelectorAll('#addrChips .chip').forEach(function(c){c.classList.remove('active')});
      ch.classList.add('active');
      var i = ch.querySelector('input'), d = i.dataset;
      if (!i.value) {                           // «Інша адреса» — звільняємо поля під нову
        if (npWidget) npWidget.apply('', '', '', '', '', '');
        else { document.getElementById('npCity').value = ''; document.getElementById('npOffice').value = ''; }
        document.getElementById('npCityRef').value = '';
        document.getElementById('npOfficeRef').value = '';
        document.getElementById('npHouse').value = document.getElementById('npFlat').value = '';
        document.getElementById('otherAddress').value = '';
        return;
      }
      var delivery = d.delivery === 'other' ? 'other' : 'np';
      chips.forEach(function(c){
        var on = c.querySelector('input').value === delivery;
        c.classList.toggle('active', on);
        c.querySelector('input').checked = on;
      });
      showFor(delivery);
      var type = d.type === 'courier' ? 'courier' : 'warehouse';
      npTypeChips.forEach(function(c){
        var on = c.querySelector('input').value === type;
        c.classList.toggle('active', on);
        c.querySelector('input').checked = on;
      });
      showNpType(type);
      if (npWidget) npWidget.apply(d.city, d.ref, d.office, d.officeRef, d.street, d.streetRef);
      else { document.getElementById('npCity').value = d.city || ''; document.getElementById('npOffice').value = d.office || ''; }
      document.getElementById('npCityRef').value = d.ref || '';
      document.getElementById('npOfficeRef').value = d.officeRef || '';
      document.getElementById('npHouse').value = d.house || '';
      document.getElementById('npFlat').value = d.flat || '';
      document.getElementById('otherAddress').value = d.address || '';
    });
  });

  // Промокод: перевіряємо окремим запитом і одразу показуємо, що саме дала
  // знижка. Без цього людина дізнавалась про долю коду вже після підтвердження
  // замовлення — тобто коли міняти щось пізно.
  var promoInput = document.getElementById('promoInput'), promoBtn = document.getElementById('promoBtn');
  var promoHint = document.getElementById('promoHint');
  function applyPromo(code){
    promoBtn.disabled = true;
    promoHint.className = 'field-hint';
    promoHint.textContent = 'Перевіряємо…';
    // телефон потрібен для коду «один раз на людину»: гостя ми впізнаємо лише
    // за номером, тож без нього ліміт перевірився б аж при підтвердженні
    var body = new URLSearchParams({_csrf: '<?= e(Csrf::token()) ?>', promo_code: code,
      phone: (phoneInput && phoneInput.value) || ''});
    fetch('<?= e(url('/checkout/promo')) ?>', {method: 'POST', body: body, credentials: 'same-origin'})
      .then(function(r){ return r.json() }).then(function(d){
        promoBtn.disabled = false;
        promoBtn.textContent = d.ok ? 'Прибрати' : 'Застосувати';
        promoHint.className = 'field-hint' + (d.ok ? ' is-ok' : (d.empty ? '' : ' is-bad'));
        // «заощадили», а не «мінус»: те саме число, але як здобуток, а не як
        // бухгалтерська операція — і воно доречніше саме в мить рішення
        // причину пише сервер: «вже використаний» і «такого немає» — різні речі
        promoHint.textContent = d.ok
          ? '✓ Код ' + d.code + ' діє — ви заощадили ' + d.discount + (d.note ? '. ' + d.note : '')
          : (d.empty ? '' : '✕ ' + (d.error || 'Такий промокод не діє'));
        // ціни позицій: стара лишається закресленою, щоб знижку було видно
        document.querySelectorAll('.co-item').forEach(function(el){
          var it = d.items[el.dataset.key];
          if (!it) return;
          var old = el.querySelector('.co-old'), now = el.querySelector('.co-now');
          now.textContent = it.sum;
          if (it.old) {
            if (!old) { old = document.createElement('s'); old.className = 'co-old'; now.parentNode.insertBefore(old, now); }
            old.textContent = it.old;
          } else if (old) old.remove();
        });
        document.getElementById('sumSubtotal').textContent = d.subtotal;
        document.getElementById('sumTotal').textContent = d.total;
        ['barTotal', 'foldTotal'].forEach(function(id){
          var el = document.getElementById(id);
          if (el) el.textContent = d.total;
        });
        document.getElementById('sumDiscountRow').style.display = d.ok ? '' : 'none';
        document.getElementById('sumDiscountLabel').textContent = (d.label || 'Знижка') + ':';
        document.getElementById('sumDiscount').textContent = '−' + d.discount;
      }).catch(function(){
        promoBtn.disabled = false;
        promoHint.className = 'field-hint is-bad';
        promoHint.textContent = 'Не вдалося перевірити код — спробуйте ще раз';
      });
  }
  // На телефоні картка згорнута: у розгорнутому стані вона відсувала першу
  // клітинку форми майже на екран. Атрибут open лишається в розмітці, тож без
  // JS усе видно як було.
  var fold = document.getElementById('coFold');
  if (fold) {
    var narrow = window.matchMedia('(max-width:1000px)');
    // стежимо за шириною, а не питаємо один раз: поворот телефона й зміна
    // розміру вікна мають повертати картку у відповідний стан
    var syncFold = function(){ narrow.matches ? fold.removeAttribute('open') : fold.setAttribute('open', ''); };
    syncFold();
    window.addEventListener('load', syncFold);
    narrow.addEventListener ? narrow.addEventListener('change', syncFold) : narrow.addListener(syncFold);
  }

  var promoToggle = document.getElementById('promoToggle');
  if (promoToggle) promoToggle.addEventListener('click', function(){
    document.getElementById('promoToggleRow').hidden = true;
    document.getElementById('promoBox').style.display = '';
    promoInput.focus();
  });
  if (promoBtn) {
    promoBtn.addEventListener('click', function(){
      // «Прибрати» — це та сама перевірка з порожнім кодом
      if (promoBtn.textContent === 'Прибрати') promoInput.value = '';
      applyPromo(promoInput.value.trim());
    });
    promoInput.addEventListener('keydown', function(e){
      if (e.key === 'Enter') { e.preventDefault(); applyPromo(promoInput.value.trim()); }
    });
    // правка коду руками скасовує попередній результат: показані суми більше
    // не відповідають тому, що в полі
    promoInput.addEventListener('input', function(){
      promoBtn.textContent = 'Застосувати';
      promoHint.className = 'field-hint';
      promoHint.textContent = 'Натисніть «Застосувати», щоб перевірити код';
    });
  }

  // Пройдений крок позначаємо галкою: людині видно, скільки лишилось, і
  // незакритий крок помітний одразу, а не після відмови форми на кнопці.
  var steps = document.querySelectorAll('.co-step');
  function stepDone(i){
    var chips = document.getElementById('deliveryChips');
    // Цифрове замовлення: блоку доставки на сторінці немає взагалі, і питати
    // з нього нічого. Крок закритий за побудовою — везти нема чого.
    if (!chips) { if (i === 0) return true; }
    var d = chips ? chips.querySelector('input:checked') : null;
    var v = d ? d.value : 'np';
    if (i === 0) {
      if (v === 'np') {
        if (!document.getElementById('npCity').value.trim()) return false;
        return npType() === 'courier'
          ? !!(document.getElementById('npStreet').value.trim() && document.getElementById('npHouse').value.trim())
          : !!document.getElementById('npOffice').value.trim();
      }
      if (v === 'pickup') return !!(document.getElementById('pickupStore') || {}).value;
      return !!document.getElementById('otherAddress').value.trim();
    }
    if (i === 1) {
      return nameInput.value.trim().length >= 2 && phoneInput.value.replace(/\D/g, '').length >= 9;
    }
    return null;                       // третій крок необовʼязковий — його не «закривають»
  }
  function refreshSteps(){
    steps.forEach(function(st, i){
      var num = st.querySelector('.co-num'), done = stepDone(i);
      if (done === null) return;
      num.classList.toggle('is-done', done);
      num.textContent = done ? '✓' : String(i + 1);
    });
  }
  document.getElementById('checkoutForm').addEventListener('input', refreshSteps);
  document.getElementById('checkoutForm').addEventListener('click', function(){ setTimeout(refreshSteps, 0) });
  refreshSteps();

  // галка розсилки має сенс лише коли вказано email
  var em = document.getElementById('orderEmail'), nlRow = document.getElementById('newsletterRow');
  if (em && nlRow) {
    em.addEventListener('input', function(){
      nlRow.style.display = em.value.trim() ? '' : 'none';
      if (!em.value.trim()) nlRow.querySelector('input').checked = false;
    });
  }
  // самовивіз: показуємо, чого бракує в обраному магазині
  var store = document.getElementById('pickupStore'), note = document.getElementById('pickupNote');
  if (store && note) {
    store.addEventListener('change', function () {
      var opt = store.options[store.selectedIndex];
      var miss = opt ? (opt.dataset.missing || '') : '';
      // рядком на позицію: поруч із кожною видно, де її можна забрати натомість
      note.textContent = miss
        ? 'У цьому магазині зараз немає:\n' + miss + '\nЗамовлення приймемо й так — узгодимо строки.'
        : '';
    });
  }

  // Посилання на маршрут веде до тієї точки, яку обрано в списку. Показуємо
  // лише коли в точки справді є координати — мертве посилання гірше за його
  // відсутність.
  var routeLink = document.getElementById('pickupRoute');
  if (routeLink && store) {
    var routes = {};
    <?php foreach ($map_points as $p): ?>
      routes[<?= (int)$p['id'] ?>] = <?= json_js($p['route']) ?>;
    <?php endforeach; ?>
    var syncRoute = function () {
      var url = routes[parseInt(store.value, 10) || 0] || '';
      routeLink.style.display = url ? '' : 'none';
      if (url) routeLink.href = url;
    };
    store.addEventListener('change', syncRoute);
    syncRoute();
  }
})();
</script>
