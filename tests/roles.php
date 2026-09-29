<?php
/**
 * Перевірка прав і режиму перегляду.  Запуск: php bin/cli.php test
 *
 * Головне, що доводить цей файл: режим перегляду не здатен видати жодного права.
 * За будь-якої підробки сесії чинні права лишаються підмножиною власних.
 * Якщо колись знадобиться це послабити — спершу поясніть, чому інваріант більше
 * не потрібен, бо на ньому тримається вся безпека перемикача ролей.
 *
 * Тест створює власних користувачів і прибирає їх за собою, тож не залежить
 * від сидів і нічого не псує в наявних даних.
 */
declare(strict_types=1);

use Controllers\Admin\Users;

final class RolesTest
{
    private int $pass = 0;
    private int $fail = 0;
    private array $userIds = [];
    private array $storeIds = [];
    private bool $madeStore = false;

    public function run(): int
    {
        $this->setUp();
        try {
            $this->testRoles();
            $this->testCaps();
            $this->testWhoMaySimulate();
            $this->testSimulationNarrows();
            $this->testForgedSessionGrantsNothing();
            $this->testInvariantExhaustively();
            $this->testStores();
            $this->testWorkStore();
            $this->testDefaultDeny();
            $this->testStoreAccessFollowsRole();
        } finally {
            $this->tearDown();
        }
        echo "\n" . ($this->fail === 0
            ? "УСЕ ДОБРЕ: {$this->pass} перевірок\n"
            : "ПРОВАЛЕНО: {$this->fail} з " . ($this->pass + $this->fail) . "\n");
        return $this->fail === 0 ? 0 : 1;
    }

    // ── Дані ────────────────────────────────────────────────────────────────

    private function setUp(): void
    {
        $this->storeIds = array_map(fn($r) => (int)$r['id'], DB::all('SELECT id FROM stores ORDER BY id'));
        while (count($this->storeIds) < 2) {
            $this->storeIds[] = DB::insert('stores', ['name' => 'Тестова точка', 'slug' => 'test-roles-' . bin2hex(random_bytes(3)), 'active' => 0]);
            $this->madeStore = true;
        }
        foreach ([Roles::ADMIN, Roles::SELLER, Roles::CUSTOMER] as $role) {
            $id = DB::insert('users', [
                'email' => "roles-test-$role@bofu.test", 'name' => "Тест $role",
                'active' => 1, 'created_at' => now(),
            ]);
            $this->userIds[$role] = $id;
            if ($role !== Roles::CUSTOMER) {
                DB::insert('user_roles', ['user_id' => $id, 'role' => $role, 'created_at' => now()]);
            }
        }
        // продавця призначаємо на першу точку, другу лишаємо чужою
        DB::insert('seller_stores', ['user_id' => $this->userIds[Roles::SELLER], 'store_id' => $this->storeIds[0]]);
    }

    private function tearDown(): void
    {
        foreach ($this->userIds as $id) {
            DB::delete('user_roles', 'user_id = ?', [$id]);
            DB::delete('seller_stores', 'user_id = ?', [$id]);
            DB::delete('users', 'id = ?', [$id]);
        }
        if ($this->madeStore) {
            foreach ($this->storeIds as $sid) {
                DB::delete('stores', 'id = ? AND name = ?', [$sid, 'Тестова точка']);
            }
        }
        $_SESSION = [];
        Auth::forgetRoles();
    }

    // ── Інструменти ─────────────────────────────────────────────────────────

    private function ok(string $what, bool $cond): void
    {
        if ($cond) { $this->pass++; echo "  ok   $what\n"; }
        else { $this->fail++; echo "  FAIL $what\n"; }
    }

    private function group(string $name): void { echo "\n== $name ==\n"; }

    /** Підміняємо сесію: $actAs може бути будь-чим, зокрема сміттям */
    private function as(string $role, ?string $actAs = null, ?int $actStore = null): void
    {
        $_SESSION = ['user_id' => $this->userIds[$role]];
        if ($actAs !== null) $_SESSION['act_as'] = $actAs;
        if ($actStore !== null) $_SESSION['act_store'] = $actStore;
        Auth::forgetRoles();
    }

    // ── Перевірки ───────────────────────────────────────────────────────────

    private function testRoles(): void
    {
        $this->group('ролі з БД');
        $this->as(Roles::ADMIN);
        $this->ok('адмін має роль admin', in_array(Roles::ADMIN, Auth::roles(), true));
        $this->ok('адмін є й покупцем', in_array(Roles::CUSTOMER, Auth::roles(), true));
        $this->as(Roles::SELLER);
        $this->ok('продавець має рівно роль seller', Auth::staffRoles() === [Roles::SELLER]);
        $this->as(Roles::CUSTOMER);
        $this->ok('покупець не має стафних ролей', Auth::staffRoles() === []);
    }

    private function testCaps(): void
    {
        $this->group('права');
        $this->as(Roles::ADMIN);
        $this->ok('адмін може все', Auth::can('users.manage') && Auth::can('settings.manage') && Auth::isAdmin());
        $this->as(Roles::SELLER);
        $this->ok('продавець править статус замовлення', Auth::can('orders.status'));
        $this->ok('продавець править залишки', Auth::can('products.stock'));
        $this->ok('продавець НЕ керує користувачами', !Auth::can('users.manage'));
        $this->ok('продавець НЕ створює товари', !Auth::can('products.manage'));
        $this->ok('продавець НЕ бачить усі магазини', !Auth::can('stores.all'));
        $this->ok('продавець не є адміном', !Auth::isAdmin());
        $this->ok('продавець бачить замовлення мережі', Auth::can('orders.view_all'));
        $this->ok('продавець бере замовлення в роботу', Auth::can('orders.assign'));
        $this->ok('продавець пише нотатки', Auth::can('orders.note'));
        $this->as(Roles::CUSTOMER);
        $this->ok('покупець не має прав', Auth::caps() === []);
        $this->ok('покупець не стаф', !Auth::isStaff());
    }

    private function testWhoMaySimulate(): void
    {
        $this->group('кому що можна вдавати');
        $this->as(Roles::ADMIN);
        $this->ok('адмін вдає продавця', Auth::canSimulate(Roles::SELLER));
        $this->ok('адмін вдає покупця', Auth::canSimulate(Roles::CUSTOMER));
        $this->as(Roles::SELLER);
        $this->ok('продавець вдає покупця', Auth::canSimulate(Roles::CUSTOMER));
        $this->ok('продавець НЕ вдає адміна', !Auth::canSimulate(Roles::ADMIN));
        $this->as(Roles::CUSTOMER);
        $this->ok('покупцю нема кого вдавати', Auth::simulatableRoles() === []);

        // у перемикачі показуємо лише ролі, що справді звужують права.
        // Розподільник тут зʼявився разом із самою роллю — і має зʼявлятись:
        // перевірити, що саме він бачить, інакше можна лише завівши собі
        // окремий акаунт.
        $this->as(Roles::ADMIN);
        $this->ok('адміну пропонують усі вужчі ролі',
            Auth::simulatableRoles() === [Roles::SELLER, Roles::DISPATCHER, Roles::CUSTOMER]);
        $this->ok('адміну не пропонують вдавати адміна',
            !in_array(Roles::ADMIN, Auth::simulatableRoles(), true));
        $this->as(Roles::SELLER);
        $this->ok('продавцю пропонують лише покупця',
            Auth::simulatableRoles() === [Roles::CUSTOMER]);
    }

    private function testSimulationNarrows(): void
    {
        $this->group('режим перегляду звужує');
        $this->as(Roles::ADMIN, Roles::SELLER);
        $this->ok('адмін як продавець втрачає users.manage', !Auth::can('users.manage'));
        $this->ok('адмін як продавець лишає orders.status', Auth::can('orders.status'));
        $this->ok('адмін як продавець більше не isAdmin', !Auth::isAdmin());
        $this->as(Roles::ADMIN, Roles::CUSTOMER);
        $this->ok('адмін як покупець не має прав', Auth::caps() === []);
        $this->ok('адмін як покупець не стаф', !Auth::isStaff());
    }

    private function testForgedSessionGrantsNothing(): void
    {
        $this->group('підробка сесії не підвищує прав');
        $this->as(Roles::SELLER, Roles::ADMIN);
        $this->ok('продавець з act_as=admin НЕ дістав users.manage', !Auth::can('users.manage'));
        $this->ok('продавець з act_as=admin НЕ став адміном', !Auth::isAdmin());
        $this->ok('продавець з act_as=admin лишився у своїх межах', Auth::caps() === Roles::caps(Roles::SELLER));
        $this->as(Roles::CUSTOMER, Roles::ADMIN);
        $this->ok('покупець з act_as=admin не дістав нічого', Auth::caps() === []);
    }

    private function testInvariantExhaustively(): void
    {
        $this->group('вичерпна перевірка інваріанта');
        $forged = array_merge(Roles::all(), ['неіснуюча', '', 'admin ', '*']);
        $violations = [];
        foreach ([Roles::ADMIN, Roles::SELLER, Roles::CUSTOMER] as $who) {
            foreach ($forged as $f) {
                $this->as($who, $f === '' ? null : $f);
                $own = Auth::ownCaps();
                foreach (Auth::caps() as $c) {
                    if (!Roles::allows($own, $c)) $violations[] = "$who / act_as='$f' / $c";
                }
            }
        }
        $this->ok('жодна комбінація не виходить за межі власних прав', $violations === []);
        foreach ($violations as $v) echo "       $v\n";
    }

    private function testStores(): void
    {
        [$mine, $foreign] = $this->storeIds;
        $this->group('магазини');
        $this->as(Roles::SELLER);
        $this->ok('продавець бачить призначену точку', Auth::storeIds() === [$mine]);
        $this->as(Roles::SELLER, Roles::SELLER, $foreign);
        $this->ok('продавець НЕ зазирне в чужу точку через act_store', Auth::storeIds() === []);
        $this->as(Roles::SELLER, Roles::SELLER, $mine);
        $this->ok('продавець бачить свою точку в режимі перегляду', Auth::storeIds() === [$mine]);
        $this->as(Roles::ADMIN);
        $this->ok('адмін бачить усі точки', count(Auth::storeIds()) === count($this->storeIds));
        $this->as(Roles::ADMIN, Roles::SELLER, $foreign);
        $this->ok('адмін як продавець точки бачить лише її', Auth::storeIds() === [$foreign]);

        $this->group('simulate() відкидає недозволене');
        $this->as(Roles::SELLER);
        $this->ok('продавцю відмовлено в чужій точці', Auth::simulate(Roles::SELLER, $foreign) === false);
        $this->as(Roles::SELLER);
        $this->ok('продавцю дозволено власну точку', Auth::simulate(Roles::SELLER, $mine) === true);
        $this->as(Roles::SELLER);
        $this->ok('продавцю відмовлено вдавати адміна', Auth::simulate(Roles::ADMIN) === false);
    }

    /**
     * Маршрут чи дія без оголошеного права доступні лише тим, хто має всі права.
     * Саме на цьому тримається те, що забутий гейт закриває доступ, а не відкриває.
     */
    private function testDefaultDeny(): void
    {
        $this->group('замовчувана відмова');
        $this->as(Roles::ADMIN);
        $this->ok('адмін проходить гейт без оголошеного права', Auth::can('*'));
        $this->as(Roles::SELLER);
        $this->ok('продавець НЕ проходить такий гейт', !Auth::can('*'));
        $this->as(Roles::ADMIN, Roles::SELLER);
        $this->ok('адмін у ролі продавця теж не проходить', !Auth::can('*'));
        $this->as(Roles::SELLER);
        $this->ok('невідоме право нікому не дається', !Auth::can('щось.вигадане'));

        // межа продавця, закріплена поіменно: якщо колись розширять — тест впаде й спитає чому
        $forbidden = ['products.manage', 'catalog.manage', 'stores.manage', 'stores.all',
            'users.manage', 'settings.manage', 'content.manage', 'media.manage', 'promos.manage',
            'diplomas.manage', 'subscribers.manage', 'notifications.manage', 'orders.manage'];
        $leaks = [];
        $this->as(Roles::SELLER);
        foreach ($forbidden as $cap) if (Auth::can($cap)) $leaks[] = $cap;
        $this->ok('продавцю не належить жодне адмінське право', $leaks === []);
        foreach ($leaks as $l) echo "       протікає: $l\n";

        $this->as(Roles::ADMIN);
        $missing = [];
        foreach (array_keys(Roles::CAPS) as $cap) if (!Auth::can($cap)) $missing[] = $cap;
        $this->ok('адміну належать усі права з реєстру', $missing === []);
        foreach ($missing as $m) echo "       бракує: $m\n";
    }

    /** Робоча точка звужує кабінет, але не роздає доступу */
    private function testWorkStore(): void
    {
        [$mine, $foreign] = $this->storeIds;
        $seller = $this->userIds[Roles::SELLER];
        DB::insert('seller_stores', ['user_id' => $seller, 'store_id' => $foreign]);

        $this->group('робоча точка');
        $this->as(Roles::SELLER);
        $this->ok('без вибору працює по всіх своїх', count(Auth::storeIds()) === 2);
        $this->ok('вибір власної точки приймається', Auth::setWorkStore($mine) === true);
        $this->ok('кабінет звузився до неї', Auth::storeIds() === [$mine]);
        $this->ok('скидання повертає всі точки', Auth::setWorkStore(null) === true && count(Auth::storeIds()) === 2);

        DB::delete('seller_stores', 'user_id = ? AND store_id = ?', [$seller, $foreign]);
        Auth::forgetRoles();
        $this->as(Roles::SELLER);
        $this->ok('чужу точку як робочу не взяти', Auth::setWorkStore($foreign) === false);
        $_SESSION['act_store'] = $foreign;   // підробка в обхід сеттера
        $this->ok('підроблена робоча точка нічого не відкриває', Auth::storeIds() === []);

        $this->as(Roles::ADMIN);
        $_SESSION['act_store'] = $foreign;
        $this->ok('адміну робоча точка не звужує мережу', count(Auth::storeIds()) === count($this->storeIds));
    }

    /**
     * Прив'язка до точки живе рівно стільки, скільки роль продавця.
     * Головне тут — третя перевірка: повернення ролі саме по собі доступу не віддає.
     */
    private function testStoreAccessFollowsRole(): void
    {
        [$mine, $other] = $this->storeIds;
        $seller = $this->userIds[Roles::SELLER];
        $admin  = $this->userIds[Roles::ADMIN];
        $assign = fn(int $uid, array $ids, array $roles) => Users::saveStores($uid, $ids, $roles);
        // порядок рядків у БД без ORDER BY не гарантований — звіряємо множини
        $got = function (int $uid): array { $s = Auth::assignedStoreIds($uid); sort($s); return $s; };
        $want = [$mine, $other]; sort($want);

        $this->group('доступ до точок іде за роллю');

        $assign($seller, [$mine, $other], [Roles::SELLER]);
        $this->ok('продавцю точки призначаються', $got($seller) === $want);

        $assign($seller, [$mine, $other], []);   // роль зняли — точки прийшли ті самі
        $this->ok('зняття ролі прибирає з усіх точок', $got($seller) === []);

        $assign($seller, [], [Roles::SELLER]);   // роль повернули, точок не обрали
        $this->ok('повернення ролі доступу не відновлює', $got($seller) === []);

        $assign($seller, [$mine], [Roles::SELLER]);
        $this->ok('після повернення точку призначають наново', $got($seller) === [$mine]);

        $assign($admin, [$mine], [Roles::ADMIN]);
        $this->ok('без ролі продавця точку не прив\'язати', $got($admin) === []);

        // повертаємо стан, який заклав setUp — на випадок нових перевірок після цієї
        $assign($seller, [$mine], [Roles::SELLER]);
        Auth::forgetRoles();
    }
}

return (new RolesTest())->run();
