<?php
/** @var array $products @var array $stores @var array $variants */
// Те саме, що перевіряє Products::bulk() на сервері: картку товару веде адмін,
// продавцю лишаються ціни й залишки його точок. Поле, яке все одно не збережеться,
// показувати не можна — інакше людина вводить число, бачить «Зміни збережено»
// і не здогадується, що воно нікуди не поділось.
$canCard = Auth::can('products.manage');
$canStore = fn(int $sid): bool => in_array($sid, Auth::storeIds(), true);
$roCard = $canCard ? '' : 'disabled';
?>
<div class="admin-head">
  <h1 class="h-serif">Масове редагування</h1>
  <?php /* лічильник тут, а не у формі: він не контрол, а відповідь на фільтр,
           і в ряду полів забирав місце, через яке кнопка «Знайти» падала
           на другий рядок */ ?>
  <span class="dim">Знайдено: <b><?= count($products) ?></b> · змінюйте будь-які значення і натисніть «Зберегти все»</span>
</div>
<?php if (!$canCard): ?>
  <div class="admin-card" style="border-color:var(--gold)">
    Назву, базову ціну й видимість товару редагує адміністратор.
    Вам доступні <b>ціни та залишки ваших магазинів</b> — решта колонок лише для перегляду.
  </div>
<?php endif; ?>
<?php /* Фільтр окремою формою (GET) — усередині форми збереження він став би
         її частиною й відправлявся б разом із цінами. */ ?>
<form class="admin-card bulk-filter" method="get" action="<?= e(url('/admin/products/bulk')) ?>">
  <div style="flex:1;min-width:130px" data-help-title="Пошук"
       data-help="Шукає за назвою товару й за артикулом одночасно. Досить частини слова.

Фільтр переживає збереження: після «Зберегти все» ви лишаєтесь у тій самій добірці.">
    <label>Пошук</label><input type="text" name="q" value="<?= e($f['q']) ?>" placeholder="Назва або артикул"></div>
  <div data-help-title="Категорія" data-help="Показати лише товари однієї категорії.">
    <label>Категорія</label>
    <select name="cat">
      <option value="">Всі</option>
      <?php foreach ($categories as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= $f['cat'] === (int)$c['id'] ? 'selected' : '' ?>><?= e(cat_label($c)) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php if ($brands): ?>
    <div data-help-title="Бренд" data-help="Показати товари одного виробника. Товар під кількома брендами знайдеться за кожним із них.">
      <label>Бренд</label>
      <select name="brand">
        <option value="">Всі</option>
        <?php foreach ($brands as $b): ?>
          <option value="<?= (int)$b['id'] ?>" <?= $f['brand'] === (int)$b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  <?php endif; ?>
  <?php if (count($all_stores) > 1): ?>
    <div data-help-title="Магазин"
         data-help="Лишає в таблиці колонки лише однієї точки — її ціну й залишок.

Це саме звуження показу: ціни й залишки інших магазинів нікуди не діваються й не зачіпаються при збереженні.

Разом із добіркою «Закінчились» питання стає точнішим: не «чого немає ніде», а «чого немає в цій точці».">
      <label>Магазин</label>
      <select name="store">
        <option value="">Усі магазини</option>
        <?php foreach ($all_stores as $s): ?>
          <option value="<?= (int)$s['id'] ?>" <?= $f['store'] === (int)$s['id'] ? 'selected' : '' ?>>
            <?= e($s['city'] ?: $s['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  <?php endif; ?>
  <div data-help-title="Показати"
       data-help="Готові добірки під те, заради чого сюди зазвичай заходять:

«Закінчились» — нічого немає в жодному магазині: саме їх треба поповнити після постачання.

«Без ціни» — порожня базова ціна: покупець бачить «За запитом».

«З варіантами» — товари, у яких ціна й залишок задаються по кожному варіанту окремо.">
    <label>Показати</label>
    <select name="only">
      <option value="">Усі товари</option>
      <option value="zero" <?= $f['only'] === 'zero' ? 'selected' : '' ?>>Закінчились</option>
      <option value="noprice" <?= $f['only'] === 'noprice' ? 'selected' : '' ?>>Без ціни</option>
      <option value="variants" <?= $f['only'] === 'variants' ? 'selected' : '' ?>>З варіантами</option>
    </select>
  </div>
  <button class="btn btn-gold btn-sm" type="submit">Знайти</button>
  <?php if ($f['q'] !== '' || $f['cat'] || $f['brand'] || $f['store'] || $f['only'] !== ''): ?>
    <a class="btn btn-line btn-sm" href="<?= e(url('/admin/products/bulk')) ?>">Скинути</a>
  <?php endif; ?>
</form>

<?php /* фільтр у дії форми: після збереження редірект має повернути ту саму добірку */ ?>
<form method="post" action="<?= e(url('/admin/products/bulk' . $query)) ?>">
  <?= Csrf::field() ?>
  <p class="dim" style="margin:0 0 12px">
    У товарів з варіантами ціна й залишок задаються окремим рядком для кожного варіанта — саме з них береться наявність у магазині.
  </p>
  <?php if (!$products): ?>
    <p class="dim">За цим фільтром нічого немає. <a href="<?= e(url('/admin/products/bulk')) ?>">Показати всі товари</a>.</p>
  <?php endif; ?>
  <table class="tbl tbl-bulk">
    <?php /* Двоповерхова шапка: «ціна» і «залишок» читаються як пара під назвою
             магазину, а не як чотири однакові стовпці поспіль. Разом із рамкою
             ліворуч кожної групи це знімає потребу щоразу зіставляти заголовок
             зі стовпцем. */ ?>
    <tr class="grp-head">
      <th rowspan="2" data-help-title="Колонка «Товар»"
          data-help="Назву можна правити прямо тут, не заходячи в картку.

Сірим під полем — категорія й кількість варіантів, якщо вони є.

Фото, опис, характеристики й самі варіанти тут не редагуються — для цього відкрийте картку товару в розділі «Товари».">Товар</th>
      <th rowspan="2" class="w-price num cell-money" data-help-title="Базова ціна"
          data-help="Ціна за замовчуванням для всіх магазинів.

Порожньо означає «За запитом»: покупець побачить не число, а пропозицію звʼязатися.

Її перебиває ціна конкретного магазину, задана у стовпцях праворуч.">Базова ціна</th>
      <?php foreach ($stores as $s): $lock = $canStore((int)$s['id']) ? '' : ' 🔒'; ?>
        <th colspan="2" class="grp-store"
            data-help-title="Магазин · <?= e($s['city'] ?: $s['name']) ?>"
            data-help="Дві колонки під назвою точки — це її власна ціна й залишок.

Ціна порожня (підказка «базова») — діє базова ціна товару, задана ліворуч. Акція накладається вже поверх.

Залишок — скільки штук зараз у цій точці. Від цих чисел залежить, якому магазину дістанеться замовлення.

Замок 🔒 означає, що магазин не ваш: значення видно, але правити його може лише його продавець або адміністратор."><?= e($s['city'] ?: $s['name']) . $lock ?></th>
      <?php endforeach; ?>
      <th rowspan="2" class="col-mid" data-help-title="Колонка «Активний»"
          data-help="Чи показувати товар на сайті.

Знята галка ховає його з каталогу й пошуку, але нічого не видаляє: ціни, залишки, фото й опис лишаються на місці.

Зміни застосуються лише після «Зберегти все» внизу сторінки.">Активний</th>
    </tr>
    <tr class="grp-sub">
      <?php foreach ($stores as $s): ?>
        <th class="w-price num cell-price">ціна</th>
        <th class="w-stock num cell-stock">залишок</th>
      <?php endforeach; ?>
    </tr>
    <?php foreach ($products as $p): $pid = (int)$p['id']; $vs = $variants[$pid] ?? []; ?>
      <tr class="row-product">
        <td>
          <input type="text" name="p[<?= $pid ?>][name]" value="<?= e($p['name']) ?>" style="min-width:220px" <?= $roCard ?>>
          <div class="dim"><?= e($p['cat_name'] ?? '') ?><?= $vs ? ' · варіантів: ' . count($vs) : '' ?></div>
        </td>
        <td class="num cell-money"><input type="number" step="0.01" name="p[<?= $pid ?>][base_price]" value="<?= e(num_val($p['base_price'])) ?>" placeholder="За запитом" <?= $roCard ?>></td>
        <?php foreach ($stores as $s): $sid = (int)$s['id']; $ro = $canStore($sid) ? '' : 'disabled title="Магазин не ваш — правити може лише його продавець або адмін"'; ?>
          <td class="num cell-price"><input type="number" step="0.01" name="p[<?= $pid ?>][store_price][<?= $sid ?>]"
                     value="<?= e(num_val($prices[$pid][$sid] ?? '')) ?>" placeholder="базова" <?= $ro ?>></td>
          <?php
          // «нуль» видно одразу кольором, а не після прочитання числа
          $stockVal = $vs ? null : ($stocks[$pid][$sid] ?? '');
          $zero = $vs ? false : ($stockVal === '' || (int)$stockVal === 0);
          ?>
          <td class="num cell-stock<?= $zero ? ' is-zero' : '' ?>">
            <?php if ($vs): $sum = 0; foreach ($vs as $v) $sum += (int)($vstocks[(int)$v['id']][$sid] ?? 0); ?>
              <span class="stock-sum" title="Сума по варіантах — редагуйте в рядках нижче">Σ <?= $sum ?></span>
            <?php else: ?>
              <input type="number" name="p[<?= $pid ?>][stock][<?= $sid ?>]" value="<?= e($stockVal) ?>" placeholder="0" <?= $ro ?>>
            <?php endif; ?>
          </td>
        <?php endforeach; ?>
        <td class="col-mid">
          <?php if ($canCard): ?>
            <input type="hidden" name="p[<?= $pid ?>][active]" value="0">
            <input type="checkbox" name="p[<?= $pid ?>][active]" value="1" <?= $p['active'] ? 'checked' : '' ?>>
          <?php else: ?>
            <span class="dim"><?= $p['active'] ? '✓' : '—' ?></span>
          <?php endif; ?>
        </td>
      </tr>
      <?php foreach ($vs as $v): $vid = (int)$v['id']; ?>
        <tr class="variant-sub">
          <?php /* стрілка ↳ була символом, якого немає в шрифті інтерфейсу, —
                   на екрані виходило «І,». Вкладеність малюємо рискою в CSS. */ ?>
          <td class="var-name muted"><?= e($v['name']) ?><?= $v['sku'] ? ' · ' . e($v['sku']) : '' ?></td>
          <?php /* Власна ціна варіанта. Була текстом — і виходило, що ціну
                   «Комбінезона» правити треба в картці товару, хоча решта
                   чисел цього ж рядка редагується тут. */ ?>
          <td class="num cell-money"><input type="number" step="0.01" name="p[<?= $pid ?>][vbase][<?= $vid ?>]"
                     value="<?= e(num_val($v['price'])) ?>" placeholder="базова"
                     title="Ціна саме цього варіанта. Порожньо — діє базова ціна товару" <?= $roCard ?>></td>
          <?php foreach ($stores as $s): $sid = (int)$s['id']; $ro = $canStore($sid) ? '' : 'disabled title="Магазин не ваш — правити може лише його продавець або адмін"'; ?>
            <td class="num cell-price"><input type="number" step="0.01" name="p[<?= $pid ?>][vprice][<?= $vid ?>][<?= $sid ?>]"
                       value="<?= e(num_val($vprices[$vid][$sid] ?? '')) ?>" placeholder="базова" <?= $ro ?>></td>
            <?php $vq = $vstocks[$vid][$sid] ?? ''; $vzero = $vq === '' || (int)$vq === 0; ?>
            <td class="num cell-stock<?= $vzero ? ' is-zero' : '' ?>"><input type="number" name="p[<?= $pid ?>][vstock][<?= $vid ?>][<?= $sid ?>]"
                       value="<?= e($vq) ?>" placeholder="0" <?= $ro ?>></td>
          <?php endforeach; ?>
          <td></td>
        </tr>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </table>
  <div class="admin-save">
    <button class="btn btn-gold" type="submit">💾 Зберегти все</button>
    <span class="admin-save-note"></span>
  </div>
</form>
