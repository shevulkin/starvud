<section class="section" style="padding-top:48px">
  <div class="container narrow">
    <div class="kicker">Кошик</div>
    <h2>Ваше замовлення</h2>
    <?php if (!$rows): ?>
      <p class="muted" style="padding:28px 0 4px">Тут поки порожньо.
        <a href="<?= e(url('/shop')) ?>">Перейти до магазину →</a></p>
      <?php if ($suggest): ?>
        <div class="kicker" style="margin-top:40px">З чого почати</div>
        <div class="grid grid-4" style="margin-top:16px">
          <?php foreach ($suggest as $prod): ?>
            <?= View::partial('partials/product_card', ['prod' => $prod]) ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    <?php else: ?>
      <table class="cart-table sv-cart-table" style="margin-top:30px">
        <tr><th>Товар</th><th></th><th>Ціна</th><th>К-сть</th><th>Сума</th><th></th></tr>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td class="cc-img" style="width:76px"><img class="cart-thumb" src="<?= e(asset($r['photo'])) ?>" alt=""></td>
            <td class="cc-name">
              <a href="<?= e(url('/product/' . $r['product']['slug'])) ?>"><b><?= e($r['product']['name']) ?></b></a>
              <?php if ($r['variant']): ?><div class="dim"><?= e($r['variant']['name']) ?></div><?php endif; ?>
              <?php
                // наявність саме цієї позиції (варіанта) по магазинах
                $where = [];
                foreach ($stores as $s) {
                    $q = (int)($r['stock'][(int)$s['id']] ?? 0);
                    if ($q >= $r['qty']) $where[] = $s['city'] ?: $s['name'];
                }
              ?>
              <?php if ($where): ?>
                <div class="dim">Є в наявності: <?= e(implode(', ', $where)) ?></div>
              <?php elseif ($r['product']['made_to_order']): ?>
                <div class="dim">Немає в магазинах — <?= e(Catalog::madeToOrderShort($r['product'])) ?></div>
              <?php else: ?>
                <div class="dim" style="color:var(--danger2)">Немає в наявності</div>
              <?php endif; ?>
            </td>
            <td class="cc-price">
              <?php /* Домовлена ціна називає себе вголос. Без підпису рядок
                       виглядав би як помилка в прайсі: сума в кошику не
                       збігається ні з ціною на сторінці товару, ні з жодною
                       знижкою, і пояснити її нічим. */ ?>
              <?php if (!empty($r['offer_id'])): ?>
                <?php if ($r['old'] !== null): ?><s class="dim"><?= e(price_fmt($r['old'])) ?></s><br><?php endif; ?>
                <?= e(price_fmt($r['price'])) ?>
                <div class="cart-tier is-on">домовлена ціна</div>
              <?php else: ?>
              <?php if (($r['wholesale'] ?? 0) > 0 && $r['old'] !== null): ?>
                <s class="dim"><?= e(price_fmt($r['old'])) ?></s><br>
              <?php endif; ?>
              <?= e(price_fmt($r['price'])) ?>
              <?php if (($r['wholesale'] ?? 0) > 0): ?>
                <div class="cart-tier is-on">опт −<?= e(QtyDiscounts::pct((float)$r['wholesale'])) ?>%</div>
              <?php endif; ?>
              <?php
                /* Наступний поріг. Найдорожча помилка оптової шкали — та, про
                   яку покупець дізнається після оформлення: «взяв би девʼять,
                   якби знав». Тому підказка стоїть рівно там, де кнопка «+»,
                   і називає, скільки саме не вистачає. Відсоток уже обрізаний
                   стелею в Cart::detailed — обіцяємо те, що справді буде. */
                $next = $r['next_tier'] ?? null;
              ?>
              <?php if ($next): ?>
                <div class="cart-tier">
                  ще <?= (int)$next['need'] ?> шт — і по <?= e(price_fmt($next['each'] ?? 0)) ?>/шт
                </div>
              <?php endif; ?>
              <?php endif; ?>
            </td>
            <td class="cc-qty">
              <?php /* Кількість домовленої партії не міняється: ціну назвали
                       саме за неї. Замість кнопок — число й одне слово, чому
                       кнопок немає; прибрати рядок цілком можна й далі. */ ?>
              <?php if (!empty($r['offer_id'])): ?>
                <b><?= (int)$r['qty'] ?></b>
                <div class="cart-tier">партія за домовленістю</div>
              <?php else: ?>
              <form method="post" action="<?= e(url('/cart/update')) ?>" style="display:inline-flex;gap:6px;align-items:center">
                <?= Csrf::field() ?>
                <input type="hidden" name="key" value="<?= e($r['key']) ?>">
                <input type="hidden" name="qty" value="<?= (int)$r['qty'] ?>">
                <button class="btn btn-line btn-xs" name="action" value="dec">−</button>
                <b><?= (int)$r['qty'] ?></b>
                <button class="btn btn-line btn-xs" name="action" value="inc">+</button>
              </form>
              <?php endif; ?>
            </td>
            <td class="cc-sum">
              <?php /* Позицію, що ввійшла в набір, показуємо зі знятою знижкою,
                       але з підписом: інакше сума рядка не сходиться з тим, що
                       написано в стовпці «Ціна». */ ?>
              <?php if (($r['bundle_cut'] ?? 0) > 0): ?>
                <s class="dim"><?= e(price_fmt($r['sum'])) ?></s><br>
                <b><?= e(price_fmt($r['sum'] - $r['bundle_cut'])) ?></b>
                <div class="cart-tier is-on">у наборі</div>
              <?php else: ?>
                <b><?= e(price_fmt($r['sum'])) ?></b>
              <?php endif; ?>
            </td>
            <td class="cc-del">
              <form method="post" action="<?= e(url('/cart/update')) ?>"><?= Csrf::field() ?>
                <input type="hidden" name="key" value="<?= e($r['key']) ?>">
                <button class="btn btn-danger btn-xs" name="action" value="remove" title="Прибрати">✕</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>

      <?php
      /* «Ще трохи — і дешевше». Найдорожча помилка набору — та, про яку
         покупець дізнається після оформлення: «взяв би й прополіс, якби знав».
         Кошик — момент найвищої готовності купити, і саме тут ми точно
         знаємо, чого бракує й скільки на цьому втрачається.

         Під таблицею, а не під кожною позицією: набір належить кошику, а не
         рядку, і повторений під трьома товарами він читався б як реклама.
         Показуємо не більше двох — далі це вже перелік чужих товарів. */
      $suggest = array_slice($totals['bundle_suggest'] ?? [], 0, 2);
      ?>
      <?php if ($suggest): ?>
        <div class="bundle-hint-list">
          <?php foreach ($suggest as $s): ?>
            <div class="bundle-hint">
              <div class="bundle-hint-text">
                <b>Ще трохи — і дешевше.</b>
                Додайте
                <?php foreach ($s['need'] as $i => $n): ?>
                  <?= $i ? ' і ' : '' ?><span class="bundle-hint-need"><?= e($n['product']['name']) ?><?=
                    $n['variant'] ? ', ' . e($n['variant']['name']) : '' ?><?=
                    $n['short'] > 1 ? ' × ' . (int)$n['short'] : '' ?></span>
                <?php endforeach; ?>
                — і набір «<?= e($s['bundle']['title']) ?>» здешевшає на
                <b class="bundle-hint-cut"><?= e(price_fmt($s['cut'])) ?></b>.
              </div>
              <form method="post" action="<?= e(url('/cart/add-bundle')) ?>">
                <?= Csrf::field() ?>
                <input type="hidden" name="bundle_id" value="<?= (int)$s['bundle']['id'] ?>">
                <button class="btn btn-gold btn-sm" type="submit">Додати те, чого бракує</button>
              </form>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php
      /* Скільки лишилось до безкоштовної доставки. Поріг обіцяний у шапці на
         кожній сторінці — у кошику він має стати конкретним числом. */
      $freeFrom = (float)Settings::get('free_shipping_from', '0');
      $left = $freeFrom > 0 ? $freeFrom - (float)$totals['total'] : 0;
      ?>
      <?php if ($freeFrom > 0): ?>
        <div class="sv-freeship<?= $left <= 0 ? ' is-done' : '' ?>">
          <?php if ($left > 0): ?>
            <p>Додайте ще <b><?= e(price_fmt($left)) ?></b> — і доставка Новою Поштою буде безкоштовною</p>
          <?php else: ?>
            <p><b>Доставка безкоштовна</b> — сума замовлення понад <?= e(price_fmt($freeFrom)) ?></p>
          <?php endif; ?>
          <span class="sv-freeship-bar"><i style="width:<?= (int)min(100, round((float)$totals['total'] / $freeFrom * 100)) ?>%"></i></span>
        </div>
      <?php endif; ?>

      <div class="totals">
        <?php /* Набір називаємо поіменно й до підсумку. Знижку за кількість
                 видно з ціни позиції, а знижку за поєднання — ні: вона
                 виникає з того, ЩО лежить поруч, і без підпису читається як
                 помилка в рахунку. */ ?>
        <?php if ($totals['bundles'] ?? []): ?>
          <div class="row"><span class="muted">Товари:</span><span><?= e(price_fmt($totals['subtotal'])) ?></span></div>
          <?php foreach ($totals['bundles'] as $hit): ?>
            <div class="row"><span class="muted"><?= e(Bundles::label($hit)) ?>:</span>
              <span>−<?= e(price_fmt($hit['cut'])) ?></span></div>
          <?php endforeach; ?>
        <?php endif; ?>
        <div class="row grand"><span>Разом:</span><span><?= e(price_fmt($totals['total'])) ?></span></div>
      </div>
      <div class="sv-cart-actions">
        <a class="btn btn-line" href="<?= e(url('/shop')) ?>">Продовжити покупки</a>
        <a class="btn btn-gold" href="<?= e(url('/checkout')) ?>">Оформити замовлення</a>
      </div>
    <?php endif; ?>
  </div>
</section>
