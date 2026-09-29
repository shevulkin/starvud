<?php
/**
 * Набори: разом дешевше.  Запуск: php bin/cli.php test
 *
 * Головне, що доводимо:
 *
 * 1. Неповний набір не дає нічого. «Разом дешевше» мусить означати «разом»,
 *    інакше знижку отримає той, хто взяв половину.
 * 2. Та сама штука не потрапляє у два набори. Без цього перетин двох наборів
 *    множив би знижку на тому самому товарі.
 * 3. Знижка набору впирається в ту саму стелю, що акція, опт і промокод, —
 *    і рахується по позиціях, щоб чек сходився по рядках.
 */
declare(strict_types=1);

final class BundlesTest
{
    private int $pass = 0;
    private int $fail = 0;

    private array $products = [];
    private array $bundles = [];
    private array $codes = [];
    private int $cat = 0;

    private int $honey = 0;    // 100 грн
    private int $propolis = 0; // 50 грн
    private int $candle = 0;   // 20 грн
    private int $soap = 0;     // 40 грн, у наборах не бере участі
    private array $vars = [];  // фасовки меду

    private int $bGift = 0;    // мед + прополіс, −10%
    private int $bFixed = 0;   // мед + свічка, рівно 100 грн за комплект

    public function run(): int
    {
        $this->setUp();
        try {
            $this->testComplete();
            $this->testIncomplete();
            $this->testMultiple();
            $this->testFixedPrice();
            $this->testNoDoubleDip();
            $this->testVariants();
            $this->testCap();
            $this->testPromo();
            $this->testShowcase();
            $this->testNearMiss();
            $this->testTopUp();
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
        $this->cat = DB::insert('categories', [
            'name' => 'Набір-розділ', 'slug' => 'b-' . bin2hex(random_bytes(4)),
            'type' => 'product', 'sort' => 950, 'active' => 1,
        ]);

        $this->honey    = $this->mkProduct('Набір: мед', 100.0);
        $this->propolis = $this->mkProduct('Набір: прополіс', 50.0);
        $this->candle   = $this->mkProduct('Набір: свічка', 20.0);
        $this->soap     = $this->mkProduct('Набір: мило', 40.0);

        $this->bGift  = $this->mkBundle('Подарунковий', 'percent', 10, [
            [$this->honey, null, 1], [$this->propolis, null, 1],
        ]);
        $this->bFixed = $this->mkBundle('Свічковий', 'fixed', 100, [
            [$this->honey, null, 1], [$this->candle, null, 1],
        ]);

        $_SESSION = [];
        Bundles::forget();
    }

    private function mkProduct(string $name, float $price): int
    {
        $id = DB::insert('products', [
            'category_id' => $this->cat, 'name' => $name,
            'slug' => 'b-' . bin2hex(random_bytes(4)),
            'base_price' => $price, 'active' => 1, 'made_to_order' => 1,
            'wholesale' => 0,   // опт тут заважав би: перевіряємо самі набори
            'qty_scope' => 'product',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->products[] = $id;
        return $id;
    }

    /** @param array $items [[product_id, variant_id|null, qty], ...] */
    private function mkBundle(string $title, string $kind, float $value, array $items): int
    {
        $id = DB::insert('bundles', [
            'title' => $title, 'kind' => $kind, 'value' => $value, 'active' => 1, 'sort' => count($this->bundles),
        ]);
        foreach ($items as [$pid, $vid, $qty]) {
            DB::insert('bundle_items', ['bundle_id' => $id, 'product_id' => $pid, 'variant_id' => $vid, 'qty' => $qty]);
        }
        $this->bundles[] = $id;
        Bundles::forget();
        return $id;
    }

    private function tearDown(): void
    {
        foreach ($this->bundles as $id) {
            DB::delete('bundle_items', 'bundle_id = ?', [$id]);
            DB::delete('bundles', 'id = ?', [$id]);
        }
        foreach ($this->products as $id) {
            DB::delete('product_variants', 'product_id = ?', [$id]);
            DB::delete('products', 'id = ?', [$id]);
        }
        if ($this->cat) DB::delete('categories', 'id = ?', [$this->cat]);
        foreach ($this->codes as $c) DB::delete('promo_codes', 'code = ?', [$c]);
        Bundles::forget();
        Catalog::forgetCaches();
        $_SESSION = [];
    }

    private function ok(string $what, bool $cond): void
    {
        if ($cond) { $this->pass++; echo "  ok   $what\n"; }
        else { $this->fail++; echo "  FAIL $what\n"; }
    }

    private function group(string $name): void { echo "\n== $name ==\n"; }

    /** Кошик із пар [product_id => qty] (фасовок немає) */
    private function cart(array $pairs): void
    {
        $cart = [];
        foreach ($pairs as $pid => $qty) {
            $cart[$pid . ':0'] = ['product_id' => (int)$pid, 'variant_id' => null, 'qty' => (int)$qty];
        }
        $_SESSION['cart'] = $cart;
    }

    // ------------------------------------------------------------------ збірка

    private function testComplete(): void
    {
        $this->group('набір зібрався');
        $this->cart([$this->honey => 1, $this->propolis => 1]);
        $t = Cart::total();

        $this->ok('без знижок сума 150', (float)$t['subtotal'] === 150.0);
        $this->ok('набір зняв свої 10%', (float)$t['bundle_discount'] === 15.0);
        $this->ok('до сплати 135', (float)$t['total'] === 135.0);
        $this->ok('набір названий поіменно', count($t['bundles']) === 1
            && $t['bundles'][0]['bundle']['title'] === 'Подарунковий');
        $this->ok('і зібрався рівно раз', (int)$t['bundles'][0]['sets'] === 1);

        // Знижка розкладена по позиціях: інакше фіскальний чек не зійшовся б
        $b = Cart::breakdown();
        $cuts = [];
        foreach ($b['rows'] as $r) $cuts[] = (float)$r['bundle_cut'];
        $this->ok('знижка лягла на обидві позиції', count(array_filter($cuts)) === 2);
        $this->ok('і склалась рівно в загальну', array_sum($cuts) === 15.0);
        $this->ok('пропорційно ціні: мед дорожчий — з нього й більше',
            max($cuts) === 10.0 && min($cuts) === 5.0);
    }

    private function testIncomplete(): void
    {
        $this->group('неповний набір не дає нічого');
        $this->cart([$this->honey => 1]);
        $this->ok('сам мед знижки не дає', (float)Cart::total()['bundle_discount'] === 0.0);

        $this->cart([$this->honey => 5]);
        $this->ok('пʼять медів — теж ні: набір не про кількість',
            (float)Cart::total()['bundle_discount'] === 0.0);

        $this->cart([$this->honey => 1, $this->soap => 1]);
        $this->ok('чужий товар поруч набору не збирає',
            (float)Cart::total()['bundle_discount'] === 0.0);
    }

    private function testMultiple(): void
    {
        $this->group('кілька комплектів');
        $this->cart([$this->honey => 2, $this->propolis => 2]);
        $t = Cart::total();
        $this->ok('два комплекти — подвійна знижка', (float)$t['bundle_discount'] === 30.0);
        $this->ok('і сказано, що комплектів два', (int)$t['bundles'][0]['sets'] === 2);

        // Зайва штука понад комплект знижки не додає: вона просто лежить поруч
        $this->cart([$this->honey => 3, $this->propolis => 2]);
        $t = Cart::total();
        $this->ok('третій мед без пари нічого не додає', (float)$t['bundle_discount'] === 30.0);
        $this->ok('комплектів усе одно два', (int)$t['bundles'][0]['sets'] === 2);
    }

    private function testFixedPrice(): void
    {
        $this->group('фіксована ціна набору');
        // мед 100 + свічка 20 = 120, набір коштує 100 → знижка 20
        $this->cart([$this->honey => 1, $this->candle => 1]);
        $t = Cart::total();
        $this->ok('знижка — різниця зі звичайною ціною', (float)$t['bundle_discount'] === 20.0);
        $this->ok('до сплати рівно ціна набору', (float)$t['total'] === 100.0);

        // Два комплекти коштують удвічі більше: інакше другий їхав би задарма
        $this->cart([$this->honey => 2, $this->candle => 2]);
        $this->ok('два комплекти — 200, а не 100', (float)Cart::total()['total'] === 200.0);

        // Ціна, вища за звичайну, не робить знижку відʼємною
        $dear = $this->mkBundle('Дорожчий', 'fixed', 999, [
            [$this->candle, null, 1], [$this->soap, null, 1],
        ]);
        $this->cart([$this->candle => 1, $this->soap => 1]);
        $this->ok('набір дорожчий за складові — знижки просто немає',
            (float)Cart::total()['bundle_discount'] === 0.0);
        DB::delete('bundle_items', 'bundle_id = ?', [$dear]);
        DB::delete('bundles', 'id = ?', [$dear]);
        Bundles::forget();
    }

    private function testNoDoubleDip(): void
    {
        $this->group('одна штука — один набір');
        // Один мед потрібен обом наборам. Дістанеться першому за порядком,
        // другий лишиться неповним — інакше та сама банка дала б дві знижки.
        $this->cart([$this->honey => 1, $this->propolis => 1, $this->candle => 1]);
        $t = Cart::total();
        $this->ok('спрацював рівно один набір', count($t['bundles']) === 1);
        $this->ok('саме той, що стоїть першим', $t['bundles'][0]['bundle']['title'] === 'Подарунковий');
        $this->ok('знижка не подвоїлась', (float)$t['bundle_discount'] === 15.0);

        // Два меди — вистачає обом
        $this->cart([$this->honey => 2, $this->propolis => 1, $this->candle => 1]);
        $t = Cart::total();
        $this->ok('двох медів вистачає на обидва набори', count($t['bundles']) === 2);
        $this->ok('знижки склались', (float)$t['bundle_discount'] === 35.0);
    }

    private function testVariants(): void
    {
        $this->group('фасовки в наборі');
        $a = DB::insert('product_variants', ['product_id' => $this->honey, 'name' => '350 г',
            'price' => 100, 'active' => 1, 'sort' => 0]);
        $b = DB::insert('product_variants', ['product_id' => $this->honey, 'name' => '1 кг',
            'price' => 250, 'active' => 1, 'sort' => 1]);
        $this->vars = [$a, $b];

        // «Будь-яка фасовка» збирає набір із тієї, що вже лежить у кошику
        $_SESSION['cart'] = [
            $this->honey . ':' . $b => ['product_id' => $this->honey, 'variant_id' => $b, 'qty' => 1],
            $this->propolis . ':0'  => ['product_id' => $this->propolis, 'variant_id' => null, 'qty' => 1],
        ];
        $t = Cart::total();
        $this->ok('порожня фасовка бере ту, що в кошику', (float)$t['bundle_discount'] === 30.0);

        // Конкретна фасовка наполягає саме на ній
        DB::update('bundle_items', ['variant_id' => $a], 'bundle_id = ? AND product_id = ?',
            [$this->bGift, $this->honey]);
        Bundles::forget();
        $t = Cart::total();
        $this->ok('чужа фасовка набору не збирає', (float)$t['bundle_discount'] === 0.0);

        $_SESSION['cart'][$this->honey . ':' . $a] =
            ['product_id' => $this->honey, 'variant_id' => $a, 'qty' => 1];
        $this->ok('потрібна фасовка збирає', (float)Cart::total()['bundle_discount'] === 15.0);

        DB::update('bundle_items', ['variant_id' => null], 'bundle_id = ? AND product_id = ?',
            [$this->bGift, $this->honey]);
        DB::delete('product_variants', 'product_id = ?', [$this->honey]);
        Bundles::forget();
    }

    private function testCap(): void
    {
        $this->group('стеля тримає й набір');
        // Стеля 5% на мед: із 10 грн його частки набору пройдуть лише 5
        DB::update('products', ['max_discount' => 5], 'id = ?', [$this->honey]);
        $this->cart([$this->honey => 1, $this->propolis => 1]);
        $b = Cart::breakdown();
        $byId = [];
        foreach ($b['rows'] as $r) $byId[(int)$r['product']['id']] = $r;

        $this->ok('меду дісталось лише до стелі', (float)$byId[$this->honey]['bundle_cut'] === 5.0);
        $this->ok('прополіс своє отримав повністю', (float)$byId[$this->propolis]['bundle_cut'] === 5.0);
        $this->ok('загальна знижка зменшилась на обрізане', (float)$b['bundle_discount'] === 10.0);
        // Обрізане НЕ перекладається на сусіда: інакше стелю можна було б
        // обійти, просто поклавши поруч товар без неї
        $this->ok('обрізане не перекладено на прополіс', (float)$byId[$this->propolis]['bundle_cut'] < 10.0);

        DB::update('products', ['max_discount' => 0], 'id = ?', [$this->honey]);
        $this->cart([$this->honey => 1, $this->propolis => 1]);
        $this->ok('нульова стеля лишає позицію без знижки',
            (float)Cart::total()['bundle_discount'] === 5.0);

        DB::update('products', ['max_discount' => null], 'id = ?', [$this->honey]);
    }

    private function testPromo(): void
    {
        $this->group('набір і промокод');
        $this->codes[] = 'TESTBUNDLE';
        DB::delete('promo_codes', 'code = ?', ['TESTBUNDLE']);
        DB::insert('promo_codes', ['code' => 'TESTBUNDLE', 'percent' => 10, 'active' => 1, 'stackable' => 1]);
        $promo = Promo::find('TESTBUNDLE');

        $this->cart([$this->honey => 1, $this->propolis => 1]);
        $t = Cart::total(null, $promo);

        $this->ok('набір лишився при своїх 15', (float)$t['bundle_discount'] === 15.0);
        // Код бере 10% від того, що лишилось після набору (135), а не від 150:
        // ті самі гривні не можна знизити двічі
        $this->ok('код рахує залишок, а не початкову суму', (float)$t['promo_discount'] === 13.5);
        $this->ok('разом 28.50', (float)$t['discount'] === 28.5);
        $this->ok('до сплати 121.50', (float)$t['total'] === 121.5);

        // Обидві знижки видно окремо: покупцеві важливо, за що саме знижено
        $this->ok('джерела знижки не злиплись',
            $t['bundle_discount'] + $t['promo_discount'] === $t['discount']);
    }

    private function testShowcase(): void
    {
        $this->group('що бачить покупець на сторінці товару');
        $found = Bundles::forProduct($this->honey);
        $this->ok('набори з цим товаром знайшлись', count($found) === 2);

        $gift = null;
        foreach ($found as $f) if ((int)$f['id'] === $this->bGift) $gift = $f;
        $this->ok('склад розгорнуто з цінами', $gift !== null && count($gift['expanded']) === 2);
        $this->ok('окремо — 150', $gift !== null && (float)$gift['sum'] === 150.0);
        $this->ok('набором — 135', $gift !== null && (float)$gift['total'] === 135.0);
        $this->ok('вигода названа числом', $gift !== null && (float)$gift['cut'] === 15.0);

        $this->ok('у товару поза наборами їх немає', Bundles::forProduct($this->soap) === []);

        // Набір, у якому щось зникло, покупцю не показуємо: пропозиція, яку не
        // можна прийняти, гірша за її відсутність
        DB::update('products', ['active' => 0], 'id = ?', [$this->propolis]);
        $this->ok('набір із вимкненим товаром зникає з вітрини',
            count(Bundles::forProduct($this->honey)) === 1);
        DB::update('products', ['active' => 1], 'id = ?', [$this->propolis]);
    }

    // ------------------------------------------------------------------ підказка

    private function testNearMiss(): void
    {
        $this->group('до набору лишився крок');

        // Мед є, прополісу немає — це та мить, заради якої підказка й існує
        $this->cart([$this->honey => 1]);
        $s = Cart::total()['bundle_suggest'];
        $mine = array_values(array_filter($s, fn($x) => (int)$x['bundle']['id'] === $this->bGift));
        $this->ok('набір, до якого лишився крок, знайдено', count($mine) === 1);
        $this->ok('названо, чого саме бракує',
            count($mine[0]['need']) === 1 && (int)$mine[0]['need'][0]['product']['id'] === $this->propolis);
        $this->ok('і скільки штук', (int)$mine[0]['need'][0]['short'] === 1);
        $this->ok('вигода названа числом', (float)$mine[0]['cut'] === 15.0);

        // Зібраний набір підказки не потребує: він уже спрацював
        $this->cart([$this->honey => 1, $this->propolis => 1]);
        $t = Cart::total();
        $ids = array_map(fn($x) => (int)$x['bundle']['id'], $t['bundle_suggest']);
        $this->ok('зібраний набір у підказки не потрапляє', !in_array($this->bGift, $ids, true));
        $this->ok('бо він уже в знижці', count($t['bundles']) === 1);

        // Порожній кошик і чужий товар — не привід перелічувати каталог:
        // підказка мусить упізнаватись як продовження власного наміру
        $this->cart([$this->soap => 1]);
        $this->ok('товар поза наборами підказок не породжує',
            Cart::total()['bundle_suggest'] === []);

        // Штука, що вже пішла в один набір, до другого не рахується
        $this->cart([$this->honey => 1, $this->propolis => 1]);
        $s = Cart::total()['bundle_suggest'];
        $fixed = array_values(array_filter($s, fn($x) => (int)$x['bundle']['id'] === $this->bFixed));
        $this->ok('другий набір бачить, що мед уже зайнятий',
            count($fixed) === 0 || (int)$fixed[0]['need'][0]['product']['id'] !== $this->candle);

        // Забагато бракує — це вже не підказка, а реклама
        $big = $this->mkBundle('Великий', 'percent', 10, [
            [$this->honey, null, 1], [$this->candle, null, 1], [$this->soap, null, 1],
        ]);
        $this->cart([$this->honey => 1]);
        $ids = array_map(fn($x) => (int)$x['bundle']['id'], Cart::total()['bundle_suggest']);
        $this->ok('набір, де бракує двох із трьох, ще підказується', in_array($big, $ids, true));

        $huge = $this->mkBundle('Величезний', 'percent', 10, [
            [$this->honey, null, 1], [$this->candle, null, 1],
            [$this->soap, null, 1], [$this->propolis, null, 1],
        ]);
        $ids = array_map(fn($x) => (int)$x['bundle']['id'], Cart::total()['bundle_suggest']);
        $this->ok('а де бракує трьох — уже ні', !in_array($huge, $ids, true));

        foreach ([$big, $huge] as $id) {
            DB::delete('bundle_items', 'bundle_id = ?', [$id]);
            DB::delete('bundles', 'id = ?', [$id]);
        }
        Bundles::forget();
    }

    private function testTopUp(): void
    {
        $this->group('кнопка добирає, а не додає наосліп');
        $bundle = Bundles::find($this->bGift);

        // Порожній кошик: добирати нема з чого, кладемо повний склад
        $_SESSION['cart'] = [];
        $this->ok('у порожньому кошику комплектів нуль', Bundles::setsIn($bundle, Cart::detailed()) === 0);

        // Мед уже є — до набору бракує тільки прополісу
        $this->cart([$this->honey => 1]);
        $rows = Cart::detailed();
        $this->ok('мед порахований як наявний',
            Bundles::inCart($bundle['items'][0], $rows) === 1);
        $this->ok('прополісу немає', Bundles::inCart($bundle['items'][1], $rows) === 0);
        $this->ok('повних комплектів ще нуль', Bundles::setsIn($bundle, $rows) === 0);

        // Комплект зібрано — наступний клік має цілити у другий
        $this->cart([$this->honey => 1, $this->propolis => 1]);
        $this->ok('комплект зібрався', Bundles::setsIn($bundle, Cart::detailed()) === 1);

        $this->cart([$this->honey => 3, $this->propolis => 2]);
        $this->ok('комплектів рівно стільки, скільки вистачає пар',
            Bundles::setsIn($bundle, Cart::detailed()) === 2);

        // «Будь-яка фасовка» рахує всі фасовки товару: набір збереться з тієї,
        // що вже взяли, і кнопка не має класти ще одну «правильну»
        $v = DB::insert('product_variants', ['product_id' => $this->propolis, 'name' => 'велика',
            'price' => 60, 'active' => 1, 'sort' => 0]);
        $_SESSION['cart'] = [
            $this->propolis . ':' . $v => ['product_id' => $this->propolis, 'variant_id' => $v, 'qty' => 1],
        ];
        $this->ok('чужа фасовка теж рахується, коли в наборі «будь-яка»',
            Bundles::inCart($bundle['items'][1], Cart::detailed()) === 1);
        DB::delete('product_variants', 'id = ?', [$v]);
    }
}

return (new BundlesTest())->run();
