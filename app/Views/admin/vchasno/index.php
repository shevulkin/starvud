<div class="admin-head"><h1 class="h-serif">Каса (ПРРО)</h1></div>

<p class="card-lead" style="margin:-14px 0 22px">
  Стан каси, зміна та звіти. Самі чеки живуть у картках замовлень — тут лише те, що
  стосується каси цілком. Зміну <b>не обовʼязково відкривати руками</b>: якщо вона закрита,
  каса відкриє її сама разом із першим чеком. А от закривати треба — Z-звіт хоча б раз на
  добу вимагає закон.
</p>

<?php if (!$cases): ?>
  <div class="admin-card dim">
    Жодної каси не налаштовано. Оберіть маршрут у
    <a href="<?= e(url('/admin/settings')) ?>">Налаштуваннях</a> — і, якщо в точок каси різні,
    у <a href="<?= e(url('/admin/stores')) ?>">картках магазинів</a>.
  </div>
<?php else: ?>

<?php if (count($cases) > 1): ?>
  <div class="admin-card" style="margin-bottom:18px">
    <div class="dim" style="font-size:13px;margin-bottom:8px">Точка</div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <?php foreach ($cases as $c): ?>
        <a class="btn <?= $c['id'] === $case['id'] ? 'btn-gold' : 'btn-line' ?> btn-sm"
           href="<?= e(url('/admin/vchasno?case=' . rawurlencode($c['id']))) ?>"><?= e($c['name']) ?></a>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

<?php $route = $case['route']; $isCloud = $route['route'] === 'cloud'; ?>
<div class="admin-card">
  <div style="display:flex;justify-content:space-between;align-items:baseline;gap:12px;flex-wrap:wrap">
    <h2 class="h-serif" style="margin:0"><?= e($case['name']) ?></h2>
    <span class="dim" style="font-size:12.5px"><?= e($route['label']) ?></span>
  </div>

  <?php if ($case['gaps']): ?>
    <p class="dim" style="margin:10px 0 0;color:var(--warn,#f0b429)">
      Каса поки не працює: <?= e(implode('; ', $case['gaps'])) ?>.
    </p>
  <?php endif; ?>

  <?php if ($isCloud && $status && !$status['ok']): ?>
    <p class="dim" style="margin:10px 0 0">
      Каса не відповіла: <?= e($status['error']) ?>.
      Перевірити токен можна в <a href="<?= e(url('/admin/settings')) ?>">Налаштуваннях</a>
      кнопкою «Перевірити інтеграції».
    </p>
  <?php elseif ($isCloud && $info): ?>
    <div style="display:flex;gap:24px;flex-wrap:wrap;margin-top:10px">
      <div>
        <div class="dim" style="font-size:12.5px">Фіскальний номер каси</div>
        <b><?= e($info['rro'] ?: '—') ?></b>
      </div>
      <div>
        <div class="dim" style="font-size:12.5px">ЄДРПОУ</div>
        <b><?= e($info['edrpou'] ?: '—') ?></b>
      </div>
      <div>
        <div class="dim" style="font-size:12.5px">Зміна</div>
        <b><?= $info['shift'] === 1 ? 'відкрита' : ($info['shift'] === 0 ? 'закрита' : 'ще не відкривалась') ?></b>
        <?php if ($info['shift'] === 1 && $info['shift_at'] !== ''): ?>
          <span class="dim">з <?= e($info['shift_at']) ?></span>
        <?php endif; ?>
      </div>
      <div>
        <div class="dim" style="font-size:12.5px">Готівка в скриньці</div>
        <b><?= e(price_fmt($info['safe'])) ?></b>
      </div>
      <div>
        <div class="dim" style="font-size:12.5px">Звʼязок із ДПС</div>
        <b><?= $info['online'] ? 'онлайн' : 'офлайн' ?></b>
      </div>
    </div>
    <?php if ($info['test']): ?>
      <p class="dim" style="margin:14px 0 0;color:var(--warn,#f0b429)">
        Це <b>тестова каса</b>: чеки з неї не мають юридичної сили й у ДПС не потрапляють.
        Так перевіряють інтеграцію — але перед відкриттям магазину підставте робочий ПРРО.
      </p>
    <?php endif; ?>
    <?php if (!$info['signed']): ?>
      <p class="dim" style="margin:10px 0 0;color:var(--warn,#f0b429)">
        Ключ касира не завантажено у сховище — перший чек не буде кому підписати.
      </p>
    <?php endif; ?>

  <?php elseif ($route['provider'] === 'dps'): ?>
    <?php /* ПРРО ДПС: стан — наша копія, звірена з фіскальним сервером під час
             останнього підпису. Самого сервера без ключа не спитати: запит
             стану ПРРО теж підписується. */
      $s = $case['store']; $synced = trim((string)($s['dps_synced_at'] ?? '')); ?>
    <div style="display:flex;gap:24px;flex-wrap:wrap;margin-top:10px">
      <div>
        <div class="dim" style="font-size:12.5px">Фіскальний номер ПРРО</div>
        <b><?= e((string)($s['dps_fiscal_num'] ?: '— не вказано')) ?></b>
        <?php if ((int)($s['dps_local_num'] ?? 0) > 0): ?><span class="dim"> · локальний № <?= (int)$s['dps_local_num'] ?></span><?php endif; ?>
      </div>
      <div>
        <div class="dim" style="font-size:12.5px">Зміна</div>
        <b><?= (int)$s['dps_shift_open'] ? 'відкрита' : 'закрита' ?></b>
        <?php if ((int)$s['dps_shift_open'] && $s['dps_shift_at']): ?>
          <span class="dim">з <?= e(date('d.m H:i', strtotime((string)$s['dps_shift_at']))) ?></span>
        <?php endif; ?>
        <?php if ($s['dps_testing'] !== null && (int)$s['dps_testing']): ?><span class="dim"> · тестова</span><?php endif; ?>
      </div>
      <div>
        <div class="dim" style="font-size:12.5px">Режим</div>
        <b><?= Settings::bool('dps_testing', true) ? 'тестовий (чеки без сили)' : 'робочий' ?></b>
      </div>
      <div>
        <div class="dim" style="font-size:12.5px">Наступний номер документа</div>
        <b><?= (int)($s['dps_next_num'] ?? 0) ?: '—' ?></b>
        <span class="dim"><?= $synced !== '' ? '· звірено ' . e(date('d.m H:i', strtotime($synced))) : '· ще не звірялось' ?></span>
      </div>
    </div>
    <?php if (Settings::bool('dps_testing', true)): ?>
      <p class="dim" style="margin:12px 0 0;color:var(--warn,#f0b429)">
        <b>Тестовий режим:</b> документи йдуть у ДПС з позначкою «ТЕСТОВИЙ НЕФІСКАЛЬНИЙ ДОКУМЕНТ».
        Так перевіряють ключ і налаштування. Перед роботою з покупцями закрийте тестову зміну
        Z-звітом і вимкніть тестовий режим у <a href="<?= e(url('/admin/settings')) ?>">Налаштуваннях</a>.
      </p>
    <?php endif; ?>
    <p class="dim" style="margin:10px 0 0;font-size:12.5px">
      Кнопки нижче ставлять завдання в чергу — його підпише ця вкладка, щойно в ній зчитано ключ.
      Чеки з кабінету ДПС: <a href="https://cabinet.tax.gov.ua/cashregs/allchecks" target="_blank" rel="noopener">cabinet.tax.gov.ua</a>.
    </p>
  <?php else: ?>
    <?php /* Ключ лежить у магазині — синхронно спитати касу ми не можемо
             взагалі. Чесно кажемо, що бачимо: чи виходив на звʼязок агент і
             що вийшло з останніх службових завдань. */ ?>
    <div style="display:flex;gap:24px;flex-wrap:wrap;margin-top:10px">
      <div>
        <div class="dim" style="font-size:12.5px">Каса в Device Manager</div>
        <b><?= e($route['device'] ?: '— не вказано') ?></b>
      </div>
      <div>
        <div class="dim" style="font-size:12.5px">Адреса</div>
        <b><?= e($route['url']) ?></b>
      </div>
      <?php if ($route['route'] === 'agent'): ?>
        <div>
          <div class="dim" style="font-size:12.5px">Агент</div>
          <b><?= $case['agent']['alive'] ? 'на звʼязку'
                : ($case['agent']['seen'] !== '' ? 'мовчить' : 'не запускався') ?></b>
          <?php if ($case['agent']['seen'] !== ''): ?>
            <span class="dim"><?= e(date('d.m H:i', strtotime($case['agent']['seen']))) ?></span>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
    <?php if ($route['route'] === 'agent' && !$case['agent']['ready']): ?>
      <p class="dim" style="margin:10px 0 0;color:var(--warn,#f0b429)">
        Токен агента ще не створено —
        <a href="<?= e(url('/admin/stores')) ?>" style="color:inherit;text-decoration:underline">картка точки</a>.
        Без нього завдання нікому забирати.
      </p>
    <?php endif; ?>
    <p class="dim" style="margin:10px 0 0;font-size:12.5px">
      Стан каси показує сам Device Manager — у нього свій веб-інтерфейс на тому ж ПК.
      Звідси ми можемо лише <b>поставити завдання в чергу</b>: його забере
      <?= $route['route'] === 'agent' ? 'агент точки' : 'браузер продавця' ?>.
    </p>
  <?php endif; ?>

  <?php if (!$case['gaps']): ?>
    <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:16px">
      <?= Csrf::field() ?>
      <input type="hidden" name="case" value="<?= e($case['id']) ?>">
      <button class="btn btn-line btn-sm" type="submit" name="_action" value="shift_open"
              data-help-title="Відкрити зміну"
              data-help="Потрібне рідко: якщо зміна закрита, каса відкриє її сама разом із першим чеком.

Ця кнопка знадобиться, коли треба зробити службове внесення до першого продажу — внести розмінну готівку на початку дня.">▶ Відкрити зміну</button>
      <button class="btn btn-line btn-sm" type="submit" name="_action" value="x_report"
              data-help-title="X-звіт"
              data-help="Проміжний звіт: скільки набігло від початку зміни. Нічого не закриває, робити його можна скільки завгодно разів.">📄 X-звіт</button>
      <button class="btn btn-line btn-sm" type="submit" name="_action" value="shift_close"
              onclick="return confirm('Закрити зміну Z-звітом? Після цього чеки цієї зміни вже не змінити.')"
              data-help-title="Z-звіт — закриття зміни"
              data-help="Підсумковий звіт за зміну. Закон вимагає закривати зміну щонайменше раз на 24 години — інакше каса перестане приймати чеки.

Це можна поставити в cron: php bin/cli.php fiscal:z — команда сама вибере, куди слати завдання по кожній точці.">🔒 Z-звіт (закрити зміну)</button>
    </form>

    <details style="margin-top:16px">
      <summary class="dim" style="cursor:pointer;font-size:13px">Службове внесення та видача готівки</summary>
      <p class="dim" style="margin:8px 0;font-size:12.5px">
        Внесення — розмінна готівка на початку дня, видача — інкасація. Це фіскальні
        операції: вони друкуються чеком і змінюють суму готівки в скриньці.
      </p>
      <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
        <?= Csrf::field() ?>
        <input type="hidden" name="case" value="<?= e($case['id']) ?>">
        <div>
          <label class="dim" style="font-size:12px">Сума</label>
          <input type="text" name="sum" inputmode="decimal" style="max-width:140px" placeholder="500">
        </div>
        <div style="flex:1;min-width:200px">
          <label class="dim" style="font-size:12px">Коментар</label>
          <input type="text" name="comment" maxlength="120" placeholder="розмінна монета">
        </div>
        <button class="btn btn-line btn-sm" type="submit" name="_action" value="cash_in">↓ Внести</button>
        <button class="btn btn-line btn-sm" type="submit" name="_action" value="cash_out">↑ Видати</button>
      </form>
    </details>
  <?php endif; ?>
</div>

<?php if ($route['provider'] === 'dps' && !$case['gaps'] && Auth::can('orders.fiscal')): ?>
  <div style="margin-top:18px">
    <?= View::partial('partials/dps_signer', ['mode' => 'kasa']) ?>
  </div>
<?php endif; ?>

<?php if ($fiscal_jobs > 0): ?>
  <?php /* Завдання, які чекають на браузер саме цієї людини. Найчастіше це
           нічний Z-звіт: cron поставив його в чергу, а виконати його може лише
           пристрій, на якому стоїть каса. */ ?>
  <div class="fiscal-runner" data-fiscal-runner data-parent="" style="margin-top:18px">
    <span data-fiscal-status>Виконуємо завдання, що чекали на цей пристрій…</span>
  </div>
  <script src="<?= e(asset_v('js/fiscal.js')) ?>" defer></script>
<?php endif; ?>

<?php if ($service): ?>
  <div class="admin-card" style="margin-top:18px">
    <h2 class="h-serif">Службові завдання</h2>
    <p class="dim" style="margin:-6px 0 14px">
      Зміна, звіти й готівка цієї точки. Там, де ключ у магазині, це саме черга: видно,
      що завдання поставлено й що з нього вийшло.
    </p>
    <table class="tbl">
      <tr><th>Що</th><th>Стан</th><th class="num">Сума</th><th>Коли</th><th>Каса відповіла</th></tr>
      <?php
        $names = ['shift_open' => 'Відкриття зміни', 'shift_close' => 'Z-звіт', 'x_report' => 'X-звіт',
                  'cash_in' => 'Внесення', 'cash_out' => 'Видача'];
      ?>
      <?php foreach ($service as $t): $res = json_decode((string)$t['result'], true) ?: []; ?>
        <tr>
          <td><?= e($names[$t['task']] ?? $t['task']) ?></td>
          <td class="dim"><?= e(Fiscal::statusLabel($t)) ?><?= $t['error'] ? ' — ' . e((string)$t['error']) : '' ?></td>
          <td class="num"><?= (float)$t['sum'] > 0 ? e(price_fmt((float)$t['sum'])) : '—' ?></td>
          <td class="dim"><?= e(date('d.m H:i', strtotime((string)$t['created_at']))) ?></td>
          <td class="dim" style="font-size:12.5px">
            <?php if (isset($res['safe'])): ?>готівка <?= e(price_fmt((float)$res['safe'])) ?><?php endif; ?>
            <?php if (isset($res['shift_link'])): ?> · зміна <?= (int)$res['shift_link'] ?><?php endif; ?>
            <?php if (isset($res['totals']['Real']['Sum'])): ?>виторг <?= e(price_fmt((float)$res['totals']['Real']['Sum'])) ?><?php endif; ?>
            <?php if (isset($res['totals']['Ret']['Sum']) && (float)$res['totals']['Ret']['Sum'] > 0): ?> · повернення <?= e(price_fmt((float)$res['totals']['Ret']['Sum'])) ?><?php endif; ?>
            <?php if (!empty($res['zrep'])): ?> · Z-звіт № <?= e((string)$res['zrep']) ?><?php endif; ?>
            <?php if (!empty($res['note'])): ?><?= e((string)$res['note']) ?><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>
<?php endif; ?>

<?php if ($broken): ?>
  <div class="admin-card" style="margin-top:18px">
    <h2 class="h-serif">Чеки, які не пройшли</h2>
    <p class="dim" style="margin:-6px 0 14px">
      Продаж без чека — це продаж повз ДПС, тож вони зібрані тут, а не лише в картках замовлень.
      «У черзі» означає, що завдання чекає на агента точки або на браузер продавця.
      «Відповіді ще немає» — що каса змовчала: такий чек міг і пробитись, тому повторний запит
      іде з тією самою міткою й другого чека з нього не вийде.
    </p>
    <table class="tbl">
      <tr><th>Замовлення</th><th>Що</th><th class="num">Сума</th><th>Коли</th><th>Стан</th></tr>
      <?php foreach ($broken as $r): ?>
        <tr>
          <td><a href="<?= e(url('/admin/orders/' . (int)$r['parent_id'])) ?>"><?= e((string)($r['number'] ?? '—')) ?></a></td>
          <td><?= $r['type'] === 'return' ? 'повернення' : 'продаж' ?></td>
          <td class="num"><?= e(price_fmt((float)$r['sum'])) ?></td>
          <td class="dim"><?= e(date('d.m H:i', strtotime((string)$r['created_at']))) ?></td>
          <td class="dim"><?= e(Fiscal::statusLabel($r)) ?><?= $r['error'] ? ' — ' . e((string)$r['error']) : '' ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
    <form method="post" style="margin-top:12px">
      <?= Csrf::field() ?>
      <input type="hidden" name="case" value="<?= e($case['id']) ?>">
      <button class="btn btn-line btn-sm" type="submit" name="_action" value="retry_all">
        ↻ Перепитати касу про непевні</button>
    </form>
  </div>
<?php endif; ?>

<?php if ($recent): ?>
  <div class="admin-card" style="margin-top:18px">
    <h2 class="h-serif">Останні чеки</h2>
    <table class="tbl">
      <tr><th>Чек</th><th>Замовлення</th><th>Що</th><th class="num">Сума</th><th>Коли</th></tr>
      <?php foreach ($recent as $r): ?>
        <tr>
          <td>
            <?php if ($r['qr']): ?>
              <a href="<?= e(safe_url((string)$r['qr'])) ?>" target="_blank" rel="noopener"><?= e((string)$r['fiscal_number']) ?></a>
            <?php else: ?><?= e((string)$r['fiscal_number']) ?><?php endif; ?>
            <?php if (!empty($r['is_test'])): ?><span class="dim"> · тестова</span><?php endif; ?>
          </td>
          <td><a href="<?= e(url('/admin/orders/' . (int)$r['parent_id'])) ?>"><?= e((string)($r['number'] ?? '—')) ?></a></td>
          <td><?= $r['type'] === 'return' ? 'повернення' : 'продаж' ?></td>
          <td class="num"><?= e(price_fmt((float)$r['sum'])) ?></td>
          <td class="dim"><?= e(date('d.m H:i', strtotime((string)$r['created_at']))) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>
<?php endif; ?>

<?php endif; ?>

<?php /* Звірка товарів — з кабінетом Вчасно; у ПРРО ДПС номенклатури немає */ ?>
<?php if (($case['route']['provider'] ?? '') !== 'dps'): ?>
<div class="admin-card" style="margin-top:18px">
  <h2 class="h-serif">Товари</h2>
  <p class="dim" style="margin:-6px 0 12px">
    Звірити наш каталог із каталогом кабінету ПРРО — щоб у звітах наш товар був одним рядком,
    а не двома половинками під різними назвами.
  </p>
  <a class="btn btn-line btn-sm" href="<?= e(url('/admin/vchasno/goods')) ?>">Звірка товарів →</a>
</div>
<?php endif; ?>
