<?php
/**
 * Список усіх блоків сайту. Головний спосіб редагування — режим прямо на
 * вітрині, тому запрошення туди стоїть найпершим: людині, яка шукає «де
 * змінити той абзац під заголовком», список ключів не допоможе, а сторінка —
 * так. Список лишається для тих випадків, коли треба пройтись по всьому
 * одразу або дістати блок, якого зараз немає на екрані.
 */
$imageKeys = [];   // ключі з фото — приховані форми для них лежать унизу сторінки
?>
<div class="admin-head"><h1 class="h-serif">Тексти й фото сайту</h1></div>

<div class="admin-card" style="border-color:var(--gold);display:flex;gap:20px;align-items:center;flex-wrap:wrap">
  <div style="flex:1;min-width:260px">
    <h2 class="h-serif" style="margin-bottom:6px">Редагувати прямо на сайті</h2>
    <p class="dim" style="font-size:13.5px;line-height:1.6">
      Відкриється звичайний сайт, але кожен редагований блок буде обведено.
      Натискаєте на потрібний текст чи фото — збоку відкривається поле саме для нього.
      Так не треба вгадувати, який рядок у цьому списку відповідає якому місцю на сторінці.
    </p>
  </div>
  <form method="post" action="<?= e(url('/edit/on')) ?>"><?= Csrf::field() ?>
    <input type="hidden" name="back" value="/">
    <button class="btn btn-gold" type="submit">✏️ Редагувати на сайті</button>
  </form>
</div>

<p class="dim" style="margin-bottom:18px">Нижче — ті самі блоки списком. Зміни зʼявляються на сайті одразу після збереження.</p>

<form method="post" action="<?= e(url('/admin/content')) ?>" id="contentForm">
  <?= Csrf::field() ?><input type="hidden" name="_action" value="save">
  <?php foreach ($groups as $groupName => $keys): ?>
    <div class="admin-card">
      <h2 class="h-serif"><?= e($groupName) ?></h2>
      <?php foreach ($keys as $key):
        $fields = ContentSchema::fields($key);
        // списки мають власні розділи внизу — тут показувати нічого
        $editable = array_filter($fields, fn($f) => !in_array($f['type'], ContentSchema::JSON_TYPES, true));
        if (!$editable) continue;
        $b = $blocks[$key] ?? [];
        $hasImage = ContentSchema::type($key, 'image') === 'image';
        if ($hasImage) $imageKeys[$key] = ContentSchema::field($key, 'image')['label'] ?? 'Фото';
      ?>
        <div class="content-block">
          <div class="content-block-head">
            <b><?= e(ContentSchema::label($key)) ?></b>
            <span class="dim"><?= e(ContentSchema::where($key)) ?></span>
          </div>
          <div class="form-grid">
            <?php foreach ($editable as $name => $f): ?>
              <?php if ($f['type'] === 'image'): ?>
                <div class="field">
                  <label><?= e($f['label']) ?></label>
                  <?php $img = (string)($b['image'] ?? ''); ?>
                  <img src="<?= e(asset($img !== '' ? $img : 'img/about-photo.webp')) ?>"
                       style="width:120px;height:120px;object-fit:cover;border-radius:4px;border:1px solid var(--line)">
                  <div style="margin-top:8px">
                    <button class="btn btn-line btn-xs" type="button"
                            onclick="var f=document.getElementById('setImg_<?= e($key) ?>');MediaPicker.open(function(p){f.querySelector('[name=media_path]').value=p;f.submit();})">Змінити фото</button>
                  </div>
                  <?php if (!empty($f['hint'])): ?><p class="field-hint"><?= e($f['hint']) ?></p><?php endif; ?>
                </div>
              <?php else: ?>
                <div class="field"
                     data-help-title="<?= e(ContentSchema::label($key) . ' — ' . $f['label']) ?>"
                     data-help="<?= e(($f['hint'] ?? '') . "\n\n" . 'Де це на сайті: ' . ContentSchema::where($key)) ?>">
                  <label><?= e($f['label']) ?></label>
                  <?php if ($f['type'] === 'textarea' || $f['type'] === 'lines'): ?>
                    <textarea name="block[<?= e($key) ?>][<?= e($name) ?>]" rows="<?= $f['type'] === 'lines' ? 3 : 4 ?>"><?= e($b[$name] ?? '') ?></textarea>
                  <?php else: ?>
                    <input type="<?= $f['type'] === 'url' ? 'url' : 'text' ?>"
                           name="block[<?= e($key) ?>][<?= e($name) ?>]"
                           value="<?= e($b[$name] ?? '') ?>"<?= $f['type'] === 'url' ? ' placeholder="https://"' : '' ?>>
                  <?php endif; ?>
                  <?php if (!empty($f['hint'])): ?><p class="field-hint"><?= e($f['hint']) ?></p><?php endif; ?>
                </div>
              <?php endif; ?>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>

  <div class="admin-card">
    <?php $faqDef = ContentSchema::field('faq', 'body'); ?>
    <h2 class="h-serif" data-help-title="<?= e(ContentSchema::label('faq')) ?>"
        data-help="<?= e($faqDef['hint'] . "\n\n" . 'Щоб прибрати питання — очистіть його поле й збережіть.') ?>">
      <?= e(ContentSchema::label('faq')) ?></h2>
    <p class="dim" style="margin-bottom:14px"><?= e(ContentSchema::where('faq')) ?></p>
    <div id="faqList">
      <?php foreach ($faq as $qa): ?>
        <div class="form-grid">
          <div class="field"><label>Питання</label><input type="text" name="faq_q[]" value="<?= e($qa[0] ?? '') ?>"></div>
          <div class="field"><label>Відповідь</label><textarea name="faq_a[]" rows="2"><?= e($qa[1] ?? '') ?></textarea></div>
        </div>
      <?php endforeach; ?>
      <div class="form-grid">
        <div class="field"><label>+ Нове питання</label><input type="text" name="faq_q[]" placeholder="Порожнє = не додавати"></div>
        <div class="field"><label>Відповідь</label><textarea name="faq_a[]" rows="2"></textarea></div>
      </div>
    </div>
  </div>
  <div class="admin-save">
    <button class="btn btn-gold" type="submit">💾 Зберегти всі тексти</button>
    <span class="admin-save-note"></span>
  </div>
</form>

<div class="admin-card">
  <?php $galDef = ContentSchema::field('gallery', 'body'); ?>
  <h2 class="h-serif" data-help-title="<?= e(ContentSchema::label('gallery')) ?>"
      data-help="<?= e($galDef['hint']) ?>"><?= e(ContentSchema::label('gallery')) ?></h2>
  <p class="dim" style="margin-bottom:14px"><?= e(ContentSchema::where('gallery')) ?></p>
  <div class="img-grid">
    <?php foreach ($gallery as $i => $g): ?>
      <div class="img-cell">
        <img src="<?= e(asset(Images::displayThumb($g[1]))) ?>" alt="">
        <span class="dim"><?= e($g[0]) ?></span>
        <form method="post" action="<?= e(url('/admin/content')) ?>"><?= Csrf::field() ?>
          <input type="hidden" name="_action" value="gallery_del"><input type="hidden" name="index" value="<?= $i ?>">
          <button class="btn btn-danger btn-xs" style="margin-top:5px" onclick="return confirm('Прибрати фото?')">Прибрати</button>
        </form>
      </div>
    <?php endforeach; ?>
  </div>
  <div style="margin-top:18px;display:flex;gap:12px;align-items:end;flex-wrap:wrap">
    <form method="post" action="<?= e(url('/admin/content')) ?>" id="galAddForm" style="display:flex;gap:12px;align-items:end">
      <?= Csrf::field() ?><input type="hidden" name="_action" value="gallery_pick"><input type="hidden" name="media_path" value="">
      <div><label>Підпис</label><input type="text" name="title" placeholder="Напр. Наш склад"></div>
      <button class="btn btn-line btn-sm" type="button" onclick="var f=document.getElementById('galAddForm');MediaPicker.open(function(p){f.querySelector('[name=media_path]').value=p;f.submit();})">+ Додати фото в галерею</button>
    </form>
    <span class="dim">Вибір із сайту або завантаження з ПК; фото стискається автоматично</span>
  </div>
</div>

<?php /* форми зміни фото — поза формою текстів: вкладені <form> браузер не приймає */ ?>
<?php foreach ($imageKeys as $key => $label): ?>
  <form method="post" action="<?= e(url('/admin/content')) ?>" id="setImg_<?= e($key) ?>" style="display:none">
    <?= Csrf::field() ?><input type="hidden" name="_action" value="set_image">
    <input type="hidden" name="key" value="<?= e($key) ?>"><input type="hidden" name="media_path" value="">
  </form>
<?php endforeach; ?>
<?= View::partial('partials/media_picker') ?>
