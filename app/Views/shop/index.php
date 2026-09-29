<?php
/**
 * Каталог «Старвуд-М».
 *
 * Головний фільтр — ростомір угорі: вік дитини вибирають раніше за все інше.
 * Решта — у бічній панелі звичайною GET-формою (працює без JS). Посилання
 * смуг ростоміра й розділів зберігають уже обрані фільтри: людина уточнює
 * вибір, а не починає спочатку.
 */
$curSlug = $current_cat['slug'] ?? null;
// поточні параметри, щоб посилання смуг і розділів їх не губили
$keep = array_filter([
    'q' => $filters['q'], 'min' => $filters['min'], 'max' => $filters['max'], 'sort' => $filters['sort'],
    'age' => $filters['age'], 'instock' => $filters['instock'] ? '1' : '', 'brand' => $filters['brand'], 'attr' => array_filter($filters['attr']),
], static fn($v) => $v !== '' && $v !== [] && $v !== null);
$with = static function (array $change) use ($keep): array {
    $q = array_merge($keep, $change);
    return array_filter($q, static fn($v) => $v !== '' && $v !== null && $v !== []);
};
$hasActiveFilters = $filters['q'] !== '' || $filters['min'] !== '' || $filters['max'] !== '' || $filters['instock']
    || array_filter($filters['attr']) || $filters['brand'];
$title = $brand ? $brand['name'] : ($current_cat['name'] ?? ($filters['q'] !== '' ? 'Пошук: «' . $filters['q'] . '»' : 'Каталог'));
$catCounts = [];
foreach (DB::all('SELECT category_id, COUNT(*) n FROM products WHERE active = 1 GROUP BY category_id') as $r) $catCounts[(int)$r['category_id']] = (int)$r['n'];
?>
<div class="container sv-page">
  <nav class="sv-crumbs" aria-label="Хлібні крихти">
    <a href="<?= e(url('/')) ?>">Головна</a><span>/</span>
    <?php if ($current_cat || $brand || $filters['q'] !== ''): ?>
      <a href="<?= e(url('/shop')) ?>">Каталог</a><span>/</span>
      <?php if ($parent_cat): ?><a href="<?= e(shop_url($parent_cat['slug'])) ?>"><?= e($parent_cat['name']) ?></a><span>/</span><?php endif; ?>
      <span aria-current="page"><?= e($title) ?></span>
    <?php else: ?>
      <span aria-current="page">Каталог</span>
    <?php endif; ?>
  </nav>

  <div class="sv-shop-head">
    <div class="sv-shop-title">
      <h1><?= e($title) ?></h1>
      <span class="sv-count-head"><?= (int)$total ?> <?= plural((int)$total, 'товар', 'товари', 'товарів') ?></span>
    </div>
    <form class="sv-sort" method="get" action="<?= e(shop_url($curSlug)) ?>">
      <?php foreach ($with(['sort' => '']) as $k => $v): if (is_array($v)) continue; ?><input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>"><?php endforeach; ?>
      <?php foreach ($filters['brand'] as $b): ?><input type="hidden" name="brand[]" value="<?= e($b) ?>"><?php endforeach; ?>
      <?php foreach (array_filter($filters['attr']) as $as => $vals): foreach ((array)$vals as $v): ?><input type="hidden" name="attr[<?= e($as) ?>][]" value="<?= e($v) ?>"><?php endforeach; endforeach; ?>
      <label for="sortSel">Сортувати</label>
      <select id="sortSel" name="sort" onchange="this.form.submit()">
        <option value="">Спершу популярні</option>
        <option value="price_asc" <?= $filters['sort'] === 'price_asc' ? 'selected' : '' ?>>Спершу дешевші</option>
        <option value="price_desc" <?= $filters['sort'] === 'price_desc' ? 'selected' : '' ?>>Спершу дорожчі</option>
        <option value="new" <?= $filters['sort'] === 'new' ? 'selected' : '' ?>>Новинки</option>
      </select>
      <noscript><button class="btn btn-line btn-sm" type="submit">OK</button></noscript>
    </form>
  </div>

  <?php if ($brand && (!empty($brand['description']) || !empty($brand['logo']))): ?>
    <div class="brand-head sv-panel">
      <?php if (!empty($brand['logo'])): ?><img class="brand-logo" src="<?= e(asset($brand['logo'])) ?>" alt="<?= e($brand['name']) ?>"><?php endif; ?>
      <?php if (!empty($brand['description'])): ?><p class="lead" style="margin:0"><?= e($brand['description']) ?></p><?php endif; ?>
    </div>
  <?php endif; ?>

  <nav class="sv-agebar" id="age" aria-label="Фільтр за віком дитини">
    <?php $bands = ['' => [0, 0, 'Усі', '0–7 років', '#FFFFFF']] + Ages::BANDS;
    foreach ($bands as $k => [, , $label, $hint, $color]):
      $k = (string)$k; $on = $filters['age'] === $k;
      $n = (int)($age_counts[$k] ?? 0); ?>
      <a class="sv-agebtn sv-age-<?= $k === '' ? 'all' : e($k) ?><?= $on ? ' is-on' : '' ?><?= $n === 0 && !$on ? ' is-empty' : '' ?>" href="<?= e(shop_url($curSlug, $with(['age' => $k]))) ?>"<?= $on ? ' aria-current="true"' : '' ?>>
        <span class="sv-agebtn-l"><?= $k === '' ? 'Будь-який вік' : e($label) ?></span>
        <span class="sv-agebtn-h"><?= $n ?></span>
      </a>
    <?php endforeach; ?>
  </nav>

  <div class="sv-shop">
    <details class="sv-filters" <?= $hasActiveFilters ? 'open' : '' ?> id="filtersBox">
      <summary class="sv-filters-sum">Фільтри<?= $hasActiveFilters ? ' · обрано' : '' ?></summary>
      <form method="get" action="<?= e(shop_url($curSlug)) ?>" class="sv-filters-form">
        <?php if ($filters['age'] !== ''): ?><input type="hidden" name="age" value="<?= e($filters['age']) ?>"><?php endif; ?>
        <?php if ($filters['sort'] !== ''): ?><input type="hidden" name="sort" value="<?= e($filters['sort']) ?>"><?php endif; ?>
        <?php if ($filters['q'] !== ''): ?><input type="hidden" name="q" value="<?= e($filters['q']) ?>"><?php endif; ?>

        <fieldset>
          <legend>Категорія</legend>
          <a class="sv-catlink<?= !$current_cat ? ' is-on' : '' ?>" href="<?= e(shop_url(null, $with([]))) ?>">Усі іграшки</a>
          <?php foreach ($cat_tree as $c): $cid = (int)$c['id']; ?>
            <a class="sv-catlink<?= ($current_cat['id'] ?? 0) == $cid ? ' is-on' : '' ?>" href="<?= e(shop_url($c['slug'], $with([]))) ?>"><?= e($c['name']) ?> <span><?= $catCounts[$cid] ?? 0 ?></span></a>
            <?php foreach ($c['children'] ?? [] as $k): ?>
              <a class="sv-catlink sv-catsub<?= ($current_cat['id'] ?? 0) == $k['id'] ? ' is-on' : '' ?>" href="<?= e(shop_url($k['slug'], $with([]))) ?>"><?= e($k['name']) ?></a>
            <?php endforeach; ?>
          <?php endforeach; ?>
        </fieldset>

        <fieldset>
          <legend>Ціна, ₴</legend>
          <div class="sv-price-row">
            <label>від<input type="number" name="min" value="<?= e($filters['min']) ?>" min="0" step="1" placeholder="0" inputmode="numeric"></label>
            <label>до<input type="number" name="max" value="<?= e($filters['max']) ?>" min="0" step="1" placeholder="5000" inputmode="numeric"></label>
          </div>
        </fieldset>

        <label class="sv-check-line"><input type="checkbox" name="instock" value="1" <?= $filters['instock'] ? 'checked' : '' ?>> Лише в наявності</label>

        <?php if ($brand_options): $chosenBrands = array_map('strval', (array)$filters['brand']); ?>
          <fieldset>
            <legend>Бренд</legend>
            <?php foreach ($brand_options as $b): ?>
              <label class="sv-check-line"><input type="checkbox" name="brand[]" value="<?= e($b['slug']) ?>" <?= in_array((string)$b['slug'], $chosenBrands, true) ? 'checked' : '' ?>> <?= e($b['name']) ?> <span class="dim">(<?= (int)$b['cnt'] ?>)</span></label>
            <?php endforeach; ?>
          </fieldset>
        <?php endif; ?>

        <?php foreach ($attr_options as $slug => $a): $chosen = array_map('strval', (array)($filters['attr'][$slug] ?? [])); ?>
          <fieldset>
            <legend><?= e($a['name']) ?><?= $a['unit'] ? ', ' . e($a['unit']) : '' ?></legend>
            <div class="sv-chipset">
              <?php foreach ($a['values'] as $v): ?>
                <label class="sv-chip-check"><input type="checkbox" name="attr[<?= e($slug) ?>][]" value="<?= e($v['value']) ?>" <?= in_array((string)$v['value'], $chosen, true) ? 'checked' : '' ?>><span><?= e($v['value']) ?> <small><?= (int)$v['count'] ?></small></span></label>
              <?php endforeach; ?>
            </div>
          </fieldset>
        <?php endforeach; ?>

        <div class="sv-filter-actions">
          <button class="btn btn-gold btn-sm" type="submit">Показати</button>
          <?php if ($hasActiveFilters): ?><a class="btn btn-line btn-sm" href="<?= e(shop_url($curSlug, array_filter(['age' => $filters['age']], 'strlen'))) ?>">Скинути</a><?php endif; ?>
        </div>

        <div class="sv-tip">
          <b>Порада</b>
          <p>Для малюків до 3 років обирайте іграшки без дрібних деталей — їх легко проковтнути.</p>
        </div>
      </form>
    </details>

    <div class="sv-shop-main">
      <?php if ($filters['age'] !== '' && ($b = Ages::band($filters['age']))): ?><div class="sv-count">Для дітей <b><?= e($b[2]) ?></b> · <?= e($b[3]) ?></div><?php endif; ?>
      <?php if (!$products): ?>
        <div class="sv-empty">
          <?= mascot('shrug', 110) ?>
          <p>Нічого не знайшлося. Спробуйте інший вік або приберіть частину фільтрів.</p>
          <a class="sv-btn sv-btn-ghost" href="<?= e(url('/shop')) ?>">Усі іграшки</a>
        </div>
      <?php else: ?>
        <div class="sv-grid sv-grid-shop" id="productGrid">
          <?php foreach ($products as $prod) echo View::partial('partials/product_card', ['prod' => $prod]); ?>
        </div>
        <?php if ($pages > 1):
          $pageUrl = static fn(int $n): string => shop_url($curSlug, $with(['page' => $n > 1 ? (string)$n : '']));
          // вікно навколо поточної: 1 … 4 [5] 6 … 12
          $shown = array_unique(array_filter([1, $page - 1, $page, $page + 1, $pages], static fn($n) => $n >= 1 && $n <= $pages));
          sort($shown); ?>
          <nav class="sv-pager" aria-label="Сторінки каталогу">
            <?php if ($page > 1): ?><a class="sv-pg sv-pg-nav" href="<?= e($pageUrl($page - 1)) ?>" rel="prev" aria-label="Попередня сторінка">←</a><?php endif; ?>
            <?php $prev = 0; foreach ($shown as $n):
              if ($n - $prev > 1): ?><span class="sv-pg sv-pg-gap">…</span><?php endif; ?>
              <a class="sv-pg<?= $n === $page ? ' is-on' : '' ?>" href="<?= e($pageUrl($n)) ?>"<?= $n === $page ? ' aria-current="page"' : '' ?>><?= $n ?></a>
            <?php $prev = $n; endforeach; ?>
            <?php if ($page < $pages): ?><a class="sv-pg sv-pg-nav" href="<?= e($pageUrl($page + 1)) ?>" rel="next" aria-label="Наступна сторінка">→</a><?php endif; ?>
          </nav>
          <p class="sv-pager-note">Показано <?= ($page - 1) * $per_page + 1 ?>–<?= min($total, $page * $per_page) ?> з <?= (int)$total ?></p>
        <?php endif; ?>
      <?php endif; ?>

      <?php if ($other_products): ?>
        <div class="sv-sec-row" style="margin:72px 0 28px">
          <div><span class="sv-kicker">Популярне</span><h2 class="sv-h2" style="font-size:32px">Вас може зацікавити</h2></div>
        </div>
        <div class="sv-grid sv-grid-shop">
          <?php foreach ($other_products as $prod) echo View::partial('partials/product_card', ['prod' => $prod]); ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<script>
/* На широкому екрані фільтри завжди розгорнуті: <details> там лише обгортка */
(function(){var d=document.getElementById('filtersBox');if(!d)return;var mq=window.matchMedia('(min-width:1081px)');function f(){if(mq.matches)d.setAttribute('open','');}f();mq.addEventListener&&mq.addEventListener('change',f);})();
</script>
