<?php
/**
 * Наші партнери.
 *
 * Один партнер — одна форма: файл лого не можна вкласти у спільну форму
 * таблиці, а вкладені форми браузер не приймає (те саме рішення, що й у брендах).
 *
 * @var array $partners
 */
?>
<div class="admin-head">
  <h1 class="h-serif">Партнери</h1>
  <a class="btn btn-line btn-sm" href="<?= e(url('/partners')) ?>" target="_blank" rel="noopener">Сторінка на сайті →</a>
</div>
<p class="dim" style="margin:0 0 18px;max-width:760px">
  Господарства, школи та крамниці, з якими ви працюєте. Показуються на сторінці
  «Наші партнери» в порядку, який ви задасте. Це не бренди: бренд відповідає на питання,
  чий товар, і живе в картці товару.
</p>

<form class="admin-card" method="post" action="<?= e(url('/admin/partners')) ?>"
      style="display:flex;gap:14px;align-items:end;flex-wrap:wrap">
  <?= Csrf::field() ?><input type="hidden" name="_action" value="add">
  <div style="flex:1;min-width:220px" data-help-title="Назва партнера"
       data-help="Як партнер називається публічно: «Дитячий садок «Сонечко»», «Розвиваючий центр «Крок»».

Пишіть так, як він сам себе називає, — покупець бачить цю назву на сторінці партнерів, а вона ж іде в підпис лого.

Назви не повторюються: другого такого самого додати не дасть.

Лого, опис і посилання додаються нижче, після створення.">
    <label>Назва</label><input type="text" name="name" required></div>
  <button class="btn btn-gold btn-sm" type="submit">+ Додати</button>
</form>

<?php if (!$partners): ?>
  <p class="dim">Поки що порожньо. Додайте першого партнера — сторінка «Наші партнери»
    зʼявиться в меню сайту, щойно в списку буде хоч один активний.</p>
<?php endif; ?>

<?php foreach ($partners as $p): $pid = (int)$p['id']; ?>
  <form class="admin-card" method="post" action="<?= e(url('/admin/partners')) ?>" enctype="multipart/form-data">
    <?= Csrf::field() ?><input type="hidden" name="id" value="<?= $pid ?>">

    <div style="display:flex;gap:20px;flex-wrap:wrap;align-items:flex-start">
      <div style="flex:1;min-width:260px">
        <div class="form-grid">
          <div class="field"><label>Назва</label>
            <input type="text" name="name" value="<?= e($p['name']) ?>" required></div>
          <div class="field" data-help-title="Порядок"
               data-help="Чим менше число, тим вище партнер на сторінці.

Порядок задають руками, бо він означає щось для вас, а не для компʼютера: за абеткою найголовніший партнер опинився б посередині списку.">
            <label>Порядок</label><input type="number" name="sort" value="<?= (int)$p['sort'] ?>"></div>
        </div>
        <div class="field" data-help-title="Сайт партнера"
             data-help="Куди веде клік по картці партнера. Можна писати без «https://» — допишемо самі.

Порожньо — картка лишається, але не клікається. Це нормально для партнера без сайту: господарство часто має лише сторінку в Instagram, її теж можна вставити сюди.">
          <label>Сайт (необовʼязково)</label>
          <input type="text" name="url" value="<?= e($p['url'] ?? '') ?>" placeholder="https://сайт-партнера.ua"></div>
        <div class="field" data-help-title="Опис"
             data-help="Одне-два речення: хто це і чим ви разом займаєтесь.

Показується під назвою на сторінці партнерів. Не переказуйте їхній сайт — покупцю тут потрібна відповідь на питання «а вони вам хто».

Порожньо — картка покаже лише лого й назву. Це теж працює.">
          <label>Опис</label>
          <textarea name="description" rows="3"
                    placeholder="Хто це і чим ви разом займаєтесь"><?= e($p['description'] ?? '') ?></textarea>
        </div>
        <label class="checkbox" style="margin:0" data-help-title="Активний"
               data-help="Чи показувати партнера на сайті.

Знята галка ховає його зі сторінки, але сам запис, лого й опис лишаються на місці. Так правильно тимчасово прибрати партнера, з яким узяли паузу, замість видаляти й заводити наново.">
          <input type="checkbox" name="active" value="1" <?= $p['active'] ? 'checked' : '' ?>> Активний</label>
      </div>

      <div style="width:200px" data-help-title="Лого партнера"
           data-help="Показується на картці партнера. Підійде звичайний файл із їхнього сайту: JPEG, PNG, GIF або WebP до 15 МБ — велике зображення сайт зменшить сам.

Фон краще прозорий або білий: лого стоїть на темному.

Лого немає — картка покаже назву великими літерами. Сторінка від цього не ламається, але ряд різнокаліберних карток виглядає гірше за рівний ряд лого.">
        <label style="display:block;margin-bottom:8px">Лого</label>
        <?php if (!empty($p['logo'])): ?>
          <div style="background:var(--bg3);border-radius:6px;padding:10px;text-align:center;margin-bottom:8px">
            <img src="<?= e(asset($p['logo'])) ?>" alt="<?= e($p['name']) ?>" style="max-width:100%;max-height:90px">
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
      <?php if (!empty($p['url'])): ?>
        <a class="btn btn-line btn-xs" href="<?= e(safe_url($p['url'])) ?>" target="_blank" rel="noopener">Відкрити сайт →</a>
      <?php endif; ?>
      <?php /* Видалення без умов: на партнері, на відміну від бренду, не висять
               товари, тож після нього нічого не лишається без відповіді */ ?>
      <button class="btn btn-danger btn-xs" type="submit" name="_action" value="delete"
              style="margin-left:auto"
              onclick="return confirm(<?= e(json_encode('Видалити партнера «' . $p['name'] . '»? Лого теж прибереться, якщо його більше ніде не використано.', JSON_UNESCAPED_UNICODE)) ?>)">Видалити</button>
    </div>
  </form>
<?php endforeach; ?>
