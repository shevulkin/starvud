<div class="admin-head"><h1 class="h-serif">Налаштування</h1></div>
<form method="post" action="<?= e(url('/admin/settings')) ?>">
  <?= Csrf::field() ?>
  <?php
  // Головний вимикач стоїть над іншими не для краси: у Notify::fire() він —
  // жорсткий гейт, і поки він вимкнений, канальні перемикачі ні на що не
  // впливають. Тому вони й показуються неактивними, а не просто стоять поруч.
  $master = 'notify_all_enabled';
  $masterOn = Settings::bool($master, true);
  $channels = array_diff_key($toggles, [$master => 1]);
  ?>
  <div class="admin-card">
    <h2 class="h-serif">Канали сповіщень</h2>
    <p class="dim" style="margin-bottom:16px">Тексти повідомлень і події — в розділі «Сповіщення».</p>
    <label class="toggle" data-help-title="Головний вимикач сповіщень"
           data-help="Загальний рубильник: поки він вимкнений, не надсилається НІЧОГО, у жоден канал.

Канальні перемикачі нижче при цьому не скидаються — вони просто гаснуть і ні на що не впливають. Увімкнете головний назад, і все повернеться як було.

Беріть його, коли треба швидко припинити всі сповіщення: тестуєте щось на живому сайті або розбираєтесь із помилковою розсилкою.

Для повсякденної роботи він має бути увімкнений.">
      <input type="checkbox" name="toggle[<?= e($master) ?>]" data-master <?= $masterOn ? 'checked' : '' ?>>
      <span class="tr"></span> <b><?= e($toggles[$master]) ?></b>
    </label>
    <div class="toggle-group <?= $masterOn ? '' : 'is-off' ?>" data-group
         data-help-title="Канали сповіщень"
         data-help="Якими шляхами взагалі дозволено надсилати повідомлення: Telegram, Viber, email, пуші.

Це загальний дозвіл на канал. Які саме події яким каналом ідуть і яким текстом — налаштовується окремо, у розділі «Сповіщення».

Вимкнений тут канал не спрацює в жодній події, навіть якщо там він увімкнений.

Канал працює лише тоді, коли для нього заповнені ключі в блоці «Інтеграції та SEO» нижче. Перемикач без ключів нічого не надішле.">

      <?php foreach ($channels as $key => $label): ?>
        <label class="toggle">
          <input type="checkbox" name="toggle[<?= e($key) ?>]" data-child
                 <?= Settings::bool($key, true) ? 'checked' : '' ?> <?= $masterOn ? '' : 'disabled' ?>>
          <span class="tr"></span> <?= e($label) ?>
        </label>
      <?php endforeach; ?>
      <p class="dim toggle-group-note" style="margin:2px 0 0"<?= $masterOn ? ' hidden' : '' ?>>
        Поки головний вимикач вимкнено, не надсилається нічого — налаштування каналів збережені й
        повернуться, щойно ви його ввімкнете.
      </p>
      <?php
      // Головний увімкнено, а канали всі вимкнені — стан, який виглядає
      // налаштованим і при цьому мовчить. Найлегше не помітити саме його.
      $anyChannel = false;
      foreach ($channels as $k => $l) if (Settings::bool($k, true)) { $anyChannel = true; break; }
      ?>
      <p class="check-row is-warn" data-nochan style="margin:4px 0 0"<?= ($masterOn && !$anyChannel) ? '' : ' hidden' ?>>
        <span class="check-icon">⚠️</span>
        <span>Головний вимикач увімкнено, але <b>жоден канал не активний</b> — сповіщення нікуди не підуть.
        Увімкніть хоча б один.</span>
      </p>
    </div>
  </div>
  <div class="admin-card">
    <h2 class="h-serif" data-help-title="Замовлення"
        data-help="Як система поводиться із замовленнями, коли товару немає на складі.

Замовлення розкладається між магазинами автоматично: кожна позиція дістається тій точці, де вона є. Але позицію можна замовити й тоді, коли її немає ніде — товар доробить виробник. Таку позицію теж комусь треба віддати, інакше вона зависне без відповідального.

Магазин за замовчуванням — це і є та точка, яка їх приймає. Ставте сюди основну: ту, де виробництво, або ту, за якою найуважніше стежать.">
      Замовлення</h2>
    <div class="field" data-help-title="Магазин за замовчуванням"
         data-help="Кому дістаються позиції, яких немає на складі жодної точки, — те, що виготовляється під замовлення.

Такі позиції потрапляють у звичайне підзамовлення цього магазину зі звичайним статусом. Продавець бачить у ньому позначку «замовлено понад залишок» і вирішує: передати іншій точці, де товар є, або довиробити.

Якщо не вибрати нічого, їх забирає перша активна точка за порядком сортування. Це працює, але залежить від порядку в списку магазинів: переставили точки місцями — і замовлення почали падати іншому продавцю, ніж досі. Тому краще вибрати явно.">
      <label>Магазин за замовчуванням <span class="dim">— кому йдуть позиції «під замовлення»</span></label>
      <select name="default_store_id">
        <option value="">Перша активна точка (за порядком)</option>
        <?php foreach ($stores as $s): ?>
          <option value="<?= (int)$s['id'] ?>" <?= ($default_store_set && (int)$s['id'] === $default_store_id) ? 'selected' : '' ?>><?= e($s['name'] . ($s['city'] ? ', ' . $s['city'] : '')) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <p class="dim" style="margin:12px 0 0">
      Позиції, яких немає на складі жодної точки, виконує саме цей магазин: товар доробить виробник,
      але відповідальний за замовлення має бути з першої хвилини.
      <?php if (!$default_store_set): ?>
        <br>Зараз вибору немає, і їх забирає
        <b style="color:var(--gold)"><?php
          $auto = '';
          foreach ($stores as $s) if ((int)$s['id'] === $default_store_id) $auto = $s['name'];
          echo e($auto !== '' ? $auto : 'жодна — активних магазинів немає');
        ?></b> — перша активна точка. Порядок магазинів може змінитись, тож надійніше вибрати явно.
      <?php endif; ?>
    </p>
  </div>
  <div class="admin-card">
    <h2 class="h-serif" data-help-title="Торг"
        data-help="Дозволяє покупцям пропонувати свою ціну за свою кількість прямо на сторінці товару.

Це не ще одна автоматична знижка: кожну пропозицію розглядає жива людина в розділі «Торг» і сама вирішує — погодитись, назвати свої умови чи відмовити.

Домовлена ціна діє лише на ту кількість, про яку домовились, лише для тієї людини й лише вказаний час. Ніяких інших знижок поверх неї не додається.

Окремі товари можна вивести з торгу в їхніх картках.">Торг</h2>
    <p class="dim" style="margin:-8px 0 16px">
      Покупець бачить на сторінці товару блок «Не влаштовує ціна? Запропонуйте свою»,
      ви відповідаєте в розділі <a href="<?= e(url('/admin/offers')) ?>">Торг</a>.
      Погоджена ціна закріплюється за цією людиною й нею ж оформлюється замовлення —
      правити суму руками не потрібно.
    </p>
    <label class="toggle" data-help-title="Дозволити торг"
           data-help="Головний вимикач. Знято — блок пропозиції зникає з усіх товарів, а вже початі розмови лишаються видимими, доки не закінчаться.">
      <input type="checkbox" name="offers_enabled" <?= Offers::enabled() ? 'checked' : '' ?>>
      <span class="tr"></span> Дозволити торг у магазині
    </label>
    <div class="form-grid" style="margin-top:16px">
      <div class="field" data-help-title="Не приймати пропозиції нижчі за, %"
           data-help="Найнижча частка від ціни товару, яку форма взагалі пропустить до вас. За замовчуванням 50%.

Це не «наша мінімальна ціна», а фільтр від несерйозного: «машинку за 10 грн» не обговорюють, і десяток таких у черзі ховає одинадцяту, справжню.

Покупцю це число НЕ показується — ані в формі, ані у відмові. Назви ми його, і кожна наступна пропозиція була б рівно ним: торгу не лишилося б, лишилася б ще одна знижка, яку магазин роздає сам собі.

Порожньо — 50%.">
        <label>Не приймати пропозиції нижчі за, % від ціни</label>
        <input type="number" min="0" max="100" name="offers_min_percent"
               value="<?= e((string)Settings::get('offers_min_percent', '')) ?>"
               placeholder="порожньо — <?= (int)Offers::DEFAULT_MIN_PERCENT ?>%">
      </div>
      <div class="field" data-help-title="За скільки годин обіцяємо відповісти"
           data-help="Одне число робить дві роботи, і саме тому воно одне.

Покупець бачить його у формі як обіцянку: «продавець відповість протягом доби». Обіцянка, сказана до того, як людина натиснула кнопку, важить більше за швидкість без обіцянки: мовчання тривожить саме тим, що незрозуміло, скільки чекати.

Ви отримуєте по ньому нагадування: пропозиція, яка чекає довше, нагадає про себе сама (команда offers:remind у cron). Так само нагадуємо покупцю, якщо це ВІН не відповів на ваші умови.

Розвести обіцянку й нагадування на два різні числа — означає рано чи пізно почати брехати одним із них.

Порожньо — 24 години.">
        <label>Обіцяємо відповісти протягом, годин</label>
        <input type="number" min="1" max="720" name="offers_reply_hours"
               value="<?= e((string)Settings::get('offers_reply_hours', '')) ?>"
               placeholder="порожньо — <?= (int)Offers::DEFAULT_REPLY_HOURS ?>">
      </div>
      <div class="field" data-help-title="Скільки діє погоджена ціна, годин"
           data-help="Скільки часу в покупця є на оформлення замовлення після того, як ви погодились. За замовчуванням 48 годин.

Строк потрібен саме тому, що домовленість — це не нова ціна товару назавжди: вона про цю кількість, цю людину й сьогоднішній день. Без строку погоджена колись ціна спливала б через півроку, коли й закупівля, і курс уже інші.

Коли час вийде, розмова закриється сама, а покупець зможе почати нову.">
        <label>Погоджена ціна діє, годин</label>
        <input type="number" min="1" max="8760" name="offers_hold_hours"
               value="<?= e((string)Settings::get('offers_hold_hours', '')) ?>"
               placeholder="порожньо — <?= (int)Offers::DEFAULT_HOLD_HOURS ?>">
      </div>
    </div>
  </div>
  <div class="admin-card">
    <h2 class="h-serif">Видимість для пошукових систем</h2>
    <label class="toggle" data-help-title="Закрити сайт від пошукових систем"
           data-help="Просить Google та інші пошуковики не показувати сайт у результатах пошуку.

Вмикайте, поки сайт наповнюють і тестують: щоб недороблені сторінки не потрапили у видачу.

ОБОВʼЯЗКОВО вимкніть перед запуском. Поки перемикач стоїть, сайт не зʼявиться в Google взагалі, скільки б реклами ви не давали. Поки він увімкнений, угорі кожної сторінки розділу «Адміністрування» висить червоне нагадування.

Це не захист паролем: сторінки лишаються доступними всім, хто знає адресу. Пошуковики просто не додають їх у видачу, а вже проіндексовані зникають за кілька днів.">
      <input type="checkbox" name="seo_noindex" <?= Settings::bool('seo_noindex') ? 'checked' : '' ?>>
      <span class="tr"></span> Закрити сайт від пошукових систем
    </label>
    <p class="dim" style="margin:14px 0 0">Поки увімкнено: у <code>robots.txt</code> стоїть <code>Disallow: /</code>,
      на кожній сторінці — <code>noindex, nofollow</code>, карта сайту порожня. Зручно на час налаштування й тестів.
      <b style="color:var(--gold)">Не забудьте вимкнути перед запуском</b> — інакше сайт не потрапить у Google.</p>
    <p class="dim" style="margin:8px 0 0">Це не захист: сторінки лишаються доступними всім, хто знає адресу.
      Пошуковики просто не додають їх у видачу, а вже проіндексовані зникають протягом кількох днів.</p>
  </div>
  <div class="admin-card">
    <h2 class="h-serif" data-help-title="Інтеграції та SEO"
        data-help="Ключі й адреси, якими сайт зʼєднується із зовнішніми сервісами: месенджери, пошта, Нова Пошта, аналітика.

Значення беруться в кабінеті відповідного сервісу. Поля з ключами й токенами показані крапками — це навмисно, щоб їх не підгледіли через плече.

Не вставляйте сюди чужі чи тимчасові ключі «просто спробувати»: від них залежить, чи дійдуть сповіщення про замовлення.

Кнопка перевірки нижче пробує зʼєднання тими значеннями, що зараз у полях, і нічого не зберігає — можна переконатися до збереження.">
      Інтеграції та SEO</h2>
    <?php /* Одна колонка, а не дві: тут не короткі підписи, а ключі, токени й
             адреси на пів сотні символів. У вузькому полі видно початок і
             крапки — звірити його з кабінетом сервісу неможливо. */ ?>
    <div class="form-grid form-grid-1">
      <?php foreach ($text_keys as $key => $label): ?>
        <?php
          /*
           * Секретність поля вирішує перелік у контролері, а не назва.
           *
           * Раніше тут стояла здогадка по підрядку («є слово key — отже,
           * секрет»). Вона однаково помилялась в обидва боки: ключ Google Maps
           * ховала, хоч він і так їде в HTML кожної сторінки з картою, а нове
           * поле без слова-підказки показала б відкрито.
           */
          $secret = Controllers\Admin\SettingsAdmin::isSecret($key);
          $hint = $secret ? Controllers\Admin\SettingsAdmin::secretHint($key) : '';
        ?>
        <div class="field" data-help-title="<?= e($label) ?>"
             data-help="Значення для інтеграції «<?= e($label) ?>».

Візьміть його в кабінеті відповідного сервісу й вставте сюди повністю, без пробілів на початку та в кінці.
<?= $secret ? "\nЗбережений ключ сюди не повертається — сторінка його не показує нікому, зокрема й розширенням браузера. Порожнє поле означає «не змінювати», а прибрати збережений можна галкою.\n" : '' ?>
Порожнє поле означає, що інтеграція вимкнена: повʼязаний з нею канал сповіщень не працюватиме, навіть якщо його перемикач увімкнений.

Перевірте зʼєднання кнопкою нижче до того, як зберігати.">
          <label><?= e($label) ?>
            <?php if ($secret): ?>
              <span class="dim" style="font-weight:400">— <?= e($hint !== '' ? $hint : 'не задано') ?></span>
            <?php endif; ?>
          </label>
          <?php if ($secret): ?>
            <?php /* Значення не рендериться взагалі — те саме правило, що й для
                     приватного ключа платіжного шлюзу нижче. Звірити збережене з
                     кабінетом сервісу дає підпис поруч із назвою: довжина й
                     чотири останні символи. Цього досить, щоб побачити, що ключ
                     не той, і замало, щоб ним скористатись. */ ?>
            <input type="password" name="text[<?= e($key) ?>]" value=""
                   data-saved="<?= e(Settings::get($key, '')) ?>"
                   autocomplete="off" spellcheck="false"
                   placeholder="<?= $hint !== '' ? 'Залиште порожнім, щоб не змінювати' : 'Вставте ключ із кабінету сервісу' ?>">
            <?php if ($hint !== ''): ?>
              <label class="checkbox" style="margin-top:8px">
                <input type="checkbox" name="secret_clear[<?= e($key) ?>]" value="1"><span>Прибрати збережений ключ</span>
              </label>
            <?php endif; ?>
          <?php else: ?>
            <input type="text" name="text[<?= e($key) ?>]"
                   value="<?= e(Settings::get($key, '')) ?>" autocomplete="off" spellcheck="false">
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <?php /* Перевірка бере значення просто з полів і нічого не зберігає — інакше
             помилковий токен осідав би в базі ще до того, як стало ясно, що він
             помилковий. Виклики лише читальні: webhook не переставляємо, листів
             і повідомлень не шлемо, бо вони пішли б живим людям. */ ?>
    <div class="check-bar">
      <button class="btn btn-line btn-sm" type="button" id="checkBtn"
              data-help-title="Перевірити зʼєднання"
              data-help="Пробує звʼязатися з Telegram, Viber і Новою Поштою тими значеннями, які ЗАРАЗ у полях вище — навіть якщо ви їх ще не зберегли.

Нічого не зберігає й нікому не пише: запити лише читальні, живі люди повідомлень не отримають.

Результат зʼявиться списком нижче: зелена галка — працює, жовтий знак — щось не так, хрестик — не зʼєдналось.

Робіть це щоразу після зміни ключів. Інакше про непрацюючу інтеграцію дізнаєтесь тоді, коли загубиться сповіщення про замовлення.">🔍 Перевірити з&#39;єднання</button>
      <span class="dim" id="checkNote">Питає Telegram, Viber і Нову Пошту тим, що зараз у полях. Нічого не зберігає.</span>
    </div>
    <div id="checkResult" class="check-list" hidden></div>
    <p class="dim">Google OAuth: створіть ключі в Google Cloud Console → OAuth 2.0 Client ID, redirect URI: <code><?= e(GoogleAuth::redirectUri()) ?></code></p>
    <p class="dim">Telegram: створіть бота через @BotFather, вставте токен. Продавці/адміни отримують Chat ID, написавши боту /start (ID показує, напр., @userinfobot).</p>
    <p class="dim">Нова Пошта: безкоштовний API-ключ у особистому кабінеті novaposhta.ua → Налаштування → Безпека.</p>
    <p class="dim">Пошта: заведіть скриньки <b>на домені сайту</b> — <code>shop@</code> (замовлення й сповіщення) і <code>login@</code> (коди входу).
      Друга потрібна, щоб чиясь скарга «це спам» на лист про акцію не завалила доставку тих листів, якими люди входять в акаунт.
      Порожні поля — усе піде з однієї адреси, як раніше. Чужа скринька (<code>@gmail.com</code>, <code>@ukr.net</code>) у полі відправника
      не годиться: сервер сайту не має права слати від їхнього імені, і такі листи відхиляють. Щоб листи не осідали у «Спамі»,
      у DNS домену мають бути записи SPF, DKIM і DMARC.</p>
    <p class="dim">Web Push: ключі згенеровано автоматично. Пуші на телефоні запрацюють після переносу на HTTPS-домен.</p>
  </div>

  <?php /* Відправник накладних. Це не «ще одна інтеграція», а те, що надрукують
           на кожній посилці, — тому окремою карткою й із поясненням, звідки
           беруться значення. Без цих полів кнопка «створити накладну» в
           замовленні чесно скаже, чого бракує, замість мовчазної відмови НП. */ ?>
  <?php /* id — щоб посилання з картки замовлення («накладну не створити,
           бо немає відправника») вело просто сюди, а не на початок сторінки */ ?>
  <div class="admin-card" id="np-sender">
    <h2 class="h-serif" data-help-title="Відправник Нової Пошти"
        data-help="Дані, з якими створюються експрес-накладні: хто відправник, хто контактна особа, з якого відділення несуть посилки.

Контрагента й контактну особу беремо з вашого кабінету НП — натисніть «Підтягнути», і списки заповняться самі. Вручну ці поля не вигадують: у накладній має стояти саме той контрагент, від імені якого ви відправляєте.

Місто й відділення відправлення — те, куди ви фізично приносите посилки.

Магазин може мати власне відділення відправлення (Магазини → правка точки). Тоді ці налаштування для нього не діють — посилку понесуть у сусіднє відділення, а не через пів країни.">
      Нова Пошта: відправник</h2>
    <p class="dim" style="margin-bottom:16px">
      Ці дані друкуються на кожній накладній. Контрагента й контактну особу підтягніть із кабінету НП —
      вигадати їх не можна, накладна створюється саме від їхнього імені.
      <?php if (!$np_enabled): ?><br><b>Спершу впишіть API-ключ вище й збережіть.</b><?php endif; ?>
    </p>

    <div class="check-bar" style="margin-bottom:16px">
      <button class="btn btn-line btn-sm" type="button" id="npLoadBtn">⬇️ Підтягнути з Нової Пошти</button>
      <span class="dim" id="npLoadNote">Читає список відправників вашого кабінету. Нічого не змінює.</span>
    </div>

    <div class="form-grid">
      <div class="field">
        <label>Контрагент-відправник</label>
        <select name="np[np_sender_ref]" id="npSenderRef">
          <option value="<?= e(Settings::get('np_sender_ref', '')) ?>">
            <?= e(Settings::get('np_sender_name', '') ?: (Settings::get('np_sender_ref', '') ? 'Збережений відправник' : '— не обрано —')) ?>
          </option>
        </select>
        <input type="hidden" name="np[np_sender_name]" id="npSenderName" value="<?= e(Settings::get('np_sender_name', '')) ?>">
      </div>
      <div class="field">
        <label>Контактна особа</label>
        <select name="np[np_sender_contact_ref]" id="npContactRef">
          <option value="<?= e(Settings::get('np_sender_contact_ref', '')) ?>">
            <?= e(Settings::get('np_sender_contact_name', '') ?: (Settings::get('np_sender_contact_ref', '') ? 'Збережена особа' : '— не обрано —')) ?>
          </option>
        </select>
        <input type="hidden" name="np[np_sender_contact_name]" id="npContactName" value="<?= e(Settings::get('np_sender_contact_name', '')) ?>">
      </div>
      <div class="field">
        <label>Телефон відправника</label>
        <input type="text" name="np[np_sender_phone]" id="npSenderPhone" value="<?= e(Settings::get('np_sender_phone', '')) ?>" placeholder="0501234567">
      </div>
      <div class="field">
        <label>Місто відправлення</label>
        <input type="text" name="np[np_sender_city]" id="npCity" value="<?= e(Settings::get('np_sender_city', '')) ?>" placeholder="Почніть вводити місто…" autocomplete="new-password" data-lpignore="true" data-1p-ignore data-form-type="other" spellcheck="false">
        <input type="hidden" name="np[np_sender_city_ref]" id="npCityRef" value="<?= e(Settings::get('np_sender_city_ref', '')) ?>">
      </div>
      <div class="field" style="grid-column:1/-1">
        <label>Відділення відправлення</label>
        <input type="text" name="np[np_sender_warehouse]" id="npOffice" value="<?= e(Settings::get('np_sender_warehouse', '')) ?>" placeholder="Номер або адреса відділення" autocomplete="new-password" data-lpignore="true" data-1p-ignore data-form-type="other" spellcheck="false">
        <input type="hidden" name="np[np_sender_warehouse_ref]" id="npOfficeRef" value="<?= e(Settings::get('np_sender_warehouse_ref', '')) ?>">
        <p class="field-hint">Обирайте зі списку — накладна створюється за посиланням на відділення, а не за його назвою.</p>
      </div>
    </div>

    <h3 class="h-serif" style="font-size:16px;margin:22px 0 12px">Типова накладна</h3>
    <p class="dim" style="margin-bottom:14px">Чим заповнюється форма відправлення. Продавець може змінити будь-що перед створенням.</p>
    <div class="form-grid">
      <div class="field">
        <label>Хто платить за доставку</label>
        <select name="np[np_payer]">
          <?php foreach ($np_payers as $key => $label): ?>
            <option value="<?= e($key) ?>"<?= Settings::get('np_payer', 'Recipient') === $key ? ' selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label>Спосіб оплати</label>
        <select name="np[np_payment]">
          <?php foreach ($np_payments as $key => $label): ?>
            <option value="<?= e($key) ?>"<?= Settings::get('np_payment', 'Cash') === $key ? ' selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label>Опис вантажу</label>
        <input type="text" name="np[np_description]" value="<?= e(Settings::get('np_description', 'Дитячі іграшки')) ?>" maxlength="120">
      </div>
      <div class="field">
        <label>Вага за замовчуванням, кг</label>
        <input type="text" name="np[np_weight_default]" value="<?= e(Settings::get('np_weight_default', '0.5')) ?>" placeholder="0.5">
        <p class="field-hint">Береться, лише коли в товарах не проставлена власна вага (Каталог → товар → Вага).</p>
      </div>
      <div class="field">
        <label>Місць у відправленні</label>
        <input type="text" name="np[np_seats_default]" value="<?= e(Settings::get('np_seats_default', '1')) ?>" placeholder="1">
      </div>
    </div>
    <label class="toggle" style="margin-top:14px"
           data-help-title="Післяплата за замовчуванням"
           data-help="Форма накладної одразу підставить суму частини замовлення як післяплату — покупець заплатить при отриманні.

Вмикайте, якщо у вас так возять більшість посилок: інакше продавець вписуватиме суму щоразу руками, а забута післяплата означає віддану без грошей посилку.

Це лише передзаповнення — обнулити поле перед створенням накладної можна завжди.">
      <input type="checkbox" name="np_cod_default" value="1"<?= Settings::bool('np_cod_default', false) ? ' checked' : '' ?>>
      <span class="tr"></span> Післяплата за замовчуванням (сума замовлення)
    </label>
  </div>
  <div class="admin-card">
    <h2 class="h-serif" data-help-title="Оплата карткою на сайті"
        data-help="Інтернет-еквайринг Raiffeisen Bank. Покупець платить карткою одразу при оформленні, а не при отриманні.

Технічно це шлюз UPC (процесинг групи Raiffeisen), тож у документації банку він зветься «eCommerce Connect». Merchant ID, Terminal ID і доступ до тестового середовища видає банк після підписання договору.

Ключі магазин генерує сам у кабінеті шлюзу: приватний лишається у вас і підписує запити, сертифікат UPC перевіряє відповіді про оплату. Для тестового й робочого середовища сертифікати РІЗНІ.

Поки не заповнено все — вибір «оплатити карткою» покупцю просто не показується.">
      Оплата карткою на сайті (Raiffeisen Bank)</h2>

    <?php /* Стан угорі блока, а не внизу: перше питання того, хто сюди зайшов,
             — «воно взагалі працює?», і відповідь на нього не має вимагати
             гортання. Перелік того, чого бракує, — з того самого джерела, що й
             перевірка перед показом кнопки покупцю (Acquiring::missing). */ ?>
    <?php if ($acq_gaps): ?>
      <p class="dim" style="margin-bottom:16px;border-left:3px solid #c0563f;padding-left:12px">
        Оплата карткою <b>не працює</b>: <?= e(implode('; ', $acq_gaps)) ?>.
      </p>
    <?php elseif (!Settings::bool('acq_enabled')): ?>
      <p class="dim" style="margin-bottom:16px;border-left:3px solid var(--gold);padding-left:12px">
        Усе налаштовано, але приймання оплат вимкнене галкою нижче.
      </p>
    <?php else: ?>
      <p class="dim" style="margin-bottom:16px;border-left:3px solid var(--gold);padding-left:12px">
        Оплата карткою працює<?= $acq_env === 'test' ? ' у <b>тестовому</b> середовищі — справжні гроші не рухаються' : '' ?>.
      </p>
    <?php endif; ?>

    <?php /* Адреси для кабінету банку. Їх треба не заповнити тут, а ПЕРЕДАТИ
             банку — інакше шлюзу нікуди повідомляти про оплату, і кожен платіж
             доводилось би звіряти руками. Показуємо готовими рядками, щоб їх
             можна було скопіювати, а не складати з голови. */ ?>
    <div class="field" data-help-title="Адреси для банку"
         data-help="Ці дві адреси вписують у налаштуваннях терміналу на боці банку (або надсилають банку листом при підключенні).

NOTIFY_URL — за нею шлюз повідомляє наш сервер про кожну оплату. Це ЄДИНЕ джерело, за яким замовлення стає оплаченим: браузер покупця може не повернутись узагалі.

SUCCESS_URL / FAILURE_URL — сюди повертається сам покупець. Обидві однакові: сторінка сама розбереться, чим закінчилась оплата, і поведе людину або до замовлення, або до повторної спроби.

Сайт має бути доступний з інтернету по HTTPS. На localhost шлюз не достукається — там перевіряйте оплату лише повертаючись у браузері.">
      <label>Адреси, які треба передати банку</label>
      <p class="dim" style="margin:0;font-size:13px;line-height:1.9">
        NOTIFY_URL: <code><?= e($acq_notify_url) ?></code><br>
        SUCCESS_URL і FAILURE_URL: <code><?= e($acq_return_url) ?></code>
      </p>
    </div>

    <div class="form-grid">
      <div class="field">
        <label>Merchant ID</label>
        <input type="text" name="acq[acq_merchant_id]" value="<?= e((string)Settings::get('acq_merchant_id', '')) ?>"
               placeholder="напр. 1752493" autocomplete="off" spellcheck="false">
      </div>
      <div class="field">
        <label>Terminal ID</label>
        <input type="text" name="acq[acq_terminal_id]" value="<?= e((string)Settings::get('acq_terminal_id', '')) ?>"
               placeholder="напр. E7880293" autocomplete="off" spellcheck="false">
      </div>
      <div class="field" data-help-title="Середовище"
           data-help="Тестовий шлюз приймає тестові картки й нічого не списує — на ньому перевіряють, що підпис сходиться, а сповіщення доходить.

Робоче середовище має ВЛАСНІ Merchant ID, Terminal ID, ключі й сертифікат. Перемикання без заміни всього переліку дасть відмову з кодом 402 на першій же оплаті.">
        <label>Середовище</label>
        <select name="acq_env">
          <?php foreach ($acq_envs as $key => $label): ?>
            <option value="<?= e($key) ?>"<?= $acq_env === $key ? ' selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field" data-help-title="Призначення платежу"
           data-help="Те, що покупець побачить у виписці свого банку. {number} — номер замовлення, {shop} — назва магазину.

Номер тут важливіший за назву товару: саме за ним людина впізнає покупку через тиждень, коли переглядає виписку.">
        <label>Призначення платежу</label>
        <input type="text" name="acq[acq_desc]" value="<?= e((string)Settings::get('acq_desc', '')) ?>"
               placeholder="Замовлення {number} на сайті {shop}" maxlength="125">
      </div>
    </div>

    <?php /* Ключі. У формі їх видно лише як «є / немає»: приватний ключ,
             відданий назад у браузер, лишається потім у кожному його кеші й
             кожному розширенні. Порожнє поле нічого не стирає — інакше правка
             сусіднього рядка вимикала б оплату на всьому сайті. */ ?>
    <div class="field" data-help-title="Приватний ключ магазину"
         data-help="Ним підписується кожен запит на шлюз. Генерується в кабінеті UPC e-Commerce Connect (розділ управління ключами) разом із запитом на сертифікат.

Надійніший спосіб — покласти файл у storage/keys замість вставляння сюди: тоді ключ не потрапляє в дампи бази й у копії, які возять на ноутбуці. Файл із папки завжди старший за вставлене тут.

Формат — PEM, тобто текст, що починається з «-----BEGIN PRIVATE KEY-----».">
      <label>Приватний ключ магазину (PEM)
        <span class="dim" style="font-weight:400">— <?= $acq_has_key ? 'збережено' : 'не задано' ?></span></label>
      <textarea name="acq[acq_key]" rows="3" spellcheck="false" autocomplete="off"
                placeholder="<?= $acq_has_key ? 'Залиште порожнім, щоб не змінювати' : '-----BEGIN PRIVATE KEY-----' ?>"></textarea>
      <?php if ($acq_has_key): ?>
        <label class="checkbox" style="margin-top:8px">
          <input type="checkbox" name="acq_key_clear" value="1"><span>Прибрати збережений ключ</span>
        </label>
      <?php endif; ?>
    </div>

    <div class="field" data-help-title="Сертифікат шлюзу"
         data-help="Ним перевіряється підпис під повідомленням «оплату отримано». Без нього ми не маємо права вважати оплату справжньою — і не вважаємо: без сертифіката оплата карткою просто не вмикається.

Сертифікати тестового й робочого середовища РІЗНІ. Обидва є в документації UPC; банк дає посилання при підключенні.

Файлом кладеться в storage/keys як upc-test.crt або upc-prod.crt — за назвою середовища.">
      <label>Сертифікат шлюзу UPC (PEM)
        <span class="dim" style="font-weight:400">— <?= $acq_has_cert ? 'збережено' : 'не задано' ?></span></label>
      <textarea name="acq[acq_cert]" rows="3" spellcheck="false" autocomplete="off"
                placeholder="<?= $acq_has_cert ? 'Залиште порожнім, щоб не змінювати' : '-----BEGIN CERTIFICATE-----' ?>"></textarea>
      <?php if ($acq_has_cert): ?>
        <label class="checkbox" style="margin-top:8px">
          <input type="checkbox" name="acq_cert_clear" value="1"><span>Прибрати збережений сертифікат</span>
        </label>
      <?php endif; ?>
      <p class="dim" style="margin:8px 0 0;font-size:12.5px">Замість вставляння сюди можна покласти файли
        <code>upc-private.pem</code> і <code>upc-<?= e($acq_env) ?>.crt</code> у
        <code><?= e($acq_key_dir) ?></code> — файл завжди старший за збережене тут.</p>
    </div>

    <?php /* Незакриті оплати. Показуємо тільки коли вони є — і поруч із самим
             вимикачем, а не в кінці сторінки: рішення «вимкнути» приймається
             тут, і саме тут треба знати, кого це залишить без обіцяного
             способу заплатити. Вимкнення нічого не ламає: замовлення живі,
             покупці бачать «продавець зателефонує». Але зателефонувати
             справді доведеться. */ ?>
    <?php if ($acq_pending): ?>
      <p class="dim" style="margin:16px 0 8px;border-left:3px solid var(--gold);padding-left:12px">
        Чекають на оплату карткою: <b><?= count($acq_pending) ?></b> —
        <?php foreach (array_slice($acq_pending, 0, 5) as $i => $o): ?><?= $i ? ', ' : '' ?><a
          href="<?= e(url('/admin/orders/' . (int)$o['id'])) ?>"><?= e($o['number']) ?></a><?php endforeach; ?>
        <?= count($acq_pending) > 5 ? ' та інші' : '' ?>.
        Якщо вимкнути оплату, посилання на неї перестане працювати, а замовлення лишаться —
        з їхніми покупцями доведеться узгодити оплату по телефону.
      </p>
    <?php endif; ?>

    <label class="toggle" data-help-title="Приймати оплату карткою"
           data-help="Головний вимикач. Поки він вимкнений, покупець не бачить вибору «оплатити карткою», а посилання на оплату відповідає «продавець зателефонує». Усе інше працює так, як працювало досі.

Вимкнення безпечне й оборотне: реквізити лишаються на місці, історія оплат у картках замовлень нікуди не зникає, а повернення й списання заблокованих коштів по вже проведених платежах працюють далі — інакше вимкнути оплату означало б заморозити чужі гроші.

Не спрацює вимикач лише на одному: оплату, яку покупець почав за хвилину до вимкнення, банк усе одно проведе, і сайт її зарахує. Це навмисно — гроші вже пішли з картки.

Вимикайте його, а не стирайте ключі, коли треба тимчасово зупинити онлайн-оплату.">
      <input type="checkbox" name="acq_enabled" value="1"<?= Settings::bool('acq_enabled') ? ' checked' : '' ?>>
      <span class="tr"></span> Приймати оплату карткою на сайті
    </label>

    <label class="toggle" data-help-title="Блокувати кошти замість списання"
           data-help="Преавторизація. Гроші на картці покупця блокуються, але магазину ще не належать — списати їх треба окремою дією в картці замовлення, коли підтверджено наявність товару.

Навіщо: у замовленні з кількох магазинів останню банку може забрати хтось інший за хвилину до вас. Заблоковані кошти дозволяють списати менше або не списати нічого, і покупцю не доведеться чекати повернення.

Ціна: списання не можна відкладати надовго — банки розблоковують суму самі за 7–10 днів, і тоді гроші треба брати заново. Забуте блокування коштує довіри дорожче, ніж повернення.

Фіскальний чек за блокуванням не пробивається: розрахунку ще не було. Він з'явиться після списання.">
      <input type="checkbox" name="acq_hold" value="1"<?= Settings::bool('acq_hold', false) ? ' checked' : '' ?>>
      <span class="tr"></span> Блокувати кошти, а списувати вручну (преавторизація)
    </label>

    <label class="toggle" data-help-title="Чек за онлайн-оплатою"
           data-help="Оплата карткою на сайті — така сама розрахункова операція, як картка біля каси, і чек за нею обов'язковий.

Чек ставиться в чергу відразу після оплати, зі способом «Картка» — щоб Z-звіт зійшовся з випискою еквайєра. Точки без заведеної каси пропускаються мовчки, як і при касовому продажу.

Вимикайте лише тоді, коли чеки за онлайн-продажами пробиваються деінде — інакше продаж піде повз ДПС.">
      <input type="checkbox" name="acq_auto_fiscal" value="1"<?= Settings::bool('acq_auto_fiscal', true) ? ' checked' : '' ?>>
      <span class="tr"></span> Пробивати фіскальний чек одразу після оплати
    </label>
  </div>
  <div class="admin-card">
    <?php $isDps = (string)Settings::get('fiscal_provider', 'dps') === 'dps'; ?>
    <h2 class="h-serif" data-help-title="Каса (ПРРО)"
        data-help="Фіскальні чеки. Продаж на касі пробивається автоматично, будь-яке замовлення — кнопкою в його картці.">
      Каса (ПРРО)</h2>
    <?php if ($isDps): ?>
      <p class="dim" style="margin-bottom:16px">
        Чеки йдуть напряму в податкову — безкоштовний ПРРО ДПС
        (<a href="https://webrro.tax.gov.ua/" target="_blank" rel="noopener" style="color:inherit">webrro.tax.gov.ua</a>).
        Потрібні лише зареєстрований ПРРО (фіскальний номер вказується в
        <a href="<?= e(url('/admin/stores')) ?>" style="color:inherit">картці магазину</a>)
        та електронний ключ касира (КЕП). Ключ не завантажується на сайт: касир зчитує його
        з флешки чи телефона на сторінці <a href="<?= e(url('/admin/vchasno')) ?>" style="color:inherit">«Каса»</a>,
        і поки ця вкладка відкрита, вона сама підписує чеки, відкриття зміни й Z-звіти.
      </p>
    <?php else: ?>
      <p class="dim" style="margin-bottom:16px">
        Токен беруть у кабінеті <a href="https://kasa.vchasno.ua/" target="_blank" rel="noopener"
        style="color:inherit">kasa.vchasno.ua</a>: <b>Дії з касою → Налаштування каси → Токен</b>.
        Якщо кас кілька, вписуйте токени в
        <a href="<?= e(url('/admin/stores')) ?>" style="color:inherit">картках магазинів</a>.
      </p>
    <?php endif; ?>
    <?php if ($vchasno_own && !$isDps): ?>
      <p class="dim" style="margin-bottom:16px">
        Власну касу мають: <?= e(implode(', ', array_map(fn($s) => (string)$s['name'], $vchasno_own))) ?>.
        Загальний токен на них не діє.
      </p>
    <?php endif; ?>
    <div class="form-grid">
      <div class="field" data-help-title="Постачальник ПРРО"
           data-help="Хто саме пробиває ваші чеки.

Логіка чека від постачальника не залежить: рядки, знижки, податкові групи й повернення живуть у нас. Тому постачальника можна змінити — а вже пробиті чеки залишаться за тим, хто їх видав, бо повернення робить той самий ПРРО, що й продаж.">
        <label>Постачальник ПРРО</label>
        <select name="fiscal_provider">
          <?php $curProv = (string)Settings::get('fiscal_provider', 'dps'); ?>
          <?php foreach ($fiscal_providers as $key => $p): ?>
            <option value="<?= e($key) ?>"<?= $curProv === $key ? ' selected' : '' ?>><?= e($p['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if ($isDps): ?>
        <?php /* ПРРО ДПС: маршрут один — ключ у браузері касира; натомість тут
                 тестовий режим і адреса фіскального сервера */ ?>
        <div class="field" data-help-title="Адреса фіскального сервера ДПС"
             data-help="Порожньо — типова: https://fs.tax.gov.ua:8643/fs.
ДПС має й запасну адресу без шифрування (http://fs.tax.gov.ua:8609/fs) — вона знадобиться, якщо хостинг не випускає запити на порт 8643. Документи однаково підписані КЕП, тож їх не підмінити.">
          <label>Адреса фіскального сервера</label>
          <input type="text" name="dps_fs_url" value="<?= e((string)Settings::get('dps_fs_url', '')) ?>"
                 placeholder="<?= e(DpsDoc::FS_URL) ?>" spellcheck="false">
        </div>
        <label class="toggle" style="grid-column:1/-1"
               data-help-title="Тестовий режим ПРРО"
               data-help="Документи йдуть у ДПС з позначкою «ТЕСТОВИЙ НЕФІСКАЛЬНИЙ ДОКУМЕНТ»: їм дають номери, але юридичної сили вони не мають. Так перевіряють ключ касира й налаштування.
В одній зміні не можна змішувати тестові й робочі документи: перед вимкненням закрийте тестову зміну Z-звітом на сторінці «Каса».">
          <input type="checkbox" name="dps_testing" value="1"<?= Settings::bool('dps_testing', true) ? ' checked' : '' ?>>
          <span class="tr"></span> Тестовий режим: документи без юридичної сили
        </label>
      <?php else: ?>
      <div class="field" data-help-title="Як доходимо до каси"
           data-help="Маршрут за замовчуванням. Точка може мати свій (Магазини), а продавець — власний (його профіль); тут — те, що діє, коли ніде більше нічого не вказано.

«Хмара постачальника» — чек пробиває наш сервер, ключ підпису лежить у постачальника. Найпростіше, але ключ доводиться віддати.

«Каса точки» — ключ на флешці чи в папці на касовому ПК, а завдання йому приносить агент, який сам стукає до сайту. Назовні в магазині не відкрито нічого, і телефон продавця працює звідусіль.

«Каса на цьому пристрої» — Device Manager стоїть у самого продавця, і чек несе його ж вкладка. Вмикається в профілі людини.">
        <label>Як доходимо до каси</label>
        <select name="fiscal_route">
          <?php $curRoute = (string)Settings::get('fiscal_route', 'cloud'); ?>
          <?php foreach ($fiscal_routes as $key => $label): ?>
            <option value="<?= e($key) ?>"<?= $curRoute === $key ? ' selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="field" data-help-title="Податкова група за замовчуванням"
           data-help="Ставка, з якою товар потрапляє в чек і в ДПС.

Береться, коли в товарі не проставлена власна (Каталог → товар → Податкова група) і коли в магазину немає своєї. Точки можуть належати різним ФОПам, тож у картці магазину група теж є — і вона старша за цю.

Помилка тут не помітна одразу: чек пробʼється, а розбіжність спливе в податковому періоді.">
        <label>Податкова група за замовчуванням</label>
        <select name="vchasno_taxgrp">
          <?php $curTax = (int)Settings::get('vchasno_taxgrp', '2'); ?>
          <?php foreach ($tax_groups as $code => $label): ?>
            <option value="<?= (int)$code ?>"<?= $curTax === (int)$code ? ' selected' : '' ?>>
              <?= (int)$code ?> — <?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label>Підпис під автоматичними операціями</label>
        <input type="text" name="vch[vchasno_cashier]"
               value="<?= e(Settings::get('vchasno_cashier', '')) ?>" maxlength="100"
               placeholder="напр. ФОП Прізвище І. Б.">
        <p class="field-hint">
          <b>Чеки продажу це поле не чіпає</b> — там завжди імʼя того, хто продав.
          Підпис потрібен лише документам, яких не пробивав ніхто живий: нічному Z-звіту з cron.
          У точки може бути свій (Магазини) — це для мережі, де точки належать різним ФОПам.
        </p>
      </div>
      <div class="field" style="grid-column:1/-1">
        <label>Рядок унизу чека</label>
        <input type="text" name="vch[vchasno_comment_down]"
               value="<?= e(Settings::get('vchasno_comment_down', '')) ?>" maxlength="120"
               placeholder="Дякуємо за покупку!">
        <p class="field-hint">Друкується під сумою. Емодзі та рідкісні символи ПРРО не приймає — ми їх приберемо.</p>
      </div>
    </div>
    <label class="toggle" style="margin-top:14px"
           data-help-title="Пробивати чек одразу на касі"
           data-help="Продаж у точці фіскалізується тим самим рухом, яким оформлюється, — але лише той, за який гроші вже отримали: видача з рук із галкою «товар віддано».

Замовлення з каси на доставку так не пробивається: його оплатять при отриманні, а післяплату фіскалізує перевізник. Такий чек лишається кнопкою в картці замовлення.

Вимикайте, якщо чеки пробиваєте в іншій програмі, — тоді сайт нічого не надсилатиме сам.">
      <input type="checkbox" name="vchasno_auto_pos" value="1"<?= Settings::bool('vchasno_auto_pos', true) ? ' checked' : '' ?>>
      <span class="tr"></span> Пробивати чек одразу при продажі на касі
    </label>
    <label class="toggle" style="margin-top:10px"
           data-help-title="Надсилати покупцю посилання на чек"
           data-help="Сервіс ПРРО сам надішле електронний чек на пошту або в SMS, якщо ми передамо їй контакти покупця.

Це не окреме повідомлення від магазину, а сервіс ПРРО: покупець отримає посилання на чек, який можна відкрити й пізніше.

Неправильна пошта чи номер на проведення чека не впливають — просто не буде повідомлення.">
      <input type="checkbox" name="vchasno_send_link" value="1"<?= Settings::bool('vchasno_send_link', true) ? ' checked' : '' ?>>
      <span class="tr"></span> Надсилати покупцю посилання на електронний чек
    </label>
    <label class="toggle" style="margin-top:10px"
           data-help-title="Округлювати готівку"
           data-help="Монет дрібніших за 10 копійок в обігу немає, тож готівкову суму заокруглюють до 10 копійок.

Округлення йде в чек окремим рядком, а не тихою зміною ціни: покупець бачить, звідки взялась різниця, а в ДПС товар коштує рівно стільки, скільки на полиці.

На картку не діє — там платять копійка в копійку.">
      <input type="checkbox" name="vchasno_cash_round" value="1"<?= Settings::bool('vchasno_cash_round', true) ? ' checked' : '' ?>>
      <span class="tr"></span> Округлювати готівку до 10 копійок
    </label>
  </div>
  <div class="admin-card">
    <h2 class="h-serif" data-help-title="Тексти бота при вході"
        data-help="Що саме бот пише людині, яка входить на сайт через Telegram чи Viber.

Вхід завершується лише після того, як людина поділиться номером телефону: без нього неможливо підтвердити замовлення, а покупець із замовленнями на цей номер отримав би другий акаунт.

Ці поля — репліки бота на кожному кроці розмови. Пишіть коротко й по-людськи: це перше враження про магазин.

Порожнє поле повертає типовий текст — сміливо очищайте, якщо свій варіант вийшов гіршим. Слова у фігурних дужках підставляються автоматично.">
      Тексти бота при вході</h2>
    <p class="dim" style="margin-bottom:16px">
      Вхід через Telegram чи Viber завершується лише після того, як людина поділиться номером телефону —
      без нього ми не змогли б підтвердити замовлення, а покупець із замовленнями на цей номер
      отримав би другий акаунт. Це те, що бот пише на кожному кроці.
      <br>Порожнє поле повертає типовий текст. У фігурних дужках — підстановки.
    </p>
    <div style="display:flex;flex-direction:column;gap:16px">
      <?php foreach ($bot_texts as $key => [$default, $hint]): ?>
        <div class="field" style="margin:0">
          <label><?= e($hint) ?></label>
          <textarea name="bot[<?= e($key) ?>]" rows="2" placeholder="<?= e($default) ?>"><?= e(Settings::get($key, '')) ?></textarea>
        </div>
      <?php endforeach; ?>
    </div>
    <p class="dim" style="margin-top:14px">
      Кнопка «назад на сайт» веде на
      <?php if ($bot_site !== ''): ?><code><?= e($bot_site) ?></code><?php else: ?>
        <b style="color:var(--gold)">нікуди — заповніть «Адреса сайту для кнопки в боті» вище</b><?php endif; ?>.
      На домені адреса визначається сама, а от з localhost кнопки не буде: Telegram відхиляє
      посилання на локальну адресу разом з усім повідомленням. Viber до того ж стукає у webhook
      власним запитом, тож на бойовому сервері поле краще заповнити явно.
    </p>
  </div>
  <div class="admin-save">
    <button class="btn btn-gold" type="submit">💾 Зберегти налаштування</button>
    <span class="admin-save-note"></span>
  </div>
</form>

<?= View::partial('partials/np_autocomplete') ?>
<script>
(function(){
  // Місто й відділення відправника — той самий віджет, що й у покупця: люди
  // однаково не памʼятають точних назв відділень, з якого б боку прилавка не стояли
  if (window.npAutocomplete) {
    window.npAutocomplete({city: 'npCity', office: 'npOffice', ref: 'npCityRef', officeRef: 'npOfficeRef'});
  }

  /**
   * Контрагент і контактна особа — з кабінету НП, а не з рук.
   *
   * Вручну ці поля не заповнюють: у накладній має стояти саме той контрагент,
   * від імені якого відправляють, а його Ref ніде, крім API, не побачити.
   * Ключ передаємо з форми — інакше довідник неможливо підтягнути, поки
   * новий ключ ще не збережено.
   */
  var btn = document.getElementById('npLoadBtn'), note = document.getElementById('npLoadNote');
  var senderSel = document.getElementById('npSenderRef'), contactSel = document.getElementById('npContactRef');
  if (!btn || !senderSel || !contactSel) return;
  var senders = [];

  function fill(sel, items, keep){
    var was = keep || sel.value;
    sel.innerHTML = '';
    if (!items.length) {
      sel.appendChild(new Option('— порожньо —', ''));
      return;
    }
    items.forEach(function(it){ sel.appendChild(new Option(it.label, it.ref)); });
    // збережений вибір лишається обраним, якщо він досі є в кабінеті
    if (was && items.some(function(it){ return it.ref === was })) sel.value = was;
    sel.dispatchEvent(new Event('change'));
  }
  function syncNames(){
    document.getElementById('npSenderName').value = senderSel.selectedOptions[0] ? senderSel.selectedOptions[0].text : '';
    document.getElementById('npContactName').value = contactSel.selectedOptions[0] ? contactSel.selectedOptions[0].text : '';
  }
  senderSel.addEventListener('change', function(){
    var s = senders.find(function(x){ return x.ref === senderSel.value });
    if (s) fill(contactSel, s.contacts);
    syncNames();
    // телефон контактної особи — найчастіше саме той, що має стояти в накладній
    var c = s && s.contacts.find(function(x){ return x.ref === contactSel.value });
    var phone = document.getElementById('npSenderPhone');
    if (c && c.phone && !phone.value.trim()) phone.value = c.phone;
  });
  contactSel.addEventListener('change', syncNames);

  btn.addEventListener('click', function(){
    btn.disabled = true;
    note.textContent = 'Питаємо Нову Пошту…';
    var body = new FormData();
    body.append('_csrf', '<?= e(Csrf::token()) ?>');
    var keyField = document.querySelector('[name="text[np_api_key]"]');
    // Збережений ключ у поле не підставляється взагалі, тож порожнє поле
    // означає «не міняли» — тоді сервер бере збережений сам. Надсилаємо лише
    // те, що людина щойно вписала: інакше новий ключ неможливо було б
    // перевірити до збереження.
    if (keyField && keyField.value.trim()) body.append('key', keyField.value.trim());
    fetch('<?= e(url('/api/np/senders')) ?>', {method: 'POST', body: body, credentials: 'same-origin'})
      .then(function(r){ return r.json() })
      .then(function(d){
        btn.disabled = false;
        if (!d.ok) { note.textContent = 'Не вдалося: ' + (d.error || 'невідома причина'); return; }
        senders = d.items || [];
        if (!senders.length) { note.textContent = 'У кабінеті немає жодного відправника — заведіть його на novaposhta.ua'; return; }
        fill(senderSel, senders);
        note.textContent = 'Знайдено відправників: ' + senders.length + '. Оберіть потрібного й збережіть налаштування.';
      })
      .catch(function(){ btn.disabled = false; note.textContent = 'Не вдалося звʼязатися з сервером'; });
  });
})();
</script>
