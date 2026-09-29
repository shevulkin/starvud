<?php
/** @var array $users @var array $stores @var array $seller_stores @var array $user_roles
 *  @var array $assignable @var array $counts @var string $tab @var string $q
 *  @var int $page @var int $pages @var int $total */
$tgOn = Telegram::configured();
$viberOn = Viber::configured();
// Адреса поточного списку: її кладемо в кожну форму, щоб після збереження
// повернутись у той самий фільтр і пошук, а не на початок.
$listUrl = function (array $over = []) use ($tab, $q, $page): string {
    $p = array_filter(array_merge(['tab' => $tab, 'q' => $q, 'p' => $page], $over),
        fn($v) => $v !== '' && $v !== null && $v !== 1 && $v !== '1');
    return '/admin/users' . ($p ? '?' . http_build_query($p) : '');
};
$back = $listUrl();
$tabs = ['staff' => 'Персонал', 'customers' => 'Покупці', 'all' => 'Усі'];
?>
<div class="admin-head"><h1 class="h-serif">Користувачі та ролі</h1></div>

<form class="admin-card users-bar" method="get" action="<?= e(url('/admin/users')) ?>">
  <input type="hidden" name="tab" value="<?= e($tab) ?>">
  <div class="users-tabs" data-help-title="Персонал / Покупці / Усі"
       data-help="Ділить список на три групи, число поруч — скільки там людей.

«Персонал» — усі, у кого є хоч одна роль: адміни й продавці.

«Покупці» — ті, хто входив на сайт, але ролей не має. Гості, які замовляли без входу, сюди не потрапляють: їхні контакти лежать у самому замовленні.

«Усі» — кожен, хто хоч раз увійшов через Google, Telegram або Viber.">
    <?php foreach ($tabs as $key => $label): ?>
      <a class="chip <?= $tab === $key ? '' : 'off' ?>" href="<?= e(url($listUrl(['tab' => $key, 'p' => 1]))) ?>">
        <?= e($label) ?> <span class="dim"><?= (int)($counts[$key] ?? 0) ?></span>
      </a>
    <?php endforeach; ?>
  </div>
  <div class="users-search" data-help-title="Пошук людини"
       data-help="Шукає одразу за ПІБ, email і телефоном — досить частини значення.

Пошук іде в межах обраної вкладки. Якщо когось не знаходите, спершу перемкніться на «Усі»: людина може не мати ролі й не бути в «Персоналі».">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Пошук: ПІБ, email або телефон">
    <button class="btn btn-gold btn-sm" type="submit">Знайти</button>
    <?php if ($q !== ''): ?>
      <a class="btn btn-line btn-sm" href="<?= e(url($listUrl(['q' => '', 'p' => 1]))) ?>">Скинути</a>
    <?php endif; ?>
  </div>
</form>

<p class="dim" style="margin-bottom:18px">
  <?php if ($q !== ''): ?>
    Знайдено: <b><?= (int)$total ?></b> за запитом «<?= e($q) ?>».
  <?php elseif ($tab === 'staff'): ?>
    Персонал — усі, у кого є хоч одна роль. Магазини призначаються людині, а не ролі,
    і лише поки в неї є роль продавця: знімете роль — вона зникне з усіх точок.
  <?php elseif ($tab === 'customers'): ?>
    Покупці — ті, хто входив на сайт, але не має жодної ролі. Гості, які замовляли без входу,
    сюди не потрапляють: їхні контакти лежать у самому замовленні.
  <?php else: ?>
    Усі, хто хоч раз увійшов — через Google, Telegram або Viber.
  <?php endif; ?>
</p>

<?php if (!$users): ?>
  <div class="admin-card dim">Нікого не знайдено. Спробуйте інший запит або вкладку «Усі».</div>
<?php endif; ?>

<div style="display:flex;flex-direction:column;gap:14px">
  <?php foreach ($users as $u): $uid = (int)$u['id'];
      $has = $user_roles[$uid] ?? [];
      $isSeller = in_array(Roles::SELLER, $has, true);
      // Написати можна лише туди, де є і бот, і привʼязка людини до нього
      $canTg = $tgOn && !empty($u['tg_chat_id']);
      $canViber = $viberOn && !empty($u['viber_id']);
  ?>
    <div class="admin-card user-card">
      <form class="user-grid" method="post" action="<?= e(url('/admin/users')) ?>">
        <?= Csrf::field() ?>
        <input type="hidden" name="user_id" value="<?= $uid ?>">
        <input type="hidden" name="back" value="<?= e($back) ?>">

        <div>
          <b><?= e($u['name']) ?></b>
          <div class="dim"><?= e($u['email'] ?: 'без email') ?></div>
          <div class="dim" style="font-size:11.5px">у системі з <?= e(substr((string)$u['created_at'], 0, 10)) ?></div>
        </div>

        <div data-help-title="Ролі"
             data-help="Що людині дозволено в адмінці.

«Адміністратор» — повний доступ до всієї мережі: товари, ціни, акції, налаштування, інші користувачі.

«Продавець» — лише свої точки: замовлення цих магазинів, їхні ціни й залишки. Картку товару, акції й налаштування він не редагує.

Без жодної галки людина — звичайний покупець і в адмінку не потрапить узагалі.

Ролі можна поєднувати; доступ тоді складається з усіх наданих.">
          <label>Ролі</label>
          <?php foreach ($assignable as $r): ?>
            <label class="checkbox" style="display:block">
              <input type="checkbox" name="roles[]" value="<?= e($r) ?>" <?= in_array($r, $has, true) ? 'checked' : '' ?>
                     <?= $r === Roles::SELLER ? 'data-seller-role' : '' ?>>
              <?= e(Roles::label($r)) ?>
            </label>
          <?php endforeach; ?>
          <span class="dim" style="font-size:12px">Без жодної — звичайний покупець</span>
        </div>

        <?php /* Точки привʼязані до ролі продавця (Users::saveStores), а не до кожної
                 ролі окремо: доступ у людини один на всі її ролі.

                 Галки, а не <select multiple>: у нативному списку звичайний клік
                 скидає попередній вибір, а другу точку додають лише Ctrl+кліком.

                 Підказка під списком міняє ТЕКСТ, а не видимість: зникома підказка
                 змінювала висоту блоку, і від кліку по ролі вся картка стрибала. */ ?>
        <div data-help-title="Магазини"
             data-help="Які точки веде ця людина. Відмічені магазини — ті, чиї замовлення, ціни й залишки їй доступні.

Працює лише разом із роллю «Продавець»: без неї галки заблоковані. Адміну точки не призначають — він і так бачить усю мережу.

Важливо: якщо зняти роль продавця, людина зникає з усіх точок одразу. Повернення ролі доступ НЕ відновлює — магазини доведеться відмітити наново. Це захист від тихого повернення доступу.">
          <label>Магазини</label>
          <div class="store-picker">
            <?php foreach ($stores as $s): $sid = (int)$s['id']; ?>
              <label class="checkbox">
                <input type="checkbox" name="stores[]" value="<?= $sid ?>" data-seller-stores
                       <?= $isSeller && in_array($sid, $seller_stores[$uid] ?? [], true) ? 'checked' : '' ?>
                       <?= $isSeller ? '' : 'disabled' ?>>
                <?= e($s['name'] . ($s['city'] ? ' · ' . $s['city'] : '')) ?>
              </label>
            <?php endforeach; ?>
            <?php if (!$stores): ?><span class="dim">Магазинів ще немає</span><?php endif; ?>
          </div>
          <span class="dim chan-note" data-seller-hint
                data-on="Відмічені точки веде ця людина"
                data-off="Лише для продавців — адмін і так бачить усю мережу"
          ><?= $isSeller ? 'Відмічені точки веде ця людина' : 'Лише для продавців — адмін і так бачить усю мережу' ?></span>
        </div>

        <div data-help-title="Звʼязок"
             data-help="Телефон людини й канали, якими до неї доходять сповіщення.

Номер приводиться до єдиного вигляду (+380…) автоматично — вводьте як зручно.

Кружечок ● означає, що канал привʼязаний і повідомлення дійдуть; ○ — ні.

Telegram привʼязується через chat id, Viber — тільки самим ботом, коли людина йому напише. Вписати Viber руками не можна, тому там лише стан.">
          <label>Звʼязок</label>
          <input type="tel" name="phone" value="<?= e($u['phone']) ?>" placeholder="067 123 45 67">
          <div class="chan-list">
            <?php if ($tgOn): ?>
              <div class="chan">
                <span class="chan-name">Telegram</span>
                <input type="text" name="tg_chat_id" value="<?= e($u['tg_chat_id']) ?>" placeholder="chat id">
                <span class="chan-state <?= $u['tg_chat_id'] ? 'is-on' : '' ?>"><?= $u['tg_chat_id'] ? '●' : '○' ?></span>
              </div>
            <?php endif; ?>
            <?php if ($viberOn): ?>
              <?php /* viber_id привʼязує сам бот за токеном (Viber::webhook) — руками
                       його не вписати, тому лише показуємо стан */ ?>
              <div class="chan">
                <span class="chan-name">Viber</span>
                <span class="chan-val dim"><?= $u['viber_id'] ? 'привʼязано' : 'не привʼязано' ?></span>
                <span class="chan-state <?= $u['viber_id'] ? 'is-on' : '' ?>"><?= $u['viber_id'] ? '●' : '○' ?></span>
              </div>
            <?php endif; ?>
            <?php if (!$tgOn && !$viberOn): ?>
              <span class="dim chan-note">Месенджери не налаштовані —
                <a href="<?= e(url('/admin/settings')) ?>">увімкнути</a></span>
            <?php endif; ?>
          </div>
        </div>

        <div class="user-actions">
          <label class="checkbox" data-help-title="Активний"
                 data-help="Чи може людина входити в систему.

Знята галка — вхід закритий, але дані, ролі й історія лишаються. Це правильний спосіб відключити людину, що звільнилась: акаунт не губиться, а замовлення, які вона вела, лишаються підписані її іменем.">
            <input type="checkbox" name="active" <?= $u['active'] ? 'checked' : '' ?>> Активний</label>
          <button class="btn btn-gold btn-sm" type="submit" data-help-title="Кнопка «Зберегти»"
                  data-help="Зберігає зміни лише в цій картці — ролі, магазини, телефон, канали й позначку «Активний».

У кожної людини своя кнопка: правки в інших картках не збережуться, доки не натиснете «Зберегти» в кожній із них.">Зберегти</button>
        </div>
      </form>

      <?php /* Окрема форма, а не частина картки: форми не вкладаються одна в одну,
               та й надсилання повідомлення — інша дія, ніж збереження профілю. */ ?>
      <?php if ($canTg || $canViber): ?>
        <details class="msg-box" data-help-title="Написати від бота"
                 data-help="Надсилає особисте повідомлення цій людині в месенджер від імені бота магазину.

Зручно, щоб узгодити зміну, попередити про постачання чи відповісти покупцю, який писав боту.

Приходить одразу й від імені магазину, не від вашого — тож пишіть так, як говорили б від компанії. Скасувати надіслане не можна.

Кнопка зʼявляється лише там, де канал справді привʼязаний.">
          <summary>✉ Написати від бота</summary>
          <form method="post" action="<?= e(url('/admin/users/message')) ?>" class="msg-form">
            <?= Csrf::field() ?>
            <input type="hidden" name="user_id" value="<?= $uid ?>">
            <input type="hidden" name="back" value="<?= e($back) ?>">
            <?php if ($canTg && $canViber): ?>
              <select name="channel">
                <option value="telegram">Telegram</option>
                <option value="viber">Viber</option>
              </select>
            <?php else: ?>
              <input type="hidden" name="channel" value="<?= $canTg ? 'telegram' : 'viber' ?>">
              <span class="dim">у <?= $canTg ? 'Telegram' : 'Viber' ?></span>
            <?php endif; ?>
            <textarea name="text" rows="2" required placeholder="Текст повідомлення" maxlength="2000"></textarea>
            <button class="btn btn-line btn-sm" type="submit">Надіслати</button>
          </form>
        </details>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>

<?php if ($pages > 1): ?>
  <div class="pager">
    <?php if ($page > 1): ?>
      <a class="btn btn-line btn-sm" href="<?= e(url($listUrl(['p' => $page - 1]))) ?>">← Назад</a>
    <?php endif; ?>
    <span class="dim">Сторінка <?= (int)$page ?> з <?= (int)$pages ?> · усього <?= (int)$total ?></span>
    <?php if ($page < $pages): ?>
      <a class="btn btn-line btn-sm" href="<?= e(url($listUrl(['p' => $page + 1]))) ?>">Далі →</a>
    <?php endif; ?>
  </div>
<?php endif; ?>
