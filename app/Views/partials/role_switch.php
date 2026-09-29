<?php
/**
 * Перемикач режиму перегляду. Показуємо лише тим, кому є що вдавати,
 * тобто персоналу — покупець цього блоку не бачить взагалі.
 */
$rsRoles = Auth::simulatableRoles();
if (!$rsRoles) return;

$rsActive  = Auth::actingAs();
$rsBack    = request_path();
$rsAllowed = Auth::authorityStoreIds();
$rsStores  = array_values(array_filter(
    DB::all('SELECT id, name FROM stores ORDER BY id'),
    fn($s) => in_array((int)$s['id'], $rsAllowed, true)
));
$rsStoreId = Auth::actingStoreId();
// Точка потрібна тільки ролі продавця — в інших ролях store_id сервер ігнорує.
// Якщо продавця серед доступних ролей немає, поле не показуємо взагалі.
$rsShowStore = in_array(Roles::SELLER, $rsRoles, true) && count($rsStores) > 1;
?>
<div class="role-switch<?= $rsActive !== null ? ' is-acting' : '' ?>">
  <?php if ($rsActive !== null): ?>
    <span class="role-switch-now">
      Працюю як: <b><?= e(Roles::label($rsActive)) ?></b>
      <?php if ($rsActive === Roles::SELLER && $rsStoreId):
        $rsName = '';
        foreach ($rsStores as $s) if ((int)$s['id'] === $rsStoreId) $rsName = $s['name'];
        if ($rsName !== ''): ?> · <?= e($rsName) ?><?php endif;
      endif; ?>
    </span>
    <form method="post" action="<?= e(url('/role/reset')) ?>"><?= Csrf::field() ?>
      <input type="hidden" name="back" value="<?= e($rsBack) ?>">
      <button class="btn btn-line btn-xs" type="submit">Повернути свої права</button>
    </form>
  <?php else: ?>
    <?php /* згорнуто в <details>: щодня цим не користуються, а місця в шапці займало багато.
             Без JS — працює й тоді, коли скрипти не завантажились. */ ?>
    <details class="role-switch-more">
      <summary title="Подивитись на систему очима іншої ролі">Змінити роль</summary>
      <form method="post" action="<?= e(url('/role/switch')) ?>"><?= Csrf::field() ?>
        <input type="hidden" name="back" value="<?= e($rsBack) ?>">
        <label class="dim" for="rsRole">Працювати як</label>
        <select name="role" id="rsRole">
          <?php foreach ($rsRoles as $r): ?>
            <option value="<?= e($r) ?>"><?= e(Roles::label($r)) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if ($rsShowStore): ?>
          <label class="dim rs-store-row" for="rsStore">Точка продавця</label>
          <select class="rs-store-row" name="store_id" id="rsStore">
            <?php foreach ($rsStores as $s): ?>
              <option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?></option>
            <?php endforeach; ?>
          </select>
        <?php endif; ?>
        <button class="btn btn-gold btn-xs" type="submit">Перемкнути</button>
      </form>
    </details>
  <?php endif; ?>
</div>
<?php if ($rsActive === null && $rsShowStore): ?>
<script>
// Ховаємо вибір точки для всіх ролей, крім продавця: інакше в ролі покупця
// це виглядає як обовʼязковий другий крок, хоча сервер store_id там не читає.
// Поле лишається в розмітці видимим — якщо скрипт не завантажився, це просто
// зайвий вибір, а не поламаний перемикач.
(function () {
  var seller = <?= json_encode(Roles::SELLER) ?>;
  document.querySelectorAll('.role-switch-more form').forEach(function (f) {
    var role = f.querySelector('select[name="role"]'),
        rows = f.querySelectorAll('.rs-store-row');
    if (!role || !rows.length) return;
    function sync() {
      rows.forEach(function (el) { el.hidden = role.value !== seller; });
    }
    role.addEventListener('change', sync);
    sync();
  });
})();
</script>
<?php endif; ?>
