<?php
/**
 * Фото варіанта.  Запуск: php bin/cli.php test
 *
 * Головне, що доводимо:
 *
 * 1. Фасовка зі своїм кадром показує його першим, а спільні — після нього.
 *    «Оберіть колір» без кадру самого кольору не вибір, а здогад.
 * 2. Чужі кадри в її галерею не потрапляють: червона шапка, поки обрано
 *    синю, — це не «більше фото», а помилка.
 * 3. Фасовка без своїх кадрів поводиться так, як поводився товар до всієї цієї
 *    затії: показує галерею товару з головним фото попереду.
 * 4. Кошик бере фото обраної фасовки, а коли його немає — головне товару.
 *    Заглушка тут неприпустима: рядок мусить бути впізнаваним завжди.
 */
declare(strict_types=1);

final class VariantPhotosTest
{
    private int $pass = 0;
    private int $fail = 0;

    private int $cat = 0;
    private int $product = 0;   // шапка: червона, синя, зелена
    private int $plain = 0;     // товар без фасовок — контроль
    private array $vars = [];   // назва => id

    private const SHARED = 'img/vp-shared.webp';   // загальний план
    private const DETAIL = 'img/vp-detail.webp';   // фактура, теж спільна
    private const RED    = 'img/vp-red.webp';
    private const BLUE   = 'img/vp-blue.webp';

    public function run(): int
    {
        $this->setUp();
        try {
            $this->testOwnFirst();
            $this->testForeignHidden();
            $this->testWithoutOwn();
            $this->testNoVariants();
            $this->testMainPhoto();
            $this->testCartRow();
            $this->testAllTagged();
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
            'name' => 'Фото-розділ', 'slug' => 'vp-' . bin2hex(random_bytes(4)),
            'type' => 'product', 'sort' => 950, 'active' => 1,
        ]);

        $this->product = $this->mkProduct('Фото: шапка');
        $this->plain   = $this->mkProduct('Фото: мед без фасовок');

        foreach (['червона', 'синя', 'зелена'] as $i => $name) {
            $this->vars[$name] = DB::insert('product_variants', [
                'product_id' => $this->product, 'name' => $name, 'sort' => $i, 'active' => 1,
            ]);
        }

        // Порядок навмисно такий, як його бачить адмін: спільне, свої, спільне.
        // Так перевіряється саме перебирання, а не збіг із порядком у базі.
        $this->mkImage($this->product, self::SHARED, null, 0);
        $this->mkImage($this->product, self::RED, $this->vars['червона'], 1);
        $this->mkImage($this->product, self::BLUE, $this->vars['синя'], 2);
        $this->mkImage($this->product, self::DETAIL, null, 3);
        DB::update('products', ['image' => self::SHARED], 'id = ?', [$this->product]);

        $this->mkImage($this->plain, self::DETAIL, null, 0);
        DB::update('products', ['image' => self::DETAIL], 'id = ?', [$this->plain]);

        $_SESSION = [];
        Catalog::forgetCaches();
    }

    private function mkProduct(string $name): int
    {
        return DB::insert('products', [
            'category_id' => $this->cat, 'name' => $name,
            'slug' => 'vp-' . bin2hex(random_bytes(4)),
            'base_price' => 100.0, 'active' => 1, 'made_to_order' => 1,
            'wholesale' => 0, 'qty_scope' => 'product',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function mkImage(int $productId, string $path, ?int $variantId, int $sort): int
    {
        return DB::insert('product_images', [
            'product_id' => $productId, 'path' => $path, 'variant_id' => $variantId,
            'width' => 800, 'height' => 800, 'bytes' => 1024, 'sort' => $sort,
        ]);
    }

    private function tearDown(): void
    {
        foreach ([$this->product, $this->plain] as $id) {
            if (!$id) continue;
            DB::delete('product_images', 'product_id = ?', [$id]);
            DB::delete('product_variants', 'product_id = ?', [$id]);
            DB::delete('products', 'id = ?', [$id]);
        }
        if ($this->cat) DB::delete('categories', 'id = ?', [$this->cat]);
        $_SESSION = [];
        Catalog::forgetCaches();
    }

    private function ok(string $what, bool $cond): void
    {
        if ($cond) { $this->pass++; echo "  ok   $what\n"; }
        else { $this->fail++; echo "  FAIL $what\n"; }
    }

    private function group(string $name): void { echo "\n== $name ==\n"; }

    /** Галерея списком шляхів — так її видно очима */
    private function paths(?string $variant): array
    {
        $p = DB::row('SELECT * FROM products WHERE id = ?', [$this->product]);
        $v = $variant === null ? null
            : DB::row('SELECT * FROM product_variants WHERE id = ?', [$this->vars[$variant]]);
        Catalog::forgetCaches();
        return array_column(Catalog::gallery($p, $v), 'path');
    }

    // ------------------------------------------------------------------ перевірки

    private function testOwnFirst(): void
    {
        $this->group('свої кадри попереду спільних');
        $red = $this->paths('червона');

        $this->ok('перший кадр — власний', ($red[0] ?? '') === self::RED);
        $this->ok('спільні йдуть після нього',
            array_slice($red, 1) === [self::SHARED, self::DETAIL]);
        // Головне фото товару стоїть у списку першим лише поки в фасовки немає
        // свого: інакше покупець обирає колір і бачить загальний план
        $this->ok('головне фото не пролізло вперед', ($red[0] ?? '') !== self::SHARED);
    }

    private function testForeignHidden(): void
    {
        $this->group('чужі кадри не показуються');
        $red = $this->paths('червона');

        $this->ok('синього кадру в червоній галереї немає', !in_array(self::BLUE, $red, true));
        $this->ok('у синьої — свій', ($this->paths('синя')[0] ?? '') === self::BLUE);
        $this->ok('червоного в неї теж немає', !in_array(self::RED, $this->paths('синя'), true));
    }

    private function testWithoutOwn(): void
    {
        $this->group('фасовка без своїх кадрів');
        $green = $this->paths('зелена');

        $this->ok('показує спільні', $green === [self::SHARED, self::DETAIL]);
        $this->ok('головне фото попереду', ($green[0] ?? '') === self::SHARED);
        $this->ok('чужих кольорів не видно',
            !in_array(self::RED, $green, true) && !in_array(self::BLUE, $green, true));
    }

    private function testNoVariants(): void
    {
        $this->group('без фасовки галерея повна');
        $all = $this->paths(null);

        // Так її бачить адмінка й розмітка для пошуковиків: товар цілком
        $this->ok('усі чотири кадри на місці', count($all) === 4);
        $this->ok('головне попереду', ($all[0] ?? '') === self::SHARED);

        $plain = DB::row('SELECT * FROM products WHERE id = ?', [$this->plain]);
        $this->ok('товар без фасовок не змінився',
            array_column(Catalog::gallery($plain), 'path') === [self::DETAIL]);
    }

    private function testMainPhoto(): void
    {
        $this->group('головне фото фасовки');
        $p = DB::row('SELECT * FROM products WHERE id = ?', [$this->product]);
        $red = DB::row('SELECT * FROM product_variants WHERE id = ?', [$this->vars['червона']]);
        $green = DB::row('SELECT * FROM product_variants WHERE id = ?', [$this->vars['зелена']]);

        $this->ok('своє фото виграє в товарного', Catalog::photo($p, $red) === self::RED);
        $this->ok('без свого — головне товару', Catalog::photo($p, $green) === self::SHARED);
        $this->ok('без фасовки — теж головне', Catalog::photo($p) === self::SHARED);
    }

    private function testCartRow(): void
    {
        $this->group('кошик показує обране');
        $_SESSION['cart'] = [
            $this->product . ':' . $this->vars['синя'] =>
                ['product_id' => $this->product, 'variant_id' => $this->vars['синя'], 'qty' => 1],
            $this->product . ':' . $this->vars['зелена'] =>
                ['product_id' => $this->product, 'variant_id' => $this->vars['зелена'], 'qty' => 1],
        ];
        $rows = [];
        foreach (Cart::detailed() as $r) $rows[(int)($r['variant']['id'] ?? 0)] = $r['photo'];

        $this->ok('рядок синьої — синє фото',
            ($rows[$this->vars['синя']] ?? '') === self::BLUE);
        // Заглушки тут бути не може: рядок кошика мусить лишатись впізнаваним
        $this->ok('рядок зеленої — головне фото товару',
            ($rows[$this->vars['зелена']] ?? '') === self::SHARED);

        $_SESSION['cart'] = [];
    }

    private function testAllTagged(): void
    {
        $this->group('коли спільних кадрів не лишилось');
        // Усі фото розібрані по фасовках — зеленій не дістається жодного.
        // Показати головне фото товару все одно краще, ніж заглушку.
        DB::update('product_images', ['variant_id' => $this->vars['червона']],
            'product_id = ? AND path = ?', [$this->product, self::SHARED]);
        DB::update('product_images', ['variant_id' => $this->vars['синя']],
            'product_id = ? AND path = ?', [$this->product, self::DETAIL]);

        $green = $this->paths('зелена');
        $this->ok('галерея не порожня', count($green) === 1);
        $this->ok('це фото товару, а не заглушка', ($green[0] ?? '') !== 'img/honey-jar.webp');

        DB::update('product_images', ['variant_id' => null],
            'product_id = ? AND path = ?', [$this->product, self::SHARED]);
        DB::update('product_images', ['variant_id' => null],
            'product_id = ? AND path = ?', [$this->product, self::DETAIL]);
        Catalog::forgetCaches();
    }
}

return (new VariantPhotosTest())->run();
