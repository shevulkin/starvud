<?php
/**
 * Оптові знижки: шкала за кількістю.  Запуск: php bin/cli.php test
 *
 * Головне, що доводимо, — два правила, які легко порушити непомітно:
 *
 * 1. Виграє НАЙБЛИЖЧИЙ заповнений ярус, цілком. Не найбільший відсоток серед
 *    усіх (як в акціях): інакше шкала розділу назавжди перебивала б шкалу
 *    товару, і сказати «на цей сорт опт менший» стало б неможливо.
 *
 * 2. Знижки складаються, але впираються в стелю. Стеля обрізає те, що
 *    ДОДАЄТЬСЯ, і ніколи не піднімає ціну, яку власник призначив сам.
 *
 * Тест створює власні розділи, товари й шкали, а наприкінці прибирає їх.
 */
declare(strict_types=1);

final class WholesaleTest
{
    private int $pass = 0;
    private int $fail = 0;

    /** @var int[] прибрати наприкінці */
    private array $cats = [];
    private array $products = [];
    private array $codes = [];

    private int $catRoot = 0;    // розділ зі своєю шкалою
    private int $catSub = 0;     // підрозділ без шкали — має брати від батька
    private int $catBare = 0;    // розділ без шкали — має брати загальну

    private int $pOwn = 0;       // товар зі своєю шкалою
    private int $pCat = 0;       // товар без своєї — бере від розділу
    private int $pSub = 0;       // товар у підрозділі — бере від батьківського розділу
    private int $pGlobal = 0;    // товар у голому розділі — бере загальну
    private int $pOff = 0;       // товар із вимкненим оптом
    private int $pMix = 0;       // товар із фасовками, опт по товару загалом
    private int $pSplit = 0;     // те саме, але опт по кожній фасовці окремо

    private array $variants = [];   // [product_id => [variant_id, ...]]

    public function run(): int
    {
        $this->setUp();
        try {
            $this->testLadder();
            $this->testSwitch();
            $this->testPercent();
            $this->testNextTier();
            $this->testValidation();
            $this->testCartScope();
            $this->testCap();
            $this->testPromoInteraction();
            $this->testRounding();
        } finally {
            $this->tearDown();
        }
        echo "\n" . ($this->fail === 0
            ? "УСЕ ДОБРЕ: {$this->pass} перевірок\n"
            : "ПРОВАЛЕНО: {$this->fail} з " . ($this->pass + $this->fail) . "\n");
        return $this->fail === 0 ? 0 : 1;
    }

    // ------------------------------------------------------------------ підготовка

    private function setUp(): void
    {
        $this->catRoot = $this->mkCat('Опт-розділ', null);
        $this->catSub  = $this->mkCat('Опт-підрозділ', $this->catRoot);
        $this->catBare = $this->mkCat('Опт-голий', null);

        $this->pOwn    = $this->mkProduct('Опт свій', $this->catRoot, 100.0);
        $this->pCat    = $this->mkProduct('Опт розділу', $this->catRoot, 100.0);
        $this->pSub    = $this->mkProduct('Опт підрозділу', $this->catSub, 100.0);
        $this->pGlobal = $this->mkProduct('Опт загальний', $this->catBare, 100.0);
        $this->pOff    = $this->mkProduct('Опт вимкнено', $this->catRoot, 100.0, ['wholesale' => 0]);
        $this->pMix    = $this->mkProduct('Свічки', $this->catRoot, 100.0, ['qty_scope' => 'product']);
        $this->pSplit  = $this->mkProduct('Шапки', $this->catRoot, 100.0, ['qty_scope' => 'variant']);

        foreach ([$this->pMix, $this->pSplit] as $pid) {
            $this->variants[$pid] = [
                $this->mkVariant($pid, 'A'),
                $this->mkVariant($pid, 'Б'),
            ];
        }

        // Три яруси одразу: далі жоден тест їх не міняє, тому кеш шкал ніде
        // не розходиться з базою. Записуємо тим самим QtyDiscounts::save(),
        // яким їх пише адмінка, — інакше перевіряли б не той шлях.
        QtyDiscounts::save(null, null, [['min_qty' => 3, 'percent' => 3]]);
        QtyDiscounts::save(null, $this->catRoot, [['min_qty' => 4, 'percent' => 4]]);
        QtyDiscounts::save($this->pOwn, null, [
            ['min_qty' => 5, 'percent' => 5],
            ['min_qty' => 10, 'percent' => 7],
        ]);

        $_SESSION = [];
    }

    private function mkCat(string $name, ?int $parent): int
    {
        $id = DB::insert('categories', [
            'name' => $name, 'slug' => 'w-' . bin2hex(random_bytes(4)),
            'parent_id' => $parent, 'type' => 'product', 'sort' => 900, 'active' => 1,
        ]);
        $this->cats[] = $id;
        return $id;
    }

    private function mkProduct(string $name, int $cat, float $price, array $extra = []): int
    {
        $id = DB::insert('products', array_merge([
            'category_id' => $cat, 'name' => $name,
            'slug' => 'w-' . bin2hex(random_bytes(4)),
            'base_price' => $price, 'active' => 1, 'made_to_order' => 1,
            'wholesale' => 1, 'qty_scope' => 'product',
            'created_at' => now(), 'updated_at' => now(),
        ], $extra));
        $this->products[] = $id;
        return $id;
    }

    private function mkVariant(int $productId, string $name): int
    {
        return DB::insert('product_variants', [
            'product_id' => $productId, 'name' => $name, 'active' => 1, 'sort' => 0,
        ]);
    }

    private function tearDown(): void
    {
        foreach ($this->products as $id) {
            DB::delete('qty_discounts', 'product_id = ?', [$id]);
            DB::delete('product_variants', 'product_id = ?', [$id]);
            DB::delete('products', 'id = ?', [$id]);
        }
        foreach ($this->cats as $id) {
            DB::delete('qty_discounts', 'category_id = ?', [$id]);
            DB::delete('categories', 'id = ?', [$id]);
        }
        DB::delete('qty_discounts', 'product_id IS NULL AND category_id IS NULL');
        foreach ($this->codes as $c) DB::delete('promo_codes', 'code = ?', [$c]);
        Catalog::forgetCaches();
        $_SESSION = [];
    }

    private function ok(string $what, bool $cond): void
    {
        if ($cond) { $this->pass++; echo "  ok   $what\n"; }
        else { $this->fail++; echo "  FAIL $what\n"; }
    }

    private function group(string $name): void { echo "\n== $name ==\n"; }

    private function product(int $id): array { return DB::row('SELECT * FROM products WHERE id = ?', [$id]); }

    /** Кошик з однієї позиції */
    private function cart(int $productId, int $qty, ?int $variantId = null): void
    {
        $_SESSION['cart'] = [
            $productId . ':' . ($variantId ?? 0) =>
                ['product_id' => $productId, 'variant_id' => $variantId, 'qty' => $qty],
        ];
    }

    // ------------------------------------------------------------------ ярусність

    private function testLadder(): void
    {
        $this->group('виграє найближчий заповнений ярус');

        $own = Catalog::qtyResolve($this->product($this->pOwn));
        $this->ok('товар зі своєю шкалою бере свою', $own['level'] === 'product' && count($own['tiers']) === 2);
        $this->ok('і саме її пороги, а не розділу',
            (int)$own['tiers'][0]['min_qty'] === 5 && (float)$own['tiers'][0]['percent'] === 5.0);

        $cat = Catalog::qtyResolve($this->product($this->pCat));
        $this->ok('без своєї — бере шкалу розділу',
            $cat['level'] === 'category' && (int)$cat['tiers'][0]['min_qty'] === 4);

        $sub = Catalog::qtyResolve($this->product($this->pSub));
        $this->ok('підрозділ без шкали піднімається до батьківського розділу',
            $sub['level'] === 'category' && (int)$sub['category_id'] === $this->catRoot);

        $glob = Catalog::qtyResolve($this->product($this->pGlobal));
        $this->ok('розділ без шкали віддає товар загальній',
            $glob['level'] === 'global' && (int)$glob['tiers'][0]['min_qty'] === 3);

        // Найголовніше: ярус нижче ЗАМІНЮЄ верхній, а не змішується з ним.
        // 10 штук товару зі своєю шкалою дають 7% — свої, а не 4% розділу
        // й не 3% загальної, хоч ті теж «підійшли б» за кількістю.
        $this->ok('шкала товару замінює шкалу розділу цілком',
            Catalog::qtyPercent($this->product($this->pOwn), 10) === 7.0);
        $this->ok('і при кількості, де діяв би тільки розділ, опту немає',
            Catalog::qtyPercent($this->product($this->pOwn), 4) === 0.0);
    }

    private function testSwitch(): void
    {
        $this->group('вимикач опту');
        $off = $this->product($this->pOff);
        $this->ok('вимкнений опт не бере навіть шкалу розділу', Catalog::qtyTiers($off) === []);
        $this->ok('і не дає жодного відсотка', Catalog::qtyPercent($off, 100) === 0.0);
        $this->ok('ярус так і зветься — вимкнено', Catalog::qtyResolve($off)['level'] === 'off');
        // Товар зі старої бази, де стовпця ще немає, має поводитись як увімкнений:
        // міграція не привід тихо вимкнути знижки на всьому каталозі.
        $this->ok('запис без стовпця вважається увімкненим', Catalog::wholesale(['id' => 1]));
    }

    private function testPercent(): void
    {
        $this->group('скільки дає шкала');
        $p = $this->product($this->pOwn);
        $this->ok('нижче першого порога — нічого', Catalog::qtyPercent($p, 4) === 0.0);
        $this->ok('рівно на порозі — вже так', Catalog::qtyPercent($p, 5) === 5.0);
        $this->ok('між порогами діє менший', Catalog::qtyPercent($p, 9) === 5.0);
        $this->ok('на другому порозі — більший', Catalog::qtyPercent($p, 10) === 7.0);
        $this->ok('вище останнього порога більшого не буває', Catalog::qtyPercent($p, 1000) === 7.0);
    }

    private function testNextTier(): void
    {
        $this->group('підказка про наступний поріг');
        $p = $this->product($this->pOwn);

        $n = Catalog::nextTier($p, 3);
        $this->ok('до першого порога — двох штук', $n !== null && $n['need'] === 2 && $n['percent'] === 5.0);

        $n = Catalog::nextTier($p, 5);
        $this->ok('на першому порозі кличе до другого', $n !== null && $n['need'] === 5 && $n['percent'] === 7.0);

        $this->ok('після останнього кликати нікуди', Catalog::nextTier($p, 10) === null);
        $this->ok('без шкали підказки немає', Catalog::nextTier($this->product($this->pOff), 1) === null);
    }

    // ------------------------------------------------------------------ запис шкали

    private function testValidation(): void
    {
        $this->group('що редактор шкали не пропустить');
        $pid = $this->mkProduct('Опт перевірка', $this->catBare, 100.0);

        $err = QtyDiscounts::save($pid, null, [
            ['min_qty' => '', 'percent' => ''],          // порожній рядок — не помилка
            ['min_qty' => 1, 'percent' => 5],            // «від 1 шт» — це не опт
            ['min_qty' => 6, 'percent' => 0],            // нуль відсотків — не знижка
            ['min_qty' => 8, 'percent' => 5],
            ['min_qty' => 12, 'percent' => 4],           // більша партія дешевша менше
        ]);
        $tiers = QtyDiscounts::level($pid, null);

        $this->ok('лишився рівно один придатний поріг', count($tiers) === 1);
        $this->ok('і це саме «від 8 шт −5%»',
            (int)$tiers[0]['min_qty'] === 8 && (float)$tiers[0]['percent'] === 5.0);
        $this->ok('про кожен відкинутий рядок сказано', count($err) === 3);
        $this->ok('порожній рядок помилкою не вважається',
            !array_filter($err, fn($e) => str_contains($e, 'від 0 шт')));

        // Кома як десятковий знак: саме її дає українська розкладка, і «7,5»
        // не має ставати сімкою чи нулем
        QtyDiscounts::save($pid, null, [['min_qty' => 5, 'percent' => '7,5']]);
        $this->ok('кома в відсотку читається як кома',
            (float)QtyDiscounts::level($pid, null)[0]['percent'] === 7.5);

        // Порожня шкала — осмислена дія: товар повертається до шкали розділу
        QtyDiscounts::save($pid, null, []);
        $this->ok('порожня форма очищає ярус', QtyDiscounts::level($pid, null) === []);
        $this->ok('і товар падає на ярус вище',
            Catalog::qtyResolve($this->product($pid))['level'] === 'global');
    }

    // ------------------------------------------------------------------ кошик

    private function testCartScope(): void
    {
        $this->group('що рахувати за поріг');
        [$a, $b] = $this->variants[$this->pMix];

        // Три однієї фасовки плюс дві іншої = пʼять свічок. Поріг «від 4»
        // (шкала розділу) має спрацювати на ОБИДВОХ рядках.
        $_SESSION['cart'] = [
            $this->pMix . ':' . $a => ['product_id' => $this->pMix, 'variant_id' => $a, 'qty' => 3],
            $this->pMix . ':' . $b => ['product_id' => $this->pMix, 'variant_id' => $b, 'qty' => 2],
        ];
        $rows = Cart::detailed();
        $this->ok('фасовки склались у пʼять штук', ($rows[0]['tier_qty'] ?? 0) === 5);
        $this->ok('знижку дістав перший рядок', (float)$rows[0]['wholesale'] === 4.0);
        $this->ok('і другий теж', (float)$rows[1]['wholesale'] === 4.0);
        $this->ok('ціна впала на ті самі 4%', (float)$rows[0]['price'] === 96.0);
        $this->ok('стара ціна показана для закреслення', (float)$rows[0]['old'] === 100.0);

        // Той самий набір, але фасовки рахуються окремо: три й дві — це три
        // й дві, до порога «від 4» не дотягує жодна.
        [$c, $d] = $this->variants[$this->pSplit];
        $_SESSION['cart'] = [
            $this->pSplit . ':' . $c => ['product_id' => $this->pSplit, 'variant_id' => $c, 'qty' => 3],
            $this->pSplit . ':' . $d => ['product_id' => $this->pSplit, 'variant_id' => $d, 'qty' => 2],
        ];
        $rows = Cart::detailed();
        $this->ok('окремий рахунок дає власну кількість', ($rows[0]['tier_qty'] ?? 0) === 3);
        $this->ok('і жодна фасовка порога не бере', (float)$rows[0]['wholesale'] === 0.0
            && (float)$rows[1]['wholesale'] === 0.0);
        $this->ok('ціна лишилась повною', (float)$rows[0]['price'] === 100.0);

        // Підказка в кошику мусить обіцяти те, що справді буде
        $this->cart($this->pOwn, 3);
        $rows = Cart::detailed();
        $next = $rows[0]['next_tier'] ?? null;
        $this->ok('кошик каже, скількох штук бракує', $next !== null && $next['need'] === 2);
        $this->ok('і який відсоток буде насправді', $next !== null && (float)$next['effective'] === 5.0);
    }

    private function testCap(): void
    {
        $this->group('стеля сумарної знижки');
        $p = $this->product($this->pOwn);

        $this->ok('без своєї стелі діє загальна з налаштувань',
            Catalog::discountCap($p) === (float)Settings::get('max_discount_default', '30'));
        $this->ok('стеля товару перебиває загальну',
            Catalog::discountCap(['max_discount' => 12]) === 12.0);
        $this->ok('стеля фасовки перебиває стелю товару',
            Catalog::discountCap(['max_discount' => 12], ['max_discount' => 6]) === 6.0);
        $this->ok('порожнє поле фасовки означає «як у товару»',
            Catalog::discountCap(['max_discount' => 12], ['max_discount' => null]) === 12.0);
        $this->ok('нуль — це справжній нуль, а не «не задано»',
            Catalog::discountCap(['max_discount' => 0]) === 0.0);

        // Стеля обрізає те, що опт ДОДАЄ: 6% стелі проти 7% опту дають 6%
        DB::update('products', ['max_discount' => 6], 'id = ?', [$this->pOwn]);
        $this->cart($this->pOwn, 10);
        $rows = Cart::detailed();
        $this->ok('опт обрізано стелею', (float)$rows[0]['wholesale'] === 6.0);
        $this->ok('і ціна відповідає саме їй', (float)$rows[0]['price'] === 94.0);

        // Головне: стеля не піднімає ціну, яку власник призначив сам. Стара
        // ціна 200 проти 100 — це вже 50% знижки, більше за будь-яку стелю,
        // і опт просто нічого не додає замість того, щоб ціну підняти.
        DB::update('products', ['old_price' => 200], 'id = ?', [$this->pOwn]);
        $rows = Cart::detailed();
        $this->ok('стеля не піднімає вже призначену ціну', (float)$rows[0]['price'] === 100.0);
        $this->ok('опт при вибраній стелі нічого не додає', (float)$rows[0]['wholesale'] === 0.0);
        $this->ok('і підказка про наступний поріг мовчить', ($rows[0]['next_tier'] ?? null) === null);

        DB::update('products', ['old_price' => null, 'max_discount' => null], 'id = ?', [$this->pOwn]);
    }

    private function testPromoInteraction(): void
    {
        $this->group('опт і промокод');
        $this->codes[] = 'TESTWHOLE';
        DB::delete('promo_codes', 'code = ?', ['TESTWHOLE']);
        DB::insert('promo_codes', ['code' => 'TESTWHOLE', 'percent' => 20, 'active' => 1, 'stackable' => 1]);
        $promo = Promo::find('TESTWHOLE');

        // Стеля 10% на товар: опт уже зʼїв 7, коду лишається рівно 3
        DB::update('products', ['max_discount' => 10], 'id = ?', [$this->pOwn]);
        $this->cart($this->pOwn, 10);
        $rows = Cart::detailed();
        $this->ok('опт дав свої 7%', (float)$rows[0]['wholesale'] === 7.0);
        $this->ok('коду лишилось рівно до стелі',
            Promo::extraPercent($promo, Promo::ownPercent($rows[0]), $rows[0]['cap']) === 3.0);

        $t = Cart::total(null, $promo);
        $this->ok('у гривнях це 3% від суми зі знижкою', $t['discount'] === 27.9);
        $this->ok('до сплати — опт і код разом', $t['total'] === 902.1);

        // Дві стелі — коду й товару — не складаються: діє найменша
        $tight = ['percent' => 20, 'stackable' => 1, 'max_total_percent' => 8];
        $this->ok('менша стеля коду виграє', Promo::cap($tight, 30.0) === 8.0);
        $this->ok('менша стеля товару теж виграє', Promo::cap($tight, 5.0) === 5.0);
        $this->ok('без стелі товару лишається стеля коду', Promo::cap($tight, null) === 8.0);
        $this->ok('без обох стелі немає', Promo::cap(['percent' => 20], null) === null);

        // Пояснення для покупця: чому код додав менше, ніж обіцяв
        $note = Promo::note($promo, $rows);
        $this->ok('покупцю пояснено, що знижку обмежила стеля', str_contains($note, 'обмежена 10%'));

        DB::update('products', ['max_discount' => null], 'id = ?', [$this->pOwn]);
    }

    private function testRounding(): void
    {
        $this->group('копійки сходяться');
        // Той самий принцип, що й у промокодів: покупець бачить перераховану
        // ціну кожної позиції, і ці числа мусять скластися рівно в «до сплати».
        DB::update('products', ['base_price' => 33.33], 'id = ?', [$this->pOwn]);
        $this->cart($this->pOwn, 10);

        $rows = Cart::detailed();
        $t = Cart::total();
        $sum = 0.0;
        foreach ($rows as $r) $sum += (float)$r['sum'];
        $this->ok('сума позицій = до сплати', abs($sum - $t['total']) < 0.0001);
        $this->ok('ціна за штуку округлена до копійки',
            (float)$rows[0]['price'] === round((float)$rows[0]['price'], 2));
        $this->ok('опт справді знизив ціну', (float)$rows[0]['price'] < 33.33);

        DB::update('products', ['base_price' => 100], 'id = ?', [$this->pOwn]);
    }
}

return (new WholesaleTest())->run();
