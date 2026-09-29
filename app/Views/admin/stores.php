<div class="admin-head"><h1 class="h-serif">Магазини</h1></div>
<form class="admin-card" method="post" action="<?= e(url('/admin/stores')) ?>" style="display:flex;gap:14px;align-items:end;flex-wrap:wrap">
  <?= Csrf::field() ?><input type="hidden" name="_action" value="add">
  <div style="flex:1;min-width:160px" data-help-title="Назва магазину"
       data-help="Як точка називається у вас і на сайті: «Головний», «Філія на Подолі».

Покупець бачить цю назву при виборі самовивозу, а продавець — у списку замовлень. Робіть її такою, щоб точку можна було впізнати з одного погляду.

Єдине обовʼязкове поле при створенні — решту можна дозаповнити пізніше.">
    <label>Назва</label><input type="text" name="name" required></div>
  <div data-help-title="Місто"
       data-help="Місто, де стоїть точка.

Саме воно частіше за назву показується там, де мало місця: у виборі магазину, у розкладці залишків, у списку замовлень. Якщо місто задане, у таких місцях покажеться воно, а не назва.

Заповнюйте завжди, коли точок більше однієї, — інакше їх плутатимуть.">
    <label>Місто</label><input type="text" name="city"></div>
  <div data-help-title="Адреса"
       data-help="Вулиця й будинок. Покупець бачить її при виборі самовивозу — саме за нею він до вас приїде.

Пишіть так, як зручно шукати в картах: «вул. Медова, 12».">
    <label>Адреса</label><input type="text" name="address"></div>
  <div data-help-title="Телефон"
       data-help="Контактний номер точки для покупців.

Це номер магазину, а не ваш особистий: він показується на сайті. Сповіщення про замовлення на нього не надсилаються — вони налаштовуються окремо, у розділі «Сповіщення».">
    <label>Телефон</label><input type="text" name="phone"></div>
  <div data-help-title="Графік роботи"
       data-help="Коли точка відчинена. Це найчастіше питання фізичного магазину — без відповіді людина їде навмання.

Пишіть рядком так, як сказали б по телефону: «Пн–Пт 9:00–18:00, Сб 10:00–15:00, Нд вихідний».

Показується на сторінці «Де нас знайти» і при виборі самовивозу. Порожнє поле просто ховає рядок.">
    <label>Графік роботи</label><input type="text" name="hours" placeholder="Пн–Пт 9:00–18:00"></div>
  <div style="min-width:200px" data-help-title="Координати"
       data-help="Точка на карті. Саме за нею магазин показується покупцю при самовивозі й на сторінці «Де нас знайти».

Звідки взяти: відкрийте Google Maps, знайдіть свій вхід, клацніть по ньому правою кнопкою — у меню перший рядок і буде парою чисел. Клацніть по ньому: воно скопіюється. Сюди можна вставити або цю пару, або просто посилання на місце — розберемо обидва.

Адресу це не замінює: адресу читає людина, координати потрібні карті. Порожнє поле — точка лишається в списку, але без мітки.">
    <label>Координати (необовʼязково)</label><input type="text" name="coords" placeholder="50.4501, 30.5234">
</div>
  <button class="btn btn-gold btn-sm" type="submit" data-help-title="Кнопка «Додати»"
          data-help="Створює нову точку одразу активною — вона відразу зʼявиться на сайті у виборі магазину.

Після створення точці треба задати залишки товарів (у картці товару або в масовому редагуванні) і призначити продавців у розділі «Користувачі». Без залишків вона буде порожньою вітриною.">+ Додати</button>
</form>
<form method="post" action="<?= e(url('/admin/stores')) ?>">
  <?= Csrf::field() ?><input type="hidden" name="_action" value="save">
  <?php /* Заповнюється кнопкою «створити токен агента»: форма одна на всі
           точки, і сказати, про яку саме йдеться, більше нема як. */ ?>
  <input type="hidden" name="store_id" value="">
  <table class="tbl">
    <tr><th>Назва</th><th>Місто</th><th>Адреса</th><th>Телефон</th>
      <th data-help-title="Колонка «Графік»"
          data-help="Коли точка відчинена — рядком, як його читає покупець: «Пн–Пт 9:00–18:00, Сб 10:00–15:00».

Показується на сторінці «Де нас знайти» і в розмітці для Google, тож графік потрапляє й у картку компанії в пошуку.">Графік</th>
      <th style="width:220px" data-help-title="Колонка «Координати»"
          data-help="Мітка точки на карті — у самовивозі й на сторінці «Де нас знайти».

Вставляйте пару чисел «50.4501, 30.5234» або посилання з Google Maps: розберемо і те, і те. Кома як десятковий знак («50,4501, 30,5234») теж підійде — саме так копіює система з українською локаллю.

Поруч із заповненим полем зʼявляється «перевірити»: воно відкриває цю точку в Google Maps. Клацніть після заповнення — це єдиний спосіб побачити, що мітка стала на ваш вхід, а не на сусідній квартал.

Порожнє поле — точка лишається в списку самовивозу, але на карті її не буде.">Координати</th>
      <th class="col-mid" data-help-title="Колонка «Активний»"
          data-help="Чи працює точка на сайті просто зараз.

Знята галка означає: магазин зникає з вибору самовивозу, його залишки перестають враховуватись, і нові замовлення на нього не розподіляються.

Уже оформлені замовлення нікуди не діваються — їх усе одно треба виконати.

Це правильний спосіб тимчасово закрити точку (ремонт, відпустка): усі дані, ціни й залишки лишаються на місці, галку можна повернути будь-коли. Видалення магазинів тут немає навмисно — з ними повʼязані замовлення.">Активний</th></tr>
    <?php foreach ($stores as $s): ?>
      <tr>
        <td><input type="text" name="store[<?= (int)$s['id'] ?>][name]" value="<?= e($s['name']) ?>"></td>
        <td><input type="text" name="store[<?= (int)$s['id'] ?>][city]" value="<?= e($s['city']) ?>"></td>
        <td><input type="text" name="store[<?= (int)$s['id'] ?>][address]" value="<?= e($s['address']) ?>"></td>
        <td><input type="text" name="store[<?= (int)$s['id'] ?>][phone]" value="<?= e($s['phone']) ?>"></td>
        <td><input type="text" name="store[<?= (int)$s['id'] ?>][hours]" value="<?= e($s['hours'] ?? '') ?>" placeholder="Пн–Пт 9:00–18:00"></td>
        <td>
          <input type="text" name="store[<?= (int)$s['id'] ?>][coords]" value="<?= e(Geo::format($s)) ?>"
                 placeholder="50.4501, 30.5234">
          <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:5px">
            <?php /* Перевірити мітку можна лише оком на самій карті: пара чисел
                     виглядає правдоподібно й тоді, коли вказує на інший район */ ?>
            <?php if (Geo::has($s)): ?>
              <a class="dim" style="font-size:12px" target="_blank" rel="noopener"
                 href="<?= e('https://www.google.com/maps/search/?api=1&query=' . rawurlencode($s['lat'] . ',' . $s['lng'])) ?>">перевірити →</a>
            <?php endif; ?>
          </div>
        </td>
        <td class="col-mid"><input type="checkbox" name="store[<?= (int)$s['id'] ?>][active]" <?= $s['active'] ? 'checked' : '' ?>></td>
      </tr>
      <?php /* Звідки ця точка відправляє посилки. Окремим рядком під магазином,
               а не колонкою: заповнюють це раз і рідко, а в таблиці з чотирьох
               колонок ще два поля зробили б нечитабельним усе інше. */ ?>
      <tr class="store-np-row">
        <td colspan="6" style="padding-top:0">
          <details<?= trim((string)($s['np_warehouse_ref'] ?? '')) !== '' ? ' open' : '' ?>>
            <summary class="dim" style="cursor:pointer;font-size:13px">
              📦 Відправлення Новою Поштою
              <?= trim((string)($s['np_warehouse'] ?? '')) !== ''
                    ? '— ' . e(trim((string)$s['np_city'] . ', ' . (string)$s['np_warehouse'], ' ,'))
                    : '— зі спільного відділення (Налаштування)' ?>
            </summary>
            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:10px">
              <div style="flex:1;min-width:180px">
                <label class="dim" style="font-size:12px">Місто відправлення</label>
                <input type="text" name="store[<?= (int)$s['id'] ?>][np_city]" value="<?= e($s['np_city'] ?? '') ?>"
                       id="npCity<?= (int)$s['id'] ?>" placeholder="як у спільних налаштуваннях"
                       autocomplete="new-password" data-lpignore="true" data-1p-ignore spellcheck="false">
                <input type="hidden" name="store[<?= (int)$s['id'] ?>][np_city_ref]" value="<?= e($s['np_city_ref'] ?? '') ?>" id="npCityRef<?= (int)$s['id'] ?>">
              </div>
              <div style="flex:2;min-width:220px">
                <label class="dim" style="font-size:12px">Відділення відправлення</label>
                <input type="text" name="store[<?= (int)$s['id'] ?>][np_warehouse]" value="<?= e($s['np_warehouse'] ?? '') ?>"
                       id="npOffice<?= (int)$s['id'] ?>" placeholder="оберіть зі списку"
                       autocomplete="new-password" data-lpignore="true" data-1p-ignore spellcheck="false">
                <input type="hidden" name="store[<?= (int)$s['id'] ?>][np_warehouse_ref]" value="<?= e($s['np_warehouse_ref'] ?? '') ?>" id="npOfficeRef<?= (int)$s['id'] ?>">
              </div>
              <div style="flex:1;min-width:150px">
                <label class="dim" style="font-size:12px">Телефон відправника</label>
                <input type="text" name="store[<?= (int)$s['id'] ?>][np_sender_phone]" value="<?= e($s['np_sender_phone'] ?? '') ?>"
                       placeholder="як у спільних налаштуваннях">
              </div>
            </div>
            <p class="dim" style="margin:8px 0 0;font-size:12px">
              Порожньо — посилки цієї точки їдуть із відділення, вказаного в Налаштуваннях.
              Місто й відділення діють лише разом: одне без одного — накладна в нікуди, тому таке поєднання ігнорується.
            </p>
          </details>
          <?php /* Каса тут із тієї ж причини, що й відділення: у точки вона
                   своя, заповнюють раз і рідко, а в таблиці це була б колонка
                   з шістдесятьма символами токена. */ ?>
          <?php
            $ownRoute = trim((string)($s['fiscal_route'] ?? ''));
            $seen = trim((string)($s['agent_seen_at'] ?? ''));
            $agentAlive = $seen !== '' && strtotime($seen) > time() - 300;
          ?>
          <?php if (FiscalProvider::current() === 'dps'): ?>
            <?php /* ПРРО ДПС: реквізити з форми 1-ПРРО. Маршруту й токена тут
                     немає — чек підписує ключ касира в його браузері. */ ?>
            <details<?= trim((string)($s['dps_fiscal_num'] ?? '')) === '' ? ' open' : '' ?> style="margin-top:8px">
              <summary class="dim" style="cursor:pointer;font-size:13px">
                🧾 ПРРО ДПС цієї точки
                <?= trim((string)($s['dps_fiscal_num'] ?? '')) !== '' ? '— № ' . e((string)$s['dps_fiscal_num']) : '— не вказано' ?>
              </summary>
              <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:10px">
                <div style="flex:1;min-width:180px">
                  <label class="dim" style="font-size:12px">Фіскальний номер ПРРО</label>
                  <input type="text" name="store[<?= (int)$s['id'] ?>][dps_fiscal_num]" inputmode="numeric"
                         value="<?= e((string)($s['dps_fiscal_num'] ?? '')) ?>" placeholder="10 цифр, з кабінету ДПС" spellcheck="false">
                </div>
                <div style="flex:0 1 150px;min-width:120px">
                  <label class="dim" style="font-size:12px">Локальний номер</label>
                  <input type="number" min="1" name="store[<?= (int)$s['id'] ?>][dps_local_num]"
                         value="<?= e((string)($s['dps_local_num'] ?? '')) ?>" placeholder="1">
                </div>
                <div style="flex:1;min-width:200px">
                  <label class="dim" style="font-size:12px">Податкова група точки</label>
                  <select name="store[<?= (int)$s['id'] ?>][vchasno_taxgrp]">
                    <option value="0">як у Налаштуваннях</option>
                    <?php foreach (Vchasno::TAX_GROUPS as $code => $label): ?>
                      <option value="<?= (int)$code ?>"<?= (int)($s['vchasno_taxgrp'] ?? 0) === (int)$code ? ' selected' : '' ?>>
                        <?= (int)$code ?> — <?= e($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
              <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:10px">
                <div style="flex:1;min-width:220px">
                  <label class="dim" style="font-size:12px">Назва господарської одиниці (як у 20-ОПП)</label>
                  <input type="text" maxlength="256" name="store[<?= (int)$s['id'] ?>][dps_point_name]"
                         value="<?= e((string)($s['dps_point_name'] ?? '')) ?>" placeholder="<?= e((string)$s['name']) ?>">
                </div>
                <div style="flex:1;min-width:220px">
                  <label class="dim" style="font-size:12px">Адреса господарської одиниці</label>
                  <input type="text" maxlength="256" name="store[<?= (int)$s['id'] ?>][dps_point_addr]"
                         value="<?= e((string)($s['dps_point_addr'] ?? '')) ?>" placeholder="м. Київ, вул. …">
                </div>
              </div>
              <p class="dim" style="margin:8px 0 0;font-size:12px">
                Номери — з кабінету ДПС (Реєстр ПРРО) після реєстрації форми 1-ПРРО. Касира з ключем
                реєструють у ДПС формою 5-ПРРО. РНОКПП і назву ФОПа чек бере з картки власника точки.
              </p>
            </details>
          <?php else: ?>
          <details<?= $ownRoute !== '' || trim((string)($s['vchasno_token'] ?? '')) !== '' ? ' open' : '' ?> style="margin-top:8px">
            <summary class="dim" style="cursor:pointer;font-size:13px">
              🧾 Каса цієї точки
              <?= $ownRoute !== '' ? '— ' . e(mb_strtolower(FiscalProvider::routeLabel($ownRoute))) : '— як у Налаштуваннях' ?>
            </summary>

            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:10px">
              <div style="flex:1;min-width:220px">
                <label class="dim" style="font-size:12px">Як доходимо до каси</label>
                <select name="store[<?= (int)$s['id'] ?>][fiscal_route]">
                  <option value="">як у Налаштуваннях</option>
                  <?php foreach (FiscalProvider::ROUTES as $key => $label): ?>
                    <option value="<?= e($key) ?>"<?= $ownRoute === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div style="flex:1;min-width:200px">
                <label class="dim" style="font-size:12px">Податкова група точки</label>
                <select name="store[<?= (int)$s['id'] ?>][vchasno_taxgrp]">
                  <option value="0">як у Налаштуваннях</option>
                  <?php foreach (Vchasno::TAX_GROUPS as $code => $label): ?>
                    <option value="<?= (int)$code ?>"<?= (int)($s['vchasno_taxgrp'] ?? 0) === (int)$code ? ' selected' : '' ?>>
                      <?= (int)$code ?> — <?= e($label) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <?php /* Поля обох маршрутів показуємо разом: перемикати їх
                     javascript-ом заради двох рядків — більше коду, ніж
                     користі, а заповнене «не те» поле нікому не заважає. */ ?>
            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:10px">
              <div style="flex:2;min-width:240px">
                <label class="dim" style="font-size:12px">Токен каси (для хмари)</label>
                <input type="password" name="store[<?= (int)$s['id'] ?>][vchasno_token]"
                       value="<?= e($s['vchasno_token'] ?? '') ?>"
                       placeholder="порожньо — спільний токен із Налаштувань"
                       autocomplete="off" spellcheck="false">
              </div>
              <div style="flex:1;min-width:180px">
                <label class="dim" style="font-size:12px">Адреса Device Manager</label>
                <input type="text" name="store[<?= (int)$s['id'] ?>][dm_url]"
                       value="<?= e($s['dm_url'] ?? '') ?>" placeholder="http://localhost:3939" spellcheck="false">
              </div>
              <div style="flex:1;min-width:150px">
                <label class="dim" style="font-size:12px">Назва каси в DM</label>
                <input type="text" name="store[<?= (int)$s['id'] ?>][dm_device]"
                       value="<?= e($s['dm_device'] ?? '') ?>" placeholder="kasa1" spellcheck="false">
              </div>
              <div style="flex:1;min-width:200px">
                <label class="dim" style="font-size:12px">Підпис під нічним Z-звітом</label>
                <input type="text" name="store[<?= (int)$s['id'] ?>][vchasno_cashier]"
                       value="<?= e($s['vchasno_cashier'] ?? '') ?>" maxlength="100"
                       placeholder="як у Налаштуваннях">
              </div>
            </div>
            <p class="dim" style="margin:8px 0 0;font-size:12px">
              Підпис потрібен лише документам, яких не пробивав ніхто живий, — нічному Z-звіту.
              <b>Чеки продажу завжди підписані іменем того, хто продав</b>, і це поле їх не стосується.
              Заповнюйте, якщо точка належить іншому ФОПу.
            </p>

            <p class="dim" style="margin:8px 0 0;font-size:12px">
              <b>Хмара</b> — чек пробиває наш сервер, ключ підпису лежить у постачальника.
              <b>Каса точки</b> — ключ на флешці чи в папці на касовому ПК, а завдання йому
              приносить агент; назовні в магазині нічого не відкрито.
              <b>Каса на цьому пристрої</b> — те саме, але Device Manager стоїть у самого продавця,
              і маршрут йому вмикають у профілі, а не тут.
            </p>

            <?php if ($ownRoute === 'agent'): ?>
              <div style="margin-top:10px;padding-top:10px;border-top:1px solid var(--bg3)">
                <div class="dim" style="font-size:12.5px">
                  Агент:
                  <?php if ($seen === ''): ?>
                    <b>жодного разу не виходив на звʼязок</b> — запустіть його на касовому ПК
                  <?php else: ?>
                    <b><?= $agentAlive ? 'на звʼязку' : 'мовчить' ?></b>,
                    востаннє <?= e(date('d.m H:i', strtotime($seen))) ?>
                  <?php endif; ?>
                </div>
                <button class="btn btn-line btn-xs" type="submit" name="_action" value="agent_token"
                        formnovalidate onclick="this.form.store_id.value='<?= (int)$s['id'] ?>'"
                        style="margin-top:8px"
                        data-help-title="Токен агента"
                        data-help="Ним агент на касовому ПК доводить, що він саме з цієї точки, і забирає лише її чеки.

Токен показується РІВНО ОДИН РАЗ — у базі лишається тільки його відбиток. Скопіюйте його в agent.config.json поруч з агентом.

Натиснути ще раз можна будь-коли, але старий токен одразу перестане працювати, і агента доведеться переналаштувати.">
                  🔑 <?= $seen === '' ? 'Створити токен агента' : 'Створити новий токен' ?></button>
              </div>
            <?php endif; ?>
          </details>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
  <?= View::partial('partials/np_autocomplete') ?>
  <script>
  // Підказки відділень — на кожен рядок свої: id полів різняться номером точки
  (function(){
    if (!window.npAutocomplete) return;
    <?php foreach ($stores as $s): $i = (int)$s['id']; ?>
    window.npAutocomplete({city: 'npCity<?= $i ?>', office: 'npOffice<?= $i ?>',
                           ref: 'npCityRef<?= $i ?>', officeRef: 'npOfficeRef<?= $i ?>'});
    <?php endforeach; ?>
  })();
  </script>
  <div class="admin-save">
    <button class="btn btn-gold" type="submit" data-help-title="Кнопка «Зберегти»"
            data-help="Зберігає всі зміни в таблиці одразу — правки в кількох рядках підуть одним натисканням.

Поки не натиснете, жодна зміна не застосована: підпис поруч показує «Є незбережені зміни», щойно ви щось поправили.

Якщо спробуєте піти зі сторінки з незбереженими правками, браузер перепитає — щоб півгодини роботи не зникли від випадкового кліку по меню.">💾 Зберегти</button>
    <span class="admin-save-note"></span>
  </div>
</form>

