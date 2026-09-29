<?php
/**
 * Картка курсу — не картка товару.
 *
 * @var array $prod
 *
 * Товарна картка — вертикальна, з великим фото зверху: банку меду впізнають
 * саме по банці, і рішення там ухвалюють за секунду. З курсом навпаки:
 * дивляться не на картинку, а на те, що написано, і фото на пів екрана лише
 * відсуває текст, заради якого сюди прийшли.
 *
 * Тому горизонтальна: фото збоку й фіксованою пропорцією, а праворуч — назва,
 * суть, факти й ціна. Ті самі дані, інша вага.
 */
$courseOwned = Courses::owned(Auth::id(), (int)$prod['id']);
$courseOpen  = $courseOwned && Courses::isOpen((int)Auth::id(), (int)$prod['id']);
[$coursePrice, $courseOld] = Catalog::price($prod);
$courseFacts = Catalog::attrs((int)$prod['id']);
?>
<article class="course-card">
  <a class="course-card-img" href="<?= e(url('/course/' . $prod['slug'])) ?>">
    <?php $photo = Catalog::photo($prod); if ($photo): ?>
      <img src="<?= e(asset(Images::displayThumb($photo))) ?>" alt="<?= e($prod['name']) ?>" loading="lazy">
    <?php else: ?><span class="ph">🎓</span><?php endif; ?>
    <?php if ($courseOwned): ?><span class="course-badge">Ваш курс</span><?php endif; ?>
  </a>
  <div class="course-card-body">
    <h3 class="course-card-title">
      <a href="<?= e(url('/course/' . $prod['slug'])) ?>"><?= e($prod['name']) ?></a></h3>
    <?php if (trim((string)($prod['short_desc'] ?? '')) !== ''): ?>
      <p class="course-card-desc"><?= e($prod['short_desc']) ?></p>
    <?php endif; ?>

    <?php /* Короткі факти — з тієї ж системи характеристик, що й у товарів:
             тривалість, формат, розмір групи. Заводяться в картці курсу, а не
             вигадуються тут, тож новий факт зʼявиться без правки шаблону. */ ?>
    <?php if ($courseFacts): ?>
      <ul class="course-facts">
        <?php foreach (array_slice($courseFacts, 0, 4) as $f): ?>
          <li><span><?= e($f['name']) ?></span><b><?= e($f['value']) ?><?= !empty($f['unit']) ? ' ' . e($f['unit']) : '' ?></b></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <div class="course-card-foot">
      <?php /* Куплений курс ціни не показує: вона вже сплачена, і нагадувати
               про суму людині, яка прийшла вчитись, — недоречно. */ ?>
      <?php if (!$courseOwned): ?>
        <span class="price">
          <?php if ($courseOld !== null): ?><s><?= e(price_fmt($courseOld)) ?></s><?php endif; ?>
          <?= e(price_label($coursePrice, true)) ?></span>
      <?php endif; ?>
      <?php if ($courseOpen): ?>
        <a class="btn btn-gold btn-sm" href="<?= e(url('/learning')) ?>">Перейти до курсу →</a>
      <?php elseif ($courseOwned): ?>
        <?php /* Куплений, але строк вийшов: пропонуємо продовжити, а не «купити»
                 — людина вже вчилась, і слово «купити» тут звучить як «спочатку». */ ?>
        <span class="course-expired">Строк доступу вийшов</span>
        <a class="btn btn-line btn-sm" href="<?= e(url('/course/' . $prod['slug'])) ?>">Продовжити доступ</a>
      <?php else: ?>
        <a class="btn btn-gold btn-sm" href="<?= e(url('/course/' . $prod['slug'])) ?>">Про курс →</a>
      <?php endif; ?>
    </div>
  </div>
</article>
