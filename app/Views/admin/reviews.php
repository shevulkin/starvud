<?php
/**
 * @var string $tab     'new' — чекають перевірки, 'all' — усі
 * @var array  $rows
 * @var int    $pending
 */
?>
<div class="admin-head"><h1 class="h-serif">Відгуки</h1></div>

<div class="admin-card">
  <div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap">
    <a class="btn btn-sm <?= $tab === 'new' ? 'btn-gold' : 'btn-line' ?>" href="<?= e(url('/admin/reviews')) ?>">
      Нові<?= $pending ? ' · ' . $pending : '' ?></a>
    <a class="btn btn-sm <?= $tab === 'all' ? 'btn-gold' : 'btn-line' ?>" href="<?= e(url('/admin/reviews?tab=all')) ?>">Усі</a>
  </div>

  <?php if (!$rows): ?>
    <p class="muted" style="margin:0">
      <?= $tab === 'new' ? 'Нових відгуків немає. Щойно покупець залишить відгук на сайті, він зʼявиться тут — на сайті його видно лише після публікації.' : 'Відгуків ще немає.' ?>
    </p>
  <?php endif; ?>

  <?php foreach ($rows as $r): ?>
    <div style="border-top:1px solid var(--line,#e8e2d8);padding:16px 0">
      <div style="display:flex;gap:12px;align-items:baseline;flex-wrap:wrap">
        <b><?= e((string)$r['author']) ?></b>
        <span style="color:#E8A33D"><?= str_repeat('★', (int)$r['rating']) . str_repeat('☆', 5 - (int)$r['rating']) ?></span>
        <span class="muted"><?= e(date('d.m.Y', strtotime((string)$r['created_at']) ?: time())) ?></span>
        <span class="muted"><?= ($r['source'] ?? '') === 'prom' ? 'з Prom' : 'з сайту' ?></span>
        <?php if (!(int)$r['approved']): ?><span class="badge">не опубліковано</span><?php endif; ?>
      </div>
      <?php if (trim((string)$r['text']) !== ''): ?>
        <p style="margin:8px 0 0;white-space:pre-line"><?= e((string)$r['text']) ?></p>
      <?php endif; ?>

      <form method="post" action="<?= e(url('/admin/reviews')) ?>" style="margin-top:10px;max-width:640px">
        <?= Csrf::field() ?>
        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <input type="hidden" name="tab" value="<?= e($tab) ?>">
        <label class="muted" style="font-size:13px">Відповідь магазину (видно під відгуком)</label>
        <textarea name="reply" rows="2" placeholder="Дякуємо за відгук! …"><?= e((string)($r['reply'] ?? '')) ?></textarea>
        <div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap">
          <button class="btn btn-line btn-sm" type="submit" name="_action" value="reply">Зберегти відповідь</button>
          <?php if ((int)$r['approved']): ?>
            <button class="btn btn-line btn-sm" type="submit" name="_action" value="hide">Приховати</button>
          <?php else: ?>
            <button class="btn btn-gold btn-sm" type="submit" name="_action" value="approve">Опублікувати</button>
          <?php endif; ?>
          <button class="btn btn-danger btn-sm" type="submit" name="_action" value="delete"
                  onclick="return confirm('Видалити відгук назавжди?')">Видалити</button>
        </div>
      </form>
    </div>
  <?php endforeach; ?>
</div>
