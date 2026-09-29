<section class="section sv-done" style="text-align:center;padding-top:64px">
  <div class="container narrow">
    <div class="sv-done-mascot"><?= mascot('wave', 120) ?></div>
    <h1 class="sv-h2">Дякуємо! Замовлення прийнято</h1>
    <p class="lead" style="margin:14px auto 6px">Номер вашого замовлення:</p>
    <p class="sv-done-number"><?= e($order['number']) ?></p>
    <p class="muted" style="margin:10px auto 26px">Сума: <b><?= e(price_fmt($order['total'])) ?></b></p>
    <?php /* Що буде далі — трьома кроками: людина щойно віддала замовлення й
             хоче знати, чого чекати, а не лише «ми звʼяжемося». */
      $pickup = ($order['delivery'] ?? '') === 'pickup'; ?>
    <ol class="sv-done-steps">
      <li><b>Дзвінок</b><span>Зателефонуємо в робочий час, щоб підтвердити наявність і доставку.</span></li>
      <li><b><?= $pickup ? 'Збираємо замовлення' : 'Відправка' ?></b><span><?= $pickup ? 'Повідомимо, коли можна забирати.' : 'Надішлемо номер накладної — посилка йде 1–3 дні.' ?></span></li>
      <li><b>Оплата</b><span><?= (string)($order['payment_kind'] ?? '') === 'card' ? 'Карткою на сайті — див. нижче.' : 'При отриманні або на рахунок — як домовимось.' ?></span></li>
    </ol>

    <?php /* Оплата карткою.
             Три стани, і кожен вимагає свого: гроші прийшли (сказати про це
             прямо — людина щойно віддала суму й хоче підтвердження), гроші
             заблоковані (це ще не списання, і мовчати про різницю не можна),
             оплата не пройшла або її не почали (дати посилання, а не залишити
             покупця гадати, чи можна ще заплатити). Замовлення живе в усіх
             трьох випадках — саме тому кнопка не єдиний вихід зі сторінки. */ ?>
    <?php $payState = (string)($payment['status'] ?? ''); ?>
    <?php if ($payState === 'paid'): ?>
      <div class="card" style="max-width:520px;margin:0 auto 26px;padding:16px 20px">
        <p style="margin:0"><b style="color:var(--gold)">Оплату отримано</b> — <?= e(price_fmt($payment['amount'])) ?>
          <?php if (!empty($payment['proxy_pan'])): ?><span class="dim">, картка <?= e($payment['proxy_pan']) ?></span><?php endif; ?>
        </p>
        <p class="dim" style="margin:8px 0 0;font-size:13px">Квитанцію про операцію надішле банк, що видав картку.</p>
      </div>
    <?php elseif ($payState === 'held'): ?>
      <div class="card" style="max-width:520px;margin:0 auto 26px;padding:16px 20px">
        <p style="margin:0"><b style="color:var(--gold)">Кошти заблоковано на картці</b> — <?= e(price_fmt($payment['amount'])) ?></p>
        <p class="dim" style="margin:8px 0 0;font-size:13px">Списання відбудеться, коли продавець підтвердить наявність
          усіх позицій. Якщо чогось не буде — спишемо менше або розблокуємо все.</p>
      </div>
    <?php elseif ((string)($order['payment_kind'] ?? '') === 'card'): ?>
      <div class="card" style="max-width:520px;margin:0 auto 26px;padding:16px 20px">
        <p style="margin:0 0 12px"><b>Замовлення ще не оплачене</b></p>
        <?php /* Оплату могли вимкнути, поки покупець вибирав картку. Замовлення
                 від цього не зникає й не стає гіршим — зникає лише кнопка, а
                 замість неї має бути сказано, що буде далі. Мовчазний блок без
                 кнопки читався б як «сайт зламався разом із моїми грошима». */ ?>
        <?php if ($card_enabled): ?>
          <p class="dim" style="margin:0 0 14px;font-size:13px">
            <?php if ($payState === 'failed'): ?>
              <?= e($payment['error'] ?: Acquiring::codeLabel($payment['tran_code'])) ?>.
              Гроші не списувались — спробуйте ще раз, зокрема іншою карткою.
            <?php else: ?>
              Оплату можна завершити зараз або пізніше — посилання лишається робочим.
            <?php endif; ?>
          </p>
          <a class="btn btn-gold" href="<?= e(url('/pay/' . $order['token'])) ?>">Оплатити карткою</a>
        <?php else: ?>
          <p class="dim" style="margin:0;font-size:13px">Оплата карткою на сайті наразі недоступна.
            Продавець зателефонує й узгодить зручний спосіб оплати — замовлення в силі.</p>
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <?php if (count($children) > 1): ?>
      <div class="card" style="max-width:520px;margin:0 auto 30px;padding:18px 22px;text-align:left">
        <p class="dim" style="margin:0 0 12px">Товари є в різних магазинах, тож замовлення виконають <?= count($children) ?> продавці — кожен відправить свою частину. Стежити за ними можна в кабінеті одним замовленням.</p>
        <?php foreach ($children as $c): ?>
          <div style="padding:8px 0;border-top:1px solid var(--bg3)">
            <b><?= e($c['store_name'] ?: 'Уточнюємо магазин') ?></b>
            <span class="dim"> — <?= e(price_fmt($c['total'])) ?></span>
            <?php foreach ($items[(int)$c['id']] ?? [] as $it): ?>
              <div class="dim" style="font-size:13px"><?= e($it['title']) ?><?= $it['variant_name'] ? ' · ' . e($it['variant_name']) : '' ?> × <?= (int)$it['qty'] ?></div>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <?php /* Накладні. На щойно оформленому замовленні їх ще немає — блок
             зʼявиться, коли продавець відправить посилку, а посилання на цю
             сторінку в гостя лишається робочим. Для нього це єдине місце, де
             видно номер: кабінету в нього немає. */ ?>
    <?php if ($shipments): ?>
      <div class="card" style="max-width:520px;margin:0 auto 30px;padding:18px 22px;text-align:left">
        <p class="dim" style="margin:0 0 12px">Ваша посилка вже в дорозі:</p>
        <?php foreach ($children as $c): $sh = $shipments[(int)$c['id']] ?? null; if (!$sh) continue; ?>
          <div style="padding:10px 0;border-top:1px solid var(--bg3)">
            <?php if (count($children) > 1): ?>
              <div class="dim" style="font-size:13px"><?= e($c['store_name'] ?: 'Магазин') ?></div>
            <?php endif; ?>
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:3px">
              <b style="font-family:var(--serif);font-size:18px;letter-spacing:.5px"><?= e($sh['number']) ?></b>
              <a class="btn btn-line btn-xs" target="_blank" rel="noopener"
                 href="<?= e(Shipments::trackUrl($sh['number'])) ?>">Відстежити →</a>
            </div>
            <div class="dim" style="font-size:13px;margin-top:5px"><?= e(Shipments::statusLabel($sh)) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <div style="display:flex;gap:14px;justify-content:center;flex-wrap:wrap">
      <a class="btn btn-gold" href="<?= e(url('/shop')) ?>">Продовжити покупки</a>
      <a class="btn btn-line" href="<?= e(url('/')) ?>">На головну</a>
    </div>
  </div>
</section>
