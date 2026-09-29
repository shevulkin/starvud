<?php
/**
 * Словник характеристик.
 *
 * Екран відповідає на питання «що в мене вже заведено» — і лише потім дає це
 * правити. Тому список іде першим, а форма створення згорнута: заводять
 * характеристику раз на місяць, а звіряються зі словником щоразу, коли
 * заповнюють товар.
 *
 * Головне в згорнутому рядку — самі значення. Раніше там стояв ланцюжок фактів
 * через крапку (тип, кількість, товари, варіанти, категорії) однаковим сірим, а
 * значень не було зовсім: щоб побачити, які саме медоноси заведені, доводилось
 * розгортати форму редагування. Тепер словник читається згорнутим.
 *
 * @var array $attrs @var array $usage @var array $val_usage @var array $categories @var int $open
 */
$catName = [];
foreach ($categories as $c) $catName[(int)$c['id']] = $c['name'];

/** Скільки значень показувати згорнутим: далі рядок перестає читатись */
$chipLimit = 8;

/** Поля, однакові у формі створення й у формі правки */
$typeOptions = function (string $current): string {
    $out = '';
    foreach (Attrs::TYPES as $t => $lbl) {
        $out .= '<option value="' . e($t) . '"' . ($current === $t ? ' selected' : '') . '>' . e($lbl) . '</option>';
    }
    return $out;
};
?>
<div class="admin-head">
  <h1 class="h-serif">Характеристики</h1>
  <a class="btn btn-line btn-sm" href="<?= e(url('/admin/products')) ?>">До товарів →</a>
</div>

<p class="dim" style="margin:-8px 0 18px;max-width:760px">
  Спільний словник для всіх товарів: у картці товару значення обирають зі списку, а не набирають руками.
  Привʼязка до категорій вирішує, де характеристику пропонувати: щоб у пазлів не було «Типу живлення»,
  а в машинок — «Кількості елементів».
</p>

<?php /* Форма створення згорнута: вона потрібна зрідка, а розгорнутою забирала
         перший екран — той самий, на якому мали б бути наявні характеристики */ ?>
<details class="admin-card attr-new" <?= $attrs ? '' : 'open' ?>>
  <summary>+ Нова характеристика</summary>
  <form method="post" action="<?= e(url('/admin/attributes')) ?>" style="margin-top:18px">
    <?= Csrf::field() ?><input type="hidden" name="_action" value="create">
    <div class="form-grid">
      <div class="field" data-help-title="Назва характеристики"
           data-help="Властивість, спільна для багатьох товарів: «Колір», «Матеріал», «Тип живлення», «Персонажі».

Пишіть в однині й так, як прочитав би покупець: саме ця назва стоїть у таблиці характеристик на сторінці товару й над фільтром у каталозі.

Це словник: створивши характеристику один раз, ви обираєте її значення в кожному товарі зі списку, а не набираєте руками. Тому «Обʼєм» не перетвориться на «обєм», «Обʼем» і «ОБʼЄМ» у трьох різних картках.">
        <label>Назва *</label><input type="text" name="name" required placeholder="Напр. Колір, Обʼєм, Матеріал"></div>
      <div class="field" data-help-title="Тип характеристики"
           data-help="Як значення виглядатиме й поводитиметься.

«Список значень» і «Колір» мають готовий перелік, з якого обирають, — саме вони стають фільтрами в каталозі.

«Довільний текст» і «Число» списку не мають: значення набирають прямо в картці товару. Фільтр із них не вийде — однакове написання ніхто не гарантує.

Якщо сумніваєтесь, беріть «Список значень».">
        <label>Тип</label>
        <select name="type"><?= $typeOptions('select') ?></select>
      </div>
      <div class="field" data-help-title="Одиниця виміру"
           data-help="Підпис, що дописується до назви: «Обʼєм, мл», «Вага, г».

Пишіть саму одиницю — «г», «мл», «см», — без дужок і коми, вони додадуться самі.

Необовʼязкове поле. Для «Кольору» чи «Матеріалу» одиниці, звісно, не потрібні.">
        <label>Одиниця (необовʼязково)</label><input type="text" name="unit" placeholder="г, мл, см"></div>
      <div class="field" data-help-title="Показувати у фільтрах"
           data-help="Чи можна відбирати товари за цією характеристикою в каталозі.

Галка стоїть — у бічній панелі каталогу зʼявиться фільтр із цими значеннями, і покупець зможе обрати, скажімо, лише дерев'яні іграшки.

Галка знята — характеристика все одно показується в таблиці на сторінці товару, але фільтрувати за нею не можна.

Знімайте для того, що не допомагає вибирати: «Термін придатності» чи «Номер партії» у фільтрі лише захаращують панель.">
        <label>&nbsp;</label>
        <label class="checkbox"><input type="checkbox" name="filterable" checked> Показувати у фільтрах магазину</label>
      </div>
    </div>
    <div class="field" data-help-title="Значення"
         data-help="Готовий список, з якого потім обиратимуть у картці товару.

Пишіть по одному в рядок або через кому: «Липовий, Гречаний, Соняшниковий».

Заповніть одразу всі, які плануєте: саме з цього списку складається фільтр у каталозі. Дописати нові значення можна й пізніше.

Порядок тут довільний — у фільтрі значення сортуються самі.">
      <label>Значення — по одному в рядок або через кому</label>
      <textarea name="values" rows="3" placeholder="Червоний, Синій, Золотий"></textarea>
    </div>
    <div class="field" data-help-title="Категорії"
         data-help="У яких категоріях пропонувати цю характеристику при заповненні товару.

Нічого не обрано — характеристика пропонується всюди.

Привʼязка потрібна, щоб форма не перетворювалась на звалище: у пазлів не буде «Типу живлення», а в машинок — «Кількості елементів». Заповнювати картку стає помітно швидше.

На вже заповнені товари це не впливає — лише на те, що пропонується далі.">
      <label>Категорії (нічого не обрано = для всіх)</label>
      <div class="pick-grid">
        <?php foreach ($categories as $c): ?>
          <label class="checkbox"><input type="checkbox" name="categories[]" value="<?= (int)$c['id'] ?>"> <?= e(cat_label($c)) ?></label>
        <?php endforeach; ?>
      </div>
    </div>
    <button class="btn btn-gold btn-sm" type="submit" data-help-title="Кнопка «Створити»"
            data-help="Додає характеристику у спільний словник.

Після цього вона зʼявиться в картках товарів обраних категорій — у блоці «Характеристики», де її можна буде призначити конкретному товару.

Сама по собі вона нічого не змінює на сайті: у каталозі й фільтрах характеристика зʼявиться тоді, коли її отримає хоч один товар.">+ Створити</button>
  </form>
</details>

<?php if (!$attrs): ?>
  <p class="muted">Поки що словник порожній — заведіть першу характеристику формою вище.</p>
<?php endif; ?>

<?php foreach ($attrs as $a): $aid = (int)$a['id']; $u = $usage[$aid];
      $hasList = in_array($a['type'], ['select', 'color'], true);
      $shown = array_slice($a['values'], 0, $chipLimit);
      $rest = count($a['values']) - count($shown); ?>
  <details class="admin-card attr-item" <?= $open === $aid ? 'open' : '' ?>>
    <?php /* Згорнутий рядок читають, а не переглядають: тому в ньому три поверхи
             з різною вагою — назва, значення, і вже потім службові числа */ ?>
    <summary>
      <div class="attr-top">
        <span class="attr-name"><?= e($a['name']) ?><?php if ($a['unit']): ?><span class="dim">, <?= e($a['unit']) ?></span><?php endif; ?></span>
        <?php if ($a['filterable']): ?><span class="tag">у фільтрах</span><?php endif; ?>
        <?php if (!$a['active']): ?><span class="tag tag-off">вимкнена</span><?php endif; ?>
        <?php /* Тип називаємо лише тоді, коли він не очевидний зі значень нижче */ ?>
        <?php if ($a['type'] !== 'select'): ?><span class="tag"><?= e(Attrs::TYPES[$a['type']] ?? $a['type']) ?></span><?php endif; ?>
      </div>

      <?php if (!$hasList): ?>
        <div class="attr-none">Значення набирають у картці товару — списку немає</div>
      <?php elseif (!$a['values']): ?>
        <div class="attr-none">Жодного значення — характеристику ще нема з чого обирати</div>
      <?php else: ?>
        <div class="attr-chips">
          <?php foreach ($shown as $v): ?>
            <span class="attr-chip">
              <?php if ($a['type'] === 'color' && $v['color']): ?>
                <i class="swatch" style="background:<?= e($v['color']) ?>"></i>
              <?php endif; ?><?= e($v['value']) ?>
            </span>
          <?php endforeach; ?>
          <?php if ($rest > 0): ?><span class="attr-chip attr-chip-rest">ще <?= $rest ?></span><?php endif; ?>
        </div>
      <?php endif; ?>

      <div class="attr-meta">
        <?php if ($a['category_ids']): ?>
          <?= e(implode(', ', array_map(fn($i) => $catName[$i] ?? '#' . $i, $a['category_ids']))) ?>
        <?php else: ?>усі категорії<?php endif; ?>
        <?php /* Нуль товарів — не дрібниця: характеристика заведена, але на
                 сайті її ще ніде не видно, і людина шукає, чому */ ?>
        · <?= $u['products'] ? 'у ' . (int)$u['products'] . ' товарах' : 'ще не використовується' ?><?php
        if ($u['variants']): ?> · фасовок: <?= (int)$u['variants'] ?><?php endif; ?>
      </div>
    </summary>

    <form method="post" action="<?= e(url('/admin/attributes')) ?>" class="attr-edit">
      <?= Csrf::field() ?><input type="hidden" name="id" value="<?= $aid ?>">
      <?php /* дія за замовчуванням: ✕ біля значення не несе _action, а Enter у полі не має нічого видаляти */ ?>
      <input type="hidden" name="_action" value="update">
      <button type="submit" class="submit-default" tabindex="-1" aria-hidden="true"></button>

      <div class="form-grid">
        <div class="field"><label>Назва</label><input type="text" name="name" value="<?= e($a['name']) ?>" required></div>
        <div class="field"><label>Тип</label>
          <select name="type"><?= $typeOptions((string)$a['type']) ?></select>
        </div>
        <div class="field"><label>Одиниця</label><input type="text" name="unit" value="<?= e($a['unit'] ?? '') ?>"></div>
        <div class="field"><label>Порядок</label><input type="number" name="sort" value="<?= (int)$a['sort'] ?>"></div>
      </div>

      <div class="field attr-flags">
        <label class="checkbox"><input type="checkbox" name="filterable" <?= $a['filterable'] ? 'checked' : '' ?>> У фільтрах магазину</label>
        <label class="checkbox"><input type="checkbox" name="active" <?= $a['active'] ? 'checked' : '' ?>> Активна (пропонувати у формі товару)</label>
      </div>

      <div class="field"><label>Категорії (нічого не обрано = для всіх)</label>
        <div class="pick-grid">
          <?php foreach ($categories as $c): ?>
            <label class="checkbox"><input type="checkbox" name="categories[]" value="<?= (int)$c['id'] ?>"
              <?= in_array((int)$c['id'], $a['category_ids'], true) ? 'checked' : '' ?>> <?= e(cat_label($c)) ?></label>
          <?php endforeach; ?>
        </div>
      </div>

      <?php if ($hasList): ?>
        <div class="field">
          <label>Значення</label>
          <?php /* Список значень, а не таблиця: колонка «Використань» на телефоні
                   їхала б за екран, а насправді це підпис до самого значення —
                   тому він стоїть під ним, а не в окремому стовпчику */ ?>
          <div class="val-list">
            <?php foreach ($a['values'] as $v): $vid = (int)$v['id']; $cnt = $val_usage[$vid] ?? 0; ?>
              <div class="val-row">
                <?php if ($a['type'] === 'color'): ?>
                  <input type="color" name="value_color[<?= $vid ?>]" value="<?= e($v['color'] ?: '#cccccc') ?>"
                         class="val-color" title="Зразок кольору у фільтрі">
                <?php endif; ?>
                <div class="val-main">
                  <input type="text" name="value[<?= $vid ?>]" value="<?= e($v['value']) ?>">
                  <span class="val-use<?= $cnt ? '' : ' is-none' ?>"><?= $cnt ? 'у ' . $cnt . ' позиціях' : 'не використовується' ?></span>
                </div>
                <button class="btn btn-danger btn-xs val-del" type="submit" name="delete_value_id" value="<?= $vid ?>"
                  title="Видалити значення"
                  onclick="return confirm(<?= e(json_encode('Видалити значення «' . $v['value'] . '»?' . ($cnt ? ' Воно зникне у ' . $cnt . ' товарів/варіантів.' : ''), JSON_UNESCAPED_UNICODE)) ?>)">✕</button>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="field"><label>Додати значення — по одному в рядок або через кому</label>
          <textarea name="new_values" rows="2" placeholder="Нове значення…"></textarea>
        </div>
      <?php else: ?>
        <p class="dim" style="margin-bottom:14px">Тип «<?= e(Attrs::TYPES[$a['type']]) ?>» — значення вводяться прямо у формі товару, список не потрібен.</p>
      <?php endif; ?>

      <div class="attr-acts">
        <button class="btn btn-gold btn-sm" type="submit">💾 Зберегти</button>
        <button class="btn btn-danger btn-sm" type="submit" name="_action" value="delete"
          onclick="return confirm(<?= e(json_encode('Видалити характеристику «' . $a['name'] . '» разом з усіма значеннями?'
            . ($u['products'] ? ' Вона зникне у ' . $u['products'] . ' товарів.' : '')
            . ($u['variants'] ? ' Торкнеться ' . $u['variants'] . ' варіантів.' : ''), JSON_UNESCAPED_UNICODE)) ?>)">Видалити характеристику</button>
      </div>
    </form>
  </details>
<?php endforeach; ?>
