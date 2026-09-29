<?php
/** @var array|null $p @var array $variants @var array $variant_options @var array $attrs @var array $dict */
$isNew = $p === null;
$canStore = fn(int $sid): bool => in_array($sid, Auth::storeIds(), true);
// Картку товару веде адмін, продавець — лише ціни й залишки своїх магазинів.
// Форма повторює те, що перевіряє Products::save(): показувати поля, які все одно
// не збережуться, — гірше, ніж не показувати їх зовсім.
$canEdit = Auth::can('products.manage');
$roEdit = $canEdit ? '' : 'disabled';
?>
<div class="admin-head">
  <?php /* Мініатюра біля назви: блок фото лежить у самому низу (він поза формою,
           бо кожна дія з фото — окрема форма), тож без неї до кінця сторінки
           незрозуміло, який саме товар редагуєш. */ ?>
  <div class="prod-title">
    <?php if (!$isNew && $images): ?>
      <a class="prod-thumb" href="#photos" title="Перейти до фотографій">
        <img src="<?= e(asset(Images::displayThumb($images[0]['path']))) ?>" alt="">
        <?php if (count($images) > 1): ?><span class="prod-thumb-n"><?= count($images) ?></span><?php endif; ?>
      </a>
    <?php elseif (!$isNew): ?>
      <a class="prod-thumb is-empty" href="#photos" title="Фото ще немає — додати">🧸</a>
    <?php endif; ?>
    <h1 class="h-serif"><?= $isNew ? 'Новий товар' : e($p['name']) ?></h1>
  </div>
  <a class="btn btn-line btn-sm" href="<?= e(url('/admin/products')) ?>">← До списку</a>
</div>

<?php if (!$canEdit): ?>
  <div class="admin-card" style="border-color:var(--gold)">
    Картку товару (назву, опис, базові ціни, варіанти, фото) редагує адміністратор.
    Вам доступні <b>ціни та залишки ваших магазинів</b> — нижче на цій сторінці.
  </div>
<?php endif; ?>

<form method="post" action="<?= e(url($isNew ? '/admin/products/new' : '/admin/products/' . $p['id'])) ?>" id="productForm">
  <?= Csrf::field() ?>
  <?php /* Enter у будь-якому полі має зберігати товар, а не запускати генератор варіантів */ ?>
  <button type="submit" class="submit-default" tabindex="-1" aria-hidden="true"></button>
  <div class="admin-card">
    <h2 class="h-serif">Основне</h2>
    <div class="form-grid">
      <div class="field" data-help-title="Назва товару"
           data-help="Те, що покупець бачить у каталозі, на сторінці товару, у кошику й у листі про замовлення. Єдине обовʼязкове поле.

Пишіть так, як людина шукала б це у пошуку: «Пазл дерев'яний „Ферма“» краще за «Пазл дер. арт.2024».

Обʼєм, вагу чи колір у назву краще не вписувати — для цього є Варіанти нижче: тоді це буде один товар з вибором, а не пʼять окремих карток.">
        <label>Назва *</label><input type="text" name="name" required value="<?= e($p['name'] ?? '') ?>" <?= $roEdit ?>></div>
      <div class="field" data-help-title="Категорія"
           data-help="Розділ каталогу, у якому покупець знайде товар.

Впливає більше, ніж здається: від категорії залежить, чи спрацює акція, задана на категорію, і які характеристики зʼявляться у списку нижче (він підлаштовується під обрану категорію).

Якщо категорія неправильна, товар просто не знайдуть у потрібному розділі.">
        <label>Категорія</label>
        <?php /* data-type на кожному пункті: скрипт лишає в списку лише ті
                 категорії, що відповідають обраному типу товару. Заборона все
                 одно на сервері (Products::categoryTypeError) — тут зручність,
                 щоб не обирати те, що потім не збережеться. */ ?>
        <select name="category_id" id="catSelect" <?= $roEdit ?>>
          <?php foreach ($categories as $c): ?>
            <option value="<?= (int)$c['id'] ?>" data-type="<?= e((string)($c['type'] ?? 'product')) ?>"
                    <?= ($p['category_id'] ?? 0) == $c['id'] ? 'selected' : '' ?>><?= e(cat_label($c)) ?></option>
          <?php endforeach; ?>
        </select>
        <p class="field-hint" id="catTypeHint" hidden></p>
      </div>
      <div class="field" data-help-title="Артикул (SKU)"
           data-help="Ваш внутрішній код товару для обліку: «PZL-FARM-01».

У каталозі покупцю не показується, але за ним працює пошук в адмінці — зручно, коли назви схожі й треба знайти рівно те, що на етикетці.

Поле необовʼязкове. Якщо ведете склад у таблиці чи 1С, ставте тут той самий код, що й там.">
        <label>Артикул</label><input type="text" name="sku" value="<?= e($p['sku'] ?? '') ?>" <?= $roEdit ?>></div>
      <div class="field" data-help-title="Штрихкод"
           data-help="Код із етикетки — той, що під смужками (EAN-13, зазвичай 13 цифр). Саме його читає сканер на касі.

Це не те саме, що артикул: артикул придумуєте ви для обліку, штрихкод друкує виробник. Тому й поля два — інакше вони рано чи пізно перетруть одне одного.

Найпростіший спосіб заповнити: станьте курсором у поле й піднесіть сканер до етикетки — він сам «надрукує» код.

У товару з фасовками код належить фасовці, а не товару: заповнюйте його в рядках варіантів нижче.">
        <label>Штрихкод</label><input type="text" name="barcode" value="<?= e($p['barcode'] ?? '') ?>" <?= $roEdit ?>
               inputmode="numeric" autocomplete="off"></div>
      <?php
      $prodBrands = $p ? Catalog::brandsOf($p) : [];
      $chosenBrands = array_map(fn($b) => (int)$b['id'], $prodBrands);
      // неактивний бренд лишається у списку, поки він призначений цьому товару:
      // інакше вибір мовчки злетів би при найближчому збереженні
      $brandList = Catalog::brands(true);
      foreach ($prodBrands as $b) {
          if (!$b['active']) $brandList[] = $b;
      }
      ?>
      <div class="field" style="min-width:260px" data-help-title="Бренди (чий товар)"
           data-help="Хто виробник цієї позиції. Список ведеться в розділі «Каталог → Бренди».

Брендів може бути кілька — це і є спільне виробництво. Позначте свій і партнерів: товар знайдеться в пошуку за кожним із них, а покупець побачить «Виготовляємо разом із «Медоїжка»».

Лише свій бренд — «Виготовимо під замовлення, ми виробник». Лише чужий або жодного — «Виготовляється на замовлення, привеземо для вас».

Це твердження про походження товару, тому вгадувати його сайт не буде: порожньо означає «не наше».

Бренди також показуються покупцю в характеристиках і йдуть у розмітку для Google.">
        <label>Бренди</label>
        <div style="display:flex;flex-direction:column;gap:6px">
          <?php foreach ($brandList as $b): ?>
            <label class="checkbox" style="margin:0">
              <input type="checkbox" name="brand_ids[]" value="<?= (int)$b['id'] ?>"
                     <?= in_array((int)$b['id'], $chosenBrands, true) ? 'checked' : '' ?> <?= $roEdit ?>>
              <span><?= e($b['name']) ?><?php if ($b['own']): ?> <span class="dim">— наш</span><?php endif; ?><?php
                if (!$b['active']): ?> <span class="dim">— неактивний</span><?php endif; ?></span>
            </label>
          <?php endforeach; ?>
          <?php if (!$brandList): ?>
            <span class="dim">Список порожній — <a href="<?= e(url('/admin/brands')) ?>">додайте бренди</a>.</span>
          <?php endif; ?>
        </div>
      </div>
      <div class="field" data-help-title="Тип"
           data-help="Що це за позиція: звичайний Товар, Послуга, Відео чи Курс.

Для іграшок і всього, що можна покласти в коробку, лишайте «Товар» — це варіант за замовчуванням.

Решта типів потрібні для нематеріальних позицій, які не возять і не рахують на складі.">
        <label>Тип</label>
        <select name="type" <?= $roEdit ?>>
          <?php foreach (Catalog::TYPES as $t => $lbl): ?>
            <option value="<?= $t ?>" <?= ($p['type'] ?? 'product') === $t ? 'selected' : '' ?>><?= $lbl ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php /* Поле курсу серед полів товару — бо курс і є товар. Показуємо
               завжди: воно порожнє в усіх, крім курсів, і ховати його скриптом
               означало б ще один стан інтерфейсу заради одного числа. */ ?>
      <div class="field" data-help-title="Доступ до курсу, днів"
           data-help="Скільки днів курс лишається відкритим у кабінеті після оплати.

Порожньо означає «назавжди» — саме так продається більшість курсів, і цей випадок не має вимагати заповнення поля.

Стосується лише товарів типу «Курс»; для решти поле не читається.

Якщо курс куплять удруге, доступ ПОДОВЖИТЬСЯ від пізнішої дати, а не почнеться заново. Раз відкритий назавжди строковим уже не стане.">
        <label>Доступ, днів <span class="dim">(курс)</span></label>
        <input type="number" name="access_days" min="1" step="1" placeholder="назавжди"
               value="<?= e((string)($p['access_days'] ?? '')) ?>">
      </div>
      <div class="field" data-help-title="Базова ціна"
           data-help="Ціна за замовчуванням, у гривнях.

Її можуть перебити, у такому порядку: окрема ціна магазину (таблиця нижче), потім ціна варіанта, а вже на результат накладається акція.

Порожнє поле означає «За запитом»: покупець побачить не число, а пропозицію звʼязатися. Використовуйте свідомо — товар без ціни купують значно рідше.">
        <label>Базова ціна, грн (порожньо = «За запитом»)</label><input type="number" step="0.01" name="base_price" value="<?= e(num_val($p['base_price'] ?? '')) ?>" <?= $roEdit ?>></div>
      <div class="field" data-help-title="Стара ціна"
           data-help="Закреслена ціна поруч із поточною — щоб показати вигоду.

Показується тільки якщо вона більша за поточну; інакше сайт її просто проігнорує.

Увага: товар зі старою ціною вважається «вже зі знижкою». Промокод із вимкненим «Сумується з акціями» на нього не подіє, а стеля знижки рахуватиме цю різницю. Не ставте сюди вигадану ціну «для краси» — вона впливає на реальні розрахунки.

Якщо знижка тимчасова, правильніше створити Акцію: вона сама закреслить стару ціну й сама закінчиться в потрібний день.">
        <label>Стара ціна (закреслена)</label><input type="number" step="0.01" name="old_price" value="<?= e(num_val($p['old_price'] ?? '')) ?>" <?= $roEdit ?>></div>
    </div>
    <div class="field" data-help-title="Короткий опис"
         data-help="Один рядок, який показується під назвою в каталозі — там, де покупець швидко переглядає багато товарів.

Скажіть головне: «Зібраний у липні на Полтавщині, 0.5 л».

Довгий текст тут обріжеться — для нього є «Повний опис» нижче.">
      <label>Короткий опис</label><input type="text" name="short_desc" value="<?= e($p['short_desc'] ?? '') ?>" <?= $roEdit ?>></div>
    <div class="field" data-help-title="Повний опис"
         data-help="Розгорнутий текст на сторінці товару: походження, смак, як зберігати, чим корисний.

Переноси рядків зберігаються, тож можна писати абзацами. Розмітка не підтримується — це звичайний текст.

Технічні дані (вага, обʼєм, регіон) краще виносити в Характеристики нижче: за ними працюють фільтри в каталозі, а за текстом опису — ні.">
      <label>Повний опис</label><textarea name="description" rows="5" <?= $roEdit ?>><?= e($p['description'] ?? '') ?></textarea></div>
    <div style="display:flex;gap:26px;flex-wrap:wrap">
      <label class="checkbox" data-help-title="Активний"
             data-help="Чи показувати товар на сайті просто зараз.

Знята галка — товар зникає з каталогу й пошуку, купити його неможливо. При цьому нічого не втрачається: фото, опис, ціни й залишки лишаються, галку можна повернути будь-коли.

Так правильно ховати сезонні позиції й те, що тимчасово не продаєте, — замість видаляти картку.">
        <input type="checkbox" name="active" <?= ($p['active'] ?? 1) ? 'checked' : '' ?> <?= $roEdit ?>> Активний (видно на сайті)</label>
      <label class="checkbox" data-help-title="Рекомендований"
             data-help="Піднімає товар угору каталогу й позначає його як «Хіт».

Ставте небагатьом позиціям. Якщо позначити половину каталогу, позначка перестане щось означати, а сортування — допомагати.">
        <input type="checkbox" name="featured" <?= ($p['featured'] ?? 0) ? 'checked' : '' ?> <?= $roEdit ?>> Рекомендований (на головній)</label>
      <label class="checkbox" data-help-title="Виготовляємо під замовлення"
             data-help="Змінює те, що бачить покупець, коли товару немає на складі.

Галка стоїть — товар можна замовити в будь-якій кількості, навіть коли на складі порожньо. Замість «немає в наявності» покупець побачить, що позицію зроблять під замовлення. Текст залежить від поля «Бренд»: свій товар — «ми виробник», чужий — нейтральне «привеземо для вас».

Галка знята — продаємо лише те, що є: покласти в кошик більше, ніж лишилось у мережі магазинів, сайт не дасть, а на нулі кнопка стане «Немає в наявності».

Тобто це не лише напис, а й межа продажу. Знімайте галку там, де виготовити додатково неможливо.">
        <input type="checkbox" name="made_to_order" <?= ($p['made_to_order'] ?? 1) ? 'checked' : '' ?> <?= $roEdit ?>> Виготовляємо під замовлення</label>
    </div>
    <?php $yr = static fn($mo) => $mo === null || $mo === '' ? '' : rtrim(rtrim(number_format((int)$mo / 12, 1, '.', ''), '0'), '.'); ?>
    <div class="field" style="max-width:360px" data-help-title="Вік дитини"
         data-help="З якого віку іграшка розрахована — як на упаковці. За цим числом працює фільтр «За віком» на сайті й бейдж на картці.

Роки, можна з половинкою: 0 — з народження, 0.5 — від 6 місяців, 1.5 — від 18 місяців.

«До» заповнюйте лише для іграшок з верхньою межею (напр. «від 2 до 4 років»). Порожнє «від» — товар не потрапить у жоден віковий фільтр.">
      <label>Вік дитини, років</label>
      <div style="display:flex;gap:10px;align-items:center">
        <input type="text" inputmode="decimal" name="age_from" value="<?= e($yr($p['age_min'] ?? null)) ?>" placeholder="від" style="max-width:110px" <?= $roEdit ?>>
        <span class="dim">—</span>
        <input type="text" inputmode="decimal" name="age_to" value="<?= e(($p['age_max'] ?? null) === null ? '' : (string)intdiv((int)$p['age_max'], 12)) ?>" placeholder="до (не обовʼязково)" style="max-width:170px" <?= $roEdit ?>>
      </div>
    </div>
    <div class="field" style="max-width:360px" data-help-title="Поріг «закінчується»"
         data-help="З якої кількості показувати покупцю «закінчується» замість «в наявності».

Поставте 3 — і щойно в магазині лишиться 3 штуки або менше, поруч із цією точкою зʼявиться «закінчується». Це підштовхує не відкладати покупку.

Рахується окремо по кожному магазину, а не по всій мережі.

Порожньо — попередження не показується взагалі, буде просто «в наявності».">
      <label>Поріг «закінчується», шт.</label>
      <input type="number" min="0" step="1" name="low_stock_threshold" value="<?= e($p['low_stock_threshold'] ?? '') ?>" placeholder="порожньо — показувати просто «в наявності»" <?= $roEdit ?>>
    </div>
    <div class="field" data-help-title="Вага, кг"
         data-help="Скільки важить одна штука разом із тарою — це те, за чим Нова Пошта рахує доставку.

Проставте — і форма накладної сама порахує вагу посилки: вага × кількість по всіх позиціях. Продавцю лишиться звірити, а не зважувати кожне замовлення.

Порожньо — береться типова вага з Налаштувань. Це не помилка, просто накладна буде приблизною, і НП може перерахувати за фактом.

У товару з фасовками вагу вказують у самій фасовці: набір із 12 баночок і з 6 важить по-різному.">
      <label>Вага, кг</label>
      <input type="text" name="weight" value="<?= e(num_val($p['weight'] ?? null)) ?>" placeholder="0.5 — для розрахунку накладної" <?= $roEdit ?>>
    </div>
    <div class="field" data-help-title="Податкова група"
         data-help="З якою ставкою товар потрапляє у фіскальний чек і в ДПС.

Порожньо — береться типова група магазину, який продає (а якщо в нього своєї немає — загальна з Налаштувань). Так стоїть у більшості товарів: уся полиця оподатковується однаково.

Заповнюйте там, де товар відрізняється від решти: підакцизне, пільгове, послуга без ПДВ.

Помилка тут не помітна одразу — чек пробʼється, а розбіжність спливе аж у податковому періоді.">
      <label>Податкова група</label>
      <select name="taxgrp" <?= $roEdit ?>>
        <option value="0">як у магазину</option>
        <?php foreach (Vchasno::TAX_GROUPS as $code => $label): ?>
          <option value="<?= (int)$code ?>"<?= (int)($p['taxgrp'] ?? 0) === (int)$code ? ' selected' : '' ?>>
            <?= (int)$code ?> — <?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field" data-help-title="Код УКТЗЕД"
         data-help="Потрібен у чеку лише для окремих груп товарів — підакцизних та тих, для яких його вимагає закон.

Для іграшок і решти звичайних товарів залишайте порожнім: зайвий код у чеку нікому не допомагає, а помилковий створює розбіжність у звітності.">
      <label>Код УКТЗЕД</label>
      <input type="text" name="uktzed" value="<?= e($p['uktzed'] ?? '') ?>" maxlength="32"
             placeholder="лише для підакцизних" <?= $roEdit ?>>
    </div>
  </div>

  <?php if (!$isNew && $canEdit): ?>
  <?php
    /* Звідки шкала береться зараз: своя, розділу чи загальна. Порожнє поле в
       картці означає «як у розділі», і людина мусить бачити, як саме, — інакше
       порожнеча читається як «знижок немає», а знижка при цьому діє. */
    $inherited = $qty_inherit ?? ['tiers' => [], 'level' => 'none', 'category_id' => null];
    $wholesaleOn = Catalog::wholesale($p);
  ?>
  <div class="admin-card">
    <h2 class="h-serif">Оптова знижка</h2>
    <p class="dim" style="margin:-8px 0 18px">
      Чим більше штук бере покупець, тим дешевша штука. Знижки показуються в картці товару
      й спрацьовують у кошику самі — рахувати руками й правити суму замовлення не потрібно.
    </p>

    <div class="form-grid">
      <label class="checkbox" data-help-title="Опт діє на цей товар"
             data-help="Головний вимикач опту для цього товару.

Галка стоїть — працюють знижки за кількість: свої, якщо ви заповнили їх нижче, або знижки розділу, якщо ні.

Галка знята — опту немає взагалі, скільки б штук не взяли. Це різні речі: порожня таблиця знижок означає «як у розділі», а знята галка — «на цей товар знижки за кількість немає». Знімайте її там, де партія не здешевлює товар: одинична робота, товар із чужою фіксованою ціною, позиція з мінімальною маржею.">
        <input type="checkbox" name="wholesale" id="wholesaleOn" <?= $wholesaleOn ? 'checked' : '' ?> <?= $roEdit ?>>
        Опт діє на цей товар</label>

      <?php /* Обмеження лишається на видноті завжди: воно стосується не лише
               опту, а будь-якого поєднання знижок — акції, набору, промокоду.
               Сховати його разом з оптом означало б прибрати з очей межу,
               яка діє й далі. */ ?>
      <div class="field" data-help-title="Максимальна знижка, %"
           data-help="Найбільша сумарна знижка на цей товар — межа, за яку не вийде жодне поєднання акції, опту, набору й промокоду.

Діє незалежно від опту: навіть коли галка «Опт діє» знята, це обмеження стосується акції разом із промокодом.

Знижки складаються: акція 15% плюс опт 7% плюс код 10% дають 32%. Кожна окремо здається невеликою, а разом вони доходять до цін, яких ніхто не планував. Обмеження діє саме на суму: у прикладі при межі 25% код додасть 3%, а не 10%.

Порожньо — береться загальна межа з розділу «Оптові знижки». Заповнюйте там, де товар витримує менше або більше за решту полиці.

Ціни, яку ви призначили самі, це не чіпає: якщо акція вже дає більше за межу, вона лишається як є. Обмежується те, що додається зверху.">
        <label>Максимальна знижка, %</label>
        <input type="number" min="0" max="100" step="0.01" name="max_discount"
               value="<?= e(num_val($p['max_discount'] ?? '')) ?>"
               placeholder="порожньо — загальна (<?= e(QtyDiscounts::pct(Catalog::discountCap([]))) ?>%)" <?= $roEdit ?>>
      </div>
    </div>

    <?php /* Усе, що нижче, має сенс лише при ввімкненому опті. Поля, які нічого
             не вирішують, — не нейтральні: вони змушують читати себе й гадати,
             чи не залежить від них щось. */ ?>
    <div id="wholesaleFields"<?= $wholesaleOn ? '' : ' hidden' ?>>
    <div class="form-grid">
      <div class="field" data-help-title="Що рахувати для порогу"
           data-help="Як складається кількість, за якою спрацьовує поріг, коли в товару є варіанти.

«Усі варіанти разом» — мʼячики різного кольору, фігурки різних героїв, набори одного й того самого. Три плюс дві дають пʼять, і поріг «від 5 шт» спрацьовує. Так стоїть у більшості товарів.

«Кожен варіант окремо» — коли варіанти насправді різні речі: розміри шапки, розміри костюма. Пʼять різних розмірів — це пʼять різних речей, і кожна йде своїм рахунком.

З даних ця різниця не виводиться — її знаєте лише ви. Помилка тут не ламає нічого, але дає покупцю не ту ціну, на яку ви розраховували.">
        <label>Рахувати кількість</label>
        <select name="qty_scope" <?= $roEdit ?>>
          <option value="product"<?= Catalog::qtyScope($p) === 'product' ? ' selected' : '' ?>>усі варіанти разом</option>
          <option value="variant"<?= Catalog::qtyScope($p) === 'variant' ? ' selected' : '' ?>>кожен варіант окремо</option>
        </select>
      </div>
    </div>

    <div style="margin-top:18px;max-width:520px" data-help-title="Знижки за кількість"
         data-help="Знижки саме цього товару: від скількох штук і скільки відсотків.

Заповнені знижки товару повністю замінюють знижки розділу — не додаються до них і не змішуються з ними. Саме тому тут можна зробити опт меншим за розділ, а не лише більшим.

Порожня таблиця означає «як у розділі». Щоб опту не було зовсім, зніміть галку «Опт діє на цей товар» угорі.

Пороги мають зростати: «від 10 шт» зобовʼязане давати більше за «від 5 шт», інакше покупцю немає сенсу брати десять.">
      <h3 style="margin:0 0 10px;font-size:15px">Знижки за кількість для цього товару</h3>
      <?= View::partial('partials/qty_tiers', ['tiers' => $qty_tiers ?? [], 'name' => 'tier', 'ro' => $roEdit]) ?>
    </div>

    <?php if (!$qty_tiers): ?>
      <?php
        /* Що діє замість власної шкали. Рахуємо при вимкненому опті теж:
           галку знімають і повертають у тій самій формі, і підказка мусить
           бути готовою до моменту, коли її знову покажуть. */
        $inheritLine = '';
        if ($inherited['level'] === 'category') {
            $inheritLine = 'Зараз діють знижки розділу «'
                . (string)(DB::val('SELECT name FROM categories WHERE id = ?', [$inherited['category_id']]) ?? '—')
                . '»: ' . QtyDiscounts::line($inherited['tiers']) . '.';
        } elseif ($inherited['level'] === 'global') {
            $inheritLine = 'Зараз діють загальні знижки магазину: ' . QtyDiscounts::line($inherited['tiers']) . '.';
        } else {
            $inheritLine = 'Знижок за кількість немає ні тут, ні в розділі, ні в загальних налаштуваннях — опт на цей товар не спрацює.';
        }
      ?>
      <p class="dim" style="margin:14px 0 0"><?= e($inheritLine) ?></p>
    <?php endif; ?>
    </div>

    <?php /* Замість схованих полів — одне речення про те, чому їх немає.
             Порожнє місце під галкою читалось би як недороблена сторінка. */ ?>
    <p class="dim" id="wholesaleOffNote"<?= $wholesaleOn ? ' hidden' : '' ?> style="margin:14px 0 0">
      Опт вимкнено — знижки за кількість на цей товар немає, скільки б штук не взяли.
      Поставте галку, щоб задати знижки або взяти їх із розділу.
    </p>
  </div>

  <script>
  /* Поля опту показуються рівно тоді, коли опт увімкнено. Межа знижки лишається
     на видноті завжди: вона обмежує й акцію з промокодом. */
  (function () {
    var box = document.getElementById('wholesaleOn');
    var fields = document.getElementById('wholesaleFields');
    var off = document.getElementById('wholesaleOffNote');
    if (!box || !fields) return;
    box.addEventListener('change', function () {
      fields.hidden = !box.checked;
      if (off) off.hidden = box.checked;
    });
  })();
  </script>

  <?php /* Торг стоїть одразу під оптом, бо відповідає на те саме питання
           покупця — «а дешевше буде?» — але іншим способом: опт роздає знижку
           за правилом, торг дає її живою людиною й лише цій людині. */ ?>
  <?php /* Блок курсу показуємо лише курсам: у картці меду ці поля були б двома
           великими порожніми прямокутниками, які щоразу треба проминати. Тип
           міняють рідко й свідомо, тож після зміни на «Курс» блок зʼявиться
           наступним збереженням — це чесніше за поле, яке блимає від select. */ ?>
  <?php if (($p['type'] ?? '') === 'course'): ?>
  <div class="admin-card">
    <h2 class="h-serif">Сторінка курсу</h2>
    <p class="card-lead">Те, що читає студент перед покупкою. Порожнє поле —
      блока на сторінці не буде взагалі: курс без програми виглядає скромніше,
      ніж заголовок «Програма» з порожнечею під ним.</p>
    <div class="field" data-help-title="Чого ви навчитесь"
         data-help="Результати навчання — по одному в рядку, без нумерації.

Це найважливіший блок сторінки. Пишіть про те, ким людина стане, а не про те, що ви розповідатимете: «самостійно вивести матку» замість «тема 4: виведення маток».

Три-пʼять пунктів. Довший список читають по діагоналі.">
      <label>Чого ви навчитесь <span class="dim">(по пункту в рядку)</span></label>
      <textarea name="learn_outcomes" rows="5" placeholder="Що вміє дитина після заняття — по пункту в рядку"><?= e((string)($p['learn_outcomes'] ?? '')) ?></textarea>
    </div>
    <div class="field" data-help-title="Програма"
         data-help="Модулі або тижні курсу — по одному в рядку. Нумерація проставиться сама.

Відповідає на питання «наскільки це серйозно». Тому конкретика: не «теорія», а «Біологія бджолиної сімʼї: цикл, ролі, зимівля».">
      <label>Програма <span class="dim">(по пункту в рядку, нумерація автоматична)</span></label>
      <textarea name="program" rows="7" placeholder="Теми програми — по одній у рядку"><?= e((string)($p['program'] ?? '')) ?></textarea>
    </div>
    <p class="dim" style="margin:0">Короткі факти — тривалість, формат, розмір групи —
      задаються нижче, у «Характеристиках»: вони показуються рядком під назвою курсу.</p>
  </div>
  <?php endif; ?>

  <div class="admin-card">
    <h2 class="h-serif">Торг</h2>
    <p class="dim" style="margin:-8px 0 18px">
      Покупець може запропонувати свою ціну за свою кількість, а ви — погодитись,
      дати зустрічні умови або відмовити. Пропозиції приходять у розділ
      <a href="<?= e(url('/admin/offers')) ?>">Торг</a>.
      <?php if (!Offers::enabled()): ?>
        <b>Зараз торг вимкнений по всьому магазину</b> — увімкнути можна в Налаштуваннях.
      <?php endif; ?>
    </p>
    <label class="checkbox" data-help-title="Торг на цей товар"
           data-help="Галка стоїть — на сторінці товару є блок «Не влаштовує ціна? Запропонуйте свою».

Знімайте там, де торг недоречний: товар чужого бренду з фіксованою ціною, позиція з мінімальною маржею, дрібниця, заради якої не варто витрачати розмову.

Це не те саме, що «опт не діє»: опт роздає знижку за правилом і всім однаково, торг — жива розмова з однією людиною про одну партію. Один можна вимкнути, лишивши інший.

Погоджена в торзі ціна не складається ні з чим: ні акція, ні опт, ні набір, ні промокод на таку позицію більше не діють.">
      <input type="checkbox" name="bargain" <?= Offers::bargain($p) ? 'checked' : '' ?> <?= $roEdit ?>>
      Дозволити торг за цей товар</label>
  </div>
  <?php endif; ?>

  <?php if (!$isNew): ?>
  <?php if ($canEdit): ?>
  <div class="admin-card">
    <h2 class="h-serif">Характеристики</h2>
    <p class="dim" style="margin:-8px 0 14px">
      Обирайте зі спільного словника — так значення однакові в усіх товарів і працюють фільтри.
      Список залежить від категорії товару.
      <?php if (Auth::can('catalog.manage')): ?><a href="<?= e(url('/admin/attributes')) ?>">Керувати словником →</a><?php endif; ?>
    </p>
    <div id="attrRows" class="row-list"></div>
    <button class="btn btn-line btn-sm" type="button" id="attrAdd" style="margin-top:12px">+ Додати характеристику</button>
  </div>

  <div class="admin-card">
    <h2 class="h-serif">Варіанти</h2>
    <p class="dim" style="margin:-8px 0 14px">Різні виконання того самого товару: розмір, колір, обʼєм. Покупець обирає їх на сторінці товару.</p>

    <div class="row-list" id="variantRows">
      <?php foreach ($variants as $v): $vid = (int)$v['id']; $opts = $variant_options[$vid] ?? []; ?>
        <div class="grid-row variant-row" data-vid="<?= $vid ?>">
          <div class="gr-main">
            <?php if ($opts): ?>
              <div class="variant-tags">
                <?php foreach ($opts as $o): ?>
                  <span class="tag" title="<?= e($o['attr_name']) ?>">
                    <?php if (!empty($o['color'])): ?><i class="swatch" style="background:<?= e($o['color']) ?>"></i><?php endif; ?>
                    <?= e($o['attr_name']) ?>: <b><?= e($o['value']) ?></b>
                  </span>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <input type="text" name="variant[<?= $vid ?>][name]" value="<?= e($v['name']) ?>" placeholder="Назва варіанта">
            <?php endif; ?>
          </div>
          <input type="number" step="0.01" name="variant[<?= $vid ?>][price]" value="<?= e(num_val($v['price'])) ?>" placeholder="ціна" title="Порожньо = базова ціна товару">
          <input type="text" name="variant[<?= $vid ?>][sku]" value="<?= e($v['sku'] ?? '') ?>" placeholder="артикул">
          <?php /* Штрихкод належить фасовці: етикетку клеять на банку, а не на «мед узагалі» */ ?>
          <input type="text" name="variant[<?= $vid ?>][barcode]" value="<?= e($v['barcode'] ?? '') ?>"
                 placeholder="штрихкод" inputmode="numeric" autocomplete="off">
          <?php /* Вага теж належить фасовці — за нею рахується накладна */ ?>
          <input type="text" name="variant[<?= $vid ?>][weight]" value="<?= e(num_val($v['weight'] ?? null)) ?>"
                 placeholder="вага, кг" title="Вага однієї штуки — для розрахунку доставки">
          <?php /* Своя межа знижки: дрібна фасовка витримує менший відсоток, ніж ящик */ ?>
          <input type="text" name="variant[<?= $vid ?>][max_discount]" value="<?= e(num_val($v['max_discount'] ?? null)) ?>"
                 placeholder="макс. знижка, %" title="Найбільша сумарна знижка на цей варіант. Порожньо — межа товару">
          <label class="checkbox" title="Показувати покупцям"><input type="checkbox" name="variant[<?= $vid ?>][active]" <?= $v['active'] ? 'checked' : '' ?>> вкл.</label>
          <button class="btn btn-danger btn-xs row-del" type="button" title="Видалити варіант">✕</button>
          <input type="hidden" name="variant[<?= $vid ?>][_delete]" value="" disabled>
        </div>
      <?php endforeach; ?>
    </div>
    <button class="btn btn-line btn-sm" type="button" id="variantAdd" style="margin-top:12px">+ Додати варіант вручну</button>

    <div class="gen-box" id="genBox" style="margin-top:22px">
      <b>Згенерувати варіанти з характеристик</b>
      <p class="dim" style="margin:6px 0 12px">Відмітьте значення — створяться всі їхні комбінації (напр. 3 розміри × 2 кольори = 6 варіантів). Наявні комбінації не дублюються.</p>
      <div id="genAxes"></div>
      <button class="btn btn-gold btn-sm" type="submit" name="_action" value="gen_variants" style="margin-top:12px">⚙ Створити комбінації</button>
    </div>
  </div>
  <?php endif; ?>

  <?php $activeVariants = array_values(array_filter($variants, fn($v) => (int)$v['active'] === 1)); ?>
  <div class="admin-card">
    <h2 class="h-serif" data-help-title="Ціни та залишки по магазинах"
        data-help="Таблиця, де для кожної точки задають свою ціну й свою кількість.

Ціна: порожньо — діє базова ціна товару. Заповнили — у цьому магазині діятиме саме вона. Так роблять, коли в різних містах різна собівартість.

Залишок: скільки штук фізично лежить у цій точці. Від нього залежить, який магазин отримає замовлення: система віддає його туди, де вистачає на всю кількість.

Чесні цифри тут важливіші, ніж здається: замовити понад залишок сайт дозволяє, і тоді розбиратися доведеться вам уже після покупки.">
      Ціни та залишки по магазинах</h2>
    <p class="dim" style="margin:-8px 0 14px">
      Порожня ціна = діє базова. Порожній залишок = немає в наявності (товар усе одно можна замовити, якщо ввімкнено «під замовлення»).
      <?php if ($activeVariants): ?><br>У товару є варіанти, тому наявність рахується <b>по варіантах у кожному магазині</b> — залишок «без варіанта» не враховується.<?php endif; ?>
    </p>
    <div style="overflow-x:auto">
      <table class="tbl matrix">
        <tr>
          <th data-help-title="Колонка «Магазин»"
              data-help="Точки вашої мережі. Кожен рядок — окремий склад зі своєю ціною й кількістю.

Якщо ви продавець, редагувати можна лише рядки своїх точок — чужі показані, але заблоковані.

Список береться з розділу «Магазини». Вимкнені там точки сюди не потрапляють.">Магазин</th>
          <th colspan="2" data-help-title="Товар без варіанта"
              data-help="Ціна й залишок для товару, у якого немає варіантів.

Якщо у товару є хоч один увімкнений варіант, ці два стовпці перестають враховуватись: наявність тоді рахується лише по варіантах. Цифри звідси не зникнуть, але на сайт не вплинуть.

Тобто заповнюйте цю пару тільки для простих товарів — тих, що продаються в одному виконанні.">Товар без варіанта</th>
          <?php foreach ($variants as $v): ?><th colspan="2"
              data-help-title="Варіант «<?= e($v['name']) ?>»"
              data-help="Ціна й залишок саме цього виконання товару в кожному магазині.

Позначка «(вимк.)» означає, що варіант вимкнений: покупець його не бачить і його залишок не враховується в наявності.

Кожен варіант рахується окремо: у точці може бути 10 банок 0.5 л і жодної 1 л."><?= e($v['name']) ?><?= (int)$v['active'] === 1 ? '' : ' (вимк.)' ?></th><?php endforeach; ?>
        </tr>
        <tr class="sub"><th></th>
          <th data-help-title="Ціна в магазині"
              data-help="Ціна саме в цій точці. Порожньо — діє базова ціна товару.

Заповнена тут ціна має пріоритет над базовою й над ціною варіанта. Акція накладається вже на результат: магазинна ціна 200 грн з акцією 15% дасть 170 грн.">ціна</th>
          <th data-help-title="Залишок, шт"
              data-help="Скільки штук зараз фізично в цій точці.

Від цієї цифри залежить розподіл замовлень: система шукає магазин, де вистачає на всю кількість, і лише потім — де є хоч щось.

Порожньо дорівнює нулю. Залишок зменшується сам, коли товар замовляють, і повертається, якщо позицію передали іншій точці.">шт</th>
          <?php foreach ($variants as $v): ?><th data-help-title="Ціна варіанта в магазині"
              data-help="Ціна цього варіанта саме в цій точці. Порожньо — діє ціна варіанта, а якщо й вона порожня, то базова ціна товару.">ціна</th><th
              data-help-title="Залишок варіанта, шт"
              data-help="Скільки штук цього варіанта зараз у цій точці. Саме ці числа складаються в наявність товару, коли у нього є варіанти.">шт</th><?php endforeach; ?>
        </tr>
        <?php foreach ($stores as $s): $sid = (int)$s['id']; $ro = $canStore($sid) ? '' : 'disabled title="Немає доступу до цього магазину"'; ?>
          <tr>
            <td><?= e($s['name']) ?><?= $s['city'] ? ' · ' . e($s['city']) : '' ?></td>
            <td><input type="number" step="0.01" name="store_price[<?= $sid ?>]" value="<?= e(num_val($store_prices[$sid] ?? '')) ?>" placeholder="базова" <?= $ro ?>></td>
            <td>
              <?php if ($activeVariants): ?>
                <?php $sum = 0; foreach ($activeVariants as $v) $sum += (int)($variant_stock[(int)$v['id']][$sid] ?? 0); ?>
                <span class="dim" title="Сума по варіантах цього магазину — редагуйте в колонках варіантів">Σ <?= $sum ?></span>
              <?php else: ?>
                <input type="number" name="store_stock[<?= $sid ?>]" value="<?= e($store_stock[$sid] ?? '') ?>" <?= $ro ?>>
              <?php endif; ?>
            </td>
            <?php foreach ($variants as $v): $vid = (int)$v['id']; ?>
              <td><input type="number" step="0.01" name="vprice[<?= $vid ?>][<?= $sid ?>]" value="<?= e(num_val($variant_prices[$vid][$sid] ?? '')) ?>" placeholder="базова" <?= $ro ?>></td>
              <td><input type="number" name="vstock[<?= $vid ?>][<?= $sid ?>]" value="<?= e($variant_stock[$vid][$sid] ?? '') ?>" <?= $ro ?>></td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>
  </div>
  <?php else: ?>
    <p class="dim">Характеристики, варіанти та ціни по магазинах зʼявляться одразу після створення товару.</p>
  <?php endif; ?>

  <div class="admin-save">
    <button class="btn btn-gold" type="submit">💾 Зберегти</button>
    <span class="admin-save-note"></span>
    <?php if (!$isNew && Auth::can('products.manage')): ?>
      <button class="btn btn-danger" type="submit" name="_action" value="delete" style="margin-left:auto"
        onclick="return confirm('Видалити товар разом із фото та цінами?')">Видалити товар</button>
    <?php endif; ?>
  </div>
</form>

<?php if (!$isNew && $canEdit): ?>
<div class="admin-card" id="photos" style="margin-top:22px;scroll-margin-top:16px">
  <h2 class="h-serif">Фотографії</h2>
  <p class="dim" style="margin:0 0 14px">Перше спільне фото — головне: воно у каталозі, кошику та при поширенні в соцмережах.
    Решта показуються мініатюрами на сторінці товару в цьому ж порядку.</p>
  <?php if ($variants): ?>
    <p class="dim" style="margin:-8px 0 14px">Кадр можна закріпити за варіантом — тоді на сторінці його побачить лише той,
      хто обрав цей варіант, а спільні фото покажуться після нього. Головним закріплене фото не буває:
      у каталозі товар представляє себе цілком.</p>
  <?php endif; ?>
  <div class="img-grid">
    <?php
    $last = count($images) - 1;
    foreach ($images as $i => $img):
      $imgVid = (int)($img['variant_id'] ?? 0);
      $isMain = $img['path'] === ($p['image'] ?? '');
    ?>
      <div class="img-cell<?= $isMain ? ' is-main' : '' ?>">
        <img src="<?= e(asset(Images::displayThumb($img['path']))) ?>" alt="">
        <?php if ($isMain): ?><span class="img-badge">Головне</span><?php endif; ?>
        <form method="post" action="<?= e(url('/admin/products/' . $p['id'])) ?>" class="img-del"><?= Csrf::field() ?>
          <input type="hidden" name="_action" value="delete_image">
          <input type="hidden" name="image_id" value="<?= (int)$img['id'] ?>">
          <button class="btn btn-danger btn-xs" style="padding:3px 8px" title="Прибрати з товару" onclick="return confirm('Прибрати фото з товару?')">✕</button>
        </form>
        <div class="img-actions">
          <form method="post" action="<?= e(url('/admin/products/' . $p['id'])) ?>"><?= Csrf::field() ?>
            <input type="hidden" name="_action" value="move_image">
            <input type="hidden" name="image_id" value="<?= (int)$img['id'] ?>">
            <button class="btn btn-line btn-xs" name="dir" value="up" title="Раніше" <?= $i === 0 ? 'disabled' : '' ?>>←</button>
            <button class="btn btn-line btn-xs" name="dir" value="down" title="Пізніше" <?= $i === $last ? 'disabled' : '' ?>>→</button>
          </form>
          <?php /* Закріплений кадр головним не робимо: у каталозі він обіцяв би
                   один варіант замість товару. Спершу зніміть мітку. */ ?>
          <?php if (!$isMain && !$imgVid): ?>
            <form method="post" action="<?= e(url('/admin/products/' . $p['id'])) ?>"><?= Csrf::field() ?>
              <input type="hidden" name="_action" value="main_image">
              <input type="hidden" name="image_id" value="<?= (int)$img['id'] ?>">
              <button class="btn btn-line btn-xs" title="Зробити головним">★ Головне</button>
            </form>
          <?php endif; ?>
        </div>
        <?php if ($variants): ?>
          <form method="post" action="<?= e(url('/admin/products/' . $p['id'])) ?>" style="margin-top:6px"><?= Csrf::field() ?>
            <input type="hidden" name="_action" value="image_variant">
            <input type="hidden" name="image_id" value="<?= (int)$img['id'] ?>">
            <select name="variant_id" onchange="this.form.submit()" style="width:100%"
                    title="Кому належить кадр: усім варіантам чи одному">
              <option value="0">спільне фото</option>
              <?php foreach ($variants as $v): ?>
                <option value="<?= (int)$v['id'] ?>"<?= $imgVid === (int)$v['id'] ? ' selected' : '' ?>>
                  <?= e($v['name']) ?><?= (int)$v['active'] === 1 ? '' : ' (вимкнений)' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </form>
        <?php endif; ?>
        <span class="dim"><?= (int)$img['width'] ?>×<?= (int)$img['height'] ?> · <?= round($img['bytes'] / 1024) ?> КБ</span>
      </div>
    <?php endforeach; ?>
  </div>
  <div style="margin-top:18px;display:flex;gap:12px;align-items:center;flex-wrap:wrap">
    <button class="btn btn-gold btn-sm" type="button" onclick="MediaPicker.open(function(path){
      var f = document.getElementById('attachImageForm');
      f.querySelector('[name=media_path]').value = path; f.submit();
    })">📷 Додати фото (з сайту або з ПК)</button>
    <span class="dim">Фото автоматично стискається і адаптується; розмір показано під мініатюрою</span>
  </div>
  <form method="post" action="<?= e(url('/admin/products/' . $p['id'])) ?>" id="attachImageForm" style="display:none">
    <?= Csrf::field() ?>
    <input type="hidden" name="_action" value="attach_image">
    <input type="hidden" name="media_path" value="">
  </form>
</div>
<?= View::partial('partials/media_picker') ?>

<script>
window.BOFU_DICT = <?= json_js($dict) ?>;
window.BOFU_PRODUCT_ATTRS = <?= json_js(array_map(fn($a) => [
    'attribute_id' => (int)$a['attribute_id'],
    'value_id' => (int)$a['value_id'],
    'value' => $a['value'],
], array_values(array_filter($attrs, fn($a) => !empty($a['attribute_id']))))) ?>;
</script>
<script src="<?= e(asset_v('js/product-form.js')) ?>" defer></script>
<?php endif; ?>
