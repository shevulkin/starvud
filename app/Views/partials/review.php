<?php
/**
 * Один відгук. @var array $r (Reviews::hydrate), ?array $products [id => товар]
 */
$products = $products ?? [];
$stars = (int)$r['rating'];
?>
<article class="sv-rev">
  <header class="sv-rev-head">
    <span class="sv-rev-ava" aria-hidden="true" style="background:<?= e(['#FFB59E', '#FFD35C', '#8ED9B5', '#9CC8FF', '#FFC93C'][crc32((string)$r['author']) % 5]) ?>"><?= e(mb_strtoupper(mb_substr((string)$r['author'], 0, 1))) ?></span>
    <div>
      <b class="sv-rev-author"><?= e($r['author']) ?></b>
      <span class="sv-rev-meta"><?= e($r['date']) ?><?= $r['source'] === 'prom' ? ' · покупка на Prom.ua' : '' ?></span>
    </div>
    <span class="sv-rev-stars" role="img" aria-label="Оцінка <?= $stars ?> з 5 — <?= e($r['label']) ?>">
      <?php for ($i = 1; $i <= 5; $i++): ?><svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2.8l2.8 6 6.5.7-4.9 4.4 1.4 6.4L12 17l-5.8 3.3 1.4-6.4L2.7 9.5l6.5-.7z" fill="<?= $i <= $stars ? '#FFC93C' : '#EDE3D2' ?>" stroke="#1F2544" stroke-width="1.6" stroke-linejoin="round"/></svg><?php endfor; ?>
    </span>
  </header>
  <?php if (!empty($r['text'])): ?><p class="sv-rev-text"><?= e($r['text']) ?></p><?php endif; ?>
  <?php if ($r['tags']): ?>
    <ul class="sv-rev-tags"><?php foreach ($r['tags'] as $t): ?><li><?= e($t) ?></li><?php endforeach; ?></ul>
  <?php endif; ?>
  <?php $mentioned = array_filter(array_map(static fn($id) => $products[$id] ?? null, $r['product_ids'])); ?>
  <?php if ($mentioned): ?>
    <p class="sv-rev-prod">Про товар: <?php foreach (array_values($mentioned) as $i => $mp): ?><?= $i ? ', ' : '' ?><a href="<?= e(url('/product/' . $mp['slug'])) ?>"><?= e($mp['name']) ?></a><?php endforeach; ?></p>
  <?php endif; ?>
  <?php if (!empty($r['reply'])): ?>
    <div class="sv-rev-reply"><b>Відповідь магазину</b><p><?= nl2br(e($r['reply'])) ?></p></div>
  <?php endif; ?>
</article>
