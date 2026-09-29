<?php
/**
 * Рахунок на оплату / видаткова накладна.
 *
 * Сторінка без адмінського обрамлення: її відкривають, щоб надрукувати або
 * зберегти в PDF засобами браузера. Тому все оформлення тут своє й чорно-біле —
 * бланк має однаково читатись на екрані й на папері, і не зʼїдати тонер.
 */
?><!DOCTYPE html>
<html lang="uk">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($page_title) ?></title>
<style>
  /* Бланк друкується, тож одиниці — міліметри, а кольори чорні. */
  body{font:13px/1.45 "DejaVu Sans","Segoe UI",Arial,sans-serif;color:#000;background:#fff;
       margin:0;padding:14mm 12mm}
  h1{font-size:19px;margin:0 0 2mm}
  .muted{color:#555}
  .side{display:flex;justify-content:space-between;gap:8mm;flex-wrap:wrap}
  .party{margin-bottom:5mm}
  .party b{display:block;font-size:13px}
  .party div{margin-top:1mm}
  table{width:100%;border-collapse:collapse;margin-top:5mm}
  th,td{border:1px solid #000;padding:1.6mm 2mm;text-align:left;vertical-align:top}
  th{background:#eee;font-weight:600}
  td.num,th.num{text-align:right;white-space:nowrap}
  tfoot td{border:0;padding-top:2mm}
  tfoot .total{font-size:15px;font-weight:700}
  .words{margin-top:3mm}
  .sign{margin-top:12mm;display:flex;justify-content:space-between;gap:10mm}
  .sign div{flex:1}
  .line{border-bottom:1px solid #000;height:9mm}
  .note{margin-top:6mm;padding:3mm;border:1px solid #000}
  .toolbar{margin-bottom:6mm;display:flex;gap:8px;flex-wrap:wrap}
  .btn{display:inline-block;padding:6px 14px;border:1px solid #000;background:#fff;
       color:#000;text-decoration:none;cursor:pointer;font:inherit}
  /* Друкуємо лише документ: кнопки й попередження на папері ні до чого */
  @media print{ .toolbar,.screen-only{display:none} body{padding:0} }
</style>
</head>
<body>

<div class="toolbar screen-only">
  <button class="btn" type="button" onclick="window.print()">🖨 Друк / зберегти PDF</button>
  <a class="btn" href="<?= e(url('/admin/orders/' . (int)$parent['id'])) ?>">← До замовлення</a>
  <?php if ($kind === 'inv'): ?>
    <a class="btn" href="<?= e(url('/admin/orders/' . (int)$parent['id'] . '/invoice?part=' . (int)$child['id'] . '&kind=act')) ?>">Видаткова накладна</a>
  <?php else: ?>
    <a class="btn" href="<?= e(url('/admin/orders/' . (int)$parent['id'] . '/invoice?part=' . (int)$child['id'])) ?>">Рахунок на оплату</a>
  <?php endif; ?>
</div>

<?php if ($gaps): ?>
  <div class="note screen-only">
    <b>Документ неповний.</b> Не заповнено:
    <?= e(implode('; ', $gaps)) ?>.
    <?php /* Друкувати не забороняємо: іноді реквізити дописують ручкою, і
             краще віддати бланк, ніж не віддати нічого. Але сказати про це
             треба до того, як його віддали покупцю. */ ?>
    <br>Заповнюється в розділі <b>Мережа → Власники</b>.
  </div>
<?php endif; ?>

<h1><?= $kind === 'inv' ? 'Рахунок на оплату' : 'Видаткова накладна' ?> № <?= e($doc['number']) ?></h1>
<div class="muted">від <?= e($doc['date']) ?></div>

<div class="side" style="margin-top:6mm">
  <div class="party" style="flex:1;min-width:70mm">
    <b>Постачальник</b>
    <div><?= e($doc['seller']['name'] ?: '—') ?></div>
    <?php if ($doc['seller']['tax_id']): ?><div>ІПН / ЄДРПОУ: <?= e($doc['seller']['tax_id']) ?></div><?php endif; ?>
    <?php if ($doc['seller']['address']): ?><div><?= e($doc['seller']['address']) ?></div><?php endif; ?>
    <?php if ($doc['seller']['iban']): ?><div>IBAN: <b><?= e($doc['seller']['iban']) ?></b></div><?php endif; ?>
    <?php if ($doc['seller']['bank']): ?><div>Банк: <?= e($doc['seller']['bank']) ?></div><?php endif; ?>
    <div><?= $doc['seller']['vat'] ? 'Платник ПДВ' : 'Не платник ПДВ' ?></div>
  </div>
  <div class="party" style="flex:1;min-width:70mm">
    <b>Покупець</b>
    <div><?= e($doc['buyer']['name'] ?: '—') ?></div>
    <?php if ($doc['buyer']['tax_id']): ?><div>ІПН / ЄДРПОУ: <?= e($doc['buyer']['tax_id']) ?></div><?php endif; ?>
    <?php if ($doc['buyer']['type']): ?><div class="muted"><?= e(Invoice::buyerLabel($doc['buyer']['type'])) ?></div><?php endif; ?>
    <?php if ($doc['buyer']['phone']): ?><div>тел. <?= e($doc['buyer']['phone']) ?></div><?php endif; ?>
  </div>
</div>

<table>
  <thead>
    <tr>
      <th style="width:8mm">№</th>
      <th>Назва товару</th>
      <th style="width:16mm">Од.</th>
      <th class="num" style="width:18mm">К-сть</th>
      <th class="num" style="width:26mm">Ціна</th>
      <th class="num" style="width:30mm">Сума</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($doc['rows'] as $r): ?>
      <tr>
        <td><?= (int)$r['no'] ?></td>
        <td><?= e($r['title']) ?></td>
        <td><?= e($r['unit']) ?></td>
        <td class="num"><?= (int)$r['qty'] ?></td>
        <td class="num"><?= e(number_format($r['price'], 2, ',', ' ')) ?></td>
        <td class="num"><?= e(number_format($r['sum'], 2, ',', ' ')) ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody>
  <tfoot>
    <?php if ($doc['discount'] > 0): ?>
      <tr>
        <td colspan="5" class="num">Разом:</td>
        <td class="num"><?= e(number_format($doc['subtotal'], 2, ',', ' ')) ?></td>
      </tr>
      <tr>
        <td colspan="5" class="num">Знижка:</td>
        <td class="num">−<?= e(number_format($doc['discount'], 2, ',', ' ')) ?></td>
      </tr>
    <?php endif; ?>
    <tr>
      <td colspan="5" class="num total">До сплати:</td>
      <td class="num total"><?= e(number_format($doc['total'], 2, ',', ' ')) ?></td>
    </tr>
  </tfoot>
</table>

<div class="words">
  Усього на суму: <b><?= e($words) ?></b>
  <?= $doc['seller']['vat'] ? '' : ', без ПДВ' ?>
</div>

<?php if ($kind === 'inv'): ?>
  <div class="muted" style="margin-top:4mm">
    У призначенні платежу вкажіть: <b>Оплата за рахунком № <?= e($doc['number']) ?></b>.
  </div>
<?php endif; ?>

<div class="sign">
  <div>
    <div class="line"></div>
    <div class="muted"><?= $kind === 'inv' ? 'Виписав(ла)' : 'Відпустив(ла)' ?><?php
      if ($doc['seller']['signer']): ?> — <?= e($doc['seller']['signer']) ?><?php endif; ?></div>
  </div>
  <?php if ($kind === 'act'): ?>
    <div>
      <div class="line"></div>
      <div class="muted">Отримав(ла)</div>
    </div>
  <?php endif; ?>
</div>

</body>
</html>
