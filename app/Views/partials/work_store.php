<?php
/**
 * Робоча точка. Показуємо лише продавцю і лише коли точок більше однієї:
 * з єдиною точкою вибір нічого не дає, а зайвий елемент у меню лише шумить.
 */
if (Auth::role() !== Roles::SELLER) return;
$wsAllowed = Auth::authorityStoreIds();
if (count($wsAllowed) < 2) return;
$wsStores = array_values(array_filter(
    DB::all('SELECT id, name FROM stores ORDER BY id'),
    fn($s) => in_array((int)$s['id'], $wsAllowed, true)
));
$wsCur = Auth::workStoreId();
?>
<form method="post" action="<?= e(url('/role/store')) ?>" class="work-store"><?= Csrf::field() ?>
  <input type="hidden" name="back" value="<?= e(request_path()) ?>">
  <label class="dim" for="wsSel">Робоча точка</label>
  <select name="store_id" id="wsSel" onchange="this.form.submit()">
    <option value="">Усі мої точки</option>
    <?php foreach ($wsStores as $s): ?>
      <option value="<?= (int)$s['id'] ?>" <?= $wsCur === (int)$s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <noscript><button class="btn btn-line btn-xs" type="submit">Застосувати</button></noscript>
</form>
