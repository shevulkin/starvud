<section class="section" style="padding-top:48px">
  <div class="container narrow">
    <div class="kicker">Кабінет</div>
    <h2>Мої замовлення</h2>
    <?php if (!$orders): ?>
      <p class="muted" style="padding:36px 0">Замовлень поки немає. <a href="<?= e(url('/shop')) ?>">До магазину →</a></p>
    <?php else: ?>
      <div style="display:flex;flex-direction:column;gap:14px;margin-top:28px">
        <?php foreach ($orders as $o): $parts = $children[$o['id']] ?? []; $split = count($parts) > 1; ?>
          <details class="card" style="padding:0">
            <summary style="display:flex;justify-content:space-between;align-items:center;gap:14px;padding:18px 22px;cursor:pointer;flex-wrap:wrap">
              <b style="font-family:var(--serif);font-size:19px"><?= e($o['number']) ?></b>
              <span class="dim"><?= e(date('d.m.Y', strtotime($o['created_at']))) ?></span>
              <span class="chip" style="cursor:default"><?= e(OrderFlow::statusLabel($o['status'])) ?></span>
              <b class="price"><?= e(price_fmt($o['total'])) ?></b>
            </summary>
            <div style="padding:0 22px 20px">
              <?php /* Неоплачена картка — найперше, що має побачити людина,
                       розгорнувши замовлення: доки гроші не прийшли, магазин
                       його не збирає, і чекання тут узаємне. Скасоване не
                       рахуємо: платити за нього не треба. */ ?>
              <?php if ((string)($o['payment_kind'] ?? '') === 'card'
                        && trim((string)($o['paid_at'] ?? '')) === ''
                        && (string)$o['status'] !== 'canceled'): ?>
                <?php /* Оплату могли вимкнути після оформлення. Тоді зникає
                         кнопка, а не пояснення: людина має знати, що робити
                         далі, а не лише те, що заплатити не вийшло. */ ?>
                <?php if ($card_enabled): ?>
                  <p style="margin:0 0 14px">
                    <a class="btn btn-gold btn-xs" href="<?= e(url('/pay/' . $o['token'])) ?>">Оплатити карткою</a>
                    <span class="dim" style="margin-left:8px;font-size:13px">замовлення чекає оплати</span>
                  </p>
                <?php else: ?>
                  <p class="dim" style="margin:0 0 14px;font-size:13px">Оплата карткою на сайті наразі
                    недоступна — продавець зателефонує й узгодить спосіб оплати.</p>
                <?php endif; ?>
              <?php elseif ((string)($o['payment_kind'] ?? '') === 'card' && trim((string)($o['paid_at'] ?? '')) !== ''): ?>
                <p class="dim" style="margin:0 0 14px;font-size:13px">Оплачено карткою
                  <?= e(date('d.m.Y', strtotime((string)$o['paid_at']))) ?>.</p>
              <?php endif; ?>

              <?php if ($split): ?>
                <p class="dim" style="margin:0 0 14px">Замовлення виконують <?= count($parts) ?> магазини — кожен відправляє свою частину, тому статуси можуть відрізнятися.</p>
              <?php endif; ?>

              <?php foreach ($parts as $c): ?>
                <div style="<?= $split ? 'border:1px solid var(--bg3);border-radius:8px;padding:12px 14px;margin-bottom:12px' : '' ?>">
                  <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;<?= $split ? 'margin-bottom:8px' : 'margin-bottom:4px' ?>">
                    <span class="dim">Виконує: <b><?= e($c['store_name'] ?: 'Уточнюємо') ?></b><?= $c['store_city'] ? ', ' . e($c['store_city']) : '' ?></span>
                    <?php if ($split): ?>
                      <span class="chip" style="cursor:default;font-size:11.5px"><?= e(OrderFlow::statusLabel($c['status'])) ?></span>
                    <?php endif; ?>
                  </div>
                  <?php foreach ($items[(int)$c['id']] ?? [] as $it): ?>
                    <div style="display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid var(--bg3);font-size:14px">
                      <span><?= e($it['title']) ?><?= $it['variant_name'] ? ' · ' . e($it['variant_name']) : '' ?> × <?= (int)$it['qty'] ?></span>
                      <span><?= e(price_fmt($it['sum'])) ?></span>
                    </div>
                  <?php endforeach; ?>

                  <?php /* Посилка. Номер накладної — найголовніше тут: його
                           диктують у відділенні й вставляють у трекінг, тому він
                           великий, окремим рядком і з кнопкою «копіювати».
                           Показуємо, лише коли накладна є: рядок «накладної
                           немає» нічого покупцю не дає, а тривоги додає. */ ?>
                  <?php $sh = $shipments[(int)$c['id']] ?? null; if ($sh): $phase = (string)$sh['phase']; ?>
                    <div style="margin-top:12px;padding:12px 14px;border:1px solid var(--bg3);border-radius:8px">
                      <div class="dim" style="font-size:12.5px">Нова Пошта</div>
                      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:3px">
                        <b style="font-family:var(--serif);font-size:18px;letter-spacing:.5px" data-ttn><?= e($sh['number']) ?></b>
                        <button type="button" class="btn btn-line btn-xs" data-copy-ttn="<?= e($sh['number']) ?>">Копіювати</button>
                        <a class="btn btn-line btn-xs" target="_blank" rel="noopener"
                           href="<?= e(Shipments::trackUrl($sh['number'])) ?>">Відстежити →</a>
                      </div>
                      <div style="margin-top:8px;font-size:14px">
                        <?= e(Shipments::statusLabel($sh)) ?>
                        <?php if ($sh['estimated_at'] && $phase !== 'done'): ?>
                          <span class="dim">· орієнтовно <?= e(date('d.m.Y', strtotime($sh['estimated_at']))) ?></span>
                        <?php endif; ?>
                      </div>
                      <?php if ((float)$sh['cod'] > 0 && $phase !== 'done'): ?>
                        <div style="margin-top:6px;font-size:14px">
                          До сплати при отриманні: <b><?= e(price_fmt($sh['cod'])) ?></b>
                        </div>
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>

              <p class="dim" style="margin-top:12px">
                Доставка: <?= e(OrderFlow::deliveryLabel($o['delivery'])) ?>
                <?= $o['city'] ? ' · ' . e($o['city']) : '' ?><?= $o['np_office'] ? ' · ' . e($o['np_office']) : '' ?>
              </p>
            </div>
          </details>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>
<script>
// Номер накладної переписують у трекінг або диктують у відділенні — з екрана
// телефона це чотирнадцять цифр поспіль, у яких легко збитись
document.querySelectorAll('[data-copy-ttn]').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var was = btn.textContent;
    navigator.clipboard.writeText(btn.dataset.copyTtn).then(function () {
      btn.textContent = 'Скопійовано';
      setTimeout(function () { btn.textContent = was; }, 1500);
    }).catch(function () { btn.textContent = 'Не вийшло — виділіть вручну'; });
  });
});
</script>
