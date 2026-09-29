<?php
/*
 * Шапка вітрини «Старвуд-М».
 *
 * Три поверхи, кожен відповідає на своє питання:
 *   1) тонка смужка — «як швидко привезуть і куди дзвонити»;
 *   2) біла шапка — логотип, пошук, кабінет і кошик;
 *   3) бірюзова смуга розділів — усі розділи одразу, без розкривання, як у
 *      бічному меню магазину на Prom: покупці звикли бачити їх відразу.
 * Вік дитини — окремий пункт першим у смузі: батьки шукають «для дворічки»
 * частіше, ніж «ляльки».
 *
 * .cart-link і .cart-badge потрібні app.js — він оновлює лічильник без
 * перезавантаження сторінки.
 */
$navCats = Catalog::categoryTree();
$phone1 = Content::title('contact_phone');
$phone2 = Content::title('contact_phone2');
$tel = static fn(string $p): string => preg_replace('~[^\d+]~', '', $p);
$path = rtrim(request_path(), '/') ?: '/';
$curCat = null;
if (preg_match('~^/shop/([a-z0-9-]+)~', $path, $m)) $curCat = $m[1];
// Короткі назви для смуги: повна «Машинки та іграшкова зброя» не вміщує вісім розділів в один рядок
$short = [
    'lialky-ta-pupsy' => 'Ляльки й пупси', 'nastilni-ihry-ta-pazly' => 'Ігри та пазли',
    'tvorchist-i-rozvytok' => 'Творчість', 'ihrovi-namety' => 'Намети',
    'mashynky-ta-zbroia' => 'Машинки', 'miaki-ihrashky' => 'Мʼякі іграшки',
];
?>
<div class="sv-topbar">
  <div class="container sv-topbar-in">
    <span>Доставка Новою Поштою 1–3 дні · безкоштовно від <?= e(price_fmt((float)Settings::get('free_shipping_from', '2500'))) ?></span>
    <span class="sv-topbar-phones">
      <?php if ($phone1 !== ''): ?><a href="tel:<?= e($tel($phone1)) ?>"><?= e($phone1) ?></a><?php endif; ?>
      <?php if ($phone2 !== ''): ?><a href="tel:<?= e($tel($phone2)) ?>"><?= e($phone2) ?></a><?php endif; ?>
    </span>
  </div>
</div>
<header class="topbar sv-header">
  <div class="container sv-head">
    <button class="mobile-menu-btn sv-burger" id="mobileBtn" type="button" aria-label="Меню" aria-controls="mobileMenu">
      <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
    </button>
    <a class="sv-brand" href="<?= e(url('/')) ?>" aria-label="<?= e(cfg('app_name')) ?> — на головну">
      <img src="<?= e(asset('img/logo-mark.webp')) ?>" width="52" height="42" alt="">
      <span class="sv-brand-text"><span class="sv-brand-name">Старвуд-М</span><span class="sv-brand-sub">світ іграшок</span></span>
    </a>

    <form class="nav-search sv-search" method="get" action="<?= e(url('/shop')) ?>" role="search">
      <label class="sr-only" for="navSearch">Пошук іграшок</label>
      <input type="search" id="navSearch" name="q" placeholder="Пошук: лялька, пазл, гойдалка…" value="<?= e(qs('q')) ?>" autocomplete="off">
      <button type="submit" aria-label="Знайти">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
      </button>
    </form>

    <div class="nav-side sv-side">
      <?php /* Пошук на телефоні — іконкою поруч із кошиком: сховати його в
               меню означало б, що людина, яка знає назву іграшки, мусить
               спершу здогадатись, де він. */ ?>
      <button type="button" class="sv-icon sv-search-btn" id="mobileSearchBtn" aria-label="Пошук">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
      </button>
      <?php if ($auth_user): ?>
        <?= View::partial('partials/role_switch') ?>
        <?php if (Auth::isStaff()): ?>
          <a class="sv-side-link" href="<?= e(url('/admin')) ?>"><?= Auth::isAdmin() ? 'Адміністрування' : 'Кабінет продавця' ?></a>
        <?php endif; ?>
        <?php if (($myOffers = Offers::myTurnCount(Auth::id())) > 0): ?>
          <a class="sv-side-link" href="<?= e(url('/bargain')) ?>">Пропозиції · <?= (int)$myOffers ?></a>
        <?php endif; ?>
        <a class="sv-icon" href="<?= e(url(Auth::isStaff() ? '/profile' : '/orders')) ?>" title="<?= e($auth_user['name']) ?>" aria-label="Мій кабінет">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6"/></svg>
          <span class="sv-icon-l">Кабінет</span>
        </a>
      <?php else: ?>
        <a class="sv-icon" href="#" id="loginBtn" aria-label="Увійти">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6"/></svg>
          <span class="sv-icon-l">Увійти</span>
        </a>
      <?php endif; ?>
      <a class="cart-link sv-icon sv-cart" href="<?= e(url('/cart')) ?>" aria-label="Кошик<?= $cart_count > 0 ? ': ' . (int)$cart_count . ' шт.' : '' ?>">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 8h14l-1.2 11.2a2 2 0 01-2 1.8H8.2a2 2 0 01-2-1.8z"/><path d="M9 8V6a3 3 0 016 0v2"/></svg>
        <span class="sv-icon-l">Кошик</span>
        <?php if ($cart_count > 0): ?><span class="cart-badge"><?= (int)$cart_count ?></span><?php endif; ?>
      </a>
    </div>
  </div>

  <nav class="sv-catbar" aria-label="Розділи магазину">
    <div class="container sv-catbar-in">
      <span class="nav-drop sv-agedrop" data-nav-drop>
        <a href="<?= e(url('/shop')) ?>#age">За віком</a>
        <button type="button" class="nav-drop-btn" data-nav-drop-btn aria-controls="navAgeMenu" aria-expanded="false" aria-label="Обрати вік дитини">
          <svg width="11" height="11" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M3 5l5 6 5-6"/></svg>
        </button>
        <div class="nav-drop-menu sv-age-menu" id="navAgeMenu" data-nav-drop-menu hidden>
          <?php foreach (Ages::BANDS as $k => [, , $label, $hint]): ?>
            <a href="<?= e(shop_url(null, ['age' => $k])) ?>"><span class="sv-age-dot sv-age-<?= e((string)$k) ?>"></span><b><?= e($label) ?></b><small><?= e($hint) ?></small></a>
          <?php endforeach; ?>
        </div>
      </span>
      <a href="<?= e(url('/shop')) ?>"<?= $path === '/shop' ? ' aria-current="page"' : '' ?>>Усі іграшки</a>
      <?php foreach ($navCats as $c): ?>
        <a href="<?= e(shop_url($c['slug'])) ?>"<?= $curCat === $c['slug'] ? ' aria-current="page"' : '' ?>><?= e($short[$c['slug']] ?? $c['name']) ?></a>
      <?php endforeach; ?>
    </div>
  </nav>

  <div class="mobile-menu sv-mobile" id="mobileMenu">
    <form class="nav-search sv-search" method="get" action="<?= e(url('/shop')) ?>" role="search">
      <label class="sr-only" for="navSearchMobile">Пошук іграшок</label>
      <input type="search" id="navSearchMobile" name="q" placeholder="Що шукаємо?" value="<?= e(qs('q')) ?>" autocomplete="off">
      <button type="submit" aria-label="Знайти"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg></button>
    </form>
    <p class="sv-mobile-h">За віком</p>
    <div class="sv-mobile-ages">
      <?php foreach (Ages::BANDS as $k => [, , $label]): ?>
        <a class="sv-age-<?= e((string)$k) ?>" href="<?= e(shop_url(null, ['age' => $k])) ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
    </div>
    <p class="sv-mobile-h">Розділи</p>
    <a href="<?= e(url('/shop')) ?>">Усі іграшки</a>
    <?php foreach ($navCats as $c): ?><a href="<?= e(shop_url($c['slug'])) ?>"><?= e($c['name']) ?></a><?php endforeach; ?>
    <p class="sv-mobile-h">Покупцям</p>
    <a href="<?= e(url('/delivery')) ?>">Доставка й оплата</a>
    <a href="<?= e(url('/testimonials')) ?>">Відгуки</a>
    <a href="<?= e(url('/about')) ?>">Про нас</a>
    <a href="<?= e(url('/contacts')) ?>">Контакти</a>
    <?php if ($auth_user): ?>
      <?php if (Auth::isStaff()): ?><a href="<?= e(url('/admin')) ?>">Адміністрування</a><?php endif; ?>
      <a href="<?= e(url('/orders')) ?>">Мої замовлення</a>
      <a href="<?= e(url('/profile')) ?>">Мій профіль</a>
      <form method="post" action="<?= e(url('/logout')) ?>"><?= Csrf::field() ?><button class="btn btn-line btn-sm" type="submit">Вийти</button></form>
    <?php endif; ?>
  </div>
</header>
