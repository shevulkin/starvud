<?php
/**
 * Фіскальний чек частини замовлення.
 *
 * Показуємо всім, хто бачить замовлення: номер чека питають і по телефону, і
 * коли покупець приходить із поверненням. Кнопки лишаються тому, хто веде цю
 * частину й має право orders.fiscal.
 *
 * Змінні: $cid, $c (частина), $mine, $parent, $receipts, $fiscal_gaps,
 *         $can_fiscal, $pay_types.
 */
$rows = $receipts[$cid] ?? [];
$sale = null;
foreach ($rows as $r) if ($r['type'] === 'sell' && in_array($r['status'], ['done', 'pending', 'queued'], true)) $sale = $r;
$gaps = $fiscal_gaps[$cid] ?? [];
$broken = array_values(array_filter($rows, fn($r) => $r['status'] === 'error'));
?>
<div class="fiscal-box" style="margin-top:14px;padding-top:14px;border-top:1px solid var(--bg3)">
  <?php if ($sale): ?>
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap">
      <div>
        <div class="dim" style="font-size:12.5px">Фіскальний чек</div>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:2px">
          <b style="font-family:var(--serif);font-size:17px;letter-spacing:.5px">
            <?= e($sale['fiscal_number'] ?: '— ще без номера') ?></b>
          <?php if ($sale['qr']): ?>
            <a class="dim" style="font-size:12.5px" target="_blank" rel="noopener"
               href="<?= e(safe_url($sale['qr'])) ?>">електронний чек →</a>
          <?php endif; ?>
        </div>
        <div class="dim" style="font-size:12.5px;margin-top:4px">
          <?= e(price_fmt((float)$sale['sum'])) ?>
          · <?= e(Vchasno::PAY_TYPES[(int)$sale['pay_type']] ?? 'оплата') ?>
          <?php if ((float)$sale['change'] > 0): ?> · решта <?= e(price_fmt((float)$sale['change'])) ?><?php endif; ?>
          <?php if ($sale['shift_link']): ?> · зміна <?= (int)$sale['shift_link'] ?><?php endif; ?>
        </div>
      </div>
      <span class="status-pill st-<?= $sale['status'] === 'done' ? 'done' : ($sale['status'] === 'error' ? 'canceled' : 'shipped') ?>">
        <?= e(Fiscal::statusLabel($sale)) ?></span>
    </div>

    <?php /* Дві мітки, про які мовчати не можна. Тестова каса пробиває чеки
             без юридичної сили — продаж вважається нефіскалізованим. Офлайн —
             чек справжній, але в ДПС він потрапить, коли з’явиться зв’язок. */ ?>
    <?php if (!empty($sale['is_test'])): ?>
      <p class="dim" style="margin:8px 0 0;font-size:12.5px;color:var(--warn,#f0b429)">
        Це <b>тестова каса</b>: чек не має юридичної сили й у ДПС не потрапив.
        Робочий токен вписують у Налаштуваннях або в картці магазину.</p>
    <?php endif; ?>
    <?php if (!empty($sale['is_offline'])): ?>
      <p class="dim" style="margin:8px 0 0;font-size:12.5px">
        Чек проведено офлайн — у ДПС він піде, щойно каса побачить мережу. Покупцю віддавати можна.</p>
    <?php endif; ?>

    <?php /* Скасували частину, а чек лишився пробитим — це не дрібниця:
             гроші в ДПС є, товару в покупця немає. Кажемо прямо, бо кнопка
             повернення поруч, але сама вона про себе не нагадає. */ ?>
    <?php if ($c['status'] === 'canceled' && $sale['status'] === 'done' && !Fiscal::refunded((int)$sale['id'])): ?>
      <p class="dim" style="margin:8px 0 0;font-size:12.5px;color:var(--warn,#f0b429)">
        Частину скасовано, а чек лишається дійсним. Пробийте чек повернення —
        інакше продаж так і рахуватиметься в ДПС.</p>
    <?php endif; ?>

    <?php if ($sale['status'] === 'queued'): ?>
      <?php /* Ключ лежить у магазині, і до каси йде не наш сервер. Це не
               поломка й не очікування «десь у хмарі» — сказати треба саме те,
               хто зараз несе чек, інакше продавець дивиться на екран і не
               розуміє, чи взагалі щось відбувається. */ ?>
      <p class="dim" style="margin:8px 0 0;font-size:12.5px">
        <?= $sale['provider'] === 'dps'
              ? 'Чек чекає на підпис ключем касира — у відкритій вкладці «Каса» або тут, нижче. Ключ і пароль лишаються на пристрої.'
              : ($sale['route'] === 'device'
              ? 'Чек пробивається касою на цьому пристрої — за мить оновиться. Якщо нічого не змінилось, перевірте, чи запущено Device Manager.'
              : 'Завдання чекає на агента цієї точки. Він забирає чеки кожні кілька секунд; якщо касовий ПК вимкнено, чек пробʼється, щойно його ввімкнуть.') ?></p>
    <?php endif; ?>

    <?php if ($sale['status'] === 'pending' && $sale['provider'] === 'dps'): ?>
      <p class="dim" style="margin:8px 0 0;font-size:12.5px">
        Чек зараз підписується або звʼязок із ДПС обірвався. Вкладка з ключем спершу спитає ДПС
        за номером документа, чи він дійшов, — тож другого чека не буде.</p>
    <?php elseif ($sale['status'] === 'pending'): ?>
      <p class="dim" style="margin:8px 0 0;font-size:12.5px">
        Каса не відповіла, тож достеменно невідомо, чи чек пробився.
        Перепитаємо самі<?= $mine && $can_fiscal ? ' — або натисніть «Перепитати»' : '' ?>:
        повторний запит іде з тією ж міткою, тож другого чека з нього не вийде.</p>
    <?php endif; ?>

    <?php if ($mine && $can_fiscal): ?>
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px">
        <?php if (in_array($sale['status'], ['pending', 'queued'], true)): ?>
          <form method="post" style="display:inline">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="fiscal_retry">
            <input type="hidden" name="order_id" value="<?= $cid ?>">
            <input type="hidden" name="receipt_id" value="<?= (int)$sale['id'] ?>">
            <button class="btn btn-line btn-sm" type="submit">↻ Перепитати касу</button>
          </form>
        <?php endif; ?>

        <?php if ($sale['status'] === 'done' && !Fiscal::refunded((int)$sale['id'])): ?>
          <form method="post" style="display:inline"
                onsubmit="return confirm('Пробити чек повернення на <?= e(price_fmt((float)$sale['sum'])) ?>? Це справжня фіскальна операція.')">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="fiscal_return">
            <input type="hidden" name="order_id" value="<?= $cid ?>">
            <input type="hidden" name="receipt_id" value="<?= (int)$sale['id'] ?>">
            <button class="btn btn-line btn-sm" type="submit"
                    data-help-title="Чек повернення"
                    data-help="Пробиває в ПРРО чек повернення на всю суму цього чека — саме так у ПРРО скасовують продаж.

Гроші покупцю віддає продавець; каса лише фіксує, що операція була. Скасувати сам чек повернення вже не можна.

Часткового повернення тут немає: якщо покупець повертає одну позицію, повертайте чек цілком і пробивайте новий на те, що лишилось.">↩ Чек повернення</button>
          </form>
        <?php endif; ?>

        <?php /* У ПРРО ДПС розсилки чеків немає — покупцю дають посилання на чек у кабінеті ДПС */ ?>
        <?php if ($sale['status'] === 'done' && $sale['provider'] !== 'dps'): ?>
          <details style="display:inline-block">
            <summary class="btn btn-line btn-sm" style="list-style:none">✉ Надіслати чек покупцю</summary>
            <form method="post" style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap">
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="fiscal_link">
              <input type="hidden" name="order_id" value="<?= $cid ?>">
              <input type="hidden" name="receipt_id" value="<?= (int)$sale['id'] ?>">
              <input type="text" name="recipient" style="min-width:220px"
                     value="<?= e((string)($parent['email'] ?: $parent['phone'])) ?>"
                     placeholder="пошта або +380…">
              <button class="btn btn-line btn-sm" type="submit">Надіслати</button>
            </form>
            <p class="dim" style="margin:6px 0 0;font-size:12px">
              Посилання надсилає сама «Вчасно.Каса»: на пошту — листом, на номер — SMS.</p>
          </details>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php /* Повернення показуємо окремим рядком, а не міняємо статус чека:
             продаж і повернення — дві різні фіскальні операції, і обидві
             лишаються в ДПС. */ ?>
    <?php foreach ($rows as $r): if ($r['type'] !== 'return') continue; ?>
      <div class="dim" style="margin-top:10px;font-size:12.5px">
        ↩ Повернення <?= e($r['fiscal_number'] ?: '(без номера)') ?>
        на <?= e(price_fmt((float)$r['sum'])) ?> · <?= e(Fiscal::statusLabel($r)) ?>
        <?php if ($r['status'] === 'error'): ?> — <?= e((string)$r['error']) ?><?php endif; ?>
      </div>
    <?php endforeach; ?>

  <?php elseif ($mine && $can_fiscal): ?>
    <?php if ($gaps): ?>
      <div class="dim" style="font-size:13px">
        Чек не пробити: <?= e(implode('; ', $gaps)) ?>.
      </div>
    <?php else: ?>
      <form method="post">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="fiscal_sell">
        <input type="hidden" name="order_id" value="<?= $cid ?>">
        <div class="dim" style="font-size:12.5px;margin-bottom:8px">Фіскальний чек на <?= e(price_fmt((float)$c['total'])) ?></div>
        <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end">
          <div style="display:flex;gap:14px;flex-wrap:wrap">
            <?php foreach ($pay_types as $code => $label): ?>
              <label class="checkbox" style="margin:0">
                <input type="radio" name="pay_type" value="<?= (int)$code ?>" <?= (int)$code === 0 ? 'checked' : '' ?>>
                <?= e($label) ?></label>
            <?php endforeach; ?>
          </div>
          <div style="min-width:160px">
            <label class="dim" style="font-size:12px">Отримано готівкою</label>
            <input type="text" name="got" inputmode="decimal" placeholder="без решти">
          </div>
          <button class="btn btn-gold btn-sm" type="submit"
                  data-help-title="Пробити фіскальний чек"
                  data-help="Проводить продаж цієї частини в ПРРО: позиції, ціни, знижка й податкові групи беруться із замовлення.

Чек справжній і йде в ДПС. Скасувати його можна лише чеком повернення.

Зміну відкривати не треба — якщо вона закрита, каса відкриє її сама разом із першим чеком.

Продажі з каси з галкою «товар віддано» пробиваються автоматично; ця кнопка потрібна для решти — телефонних замовлень, самовивозу, який щойно забрали, і тих випадків, коли перша спроба не вдалася.">🧾 Пробити чек</button>
        </div>
      </form>
    <?php endif; ?>

  <?php else: ?>
    <div class="dim" style="font-size:13px">Фіскального чека немає.</div>
  <?php endif; ?>

  <?php /* Невдалі спроби не ховаємо: продаж без чека — це те, про що має бути
           видно з картки, а не лише з журналу. */ ?>
  <?php foreach ($broken as $r): ?>
    <p class="dim" style="margin:8px 0 0;font-size:12.5px">
      ✗ <?= $r['type'] === 'return' ? 'Повернення' : 'Чек' ?>
      не пройшов (<?= e(date('d.m H:i', strtotime((string)$r['created_at']))) ?>): <?= e((string)$r['error']) ?>
    </p>
  <?php endforeach; ?>
</div>
