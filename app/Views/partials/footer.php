<?php
/**
 * Підвал. Спокійний і повний: розділи, умови, контакти, реквізити.
 * Порожні контакти не показуються — вигаданого номера в підвалі не буде.
 */
$phone1 = Content::title('contact_phone');
$phone2 = Content::title('contact_phone2');
$email = Content::title('contact_email');
$hours = Content::title('contact_hours');
$address = Content::title('contact_address');
$entity = Content::title('legal_entity');
$footCats = Catalog::rootCategories();
$socials = array_filter([
    'Instagram' => Content::title('social_instagram'),
    'YouTube' => Content::title('social_youtube'),
    'TikTok' => Content::title('social_tiktok'),
], static fn($u) => $u !== '' && $u !== '#');
$tel = static fn(string $p): string => preg_replace('~[^\d+]~', '', $p);
?>
<footer class="sv-footer" id="footer">
  <div class="container">
    <div class="sv-footer-grid">
      <div class="sv-footer-about">
        <a class="sv-brand sv-brand-light" href="<?= e(url('/')) ?>" aria-label="<?= e(cfg('app_name')) ?> — на головну">
          <span class="sv-brand-chip"><img src="<?= e(asset('img/logo-mark.webp')) ?>" width="44" height="36" alt="" loading="lazy"></span>
          <span class="sv-brand-text"><span class="sv-brand-name">Старвуд-М</span><span class="sv-brand-sub">світ іграшок</span></span>
        </a>
        <p>Іграшки для дітей від народження до 7 років. Щаслива дитина — щаслива сімʼя.</p>
        <?php if ($socials): ?>
          <p class="sv-footer-social"><?php foreach ($socials as $name => $u): ?><a href="<?= e(safe_url($u)) ?>" target="_blank" rel="noopener"><?= e($name) ?></a><?php endforeach; ?></p>
        <?php endif; ?>
      </div>
      <nav aria-label="Каталог">
        <span class="sv-footer-h">Каталог</span>
        <?php foreach ($footCats as $c): ?><a href="<?= e(shop_url($c['slug'])) ?>"><?= e($c['name']) ?></a><?php endforeach; ?>
      </nav>
      <nav aria-label="Покупцям">
        <span class="sv-footer-h">Покупцям</span>
        <a href="<?= e(url('/delivery')) ?>">Доставка</a>
        <a href="<?= e(url('/payment')) ?>">Оплата</a>
        <a href="<?= e(url('/returns')) ?>">Обмін і повернення</a>
        <a href="<?= e(url('/testimonials')) ?>">Відгуки</a>
        <a href="<?= e(url('/about')) ?>">Про магазин</a>
        <a href="<?= e(url('/contacts')) ?>">Контакти</a>
      </nav>
      <div>
        <span class="sv-footer-h">Звʼязатися</span>
        <?php if ($phone1 !== ''): ?><a class="sv-footer-phone" href="tel:<?= e($tel($phone1)) ?>"<?= edit_mark('contact_phone', 'title') ?>><?= e($phone1) ?></a><?php endif; ?>
        <?php if ($phone2 !== ''): ?><a class="sv-footer-phone" href="tel:<?= e($tel($phone2)) ?>"<?= edit_mark('contact_phone2', 'title') ?>><?= e($phone2) ?></a><?php endif; ?>
        <?php if ($email !== ''): ?><a href="mailto:<?= e($email) ?>"<?= edit_mark('contact_email', 'title') ?>><?= e($email) ?></a><?php endif; ?>
        <?php if ($address !== ''): ?><span<?= edit_mark('contact_address', 'title') ?>><?= e($address) ?></span><?php endif; ?>
        <?php if ($hours !== ''): ?><span class="sv-footer-dim"<?= edit_mark('contact_hours', 'title') ?>><?= e($hours) ?></span><?php endif; ?>
      </div>
    </div>
    <p class="sv-footer-copy">
      <span>© <?= e($entity !== '' ? $entity : cfg('app_name')) ?>, 2018–<?= date('Y') ?></span>
      <span><a href="<?= e(url('/offer')) ?>">Публічна оферта</a> · <a href="<?= e(url('/privacy')) ?>">Політика конфіденційності</a></span>
    </p>
  </div>
</footer>
