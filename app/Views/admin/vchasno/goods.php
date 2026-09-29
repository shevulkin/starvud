<div class="admin-head">
  <h1 class="h-serif">Товари у «Вчасно.Касі»</h1>
  <a class="btn btn-line btn-sm" href="<?= e(url('/admin/vchasno')) ?>">← Каса</a>
</div>

<p class="card-lead" style="margin:-14px 0 22px">
  У чек ми передаємо назву, ціну, податкову групу й <b>коди</b> — артикул і штрихкод.
  Саме коди зшивають наш товар із товаром у їхньому кабінеті: без них звіт покаже
  «Мед липовий 0.5» і «Мед лип. 0,5л» як два різні рядки.
  API для номенклатури в них немає, тож обмін іде файлом — так само, як у самому кабінеті.
</p>

<div class="admin-card">
  <h2 class="h-serif">Цінник якої точки звіряємо</h2>
  <p class="dim" style="margin:-6px 0 12px">
    Ціна й податкова група в кожної точки свої, тож «розбіжність» без вказаної точки
    нічого не означала б. Без вибору беремо базові ціни каталогу.
  </p>
  <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
    <div>
      <select name="store" onchange="this.form.submit()">
        <option value="0">базові ціни каталогу</option>
        <?php foreach ($stores as $s): ?>
          <option value="<?= (int)$s['id'] ?>"<?= (int)$store_id === (int)$s['id'] ? ' selected' : '' ?>>
            <?= e($s['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <noscript><button class="btn btn-line btn-sm" type="submit">Показати</button></noscript>
  </form>
</div>

<div class="admin-card" style="margin-top:18px">
  <h2 class="h-serif">Наш каталог → їхній кабінет</h2>
  <p class="dim" style="margin:-6px 0 12px">
    Файл із нашими товарами у форматі, який приймає імпорт кабінету: рядок на кожну
    <b>фасовку</b> (каса продає банку, а не «мед узагалі»). Податкова група тут завжди
    заповнена — порожня клітинка в їхньому файлі означає не «як у магазину», а «без ставки».
  </p>
  <form method="post" style="display:flex;gap:8px;flex-wrap:wrap">
    <?= Csrf::field() ?>
    <input type="hidden" name="_action" value="export">
    <input type="hidden" name="store_id" value="<?= (int)$store_id ?>">
    <button class="btn btn-gold btn-sm" type="submit" name="format" value="xlsx">⬇ Вивантажити XLSX</button>
    <button class="btn btn-line btn-sm" type="submit" name="format" value="csv">⬇ CSV</button>
  </form>
</div>

<div class="admin-card" style="margin-top:18px">
  <h2 class="h-serif">Їхній кабінет → звірка</h2>
  <p class="dim" style="margin:-6px 0 12px">
    У кабінеті «Вчасно.Каси»: <b>Товари → Дії → Експорт</b>. Збережіть вивантаження у форматі
    <b>CSV</b> і завантажте сюди — ми зіставимо його з нашим каталогом. Колонки шукаємо за
    підписами, тож порядок їхніх стовпців значення не має.
  </p>
  <form method="post" enctype="multipart/form-data" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    <?= Csrf::field() ?>
    <input type="hidden" name="_action" value="upload">
    <input type="hidden" name="store_id" value="<?= (int)$store_id ?>">
    <span class="file-field">
      <input type="file" name="file" accept=".csv">
      <button class="btn btn-line btn-xs" type="button" data-file-btn>Обрати файл</button>
      <span class="file-name" data-empty="Файл не обрано">Файл не обрано</span>
    </span>
    <button class="btn btn-gold btn-sm" type="submit">Звірити</button>
  </form>
  <?php if ($has_file): ?>
    <p class="dim" style="margin:10px 0 0;font-size:12.5px">
      Файл: <b><?= e($file_name) ?></b>
      <?php if ($parsed && $parsed['error'] === ''): ?>
        · позицій <?= count($parsed['goods']) ?>
        · знайдені колонки: <?= e(implode(', ', $parsed['columns'])) ?>
      <?php endif; ?>
    </p>
    <form method="post" style="margin-top:8px">
      <?= Csrf::field() ?>
      <input type="hidden" name="_action" value="forget">
      <button class="btn btn-line btn-xs" type="submit">прибрати файл</button>
    </form>
  <?php endif; ?>
  <?php if ($parsed && $parsed['error'] !== ''): ?>
    <p class="dim" style="margin:10px 0 0;color:var(--warn,#f0b429)"><?= e($parsed['error']) ?></p>
  <?php endif; ?>
</div>

<?php if ($report): $st = $report['stats']; ?>
  <div class="admin-card" style="margin-top:18px">
    <h2 class="h-serif">Що вийшло</h2>
    <div style="display:flex;gap:24px;flex-wrap:wrap;margin:6px 0 14px">
      <div><div class="dim" style="font-size:12.5px">Збіглося повністю</div><b><?= (int)$st['same'] ?></b></div>
      <div><div class="dim" style="font-size:12.5px">Є розбіжності</div><b><?= (int)$st['differs'] ?></b></div>
      <div><div class="dim" style="font-size:12.5px">Лише в нас</div><b><?= (int)$st['only_ours'] ?></b></div>
      <div><div class="dim" style="font-size:12.5px">Лише в них</div><b><?= (int)$st['only_theirs'] ?></b></div>
    </div>

    <p class="dim" style="font-size:12.5px">
      Зіставляємо за штрихкодом, потім за артикулом, і лише в останню чергу за назвою.
      Збіг за назвою позначено окремо: «Мед 0.5» буває і липовий, і гречаний, тому на такій
      підставі коди ми не переносимо.
      <?php if ((int)$st['weak'] > 0): ?>
        Таких тут <b><?= (int)$st['weak'] ?></b>.
      <?php endif; ?>
    </p>

    <?php if ((int)$st['differs'] > 0): ?>
      <form method="post" style="margin-top:12px">
        <?= Csrf::field() ?>
        <input type="hidden" name="_action" value="apply">
        <input type="hidden" name="store_id" value="<?= (int)$store_id ?>">
        <button class="btn btn-gold btn-sm" type="submit"
                data-help-title="Перенести до себе"
                data-help="Заповнює в наших товарах ПОРОЖНІ поля тим, що знайшлося в їхньому файлі: артикул, штрихкод, податкову групу, УКТЗЕД.

Заповнене не чіпає — навіть якщо воно розходиться. У нас теж є артикули, і мовчазна заміна нашого коду чужим — найкоротший шлях до товару, який більше не знаходить сканер. Розбіжності лишаться в таблиці нижче, і вирішувати їх вам.

Ціни не переносяться взагалі: ціна — рішення магазину, а не запис у чужому довіднику.">
          ↓ Перенести до себе порожні поля</button>
      </form>
    <?php endif; ?>
  </div>

  <div class="admin-card" style="margin-top:18px">
    <table class="tbl">
      <tr>
        <th>Позиція</th>
        <th>Збіг</th>
        <th>У нас</th>
        <th>У них</th>
        <th>Розбіжність</th>
      </tr>
      <?php foreach ($report['rows'] as $r): ?>
        <?php if ($r['state'] === 'same') continue; ?>
        <tr>
          <td>
            <?php if ($r['ours']): ?>
              <a href="<?= e(url('/admin/products/' . (int)$r['ours']['product_id'])) ?>"><?= e($r['ours']['name']) ?></a>
            <?php else: ?>
              <span class="dim"><?= e($r['theirs']['name']) ?></span>
            <?php endif; ?>
          </td>
          <td class="dim">
            <?= $r['state'] === 'only_ours' ? 'немає в них'
              : ($r['state'] === 'only_theirs' ? 'немає в нас' : e($r['match'])) ?>
          </td>
          <td class="dim" style="font-size:12.5px">
            <?php if ($r['ours']): ?>
              <?= e(price_fmt($r['ours']['price'])) ?>
              <?php if ($r['ours']['barcode'] !== ''): ?><br>ШК <?= e($r['ours']['barcode']) ?><?php endif; ?>
              <?php if ($r['ours']['sku'] !== ''): ?><br>арт. <?= e($r['ours']['sku']) ?><?php endif; ?>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td class="dim" style="font-size:12.5px">
            <?php if ($r['theirs']): ?>
              <?= $r['theirs']['price'] !== null ? e(price_fmt($r['theirs']['price'])) : '—' ?>
              <?php if ($r['theirs']['barcode'] !== ''): ?><br>ШК <?= e($r['theirs']['barcode']) ?><?php endif; ?>
              <?php if ($r['theirs']['sku'] !== ''): ?><br>арт. <?= e($r['theirs']['sku']) ?><?php endif; ?>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td class="dim" style="font-size:12.5px">
            <?php foreach ($r['diff'] as $field => [$mine, $their]): ?>
              <?php
                $label = ['price' => 'ціна', 'taxgrp' => 'податкова група',
                          'barcode' => 'штрихкод', 'sku' => 'артикул'][$field] ?? $field;
                $fmt = static fn($v) => $field === 'price' ? price_fmt((float)$v)
                    : ($field === 'taxgrp' ? ($v . ' — ' . ($tax_groups[(int)$v] ?? '?')) : ($v === '' ? 'порожньо' : (string)$v));
              ?>
              <?= e($label) ?>: <?= e($fmt($mine)) ?> → <?= e($fmt($their)) ?><br>
            <?php endforeach; ?>
            <?php if ($r['state'] === 'only_ours'): ?>
              <span>немає в кабінеті — вивантажте наш файл і завантажте його туди</span>
            <?php elseif ($r['state'] === 'only_theirs'): ?>
              <span>є в кабінеті, немає в нас — заведіть товар або ігноруйте, якщо він застарілий</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
    <?php if ((int)$st['same'] > 0): ?>
      <p class="dim" style="margin:12px 0 0;font-size:12.5px">
        Позиції, що збіглися повністю (<?= (int)$st['same'] ?>), у таблиці не показані — з ними нічого робити.
      </p>
    <?php endif; ?>
  </div>
<?php endif; ?>
