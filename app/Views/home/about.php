<?php
/** «Про нас». @var array $gallery, $faq, $review_stats; int $products_count */
$text = Content::get('about_full');
$img = Content::image('about_full');
$faq = $faq ?? [];
?>
<div class="container sv-page">
  <nav class="sv-crumbs" aria-label="Хлібні крихти">
    <a href="<?= e(url('/')) ?>">Головна</a><span>/</span><span aria-current="page">Про нас</span>
  </nav>

  <section class="sv-about">
    <div class="sv-about-copy">
      <span class="sv-eyebrow">Наш девіз</span>
      <h1 class="sv-h2" style="font-size:clamp(34px,4.6vw,58px)"<?= edit_mark('about_full', 'title') ?>><?= e(Content::title('about_full', 'Щаслива дитина — щаслива сімʼя!')) ?></h1>
      <div class="sv-desc sv-about-text"<?= edit_mark('about_full', 'body') ?>><?= rich_text($text) ?></div>
    </div>
    <div class="sv-about-side">
      <div class="sv-about-photo"<?= edit_mark('about_full', 'image') ?>>
        <?php if ($img !== ''): ?>
          <img src="<?= e(asset($img)) ?>" alt="Команда Старвуд-М" loading="lazy">
        <?php else: ?>
          <img src="<?= e(asset('img/logo.webp')) ?>" alt="Логотип Старвуд-М — три дерев'яні кубики" loading="lazy" class="sv-about-logo">
        <?php endif; ?>
      </div>
      <ul class="sv-facts">
        <li><b>2018</b><span>рік заснування</span></li>
        <li><b><?= (int)$products_count ?></b><span><?= plural((int)$products_count, 'іграшка', 'іграшки', 'іграшок') ?> в каталозі</span></li>
        <li><b><?= e(number_format((float)$review_stats['avg'], 1, ',', '')) ?>★</b><span><?= (int)$review_stats['count'] ?> <?= plural((int)$review_stats['count'], 'відгук', 'відгуки', 'відгуків') ?></span></li>
        <li><b>0–7</b><span>років — наш вік</span></li>
      </ul>
    </div>
  </section>

  <section class="sv-about-cards">
    <a class="sv-about-card" href="<?= e(url('/shop')) ?>" style="--c:#FFD35C">
      <b>Роздріб і гурт</b><span>Великогуртові й дрібногуртові ціни для магазинів іграшок — ціни від 3, 6, 12 штук видно на кожній картці.</span>
    </a>
    <a class="sv-about-card" href="<?= e(url('/delivery')) ?>" style="--c:#9CC8FF">
      <b>Доставка по Україні</b><span>Нова Пошта: відправляємо в день замовлення, безкоштовно від 2500 ₴.</span>
    </a>
    <a class="sv-about-card" href="<?= e(url('/testimonials')) ?>" style="--c:#8ED9B5">
      <b>Без вихідних</b><span>Два відділи продажу на звʼязку щодня — від 8:00 до опівночі.</span>
    </a>
  </section>

  <?php if ($gallery): ?>
    <div class="gallery-grid sv-gallery" data-lightbox<?= edit_mark('gallery') ?>>
      <?php foreach ($gallery as $g): [$cap, $path] = $g + [null, null]; if (!$path) continue; ?>
        <figure data-full="<?= e(asset($path)) ?>"><img src="<?= e(asset(Images::displayThumb($path))) ?>" alt="<?= e((string)$cap) ?>" loading="lazy"><?php if ($cap): ?><figcaption><?= e($cap) ?></figcaption><?php endif; ?></figure>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($faq): ?>
    <section class="sv-faq" id="faq"<?= edit_mark('faq') ?>>
      <h2 class="sv-h2" style="font-size:36px">Часті запитання батьків</h2>
      <?php foreach ($faq as [$q, $a]): ?>
        <details class="sv-faq-item"><summary><?= e($q) ?></summary><p><?= e($a) ?></p></details>
      <?php endforeach; ?>
    </section>
  <?php endif; ?>
</div>
