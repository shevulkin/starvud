<?php
/**
 * Промокоди й перерахунок сум.  Запуск: php bin/cli.php test
 *
 * Головне, що доводимо: знижка, яку покупець побачив у формі, дорівнює тій,
 * що потрапляє в замовлення. Тому знижка рахується по позиціях, а не від
 * загальної суми: інакше сума перерахованих товарів і «до сплати» різнились
 * би на копійки, і форма суперечила б сама собі.
 *
 * Тест створює власний товар і власний код, а наприкінці прибирає обидва.
 */
declare(strict_types=1);

final class PromoTest
{
    private int $pass = 0;
    private int $fail = 0;
    private int $productId = 0;
    private array $codes = [];

    public function run(): int
    {
        $this->setUp();
        try {
            $this->testValidation();
            $this->testSession();
            $this->testTotals();
            $this->testRounding();
            $this->testGlobalLimit();
            $this->testPerUserLimit();
            $this->testStacking();
            $this->testOneCodeOnly();
            $this->testNotes();
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
        $cat = (int)(DB::val('SELECT id FROM categories ORDER BY id LIMIT 1') ?? 0);
        $this->productId = DB::insert('products', [
            'category_id' => $cat, 'name' => 'Тестовий мед', 'slug' => 'test-promo-' . bin2hex(random_bytes(3)),
            'base_price' => 100, 'active' => 1, 'made_to_order' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->mkCode('TESTPROMO10', 10);
        $this->mkCode('TESTOFF', 20, ['active' => 0]);
        $this->mkCode('TESTOLD', 25, ['expires_at' => date('Y-m-d', strtotime('-1 day'))]);
        $this->mkCode('TESTTODAY', 30, ['expires_at' => date('Y-m-d')]);
        $_SESSION = [];
    }

    private function mkCode(string $code, float $percent, array $extra = []): void
    {
        DB::delete('promo_codes', 'code = ?', [$code]);
        $this->codes[] = $code;
        DB::insert('promo_codes', array_merge(
            ['code' => $code, 'percent' => $percent, 'active' => 1, 'expires_at' => null], $extra));
    }

    private function tearDown(): void
    {
        foreach ($this->codes as $c) {
            $row = DB::row('SELECT id FROM promo_codes WHERE code = ?', [$c]);
            if ($row) DB::delete('promo_uses', 'promo_id = ?', [(int)$row['id']]);
        }
        foreach ($this->codes as $c) DB::delete('promo_codes', 'code = ?', [$c]);
        if ($this->productId) DB::delete('products', 'id = ?', [$this->productId]);
        $_SESSION = [];
    }

    private function ok(string $what, bool $cond): void
    {
        if ($cond) { $this->pass++; echo "  ok   $what\n"; }
        else { $this->fail++; echo "  FAIL $what\n"; }
    }

    private function group(string $name): void { echo "\n== $name ==\n"; }

    private function testValidation(): void
    {
        $this->group('який код узагалі діє');
        $this->ok('робочий код знайдено', (Promo::find('TESTPROMO10')['percent'] ?? null) !== null);
        $this->ok('регістр не має значення', (Promo::find('testpromo10')['code'] ?? '') === 'TESTPROMO10');
        $this->ok('пробіли з боків не заважають', Promo::find('  TESTPROMO10  ') !== null);
        $this->ok('порожній рядок — не код', Promo::find('') === null);
        $this->ok('null — не код', Promo::find(null) === null);
        $this->ok('вигаданого коду немає', Promo::find('НЕМАЄТАКОГО') === null);
        $this->ok('вимкнений код не діє', Promo::find('TESTOFF') === null);
        $this->ok('протермінований не діє', Promo::find('TESTOLD') === null);
        // останній день має працювати: інакше знижка «до 31 числа» зникає 31-го
        $this->ok('останній день дії ще працює', Promo::find('TESTTODAY') !== null);
    }

    private function testSession(): void
    {
        $this->group('що памʼятає сесія');
        $_SESSION = [];
        Promo::apply('TESTPROMO10');
        $this->ok('робочий код запамʼятався', ($_SESSION['promo_code'] ?? '') === 'TESTPROMO10');
        $this->ok('без POST діє збережений', (Promo::current()['code'] ?? '') === 'TESTPROMO10');

        // Поле у формі головніше за памʼять: покупець платить за те, що бачить.
        $this->ok('хибний код із форми скасовує збережений', Promo::current('ХИБНИЙ') === null);
        $this->ok('і забувається', !isset($_SESSION['promo_code']));

        Promo::apply('TESTPROMO10');
        $this->ok('порожнє поле теж скасовує', Promo::current('') === null);
        $this->ok('памʼять очищено', !isset($_SESSION['promo_code']));
    }

    private function testTotals(): void
    {
        $this->group('перерахунок сум');
        $_SESSION['cart'] = [$this->productId . ':0' => ['product_id' => $this->productId, 'variant_id' => null, 'qty' => 3]];
        $promo = Promo::find('TESTPROMO10');

        $plain = Cart::total(null, null);
        $this->ok('без коду знижки немає', $plain['discount'] === 0.0 && $plain['total'] === 300.0);

        $t = Cart::total(null, $promo);
        $this->ok('знижка 10% від 300 = 30', $t['discount'] === 30.0);
        $this->ok('до сплати 270', $t['total'] === 270.0);
        $this->ok('сума товарів не змінилась', $t['subtotal'] === 300.0);

        $this->ok('знижка на позицію рахується так само', Promo::cut(300.0, $promo) === 30.0);
        $this->ok('без коду позиція не дешевшає', Promo::cut(300.0, null) === 0.0);
        $this->ok('підпис показує і код, і відсоток', Promo::label($promo) === 'Знижка (TESTPROMO10 −10%)');
    }

    /**
     * Копійки. Три позиції по 33.33 з 10% дають 3 × 3.33 = 9.99, а не round(9.999) = 10.
     * Саме перше число покупець побачить у картці замовлення, тож воно й має бути
     * в підсумку — інакше рядки не складаються в «до сплати».
     */
    private function testRounding(): void
    {
        $this->group('копійки сходяться');
        DB::update('products', ['base_price' => 33.33], 'id = ?', [$this->productId]);
        $_SESSION['cart'] = [$this->productId . ':0' => ['product_id' => $this->productId, 'variant_id' => null, 'qty' => 1]];
        $promo = Promo::find('TESTPROMO10');

        $rows = Cart::detailed();
        $t = Cart::total(null, $promo);
        $perItem = 0.0;
        foreach ($rows as $r) $perItem += (float)$r['sum'] - Promo::cut((float)$r['sum'], $promo);
        $this->ok('сума перерахованих позицій = до сплати', abs($perItem - $t['total']) < 0.0001);
        $this->ok('знижка з позиції та сама', $t['discount'] === 3.33);
        DB::update('products', ['base_price' => 100], 'id = ?', [$this->productId]);
    }

    /** «Код для однієї людини»: спрацював один раз — для решти його вже немає */
    private function testGlobalLimit(): void
    {
        $this->group('одноразовий на всіх');
        $this->mkCode('TESTONCE', 10, ['max_uses' => 1]);
        $code = Promo::find('TESTONCE');

        [$row, $err] = Promo::check('TESTONCE', 777, '+380670000777');
        $this->ok('до першого використання діє', $row !== null && $err === '');

        Promo::recordUse($code, null, 777, '+380670000777');
        [$row, $err] = Promo::check('TESTONCE', 777, '+380670000777');
        $this->ok('після використання не діє для того самого', $row === null);
        $this->ok('і пояснює чому', str_contains($err, 'одноразовий'));

        [$row2] = Promo::check('TESTONCE', 888, '+380670000888');
        $this->ok('і для будь-кого іншого теж', $row2 === null);
        $this->ok('лічильник рахує факти', Promo::usedTotal((int)$code['id']) === 1);
    }

    /** «Кожному по разу»: інша людина код ще має, ця — вже ні */
    private function testPerUserLimit(): void
    {
        $this->group('по разу на людину');
        $this->mkCode('TESTEACH', 10, ['per_user_limit' => 1]);
        $code = Promo::find('TESTEACH');

        Promo::recordUse($code, null, 777, '+380670000777');
        $this->ok('той, хто вже скористався, більше не може',
            Promo::check('TESTEACH', 777, '+380670000777')[0] === null);
        $this->ok('гість з тим самим номером — теж ні (номер і є ім\'ям гостя)',
            Promo::check('TESTEACH', null, '+380670000777')[0] === null);
        $this->ok('інша людина код ще має', Promo::check('TESTEACH', 888, '+380670000888')[0] !== null);

        // без обмежень — той самий покупець користується скільки завгодно
        $this->mkCode('TESTFREE', 10);
        $free = Promo::find('TESTFREE');
        Promo::recordUse($free, null, 777, '+380670000777');
        Promo::recordUse($free, null, 777, '+380670000777');
        $this->ok('код без обмежень діє при кожній покупці',
            Promo::check('TESTFREE', 777, '+380670000777')[0] !== null);
    }

    /**
     * Сумування з акціями. Товар зі знижкою 20% (стара ціна 100 → 80) і код на 15%:
     * без сумування код на нього не діє; зі стелею 25% додається лише 5.
     */
    private function testStacking(): void
    {
        $this->group('сумування з наявними знижками');
        $row = ['price' => 80.0, 'old' => 100.0, 'sum' => 80.0];
        $plain = ['price' => 100.0, 'old' => null, 'sum' => 100.0];
        $this->ok('знижку позиції видно з ціни й старої ціни', Promo::ownPercent($row) === 20.0);
        $this->ok('у звичайного товару своєї знижки немає', Promo::ownPercent($plain) === 0.0);

        $this->mkCode('TESTSTACK', 15);
        $this->mkCode('TESTNOSTACK', 15, ['stackable' => 0]);
        $this->mkCode('TESTCAP', 15, ['max_total_percent' => 25]);
        $stack = Promo::find('TESTSTACK'); $no = Promo::find('TESTNOSTACK'); $cap = Promo::find('TESTCAP');

        $this->ok('звичайний код складається з акцією', Promo::cut(80.0, $stack, 20.0) === 12.0);
        $this->ok('несумісний код на акційний товар не діє', Promo::cut(80.0, $no, 20.0) === 0.0);
        $this->ok('але на звичайний товар діє повністю', Promo::cut(100.0, $no, 0.0) === 15.0);
        $this->ok('стеля лишає рівно різницю', Promo::extraPercent($cap, 20.0) === 5.0);
        $this->ok('і в гривнях це 5% від суми', Promo::cut(80.0, $cap, 20.0) === 4.0);
        $this->ok('коли своя знижка вже вища за стелю — код нічого не додає',
            Promo::cut(70.0, $cap, 30.0) === 0.0);
        $this->ok('без своєї знижки стеля не заважає', Promo::extraPercent($cap, 0.0) === 15.0);
        $this->ok('старі коди без поля stackable вважаються сумісними',
            Promo::stacks(['percent' => 10]) && Promo::cut(80.0, ['percent' => 10], 20.0) === 8.0);
    }

    /**
     * Знижку дає рівно один код. Спроба протягнути кілька — це масив у POST
     * (promo_code[]=A&promo_code[]=B); він має стати порожнечею, а не «Array»,
     * інакше в базі шукався б неіснуючий код і поведінка залежала б від збігу.
     */
    private function testOneCodeOnly(): void
    {
        $this->group('діє лише один код');
        $this->ok('масив кодів — не код', Promo::fromInput(['TESTPROMO10', 'TESTSTACK']) === '');
        $this->ok('число — не код', Promo::fromInput(123) === '');
        $this->ok('null — не код', Promo::fromInput(null) === '');
        $this->ok('рядок лишається собою', Promo::fromInput(' TESTPROMO10 ') === ' TESTPROMO10 ');

        $_SESSION = [];
        Promo::apply(Promo::fromInput(['TESTPROMO10', 'TESTSTACK']));
        $this->ok('жоден із двох не застосувався', !isset($_SESSION['promo_code']));

        // у сесії теж живе рівно один код: другий витісняє перший, а не додається
        Promo::apply('TESTPROMO10');
        Promo::apply('TESTSTACK');
        $this->ok('у сесії лишається останній, а не обидва', ($_SESSION['promo_code'] ?? '') === 'TESTSTACK');
        $this->ok('знижка рахується з одного коду',
            Cart::total(null, Promo::find('TESTSTACK'))['discount'] === Promo::cut(
                (float)(Cart::total(null, null)['subtotal']), Promo::find('TESTSTACK')));
        $_SESSION = [];
    }

    /** Коли знижка вийшла меншою за відсоток коду — це має бути сказано словами */
    private function testNotes(): void
    {
        $this->group('пояснення до знижки');
        $sale = ['price' => 80.0, 'old' => 100.0, 'sum' => 80.0];   // товар зі знижкою 20%
        $plain = ['price' => 100.0, 'old' => null, 'sum' => 100.0];
        $stack = Promo::find('TESTSTACK'); $no = Promo::find('TESTNOSTACK'); $cap = Promo::find('TESTCAP');

        $this->ok('код ліг на все — пояснювати нічого', Promo::note($stack, [$plain, $plain]) === '');
        $this->ok('без коду теж', Promo::note(null, [$plain]) === '');
        $this->ok('несумісний код і всі товари акційні — кажемо прямо',
            str_contains(Promo::note($no, [$sale, $sale]), 'вже продаються зі знижкою'));
        $this->ok('частина товарів акційна — кажемо, що не на всі',
            str_contains(Promo::note($no, [$sale, $plain]), 'не поширюється'));
        $this->ok('стеля обрізала код — називаємо стелю',
            str_contains(Promo::note($cap, [$sale]), 'обмежена 25%'));
        $this->ok('стеля є, але не заважає — мовчимо', Promo::note($cap, [$plain]) === '');
    }
}

return (new PromoTest())->run();
