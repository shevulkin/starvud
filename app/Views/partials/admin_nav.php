<?php
/**
 * Нижня панель — головна навігація стафу на телефоні.
 *
 * Шухляда за ☰ коштує два дотики й висить у верхньому лівому куті, куди великий
 * палець не дістає взагалі. А продавець цілий день ходить між чотирма місцями:
 * замовлення, каса, торг, товари. Тож вони внизу, під пальцем, по одному
 * дотику; решта двох десятків пунктів лишається за «Ще» — тією ж шухлядою.
 *
 * Пунктів навмисно не більше пʼяти: шосте на 360px влазить, лише якщо зменшити
 * підпис до нечитаного, а панель без підписів — ребус із піктограм.
 *
 * Панель дублює частину бічного меню, а не замінює його: на планшеті й
 * ноутбуці меню лишається єдиною навігацією, панель туди не показується.
 *
 * @var int $offers_todo Скільки розмов торгу чекають відповіді — рахує layout,
 *                       щоб не питати базу вдруге тим самим запитом.
 */
$cur = rtrim(request_path(), '/');
if ($cur === '') $cur = '/';

/*
 * `on` — окремим виразом, а не порівнянням з $cur, бо адреси вкладені одна в
 * одну: /admin/orders/new — це каса, а не «замовлення», і підсвічувати під час
 * продажу обидва пункти означало б брехати про те, де ти зараз.
 */
$items = [
    [
        'show'  => true,
        'href'  => '/admin/orders',
        'label' => 'Замовлення',
        'on'    => str_starts_with($cur, '/admin/orders') && $cur !== '/admin/orders/new',
        'icon'  => '<path d="M5 3h9l5 5v13H5z"/><path d="M14 3v5h5"/><path d="M9 13h6M9 17h4"/>',
    ],
    [
        'show'  => Auth::can('orders.create'),
        'href'  => '/admin/orders/new',
        'label' => 'Каса',
        'on'    => $cur === '/admin/orders/new',
        'icon'  => '<circle cx="9" cy="20" r="1.4"/><circle cx="18" cy="20" r="1.4"/>'
                 . '<path d="M2.5 3.5h2.6l2.4 11.1a1 1 0 0 0 1 .8h9a1 1 0 0 0 1-.8L20.5 7H6"/>',
        // Набраний, але не проведений чек — те, про що забувають і потім
        // продають наступному покупцеві разом зі своїм. Крапка світиться доти,
        // доки продаж не закрито.
        'dot'   => Pos::active(),
    ],
    [
        'show'  => Auth::can('offers.manage') && Offers::enabled(),
        'href'  => '/admin/offers',
        'label' => 'Торг',
        'on'    => str_starts_with($cur, '/admin/offers'),
        'icon'  => '<path d="M21 11.5a8 8 0 0 1-11.7 7.1L4 20.5l1.9-5.2A8 8 0 1 1 21 11.5z"/>',
        // Розмова, помічена через тиждень, дорівнює відмові: покупець на той
        // час уже купив деінде. Тому число видно з будь-якого екрана.
        'badge' => $offers_todo ?? 0,
    ],
    [
        'show'  => true,
        'href'  => '/admin/products',
        'label' => 'Товари',
        'on'    => str_starts_with($cur, '/admin/products'),
        'icon'  => '<path d="M21 8.2v7.6L12 21l-9-5.2V8.2L12 3z"/><path d="M3 8.2 12 13.4l9-5.2"/><path d="M12 13.4V21"/>',
    ],
];
$items = array_values(array_filter($items, fn($i) => $i['show']));
// Місце під «Ще» тримаємо завжди: без нього з панелі не потрапити ані в
// налаштування, ані на сайт, ані вийти з акаунта.
$items = array_slice($items, 0, 4);
?>
<nav class="adm-nav" aria-label="Основні розділи">
  <?php foreach ($items as $i): ?>
    <a class="adm-nav-a<?= $i['on'] ? ' is-on' : '' ?>" href="<?= e(url($i['href'])) ?>"
       <?= $i['on'] ? ' aria-current="page"' : '' ?>>
      <span class="adm-nav-ico">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"
             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $i['icon'] ?></svg>
        <?php if (!empty($i['badge'])): ?>
          <span class="adm-nav-badge"><?= (int)$i['badge'] > 99 ? '99+' : (int)$i['badge'] ?></span>
        <?php elseif (!empty($i['dot'])): ?>
          <span class="adm-nav-dot" aria-hidden="true"></span>
        <?php endif; ?>
      </span>
      <span class="adm-nav-t"><?= e($i['label']) ?></span>
    </a>
  <?php endforeach; ?>
  <?php /* «Ще» відкриває ту саму шухляду, що й ☰ угорі: два входи в одне меню
           кращі за один вхід, до якого не дотягнутись. */ ?>
  <button type="button" class="adm-nav-a" aria-expanded="false"
          onclick="var s=document.querySelector('.admin-side');var o=s.classList.toggle('open');this.setAttribute('aria-expanded',o)">
    <span class="adm-nav-ico">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
           stroke-linecap="round" aria-hidden="true">
        <path d="M4 7h16M4 12h16M4 17h16"/></svg>
    </span>
    <span class="adm-nav-t">Ще</span>
  </button>
</nav>
