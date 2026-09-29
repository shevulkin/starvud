<?php $total_parts = count($children); ?>
<div class="admin-head">
  <div>
    <h1 class="h-serif" style="margin:0">Замовлення <?= e($parent['number']) ?></h1>
    <?php if ($focus): ?>
      <?php $focus_store = ''; foreach ($children as $c) if ((int)$c['id'] === $focus) $focus_store = $c['store_name'] ?: 'Без магазину'; ?>
      <div class="dim" style="margin-top:4px">Ваша частина: <?= e($order['number']) ?> — <?= e($focus_store) ?><?= $total_parts > 1 ? ' (усього частин: ' . (int)$total_parts . ')' : '' ?></div>
    <?php elseif ($total_parts > 1): ?>
      <div class="dim" style="margin-top:4px">Розділено між магазинами: <?= (int)$total_parts ?></div>
    <?php endif; ?>
  </div>
  <a class="btn btn-line btn-sm" href="<?= e(url('/admin/orders')) ?>">← До списку</a>
</div>

<div style="display:grid;grid-template-columns:1.4fr 1fr;gap:22px" data-rg="1">
  <div>
    <?php foreach ($children as $c): $cid = (int)$c['id']; $mine = !empty($can_manage[$cid]); ?>
      <div class="admin-card" style="<?= $focus === $cid ? 'border-color:var(--gold)' : '' ?>">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
          <h2 class="h-serif" style="margin:0">
            <?= e($c['store_name'] ?: 'Магазин не призначено') ?>
            <?php if ($c['store_city']): ?><span class="dim" style="font-size:14px"><?= e($c['store_city']) ?></span><?php endif; ?>
          </h2>
          <?php /* Чий це виторг. Показуємо лише там, де власників кілька:
                   інакше рядок повторював би те, що й так очевидно. */ ?>
          <?php if (count($owners) > 1 && ($owner_of[$cid] ?? null)): ?>
            <div class="dim" style="font-size:12.5px;width:100%;margin-top:-4px">
              <?= e(Owners::label($owner_of[$cid])) ?> — свій ПРРО і свій чек
            </div>
          <?php endif; ?>
          <div style="display:flex;align-items:center;gap:10px">
            <span class="dim"><?= e($c['number']) ?><?= $total_parts > 1 ? ' · частина ' . (int)$c['seq'] : '' ?></span>
            <span class="status-pill st-<?= e($c['status']) ?>"><?= e($statuses[$c['status']] ?? $c['status']) ?></span>
          </div>
        </div>

        <table class="tbl" style="margin-top:14px">
          <tr>
            <th data-help-title="Колонка «Товар»"
                data-help="Що саме замовили, з варіантом після крапки — «Мʼячик · синій».

Нижче сірим — скільки цього товару зараз лежить у кожному магазині мережі. Це поточний залишок, а не той, що був на момент замовлення.

Дивіться на цей рядок перед тим, як передавати позицію: він показує, у кого товар реально є.">Товар</th>
            <th data-help-title="Колонка «Ціна»"
                data-help="Ціна за одиницю, зафіксована в момент покупки.

Вона більше не змінюється ніколи: ні від нових акцій, ні від зміни ціни в картці товару, ні від передачі позиції в інший магазин. Покупець платить рівно те, що бачив у кошику.">Ціна</th>
            <th data-help-title="Колонка «К-сть»"
                data-help="Скільки одиниць замовили.

Якщо на складі менше, ніж тут написано, — замовлення все одно прийняте: сайт дозволяє замовляти понад залишок. Тоді узгодьте строки з покупцем.">К-сть</th>
            <th data-help-title="Колонка «Сума»" data-help="Ціна × кількість для цього рядка, без урахування знижки на все замовлення. Знижка розподіляється нижче, у підсумку частини.">Сума</th>
            <?php if ($mine): ?>
              <th data-help-title="Колонка «Передати»"
                  data-help="Передати позицію іншому магазину — коли товару у вас немає, а в сусідів є.

Оберіть магазин зі списку й натисніть стрілку. Позиція переїде в його частину замовлення (вона створиться, якщо ще не існує), залишок повернеться вам і спишеться в нього.

Ціна для покупця не змінюється — міняється лише те, хто виконує.

Якщо після передачі у вашій частині не лишиться жодної позиції, вона закриється сама.">Передати</th>
            <?php endif; ?></tr>
          <?php foreach ($items[$cid] as $it): ?>
            <tr>
              <td><?= e($it['title']) ?><?= $it['variant_name'] ? ' · ' . e($it['variant_name']) : '' ?>
                <?php
                  // Продано понад залишок: показуємо просто в рядку позиції, бо
                  // саме тут поруч стоїть кнопка «Передати» — тобто відповідь
                  $lack = $it['stock_taken'] !== null ? (int)$it['qty'] - (int)$it['stock_taken'] : 0;
                ?>
                <?php if ($lack > 0): ?>
                  <div style="color:var(--danger2);font-size:12.5px;margin-top:3px"
                       data-help-title="Не вистачає на складі"
                       data-help="Позицію замовили в більшій кількості, ніж було в цьому магазині. Сайт таке дозволяє — вважається, що виробник доробить.

Числа означають: скільки штук вдалося зняти зі складу і скільки замовили.

Два виходи, обидва тут же: передати позицію магазину, де товар є (стовпець «Передати» праворуч — під рядком видно залишки по всіх точках), або довиробити її й далі виконувати самому.

Позначка не зникає сама: вона показує, що було в момент покупки. Після передачі в іншу точку вона перерахується.">
                    ⚠️ Не вистачає: є <?= (int)$it['stock_taken'] ?> з <?= (int)$it['qty'] ?>
                  </div>
                <?php endif; ?>
                <?php $st = $item_stock[(int)$it['id']] ?? null; if ($st !== null): ?>
                  <div class="dim">Зараз на складі:
                    <?php $bits = [];
                      foreach ($stores as $s) $bits[] = e($s['city'] ?: $s['name']) . ' — ' . (int)($st[(int)$s['id']] ?? 0);
                      echo implode(' · ', $bits); ?>
                  </div>
                <?php endif; ?>
              </td>
              <td><?= e(price_fmt($it['price'])) ?></td>
              <td><?= (int)$it['qty'] ?></td>
              <td><b><?= e(price_fmt($it['sum'])) ?></b></td>
              <?php if ($mine): ?>
                <td>
                  <form method="post" action="<?= e(url('/admin/orders/' . $order['id'])) ?>" style="display:flex;gap:6px;align-items:center">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="transfer">
                    <input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
                    <select name="to_store_id" style="min-width:130px">
                      <?php foreach ($stores as $s): if ((int)$s['id'] === (int)$c['store_id']) continue; ?>
                        <option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <button class="btn btn-line btn-xs" type="submit">→</button>
                  </form>
                </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
        </table>

        <div style="display:flex;justify-content:space-between;align-items:flex-end;gap:16px;margin-top:14px;flex-wrap:wrap">
          <div class="dim">Частина магазину: <b><?= e(price_fmt($c['total'])) ?></b>
            <?php if ((float)$c['discount'] > 0): ?>
              (знижка −<?= e(price_fmt($c['discount'])) ?><?= $c['promo_code'] ? ' за кодом ' . e($c['promo_code']) : '' ?>)
            <?php endif; ?>
          </div>
          <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <?php $who = $assignees[$cid] ?? null; ?>
            <?php if ($who): ?>
              <span class="dim" style="white-space:nowrap">у роботі: <b><?= e($who['name']) ?></b><?php
                if ($who['at']): ?> · <?= e(date('d.m H:i', strtotime($who['at']))) ?><?php endif; ?></span>
            <?php endif; ?>
            <?php if ($mine && $can_assign): ?>
              <form method="post" action="<?= e(url('/admin/orders/' . $order['id'])) ?>" style="display:inline">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="<?= $who && $who['is_me'] ? 'release' : 'claim' ?>">
                <input type="hidden" name="order_id" value="<?= $cid ?>">
                <button class="btn btn-line btn-xs" type="submit"
                        data-help-title="Взяти в роботу"
                        data-help="Позначає, що цією частиною займаєтесь саме ви. Ваше імʼя зʼявляється тут і в загальному списку замовлень.

Навіщо: щоб двоє не дзвонили тому самому покупцю й не пакували те саме замовлення двічі.

«Звільнити» — відпустити її назад, якщо передумали чи йдете зі зміни.

«Перебрати на себе» — забрати в колеги, коли він не встигає. Його імʼя заміниться вашим, попереднє лишиться в історії.

Це лише позначка: доступу до замовлення вона не змінює, статусів не чіпає й покупцю не показується."><?= $who
                  ? ($who['is_me'] ? 'Звільнити' : 'Перебрати на себе')
                  : 'Взяти в роботу' ?></button>
              </form>
            <?php endif; ?>
            <?php if ($mine): ?>
              <form method="post" action="<?= e(url('/admin/orders/' . $order['id'])) ?>" style="display:flex;gap:8px;align-items:center">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="status">
                <input type="hidden" name="order_id" value="<?= $cid ?>">
                <select name="status" data-help-title="Статус вашої частини"
                        data-help="Стан саме цієї частини замовлення — тієї, яку виконує ваш магазин.

Порядок роботи: Нове → Обробляється (взяли, пакуєте) → В дорозі (віддали перевізнику) → Доставлено.

«Скасовано» — коли замовлення не відбудеться. Скасовану частину не можна повернути в роботу, тож не тисніть навмання.

Обравши значення, натисніть «Оновити» — до цього нічого не збережеться.">
                  <?php foreach ($statuses as $key => $label): ?>
                    <option value="<?= $key ?>" <?= $c['status'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                  <?php endforeach; ?>
                </select>
                <button class="btn btn-gold btn-sm" type="submit"
                        data-help-title="Кнопка «Оновити»"
                        data-help="Зберігає обраний статус вашої частини.

Одразу після цього покупець отримує сповіщення про зміну — тож ставте «В дорозі» тоді, коли посилка справді поїхала, а не наперед.

Статус усього замовлення перерахується сам: він дорівнює найменш просунутій частині. Поки хоч один магазин не закрив свою, «Доставлено» покупець не побачить.">Оновити</button>
              </form>
            <?php else: ?>
              <span class="dim">Цю частину веде інший магазин</span>
            <?php endif; ?>
          </div>
        </div>

        <?php /* Відправлення. Блок є лише в частин, які їдуть Новою Поштою:
                 самовивозу накладна ні до чого, і порожня форма там лише
                 збивала б з пантелику. Показуємо всім, хто бачить замовлення,
                 — номер потрібен і тому, хто просто відповідає на дзвінок, —
                 а кнопки лишаємо тому, хто веде цю частину. */ ?>
        <?php if ($parent['delivery'] === 'np'): $sh = $shipments[$cid] ?? null; ?>
          <div class="ship-box" style="margin-top:14px;padding-top:14px;border-top:1px solid var(--bg3)">
            <?php if ($sh): $phase = (string)$sh['phase']; ?>
              <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap">
                <div>
                  <div class="dim" style="font-size:12.5px">Накладна Нової Пошти</div>
                  <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:2px">
                    <b style="font-family:var(--serif);font-size:18px;letter-spacing:.5px"><?= e($sh['number']) ?></b>
                    <a class="dim" style="font-size:12.5px" target="_blank" rel="noopener"
                       href="<?= e(Shipments::trackUrl($sh['number'])) ?>">відстежити →</a>
                    <?php if ($sh['source'] === 'manual'): ?>
                      <span class="dim" style="font-size:12px">вписано вручну</span>
                    <?php endif; ?>
                  </div>
                  <div style="margin-top:6px">
                    <span class="status-pill st-<?= $phase === 'done' ? 'done' : ($phase === 'problem' ? 'canceled' : 'shipped') ?>">
                      <?= e(Shipments::statusLabel($sh)) ?></span>
                    <?php if ($sh['estimated_at'] && $phase !== 'done'): ?>
                      <span class="dim" style="font-size:12.5px">орієнтовно <?= e(date('d.m.Y', strtotime($sh['estimated_at']))) ?></span>
                    <?php endif; ?>
                    <?php if ($sh['tracked_at']): ?>
                      <span class="dim" style="font-size:12px">· перевірено <?= e(date('d.m H:i', strtotime($sh['tracked_at']))) ?></span>
                    <?php endif; ?>
                  </div>
                  <div class="dim" style="font-size:12.5px;margin-top:6px">
                    <?= e(Shipments::SERVICE[$sh['service']] ?? $sh['service']) ?>
                    · платить <?= e(mb_strtolower($ship_payers[$sh['payer']] ?? $sh['payer'])) ?>
                    <?php if ((float)$sh['weight'] > 0): ?> · <?= e(num_val($sh['weight'])) ?> кг<?php endif; ?>
                    <?php if ((int)$sh['seats'] > 1): ?> · місць: <?= (int)$sh['seats'] ?><?php endif; ?>
                    <?php if ((float)$sh['delivery_cost'] > 0): ?> · доставка <?= e(price_fmt($sh['delivery_cost'])) ?><?php endif; ?>
                    <?php if ((float)$sh['cod'] > 0): ?>
                      · <b style="color:var(--gold)">післяплата <?= e(price_fmt($sh['cod'])) ?></b>
                    <?php endif; ?>
                  </div>
                </div>
                <?php if ($mine && $can_ship): ?>
                  <div style="display:flex;gap:8px;flex-wrap:wrap">
                    <form method="post" action="<?= e(url('/admin/orders/' . $order['id'])) ?>">
                      <?= Csrf::field() ?>
                      <input type="hidden" name="action" value="ship_refresh">
                      <input type="hidden" name="order_id" value="<?= $cid ?>">
                      <button class="btn btn-line btn-xs" type="submit"
                              data-help-title="Оновити статус"
                              data-help="Питає Нову Пошту, де зараз посилка, і оновлює статус тут-таки.

Робити це щоразу не треба: статуси й так підтягуються самі за розкладом. Кнопка — для випадку «покупець дзвонить просто зараз і питає».

Коли посилка прибуває у відділення чи її отримують, покупець дізнається сам — йому йде повідомлення.">↻ Статус</button>
                    </form>
                    <form method="post" action="<?= e(url('/admin/orders/' . $order['id'])) ?>"
                          onsubmit="return confirm('Відкріпити накладну <?= e($sh['number']) ?> від замовлення? Якщо посилку ще не прийняли у відділенні, вона буде скасована в Новій Пошті.')">
                      <?= Csrf::field() ?>
                      <input type="hidden" name="action" value="ship_remove">
                      <input type="hidden" name="order_id" value="<?= $cid ?>">
                      <button class="btn btn-line btn-xs" type="submit"
                              data-help-title="Відкріпити накладну"
                              data-help="Прибирає номер із замовлення й пробує скасувати накладну в Новій Пошті.

Скасувати вдається, лише поки посилку не прийняли у відділенні. Якщо вже прийняли — номер відкріпиться тут, але посилка їхатиме далі, і повертати її доведеться через НП. Про це скажемо прямо.

Потрібне, коли накладну створили помилково: не на ту частину, не на те відділення, не з тією вагою.">✕ Накладна</button>
                    </form>
                  </div>
                <?php endif; ?>
              </div>

            <?php elseif ($mine && $can_ship): $gaps = $ship_gaps[$cid] ?? []; $f = $ship_form[$cid] ?? null; ?>
              <?php if ($gaps): ?>
                <div class="dim" style="font-size:13px">
                  <b>Накладну поки не створити.</b> Бракує: <?= e(implode('; ', $gaps)) ?>.
                </div>
                <?php /* Найчастіша причина — відділення без Ref: старе замовлення
                         або покупець вписав назву руками. Це лагодиться тут-таки,
                         а не в базі, тож поруч із причиною — куди по неї йти.
                         Перевіряємо самі поля, а не текст причини: підпис колись
                         перепишуть, і мовчазно зникла підказка гірша за незручну. */ ?>
                <?php
                  $noRef = trim((string)($parent['city_ref'] ?? '')) === ''
                    || (($parent['np_type'] ?? 'warehouse') === 'courier'
                        ? trim((string)($parent['np_street_ref'] ?? '')) === ''
                        : trim((string)($parent['np_office_ref'] ?? '')) === '');
                ?>
                <?php if ($np_enabled && $noRef): ?>
                  <p class="dim" style="font-size:12.5px;margin:8px 0 0">
                    Відділення дообирається у блоці «Доставка» — праворуч на цій сторінці.
                  </p>
                <?php endif; ?>
                <?php /* Відправник — інший рід перешкоди: його заповнюють раз і
                         на весь сайт, у налаштуваннях. Без цього рядка продавець
                         шукає кнопку у власній картці, де її немає й бути не може. */ ?>
                <?php if (!($ship_sender_ready[$cid] ?? true)): ?>
                  <p class="dim" style="font-size:12.5px;margin:8px 0 0">
                    <?php if (Auth::can('settings.manage')): ?>
                      Відправник заповнюється один раз:
                      <a href="<?= e(url('/admin/settings')) ?>#np-sender">Налаштування → «Нова Пошта: відправник»</a>
                      → кнопка «⬇️ Підтягнути з Нової Пошти». Після цього тут зʼявиться форма накладної.
                    <?php else: ?>
                      Відправника заповнює адміністратор — один раз, у Налаштуваннях.
                      Попросіть його це зробити: доти накладні не створюються в жодному замовленні.
                    <?php endif; ?>
                  </p>
                <?php endif; ?>
                <details style="margin-top:10px">
                  <summary class="dim" style="cursor:pointer;font-size:13px">Вписати номер накладної вручну</summary>
                  <?php include __DIR__ . '/_ship_attach.php'; ?>
                </details>
              <?php else: ?>
                <form method="post" action="<?= e(url('/admin/orders/' . $order['id'])) ?>">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="action" value="ship_create">
                  <input type="hidden" name="order_id" value="<?= $cid ?>">
                  <div class="dim" style="font-size:12.5px;margin-bottom:8px">Відправлення Новою Поштою</div>
                  <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
                    <div style="width:110px">
                      <label class="dim" style="font-size:12px">Вага, кг</label>
                      <input type="text" name="ship[weight]" value="<?= e(num_val($f['weight'])) ?>"
                             data-help-title="Вага посилки"
                             data-help="Порахована з ваг товарів (вага × кількість). Де ваги в товарі не проставлено, узято типову з Налаштувань.

Звірте з реальною: за вагою Нова Пошта рахує гроші, і занижена означає доплату у відділенні.">
                    </div>
                    <div style="width:90px">
                      <label class="dim" style="font-size:12px">Місць</label>
                      <input type="text" name="ship[seats]" value="<?= (int)$f['seats'] ?>">
                    </div>
                    <div style="width:120px">
                      <label class="dim" style="font-size:12px">Оцінка, грн</label>
                      <input type="text" name="ship[cost]" value="<?= e(num_val($f['cost'])) ?>"
                             data-help-title="Оголошена вартість"
                             data-help="Скільки коштує вміст посилки. На цю суму НП страхує відправлення — і саме з неї рахує страховий збір.

За замовчуванням дорівнює сумі частини замовлення. Занижувати не варто: у разі втрати повернуть рівно оголошене.">
                    </div>
                    <div style="width:140px">
                      <label class="dim" style="font-size:12px">Післяплата, грн</label>
                      <input type="text" name="ship[cod]" value="<?= e(num_val($f['cod'])) ?>"
                             data-help-title="Післяплата"
                             data-help="Скільки покупець платить при отриманні. Гроші НП перекаже вам.

0 — покупець уже заплатив або платитиме інакше. Ставте суму лише тоді, коли грошей за це замовлення ви ще не бачили: забута післяплата означає віддану задарма посилку.">
                    </div>
                    <div style="flex:1;min-width:180px">
                      <label class="dim" style="font-size:12px">Опис вантажу</label>
                      <input type="text" name="ship[description]" value="<?= e($f['description']) ?>" maxlength="120">
                    </div>
                    <div style="width:150px">
                      <label class="dim" style="font-size:12px">Платить за доставку</label>
                      <select name="ship[payer]">
                        <?php foreach ($ship_payers as $key => $label): ?>
                          <option value="<?= e($key) ?>"<?= $f['payer'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div style="width:150px">
                      <label class="dim" style="font-size:12px">Оплата</label>
                      <select name="ship[payment]">
                        <?php foreach ($ship_payments as $key => $label): ?>
                          <option value="<?= e($key) ?>"<?= $f['payment'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <button class="btn btn-gold btn-sm" type="submit"
                            data-help-title="Створити накладну"
                            data-help="Створює справжню експрес-накладну в Новій Пошті — з номером, за який відповідаєте ви.

Одразу після цього: частина переходить у «В дорозі», покупцю йде номер із посиланням на відстеження, а статус посилки далі оновлюється сам.

Помилились — накладну можна відкріпити, поки її не прийняли у відділенні.

Куди везти, беремо з замовлення: покупець обрав відділення сам, і міняти це за нього тут не можна.">📦 Створити накладну</button>
                  </div>
                </form>
                <details style="margin-top:10px">
                  <summary class="dim" style="cursor:pointer;font-size:13px">Накладна вже створена в кабінеті НП — вписати номер</summary>
                  <?php include __DIR__ . '/_ship_attach.php'; ?>
                </details>
              <?php endif; ?>

            <?php else: ?>
              <div class="dim" style="font-size:13px">Накладної ще немає.</div>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <?php /* Фіскальний чек. Блок є лише там, де є куди пробивати: без
                 жодної налаштованої каси він показував би кнопку, яка вміє
                 тільки відмовляти. Скасованим частинам чек ні до чого — там
                 продажу не було. */ ?>
        <?php /* Скасована частина чек усе одно показує, якщо він був: саме там
                 і потрібна кнопка повернення. А от пробивати новий на скасоване
                 нема чого — це скаже сам блок. */ ?>
        <?php if ($kasa_on && ($c['status'] !== 'canceled' || !empty($receipts[$cid]))) include __DIR__ . '/_fiscal.php'; ?>
        <?php /* Смужка «пробиваємо чек» — лише там, де його несе сам браузер.
                 Показуємо один раз на замовлення, під першою частиною: чек
                 пробивається по черзі, і два однакові рядки збивали б з пантелику. */ ?>
        <?php /* ПРРО ДПС: чек підписує ключ касира — у вкладці «Каса» або тут */ ?>
        <?php if (($dps_jobs ?? 0) > 0 && $c === reset($children)): ?>
          <?= View::partial('partials/dps_signer', ['mode' => 'order', 'parent' => (int)$parent['id']]) ?>
        <?php endif; ?>
        <?php if ($fiscal_jobs > 0 && $c === reset($children)): ?>
          <div class="fiscal-runner" data-fiscal-runner data-parent="<?= (int)$parent['id'] ?>">
            <span data-fiscal-status>Готуємо чек…</span>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>

    <div class="admin-card">
      <div class="totals">
        <div class="row"><span class="muted">Товари:</span><span><?= e(price_fmt($parent['subtotal'])) ?></span></div>
        <?php if ((float)$parent['discount'] > 0): ?>
          <?php
            // Відсоток рахуємо з самого замовлення, а не з довідника кодів: код
            // могли відтоді змінити або видалити, а в замовленні має лишатись те,
            // на чому зійшлися з покупцем.
            $pct = (float)$parent['subtotal'] > 0
                 ? round((float)$parent['discount'] / (float)$parent['subtotal'] * 100, 1) : 0;
          ?>
          <div class="row">
            <span class="muted"><?= $parent['promo_code']
              ? 'Промокод ' . e($parent['promo_code']) . ($pct > 0 ? ' (−' . e(rtrim(rtrim(number_format($pct, 1, ',', ''), '0'), ',')) . '%)' : '')
              : 'Знижка' ?>:</span>
            <span>−<?= e(price_fmt($parent['discount'])) ?></span>
          </div>
        <?php elseif ($parent['promo_code']): ?>
          <div class="row"><span class="muted">Промокод <?= e($parent['promo_code']) ?>:</span>
            <span class="dim">не дав знижки</span></div>
        <?php endif; ?>
        <div class="row grand"><span>Разом за замовленням:</span><span><?= e(price_fmt($parent['total'])) ?></span></div>
      </div>
      <p class="dim" style="margin-top:10px">Передача позиції між магазинами не змінює ціну для покупця — лише те, хто її виконує.</p>
    </div>
  </div>

  <div>
    <div class="admin-card">
      <h2 class="h-serif" data-help-title="Клієнт і доставка"
          data-help="Усе, що потрібно, щоб звʼязатися з покупцем і відправити замовлення.

Телефон та email клікабельні: натисніть — і відкриється дзвінок або лист.

Нижче — спосіб доставки й адреса. «Самовивіз» означає, що покупець забере сам, тож адреси там немає.

Коментар покупця показується окремим рядком унизу. Читайте його завжди: саме там пишуть «зателефонуйте після 18:00» чи «домофон не працює».

Ці дані однакові для всього замовлення й не змінюються від передачі позицій між магазинами.">
        Клієнт і доставка</h2>
      <p><b><?= e($parent['name']) ?></b><br>
      <?php /* Замовлення з каси може бути без номера — тоді дзвонити нікуди,
               і посилання «tel:» на порожнечу лише вводило б в оману. */ ?>
      <?php if ($parent['phone'] !== ''): ?>
        <a href="tel:<?= e($parent['phone']) ?>"><?= e($parent['phone']) ?></a>
      <?php else: ?>
        <span class="dim">без номера — анонімний покупець</span>
      <?php endif; ?>
      <?php if ($parent['email']): ?><br><a href="mailto:<?= e($parent['email']) ?>"><?= e($parent['email']) ?></a><?php endif; ?></p>
      <?php if (($parent['source'] ?? 'site') !== 'site'): ?>
        <p class="dim" style="margin-top:8px">Оформив продавець · <?= e(OrderFlow::sourceLabel($parent['source'])) ?></p>
      <?php endif; ?>
      <p class="muted" style="margin-top:12px">
        <?= e(OrderFlow::deliveryLabel($parent['delivery'])) ?>
        <?php if ($parent['delivery'] === 'np' && ($parent['np_type'] ?? 'warehouse') === 'courier'): ?>
          <br>Курʼєром: <?= e(OrderFlow::deliveryAddress($parent)) ?>
        <?php else: ?>
          <?= $parent['city'] ? '<br>Місто: ' . e($parent['city']) : '' ?>
          <?= $parent['np_office'] ? '<br>Відділення: ' . e($parent['np_office']) : '' ?>
        <?php endif; ?>
        <?= $parent['address'] ? '<br>Адреса: ' . e($parent['address']) : '' ?>
      </p>

      <?php /* Оплата. У роздрібі гроші й товар зустрічаються в одну мить, і
               блок згорнутий. Розгортається сам, коли платять за рахунком: там
               між «виставили» і «прийшло» минають дні, і всі ці дні хтось має
               бачити, чи чекати ще. */ ?>
      <?php $paid = trim((string)($parent['paid_at'] ?? '')) !== ''; ?>
      <?php /* Онлайн-оплата розгортає блок так само, як рахунок: там теж є що
               робити руками — списати заблоковане, повернути гроші, перепитати
               шлюз, коли сповіщення не дійшло. */ ?>
      <details style="margin-top:12px"<?= ($parent['payment_kind'] ?? '') === 'invoice' || $paid || $payments ? ' open' : '' ?>>
        <summary class="dim" style="cursor:pointer;font-size:13px">
          💳 Оплата —
          <?php if ($paid): ?>
            <b>отримано <?= e(date('d.m.Y', strtotime((string)$parent['paid_at']))) ?></b>
          <?php elseif (($parent['payment_kind'] ?? '') !== ''): ?>
            <?= e(mb_strtolower(Invoice::kindLabel($parent['payment_kind']))) ?>, чекаємо
          <?php else: ?>
            не вказано
          <?php endif; ?>
        </summary>
        <?php /* Оплата карткою на сайті.
                 Спроби показуємо всі й зверху вниз від свіжої: коли покупець
                 дзвонить «я платив, а у вас не видно», відповідь майже завжди
                 в коді відмови попередньої спроби, а не в останній.

                 Дії — лише біля тієї спроби, до якої вони стосуються: кнопка
                 «повернути» під невдалим платежем не має існувати взагалі. */ ?>
        <?php if ($payments): ?>
          <div style="margin-top:12px;padding-top:10px;border-top:1px solid var(--bg3)">
            <div class="dim" style="font-size:12.5px;margin-bottom:8px">Оплата карткою на сайті</div>
            <?php foreach ($payments as $p): $st = (string)$p['status']; ?>
              <div style="padding:8px 0;border-top:1px solid var(--bg3)">
                <div style="display:flex;gap:8px;align-items:baseline;flex-wrap:wrap">
                  <b style="color:<?= in_array($st, ['paid', 'held'], true) ? 'var(--gold)' : 'inherit' ?>">
                    <?= e(Acquiring::statusLabel($st)) ?></b>
                  <span><?= e(price_fmt($p['amount'])) ?></span>
                  <span class="dim" style="font-size:12px"><?= e($p['order_ref']) ?></span>
                  <?php if ((float)$p['refunded'] > 0): ?>
                    <span class="dim" style="font-size:12px">· повернено <?= e(price_fmt($p['refunded'])) ?></span>
                  <?php endif; ?>
                </div>
                <div class="dim" style="font-size:12px;margin-top:3px">
                  <?= e(date('d.m.Y H:i', strtotime((string)$p['created_at']))) ?>
                  <?php if (!empty($p['proxy_pan'])): ?> · картка <?= e($p['proxy_pan']) ?><?php endif; ?>
                  <?php if (!empty($p['wallet'])): ?> · <?= e($p['wallet'] === 'APPLE_PAY' ? 'Apple Pay' : 'Google Pay') ?><?php endif; ?>
                  <?php if (!empty($p['approval_code'])): ?> · код <?= e($p['approval_code']) ?><?php endif; ?>
                  <?php if (!empty($p['rrn'])): ?> · RRN <?= e($p['rrn']) ?><?php endif; ?>
                  <?php if ((string)$p['env'] === 'test'): ?> · <b>тестовий шлюз</b><?php endif; ?>
                </div>
                <?php if ($st === 'failed' && ($p['error'] || $p['tran_code'])): ?>
                  <div class="dim" style="font-size:12px;margin-top:3px">
                    <?= e($p['error'] ?: Acquiring::codeLabel($p['tran_code'])) ?></div>
                <?php endif; ?>

                <?php /* Спроба зависла без відповіді: покупець пішов на шлюз і
                         не повернувся. Сповіщення могло не дійти — тоді гроші
                         списані, а замовлення виглядає неоплаченим. Кнопка
                         питає шлюз напряму. */ ?>
                <?php if (in_array($st, ['new', 'sent'], true) && $can_pay_sync): ?>
                  <form method="post" action="<?= e(url('/admin/orders/' . $order['id'])) ?>" style="margin-top:6px">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="pay_sync">
                    <input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>">
                    <button class="btn btn-line btn-xs" type="submit">Спитати шлюз про стан</button>
                  </form>
                <?php endif; ?>

                <?php if ($st === 'held' && $can_refund): ?>
                  <form method="post" action="<?= e(url('/admin/orders/' . $order['id'])) ?>"
                        style="margin-top:8px;display:flex;gap:6px;align-items:flex-end;flex-wrap:wrap">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="pay_capture">
                    <input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>">
                    <div class="field" style="margin:0;max-width:140px">
                      <label style="font-size:12px">Сума списання</label>
                      <input type="text" name="amount" placeholder="<?= e(num_val($p['amount'])) ?>" inputmode="decimal">
                    </div>
                    <button class="btn btn-gold btn-xs" type="submit">Списати заблоковане</button>
                  </form>
                  <p class="dim" style="font-size:12px;margin:6px 0 0">Порожнє поле — уся заблокована сума.
                    Меншу вказують, коли частини товару не знайшлось; більшу шлюз прийме лише в межах +20%.</p>
                <?php endif; ?>

                <?php if (in_array($st, ['paid', 'refunded'], true) && $can_refund
                          && round((float)$p['refunded'], 2) < round((float)$p['amount'], 2)): ?>
                  <form method="post" action="<?= e(url('/admin/orders/' . $order['id'])) ?>"
                        style="margin-top:8px;display:flex;gap:6px;align-items:flex-end;flex-wrap:wrap"
                        onsubmit="return confirm('Повернути гроші на картку покупця? Скасувати повернення можна буде лише в банку.')">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="pay_refund">
                    <input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>">
                    <div class="field" style="margin:0;max-width:140px">
                      <label style="font-size:12px">Сума повернення</label>
                      <input type="text" name="amount"
                             placeholder="<?= e(num_val(round((float)$p['amount'] - (float)$p['refunded'], 2))) ?>"
                             inputmode="decimal">
                    </div>
                    <button class="btn btn-line btn-xs" type="submit">Повернути на картку</button>
                  </form>
                  <p class="dim" style="font-size:12px;margin:6px 0 0">Порожнє поле — увесь залишок.
                    Фіскальний чек повернення це не пробиває — його роблять окремо, у блоці чеків.</p>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <form method="post" action="<?= e(url('/admin/orders/' . $order['id'])) ?>" style="margin-top:10px">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="payment">
          <div class="field">
            <label>Чим розраховуються</label>
            <select name="payment_kind">
              <option value="">не вказано</option>
              <?php foreach (Invoice::KINDS as $k => $label): ?>
                <option value="<?= e($k) ?>"<?= (string)($parent['payment_kind'] ?? '') === $k ? ' selected' : '' ?>>
                  <?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field" style="margin-top:8px">
            <label>Хто покупець</label>
            <select name="buyer_type">
              <option value="">не вказано</option>
              <?php foreach (Invoice::BUYER_TYPES as $k => $label): ?>
                <option value="<?= e($k) ?>"<?= (string)($parent['buyer_type'] ?? '') === $k ? ' selected' : '' ?>>
                  <?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
            <p class="field-hint">Потрібне для рахунку — і щоб система не дала продати тому, кому цьому ФОПу не можна.</p>
          </div>
          <div class="field" style="margin-top:8px">
            <label>Назва покупця для документів</label>
            <input type="text" name="buyer_name" value="<?= e($parent['buyer_name'] ?? '') ?>"
                   maxlength="200" placeholder="ФОП Петренко П. П.">
          </div>
          <div class="field" style="margin-top:8px">
            <label>ІПН / ЄДРПОУ покупця</label>
            <input type="text" name="buyer_tax_id" value="<?= e($parent['buyer_tax_id'] ?? '') ?>" maxlength="20">
          </div>
          <label class="toggle" style="margin-top:10px">
            <input type="checkbox" name="paid" <?= $paid ? 'checked' : '' ?>><span class="tr"></span>
            Гроші отримано
          </label>
          <button class="btn btn-line btn-sm" type="submit" style="margin-top:10px">Зберегти оплату</button>
        </form>

        <?php /* Документи — на кожну частину окремо: продавець у них конкретний
                 ФОП, власник точки, і саме його IBAN стоїть у бланку. */ ?>
        <div style="margin-top:12px;padding-top:10px;border-top:1px solid var(--bg3)">
          <div class="dim" style="font-size:12.5px;margin-bottom:6px">Документи</div>
          <?php foreach ($children as $c2): $c2id = (int)$c2['id']; ?>
            <div style="margin-bottom:6px">
              <?php if (count($children) > 1): ?>
                <div class="dim" style="font-size:12px"><?= e($c2['store_name'] ?: 'Магазин') ?></div>
              <?php endif; ?>
              <a class="btn btn-line btn-xs" target="_blank" rel="noopener"
                 href="<?= e(url('/admin/orders/' . $parent['id'] . '/invoice?part=' . $c2id)) ?>">🧾 Рахунок</a>
              <a class="btn btn-line btn-xs" target="_blank" rel="noopener"
                 href="<?= e(url('/admin/orders/' . $parent['id'] . '/invoice?part=' . $c2id . '&kind=act')) ?>">📄 Накладна</a>
            </div>
          <?php endforeach; ?>
        </div>
      </details>

      <?php
        /*
         * Правка доставки — включно зі способом.
         *
         * Спочатку тут можна було лише дообрати відділення, і то лише коли
         * замовлення вже їхало Новою Поштою. На практиці цього мало: покупець
         * передумав щодо самовивозу, продавець домовився про пошту телефоном,
         * а старі замовлення взагалі не мали звʼязку з довідником. Перекладати
         * це на «перестворіть замовлення» — втратити його історію й номер.
         *
         * Ref-и НП тут не обовʼязкові: без ключа API підказок немає взагалі, і
         * вимагати їх означало б замкнене коло. Накладну за адресою без Ref не
         * створити — про це прямо каже блок відправлення біля позицій.
         */
        $needsRef = $parent['delivery'] === 'np' && (trim((string)($parent['city_ref'] ?? '')) === ''
          || (($parent['np_type'] ?? 'warehouse') === 'courier'
              ? trim((string)($parent['np_street_ref'] ?? '')) === ''
              : trim((string)($parent['np_office_ref'] ?? '')) === ''));
        $locked = (bool)$shipments;   // накладна вже виписана на цю адресу
      ?>
      <?php if ($can_edit_delivery): ?>
        <details style="margin-top:12px"<?= $needsRef && !$locked ? ' open' : '' ?>>
          <summary class="dim" style="cursor:pointer;font-size:13px">
            <?= $needsRef && !$locked ? '⚠️ Уточнити відділення для накладної' : 'Змінити доставку' ?>
          </summary>
          <?php if ($locked): ?>
            <p class="dim" style="font-size:12.5px;margin:8px 0">
              Накладна вже виписана на цю адресу. Щоб змінити доставку, спершу відкріпіть її
              в блоці відправлення — інакше посилка поїде туди, куди її вже відправили.
            </p>
          <?php else: ?>
            <?php if ($needsRef): ?>
              <p class="dim" style="font-size:12.5px;margin:8px 0">
                Адреса записана текстом, без звʼязку з довідником Нової Пошти — накладну за нею не створити.
                Оберіть місто й відділення з підказок: покупцю нічого робити не треба, адреса та сама.
              </p>
            <?php endif; ?>
            <?php if (!$np_enabled): ?>
              <p class="dim" style="font-size:12.5px;margin:8px 0">
                Підказок міст і відділень не буде, поки не вписано API-ключ Нової Пошти
                (Налаштування). Вписане руками збережеться, але накладну за ним не створити.
              </p>
            <?php endif; ?>
            <form method="post" action="<?= e(url('/admin/orders/' . $order['id'])) ?>" style="margin-top:8px">
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="np_address">
              <div class="field" style="margin-bottom:8px">
                <label class="dim" style="font-size:12px">Спосіб доставки</label>
                <select name="delivery" id="ordDelivery">
                  <?php foreach (OrderFlow::DELIVERY as $key => $label): ?>
                    <option value="<?= e($key) ?>"<?= $parent['delivery'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div data-d-np hidden>
                <div class="field" style="margin-bottom:8px">
                  <label class="dim" style="font-size:12px">Куди</label>
                  <select name="np_type" id="npAddrType">
                    <option value="warehouse"<?= ($parent['np_type'] ?? 'warehouse') !== 'courier' ? ' selected' : '' ?>>У відділення / поштомат</option>
                    <option value="courier"<?= ($parent['np_type'] ?? '') === 'courier' ? ' selected' : '' ?>>Курʼєром на адресу</option>
                  </select>
                </div>
                <div class="field" style="margin-bottom:8px">
                  <label class="dim" style="font-size:12px">Місто</label>
                  <input type="text" name="np_city" id="npCity" value="<?= e($parent['city'] ?? '') ?>"
                         autocomplete="new-password" data-lpignore="true" data-1p-ignore spellcheck="false">
                  <input type="hidden" name="city_ref" id="npCityRef" value="<?= e($parent['city_ref'] ?? '') ?>">
                </div>
                <div class="field" style="margin-bottom:8px" data-np-wh>
                  <label class="dim" style="font-size:12px">Відділення</label>
                  <input type="text" name="np_office" id="npOffice" value="<?= e($parent['np_office'] ?? '') ?>"
                         autocomplete="new-password" data-lpignore="true" data-1p-ignore spellcheck="false">
                  <input type="hidden" name="np_office_ref" id="npOfficeRef" value="<?= e($parent['np_office_ref'] ?? '') ?>">
                </div>
                <div data-np-courier hidden>
                  <div class="field" style="margin-bottom:8px">
                    <label class="dim" style="font-size:12px">Вулиця</label>
                    <input type="text" name="np_street" id="npStreet" value="<?= e($parent['np_street'] ?? '') ?>"
                           autocomplete="new-password" data-lpignore="true" data-1p-ignore spellcheck="false">
                    <input type="hidden" name="np_street_ref" id="npStreetRef" value="<?= e($parent['np_street_ref'] ?? '') ?>">
                  </div>
                  <div style="display:flex;gap:8px">
                    <div class="field" style="flex:1"><label class="dim" style="font-size:12px">Будинок</label>
                      <input type="text" name="np_house" value="<?= e($parent['np_house'] ?? '') ?>" maxlength="20"></div>
                    <div class="field" style="flex:1"><label class="dim" style="font-size:12px">Квартира</label>
                      <input type="text" name="np_flat" value="<?= e($parent['np_flat'] ?? '') ?>" maxlength="20"></div>
                  </div>
                </div>
              </div>

              <div class="field" style="margin-bottom:8px" data-d-pickup hidden>
                <label class="dim" style="font-size:12px">Магазин видачі</label>
                <select name="pickup_store_id">
                  <option value="">— оберіть точку —</option>
                  <?php foreach ($stores as $s): ?>
                    <option value="<?= (int)$s['id'] ?>"<?= (int)($parent['store_id'] ?? 0) === (int)$s['id'] ? ' selected' : '' ?>>
                      <?= e($s['name'] . ($s['city'] ? ', ' . $s['city'] : '')) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="field" style="margin-bottom:8px" data-d-other hidden>
                <label class="dim" style="font-size:12px">Як доставляєте</label>
                <input type="text" name="address" value="<?= e($parent['address'] ?? '') ?>" maxlength="200"
                       placeholder="Місто, вулиця, будинок — або як домовились">
              </div>

              <button class="btn btn-line btn-sm" type="submit">Зберегти доставку</button>
              <p class="dim" style="font-size:12px;margin:8px 0 0">
                Зміна стосується всього замовлення — усі магазини везуть в одне місце.
                Покупцю про це повідомлення не йде: він або сам попросив, або ви щойно з ним говорили.
              </p>
            </form>
          <?php endif; ?>
        </details>
      <?php endif; ?>
      <?php if ($parent['comment']): ?><p style="margin-top:12px" class="dim">Коментар: <?= e($parent['comment']) ?></p><?php endif; ?>
    </div>

    <div class="admin-card">
      <h2 class="h-serif">Статус замовлення</h2>
      <p style="margin-bottom:10px"><span class="status-pill st-<?= e($parent['status']) ?>"><?= e($statuses[$parent['status']] ?? $parent['status']) ?></span></p>
      <?php if ($can_manage_parent): ?>
        <form method="post" action="<?= e(url('/admin/orders/' . $order['id'])) ?>">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="status">
          <input type="hidden" name="order_id" value="<?= (int)$parent['id'] ?>">
          <div class="field">
            <select name="status">
              <?php foreach ($statuses as $key => $label): ?>
                <option value="<?= $key ?>" <?= $parent['status'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <button class="btn btn-line btn-sm" type="submit"
                  data-help-title="Проставити всім частинам"
                  data-help="Ставить обраний статус одразу всім магазинам, що виконують це замовлення.

Головне застосування — скасувати замовлення цілком одним рухом, не заходячи в кожну частину окремо.

Обережно: покупець отримає сповіщення по кожній частині.

Уже скасовані частини не воскресають — якщо магазин скасував свою, вона такою й лишиться.

Для звичайної роботи користуйтеся статусом своєї частини: спільний статус порахується сам.">Проставити всім частинам</button>
        </form>
      <?php endif; ?>
      <p class="dim" style="margin-top:12px">Статус замовлення рахується сам: він дорівнює найменш просунутій частині, тож «Доставлено» зʼявиться, коли всі магазини закриють свої.</p>
    </div>

    <?php if ($notes || $can_note): ?>
      <div class="admin-card">
        <h2 class="h-serif" data-help-title="Нотатки"
            data-help="Внутрішні записи для персоналу. Покупець їх НЕ бачить — ні в кабінеті, ні в листі, ні в боті.

Сюди пишуть те, що має знати наступна зміна: «клієнт просив зателефонувати після 18:00», «домовились відправити в понеділок», «просив не класти чек у посилку».

Кожен запис підписується імʼям і роллю автора та часом — видно, хто й коли це написав.

Нотатку не можна відредагувати чи видалити, тож формулюйте одразу як слід.">
          Нотатки</h2>
        <p class="dim" style="margin-bottom:10px">Внутрішні записи для персоналу — покупець їх не бачить.</p>
        <?php foreach ($notes as $n): ?>
          <div style="padding:8px 0;border-bottom:1px solid var(--bg3);font-size:13.5px">
            <div style="white-space:pre-wrap"><?= e($n['message']) ?></div>
            <div class="dim"><?= e(date('d.m.Y H:i', strtotime($n['created_at']))) ?><?= $n['user_name'] ? ' · ' . e($n['user_name']) : '' ?><?php
              if (!empty($n['role'])): ?> · <?= e(Roles::label($n['role'])) ?><?php endif; ?></div>
          </div>
        <?php endforeach; ?>
        <?php if (!$notes): ?><p class="muted" style="margin-bottom:10px">Поки порожньо.</p><?php endif; ?>
        <?php if ($can_note): ?>
          <form method="post" action="<?= e(url('/admin/orders/' . $order['id'])) ?>" style="margin-top:12px">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="note">
            <div class="field">
              <textarea name="note" rows="2" maxlength="2000" placeholder="Наприклад: клієнт просив зателефонувати після 18:00"></textarea>
            </div>
            <button class="btn btn-line btn-sm" type="submit">Додати нотатку</button>
          </form>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if ($events): ?>
      <div class="admin-card">
        <h2 class="h-serif" data-help-title="Історія"
            data-help="Автоматичний журнал усього, що сталося з замовленням: створення, зміни статусів, передачі позицій між магазинами, нотатки.

Кожен запис підписаний імʼям і роллю того, хто діяв, та часом. Записи без імені зробила система сама — наприклад, перерахунок спільного статусу.

Найновіше — зверху. Історію не можна змінити чи видалити: саме тому вона й корисна, коли треба зʼясувати, хто що зробив.">
          Історія</h2>
        <?php foreach ($events as $ev): ?>
          <div style="padding:8px 0;border-bottom:1px solid var(--bg3);font-size:13.5px">
            <?= e($ev['message']) ?>
            <div class="dim"><?= e(date('d.m.Y H:i', strtotime($ev['created_at']))) ?><?= $ev['user_name'] ? ' · ' . e($ev['user_name']) : '' ?><?php
              /* роль показуємо поруч з іменем: у старих записів її немає */
              if (!empty($ev['role'])): ?> · <?= e(Roles::label($ev['role'])) ?><?php endif; ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php /* Форма доставки є лише в того, хто може її правити */ ?>
<?php if ($can_edit_delivery && !$shipments): ?>
  <?php /* Підказки НП живуть окремо: без ключа їх немає, а форма працює й так */ ?>
  <?php if ($np_enabled) echo View::partial('partials/np_autocomplete'); ?>
  <script>
  (function(){
    if (window.npAutocomplete) {
      window.npAutocomplete({city: 'npCity', office: 'npOffice', ref: 'npCityRef',
                             officeRef: 'npOfficeRef', street: 'npStreet', streetRef: 'npStreetRef'});
    }
    // Показуємо поля рівно того способу, який обрано: у самовивозі відділення
    // НП читалось би як «то куди ж воно все-таки їде»
    var mode = document.getElementById('ordDelivery');
    var type = document.getElementById('npAddrType');
    if (!mode) return;
    var show = function(sel, on){ document.querySelectorAll(sel).forEach(function(el){ el.hidden = !on; }); };
    var sync = function(){
      var d = mode.value;
      show('[data-d-np]', d === 'np');
      show('[data-d-pickup]', d === 'pickup');
      show('[data-d-other]', d === 'other');
      var courier = type && type.value === 'courier';
      show('[data-np-wh]', d === 'np' && !courier);
      show('[data-np-courier]', d === 'np' && courier);
    };
    mode.addEventListener('change', sync);
    if (type) type.addEventListener('change', sync);
    sync();
  })();
  </script>
<?php endif; ?>

<?php /* Скрипт вантажимо лише тоді, коли є що нести на касу: у решті випадків
         це зайвий файл на кожне відкриття картки замовлення. */ ?>
<?php if ($fiscal_jobs > 0): ?>
  <script src="<?= e(asset_v('js/fiscal.js')) ?>" defer></script>
<?php endif; ?>
