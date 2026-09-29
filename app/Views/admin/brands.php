<div class="admin-head"><h1 class="h-serif">Бренди</h1></div>
<p class="dim" style="margin:0 0 18px;max-width:760px">Чий товар. Бренд, позначений «наш», дає покупцю напис
  «Виготовимо під замовлення — ми виробник». Для решти напис нейтральний: «Виготовляється на замовлення —
  привеземо для вас». Товар без бренду вважається не нашим.</p>

<form class="admin-card" method="post" action="<?= e(url('/admin/brands')) ?>" style="display:flex;gap:14px;align-items:end;flex-wrap:wrap">
  <?= Csrf::field() ?><input type="hidden" name="_action" value="add">
  <div style="flex:1;min-width:200px" data-help-title="Назва бренду"
       data-help="Хто виробник: «SINCERA», «Апіпродукт», «<?= e($own_default) ?>».

Пишіть так, як написано на упаковці, — покупець бачить цю назву в характеристиках товару й у каталозі, і вона ж іде в розмітку для Google.

Назви не повторюються: якщо бренд уже є в списку, додати другий такий самий сайт не дасть.

Лого та опис додаються нижче, після створення.">
    <label>Назва</label><input type="text" name="name" required></div>
  <button class="btn btn-gold btn-sm" type="submit">+ Додати</button>
</form>

<?php if (!$brands): ?>
  <p class="dim">Поки що порожньо. Додайте перший бренд — далі його можна буде обрати в картці товару.</p>
<?php endif; ?>

<?php foreach ($brands as $b): $n = (int)($counts[(int)$b['id']] ?? 0); ?>
  <?php /* окрема форма на бренд: файл лого не можна вкласти у спільну форму
           таблиці, а вкладені форми браузер не приймає */ ?>
  <form class="admin-card" method="post" action="<?= e(url('/admin/brands')) ?>" enctype="multipart/form-data">
    <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int)$b['id'] ?>">

    <div style="display:flex;gap:20px;flex-wrap:wrap;align-items:flex-start">
      <div style="flex:1;min-width:260px">
        <div class="field" style="margin-bottom:12px">
          <label>Назва <?php if ($b['own']): ?><span class="status-pill st-new">наш</span><?php endif; ?></label>
          <input type="text" name="name" value="<?= e($b['name']) ?>" required>
        </div>
        <div class="field" data-help-title="Опис бренду"
             data-help="Кілька речень про виробника: хто це, чим відомий, чому ви з ним працюєте.

Показується покупцю на сторінці бренду — тій, куди веде назва бренду з картки товару.

Порожньо — сторінка просто покаже товари без пояснення.">
          <label>Опис</label>
          <textarea name="description" rows="3" placeholder="Хто це і чому ви з ним працюєте"><?= e($b['description'] ?? '') ?></textarea>
        </div>
        <label class="checkbox" style="margin:0" data-help-title="Активний"
               data-help="Чи пропонувати бренд у картці товару та у фільтрах магазину.

Знята галка ховає бренд зі списку вибору, але вже підписані ним товари не змінюються — вони й далі показують цю назву покупцю.

Так правильно виводити з обігу бренд, з яким більше не працюєте, замість видаляти його.">
          <input type="checkbox" name="active" value="1" <?= $b['active'] ? 'checked' : '' ?>> Активний</label>
      </div>

      <div style="width:200px" data-help-title="Лого бренду"
           data-help="Показується на сторінці бренду й поруч із назвою в характеристиках товару. У каталозі на картках лого немає навмисно — воно збивало б рівний ритм фотографій товарів.

Підійде звичайний файл із сайту виробника: JPEG, PNG, GIF або WebP до 15 МБ. Велике зображення сайт зменшить сам.

Фон краще прозорий або білий — лого стоїть на темному.">
        <label style="display:block;margin-bottom:8px">Лого</label>
        <?php if (!empty($b['logo'])): ?>
          <div style="background:var(--bg3);border-radius:6px;padding:10px;text-align:center;margin-bottom:8px">
            <img src="<?= e(asset($b['logo'])) ?>" alt="<?= e($b['name']) ?>" style="max-width:100%;max-height:90px">
          </div>
          <button class="btn btn-line btn-xs" type="submit" name="_action" value="logo_remove"
                  style="width:100%;margin-bottom:8px">Прибрати лого</button>
        <?php else: ?>
          <p class="dim" style="margin:0 0 8px">Лого немає</p>
        <?php endif; ?>
        <span class="file-field">
          <input type="file" name="logo" accept="image/*">
          <button class="btn btn-line btn-xs" type="button" data-file-btn>Обрати лого</button>
          <span class="file-name" data-empty="Файл не обрано">Файл не обрано</span>
        </span>
      </div>
    </div>

    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:16px">
      <button class="btn btn-gold btn-sm" type="submit" name="_action" value="save">💾 Зберегти</button>
      <?php if (!$b['own']): ?>
        <button class="btn btn-line btn-xs" type="submit" name="_action" value="own"
                title="Зробити цей бренд власним виробництвом">Зробити нашим</button>
      <?php endif; ?>
      <span class="dim" style="margin-left:auto">
        <?php if ($n): ?>
          <a href="<?= e(url('/admin/products?brand=' . (int)$b['id'])) ?>"><?= $n ?> товар(ів)</a>
        <?php else: ?>Товарів немає<?php endif; ?>
      </span>
      <?php /* видалення лише в порожнього: інакше товари лишились би без відповіді, чиї вони */ ?>
      <?php if (!$n): ?>
        <button class="btn btn-danger btn-xs" type="submit" name="_action" value="delete">Видалити</button>
      <?php endif; ?>
    </div>
  </form>
<?php endforeach; ?>
