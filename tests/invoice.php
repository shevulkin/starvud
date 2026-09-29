<?php
/**
 * Рахунок на оплату по IBAN.  Запуск: php bin/cli.php test
 *
 * Дві речі, які тут можуть коштувати грошей, і обидві тихі.
 *
 * Перша — сума прописом. Рахунок без неї не приймають, а помилка у відмінюванні
 * («одна тисяча» проти «один тисяча») робить документ несерйозним. Найгірші
 * місця — одиниця й два в жіночому роді, підлітки 11–19 та круглі тисячі.
 *
 * Друга серйозніша. ФОП на другій групі єдиного податку не має права продавати
 * юрособам і ФОПам на загальній системі — за це втрачають спрощену систему.
 * Тому перевіряється не оформлення документа, а сам факт: чи можна цьому
 * продавцю продавати цьому покупцю.
 */
declare(strict_types=1);

final class InvoiceTest
{
    private int $pass = 0;
    private int $fail = 0;

    public function run(): int
    {
        $this->testWords();
        $this->testWhoCanSell();
        $this->testMissing();
        $this->testNumber();

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

    private function testWords(): void
    {
        $this->group('сума прописом');
        // Списком пар, а не масивом «сума => очікуване»: ключі масиву в PHP
        // цілі, і 250.50 тихо стало б 250 — перевірка копійок перевіряла б
        // рівно те, чого не перевіряє
        $cases = [
            [0, 'нуль грн 00 коп.'],
            [1, 'один грн 00 коп.'],
            [250.50, 'двісті пʼятдесят грн 50 коп.'],
            // Тисячі жіночого роду — найчастіша помилка в саморобних генераторах
            [1000, 'одна тисяча грн 00 коп.'],
            [2000, 'дві тисячі грн 00 коп.'],
            [5000, 'пʼять тисяч грн 00 коп.'],
            // Підлітки: 11 тисяч — саме «тисяч», а не «тисяча»
            [11000, 'одинадцять тисяч грн 00 коп.'],
            [21000, 'двадцять одна тисяча грн 00 коп.'],
            [2345.67, 'дві тисячі триста сорок пʼять грн 67 коп.'],
            [1000000, 'один мільйон грн 00 коп.'],
        ];
        foreach ($cases as [$sum, $want]) {
            $got = Invoice::words((float)$sum);
            $this->ok($sum . ' → ' . $want, $got === $want);
        }
        // Копійки не мають губитись на округленні
        $this->ok('99.99 не стає сотнею', str_contains(Invoice::words(99.99), 'девʼяносто девʼять грн 99'));
    }

    private function testWhoCanSell(): void
    {
        $this->group('кому цей ФОП має право продавати');
        $first = ['ep_group' => 1];
        $second = ['ep_group' => 2];
        $third = ['ep_group' => 3];
        $general = ['ep_group' => null];

        $this->ok('друга група — населенню можна', Invoice::forbidden($second, 'person') === '');
        $this->ok('друга група — платнику ЄП можна', Invoice::forbidden($second, 'ep') === '');
        $this->ok('друга група — юрособі НЕ можна', Invoice::forbidden($second, 'general') !== '');
        $this->ok('і сказано, що робити',
            str_contains(Invoice::forbidden($second, 'general'), 'іншого власника'));

        $this->ok('перша група — лише населенню', Invoice::forbidden($first, 'ep') !== '');
        $this->ok('перша група — населенню можна', Invoice::forbidden($first, 'person') === '');

        $this->ok('третя група — можна всім', Invoice::forbidden($third, 'general') === '');
        $this->ok('загальна система — можна всім', Invoice::forbidden($general, 'general') === '');
        $this->ok('покупець не вказаний — не забороняємо наперед',
            Invoice::forbidden($second, '') === '');
    }

    private function testMissing(): void
    {
        $this->group('чого бракує для рахунку');
        $store = DB::row('SELECT * FROM stores WHERE active = 1 ORDER BY sort, id LIMIT 1');
        if (!$store) { echo "  — немає магазинів, пропускаємо\n"; return; }
        $storeId = (int)$store['id'];
        $wasOwner = DB::val('SELECT owner_id FROM stores WHERE id = ?', [$storeId]);

        $child = ['id' => 0, 'store_id' => $storeId, 'total' => 100.0, 'number' => 'X-1/1'];
        $parent = ['buyer_type' => 'ep'];

        DB::update('stores', ['owner_id' => null], 'id = ?', [$storeId]);
        $said = implode(' | ', Invoice::missing($child, $parent));
        $this->ok('без власника рахунок не виставити', str_contains($said, 'власника'));

        $owner = DB::insert('owners', ['name' => 'Тест', 'ep_group' => 2, 'vat' => 0,
                                       'active' => 1, 'sort' => 0, 'created_at' => now()]);
        DB::update('stores', ['owner_id' => $owner], 'id = ?', [$storeId]);
        $said = implode(' | ', Invoice::missing($child, $parent));
        $this->ok('без IBAN не виставити', str_contains($said, 'IBAN'));
        $this->ok('без ІПН теж кажемо', str_contains($said, 'ІПН'));

        DB::update('owners', ['iban' => 'UA1234', 'tax_id' => '123', 'full_name' => 'ФОП Тест'],
                   'id = ?', [$owner]);
        $this->ok('із заповненими реквізитами перешкод немає',
            Invoice::missing($child, $parent) === []);

        // А ось це не про заповненість — це про право продавати
        $this->ok('заборона продажу юрособі теж потрапляє в перешкоди',
            (bool)array_filter(Invoice::missing($child, ['buyer_type' => 'general']),
                fn($p) => str_contains($p, 'друга група')));

        $this->ok('нульова сума не пропускається',
            (bool)array_filter(Invoice::missing(['id' => 0, 'store_id' => $storeId, 'total' => 0, 'number' => 'X'],
                $parent), fn($p) => str_contains($p, 'нульова')));

        DB::update('stores', ['owner_id' => $wasOwner], 'id = ?', [$storeId]);
        DB::delete('owners', 'id = ?', [$owner]);
    }

    private function testNumber(): void
    {
        $this->group('номер документа');
        $child = ['number' => 'BOFU-260818-A3F2/2'];
        $this->ok('рахунок бере номер частини', Invoice::number($child) === 'Р-BOFU-260818-A3F2-2');
        $this->ok('накладна відрізняється префіксом', Invoice::number($child, 'act') === 'В-BOFU-260818-A3F2-2');
        $this->ok('слеша в номері не лишається', !str_contains(Invoice::number($child), '/'));
    }
}

return (new InvoiceTest())->run();
