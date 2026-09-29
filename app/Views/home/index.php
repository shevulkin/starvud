<?php
/**
 * Головна «Старвуд-М».
 *
 * Порядок — порядок питань батьків: що тут продають (перший екран із
 * справжніми іграшками) → що підійде моїй дитині (вік) → де шукати (розділи)
 * → що беруть інші (хіти, відгуки) → чи можна довіряти (переваги, доставка).
 * Декору мінімум: найяскравішим на сторінці мають бути самі іграшки.
 *
 * @var array $categories, $featured, $fresh, $reviews, $review_stats, $age_count, $hero_photos
 */
$benefitIcons = [
    '<path d="M12 3l7 3v6c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6z"/><path d="M8.5 12l2.5 2.5 4.5-5"/>',
    '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
    '<path d="M3 7h11v9H3zM14 10h4l3 3v3h-7"/><circle cx="7" cy="18" r="2"/><circle cx="17" cy="18" r="2"/>',
    '<path d="M5 4h4l2 5-3 2a11 11 0 005 5l2-3 5 2v4a2 2 0 01-2 2A17 17 0 013 6a2 2 0 012-2z"/>',
];
$benefits = [
    [Content::title('why_1', 'Перевіряємо кожну іграшку'), Content::get('why_1')],
    [Content::title('why_2', 'Чесний вік'), Content::get('why_2')],
    ['Доставка 1–3 дні', 'Новою Поштою по всій Україні. Безкоштовно від ' . price_fmt((float)Settings::get('free_shipping_from', '2500')) . '.'],
    ['Два відділи підтримки', 'Підкажемо з вибором щодня з 8:00 до опівночі.'],
];
$avg = number_format((float)$review_stats['avg'], 1, ',', '');
?>

<section class="sv-hero">
  <div class="container sv-hero-grid">
    <div class="sv-hero-copy">
      <span class="sv-eyebrow"<?= edit_mark('hero_badge', 'title') ?>><?= e(Content::title('hero_badge', 'Іграшки від народження до 7 років')) ?></span>
      <h1 class="sv-hero-title">Світ іграшок —<br><span>світ щастя</span></h1>
      <p class="sv-hero-text"<?= edit_mark('hero_text', 'body') ?>><?= e(Content::get('hero_text')) ?></p>
      <div class="sv-hero-cta">
        <a class="sv-btn sv-btn-primary" href="<?= e(url('/shop')) ?>">До каталогу</a>
        <a class="sv-btn sv-btn-ghost" href="#age">Обрати за віком</a>
      </div>
      <ul class="sv-hero-trust">
        <?php if ($review_stats['count']): ?>
          <li><a href="<?= e(url('/testimonials')) ?>"><b>★ <?= e($avg) ?></b> <?= (int)$review_stats['count'] ?> <?= plural((int)$review_stats['count'], 'відгук', 'відгуки', 'відгуків') ?></a></li>
        <?php endif; ?>
        <li><b>з 2018</b> року поруч із батьками</li>
        <li><b>1–3 дні</b> доставка</li>
      </ul>
    </div>
    <div class="sv-hero-art" aria-hidden="true">
      <div class="sv-hero-mosaic">
        <?php foreach ($hero_photos as $i => $h): ?>
          <a class="sv-hero-tile sv-hero-tile-<?= $i ?>" href="<?= e(url('/product/' . $h['slug'])) ?>" tabindex="-1">
            <img src="<?= e(asset(Images::displayThumb($h['photo']))) ?>" alt="" <?= $i ? 'loading="lazy"' : 'fetchpriority="high"' ?>>
          </a>
        <?php endforeach; ?>
      </div>
      <div class="sv-hero-star"><?= mascot('wave', 96) ?></div>
    </div>
  </div>
</section>

<section class="sv-section" id="age">
  <div class="container">
    <div class="sv-sec-head">
      <h2 class="sv-h2">Для якого віку шукаєте?</h2>
      <p class="sv-lead">Кожна іграшка має вікову позначку — оберіть вік, і ми покажемо лише те, що підійде.</p>
    </div>
    <div class="sv-ages">
      <?php foreach (Ages::BANDS as $k => [, , $label, $hint]): ?>
        <a class="sv-age-card sv-age-<?= e((string)$k) ?>" href="<?= e(shop_url(null, ['age' => $k])) ?>">
          <span class="sv-age-label"><?= e($label) ?></span>
          <span class="sv-age-hint"><?= e($hint) ?></span>
          <span class="sv-age-count"><?= (int)$age_count[$k] ?> <?= plural((int)$age_count[$k], 'іграшка', 'іграшки', 'іграшок') ?> <i aria-hidden="true">→</i></span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="sv-section sv-section-tight">
  <div class="container">
    <div class="sv-sec-row">
      <h2 class="sv-h2">Розділи магазину</h2>
      <a class="sv-more" href="<?= e(url('/shop')) ?>">Усі іграшки →</a>
    </div>
    <div class="sv-cats">
      <?php foreach ($categories as $c): ?>
        <a class="sv-cat" href="<?= e(shop_url($c['slug'])) ?>">
          <span class="sv-cat-img"><img src="<?= e(asset(Images::displayThumb($c['photo']))) ?>" alt="" loading="lazy"></span>
          <span class="sv-cat-name"><?= e($c['name']) ?></span>
          <span class="sv-cat-count"><?= (int)$c['count'] ?> <?= plural((int)$c['count'], 'товар', 'товари', 'товарів') ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<?php if ($featured): ?>
<section class="sv-section">
  <div class="container">
    <div class="sv-sec-row">
      <div><span class="sv-eyebrow">Найчастіше обирають</span><h2 class="sv-h2">Хіти продажу</h2></div>
      <a class="sv-more" href="<?= e(url('/shop')) ?>">Дивитись усі →</a>
    </div>
    <div class="sv-grid">
      <?php foreach ($featured as $prod) echo View::partial('partials/product_card', ['prod' => $prod]); ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php /* Подарунок за бюджетом. Іграшку часто купує не мама, а бабуся, хрещена
         чи гість на день народження — вони думають сумою, а не розділом
         каталогу. Межі цін — ті самі фільтри min/max, що й у каталозі. */ ?>
<section class="sv-section sv-section-tight">
  <div class="container">
    <div class="sv-sec-row">
      <div><span class="sv-eyebrow">Шукаєте подарунок?</span><h2 class="sv-h2">Подарунок за бюджетом</h2></div>
    </div>
    <div class="sv-budget">
      <?php foreach ([['до 300 ₴', ['max' => 299], 'маленька радість'], ['300–700 ₴', ['min' => 300, 'max' => 699], 'на свято чи в гості'],
                      ['700–1500 ₴', ['min' => 700, 'max' => 1499], 'на день народження'], ['від 1500 ₴', ['min' => 1500], 'великий подарунок']] as [$lbl, $q, $hint]): ?>
        <a class="sv-budget-card" href="<?= e(shop_url(null, $q + ['sort' => 'price_asc'])) ?>">
          <b><?= e($lbl) ?></b><span><?= e($hint) ?></span><i aria-hidden="true">→</i>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="sv-benefits">
  <div class="container sv-benefits-grid">
    <?php foreach ($benefits as $i => [$t, $b]): ?>
      <div class="sv-benefit">
        <span class="sv-benefit-ic"><svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $benefitIcons[$i] ?></svg></span>
        <div><b<?= $i < 2 ? edit_mark('why_' . ($i + 1), 'title') : '' ?>><?= e($t) ?></b><p<?= $i < 2 ? edit_mark('why_' . ($i + 1), 'body') : '' ?>><?= e($b) ?></p></div>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<?php if ($fresh): ?>
<section class="sv-section">
  <div class="container">
    <div class="sv-sec-row">
      <div><span class="sv-eyebrow">Щойно на полиці</span><h2 class="sv-h2">Нові надходження</h2></div>
      <a class="sv-more" href="<?= e(shop_url(null, ['sort' => 'new'])) ?>">Усі новинки →</a>
    </div>
    <div class="sv-grid">
      <?php foreach ($fresh as $prod) echo View::partial('partials/product_card', ['prod' => $prod]); ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if ($reviews): ?>
<section class="sv-section sv-reviews-home">
  <div class="container">
    <div class="sv-sec-row">
      <div>
        <span class="sv-eyebrow">Відгуки покупців</span>
        <h2 class="sv-h2">★ <?= e($avg) ?> з 5 — за <?= (int)$review_stats['count'] ?> <?= plural((int)$review_stats['count'], 'відгуком', 'відгуками', 'відгуками') ?></h2>
      </div>
      <a class="sv-more" href="<?= e(url('/testimonials')) ?>">Читати всі →</a>
    </div>
    <div class="sv-quotes">
      <?php foreach ($reviews as $r): ?>
        <figure class="sv-quote">
          <span class="sv-quote-stars" aria-label="Оцінка <?= (int)$r['rating'] ?> з 5"><?= str_repeat('★', (int)$r['rating']) ?></span>
          <blockquote><?= e(mb_strimwidth($r['text'], 0, 200, '…')) ?></blockquote>
          <figcaption><b><?= e($r['author']) ?></b> · <?= e($r['date']) ?></figcaption>
        </figure>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<section class="sv-section sv-section-tight">
  <div class="container">
    <div class="sv-promo">
      <div>
        <span class="sv-eyebrow">Для магазинів і садочків</span>
        <h2 class="sv-h2">Гуртові ціни — вже на кожній картці</h2>
        <p>Беріть від 3, 6 чи 12 штук — знижка порахується в кошику сама. Для великого опту зателефонуйте у відділ продажу.</p>
        <a class="sv-btn sv-btn-light" href="<?= e(url('/about')) ?>">Дізнатися більше</a>
      </div>
      <img src="<?= e(asset('img/logo.webp')) ?>" alt="" loading="lazy" class="sv-promo-logo">
    </div>
  </div>
</section>
