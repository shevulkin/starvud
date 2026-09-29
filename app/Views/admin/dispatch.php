<?php
/**
 * @var array $orders   замовлення, що чекають розподілу (найстаріші вгорі)
 * @var array $children підзамовлення кожного: [order_id => [частини]]
 * @var array $hints    підказки по позиціях: залишки по точках і розбіжність цін
 * @var array $statuses назви статусів
 */
?>
<div class="admin-head">
  <h1 class="h-serif">Звідки відправити замовлення</h1>
  <?php if ($orders): ?>
    <span class="dim"><?= e(plural_n(count($orders), 'замовлення чекає', 'замовлення чекають', 'замовлень чекають')) ?></span>
  <?php endif; ?>
</div>

<?php if (!$orders): ?>
  <div class="admin-card">
    <p style="margin:0">Черга порожня — усі онлайн-замовлення розподілені.</p>
    <p class="dim" style="margin:8px 0 0">
      Сюди потрапляють замовлення з сайту, які треба везти. Самовивіз не розподіляється —
      магазин обрав сам покупець; продажі з каси теж: там точка відома одразу.
    </p>
  </div>
<?php else: ?>
  <?php /* Пояснення один раз угорі, а не в кожній картці: воно про правила
           роботи загалом, і повторене шість разів перетворюється на шум. */ ?>
  <div class="admin-card">
    <p class="card-lead" style="margin:0">
      Магазин для кожної позиції вже обрано <b>за залишком</b> — і саме це тримає резерв складу
      з моменту оформлення. Ваша робота: переглянути й перекласти там, де автомат не міг знати
      про пакувальника, відстань чи побажання покупця. Якщо все влаштовує — просто підтвердьте.
    </p>
  </div>

  <?php foreach ($orders as $o): $oid = (int)$o['id']; $kids = $children[$oid] ?? []; ?>
    <div class="admin-card">
      <div style="display:flex;justify-content:space-between;align-items:baseline;gap:14px;flex-wrap:wrap">
        <h2 class="h-serif" style="margin:0;font-size:20px">
          <a href="<?= e(url('/admin/orders/' . $oid)) ?>"><?= e($o['number']) ?></a>
        </h2>
        <span class="dim">
          <?= e($o['name']) ?><?= $o['phone'] ? ' · ' . e($o['phone']) : '' ?>
          · <?= e(OrderFlow::DELIVERY[$o['delivery']] ?? $o['delivery']) ?>
          <?php $addr = OrderFlow::deliveryAddress($o); ?><?= $addr ? ' · ' . e($addr) : '' ?>
        </span>
        <span class="dim" style="margin-left:auto"><?= e(when_human($o['created_at'])) ?></span>
      </div>

      <?php /* Як автомат розклав замовлення — одним рядком, щоб було видно,
               скільки посилок вийде. Дві частини означають дві накладні й дві
               доставки, і саме це найчастіше й хочеться змінити. */ ?>
      <p style="margin:12px 0 0">
        <?php if (count($kids) > 1): ?>
          <span class="status-pill st-new">Розділено на <?= count($kids) ?></span>
        <?php endif; ?>
        <span class="muted"><?= e(Dispatch::storeLine($oid)) ?></span>
      </p>

      <table class="tbl" style="margin-top:14px">
        <tr>
          <th>Позиція</th>
          <th class="num">К-сть</th>
          <th data-help-title="Колонка «Де є»"
              data-help="Скільки цієї позиції зараз лежить у кожній точці. Золотим позначена та, якій вона дісталась.

Це поточний залишок, а не той, що був на момент оформлення: частину вже могли продати в магазині.

Саме за цим рядком і вирішують, чи є сенс перекладати позицію: без нього «Передати» — здогадка.">Де є</th>
          <th></th>
        </tr>
        <?php foreach ($hints[$oid] ?? [] as $h): ?>
          <tr>
            <td>
              <b><?= e($h['title']) ?></b>
              <?php if ($h['mismatch']): ?>
                <?php /* Точка продає за своїм цінником, а онлайн пішло за
                         базовою ціною. Це не помилка сайту — так задумано, — але
                         виторг точки виявиться не тим, на який вона розраховувала,
                         і побачити це має людина, а не бухгалтер через місяць. */ ?>
                <div class="dim" style="color:var(--gold);font-size:12.5px;margin-top:4px">
                  ⚠ <?= e($h['mismatch']['store']) ?> продає за <?= e(price_fmt($h['mismatch']['own'])) ?>,
                  а продано за <?= e(price_fmt($h['mismatch']['paid'])) ?>
                </div>
              <?php endif; ?>
            </td>
            <td class="num"><?= (int)$h['qty'] ?></td>
            <td style="font-size:13px">
              <?php foreach ($h['stores'] as $s): ?>
                <span style="white-space:nowrap;margin-right:12px<?= $s['current'] ? ';color:var(--gold);font-weight:600' : '' ?>">
                  <?= e($s['name']) ?>: <?= $s['qty'] > 0 ? (int)$s['qty'] : '—' ?>
                </span>
              <?php endforeach; ?>
            </td>
            <td class="col-mid"></td>
          </tr>
        <?php endforeach; ?>
      </table>

      <div style="display:flex;gap:10px;margin-top:16px;flex-wrap:wrap;align-items:center">
        <?php /* Перекладають позиції в картці замовлення — там уже є колонка
                 «Передати» з усією обвʼязкою (перенесення залишку, створення
                 частини, журнал). Другий такий інтерфейс тут розійшовся б із
                 нею на першій же правці. */ ?>
        <a class="btn btn-line btn-sm" href="<?= e(url('/admin/orders/' . $oid)) ?>">Відкрити й перекласти</a>
        <form method="post" action="<?= e(url('/admin/dispatch')) ?>" style="margin:0"><?= Csrf::field() ?>
          <input type="hidden" name="id" value="<?= $oid ?>">
          <button class="btn btn-gold btn-sm" type="submit"
                  data-help-title="Кнопка «Підтвердити розподіл»"
                  data-help="Прибирає замовлення з черги: ви подивились і згодні з тим, як воно розкладене.

Магазини бачать свої частини й до цього — резерв складу тримається з моменту оформлення. Підтвердження нічого не перекладає й нікуди не відправляє, воно лише каже «переглянуто».

Скасувати не можна: замовлення просто зникне з черги. Якщо потім знадобиться перекласти позицію — це й далі робиться в картці замовлення.">Підтвердити розподіл</button>
        </form>
      </div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>
