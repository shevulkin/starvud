<?php /** @var array $prod */
/*
 * Картка товару. Головне в ній — фото: велике, на світлому тлі, без рамок,
 * що змагалися б із самою іграшкою. Далі вік (для батьків це перший фільтр),
 * назва, ціна й кнопка.
 */
$cardVariants = Catalog::variants((int)$prod['id']);
$cardVariant = $cardVariants[0] ?? null;
$needsChoice = count($cardVariants) > 1;
$cardLimit = $needsChoice ? null
    : Cart::limit((int)$prod['id'], $cardVariant ? (int)$cardVariant['id'] : null, $prod);
$soldOut = $cardLimit !== null && $cardLimit <= 0;
[$pr, $old] = Catalog::price($prod, $cardVariant);
$age = Ages::label($prod);
$tiers = Catalog::qtyTiers($prod);
$bestTier = $tiers ? end($tiers) : null;
$href = url('/product/' . $prod['slug']);
$cardCat = $cardCats[(int)$prod['category_id']] ?? null;
$photo = Catalog::photo($prod);
?>
<article class="card sv-card<?= $soldOut ? ' is-out' : '' ?>">
  <a class="sv-card-img" href="<?= e($href) ?>" tabindex="-1" aria-hidden="true">
    <img src="<?= e(asset(Images::displayThumb($photo))) ?>" alt="" loading="lazy" decoding="async">
    <span class="sv-card-tags">
      <?php if ($old !== null): ?><span class="sv-tag sv-tag-sale">−<?= (int)round((1 - $pr / $old) * 100) ?>%</span>
      <?php elseif (!empty($prod['featured'])): ?><span class="sv-tag sv-tag-hit">Хіт</span><?php endif; ?>
      <?php if ($soldOut): ?><span class="sv-tag sv-tag-out">Очікується</span><?php endif; ?>
    </span>
  </a>
  <div class="sv-card-body">
    <div class="sv-card-meta">
      <?php if ($age !== ''): ?><span class="sv-card-age"><?= e($age) ?></span><?php endif; ?>
      <?php if ($cardCat): ?><span class="sv-card-cat"><?= e($cardCat) ?></span><?php endif; ?>
    </div>
    <h3 class="sv-card-title"><a href="<?= e($href) ?>"><?= e($prod['name']) ?></a></h3>
    <div class="sv-card-foot">
      <div class="sv-price">
        <?php if ($old !== null): ?><s><?= e(price_fmt($old)) ?></s><?php endif; ?>
        <b><?= e(price_label($pr, (bool)$prod['made_to_order'])) ?></b>
        <?php if ($bestTier && !$soldOut): ?><small>від <?= (int)$bestTier['min_qty'] ?> шт — <?= e(price_fmt(round((float)$pr * (1 - (float)$bestTier['percent'] / 100), 2))) ?></small><?php endif; ?>
      </div>
      <?php if ($needsChoice): ?>
        <a class="sv-cart-btn" href="<?= e($href) ?>" aria-label="Обрати варіант «<?= e($prod['name']) ?>»">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
        </a>
      <?php elseif ($soldOut): ?>
        <a class="sv-cart-btn is-ghost" href="<?= e($href) ?>" aria-label="Повідомити про наявність «<?= e($prod['name']) ?>»" title="Повідомити, коли зʼявиться">
          <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 16V11a6 6 0 0112 0v5l1.5 2h-15z"/><path d="M10 20a2 2 0 004 0"/></svg>
        </a>
      <?php else: ?>
        <form method="post" action="<?= e(url('/cart/add')) ?>" class="add-cart-form" data-product-name="<?= e($prod['name']) ?>"><?= Csrf::field() ?>
          <input type="hidden" name="product_id" value="<?= (int)$prod['id'] ?>">
          <?php if ($cardVariant): ?><input type="hidden" name="variant_id" value="<?= (int)$cardVariant['id'] ?>"><?php endif; ?>
          <input type="hidden" name="back" value="<?= e(request_path()) ?>">
          <button class="sv-cart-btn" type="submit" aria-label="Додати «<?= e($prod['name']) ?>» у кошик" title="У кошик">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 8h14l-1.2 11.2a2 2 0 01-2 1.8H8.2a2 2 0 01-2-1.8z"/><path d="M9 8V6a3 3 0 016 0v2"/><path d="M12 12v5M9.5 14.5h5"/></svg>
          </button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</article>
