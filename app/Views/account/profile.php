<section class="section" style="padding-top:48px">
  <div class="container narrow" style="max-width:640px">
    <div class="kicker">Кабінет</div>
    <h2>Мій профіль</h2>
    <?php if (empty($u['phone'])): ?>
      <div class="flash" style="padding:0;margin-top:16px"><div class="flash-error"><?= empty($u['email_verified_at'])
        ? 'Для користування сайтом потрібен номер телефону — вкажіть його нижче.'
        : 'Вкажіть номер телефону — без нього ми не подзвонимо про замовлення. Сайтом можна користуватись і так: '
          . 'ми напишемо на вашу пошту.' ?></div></div>
      <?php
      /*
       * Тим, хто зайшов поштою, Telegram пропонуємо ПЕРЕД полем для номера — і
       * це не просто зручніший шлях, а той, що не створює другого акаунта.
       *
       * Вписаний руками номер не доводить нічого (users.phone_verified_at), і
       * замовлення, які продавець оформить на цей номер, у кабінет не
       * потраплять. Гірше інше: якщо номер уже записаний в іншому запису —
       * найчастіше тому, що покупця завів продавець у точці, — зайняти його
       * взагалі не вийде, і людина впреться в помилку.
       *
       * Підключення бота знімає обидва питання одразу. Чат чіпляється саме до
       * ЦЬОГО акаунта (Telegram::processUpdates, гілка tg_link), тож наступний
       * вхід через бота впізнає його й сяде сюди, а не заведе поруч другий
       * запис із технічною адресою @telegram.local.
       */
      ?>
      <?php if (!empty($tg_ready) && empty($u['tg_chat_id'])): ?>
        <div class="flash" style="padding:0;margin-top:8px"><div class="flash-success">
          Найпростіше — <a href="#messengers" style="color:inherit;text-decoration:underline">підключити
          Telegram</a>: він сам підкаже ваш номер, і сюди ж приходитимуть коди входу.
        </div></div>
      <?php endif; ?>
    <?php endif; ?>

    <?php /* Замовлення — те, по що в кабінет приходять найчастіше, тож кнопка
             стоїть перед формами, а не рядком під ними. Персоналу вона ще
             потрібніша: у шапці в нього «Адмінпанель» замість «Мої замовлення»,
             і власні покупки шукати більше ніде. */ ?>
    <?php /* «Моє навчання» показуємо лише тим, кому є що там показати: кнопка
             в порожній розділ — обіцянка, за якою нічого немає. */ ?>
    <p style="margin-top:18px;display:flex;gap:10px;flex-wrap:wrap">
      <a class="btn btn-line" href="<?= e(url('/orders')) ?>">📦 Мої замовлення</a>
      <?php if (!empty($has_learning)): ?>
        <a class="btn btn-line" href="<?= e(url('/learning')) ?>">🎓 Моє навчання</a>
      <?php endif; ?>
    </p>

    <form class="admin-card" method="post" action="<?= e(url('/profile')) ?>" style="margin-top:22px">
      <?= Csrf::field() ?>
      <div class="field"><label>Імʼя та прізвище</label><input type="text" name="name" value="<?= e($u['name']) ?>" required></div>
      <?php /* Обовʼязковість поля залежить від того, чи є інший спосіб звʼязку.
               Хто увійшов поштою, може не мати змоги зайняти свій номер (його
               вже міг записати продавець у точці) — тоді required перетворював
               би профіль на глухий кут: зберегти не можна нічого, зокрема імʼя. */ ?>
      <div class="field"><label>Телефон<?= empty($u['email_verified_at']) ? ' *' : '' ?></label>
        <input type="tel" name="phone" value="<?= e($u['phone']) ?>" placeholder="067 123 45 67"<?= empty($u['email_verified_at']) ? ' required' : '' ?>>
        <?php /* Стан номера показуємо словами, бо від нього залежить видима річ:
                 чи потрапить у кабінет замовлення, яке продавець оформив по
                 телефону. Мовчазна різниця між «вписаний» і «підтверджений»
                 читалась би як несправність сайту. */ ?>
        <?php if (!empty($u['phone'])): ?>
          <?php if (!empty($u['phone_verified_at'])): ?>
            <p class="dim" style="margin:6px 0 0">✓ Номер підтверджений — замовлення, оформлені на нього по телефону, потрапляють сюди.</p>
          <?php else: ?>
            <p class="dim" style="margin:6px 0 0">Номер не підтверджений. Він годиться для звʼязку, але замовлення,
              оформлені продавцем на цей номер, у кабінет не потраплять.
              <?php if (!empty($tg_ready)): ?>Щоб підтвердити — увійдіть через Telegram: бот попросить поділитися номером.<?php endif; ?></p>
          <?php endif; ?>
        <?php endif; ?>
      </div>
      <div class="field"><label>Email</label><input type="text" value="<?= e($mail_email ?: '—') ?>" disabled>
        <?php if (!$mail_email): ?><p class="dim" style="margin:6px 0 0">Email підтягнеться автоматично, якщо увійти через Google або за поштою.</p><?php endif; ?>
      </div>
      <?php if ($mail_email): ?>
        <label class="checkbox" style="align-items:flex-start;margin-bottom:18px">
          <input type="checkbox" name="newsletter" value="1" style="margin-top:3px"<?= $subscribed ? ' checked' : '' ?>>
          <span>Отримувати новини та акції на <?= e($mail_email) ?>. Зніміть галку, щоб відписатись.</span>
        </label>
      <?php endif; ?>
      <button class="btn btn-gold" type="submit">💾 Зберегти</button>
    </form>

    <div class="admin-card">
      <h2 class="h-serif" style="font-size:20px">Адреси доставки</h2>
      <p class="dim" style="margin-bottom:16px">Збережена адреса підставляється при оформленні — вводити щоразу не треба.
        Отримувача адреса не памʼятає навмисно: посилку видають лише тому, чиї імʼя й телефон у накладній,
        тож їх ви підтверджуєте в кожному замовленні.</p>

      <?php if (!$addresses): ?>
        <p class="dim">Поки що порожньо. Додайте першу — або поставте галку «Запамʼятати цю адресу» при оформленні.</p>
      <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:10px;margin-bottom:18px">
          <?php foreach ($addresses as $a): ?>
            <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;border-bottom:1px solid rgba(255,255,255,.07);padding-bottom:10px">
              <div style="flex:1;min-width:220px">
                <b><?= e(Addresses::title($a)) ?></b>
                <?php if ((int)$a['is_default'] === 1): ?><span class="status-pill st-processing">основна</span><?php endif; ?>
                <div class="dim" style="font-size:13px">
                  <?= $a['delivery'] === 'np' ? e(OrderFlow::deliveryAddress($a + ['delivery' => 'np'])) : e($a['address']) ?>
                </div>
              </div>
              <button class="btn btn-line btn-sm addr-edit" type="button"
                      data-id="<?= (int)$a['id'] ?>" data-label="<?= e($a['label']) ?>"
                      data-delivery="<?= e($a['delivery']) ?>" data-city="<?= e($a['city']) ?>"
                      data-ref="<?= e($a['city_ref']) ?>" data-office="<?= e($a['np_office']) ?>"
                      data-office-ref="<?= e($a['np_office_ref'] ?? '') ?>"
                      data-type="<?= e($a['np_type'] ?? 'warehouse') ?>"
                      data-street="<?= e($a['np_street'] ?? '') ?>" data-street-ref="<?= e($a['np_street_ref'] ?? '') ?>"
                      data-house="<?= e($a['np_house'] ?? '') ?>" data-flat="<?= e($a['np_flat'] ?? '') ?>"
                      data-address="<?= e($a['address']) ?>">Змінити</button>
              <?php if ((int)$a['is_default'] !== 1): ?>
                <form method="post" action="<?= e(url('/profile')) ?>" style="display:inline">
                  <?= Csrf::field() ?><input type="hidden" name="_action" value="address_default">
                  <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                  <button class="btn btn-line btn-sm" type="submit">Зробити основною</button>
                </form>
              <?php endif; ?>
              <form method="post" action="<?= e(url('/profile')) ?>" style="display:inline"
                    onsubmit="return confirm('Видалити цю адресу?')">
                <?= Csrf::field() ?><input type="hidden" name="_action" value="address_delete">
                <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                <button class="btn btn-line btn-sm" type="submit">Видалити</button>
              </form>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <form method="post" action="<?= e(url('/profile')) ?>" id="addrForm">
        <?= Csrf::field() ?><input type="hidden" name="_action" value="address_save">
        <input type="hidden" name="id" id="addrId" value="">
        <input type="hidden" name="delivery" id="addrDelivery" value="np">
        <input type="hidden" name="city_ref" id="npCityRef" value="">
        <input type="hidden" name="np_office_ref" id="npOfficeRef" value="">
        <input type="hidden" name="np_street_ref" id="npStreetRef" value="">
        <input type="hidden" name="np_type" id="addrNpType" value="warehouse">
        <h3 class="h-serif" style="font-size:17px;margin:0 0 12px" id="addrFormTitle">Нова адреса</h3>
        <div class="variants" id="addrKind" style="margin-bottom:14px">
          <label class="chip active" data-kind="np">Нова Пошта</label>
          <label class="chip" data-kind="other">Інша доставка</label>
        </div>
        <div class="form-grid">
          <div class="field"><label>Назва <span class="dim">(необовʼязково)</span></label>
            <input type="text" name="label" id="addrLabel" placeholder="Дім, Робота, Мамі"></div>
          <?php /* autocomplete="new-password" і назва не "city" — інакше Chrome накриває
                    наш список власною підстановкою адрес (див. checkout) */ ?>
          <div class="field addr-np"><label>Місто</label>
            <input type="text" name="np_city" id="npCity" placeholder="Почніть вводити місто…" autocomplete="new-password" data-lpignore="true" data-1p-ignore data-form-type="other" spellcheck="false"></div>
          <div class="field addr-np addr-wh"><label>Відділення / поштомат</label>
            <input type="text" name="np_office" id="npOffice" placeholder="Номер, вулиця або «поштомат»" autocomplete="new-password" data-lpignore="true" data-1p-ignore data-form-type="other" spellcheck="false"></div>
          <div class="field addr-np addr-courier" style="display:none;grid-column:1/-1"><label>Вулиця</label>
            <input type="text" name="np_street" id="npStreet" placeholder="Почніть вводити назву вулиці…" autocomplete="new-password" data-lpignore="true" data-1p-ignore data-form-type="other" spellcheck="false"></div>
          <div class="field addr-np addr-courier" style="display:none"><label>Будинок</label>
            <input type="text" name="np_house" id="npHouse" placeholder="12А" maxlength="20"></div>
          <div class="field addr-np addr-courier" style="display:none"><label>Квартира <span class="dim">(якщо є)</span></label>
            <input type="text" name="np_flat" id="npFlat" placeholder="45" maxlength="20"></div>
        </div>
        <?php /* Куди возити: у відділення чи додому. Перемикач стоїть під полями
                 Нової Пошти, бо стосується лише її — «інша доставка» його не бачить. */ ?>
        <div class="field addr-np" style="margin-top:4px">
          <div class="variants" id="addrNpKind">
            <label class="chip active" data-type="warehouse">У відділення / поштомат</label>
            <label class="chip" data-type="courier">Курʼєром на адресу</label>
          </div>
        </div>
        <div class="field addr-other" style="display:none"><label>Адреса</label>
          <input type="text" name="address" id="addrAddress" placeholder="Місто, вулиця, будинок — як вам зручно отримати"></div>
        <button class="btn btn-gold" type="submit">💾 Зберегти адресу</button>
        <button class="btn btn-line" type="button" id="addrCancel" style="display:none">Скасувати</button>
        <p class="dim" style="margin:12px 0 0">Збережено <?= count($addresses) ?> з <?= (int)$addr_limit ?>.</p>
      </form>
    </div>

    <?php if ($notify_options): ?>
      <form class="admin-card" method="post" action="<?= e(url('/profile')) ?>">
        <?= Csrf::field() ?><input type="hidden" name="_action" value="notify">
        <h2 class="h-serif" style="font-size:20px">Сповіщення</h2>
        <?php
        // Перелік подій один раз для всього блоку, а не під кожною галкою:
        // канали здебільшого несуть те саме, і повторений тричі список
        // читається як помилка верстки, а не як пояснення.
        $notify_all_events = [];
        foreach ($notify_options as $st) foreach ($st['events'] as $ev) $notify_all_events[$ev] = true;
        ?>
        <p class="dim" style="margin-bottom:16px">Оберіть, куди вам надсилати<?php
          if ($notify_all_events): ?>: <?= e(implode(', ', array_keys($notify_all_events))) ?><?php
          endif; ?>. Показано лише те, що ввімкнув адміністратор — решту він вимкнув для всіх.</p>
        <?php foreach ($notify_options as $ch => $st): ?>
          <div class="notify-row">
            <label class="checkbox<?= $st['ready'] ? '' : ' is-off' ?>"
                   <?= $st['ready'] ? '' : 'title="' . e($st['hint']) . '"' ?>>
              <input type="checkbox" name="ch[<?= e($ch) ?>]" value="1"<?= $st['on'] ? ' checked' : '' ?>>
              <span><b><?= e($notify_channels[$ch] ?? $ch) ?></b><?php
                if (!$st['ready']): ?> <span class="dim">— <?= e($st['hint']) ?></span><?php endif; ?></span>
            </label>
          </div>
        <?php endforeach; ?>
        <button class="btn btn-gold" type="submit" style="margin-top:16px">💾 Зберегти сповіщення</button>
      </form>
    <?php endif; ?>

    <?php /*
      Способи входу.
      Показуємо, лише коли налаштовано більше одного: там, де спосіб один,
      вибирати нема з чого, а єдина доступна дія — замкнути себе назовні.
    */ ?>
    <?php if ($login_ready_count > 1): ?>
      <form class="admin-card" method="post" action="<?= e(url('/profile')) ?>">
        <?= Csrf::field() ?><input type="hidden" name="_action" value="login_methods">
        <h2 class="h-serif" style="font-size:20px">Способи входу в акаунт</h2>
        <p class="dim" style="margin-bottom:16px">
          Пароля тут немає: кожен спосіб нижче — окремий вхід, і будь-який із них пускає в акаунт.
          Тому зайвий краще вимкнути: якщо ви заходите лише через Telegram, вхід поштою вам не
          потрібен, а скринька — найдовше живе й найчастіше витікає.
          Вимкнений спосіб перестає працювати одразу, навіть якщо хтось знає ваш номер чи пошту.
        </p>
        <?php foreach ($login_methods as $m => $st): ?>
          <div class="notify-row">
            <label class="checkbox<?= $st['ready'] ? '' : ' is-off' ?>"
                   <?= $st['ready'] ? '' : 'title="' . e($st['hint']) . '"' ?>>
              <?php /* Ненастроєний спосіб не можна ані обрати, ані надіслати:
                       disabled прибирає поле з форми, а сервер однаково
                       перевіряє готовність ще раз (LoginMethods::save). */ ?>
              <input type="checkbox" name="lm[<?= e($m) ?>]" value="1"
                     <?= $st['on'] ? ' checked' : '' ?><?= $st['ready'] ? '' : ' disabled' ?>>
              <span><b><?= e($st['label']) ?></b>
                <?php if ($st['ready']): ?>
                  <span class="dim">— <?= e($st['about']) ?></span>
                <?php else: ?>
                  <span class="dim">— <?= e($st['hint']) ?></span>
                <?php endif; ?>
              </span>
            </label>
          </div>
        <?php endforeach; ?>
        <p class="dim" style="margin:14px 0 0;font-size:12.5px">
          Хоча б один спосіб має лишитись увімкненим — інакше в акаунт не буде як зайти.
        </p>
        <button class="btn btn-gold" type="submit" style="margin-top:16px">💾 Зберегти способи входу</button>
      </form>
    <?php endif; ?>

    <?php if ($can_fiscal): ?>
      <form class="admin-card" method="post" action="<?= e(url('/profile')) ?>">
        <?= Csrf::field() ?><input type="hidden" name="_action" value="kasa">
        <h2 class="h-serif" style="font-size:20px">Моя каса</h2>
        <p class="dim" style="margin-bottom:16px">
          Через яку касу пробиваються чеки ваших продажів. За замовчуванням — касу вашої точки,
          і міняти тут нічого не треба. Власну касу вказують тоді, коли <b>Device Manager стоїть
          на цьому ж пристрої</b> — вашому ПК або телефоні — і ключ підпису лежить у ньому
          (на флешці чи в папці). Тоді чек несе просто ваша вкладка, і ключ нікуди не передається.
        </p>
        <div class="form-grid">
          <div class="field">
            <label>Куди пробивати</label>
            <select name="fiscal_route">
              <option value="">як у моїй точці</option>
              <?php foreach ($fiscal_routes as $key => $label): ?>
                <option value="<?= e($key) ?>"<?= (string)($u['fiscal_route'] ?? '') === $key ? ' selected' : '' ?>>
                  <?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label>Адреса Device Manager</label>
            <input type="text" name="dm_url" value="<?= e($u['dm_url'] ?? '') ?>"
                   placeholder="<?= e($fiscal_default) ?>" spellcheck="false">
            <p class="field-hint">Якщо DM стоїть тут же — лишайте як є.</p>
          </div>
          <div class="field">
            <label>Назва каси в Device Manager</label>
            <input type="text" name="dm_device" value="<?= e($u['dm_device'] ?? '') ?>"
                   placeholder="kasa1" spellcheck="false">
            <p class="field-hint">Та сама, під якою ви завели ПРРО в Device Manager.</p>
          </div>
        </div>
        <button class="btn btn-gold" type="submit" style="margin-top:16px">💾 Зберегти касу</button>
      </form>

      <?php /* Перевірка стоїть окремою формою: вона нічого не зберігає й нічого
               не проводить — лише питає касу про стан. Це найтонше місце цього
               маршруту, бо браузер може не пустити сторінку сайту на локальну
               адресу, і зʼясувати це треба до першого продажу, а не під час. */ ?>
      <?php if ((string)($u['fiscal_route'] ?? '') === 'device'): ?>
        <div class="admin-card">
          <h2 class="h-serif" style="font-size:20px">Перевірка каси</h2>
          <p class="dim" style="margin-bottom:14px">
            Питає Device Manager на цьому ж пристрої про стан — <b>нічого не пробиває</b>.
            Робіть це з того самого пристрою, де стоїть Device Manager, і в тому браузері,
            у якому працюватимете.
          </p>
          <button class="btn btn-line" type="button" data-fiscal-probe>🔌 Перевірити касу на цьому пристрої</button>
          <div class="fiscal-runner" data-fiscal-probe-out hidden></div>
        </div>
        <?php /* Профіль малюється вітринним шаблоном, а не адмінським, тож
                 window.BOFU тут не оголошено — скрипт без нього мовчки нічого
                 не робив би. */ ?>
        <script>
          window.BOFU = window.BOFU || { base: '<?= e(url('/')) ?>', csrf: '<?= e(Csrf::token()) ?>' };
        </script>
        <script src="<?= e(asset_v('js/fiscal.js')) ?>" defer></script>
      <?php endif; ?>
    <?php endif; ?>

    <div class="admin-card" id="messengers" style="scroll-margin-top:16px">
      <h2 class="h-serif" style="font-size:20px">Месенджери для сповіщень і входу</h2>
      <p class="dim" style="margin-bottom:16px">Підключіть месенджер — сюди приходитимуть коди входу за номером телефону,
        а також сповіщення, якщо цей месенджер є у списку вище.</p>
      <div style="display:flex;flex-direction:column;gap:12px">
        <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">
          <b style="min-width:90px">Telegram</b>
          <?php if (!empty($u['tg_chat_id'])): ?>
            <span class="status-pill st-processing">✓ Підключено</span>
          <?php elseif ($tg_ready): ?>
            <button class="btn btn-line btn-sm" id="tgLinkBtn" type="button">Підключити Telegram</button>
            <span class="dim" id="tgLinkHint"></span>
          <?php else: ?>
            <span class="dim">бот ще не налаштований адміністратором</span>
          <?php endif; ?>
          <?php if (!isset($notify_options['telegram'])): ?>
            <span class="dim">— лише коди входу, сповіщення в Telegram вимкнені</span>
          <?php endif; ?>
        </div>
        <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">
          <b style="min-width:90px">Viber</b>
          <?php if (!empty($u['viber_id'])): ?>
            <span class="status-pill st-processing">✓ Підключено</span>
          <?php elseif ($viber_ready && $viber_uri): ?>
            <button class="btn btn-line btn-sm" id="viberLinkBtn" type="button">Підключити Viber</button>
            <span class="dim" id="viberLinkHint"></span>
          <?php else: ?>
            <span class="dim">запрацює після публікації сайту на хостингу</span>
          <?php endif; ?>
          <?php /* месенджер підключений, але сповіщень туди не буде — кажемо це
                    тут, інакше людина підключає його заради них і чекає марно */ ?>
          <?php if (!isset($notify_options['viber'])): ?>
            <span class="dim">— лише коди входу, сповіщення в Viber вимкнені</span>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</section>
<?php if ($np_enabled) echo View::partial('partials/np_autocomplete'); ?>
<script>
(function(){
  // --- адреси доставки ---
  var form = document.getElementById('addrForm');
  var npWidget = window.npAutocomplete
    ? window.npAutocomplete({city: 'npCity', office: 'npOffice', ref: 'npCityRef',
                             officeRef: 'npOfficeRef', street: 'npStreet', streetRef: 'npStreetRef'}) : null;

  // Відділення чи курʼєр — усередині Нової Пошти. Показуємо рівно ті поля,
  // які потрібні: адреса без вулиці з довідника накладною не стане.
  function setNpType(type){
    document.getElementById('addrNpType').value = type;
    document.querySelectorAll('#addrNpKind .chip').forEach(function(c){
      c.classList.toggle('active', c.dataset.type === type);
    });
    document.querySelectorAll('.addr-wh').forEach(function(el){ el.style.display = type === 'courier' ? 'none' : '' });
    document.querySelectorAll('.addr-courier').forEach(function(el){ el.style.display = type === 'courier' ? '' : 'none' });
  }
  document.querySelectorAll('#addrNpKind .chip').forEach(function(c){
    c.addEventListener('click', function(){ setNpType(c.dataset.type) });
  });

  function setKind(kind){
    document.getElementById('addrDelivery').value = kind;
    document.querySelectorAll('#addrKind .chip').forEach(function(c){
      c.classList.toggle('active', c.dataset.kind === kind);
    });
    document.querySelectorAll('.addr-np').forEach(function(el){ el.style.display = kind === 'np' ? '' : 'none' });
    document.querySelectorAll('.addr-other').forEach(function(el){ el.style.display = kind === 'np' ? 'none' : '' });
  }
  document.querySelectorAll('#addrKind .chip').forEach(function(c){
    c.addEventListener('click', function(){ setKind(c.dataset.kind) });
  });

  function reset(){
    form.reset();
    document.getElementById('addrId').value = '';
    document.getElementById('npCityRef').value = '';
    document.getElementById('npOfficeRef').value = '';
    document.getElementById('npStreetRef').value = '';
    document.getElementById('addrFormTitle').textContent = 'Нова адреса';
    document.getElementById('addrCancel').style.display = 'none';
    setKind('np');
    setNpType('warehouse');
  }
  var cancel = document.getElementById('addrCancel');
  if (cancel) cancel.addEventListener('click', reset);

  document.querySelectorAll('.addr-edit').forEach(function(btn){
    btn.addEventListener('click', function(){
      var d = btn.dataset;
      document.getElementById('addrId').value = d.id;
      document.getElementById('addrLabel').value = d.label || '';
      document.getElementById('addrAddress').value = d.address || '';
      setKind(d.delivery === 'other' ? 'other' : 'np');
      setNpType(d.type === 'courier' ? 'courier' : 'warehouse');
      document.getElementById('npHouse').value = d.house || '';
      document.getElementById('npFlat').value = d.flat || '';
      // ref підставляємо разом з містом — інакше підказки відділень довелося б
      // «розігрівати» повторним пошуком міста
      if (npWidget) npWidget.apply(d.city, d.ref, d.office, d.officeRef, d.street, d.streetRef);
      else {
        document.getElementById('npCity').value = d.city || '';
        document.getElementById('npOffice').value = d.office || '';
        document.getElementById('npCityRef').value = d.ref || '';
        document.getElementById('npOfficeRef').value = d.officeRef || '';
      }
      document.getElementById('addrFormTitle').textContent = 'Змінити адресу';
      document.getElementById('addrCancel').style.display = '';
      form.scrollIntoView({behavior: 'smooth', block: 'center'});
    });
  });

  var base = '<?= e(url('/')) ?>'.replace(/\/$/, '');
  function poller(checkUrl, hintEl){
    var n = 0;
    var t = setInterval(function(){
      if (++n > 40) { clearInterval(t); return; }
      fetch(base + checkUrl).then(r=>r.json()).then(function(d){
        if (d.linked) { clearInterval(t); location.reload(); }
      });
    }, 3000);
  }
  var tg = document.getElementById('tgLinkBtn');
  if (tg) tg.addEventListener('click', function(){
    fetch(base + '/profile/tg/link').then(r=>r.json()).then(function(d){
      if (!d.ok) return;
      window.open(d.url, '_blank');
      document.getElementById('tgLinkHint').textContent = 'Натисніть Start у боті — сторінка оновиться сама…';
      poller('/profile/tg/check', 'tgLinkHint');
    });
  });
  var vb = document.getElementById('viberLinkBtn');
  if (vb) vb.addEventListener('click', function(){
    fetch(base + '/profile/viber/link').then(r=>r.json()).then(function(d){
      if (!d.ok) return;
      location.href = d.url;
      document.getElementById('viberLinkHint').textContent = 'Підтвердіть у Viber — сторінка оновиться сама…';
      poller('/profile/viber/check', 'viberLinkHint');
    });
  });
})();
</script>
