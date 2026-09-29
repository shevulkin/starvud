<?php
/**
 * Підрозділи каталогу.  Запуск: php bin/cli.php test
 *
 * Головне правило одне: розділ показує всю свою гілку. Товар лежить рівно в
 * одній категорії, тож щойно власник розклав мед по сортах, «Мед» без цього
 * правила став би порожньою полицею — і в каталозі, і на касі, і в акції,
 * заданій на розділ. Саме це тут і доводимо, з усіх чотирьох боків.
 *
 * Друге — що каталог не зникає від однієї галки: вимкнений розділ віддає свої
 * підрозділи нагору, а не ховає їх разом із собою.
 *
 * Тест заводить власну гілку категорій із товарами й прибирає її за собою.
 */
declare(strict_types=1);

final class CategoriesTest
{
    private int $pass = 0;
    private int $fail = 0;
    private int $root = 0;
    private int $sub = 0;
    private int $other = 0;
    private int $rootProduct = 0;
    private int $subProduct = 0;
    private int $otherProduct = 0;
    private int $attribute = 0;

    public function run(): int
    {
        $this->setUp();
        try {
            $this->testBranch();
            $this->testCatalog();
            $this->testFilters();
            $this->testPos();
            $this->testPromo();
            $this->testTree();
            $this->testHiddenParent();
        } finally {
            $this->tearDown();
        }
        echo "\n" . ($this->fail === 0
            ? "УСЕ ДОБРЕ: {$this->pass} перевірок\n"
            : "ПРОВАЛЕНО: {$this->fail} з " . ($this->pass + $this->fail) . "\n");
        return $this->fail === 0 ? 0 : 1;
    }

    private function setUp(): void
    {
        $tag = bin2hex(random_bytes(3));
        $this->root = DB::insert('categories', [
            'name' => 'Тест: розділ', 'slug' => 'test-cat-' . $tag, 'type' => 'product',
            'parent_id' => null, 'sort' => 900, 'active' => 1,
        ]);
        $this->sub = DB::insert('categories', [
            'name' => 'Тест: підрозділ', 'slug' => 'test-sub-' . $tag, 'type' => 'product',
            'parent_id' => $this->root, 'sort' => 901, 'active' => 1,
        ]);
        $this->other = DB::insert('categories', [
            'name' => 'Тест: чужий розділ', 'slug' => 'test-alien-' . $tag, 'type' => 'product',
            'parent_id' => null, 'sort' => 902, 'active' => 1,
        ]);
        $this->rootProduct = $this->product('Тест: товар розділу', $this->root, $tag . '-r');
        $this->subProduct = $this->product('Тест: товар підрозділу', $this->sub, $tag . '-s');
        $this->otherProduct = $this->product('Тест: чужий товар', $this->other, $tag . '-a');
    }

    private function product(string $name, int $cat, string $tag): int
    {
        return DB::insert('products', [
            'category_id' => $cat, 'name' => $name, 'slug' => 'test-catprod-' . $tag,
            'base_price' => 100, 'active' => 1, 'made_to_order' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function tearDown(): void
    {
        foreach ([$this->rootProduct, $this->subProduct, $this->otherProduct] as $pid) {
            if (!$pid) continue;
            DB::delete('product_attrs', 'product_id = ?', [$pid]);
            DB::delete('products', 'id = ?', [$pid]);
        }
        if ($this->attribute) {
            DB::delete('attribute_categories', 'attribute_id = ?', [$this->attribute]);
            DB::delete('attributes', 'id = ?', [$this->attribute]);
        }
        foreach ([$this->sub, $this->root, $this->other] as $cid) {
            if ($cid) DB::delete('categories', 'id = ?', [$cid]);
        }
    }

    private function ok(string $what, bool $cond): void
    {
        if ($cond) { $this->pass++; echo "  ok   $what\n"; }
        else { $this->fail++; echo "  FAIL $what\n"; }
    }

    private function group(string $name): void { echo "\n== $name ==\n"; }

    /** id товарів у результаті пошуку */
    private function ids(array $rows): array
    {
        return array_map(fn($r) => (int)$r['id'], $rows);
    }

    private function testBranch(): void
    {
        $this->group('Гілка розділу');
        $branch = Catalog::branchIds($this->root);
        sort($branch);
        $want = [$this->root, $this->sub];
        sort($want);
        $this->ok('розділ віддає себе й свої підрозділи', $branch === $want);
        $this->ok('підрозділ віддає лише себе', Catalog::branchIds($this->sub) === [$this->sub]);
        $this->ok('чужий розділ до гілки не потрапляє', !in_array($this->other, $branch, true));
        $this->ok('ланцюг угору веде від підрозділу до розділу',
            Catalog::ancestorIds($this->sub) === [$this->sub, $this->root]);
        $this->ok('розділ верхнього рівня — ланцюг з одного',
            Catalog::ancestorIds($this->root) === [$this->root]);
    }

    private function testCatalog(): void
    {
        $this->group('Каталог');
        $inRoot = $this->ids(Catalog::search(['category_id' => $this->root]));
        $this->ok('розділ показує власний товар', in_array($this->rootProduct, $inRoot, true));
        $this->ok('розділ показує товар підрозділу', in_array($this->subProduct, $inRoot, true));
        $this->ok('чужий товар у розділ не потрапив', !in_array($this->otherProduct, $inRoot, true));

        $inSub = $this->ids(Catalog::search(['category_id' => $this->sub]));
        $this->ok('підрозділ звужує до себе',
            in_array($this->subProduct, $inSub, true) && !in_array($this->rootProduct, $inSub, true));
    }

    private function testFilters(): void
    {
        $this->group('Фільтри');
        // Панель фільтрів має описувати рівно те, що лежить на полиці. Якщо
        // вона збирається лише по самій категорії, то в розділі з підрозділами
        // «Сорт» не зʼявиться зовсім — фільтрувати буде нічим при повній полиці.
        $attr = Attrs::ensure('Тест: сорт меду', 'select', $this->sub);
        $this->attribute = (int)($attr['id'] ?? 0);
        DB::insert('product_attrs', [
            'product_id' => $this->subProduct, 'attribute_id' => $this->attribute,
            'name' => (string)$attr['name'], 'value' => 'Липовий', 'filterable' => 1, 'sort' => 0,
        ]);
        $slug = (string)$attr['slug'];
        $inRoot = Catalog::filterableAttrs($this->root);
        $this->ok('характеристика підрозділу потрапляє у фільтри розділу', isset($inRoot[$slug]));
        $inAlien = Catalog::filterableAttrs($this->other);
        $this->ok('у чужому розділі її немає', !isset($inAlien[$slug]));

        // Та сама характеристика, привʼязана до розділу, має пропонуватись і в
        // картці товару з підрозділу: заводять її один раз на «Мед», а не на
        // кожен сорт окремо
        Attrs::setCategories($this->attribute, [$this->root]);
        $offered = array_map(fn($a) => (int)$a['id'], Attrs::forCategory($this->sub));
        $this->ok('привʼязка до розділу діє на його підрозділи',
            in_array($this->attribute, $offered, true));
        $alien = array_map(fn($a) => (int)$a['id'], Attrs::forCategory($this->other));
        $this->ok('на чужий розділ вона не поширюється', !in_array($this->attribute, $alien, true));
    }

    private function testPos(): void
    {
        $this->group('Каса');
        $tiles = Pos::tiles(null, $this->root);
        $ids = array_map(fn($t) => (int)$t['product_id'], $tiles);
        $this->ok('плитка розділу показує товар підрозділу', in_array($this->subProduct, $ids, true));
        $this->ok('плитка розділу показує й власний товар', in_array($this->rootProduct, $ids, true));
        $this->ok('чужого товару в плитці немає', !in_array($this->otherProduct, $ids, true));
    }

    private function testPromo(): void
    {
        $this->group('Акція на розділ');
        // Акцію передаємо списком, а не покладаємось на базу: activePromotions()
        // кешує вибірку на весь запуск, і щойно створений рядок лишився б
        // непоміченим — тест довів би зворотне тому, що хотів
        $promos = [[
            'title' => 'Тест: знижка на розділ', 'percent' => 10,
            'store_id' => null, 'category_id' => $this->root, 'product_id' => null,
            'starts_at' => null, 'ends_at' => null, 'active' => 1,
        ]];
        $sub = DB::row('SELECT * FROM products WHERE id = ?', [$this->subProduct]);
        $root = DB::row('SELECT * FROM products WHERE id = ?', [$this->rootProduct]);
        $alien = DB::row('SELECT * FROM products WHERE id = ?', [$this->otherProduct]);
        $this->ok('знижка розділу дістає товар підрозділу',
            Catalog::promoPercent($sub, null, $promos) === 10.0);
        $this->ok('власний товар розділу знижку теж отримує',
            Catalog::promoPercent($root, null, $promos) === 10.0);
        $this->ok('чужого розділу знижка не чіпає',
            Catalog::promoPercent($alien, null, $promos) === 0.0);
    }

    private function testTree(): void
    {
        $this->group('Панель каталогу');
        $cats = Catalog::categories();
        $depth = [];
        foreach ($cats as $c) $depth[(int)$c['id']] = (int)($c['depth'] ?? 0);
        $this->ok('розділ — верхній рівень', ($depth[$this->root] ?? null) === 0);
        $this->ok('підрозділ — другий рівень', ($depth[$this->sub] ?? null) === 1);

        $pos = array_search($this->root, array_map(fn($c) => (int)$c['id'], $cats), true);
        $this->ok('підрозділ стоїть одразу за своїм розділом',
            $pos !== false && (int)($cats[$pos + 1]['id'] ?? 0) === $this->sub);

        $tree = Catalog::categoryTree($cats);
        $kids = [];
        foreach ($tree as $c) if ((int)$c['id'] === $this->root) $kids = array_map(fn($k) => (int)$k['id'], $c['children']);
        $this->ok('дерево віддає підрозділ усередині розділу', $kids === [$this->sub]);

        $roots = array_map(fn($c) => (int)$c['id'], Catalog::rootCategories());
        $this->ok('верхній рівень не тягне за собою підрозділи',
            in_array($this->root, $roots, true) && !in_array($this->sub, $roots, true));
    }

    private function testHiddenParent(): void
    {
        $this->group('Вимкнений розділ');
        // Вимкнений розділ не ховає своїх підрозділів — вони піднімаються
        // нагору. Інакше одна знята галка тихо прибирала б із сайту півкаталогу.
        DB::update('categories', ['active' => 0], 'id = ?', [$this->root]);
        $shown = Catalog::categories();
        $found = null;
        foreach ($shown as $c) if ((int)$c['id'] === $this->sub) $found = $c;
        $this->ok('підрозділ вимкненого розділу лишається в каталозі', $found !== null);
        $this->ok('і стає розділом верхнього рівня', (int)($found['depth'] ?? 1) === 0);
        DB::update('categories', ['active' => 1], 'id = ?', [$this->root]);
    }
}

return (new CategoriesTest())->run();
