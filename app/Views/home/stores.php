<?php
/**
 * Контакти: два відділи продажу, пошта, адреса й графік.
 *
 * Карти всередині сторінки немає навмисно (див. Geo та Security::csp):
 * кнопка «Прокласти маршрут» відкриває рідну карту телефона, а адреса лишається
 * текстом, який можна переслати в месенджер.
 *
 * @var array $stores
 */
$phones = array_filter([Content::title('contact_phone'), Content::title('contact_phone2')]);
$email = Content::title('contact_email');
$hours = Content::title('contact_hours');
$tel = static fn(string $p): string => preg_replace('~[^\d+]~', '', $p);
?>
<div class="container sv-page">
  <nav class="sv-crumbs" aria-label="Хлібні крихти">
    <a href="<?= e(url('/')) ?>">Головна</a><span>/</span><span aria-current="page">Контакти</span>
  </nav>
  <div class="sv-legal-head">
    <h1 class="sv-h2" style="font-size:clamp(32px,4vw,52px)">Контакти</h1>
  </div>

  <div class="sv-contacts">
    <?php foreach (array_values($phones) as $i => $ph): ?>
      <a class="sv-contact-card" href="tel:<?= e($tel($ph)) ?>" style="--c:<?= $i ? '#8ED9B5' : '#FFD35C' ?>">
        <span class="sv-contact-k"><?= $i + 1 ?>-й відділ продажу</span>
        <b><?= e($ph) ?></b>
        <span class="dim">Дзвінки, Viber, Telegram</span>
      </a>
    <?php endforeach; ?>
    <?php if ($email !== ''): ?>
      <a class="sv-contact-card" href="mailto:<?= e($email) ?>" style="--c:#9CC8FF">
        <span class="sv-contact-k">Пошта</span><b><?= e($email) ?></b><span class="dim">Відповідаємо протягом дня</span>
      </a>
    <?php endif; ?>
  </div>

  <?php foreach ($stores as $s): $route = Geo::routeUrl($s);
    $where = trim(implode(', ', array_filter([$s['city'] ?? '', $s['address'] ?? ''])));
    if ($route === '' && $where !== '') $route = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($where); ?>
    <div class="sv-panel sv-store">
      <div>
        <h2 style="margin:0 0 6px;font-size:24px"><?= e($s['name']) ?></h2>
        <?php if ($where !== ''): ?><p style="margin:0;font-size:18px;font-weight:700"><?= e($where) ?></p><?php endif; ?>
        <?php if (!empty($s['hours']) || $hours !== ''): ?><p class="dim" style="margin:6px 0 0"><?= e($s['hours'] ?: $hours) ?></p><?php endif; ?>
      </div>
      <?php if ($route !== ''): ?>
        <a class="sv-btn sv-btn-paper sv-btn-sm" href="<?= e($route) ?>" target="_blank" rel="noopener">Прокласти маршрут →</a>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>
