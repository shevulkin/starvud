<?php
/**
 * Склад набору: рядки «товар — фасовка — скільки штук».
 *
 * Спільний для створення й редагування: набір без товарів не має сенсу, тож
 * питати про них треба одразу, а не після збереження назви. Дві різні форми
 * для одного й того самого списку розійшлися б на першій же зміні.
 *
 * @var array $items    наявні позиції (може бути порожньо)
 * @var array $products товари для вибору
 * @var array $variants фасовки: [product_id => [{id,name}]]
 */
$items = $items ?? [];
$products = $products ?? [];
$variants = $variants ?? [];
// Менше за дві позиції в наборі не буває за визначенням — стільки рядків і
// показуємо новому. Решта додається кнопкою: скільки товарів у наборі, знає
// лише той, хто його складає.
$rows = array_values($items);
while (count($rows) < 2) $rows[] = ['product_id' => 0, 'variant_id' => null, 'qty' => 1];
?>
<div class="bundle-edit">
  <div class="row-list">
    <?php foreach ($rows as $i => $it): $pid = (int)($it['product_id'] ?? 0); ?>
      <div class="grid-row bundle-row">
        <select name="item[<?= $i ?>][product_id]" class="js-bundle-product" data-row="<?= $i ?>">
          <option value="0">— товар —</option>
          <?php foreach ($products as $p): ?>
            <option value="<?= (int)$p['id'] ?>"<?= $pid === (int)$p['id'] ? ' selected' : '' ?>><?= e($p['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="item[<?= $i ?>][variant_id]" class="js-bundle-variant" data-row="<?= $i ?>">
          <option value="0">будь-який варіант</option>
          <?php foreach ($variants[$pid] ?? [] as $v): ?>
            <option value="<?= (int)$v['id'] ?>"<?= (int)($it['variant_id'] ?? 0) === (int)$v['id'] ? ' selected' : '' ?>><?= e($v['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <input type="number" min="1" step="1" name="item[<?= $i ?>][qty]"
               value="<?= max(1, (int)($it['qty'] ?? 1)) ?>" title="Скільки штук цього товару входить у набір">
        <button class="btn btn-danger btn-xs bundle-row-del" type="button" title="Прибрати позицію">✕</button>
      </div>
    <?php endforeach; ?>
  </div>
  <button class="btn btn-line btn-sm bundle-item-add" type="button" style="margin-top:12px">+ Додати товар</button>
</div>
<?php
/*
 * Скрипт один на сторінку, скільки б складів на ній не було: другий екземпляр
 * навісив би обробники вдруге, і одне натискання додавало б два рядки.
 */
if (empty($GLOBALS['__bundle_items_js'])):
    $GLOBALS['__bundle_items_js'] = true;
?>
<script>
(function () {
  var VARIANTS = <?= json_js($variants) ?>;
  var PRODUCTS = <?= json_js(array_map(fn($p) => ['id' => (int)$p['id'], 'name' => (string)$p['name']], $products)) ?>;

  document.querySelectorAll('.bundle-edit').forEach(function (box) {
    var list = box.querySelector('.row-list');
    var add = box.querySelector('.bundle-item-add');
    if (!list) return;

    function fillVariants(sel) {
      var row = sel.closest('.bundle-row');
      var target = row ? row.querySelector('.js-bundle-variant') : null;
      if (!target) return;
      var items = VARIANTS[sel.value] || [];
      target.innerHTML = '<option value="0">будь-який варіант</option>';
      items.forEach(function (v) {
        var o = document.createElement('option');
        o.value = v.id; o.textContent = v.name;
        target.appendChild(o);
      });
    }

    function bind(row) {
      var sel = row.querySelector('.js-bundle-product');
      if (sel) sel.addEventListener('change', function () { fillVariants(sel); });
      var del = row.querySelector('.bundle-row-del');
      if (del) del.addEventListener('click', function () {
        /* Нижче двох рядків не опускаємось: набір з одного товару однаково не
           збережеться, і краще не давати зайти в стан, який відхилять. */
        if (list.children.length > 2) row.remove();
        else { sel.value = '0'; fillVariants(sel); }
      });
    }

    Array.prototype.forEach.call(list.children, bind);

    /* Номер нового рядка — більший за всі наявні, а не кількість рядків: після
       видалення посередині лічильник збігся б із живим рядком, і два товари
       приїхали б під одним імʼям. */
    function nextIndex() {
      var max = -1;
      list.querySelectorAll('[name^="item["]').forEach(function (i) {
        var m = i.name.match(/\[(\d+)\]/);
        if (m) max = Math.max(max, parseInt(m[1], 10));
      });
      return max + 1;
    }

    if (add) add.addEventListener('click', function () {
      var k = nextIndex();
      var opts = '<option value="0">— товар —</option>';
      PRODUCTS.forEach(function (p) { opts += '<option value="' + p.id + '">' + p.name + '</option>'; });
      var row = document.createElement('div');
      row.className = 'grid-row bundle-row';
      row.innerHTML =
        '<select name="item[' + k + '][product_id]" class="js-bundle-product" data-row="' + k + '">' + opts + '</select>' +
        '<select name="item[' + k + '][variant_id]" class="js-bundle-variant" data-row="' + k + '">' +
          '<option value="0">будь-який варіант</option></select>' +
        '<input type="number" min="1" step="1" name="item[' + k + '][qty]" value="1">' +
        '<button class="btn btn-danger btn-xs bundle-row-del" type="button" title="Прибрати позицію">✕</button>';
      list.appendChild(row);
      bind(row);
      row.querySelector('select').focus();
    });
  });
})();
</script>
<?php endif; ?>
