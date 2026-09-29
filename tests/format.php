<?php
/**
 * Українські форми слів і подача ваги.  Запуск: php bin/cli.php test
 *
 * Обидва правила з тих, що ламаються тихо. Числівник неузгоджений з іменником
 * («1 авторських курсів») не валить сторінку — він просто щодня стоїть у
 * найпомітнішому рядку головної, поки хтось не прочитає його вголос. А вага
 * псується ще підступніше: зрізання нулів рядком перетворює 200 г на 2 г,
 * і на вітрині це виглядає як справжня вага товару, а не як помилка.
 *
 * Тому обидва зафіксовані тут — разом із випадками, на яких наївна реалізація
 * і спотикається: одинадцять–чотирнадцять і круглі числа.
 */
declare(strict_types=1);

final class FormatTest
{
    private int $pass = 0;
    private int $fail = 0;

    public function run(): int
    {
        $this->testPlural();
        $this->testPluralTeens();
        $this->testWeight();
        $this->testPer100g();
        $this->testShopUrl();

        echo "\n" . ($this->fail === 0
            ? "УСЕ ДОБРЕ: {$this->pass} перевірок\n"
            : "ПРОВАЛЕНО: {$this->fail} з " . ($this->pass + $this->fail) . "\n");
        return $this->fail === 0 ? 0 : 1;
    }

    private function ok(string $what, bool $cond): void
    {
        if ($cond) { $this->pass++; echo "  ok   $what\n"; }
        else { $this->fail++; echo "  FAIL $what\n"; }
    }

    private function group(string $name): void { echo "\n== $name ==\n"; }

    private function eqStr(string $what, string $got, string $want): void
    {
        $this->ok($what . ($got === $want ? '' : " — очікували «$want», а вийшло «$got»"), $got === $want);
    }

    private function testPlural(): void
    {
        $this->group('узгодження числівника');
        foreach ([1 => 'курс', 2 => 'курси', 3 => 'курси', 4 => 'курси',
                  5 => 'курсів', 9 => 'курсів', 21 => 'курс', 22 => 'курси',
                  25 => 'курсів', 101 => 'курс', 102 => 'курси'] as $n => $want) {
            $this->eqStr("$n", plural($n, 'курс', 'курси', 'курсів'), $want);
        }
        $this->eqStr('нуль — родовий множини', plural(0, 'курс', 'курси', 'курсів'), 'курсів');
        $this->eqStr('разом із числом', plural_n(3, 'товар', 'товари', 'товарів'), '3 товари');
    }

    /**
     * Одинадцять–чотирнадцять: саме на них ламається перевірка «останньої
     * цифри». 11 закінчується на 1, але це «одинадцять курсів», а не «курс».
     */
    private function testPluralTeens(): void
    {
        $this->group('одинадцять–чотирнадцять — виняток');
        foreach ([11, 12, 13, 14, 111, 112, 1013] as $n) {
            $this->eqStr("$n", plural($n, 'курс', 'курси', 'курсів'), 'курсів');
        }
    }

    private function testWeight(): void
    {
        $this->group('вага людською мовою');
        $this->eqStr('менше кілограма — у грамах', weight_fmt(0.35), '350 г');
        // Круглі сотні — те, на чому спіткнулась перша реалізація: вона зрізала
        // нулі рядком, і 200 г ставало 2 г.
        $this->eqStr('круглі сотні не втрачають нулів', weight_fmt(0.2), '200 г');
        $this->eqStr('півкіло', weight_fmt(0.5), '500 г');
        $this->eqStr('дробові грами', weight_fmt(0.0125), '12,5 г');
        $this->eqStr('рівно кілограм', weight_fmt(1), '1 кг');
        $this->eqStr('півтора — один знак', weight_fmt(1.5), '1,5 кг');
        $this->eqStr('чверть — два знаки', weight_fmt(3.25), '3,25 кг');
        $this->eqStr('порожня вага — порожній рядок', weight_fmt(null), '');
        $this->eqStr('нуль — теж порожньо', weight_fmt(0), '');
    }

    private function testPer100g(): void
    {
        $this->group('ціна за 100 г');
        $this->eqStr('банка 350 г за 180 грн', price_per_100g(180, 0.35), '51,43 грн / 100 г');
        $this->eqStr('рівне число без копійок', price_per_100g(150, 0.2), '75 грн / 100 г');
        // Дрібні фасовки ділити немає сенсу: «за 100 г» для пляшечки 30 мл —
        // цифра, якої покупець ніколи не купить.
        $this->eqStr('дрібна фасовка не ділиться', price_per_100g(120, 0.03), '');
        $this->eqStr('без ваги — нічого', price_per_100g(180, null), '');
        $this->eqStr('без ціни — нічого', price_per_100g(null, 0.35), '');
        $this->eqStr('ціна «за запитом» не ділиться', price_per_100g(0, 0.35), '');
    }

    /**
     * Адреси каталогу.
     *
     * Розділ живе у шляху (/shop/med), фільтри лишаються параметрами. Правило
     * зібране в один хелпер саме тому, що раніше рядок '/shop?cat=' був
     * розсипаний по десятку шаблонів; тест стежить, щоб форма адреси не
     * розʼїхалась між ними знову.
     */
    private function testShopUrl(): void
    {
        $this->group('адреси каталогу');
        $this->eqStr('без розділу', shop_path(), '/shop');
        $this->eqStr('розділ у шляху', shop_path('med'), '/shop/med');
        $this->eqStr('порожній slug — просто каталог', shop_path(''), '/shop');
        $this->eqStr('null — просто каталог', shop_path(null), '/shop');

        // Фільтри лишаються параметрами: вони уточнюють ту саму сторінку,
        // а не створюють нову.
        $this->ok('сортування додається параметром',
            str_ends_with(shop_url('med', ['sort' => 'price_asc']), '/shop/med?sort=price_asc'));
        $this->ok('порожні параметри не потрапляють в адресу',
            !str_contains(shop_url('med', ['sort' => '', 'min' => null, 'attr' => []]), '?'));
        $this->ok('масив характеристик переживає збірку',
            str_contains(shop_url('med', ['attr' => ['sort-medu' => ['Липа']]]), 'attr'));
    }
}

return (new FormatTest())->run();
