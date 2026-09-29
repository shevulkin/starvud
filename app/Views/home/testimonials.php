<?php
/**
 * Відгуки покупців. @var array $reviews, $stats, $products
 */
$draft = $_SESSION['review_draft'] ?? [];
$max = max(1, max($stats['by']));
?>
<div class="container sv-page">
  <nav class="sv-crumbs" aria-label="Хлібні крихти">
    <a href="<?= e(url('/')) ?>">Головна</a><span>/</span><span aria-current="page">Відгуки</span>
  </nav>

  <div class="sv-rev-hero">
    <div>
      <span class="sv-eyebrow">Листи від покупців</span>
      <h1 class="sv-h2" style="font-size:clamp(34px,4.4vw,56px)">Відгуки про «Старвуд-М»</h1>
      <p class="sv-lead" style="max-width:560px;margin-top:14px">Усі відгуки з нашого магазину на Prom.ua перенесені сюди без змін — разом із четвірками.
        Новий відгук можна залишити внизу сторінки.</p>
    </div>
    <div class="sv-rev-score sv-panel">
      <div class="sv-rev-avg">
        <b><?= e(number_format((float)$stats['avg'], 1, ',', '')) ?></b>
        <span>з 5 · <?= (int)$stats['count'] ?> <?= plural((int)$stats['count'], 'відгук', 'відгуки', 'відгуків') ?></span>
      </div>
      <ul class="sv-rev-bars">
        <?php foreach ($stats['by'] as $k => $n): ?>
          <li><span><?= $k ?>★</span><i style="--w:<?= round($n / $max * 100) ?>%"></i><em><?= (int)$n ?></em></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>

  <?php if ($stats['tags']): ?>
    <ul class="sv-rev-toptags" aria-label="Що найчастіше відзначають покупці">
      <?php foreach (array_slice($stats['tags'], 0, 7, true) as $t => $n): ?>
        <li><?= e($t) ?> <b><?= (int)$n ?></b></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <div class="sv-rev-list">
    <?php foreach ($reviews as $r) echo View::partial('partials/review', ['r' => $r, 'products' => $products]); ?>
  </div>

  <section class="sv-rev-write sv-panel" id="write">
    <h2 class="sv-h2" style="font-size:32px">Залишити відгук</h2>
    <p class="dim" style="margin:6px 0 20px">Ми читаємо кожен відгук і публікуємо його після перевірки — зазвичай того ж дня.</p>
    <form method="post" action="<?= e(url('/testimonials/new')) ?>" class="sv-rev-form">
      <?= Csrf::field() ?>
      <fieldset class="sv-rate">
        <legend>Оцінка</legend>
        <div class="sv-rate-stars">
          <?php $cur = (int)($draft['rating'] ?? 5); for ($i = 5; $i >= 1; $i--): ?>
            <input type="radio" id="rate<?= $i ?>" name="rating" value="<?= $i ?>" <?= $cur === $i ? 'checked' : '' ?>>
            <label for="rate<?= $i ?>" title="<?= e(Reviews::LABELS[$i]) ?>"><span class="sr-only"><?= $i ?> — <?= e(Reviews::LABELS[$i]) ?></span></label>
          <?php endfor; ?>
        </div>
      </fieldset>
      <label class="sv-field">Ваше імʼя
        <input type="text" name="author" maxlength="60" required value="<?= e($draft['author'] ?? ($auth_user['name'] ?? '')) ?>" autocomplete="given-name">
      </label>
      <label class="sv-field">Відгук
        <textarea name="text" rows="4" maxlength="2000" required placeholder="Що сподобалось, що ні, як пограла дитина…"><?= e($draft['text'] ?? '') ?></textarea>
      </label>
      <fieldset>
        <legend class="sv-field-l">Що відзначите?</legend>
        <div class="sv-chipset">
          <?php foreach (Reviews::TAGS as $t): ?>
            <label class="sv-chip-check"><input type="checkbox" name="tags[]" value="<?= e($t) ?>"><span><?= e($t) ?></span></label>
          <?php endforeach; ?>
        </div>
      </fieldset>
      <button class="btn btn-gold" type="submit">Надіслати відгук</button>
      <p class="sv-form-note">Відгук зʼявиться після перевірки. Імʼя буде показане поруч із ним —
        див. <a href="<?= e(url('/privacy')) ?>" target="_blank" rel="noopener">політику конфіденційності</a>.</p>
    </form>
  </section>
</div>
<?php unset($_SESSION['review_draft']); ?>
