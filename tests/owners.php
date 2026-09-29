<?php
/**
 * Власники точок: чий виторг і з якою ставкою.  Запуск: php bin/cli.php test
 *
 * Мережа з трьох магазинів може бути двома різними платниками податків. Для
 * покупця це один сайт, для ДПС — дві окремі історії, і найдорожча помилка тут
 * тиха: ставка одного платника потрапляє в чеки іншого. Помітити її можна аж у
 * податковому періоді, тож ловити треба тут.
 *
 * Друга пастка ще тихіша й трапляється частіше: людина знає, що вона «на 3-й
 * групі», і ставить 3 у поле «податкова група чека», де 3 означає «ПДВ 20% +
 * акциз 5%». Чеки пробиваються, все зелене, а в ДПС їде вигаданий податок.
 * Саме тому перевіряється поєднання групи ЄП, ознаки ПДВ і ставки чека.
 */
declare(strict_types=1);

final class OwnersTest
{
    private int $pass = 0;
    private int $fail = 0;
    private array $ownerIds = [];
    private array $storeWas = [];
    private array $settingsWas = [];
    private int $storeA = 0;
    private int $storeB = 0;

    public function run(): int
    {
        $stores = DB::all('SELECT * FROM stores ORDER BY sort, id LIMIT 2');
        if (count($stores) < 2) { echo "  — потрібні дві точки, пропускаємо\n"; return 0; }
        $this->storeA = (int)$stores[0]['id'];
        $this->storeB = (int)$stores[1]['id'];

        $this->setUp();
        try {
            $this->testChain();
            $this->testEpGroupIsNotTaxGroup();
            $this->testVatConsistency();
            $this->testIncome();
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
        foreach (['vchasno_taxgrp', 'vchasno_cashier'] as $k) $this->settingsWas[$k] = Settings::get($k, null);
        Settings::set('vchasno_taxgrp', '2');
        Settings::set('vchasno_cashier', '');

        foreach ([$this->storeA, $this->storeB] as $id) {
            $this->storeWas[$id] = (array)DB::row(
                'SELECT owner_id, vchasno_taxgrp, vchasno_cashier FROM stores WHERE id = ?', [$id]);
            DB::update('stores', ['vchasno_taxgrp' => null, 'vchasno_cashier' => null], 'id = ?', [$id]);
        }
    }

    private function tearDown(): void
    {
        foreach ($this->storeWas as $id => $was) DB::update('stores', $was, 'id = ?', [$id]);
        if ($this->ownerIds) {
            DB::delete('owners', 'id IN (' . implode(',', array_map('intval', $this->ownerIds)) . ')');
        }
        foreach ($this->settingsWas as $k => $v) Settings::set($k, $v ?? '');
    }

    private function ok(string $what, bool $cond): void
    {
        if ($cond) { $this->pass++; echo "  ok   $what\n"; }
        else { $this->fail++; echo "  FAIL $what\n"; }
    }

    private function group(string $name): void { echo "\n== $name ==\n"; }

    private function owner(array $fields): int
    {
        $id = DB::insert('owners', $fields + [
            'name' => 'Тест-власник', 'active' => 1, 'sort' => 0, 'created_at' => now(),
        ]);
        $this->ownerIds[] = $id;
        return $id;
    }

    // ──────────────────────────────────────────────────────────────── перевірки

    private function testChain(): void
    {
        $this->group('ланцюг: товар → магазин → власник → загальне');
        $mine = $this->owner(['name' => 'ФОП Перший', 'ep_group' => 2, 'vat' => 0,
                              'taxgrp' => 2, 'cashier' => 'ФОП Перший']);
        $hers = $this->owner(['name' => 'ФОП Друга', 'ep_group' => 3, 'vat' => 1,
                              'taxgrp' => 1, 'cashier' => 'ФОП Друга']);
        DB::update('stores', ['owner_id' => $mine], 'id = ?', [$this->storeA]);
        DB::update('stores', ['owner_id' => $hers], 'id = ?', [$this->storeB]);

        $this->ok('точки різних власників мають різні ставки',
            Fiscal::storeTaxGroup($this->storeA) === 2 && Fiscal::storeTaxGroup($this->storeB) === 1);
        $this->ok('підпис теж від власника', Fiscal::cashier('', $this->storeB) === 'ФОП Друга');
        $this->ok('імʼя продавця перемагає будь-які налаштування',
            Fiscal::cashier('Оксана', $this->storeB) === 'Оксана');

        // Точка може відрізнятись від власника — але це має бути свідомим кроком
        DB::update('stores', ['vchasno_taxgrp' => 4], 'id = ?', [$this->storeB]);
        $this->ok('своя ставка точки старша за власникову', Fiscal::storeTaxGroup($this->storeB) === 4);
        DB::update('stores', ['vchasno_taxgrp' => null], 'id = ?', [$this->storeB]);

        // Без власника все працює як раніше — оновлення нікого не ламає
        DB::update('stores', ['owner_id' => null], 'id = ?', [$this->storeB]);
        $this->ok('без власника береться загальна з налаштувань', Fiscal::storeTaxGroup($this->storeB) === 2);
        DB::update('stores', ['owner_id' => $hers], 'id = ?', [$this->storeB]);

        $this->ok('точки власника знаходяться', array_key_exists($this->storeA, Owners::stores($mine)));
        $this->ok('власник точки знаходиться',
            (int)(Owners::ofStore($this->storeA)['id'] ?? 0) === $mine);
    }

    private function testEpGroupIsNotTaxGroup(): void
    {
        $this->group('група ЄП — це не податкова група чека');
        // Найчастіша помилка: «я на 3-й групі» → ставить 3, а це ПДВ 20% + акциз
        $confused = ['name' => 'ФОП', 'ep_group' => 3, 'vat' => 0, 'taxgrp' => 3, 'tax_id' => '123'];
        $said = implode(' | ', Owners::problems($confused));
        $this->ok('плутанину помічено', $said !== '');
        $this->ok('і названо своїм імʼям', str_contains($said, 'третьою групою єдиного податку'));

        $right = ['name' => 'ФОП', 'ep_group' => 3, 'vat' => 0, 'taxgrp' => 2, 'tax_id' => '123'];
        $this->ok('правильне поєднання скарг не викликає', Owners::problems($right) === []);
    }

    private function testVatConsistency(): void
    {
        $this->group('ПДВ і група єдиного податку мають сходитись');
        $impossible = ['name' => 'ФОП', 'ep_group' => 2, 'vat' => 1, 'taxgrp' => 1, 'tax_id' => '123'];
        $this->ok('друга група платником ПДВ бути не може',
            (bool)array_filter(Owners::problems($impossible),
                fn($p) => str_contains($p, 'платником ПДВ бути не може')));

        $vatWithoutVat = ['name' => 'ФОП', 'ep_group' => 3, 'vat' => 1, 'taxgrp' => 2, 'tax_id' => '123'];
        $this->ok('платник ПДВ зі ставкою без ПДВ — теж розбіжність',
            (bool)array_filter(Owners::problems($vatWithoutVat),
                fn($p) => str_contains($p, 'ставка без ПДВ')));

        $noTaxId = ['name' => 'ФОП', 'ep_group' => 2, 'vat' => 0, 'taxgrp' => 2, 'tax_id' => ''];
        $this->ok('порожній ІПН помічено',
            (bool)array_filter(Owners::problems($noTaxId), fn($p) => str_contains($p, 'ІПН')));
    }

    private function testIncome(): void
    {
        $this->group('виторг рахується по власнику, а не по мережі');
        $mine = $this->owner(['name' => 'ФОП Виторг', 'ep_group' => 2, 'vat' => 0, 'taxgrp' => 2]);
        DB::update('stores', ['owner_id' => $mine], 'id = ?', [$this->storeA]);
        $before = Owners::income($mine);

        // Підзамовлення цієї точки — саме вони належать власнику
        $parent = DB::insert('orders', [
            'number' => 'OWN-' . bin2hex(random_bytes(3)), 'name' => 'Покупець', 'phone' => '',
            'delivery' => 'pickup', 'status' => 'new', 'subtotal' => 100, 'discount' => 0,
            'total' => 100, 'created_at' => now(),
        ]);
        $child = DB::insert('orders', [
            'number' => 'OWN-1/1', 'parent_id' => $parent, 'seq' => 1, 'store_id' => $this->storeA,
            'name' => 'Покупець', 'phone' => '', 'delivery' => 'pickup', 'status' => 'new',
            'subtotal' => 100, 'discount' => 0, 'total' => 100, 'created_at' => now(),
        ]);
        $this->ok('продаж потрапив у виторг власника',
            abs(Owners::income($mine) - ($before + 100)) < 0.005);

        DB::update('orders', ['status' => 'canceled'], 'id = ?', [$child]);
        $this->ok('скасоване з виторгу зникає', abs(Owners::income($mine) - $before) < 0.005);

        $this->ok('чужий власник цього не бачить', Owners::income($this->ownerIds[0]) !== null);

        DB::delete('orders', 'id IN (?,?)', [$parent, $child]);
    }
}

return (new OwnersTest())->run();
