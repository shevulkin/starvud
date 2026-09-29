<?php
/**
 * Банер обіцяє знижку, якої може не бути.  Запуск: php bin/cli.php test
 *
 * Відсоток у банері нічого не рахує — він лише дописується в текст смужки.
 * Тому «−15%» угорі сайту й реальні ціни розходяться тихо: акцію забули
 * створити, звузили до категорії або вона скінчилась. Тут доводимо, що
 * розходження помічається саме тоді, коли покупець уже бачить обіцянку.
 *
 * Акції передаємо списком, а не пишемо в базу: activePromotions() кешує
 * результат на запит, і другий розклад у тому самому запуску не побачився б.
 */
declare(strict_types=1);

final class BannerTest
{
    private int $pass = 0;
    private int $fail = 0;
    private array $saved = [];

    public function run(): int
    {
        $this->setUp();
        try {
            $this->testSilentWhenOff();
            $this->testNoPercent();
            $this->testNoPromotions();
            $this->testStoreOnlyDoesNotCount();
            $this->testNarrowedToPartOfCatalog();
            $this->testSmallerThanPromised();
            $this->testHonest();
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
        foreach (['sale_banner_active', 'sale_banner_percent'] as $k) {
            $this->saved[$k] = Settings::get($k, '');
        }
    }

    private function tearDown(): void
    {
        foreach ($this->saved as $k => $v) Settings::set($k, (string)$v);
    }

    private function ok(string $what, bool $cond): void
    {
        if ($cond) { $this->pass++; echo "  ok   $what\n"; }
        else { $this->fail++; echo "  FAIL $what\n"; }
    }

    private function group(string $name): void { echo "\n== $name ==\n"; }

    /** Банер із заданим відсотком */
    private function banner(string $active, string $percent): void
    {
        Settings::set('sale_banner_active', $active);
        Settings::set('sale_banner_percent', $percent);
    }

    /** Рядок акції у вигляді, який повертає activePromotions() */
    private function promo(float $percent, array $scope = []): array
    {
        return array_merge(
            ['percent' => $percent, 'store_id' => null, 'category_id' => null, 'product_id' => null],
            $scope);
    }

    private function testSilentWhenOff(): void
    {
        $this->group('вимкнений банер нічого не обіцяє');
        $this->banner('0', '15');
        $this->ok('мовчимо, поки смужки немає', Catalog::bannerWarning([]) === null);
        $this->ok('мовчимо навіть без жодної акції', Catalog::bannerWarning([]) === null);
    }

    private function testNoPercent(): void
    {
        $this->group('увімкнено без відсотка');
        $this->banner('1', '');
        $w = Catalog::bannerWarning([$this->promo(20)]);
        $this->ok('порожній відсоток помічено', $w !== null);
        $this->ok('сказано, що покупець бачить «−0%»', $w !== null && str_contains($w, '−0%'));
    }

    private function testNoPromotions(): void
    {
        $this->group('обіцянка без жодної акції');
        $this->banner('1', '15');
        $w = Catalog::bannerWarning([]);
        $this->ok('розходження помічено', $w !== null);
        $this->ok('названо обіцяний відсоток', $w !== null && str_contains($w, '15'));
        $this->ok('сказано, що акцій немає', $w !== null && str_contains($w, 'жодної акції'));
    }

    private function testStoreOnlyDoesNotCount(): void
    {
        $this->group('акція магазину обіцянку не виконує');
        $this->banner('1', '15');
        // у загальному каталозі така акція не показується (див. promoPercent),
        // тож для відвідувача сайту знижки як не було
        $w = Catalog::bannerWarning([$this->promo(20, ['store_id' => 3])]);
        $this->ok('акція точки не рахується за загальну', $w !== null);
        $this->ok('це саме випадок «акцій немає»', $w !== null && str_contains($w, 'жодної акції'));
    }

    private function testNarrowedToPartOfCatalog(): void
    {
        $this->group('знижка є, але не на все');
        $this->banner('1', '15');
        $cat = Catalog::bannerWarning([$this->promo(15, ['category_id' => 2])]);
        $this->ok('категорійна акція помічена як часткова',
            $cat !== null && str_contains($cat, 'частину каталогу'));
        $prod = Catalog::bannerWarning([$this->promo(15, ['product_id' => 7])]);
        $this->ok('акція на один товар — так само часткова',
            $prod !== null && str_contains($prod, 'частину каталогу'));
    }

    private function testSmallerThanPromised(): void
    {
        $this->group('знижка менша за обіцяну');
        $this->banner('1', '15');
        $w = Catalog::bannerWarning([$this->promo(10)]);
        $this->ok('розходження помічено', $w !== null);
        $this->ok('названо і обіцяне, і справжнє',
            $w !== null && str_contains($w, '15') && str_contains($w, '10'));
        // дробові показуємо без хвостових нулів: «12,5%», а не «12,50%»
        $this->banner('1', '12.5');
        $half = Catalog::bannerWarning([$this->promo(10)]);
        $this->ok('дробовий відсоток без зайвих нулів',
            $half !== null && str_contains($half, '12,5%') && !str_contains($half, '12,50'));
    }

    private function testHonest(): void
    {
        $this->group('банер каже правду — мовчимо');
        $this->banner('1', '15');
        $this->ok('акція рівно на обіцяне', Catalog::bannerWarning([$this->promo(15)]) === null);
        $this->ok('акція більша за обіцяне теж чесна', Catalog::bannerWarning([$this->promo(20)]) === null);
        $this->ok('серед кількох акцій рахується найбільша',
            Catalog::bannerWarning([$this->promo(5), $this->promo(15), $this->promo(10)]) === null);
        $this->ok('загальна акція перекриває часткову',
            Catalog::bannerWarning([$this->promo(15), $this->promo(30, ['category_id' => 2])]) === null);
    }
}

return (new BannerTest())->run();
