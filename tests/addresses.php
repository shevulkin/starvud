<?php
/**
 * Збережені адреси доставки.  Запуск: php bin/cli.php test
 *
 * Головне, що доводимо: адреса належить своєму власнику й нічого не знає про
 * отримувача. Id адреси приходить із форми, тому кожна дія (взяти, зробити
 * основною, видалити, змінити) має бути глухою до чужого рядка — інакше
 * підстановкою числа в POST можна було б читати й правити чужі адреси.
 *
 * Тест створює власних користувачів і прибирає їх за собою.
 */
declare(strict_types=1);

final class AddressesTest
{
    private int $pass = 0;
    private int $fail = 0;
    private int $me = 0;
    private int $other = 0;

    public function run(): int
    {
        $this->setUp();
        try {
            $this->testSave();
            $this->testNoDuplicates();
            $this->testForeignAddress();
            $this->testDefault();
            $this->testLimit();
            $this->testTitle();
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
        // хвости попереднього запуску, якщо той обірвався на півдорозі
        foreach (DB::all("SELECT id FROM users WHERE email IN ('addr-me@bofu.test', 'addr-other@bofu.test')") as $u) {
            DB::delete('user_addresses', 'user_id = ?', [(int)$u['id']]);
            DB::delete('users', 'id = ?', [(int)$u['id']]);
        }
        $this->me = DB::insert('users', ['email' => 'addr-me@bofu.test', 'name' => 'Тест покупець',
            'active' => 1, 'created_at' => now()]);
        $this->other = DB::insert('users', ['email' => 'addr-other@bofu.test', 'name' => 'Тест сусід',
            'active' => 1, 'created_at' => now()]);
    }

    private function tearDown(): void
    {
        foreach ([$this->me, $this->other] as $id) {
            if ($id) {
                DB::delete('user_addresses', 'user_id = ?', [$id]);
                DB::delete('users', 'id = ?', [$id]);
            }
        }
    }

    private function ok(string $what, bool $cond): void
    {
        if ($cond) { $this->pass++; echo "  ok   $what\n"; }
        else { $this->fail++; echo "  FAIL $what\n"; }
    }

    private function group(string $name): void { echo "\n== $name ==\n"; }

    private function np(string $city, string $office, string $label = ''): array
    {
        return ['delivery' => 'np', 'city' => $city, 'np_office' => $office,
                'city_ref' => 'ref-' . md5($city), 'label' => $label];
    }

    private function testSave(): void
    {
        $this->group('адреса зберігається й дістається власнику');
        $id = Addresses::save($this->me, $this->np('м. Київ, Київська обл.', 'Відділення №1'));
        $this->ok('адресу створено', $id > 0);
        $a = Addresses::find($this->me, (int)$id);
        $this->ok('читається за id', $a !== null && $a['city'] === 'м. Київ, Київська обл.');
        $this->ok('ref міста збережено — підказки відділень працюють одразу', !empty($a['city_ref']));
        $this->ok('перша адреса стала основною', (int)$a['is_default'] === 1);
        $this->ok('отримувача в адресі немає', !array_key_exists('recipient_name', $a));

        $this->ok('порожня адреса не зберігається', Addresses::save($this->me, $this->np('', '')) === null);
        $this->ok('гостю зберігати нікуди', Addresses::save(null, $this->np('м. Львів', '№5')) === null);

        // «інше» тримається на самій адресі, а не на місті
        $this->ok('доставка «інше» без адреси не зберігається',
            Addresses::save($this->me, ['delivery' => 'other', 'address' => '  ']) === null);
        $other = Addresses::save($this->me, ['delivery' => 'other', 'address' => 'Самовивіз з пасіки']);
        $this->ok('доставка «інше» з адресою зберігається', $other > 0);
        Addresses::remove($this->me, (int)$other);
    }

    private function testNoDuplicates(): void
    {
        $this->group('та сама адреса не плодить копій');
        $before = count(Addresses::forUser($this->me));
        $again = Addresses::save($this->me, $this->np('м. Київ, Київська обл.', 'Відділення №1'));
        $this->ok('повторне збереження повертає той самий рядок', count(Addresses::forUser($this->me)) === $before);
        $this->ok('id той самий', Addresses::find($this->me, (int)$again) !== null);

        $new = Addresses::save($this->me, $this->np('м. Київ, Київська обл.', 'Відділення №7'));
        $this->ok('інше відділення — це вже інша адреса', $new !== $again);
        $this->ok('у списку тепер дві', count(Addresses::forUser($this->me)) === $before + 1);
    }

    private function testForeignAddress(): void
    {
        $this->group('чужа адреса недоступна');
        $mine = (int)Addresses::save($this->me, $this->np('м. Одеса', 'Відділення №3'));
        $this->ok('сусід не бачить її за id', Addresses::find($this->other, $mine) === null);
        $this->ok('немає в списку сусіда', Addresses::forUser($this->other) === []);

        Addresses::remove($this->other, $mine);
        $this->ok('сусід не може її видалити', Addresses::find($this->me, $mine) !== null);

        Addresses::setDefault($this->other, $mine);
        $this->ok('сусід не може зробити її своєю основною', Addresses::forUser($this->other) === []);

        $this->ok('сусід не може переписати її під себе',
            Addresses::save($this->other, $this->np('м. Харків', 'Відділення №9'), $mine) === null);
        $this->ok('вміст лишився власника', (Addresses::find($this->me, $mine)['city'] ?? '') === 'м. Одеса');

        // правка власною рукою — навпаки, працює й не створює другий рядок
        $before = count(Addresses::forUser($this->me));
        Addresses::save($this->me, $this->np('м. Одеса', 'Відділення №4', 'Робота'), $mine);
        $edited = Addresses::find($this->me, $mine);
        $this->ok('власник правит свою адресу', $edited['np_office'] === 'Відділення №4');
        $this->ok('мітка збереглась', $edited['label'] === 'Робота');
        $this->ok('нового рядка не з\'явилось', count(Addresses::forUser($this->me)) === $before);
        Addresses::remove($this->me, $mine);
    }

    private function testDefault(): void
    {
        $this->group('основна адреса рівно одна');
        $list = Addresses::forUser($this->me);
        $this->ok('є з чого обирати', count($list) >= 2);
        $second = (int)$list[1]['id'];
        Addresses::setDefault($this->me, $second);
        $marked = array_filter(Addresses::forUser($this->me), fn($a) => (int)$a['is_default'] === 1);
        $this->ok('позначена одна', count($marked) === 1);
        $this->ok('саме обрана', (int)reset($marked)['id'] === $second);
        $this->ok('основна йде першою в списку', (int)Addresses::forUser($this->me)[0]['id'] === $second);

        // видалення основної не лишає список без основної — інакше checkout нічого не підставить
        Addresses::remove($this->me, $second);
        $left = Addresses::forUser($this->me);
        $this->ok('після видалення основною стала інша',
            $left !== [] && (int)$left[0]['is_default'] === 1);
    }

    private function testLimit(): void
    {
        $this->group('ліміт адрес');
        for ($i = 0; count(Addresses::forUser($this->me)) < Addresses::LIMIT && $i < 50; $i++) {
            Addresses::save($this->me, $this->np('м. Тестове ' . $i, 'Відділення №' . $i));
        }
        $this->ok('набрали ліміт', count(Addresses::forUser($this->me)) === Addresses::LIMIT);
        $this->ok('понад ліміт не зберігається',
            Addresses::save($this->me, $this->np('м. Зайве', 'Відділення №99')) === null);
        $this->ok('наявні адреси не постраждали', count(Addresses::forUser($this->me)) === Addresses::LIMIT);
        // при цьому оновлення наявної проходить і на межі ліміту
        $first = Addresses::forUser($this->me)[0];
        $this->ok('наявну адресу зберегти повторно можна',
            Addresses::save($this->me, [
                'delivery' => 'np', 'city' => $first['city'], 'np_office' => $first['np_office'],
            ]) === (int)$first['id']);
    }

    private function testTitle(): void
    {
        $this->group('підпис у списку');
        $this->ok('мітка головніша за адресу',
            Addresses::title(['label' => 'Дім', 'delivery' => 'np', 'city' => 'м. Київ', 'np_office' => '№1']) === 'Дім');
        $this->ok('без мітки — місто й відділення',
            Addresses::title(['label' => '', 'delivery' => 'np', 'city' => 'м. Київ', 'np_office' => '№1']) === 'м. Київ, №1');
        $this->ok('без відділення — саме місто',
            Addresses::title(['delivery' => 'np', 'city' => 'м. Київ', 'np_office' => null]) === 'м. Київ');
        $this->ok('для «іншого» — сама адреса',
            Addresses::title(['delivery' => 'other', 'address' => 'вул. Медова, 1']) === 'вул. Медова, 1');
    }
}

return (new AddressesTest())->run();
