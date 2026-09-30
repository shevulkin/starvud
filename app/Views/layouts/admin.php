<?php
/** @var string $content */
$cur = request_path();
/*
 * Черга торгу потрібна одразу двом місцям — пункту меню й нижній панелі, — а
 * запит під нею не безкоштовний: він ще й гасить протухлі розмови. Рахуємо
 * один раз тут і передаємо далі числом.
 */
$offers_todo = Offers::todoCount();
/*
 * Каса на телефоні вже займає низ екрана своїм чеком, і друга смужка під ним
 * лишила б від плитки товарів смужку. Та й ходити кудись посеред продажу саме
 * що не треба: з каси виходять через ☰, свідомо.
 */
$pos_screen = rtrim($cur, '/') === '/admin/orders/new';
?>
<!DOCTYPE html>
<html lang="uk">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($page_title ?? 'Адміністрування') ?></title>
<meta name="theme-color" content="#141110">
<?php /* Легкий значок вкладки; велика іконка лишається манифесту й apple-touch */ ?>
<link rel="icon" href="<?= e(asset('img/favicon.png')) ?>" sizes="64x64">
<link rel="manifest" href="<?= e(url('/manifest.webmanifest')) ?>">
<link rel="apple-touch-icon" href="<?= e(asset('img/avatar.png')) ?>">
<link rel="stylesheet" href="<?= e(asset_v('css/fonts.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_v('css/app.css')) ?>">
</head>
<body class="is-admin<?= $pos_screen ? ' pos-screen' : '' ?>">
<div class="admin-mobilebar">
  <button class="mobile-menu-btn" style="display:flex" onclick="document.querySelector('.admin-side').classList.toggle('open')">☰</button>
  <b><?= Auth::isAdmin() ? 'Адміністрування' : 'Кабінет продавця' ?></b>
  <?php if (Auth::actingAs() !== null): ?>
    <span class="role-tag"><?= e(Roles::label(Auth::actingAs())) ?></span>
  <?php endif; ?>
</div>
<div class="admin-wrap">
  <aside class="admin-side">
    <a class="brand" href="<?= e(url('/')) ?>">
      <img src="<?= e(asset('img/logo-mark.webp')) ?>" width="34" height="34" alt=""> <span class="brand-text" style="font-size:14px">Старвуд-М · <?= Auth::isAdmin() ? 'Адміністратор' : 'Продавець' ?></span>
    </a>
    <?php
    /**
     * Меню блоками: пункти згруповані за тим, чим людина зайнята, а не звалені
     * в один список з пʼятнадцяти рядків, де очима шукаєш потрібне.
     *
     * Заголовок групи ніколи не показується сам по собі: продавець бачить лише
     * частину пунктів, і порожня «Система» була б обіцянкою розділу, якого для
     * нього не існує. Тому спершу фільтруємо права, і вже потім вирішуємо,
     * чи є що озаглавлювати.
     */
    $groups = [
        ['', [
            ['/admin', 'Панель', true],
        ]],
        ['Продажі', [
            ['/admin/orders', 'Замовлення', true],
            // Число в пункті — єдине місце, де черга торгу нагадує про себе
            // сама. Розмова, помічена через тиждень, дорівнює відмові:
            // покупець на той час уже купив деінде.
            // Число тут — єдине, що нагадує про чергу саме. Замовлення, помічене
            // через день, це день, який покупець прочекав дарма.
            ['/admin/dispatch', 'Звідки відправити' . (($d = Dispatch::todoCount()) > 0 ? ' · ' . $d : ''),
                Auth::can('orders.manage')],
            ['/admin/offers', 'Торг' . ($offers_todo > 0 ? ' · ' . $offers_todo : ''),
                Auth::can('offers.manage') && Offers::enabled()],
            ['/admin/orders/new', Pos::active() ? '🛒 Каса · продаж триває' : 'Каса (новий продаж)', Auth::can('orders.create')],
            // Пункт зʼявляється лише там, де налаштована каса: без токена він
            // веде на екран, який уміє тільки сказати «токена немає»
            ['/admin/vchasno', 'Каса (ПРРО)', Auth::can('fiscal.manage') && (FiscalProvider::anyConfigured() || FiscalProvider::current() === 'dps')],
            ['/admin/promos', 'Акції та промокоди', Auth::can('promos.manage')],
            ['/admin/bundles', 'Набори (разом дешевше)', Auth::can('promos.manage')],
            ['/admin/wholesale', 'Оптові знижки', Auth::can('promos.manage')],
            ['/admin/subscribers', 'Розсилка', Auth::can('subscribers.manage')],
        ]],
        ['Каталог', [
            ['/admin/products', 'Товари', true],
            ['/admin/products/bulk', 'Масове редагування', true],
            ['/admin/products/codes', 'Коди й штрихкоди', Auth::can('products.manage')],
            ['/admin/stock-requests', 'Очікують товар', true],
            ['/admin/categories', 'Категорії', Auth::can('catalog.manage')],
            ['/admin/attributes', 'Характеристики', Auth::can('catalog.manage')],
            ['/admin/brands', 'Бренди', Auth::can('catalog.manage')],
        ]],
        ['Мережа', [
            ['/admin/stores', 'Магазини', Auth::can('stores.manage')],
            ['/admin/owners', 'Власники (ФОПи)', Auth::can('stores.manage')],
            ['/admin/users', 'Користувачі', Auth::can('users.manage')],
        ]],
        ['Сайт', [
            ['/admin/content', 'Тексти й фото сайту', Auth::can('content.manage')],
            ['/admin/reviews', 'Відгуки' . (($rv = Reviews::pendingCount()) > 0 ? ' · ' . $rv : ''),
                Auth::can('content.manage')],
            ['/admin/media', 'Фото й файли', Auth::can('media.manage')],
        ]],
        ['Система', [
            ['/admin/notifications', 'Сповіщення', Auth::can('notifications.manage')],
            ['/admin/settings', 'Налаштування', Auth::can('settings.manage')],
        ]],
    ];
    foreach ($groups as [$title, $items]):
        $items = array_values(array_filter($items, fn($i) => $i[2]));
        if (!$items) continue; ?>
      <?php if ($title !== ''): ?><div class="nav-group"><?= e($title) ?></div><?php endif; ?>
      <?php foreach ($items as [$href, $label, ]): ?>
        <a href="<?= e(url($href)) ?>" class="<?= $cur === $href ? 'active' : '' ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
    <?php endforeach; ?>
    <div class="sep"></div>
    <a href="<?= e(url('/')) ?>">← На сайт</a>
    <div style="padding:8px 22px"><?= View::partial('partials/work_store') ?></div>
    <div style="padding:8px 22px"><?= View::partial('partials/role_switch') ?></div>
    <form method="post" action="<?= e(url('/logout')) ?>" style="padding:8px 22px"><?= Csrf::field() ?>
      <button class="btn btn-line btn-xs" type="submit" style="width:100%">Вийти (<?= e($auth_user['name'] ?? '') ?>)</button>
    </form>
    <div style="padding:12px 22px">
      <button class="btn btn-line btn-xs" style="width:100%;display:none" id="pwaInstall">📱 Встановити додаток</button>
      <button class="btn btn-line btn-xs" style="width:100%;margin-top:8px;display:none" id="pushEnable">🔔 Увімкнути пуші</button>
    </div>
  </aside>
  <main class="admin-main">
    <?php /* банер веде в Налаштування — показуємо лише тим, хто може туди зайти */ ?>
    <?php if (Settings::bool('seo_noindex') && Auth::can('settings.manage')): ?>
      <div class="flash" style="padding:0;margin:0 0 16px"><div class="flash-error">
        🔒 Сайт закрито від пошукових систем. Перед запуском вимкніть це в
        <a href="<?= e(url('/admin/settings')) ?>" style="color:inherit;text-decoration:underline">Налаштуваннях</a>.
      </div></div>
    <?php endif; ?>
    <?php /* Банер обіцяє знижку, якої немає. Показуємо на кожній сторінці, а не лише
             в «Акціях»: інакше про обман дізнається той, хто вже й так туди зайшов
             його виправляти. Тільки тим, хто має право це змінити. */ ?>
    <?php if (Auth::can('promos.manage') && ($banner_warn = Catalog::bannerWarning())): ?>
      <div class="flash" style="padding:0;margin:0 0 16px"><div class="flash-error">
        ⚠️ <?= e($banner_warn) ?>
        <a href="<?= e(url('/admin/promos')) ?>" style="color:inherit;text-decoration:underline">Виправити</a>.
      </div></div>
    <?php endif; ?>
    <?php if ($msg = flash('success')): ?><div class="flash" style="padding:0;margin:0 0 16px"><div class="flash-success"><?= e($msg) ?></div></div><?php endif; ?>
    <?php if ($msg = flash('error')): ?><div class="flash" style="padding:0;margin:0 0 16px"><div class="flash-error"><?= e($msg) ?></div></div><?php endif; ?>
    <?php
    /**
     * Кнопка підказок — одна на всю адмінку, а не в кожному шаблоні.
     * Прихована доти, доки скрипт не побачить на сторінці бодай одне поле з
     * data-help: кнопка, яка нічого не пояснює, гірша за її відсутність.
     * Скрипт же переносить її у .admin-head поруч із заголовком сторінки.
     */
    ?>
    <button type="button" class="help-btn" data-help-toggle aria-pressed="false" hidden
            title="Підказки: увімкніть і клацніть на будь-яке поле" aria-label="Показати підказки">?</button>
    <p class="help-bar">
      Клацніть на поле, колонку чи кнопку — пояснимо, що воно робить.
      Поки що нічого не зберігається й не видаляється.
      <b>Щоб вийти — натисніть жовту «?» вгорі або клавішу Esc.</b>
    </p>
    <?= $content ?>
  </main>
</div>
<script>
window.BOFU = { base: '<?= e(url('/')) ?>', vapid: '<?= e(Settings::get('vapid_public', '')) ?>', csrf: '<?= e(Csrf::token()) ?>' };
</script>
<script src="<?= e(asset_v('js/admin.js')) ?>" defer></script>
<?php /* На самій касі смужка зайва — чек там і так перед очима */ ?>
<?php if (Pos::active() && !$pos_screen) echo View::partial('partials/pos_bar'); ?>
<?php /* Нижня панель — лише на телефоні (сховану ширшим за 900px робить CSS).
         Стоїть після смужки продажу навмисно: коли обидві на екрані, смужка
         має лежати НАД панеллю, і природний порядок у потоці це і дає. */ ?>
<?php if (!$pos_screen) echo View::partial('partials/admin_nav', ['offers_todo' => $offers_todo]); ?>
</body>
</html>
