<?php
/**
 * Коди й штрихкоди.
 *
 * Екран заточений під одну роботу: пройтись товарами й проставити коди. Тому
 * тут фільтр («кому ще не проставив», «де помилка»), а не просто список: на
 * сотні позицій без нього шукати очима.
 *
 * Три способи заповнити — бо в житті трапляються всі три:
 *   • на товарі є фабрична етикетка — USB-сканер або 📷 камера в рядку;
 *   • етикетки немає (своя банка меду) — кнопка ✨ у рядку: створить власний код;
 *   • код відомий із накладної — просто ввести руками.
 *
 * Свої коди впізнаються з першого погляду: вони починаються з 200 і позначені
 * міткою «свій». Кожен можна одразу надрукувати наліпками.
 *
 * @var array $products @var array $variants @var array $dupes @var array $f @var string $query
 */
$dupSku = fn(?string $c) => $c !== null && in_array(mb_strtolower(trim((string)$c)), $dupes['sku'], true);
$dupBar = fn(?string $c) => $c !== null && in_array(mb_strtolower(trim((string)$c)), $dupes['barcode'], true);
$broken = 0;

/** Комірка штрихкоду: поле, кнопки (камера, згенерувати, друк), перевірка й малюнок */
$cell = function (string $kind, int $id, ?string $code, bool $dupe, string $title) use (&$broken): string {
    $code = trim((string)($code ?? ''));
    $problem = Barcode::problem($code);
    $fixed = Barcode::fixed($code);
    if ($problem !== null) $broken++;
    $ok = $code !== '' && $problem === null;
    $svg = $ok ? Barcode::svg($code) : '';

    $html = '<div class="code-cell" data-code-row="' . e($kind . $id) . '" data-code-title="' . e($title) . '">'
        . '<input type="text" class="code-input" name="' . e($kind) . '[' . $id . '][barcode]"'
        . ' value="' . e($code) . '" inputmode="numeric" autocomplete="off" data-code-input'
        . (($dupe || $problem !== null) ? ' style="border-color:var(--danger2)"' : '')
        . ($dupe ? ' title="Такий штрихкод уже є в іншої позиції"' : '') . '>'
        // Підказка висить на всій трійці, а не на кожній кнопці: у режимі підказок
        // діти помічених елементів клікаються наскрізь, тож пояснення однакове,
        // а в сторінку на сотні рядків не лягає три копії тексту замість однієї
        . '<span class="code-acts" data-help-title="Кнопки рядка"'
        . ' data-help="📷 — прочитати код камерою просто в це поле: коли сканера немає під рукою.'
        . "\n\n" . '✨ — створити власний код, якщо фабричної етикетки немає (товар без штрихкоду).'
        . ' Такі коди починаються з 200 — цей діапазон стандарт лишив магазинам для внутрішнього'
        . ' вжитку, тож із чужим товаром він не збіжиться. Поруч зʼявиться мітка «свій код».'
        . "\n\n" . '🖨 — аркуш наліпок із цим кодом: скільки штук, оберете вже у вікні друку.'
        . ' Працює для збереженого коду — малюнок наліпки малює сервер.">'
        . '<button type="button" class="btn btn-line btn-xs" data-code-scan title="Прочитати камерою">📷</button>'
        . '<button type="button" class="btn btn-line btn-xs" data-code-gen title="Створити власний код для цієї позиції">✨</button>'
        . '<button type="button" class="btn btn-line btn-xs" data-code-print title="Надрукувати наліпки"'
        . ($ok ? '' : ' disabled') . '>🖨</button>'
        . '</span>';

    if ($ok && Barcode::isInternal($code)) $html .= '<span class="code-tag">свій код</span>';
    if ($problem !== null) {
        $html .= '<div class="code-bad">' . e($problem)
            . ($fixed !== '' ? ' <button type="button" class="btn btn-line btn-xs" data-code-fix="'
                . e($fixed) . '">виправити</button>' : '')
            . '</div>';
    }
    return $html . ($svg ? '<div class="code-pic">' . $svg . '</div>' : '') . '</div>';
};
?>
<div class="admin-head"><h1 class="h-serif">Коди й штрихкоди</h1>
  <span class="dim">Станьте в поле й піднесіть сканер · 📷 камера · ✨ свій код · 🖨 наліпки</span>
</div>

<?php /* Фільтр окремою формою (GET) — усередині форми збереження він став би
         її частиною й відправлявся б разом із кодами */ ?>
<form class="admin-card bulk-filter" method="get" action="<?= e(url('/admin/products/codes')) ?>">
  <div style="flex:1;min-width:150px" data-help-title="Пошук"
       data-help="Шукає за назвою товару, назвою фасовки, артикулом і штрихкодом одночасно.

Найшвидший спосіб знайти потрібну позицію: станьте в це поле й піднесіть сканер до етикетки — якщо код уже комусь проставлений, ви одразу побачите кому.">
    <label>Пошук</label><input type="text" name="q" value="<?= e($f['q']) ?>" placeholder="Назва, артикул або код"></div>
  <div data-help-title="Категорія" data-help="Показати лише товари однієї категорії — зручно проставляти коди партіями.">
    <label>Категорія</label>
    <select name="cat">
      <option value="">Всі</option>
      <?php foreach ($categories as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= $f['cat'] === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div data-help-title="Показати"
       data-help="Готові добірки під те, заради чого сюди заходять:

«Без штрихкоду» — кому ще не проставлено. Головна добірка: саме її проходять із товаром у руках.

«З помилкою» — код зі збитою контрольною цифрою або однаковий у двох позицій. Такий код виглядає нормально, але каса його не знайде.

«Свої коди» — ті, що ви створили кнопкою ✨ (починаються з 200). Їх треба надрукувати й наклеїти.">
    <label>Показати</label>
    <select name="only">
      <option value="">Усі позиції</option>
      <option value="nocode" <?= $f['only'] === 'nocode' ? 'selected' : '' ?>>Без штрихкоду</option>
      <option value="code" <?= $f['only'] === 'code' ? 'selected' : '' ?>>Зі штрихкодом</option>
      <option value="bad" <?= $f['only'] === 'bad' ? 'selected' : '' ?>>З помилкою</option>
      <option value="own" <?= $f['only'] === 'own' ? 'selected' : '' ?>>Свої коди</option>
    </select>
  </div>
  <button class="btn btn-gold btn-sm" type="submit">Знайти</button>
  <?php if ($f['q'] !== '' || $f['cat'] || $f['only'] !== ''): ?>
    <a class="btn btn-line btn-sm" href="<?= e(url('/admin/products/codes')) ?>">Скинути</a>
  <?php endif; ?>
</form>

<?php if ($dupes['sku'] || $dupes['barcode']): ?>
  <div class="flash" style="padding:0;margin:0 0 16px"><div class="flash-error">
    Є однакові коди — вони підсвічені. Каса щоразу братиме той товар, який трапиться першим,
    і продавець цього не помітить: у чек тихо ляже не та позиція.
  </div></div>
<?php endif; ?>

<?php /* Таблицю збираємо в буфер: скільки кодів зі збитою контрольною цифрою,
         стає відомо лише під час відмальовування, а попередити треба зверху */ ?>
<?php ob_start(); ?>
<form method="post" action="<?= e(url('/admin/products/codes' . $query)) ?>">
  <?= Csrf::field() ?>
  <?php if (!$products): ?>
    <p class="dim">За цим фільтром нічого немає.
      <a href="<?= e(url('/admin/products/codes')) ?>">Показати всі позиції</a>.</p>
  <?php endif; ?>
  <?php /* data-lbl на комірках — підпис колонки на вузькому екрані: там рядок
           розгортається карткою, шапка ховається, і без підпису «Артикул» і
           «Штрихкод» стають двома однаковими порожніми полями (див. app.css) */ ?>
  <table class="tbl tbl-codes">
    <tr>
      <th data-help-title="Товар"
          data-help="Позиція, на яку клеять етикетку. У товару з кількома фасовками код належить саме фасовці: етикетка живе на банці, а не на «меді взагалі», — тому рядок товару тоді порожній, а поля стоять у фасовках під ним.

Назва — посилання на картку товару: там фото, опис, ціни й залишки.">Товар</th>
      <th style="width:170px" data-help-title="Артикул"
          data-help="Ваш код для обліку: «PZL-FARM-01». Пошук в адмінці й на касі знаходить товар і за ним.

Якщо ведете склад у таблиці чи 1С — ставте той самий код, що й там. Штрихкод це не замінює: сканер читає саме штрихкод.">Артикул</th>
      <th data-help-title="Штрихкод"
          data-help="Код із етикетки (EAN-13 — 13 цифр). Саме його читає сканер: продавець підносить сканер до банки, і позиція сама лягає в чек. Артикул його не замінює.

Три способи заповнити: піднести сканер, стоячи в полі; ввести руками з накладної; натиснути кнопку в рядку — про них клацніть на самі кнопки.

Остання цифра штрихкоду рахується з попередніх, тож описка одразу видно: поле червоніє й поруч пишеться, якою цифра має бути.">Штрихкод</th>
    </tr>
    <?php foreach ($products as $p): $pid = (int)$p['id']; $vs = $variants[$pid] ?? [];
          $hasCode = trim((string)($p['barcode'] ?? '')) !== '';
          $deadCode = $hasCode && count($vs) > 1; ?>
      <tr class="row-product">
        <td>
          <a href="<?= e(url('/admin/products/' . $pid)) ?>"><?= e($p['name']) ?></a>
          <div class="dim"><?= e($p['cat_name'] ?? '') ?><?= $p['active'] ? '' : ' · вимкнений' ?></div>
        </td>
        <?php if (!empty($p['header_only'])): ?>
          <?php /* Знайшлися фасовки, а не сам товар — його рядок лише заголовок */ ?>
          <td colspan="2" class="dim">↓</td>
        <?php elseif ($vs && !$hasCode): ?>
          <?php /* Кілька фасовок — код належить кожній окремо; порожнє поле
                   товару лише збивало б з пантелику */ ?>
          <td colspan="2" class="dim">коди — у фасовках нижче<?= count($vs) === 1 ? ' (фасовка одна, тож і код товару спрацює)' : '' ?></td>
        <?php else: ?>
          <td data-lbl="Артикул"><input type="text" name="p[<?= $pid ?>][sku]" value="<?= e($p['sku'] ?? '') ?>" autocomplete="off"
                     <?= $dupSku($p['sku']) ? 'style="border-color:var(--danger2)" title="Такий артикул уже є в іншого товару"' : '' ?>></td>
          <td data-lbl="Штрихкод">
            <?= $cell('p', $pid, $p['barcode'], $dupBar($p['barcode']), (string)$p['name']) ?>
            <?php if ($deadCode): ?>
              <div class="code-bad">Код записаний на товарі, а фасовок <?= count($vs) ?> — сканер не знатиме, яку класти в чек.
                <button type="button" class="btn btn-line btn-xs" data-code-move>перенести в першу фасовку</button></div>
            <?php endif; ?>
          </td>
        <?php endif; ?>
      </tr>
      <?php foreach ($vs as $v): $vid = (int)$v['id']; ?>
        <tr class="variant-sub">
          <td class="var-name muted"><?= e($v['name']) ?><?= $v['active'] ? '' : ' · вимкнена' ?></td>
          <td data-lbl="Артикул"><input type="text" name="v[<?= $vid ?>][sku]" value="<?= e($v['sku'] ?? '') ?>" autocomplete="off"
                     <?= $dupSku($v['sku']) ? 'style="border-color:var(--danger2)" title="Такий артикул уже є в іншої позиції"' : '' ?>></td>
          <td data-lbl="Штрихкод"><?= $cell('v', $vid, $v['barcode'], $dupBar($v['barcode']), $p['name'] . ', ' . $v['name']) ?></td>
        </tr>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </table>
  <div class="admin-save">
    <button class="btn btn-gold" type="submit">💾 Зберегти коди</button>
    <span class="admin-save-note"></span>
  </div>
</form>
<?php
$table = ob_get_clean();
if ($broken):
?>
  <div class="flash" style="padding:0;margin:0 0 16px"><div class="flash-error">
    <b>Кодів зі збитою контрольною цифрою: <?= (int)$broken ?>.</b>
    Остання цифра штрихкоду рахується з попередніх — саме щоб описку було видно.
    Такий код виглядає правильним, але сканер не знайде його ніколи.
    Біля кожного написано, яким він має бути, і є кнопка «виправити».
  </div></div>
<?php endif; ?>
<?= $table ?>

<script src="<?= e(asset_v('js/barcode.js')) ?>" defer></script>
<script src="<?= e(asset_v('js/scan.js')) ?>" defer></script>
<script>
window.CODES = { genUrl: '<?= e(url('/admin/products/codes')) ?>' };
</script>
<script src="<?= e(asset_v('js/codes.js')) ?>" defer></script>
