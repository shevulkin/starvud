<?php
/**
 * Мої пропозиції ціни.
 *
 * Сторінка відповідає на два питання й у такому ж порядку: чи від мене зараз
 * чогось чекають — і про що ми взагалі говорили. Тому живі розмови стоять
 * згори (так їх віддає Offers::forUser), а листування ходів сховане в
 * «details»: воно потрібне, коли повертаєшся через тиждень і не памʼятаєш,
 * хто останній назвав цифру.
 */
?>
<section class="section" style="padding-top:48px">
  <div class="container narrow">
    <div class="kicker">Кабінет</div>
    <h2>Мої пропозиції ціни</h2>

    <?php if (!$offers): ?>
      <p class="muted" style="padding:36px 0">
        Ви ще не пропонували свою ціну. На сторінці майже кожного товару є блок
        «Не влаштовує ціна? Запропонуйте свою» — скажіть, скільки штук берете й
        за скільки готові, і продавець відповість особисто.
        <a href="<?= e(url('/shop')) ?>">До магазину →</a>
      </p>
    <?php else: ?>
      <div style="display:flex;flex-direction:column;gap:14px;margin-top:28px">
        <?php foreach ($offers as $o):
          $live = in_array($o['status'], ['open', 'accepted'], true);
          $mine = $live && (string)$o['turn'] === 'buyer';
          $sum = (float)$o['price'] * (int)$o['qty'];
          $listSum = (float)($o['list_price'] ?? 0) * (int)$o['qty'];
        ?>
          <div class="card offer-card" id="o<?= (int)$o['id'] ?>" style="padding:20px 22px<?= $mine ? ';border-color:var(--gold)' : '' ?>">
            <div style="display:flex;justify-content:space-between;align-items:baseline;gap:14px;flex-wrap:wrap">
              <b style="font-family:var(--serif);font-size:19px">
                <a href="<?= e(url('/product/' . $o['slug'])) ?>"><?= e($o['product_name']) ?></a><?php
                  ?><?= $o['variant_name'] ? ' · ' . e($o['variant_name']) : '' ?>
              </b>
              <span class="chip" style="cursor:default"><?= e(Offers::statusLabel($o)) ?></span>
            </div>

            <p style="margin:12px 0 0;font-size:16px">
              <?= e(Offers::terms($o)) ?>
              <?php if ($listSum > $sum): ?>
                <span class="dim"> · замість <?= e(price_fmt($listSum)) ?></span>
              <?php endif; ?>
            </p>

            <?php /* Ціна вітрини просто зараз. Показуємо лише тоді, коли вона
                     змінилась відтоді: збіг нічого не додає, а розбіжність
                     пояснює, чому домовленість тижневої давнини виглядає
                     дивно — товар відтоді подешевшав або подорожчав. */ ?>
            <?php if ($o['list_now'] !== null && (float)$o['list_now'] != (float)($o['list_price'] ?? 0)): ?>
              <p class="dim" style="margin:6px 0 0">Зараз на сайті: <?= e(price_fmt($o['list_now'])) ?>/шт</p>
            <?php endif; ?>

            <?php if ($o['status'] === 'accepted' && !empty($o['expires_at'])): ?>
              <p style="margin:8px 0 0;color:var(--gold)">
                Ціна закріплена за вами до <?= e(date('d.m.Y H:i', strtotime((string)$o['expires_at']))) ?>.
              </p>
            <?php elseif ($o['status'] === 'open' && (string)$o['turn'] === 'seller'): ?>
              <p class="dim" style="margin:8px 0 0">Чекаємо на відповідь продавця — напишемо, щойно він відповість.</p>
            <?php endif; ?>

            <?php /* Ходи розмови. Згорнуто: у живій розмові головне — останні
                     умови, вони вже вгорі; історія потрібна тому, хто
                     повернувся через тиждень. */ ?>
            <?php if (count($o['rounds_log']) > 1): ?>
              <details style="margin-top:12px">
                <summary class="dim" style="cursor:pointer">Як ішла розмова (<?= count($o['rounds_log']) ?>)</summary>
                <div style="margin-top:10px">
                  <?php foreach ($o['rounds_log'] as $r): ?>
                    <div style="display:flex;gap:10px;padding:7px 0;border-bottom:1px solid var(--bg3);font-size:14px">
                      <span class="dim" style="min-width:92px"><?= $r['side'] === 'buyer' ? 'Ви' : 'Магазин' ?></span>
                      <span>
                        <?php if ($r['action'] === 'offer'): ?>
                          <?= (int)$r['qty'] ?> шт × <?= e(price_fmt($r['price'])) ?>
                        <?php elseif ($r['action'] === 'accept'): ?>
                          погодились на умови
                        <?php else: ?>
                          закрили розмову
                        <?php endif; ?>
                        <?php if (!empty($r['note'])): ?>
                          <span class="dim"> — <?= e($r['note']) ?></span>
                        <?php endif; ?>
                      </span>
                      <span class="dim" style="margin-left:auto"><?= e(date('d.m H:i', strtotime((string)$r['created_at']))) ?></span>
                    </div>
                  <?php endforeach; ?>
                </div>
              </details>
            <?php endif; ?>

            <?php if ($live): ?>
              <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:16px">
                <?php if ($o['status'] === 'accepted'): ?>
                  <form method="post" action="<?= e(url('/cart/add-offer')) ?>"><?= Csrf::field() ?>
                    <input type="hidden" name="offer_id" value="<?= (int)$o['id'] ?>">
                    <input type="hidden" name="back" value="/bargain">
                    <button class="btn btn-gold btn-sm" type="submit">До кошика за домовленою ціною</button>
                  </form>
                <?php elseif ($mine): ?>
                  <form method="post" action="<?= e(url('/bargain/accept')) ?>"><?= Csrf::field() ?>
                    <input type="hidden" name="offer_id" value="<?= (int)$o['id'] ?>">
                    <input type="hidden" name="back" value="/bargain">
                    <button class="btn btn-gold btn-sm" type="submit">Погодитись на ці умови</button>
                  </form>
                  <a class="btn btn-line btn-sm" href="<?= e(url('/product/' . $o['slug'])) ?>">Запропонувати інше</a>
                <?php endif; ?>
                <form method="post" action="<?= e(url('/bargain/cancel')) ?>"><?= Csrf::field() ?>
                  <input type="hidden" name="offer_id" value="<?= (int)$o['id'] ?>">
                  <input type="hidden" name="back" value="/bargain">
                  <button class="btn btn-line btn-sm" type="submit">
                    <?= $o['status'] === 'accepted' ? 'Відмовитись від домовленості' : 'Скасувати' ?></button>
                </form>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>
