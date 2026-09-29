<?php
/**
 * Черга торгу.
 *
 * Одна розмова — одна картка, а не рядок таблиці. Рішення тут не «так/ні по
 * списку»: щоб відповісти, треба одночасно бачити ціну на сайті, що дав би
 * автоматичний опт на цю ж кількість, скільки товару лежить і хто просить.
 * У таблиці ці чотири речі стають чотирма стовпцями, які читають по черзі, —
 * а вони важать лише разом.
 */
?>
<div class="admin-head"><h1 class="h-serif">Торг</h1></div>

<p class="card-lead" style="margin:-14px 0 22px">
  Покупці пропонують свою ціну за свою кількість. Ви можете <b>погодитись</b>,
  дати <b>зустрічні умови</b> або <b>відмовити</b>. Погоджена ціна закріплюється
  за цією людиною на <?= (int)Offers::holdHours() ?> год і діє рівно на ту
  кількість, про яку домовились, — далі вона просто оформлює замовлення сама.
  Ніяких знижок поверх домовленої ціни не додається.
</p>

<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:18px">
  <?php foreach ($tabs as $key => $label): ?>
    <a class="chip<?= $tab === $key ? ' active' : '' ?>"
       href="<?= e(url('/admin/offers?tab=' . $key)) ?>"><?= e($label) ?><?php
      if ($key === 'todo' && $todo > 0): ?> · <b><?= (int)$todo ?></b><?php endif; ?></a>
  <?php endforeach; ?>
</div>

<?php if (!$rows): ?>
  <div class="admin-card dim">
    <?= $tab === 'todo' ? 'Усе розібрано — жодна пропозиція не чекає відповіді.' : 'Тут поки порожньо.' ?>
  </div>
<?php else: ?>
  <?php foreach ($rows as $r):
    $waiting = $r['status'] === 'open' && (string)$r['turn'] === 'seller';
    $sum = (float)$r['price'] * (int)$r['qty'];
  ?>
    <div class="admin-card offer-card" id="o<?= (int)$r['id'] ?>" style="margin-bottom:16px<?= $waiting ? ';border-color:var(--gold)' : '' ?>">
      <div style="display:flex;justify-content:space-between;align-items:baseline;gap:14px;flex-wrap:wrap">
        <b style="font-family:var(--serif);font-size:18px">
          <a href="<?= e(url('/admin/products/' . (int)$r['product_id'])) ?>"><?= e($r['product_name']) ?></a><?php
            ?><?= $r['variant_name'] ? ' · ' . e($r['variant_name']) : '' ?>
        </b>
        <span class="chip" style="cursor:default"><?= e(Offers::statusLabel($r)) ?></span>
      </div>

      <?php /* Головні чотири числа. Оптова ціна серед них не для довідки:
               саме вона каже, чи це взагалі поступка. Погодити 470, коли
               система й без вас віддала б цю партію по 465, — не знижка,
               а продаж дорожче за власний прайс, і побачити це треба до
               кліку, а не після. */ ?>
      <div class="offer-facts">
        <div data-help-title="Пропозиція"
             data-help="Кількість і ціна за штуку, які зараз на столі, та сума за всю партію.

Якщо останній хід був ваш, тут стоять ВАШІ умови, і чекаємо ми на покупця.">
          <span>Пропозиція</span>
          <b><?= (int)$r['qty'] ?> шт × <?= e(price_fmt($r['price'])) ?></b>
          <i><?= e(price_fmt($sum)) ?> за партію</i>
        </div>
        <div data-help-title="Ціна на сайті"
             data-help="Скільки цей товар коштує зараз на вітрині, і на скільки відсотків пропозиція нижча.

Це поточна ціна, а не та, що була на початку розмови: якщо ви змінили ціну товару, різниця перерахується сама.">
          <span>На сайті</span>
          <b><?= $r['list_now'] !== null ? e(price_fmt($r['list_now'])) : '—' ?></b>
          <i<?= $r['cut_percent'] >= 25 ? ' class="warn"' : '' ?>>
            <?= $r['cut_percent'] > 0 ? '−' . e(QtyDiscounts::pct((float)$r['cut_percent'])) . '%' : 'без знижки' ?></i>
        </div>
        <div data-help-title="Що дав би опт"
             data-help="Ціна, яку покупець отримав би САМ, без розмови, — за оптовою шкалою на цю саму кількість.

Це головне число на екрані. Якщо запитувана ціна вища за оптову, покупець просто не знайшов шкалу, і відповідь «погоджуюсь» продає дорожче за ваш же прайс — досить показати йому опт.

Прочерк означає, що опт на цей товар не діє або шкала для такої кількості порожня.">
          <span>Опт на <?= (int)$r['qty'] ?> шт</span>
          <b><?= $r['wholesale_price'] !== null ? e(price_fmt($r['wholesale_price'])) : '—' ?></b>
          <i><?= $r['wholesale_percent'] > 0 ? '−' . e(QtyDiscounts::pct((float)$r['wholesale_percent'])) . '% автоматично' : 'шкали немає' ?></i>
        </div>
        <div data-help-title="Склад"
             data-help="Скільки цієї позиції зараз у мережі загалом.

Менше за кількість у пропозиції — не привід відмовляти: домовитись можна й на строк виготовлення. Але сказати про строк треба зараз, у коментарі, а не після оформлення замовлення.">
          <span>На складі</span>
          <b class="<?= (int)$r['stock'] >= (int)$r['qty'] ? '' : 'warn' ?>"><?= (int)$r['stock'] ?> шт</b>
          <i><?= (int)$r['stock'] >= (int)$r['qty'] ? 'вистачає' : 'не вистачає на партію' ?></i>
        </div>
      </div>

      <?php /* Хто просить. Постійний покупець і людина з вулиці — це різні
               розмови, і різницю видно лише з історії замовлень. */ ?>
      <p class="dim" style="margin:14px 0 0">
        <b><?= e($r['user_name'] ?: 'Покупець') ?></b>
        <?= $r['user_phone'] ? ' · ' . e($r['user_phone']) : '' ?>
        · <?= (int)$r['buyer_orders'] ?> <?= e(plural((int)$r['buyer_orders'], 'замовлення', 'замовлення', 'замовлень')) ?>
        <?php if ((float)$r['buyer_spent'] > 0): ?> на <?= e(price_fmt($r['buyer_spent'])) ?><?php endif; ?>
        · розмова від <?= e(date('d.m.Y', strtotime((string)$r['created_at']))) ?>
      </p>

      <?php if ($r['rounds_log']): ?>
        <details style="margin-top:10px">
          <summary class="dim" style="cursor:pointer">Ходи розмови (<?= count($r['rounds_log']) ?>) —
            зроблено <?= (int)$r['rounds'] ?> із <?= (int)Offers::MAX_ROUNDS ?></summary>
          <div style="margin-top:8px">
            <?php foreach ($r['rounds_log'] as $h): ?>
              <div style="display:flex;gap:10px;padding:6px 0;border-bottom:1px solid var(--bg3);font-size:13.5px">
                <span class="dim" style="min-width:88px"><?= $h['side'] === 'buyer' ? 'Покупець' : 'Магазин' ?></span>
                <span><?php
                  if ($h['action'] === 'offer') echo (int)$h['qty'] . ' шт × ' . e(price_fmt($h['price']));
                  elseif ($h['action'] === 'accept') echo 'погодились';
                  else echo 'закрили розмову';
                  if (!empty($h['note'])) echo ' <span class="dim">— ' . e($h['note']) . '</span>';
                ?></span>
                <span class="dim" style="margin-left:auto"><?= e(date('d.m H:i', strtotime((string)$h['created_at']))) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        </details>
      <?php endif; ?>

      <?php if ($waiting): ?>
        <?php /* Три дії однією формою: різниця між ними — лише значення
                 кнопки. Три окремі форми означали б три копії полів
                 кількості й ціни, з яких зустрічна пропозиція читала б
                 свою, а решта — ні. */ ?>
        <form method="post" class="offer-actions" style="margin-top:16px">
          <?= Csrf::field() ?>
          <input type="hidden" name="offer_id" value="<?= (int)$r['id'] ?>">
          <input type="hidden" name="tab" value="<?= e($tab) ?>">

          <div class="offer-counter" data-help-title="Зустрічні умови"
               data-help="Ваша відповідь числами. Кількість теж можна змінити: «за десять не віддам, за тридцять віддам» — нормальна відповідь, і без права назвати свою кількість вона не висловлюється.

Поля вже заповнені тим, що запропонував покупець, — правте те, з чим не згодні.

Після надсилання хід переходить до покупця: він або погоджується, або пише своє.">
            <label>
              <span>Кількість</span>
              <input type="number" name="qty" min="1" max="999" value="<?= (int)$r['qty'] ?>">
            </label>
            <label>
              <span>Ціна за штуку</span>
              <input type="text" name="price" inputmode="decimal" value="<?= e(num_val($r['price'])) ?>">
            </label>
            <label class="grow">
              <span>Коментар покупцю</span>
              <input type="text" name="note" maxlength="500"
                     placeholder="напр.: за 30 шт віддамо по 450, відвантажимо за тиждень">
            </label>
          </div>

          <div class="offer-buttons">
            <button class="btn btn-gold btn-sm" name="do" value="accept"
                    data-help-title="Погодитись"
                    data-help="Приймаєте умови покупця як вони є: та сама кількість, та сама ціна.

Покупцю одразу піде повідомлення, і ціна закріпиться за ним. Далі він оформлює замовлення сам — правити суму руками не потрібно.

Поля «кількість» і «ціна» при цьому не читаються: погодитись означає прийняти чуже, а не своє. Щоб змінити числа, надішліть зустрічні умови.">Погодитись на <?= e(price_fmt($r['price'])) ?></button>
            <button class="btn btn-line btn-sm" name="do" value="counter"
                    data-help-title="Зустрічні умови"
                    data-help="Надсилає покупцю кількість і ціну з полів вище разом із коментарем. Розмова лишається живою, хід переходить до нього.">Надіслати зустрічні умови</button>
            <button class="btn btn-danger btn-sm" name="do" value="decline"
                    data-help-title="Відмовити"
                    data-help="Закриває розмову. Покупцю піде повідомлення разом із вашим коментарем — саме тому коментар тут важливіший, ніж здається: «на цю позицію знижок немає» і «зараз немає товару, напишіть у травні» це для нього різні відповіді.">Відмовити</button>
          </div>
        </form>
      <?php elseif ($r['status'] === 'open'): ?>
        <p class="dim" style="margin:14px 0 0">Чекаємо на відповідь покупця.</p>
      <?php elseif ($r['status'] === 'ordered'): ?>
        <p class="dim" style="margin:14px 0 0">
          Домовленість використана в замовленні
          <?php if (!empty($r['order_id'])): ?>
            <a href="<?= e(url('/admin/orders/' . (int)$r['order_id'])) ?>">№<?= (int)$r['order_id'] ?></a>
          <?php endif; ?>.
        </p>
      <?php elseif ($r['status'] === 'accepted'): ?>
        <p class="dim" style="margin:14px 0 0">
          Ціна закріплена за покупцем<?= !empty($r['expires_at'])
            ? ' до ' . e(date('d.m.Y H:i', strtotime((string)$r['expires_at']))) : '' ?>.
          Замовлення він оформлює сам.
        </p>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
<?php endif; ?>
