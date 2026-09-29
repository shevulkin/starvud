<?php
/**
 * Власні штрихкоди.  Запуск: php bin/cli.php test
 *
 * Читання коду камерою живе в браузері й перевіряється окремо — відкрийте
 * tests/barcode.html. Тут — серверна половина: контрольна цифра, з якої взагалі
 * починається довіра до коду, унікальність і малюнок для друку.
 *
 * Головне, що доводимо: два різні товари не можуть отримати один код. Однакові
 * коди на касі означають, що в чек тихо ляже не та позиція, — і продавець цього
 * не помітить, бо сканер «спрацював».
 */
declare(strict_types=1);

final class BarcodeTest
{
    private int $pass = 0;
    private int $fail = 0;

    public function run(): int
    {
        $this->testCheckDigit();
        $this->testTypo();
        $this->testMake();
        $this->testUnique();
        $this->testPicture();

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

    private function testCheckDigit(): void
    {
        $this->group('контрольна цифра');
        // Загальновідомі приклади EAN-13 і EAN-8 зі стандарту
        foreach (['400638133393' => '1', '590123412345' => '7', '012345678912' => '8', '9638507' => '4'] as $body => $want) {
            $this->ok("'$body' → $want", Barcode::checkDigit((string)$body) === $want);
        }
        $this->ok('повний код визнається дійсним', Barcode::valid('4006381333931'));
        $this->ok('зіпсована остання цифра — недійсний', !Barcode::valid('4006381333939'));
        $this->ok('EAN-8 теж перевіряється', Barcode::valid('96385074'));
        $this->ok('12 цифр — це не код', !Barcode::valid('400638133393'));
        $this->ok('літери — не код', !Barcode::valid('40063813a3931'));
    }

    /**
     * Описка в останній цифрі. Саме заради неї контрольна цифра й існує:
     * код виглядає бездоганно, а сканер не знайде його ніколи. Мовчки прийняти
     * такий код — найдорожча помилка цього екрана, бо шукати її потім будуть
     * у камері, у сканері й у декодері, тільки не в самому коді.
     */
    private function testTypo(): void
    {
        $this->group('описка в коді має бути видна одразу');
        // Справжній випадок: на коробці 4820104880119, у картці набрали …118
        $problem = (string)Barcode::problem('4820104880118');
        $this->ok('описку впіймано', $problem !== '');
        $this->ok('сказано, якою цифра має бути', str_contains($problem, '4820104880119'));
        $this->ok('виправлення пропонується готовим', Barcode::fixed('4820104880118') === '4820104880119');

        $this->ok('правильний код претензій не має', Barcode::problem('4820104880119') === null);
        $this->ok('порожнє поле — не помилка', Barcode::problem('') === null);
        $this->ok('виправляти правильний код нема чого', Barcode::fixed('4820104880119') === '');

        $short = (string)Barcode::problem('482010488011');
        $this->ok('коротший код: названо і потрібну довжину, і наявну',
            str_contains($short, '13') && str_contains($short, '12'));
        $this->ok('літери в штрихкоді відхиляються',
            str_contains((string)Barcode::problem('4820104ABC19'), 'цифр'));
        $this->ok('EAN-8 теж перевіряється', Barcode::problem('96385074') === null);
    }

    private function testMake(): void
    {
        $this->group('власний код позиції');
        $code = Barcode::make('p', 31);
        $this->ok('довжина 13', strlen($code) === 13);
        $this->ok('контрольна цифра сходиться', Barcode::valid($code));
        $this->ok('це внутрішній код магазину', Barcode::isInternal($code));
        // Префікс 20–29 стандарт лишив магазинам: такий код не належить нікому
        // у світі, тож не зіткнеться з фабричним на чужому товарі
        $this->ok('префікс із діапазону для внутрішнього вжитку',
            str_starts_with($code, '200'));
        $this->ok('повторний виклик дає той самий код', Barcode::make('p', 31) === $code);
    }

    private function testUnique(): void
    {
        $this->group('два товари не отримають один код');
        $seen = [];
        for ($id = 1; $id <= 500; $id++) {
            foreach (['p', 'v'] as $kind) {
                $code = Barcode::make($kind, $id);
                if (isset($seen[$code])) {
                    $this->ok("збіг на $kind$id з {$seen[$code]}", false);
                    return;
                }
                $seen[$code] = $kind . $id;
            }
        }
        $this->ok('1000 кодів — жодного збігу', count($seen) === 1000);
        // Товар №7 і фасовка №7 — різні позиції, і код у них має різнитись
        $this->ok('товар і фасовка з тим самим id — різні коди',
            Barcode::make('p', 7) !== Barcode::make('v', 7));
    }

    private function testPicture(): void
    {
        $this->group('малюнок для друку');
        $bits = Barcode::bits('4006381333931');
        $this->ok('EAN-13 — це 95 модулів', strlen($bits) === 95);
        $this->ok('починається роздільником', str_starts_with($bits, '101'));
        $this->ok('закінчується роздільником', str_ends_with($bits, '101'));
        // Середній роздільник стоїть рівно посередині — за ним декодер
        // відрізняє ліву половину коду від правої
        $this->ok('середній роздільник на місці', substr($bits, 45, 5) === '01010');
        $this->ok('EAN-8 — 67 модулів', strlen(Barcode::bits('96385074')) === 67);
        $this->ok('недійсний код не малюється', Barcode::bits('4006381333939') === '');

        $svg = Barcode::svg('4006381333931');
        $this->ok('svg віддається', str_starts_with($svg, '<svg'));
        $this->ok('у підписі є сам код', str_contains($svg, '4006381333931'));
        $this->ok('порожній код не дає порожнього тега', Barcode::svg('') === '');
    }
}

return (new BarcodeTest())->run();
