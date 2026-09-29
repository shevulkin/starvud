<?php
/**
 * Набори «разом дешевше».
 *
 * Сторінка — список із редактором, а не стос форм. Спершу тут стояло шість
 * карток поспіль, серед них два майже однакові редактори складу, і зрозуміти,
 * де новий набір, а де той, що редагуєш, можна було лише прочитавши все.
 * Тепер видно з першого погляду: перелік угорі, під ним рівно один відкритий
 * набір, а створення сховане за кнопкою, поки воно не потрібне.
 *
 * @var array      $list     усі набори (зокрема вимкнені)
 * @var array|null $b        набір, який зараз редагують
 * @var array|null $preview  його склад із цінами, або null якщо щось зникло
 * @var array      $products товари для вибору
 * @var array      $variants фасовки: [product_id => [{id,name}]]
 */
// Порожня сторінка не має ховати єдину дію, яку на ній можна зробити
$openNew = !$list;

// Довга довідка — одна на сторінку, у знаку «?» біля заголовка. Раніше вона
// займала окрему картку вгорі й відсувала список, заради якого сюди йдуть.
$intro = 'Знижка за поєднання: кілька певних товарів разом коштують менше, ніж кожен окремо.

Від акції відрізняється тим, за що дається. Акція здешевлює один товар усім; набір здешевлює тільки того, хто взяв усе перелічене разом. Від опту — тим, що опт про кількість одного товару, а набір про поєднання різних.

Спрацьовує сам: щойно в кошику зустрілося все зі складу набору, знижка зʼявляється окремим рядком у підсумках. Покупцю набір також пропонується на сторінці кожного товару, який до нього входить.

Неповний набір знижки не дає — інакше «разом дешевше» перестало б означати «разом».

Знижка рахується по позиціях, тому впирається в ту саму стелю, що акція, опт і промокод: якщо на товарі вже висить акція, набір додасть лише те, що лишилось до стелі. Одна й та сама штука не потрапляє у два набори одночасно.';

$helpItems = 'Що саме має зустрітись у кошику, щоб знижка спрацювала.

Товарів має бути принаймні два різні — набір з одного це звичайна акція, і для неї є свій блок на сторінці «Акції та промокоди».

«Будь-який варіант» означає, що покупцю все одно, яку фасовку брати: набір збереться з тієї, що вже лежить у кошику. Обирайте конкретну лише тоді, коли інша справді не годиться.

Кількість більша за одиницю робить набір кратним: «2 пазли + 1 лялька» спрацює на 4 пазли і 2 ляльки — двічі.';

$helpKind = '«Відсоток» — набір дешевший на N% від суми своїх позицій. Перераховується сам, коли міняються ціни.

«Фіксована ціна» — увесь набір коштує рівно N гривень за комплект, а знижкою стає різниця зі звичайною ціною. Потрібен, коли набір продають як окрему річ і рівне число на ціннику важливіше за рівний відсоток. Якщо звичайна ціна набору нижча за вказану, знижки просто не буде — відʼємної не буває.

Ціна — за один комплект: два комплекти по 500 коштують 1000.';
?>
<div class="admin-head">
  <h1 class="h-serif" data-help-title="Набори «разом дешевше»" data-help="<?= e($intro) ?>">Набори</h1>
  <?php if ($list): ?>
    <button class="btn btn-gold btn-sm" type="button" id="newBundleToggle">+ Новий набір</button>
  <?php endif; ?>
</div>

<p class="dim" style="margin:-12px 0 22px;max-width:720px">
  Кілька товарів разом — дешевше. Знижка спрацьовує в кошику сама, щойно покупець зібрав увесь склад,
  і показується окремим рядком: «Набір «Подарунковий» −30 грн».
</p>

<form class="admin-card" method="post" action="<?= e(url('/admin/bundles')) ?>"
      id="newBundleForm"<?= $openNew ? '' : ' hidden' ?>>
  <?= Csrf::field() ?><input type="hidden" name="_action" value="add">
  <h2 class="h-serif">Новий набір</h2>

  <div class="bundle-head">
    <div style="flex:1;min-width:220px" data-help-title="Назва набору"
         data-help="Те, що покупець побачить у підсумках кошика: «Набір «Подарунковий» −108 грн».

Пишіть так, щоб назва пояснювала знижку сама: «Подарунковий», «До дня народження», «Коляска + пупс». Внутрішні позначки («набір-3», «акція жовтень») у кошику виглядають як помилка.">
      <label>Назва</label><input type="text" name="title" required placeholder="Подарунковий"></div>
    <div data-help-title="Як рахувати знижку" data-help="<?= e($helpKind) ?>">
      <label>Знижка</label>
      <select name="kind"><option value="percent">відсоток</option><option value="fixed">фіксована ціна</option></select>
    </div>
    <div style="width:120px"><label>Значення</label><input type="text" name="value" placeholder="10"></div>
  </div>

  <h3 class="bundle-sub" data-help-title="Склад набору" data-help="<?= e($helpItems) ?>">Склад</h3>
  <?= View::partial('partials/bundle_items', [
        'items' => [], 'products' => $products, 'variants' => $variants]) ?>

  <div class="admin-save" style="margin-top:20px">
    <button class="btn btn-gold btn-sm" type="submit">Створити набір</button>
    <?php if ($list): ?>
      <button class="btn btn-line btn-sm" type="button" id="newBundleCancel">Скасувати</button>
    <?php endif; ?>
  </div>
</form>

<?php if ($list): ?>
<div class="admin-card">
  <h2 class="h-serif">Усі набори</h2>
  <table class="tbl">
    <tr><th style="width:40%">Назва</th><th>Знижка</th><th>Склад</th><th class="col-mid">Активний</th><th></th></tr>
    <?php foreach ($list as $row): $isCur = $b && (int)$b['id'] === (int)$row['id']; ?>
      <tr<?= $isCur ? ' class="row-current"' : '' ?>>
        <td><a href="<?= e(url('/admin/bundles?id=' . (int)$row['id'])) ?>"><?= e($row['title']) ?></a></td>
        <td class="muted"><?= $row['kind'] === 'fixed'
              ? e(price_fmt((float)$row['value'])) . ' за комплект'
              : '−' . e(QtyDiscounts::pct((float)$row['value'])) . '%' ?></td>
        <?php /* Порожній склад — не «0 товарів», а несправність: такий набір
                 ніколи не спрацює, і сказати про це треба словом, а не нулем */ ?>
        <td class="muted"><?= count($row['items'])
              ? e(plural_n(count($row['items']), 'товар', 'товари', 'товарів'))
              : '<span style="color:var(--danger2)">порожній</span>' ?></td>
        <td class="col-mid muted"><?= $row['active'] ? 'так' : '—' ?></td>
        <td class="col-mid">
          <?php if ($isCur): ?>
            <span class="muted" style="font-size:12.5px;white-space:nowrap">редагується ↓</span>
          <?php else: ?>
            <a class="btn btn-line btn-xs" href="<?= e(url('/admin/bundles?id=' . (int)$row['id'])) ?>">Змінити</a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>

<?php if ($b): ?>
<?php /* Один набір — одна картка. Три картки поспіль (поля, склад, видалення)
         виглядали як три різні речі, хоч це одна: те, що зараз редагують. */ ?>
<div class="admin-card">
  <h2 class="h-serif">Набір «<?= e($b['title']) ?>»</h2>

  <form method="post" action="<?= e(url('/admin/bundles')) ?>">
    <?= Csrf::field() ?>
    <input type="hidden" name="_action" value="save">
    <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">

    <div class="bundle-head">
      <div style="flex:1;min-width:220px"><label>Назва</label>
        <input type="text" name="title" value="<?= e($b['title']) ?>" required></div>
      <div data-help-title="Як рахувати знижку" data-help="<?= e($helpKind) ?>">
        <label>Знижка</label>
        <select name="kind">
          <option value="percent"<?= $b['kind'] !== 'fixed' ? ' selected' : '' ?>>відсоток</option>
          <option value="fixed"<?= $b['kind'] === 'fixed' ? ' selected' : '' ?>>фіксована ціна</option>
        </select>
      </div>
      <div style="width:120px"><label>Значення</label>
        <input type="text" name="value" value="<?= e(QtyDiscounts::pct((float)$b['value'])) ?>"></div>
      <div style="width:100px"><label>Порядок</label>
        <input type="number" name="sort" value="<?= (int)$b['sort'] ?>"></div>
      <label class="checkbox" style="align-self:center" data-help-title="Активний"
             data-help="Знята галка прибирає набір із кошика й зі сторінок товарів одразу. Склад і знижка зберігаються — увімкнете назад, і все повернеться.

Так вимикають сезонні набори: видаляти їх, щоб зібрати наново через рік, немає потреби.">
        <input type="checkbox" name="active" <?= $b['active'] ? 'checked' : '' ?>> Активний</label>
    </div>

    <h3 class="bundle-sub" data-help-title="Склад набору" data-help="<?= e($helpItems) ?>">Склад</h3>
    <?= View::partial('partials/bundle_items', [
          'items' => $b['items'], 'products' => $products, 'variants' => $variants]) ?>

    <?php if ($preview): ?>
      <h3 class="bundle-sub">Що побачить покупець</h3>
      <table class="tbl bundle-preview">
        <?php foreach ($preview['expanded'] as $it): ?>
          <tr>
            <td><?= e($it['product']['name']) ?><?= $it['variant'] ? ', ' . e($it['variant']['name']) : '' ?></td>
            <td class="num muted"><?= (int)$it['qty'] ?> шт</td>
            <td class="num muted"><?= e(price_fmt($it['price'] * $it['qty'])) ?></td>
          </tr>
        <?php endforeach; ?>
        <tr class="bundle-preview-sum">
          <td colspan="2">Окремо</td>
          <td class="num"><s class="dim"><?= e(price_fmt($preview['sum'])) ?></s></td>
        </tr>
        <tr class="bundle-preview-sum">
          <td colspan="2"><b>Набором</b></td>
          <td class="num"><b style="color:var(--gold)"><?= e(price_fmt($preview['total'])) ?></b></td>
        </tr>
      </table>
      <p class="dim" style="margin:10px 0 0;font-size:13px">
        Вигода — <b><?= e(price_fmt($preview['cut'])) ?></b>.
        Ціни без урахування магазину; стеля знижки може зменшити її на конкретному товарі.
      </p>
    <?php elseif ($b['items']): ?>
      <p style="margin:14px 0 0;color:var(--danger2)">
        Набір зараз не збереться: якогось товару зі складу вже немає, він вимкнений або в нього немає ціни.
      </p>
    <?php endif; ?>

    <div class="admin-save" style="margin-top:20px">
      <button class="btn btn-gold" type="submit">💾 Зберегти</button>
    </div>
  </form>

  <?php /* Видалення — рядок унизу тієї ж картки, а не власна картка: окрема
           картка робила з нього дію такої ж ваги, як «Зберегти». */ ?>
  <div class="bundle-danger">
    <form method="post" action="<?= e(url('/admin/bundles')) ?>"
          onsubmit="return confirm('Видалити набір «<?= e($b['title']) ?>»? Скасувати буде нічим.')">
      <?= Csrf::field() ?>
      <input type="hidden" name="_action" value="delete">
      <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
      <button class="btn btn-danger btn-xs" type="submit">Видалити набір</button>
      <span class="dim">Товари й ціни лишаться на місці — зникне лише сам набір.</span>
    </form>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>

<script>
/* Створення сховане, поки в нього не клікнули: на сторінці зі списком головна
   дія — відкрити наявний набір, а не завести ще один. */
(function () {
  var form = document.getElementById('newBundleForm');
  var open = document.getElementById('newBundleToggle');
  var cancel = document.getElementById('newBundleCancel');
  if (!form || !open) return;
  open.addEventListener('click', function () {
    form.hidden = !form.hidden;
    if (!form.hidden) {
      form.scrollIntoView({behavior: 'smooth', block: 'start'});
      form.querySelector('[name=title]').focus();
    }
  });
  if (cancel) cancel.addEventListener('click', function () { form.hidden = true; });
})();
</script>
