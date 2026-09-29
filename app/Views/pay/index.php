<?php
/**
 * Сторінка оплати замовлення.
 *
 * Двома шляхами сюди приходять з різними питаннями. Одразу після оформлення —
 * «скільки й куди тиснути». Після невдалої спроби — «що пішло не так і чи
 * списались гроші». Тому причина відмови стоїть вище за кнопку: людина, у якої
 * щойно не пройшла картка, спершу хоче зрозуміти, а вже потім пробувати.
 */
?>
<section class="section" style="padding-top:64px">
  <div class="container narrow" style="max-width:560px">
    <h2 style="margin-bottom:6px">Оплата замовлення</h2>
    <p class="muted" style="margin:0 0 26px">Номер <b><?= e($order['number']) ?></b></p>

    <?php if ($test): ?>
      <div class="card" style="padding:14px 18px;margin-bottom:18px;border-left:3px solid var(--gold)">
        <p class="dim" style="margin:0">Увімкнено <b>тестовий шлюз</b> банку. Справжні гроші не рухаються —
          оплата проходить лише для перевірки налаштувань.</p>
      </div>
    <?php endif; ?>

    <?php /* Невдала спроба. Код відмови перекладений на людську мову ще в
             Acquiring::CODES: «Недостатньо коштів» покупець розуміє і може
             виправити, «116» — ні. */ ?>
    <?php if ($last && (string)$last['status'] === 'failed'): ?>
      <div class="card" style="padding:16px 18px;margin-bottom:18px;border-left:3px solid #c0563f">
        <p style="margin:0 0 6px"><b>Попередня спроба не пройшла</b></p>
        <p class="dim" style="margin:0">
          <?= e($last['error'] ?: Acquiring::codeLabel($last['tran_code'])) ?>.
          Гроші за нею не списувались — можна спробувати ще раз, зокрема іншою карткою.
        </p>
      </div>
    <?php endif; ?>

    <div class="card" style="padding:20px 22px;margin-bottom:22px">
      <div style="display:flex;justify-content:space-between;align-items:baseline;gap:12px">
        <span class="muted">До сплати</span>
        <b style="font-family:var(--serif);font-size:28px;color:var(--gold)"><?= e(price_fmt($order['total'])) ?></b>
      </div>
      <?php /* Доставка окремим рядком з тієї ж причини, що й у кошику: сума
               картки не включає тариф перевізника, і мовчання про це —
               найгірша несподіванка на відділенні. */ ?>
      <?php if ((string)$order['delivery'] !== 'pickup'): ?>
        <p class="dim" style="margin:10px 0 0;font-size:13px">
          Доставку перевізник тарифікує окремо — вона в цю суму не входить.</p>
      <?php endif; ?>
    </div>

    <?php if ($enabled): ?>
      <form method="post" action="<?= e(url('/pay/start')) ?>">
        <?= Csrf::field() ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <button class="btn btn-gold" type="submit" style="width:100%">
          Оплатити карткою <?= e(price_fmt($order['total'])) ?></button>
      </form>
      <ul class="co-trust" style="margin-top:18px">
        <li>Visa, Mastercard, Apple&nbsp;Pay і Google&nbsp;Pay</li>
        <li>Дані картки вводяться на сторінці банку — наш сайт їх не бачить</li>
        <li>Підтвердження 3D&nbsp;Secure від банку, що видав картку</li>
      </ul>
    <?php else: ?>
      <?php /* Покупець не має розбиратись у наших налаштуваннях: йому важливо
               одне — замовлення живе, а оплату узгодять по телефону. Перелік
               того, чого бракує, показуємо лише персоналу. */ ?>
      <div class="card" style="padding:16px 18px">
        <p style="margin:0 0 6px"><b>Оплата карткою зараз недоступна</b></p>
        <p class="dim" style="margin:0">Ваше замовлення прийняте — продавець зателефонує
          й узгодить зручний спосіб оплати.</p>
        <?php if (Auth::isStaff() && $gaps): ?>
          <p class="dim" style="margin:12px 0 0;font-size:13px">Для персоналу:
            <?= e(implode('; ', $gaps)) ?> — <a href="<?= e(url('/admin/settings')) ?>">Налаштування</a>.</p>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <p class="dim" style="margin:22px 0 0;font-size:12.5px">
      <a href="<?= e(url('/order/success/' . $token)) ?>">Оплачу пізніше — до замовлення</a>
    </p>
  </div>
</section>
