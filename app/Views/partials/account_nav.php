<?php
/**
 * Меню кабінету покупця: замовлення, профіль, вихід. Стоїть угорі кожної сторінки
 * кабінету — без нього людина, що зайшла, не бачила ні виходу, ні свого профілю.
 *
 * @var string $active 'orders' | 'profile'
 */
$active = $active ?? 'orders';
?>
<nav class="sv-acct-nav" aria-label="Кабінет">
  <a href="<?= e(url('/orders')) ?>"<?= $active === 'orders' ? ' class="is-on" aria-current="page"' : '' ?>>Мої замовлення</a>
  <a href="<?= e(url('/profile')) ?>"<?= $active === 'profile' ? ' class="is-on" aria-current="page"' : '' ?>>Профіль і налаштування</a>
  <form method="post" action="<?= e(url('/logout')) ?>"><?= Csrf::field() ?>
    <button type="submit">Вийти</button>
  </form>
</nav>
