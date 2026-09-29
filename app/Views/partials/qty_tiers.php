<?php
/**
 * Редактор оптової шкали. Спільний для трьох рівнів — товару, розділу й
 * загальних налаштувань: форма в них однакова, різниця лише в тому, чий
 * рівень зараз редагують.
 *
 * Рядки додаються кнопкою, а не стоять порожніми про запас. Скільки порогів
 * потрібно, знає лише той, хто складає шкалу: три — це вгадування, яке одному
 * лишає зайве, а іншому не дає дописати четвертий, не зберігши спершу.
 *
 * @var array  $tiers шкала цього рівня (може бути порожня)
 * @var string $name  імʼя поля: $name[0][min_qty], $name[0][percent]
 * @var string $ro    'disabled' для того, хто дивиться без права правити
 */
$tiers = $tiers ?? [];
$name = $name ?? 'tier';
$ro = $ro ?? '';
// Один порожній рядок для шкали, якої ще немає: інакше перше, що бачить
// людина, — таблиця з самим лише заголовком і кнопкою
$rows = array_values($tiers);
if (!$rows) $rows[] = ['min_qty' => '', 'percent' => ''];
?>
<div class="qty-tier-edit" data-name="<?= e($name) ?>"<?= $ro ? ' data-ro="1"' : '' ?>>
  <table class="tbl qty-tiers">
    <tr>
      <th style="width:45%">Від скількох штук</th>
      <th>Знижка, %</th>
      <th class="col-mid"></th>
    </tr>
    <tbody class="qty-tier-rows">
      <?php foreach ($rows as $i => $t): ?>
        <tr>
          <td>
            <input type="number" min="<?= QtyDiscounts::MIN_QTY ?>" step="1"
                   name="<?= e($name) ?>[<?= $i ?>][min_qty]"
                   value="<?= e((string)($t['min_qty'] ?? '')) ?>"
                   placeholder="кількість, шт." <?= $ro ?>>
          </td>
          <td>
            <input type="text" name="<?= e($name) ?>[<?= $i ?>][percent]"
                   value="<?= ($t['percent'] ?? '') === '' || $t['percent'] === null ? '' : e(QtyDiscounts::pctExact((float)$t['percent'])) ?>"
                   placeholder="знижка, %" inputmode="decimal" <?= $ro ?>>
          </td>
          <td class="col-mid">
            <?php if (!$ro): ?>
              <button class="btn btn-danger btn-xs qty-tier-del" type="button" title="Прибрати поріг">✕</button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php if (!$ro): ?>
    <button class="btn btn-line btn-sm qty-tier-add" type="button" style="margin-top:10px">+ Додати поріг</button>
  <?php endif; ?>
  <p class="dim" style="margin:8px 0 0;font-size:13px">
    Приклад: від 3 шт — 5%, від 10 шт — 10%. Що більша кількість, то більшою має бути знижка.
    Прибраний рядок зникає після збереження.
  </p>
</div>
<?php
/*
 * Скрипт один на сторінку, скільки б редакторів на ній не було: другий
 * екземпляр навісив би обробники вдруге, і одне натискання додавало б два
 * рядки. View::partial у циклі — річ звичайна, тож захист тут, а не в
 * дисципліні викликів.
 */
if (empty($GLOBALS['__qty_tiers_js'])):
    $GLOBALS['__qty_tiers_js'] = true;
?>
<script>
(function () {
  document.querySelectorAll('.qty-tier-edit').forEach(function (box) {
    if (box.dataset.ro) return;
    var name = box.dataset.name;
    var body = box.querySelector('.qty-tier-rows');

    /* Номер нового рядка беремо більший за всі наявні, а не за їх кількістю:
       після видалення посередині лічильник збігся б із живим рядком, і
       браузер надіслав би два значення під одним імʼям. */
    function nextIndex() {
      var max = -1;
      body.querySelectorAll('input[name]').forEach(function (i) {
        var m = i.name.match(/\[(\d+)\]/);
        if (m) max = Math.max(max, parseInt(m[1], 10));
      });
      return max + 1;
    }

    function bindDelete(tr) {
      var b = tr.querySelector('.qty-tier-del');
      if (!b) return;
      b.addEventListener('click', function () {
        /* Останній рядок не видаляємо, а очищаємо: таблиця з самим
           заголовком виглядає як поломка, а порожній рівень — це те саме. */
        if (body.rows.length > 1) tr.remove();
        else tr.querySelectorAll('input').forEach(function (i) { i.value = ''; });
      });
    }

    body.querySelectorAll('tr').forEach(bindDelete);

    box.querySelector('.qty-tier-add').addEventListener('click', function () {
      var k = nextIndex();
      var tr = document.createElement('tr');
      tr.innerHTML =
        '<td><input type="number" min="<?= QtyDiscounts::MIN_QTY ?>" step="1" name="' + name + '[' + k + '][min_qty]" placeholder="кількість, шт."></td>' +
        '<td><input type="text" name="' + name + '[' + k + '][percent]" placeholder="знижка, %" inputmode="decimal"></td>' +
        '<td class="col-mid"><button class="btn btn-danger btn-xs qty-tier-del" type="button" title="Прибрати поріг">✕</button></td>';
      body.appendChild(tr);
      bindDelete(tr);
      tr.querySelector('input').focus();
    });
  });
})();
</script>
<?php endif; ?>
