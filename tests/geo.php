<?php
/**
 * Координати точок продажу.  Запуск: php bin/cli.php test
 *
 * Перевіряємо не арифметику, а розбір того, що людина реально вставить у поле.
 * Координати не набирають з голови — їх копіюють із Google Maps, і копіюють
 * по-різному: пару чисел, ту саму пару з комою замість крапки (так її віддає
 * система з українською локаллю), або просто посилання на місце.
 *
 * Головне, що доводимо: описка не стирає правильні координати, а нерозбірливий
 * рядок не перетворюється на мітку посеред океану. Мітка не там — це покупець,
 * який приїхав не туди, і дізнається він про це вже на місці.
 */
declare(strict_types=1);

final class GeoTest
{
    private int $pass = 0;
    private int $fail = 0;

    public function run(): int
    {
        $this->testPair();
        $this->testCommaDecimal();
        $this->testUrl();
        $this->testGarbage();
        $this->testFormatRoundTrip();
        $this->testRoute();

        echo "\n" . ($this->fail === 0
            ? "УСЕ ДОБРЕ: {$this->pass} перевірок\n"
            : "ПРОВАЛЕНО: {$this->fail} з " . ($this->pass + $this->fail) . "\n");
        return $this->fail === 0 ? 0 : 1;
    }

    private function ok(bool $cond, string $what): void
    {
        if ($cond) { $this->pass++; echo "  ✓ $what\n"; }
        else { $this->fail++; echo "  ✗ $what\n"; }
    }

    /** Пара чисел — те, що дає «копіювати координати» в Google Maps */
    private function testPair(): void
    {
        echo "== пара чисел ==\n";
        $p = Geo::parse('50.4501, 30.5234');
        $this->ok($p !== null && abs($p['lat'] - 50.4501) < 1e-9, 'широта з пари');
        $this->ok($p !== null && abs($p['lng'] - 30.5234) < 1e-9, 'довгота з пари');

        $this->ok(Geo::parse('50.4501 30.5234') !== null, 'пара через пробіл');
        $this->ok(Geo::parse('  50.4501,30.5234  ') !== null, 'зайві пробіли не заважають');

        $neg = Geo::parse('-33.8688, 151.2093');
        $this->ok($neg !== null && $neg['lat'] < 0, 'південна півкуля — мінус зберігається');
    }

    /** Українська локаль копіює «50,4501, 30,5234» — чотири числа замість двох */
    private function testCommaDecimal(): void
    {
        echo "== кома як десятковий знак ==\n";
        $p = Geo::parse('50,4501, 30,5234');
        $this->ok($p !== null, 'розбирається взагалі');
        $this->ok($p !== null && abs($p['lat'] - 50.4501) < 1e-9, 'широта не стала 50');
        $this->ok($p !== null && abs($p['lng'] - 30.5234) < 1e-9, 'довгота не стала 30');
    }

    /** Посилання вставляють частіше, ніж пару: воно копіюється кнопкою «Поділитися» */
    private function testUrl(): void
    {
        echo "== посилання ==\n";
        $p = Geo::parse('https://www.google.com/maps/@50.4501,30.5234,17z');
        $this->ok($p !== null && abs($p['lat'] - 50.4501) < 1e-9, 'центр карти з /@');

        // У повному посиланні є і центр карти, і сама мітка: беремо мітку
        $full = 'https://www.google.com/maps/place/Київ/@50.4000,30.5000,12z/data=!3m1!4b1!4m5!3m4!1s0x0:0x0!8m2!3d50.4501!4d30.5234';
        $p2 = Geo::parse($full);
        $this->ok($p2 !== null && abs($p2['lat'] - 50.4501) < 1e-9, 'мітка (!3d) важливіша за центр (@)');
    }

    /** Найдорожчий випадок: із сміття не має вийти правдоподібна мітка */
    private function testGarbage(): void
    {
        echo "== що не є координатами ==\n";
        $this->ok(Geo::parse('') === null, 'порожній рядок');
        $this->ok(Geo::parse('вул. Медова, 12') === null, 'адреса словами');
        $this->ok(Geo::parse('0, 0') === null, 'нуль-нуль — це порожнє поле, а не Атлантика');
        $this->ok(Geo::parse('91.5, 30.1') === null, 'широта поза глобусом');
        $this->ok(Geo::parse('50.45, 181.2') === null, 'довгота поза глобусом');
        $this->ok(Geo::parse('50.4501') === null, 'одне число — це не точка');
    }

    /** Що зберегли, те й показуємо: інакше кожне збереження зсувало б мітку */
    private function testFormatRoundTrip(): void
    {
        echo "== збереження й показ ==\n";
        $p = Geo::parse('50.4501, 30.5234');
        $store = ['lat' => $p['lat'], 'lng' => $p['lng']];
        $this->ok(Geo::format($store) === '50.4501, 30.5234', 'формат читається так само, як вводили');
        $again = Geo::parse(Geo::format($store));
        $this->ok($again !== null && $again['lat'] === $p['lat'] && $again['lng'] === $p['lng'],
            'повторний розбір дає ті самі числа');

        $this->ok(Geo::format(['lat' => null, 'lng' => null]) === '', 'без координат — порожньо');
        $this->ok(Geo::has(['lat' => null, 'lng' => null]) === false, 'has() бачить порожнечу');
    }

    /** Кнопка маршруту не має бути мертвою навіть без координат */
    private function testRoute(): void
    {
        echo "== маршрут ==\n";
        $withGeo = ['lat' => 50.4501, 'lng' => 30.5234, 'city' => 'Київ', 'address' => 'вул. Медова, 12'];
        $this->ok(str_contains(Geo::routeUrl($withGeo), '50.4501'), 'із координатами веде на координати');

        $noGeo = ['lat' => null, 'lng' => null, 'city' => 'Київ', 'address' => 'вул. Медова, 12'];
        $url = Geo::routeUrl($noGeo);
        $this->ok($url !== '' && str_contains($url, 'maps'), 'без координат веде хоч за адресою');

        $nothing = ['lat' => null, 'lng' => null, 'city' => '', 'address' => ''];
        $this->ok(Geo::routeUrl($nothing) === '', 'без адреси кнопки немає зовсім');

        $points = Geo::points([
            ['id' => 1, 'name' => 'Головний', 'city' => 'Київ', 'address' => 'Медова, 12',
             'phone' => '', 'lat' => 50.45, 'lng' => 30.52],
            ['id' => 2, 'name' => 'Без мітки', 'city' => 'Львів', 'address' => '',
             'phone' => '', 'lat' => null, 'lng' => null],
        ]);
        $this->ok(count($points) === 1, 'точки без координат на карту не потрапляють');
    }
}

return (new GeoTest())->run();
