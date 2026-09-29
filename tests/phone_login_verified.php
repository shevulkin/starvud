<?php
/**
 * Вхід за номером пускає лише туди, де номер підтверджений.
 *
 * Номер у профілі вписують руками, і вписати можна чужий. Підтверджує номер
 * тільки Telegram — він засвідчує contact.user_id проти from.id. Доки пошук
 * акаунта цього не питав, вписаний чужий номер перехоплював вхід справжнього
 * власника: той вводив свій номер, а код летів у месенджер того, хто номер
 * вписав. Власник до свого номера не діставався, а перехоплювач ще й дізнавався
 * про кожну спробу.
 *
 * Запуск: php bin/cli.php test
 */
declare(strict_types=1);

final class PhoneLoginVerifiedTest
{
    private int $pass = 0;
    private int $fail = 0;
    private array $ids = [];

    private function ok(string $what, bool $cond): void
    {
        if ($cond) { $this->pass++; echo "  ✓ $what\n"; }
        else { $this->fail++; echo "  ✗ $what\n"; }
    }

    private function mkUser(array $over): int
    {
        $id = DB::insert('users', array_merge([
            'email' => 'plv-' . bin2hex(random_bytes(5)) . '@example.com',
            'name' => 'Тест', 'role' => 'customer', 'active' => 1,
            'created_at' => now(),
        ], $over));
        $this->ids[] = $id;
        return $id;
    }

    /** Те саме, що роблять обидва кроки AuthController — і надсилання, і перевірка */
    private function lookup(string $phone): ?array
    {
        return DB::row('SELECT * FROM users WHERE phone = ? AND active = 1
                        AND phone_verified_at IS NOT NULL', [$phone]);
    }

    public function run(): int
    {
        $phone = '+380631112233';

        echo "== вписаний руками номер входу не дає ==\n";
        $impostor = $this->mkUser([
            'phone' => $phone,                    // чужий номер, вписаний у профіль
            'phone_verified_at' => null,
            'tg_chat_id' => '111222333',          // свій месенджер підключений
        ]);
        $this->ok('акаунт із непідтвердженим номером не знаходиться', $this->lookup($phone) === null);
        $u = DB::row('SELECT * FROM users WHERE id = ?', [$impostor]);
        [$ready, $why] = LoginMethods::readiness($u, 'phone');
        $this->ok('спосіб «код на телефон» позначений як неготовий', $ready === false);
        $this->ok('причина названа саме про підтвердження', str_contains($why, 'не підтверджений'));
        $this->ok('і сам вхід заборонений', LoginMethods::permits($u, 'phone') === false);

        echo "== підтверджений номер працює як раніше ==\n";
        DB::update('users', ['phone_verified_at' => now()], 'id = ?', [$impostor]);
        $found = $this->lookup($phone);
        $this->ok('акаунт знаходиться', $found !== null && (int)$found['id'] === $impostor);
        $u = DB::row('SELECT * FROM users WHERE id = ?', [$impostor]);
        [$ready, $why] = LoginMethods::readiness($u, 'phone');
        // Готовність залежить ще й від того, чи налаштований бот на самому
        // сайті, а це не предмет цього тесту: на машині без токена канал
        // відсутній у будь-якому разі. Перевіряємо своє: підтвердження більше
        // не є причиною відмови.
        $this->ok('підтвердження більше не заважає',
            $ready === true || !str_contains($why, 'не підтверджений'));

        echo "== підтвердження без месенджера коду не дає ==\n";
        DB::update('users', ['tg_chat_id' => null, 'viber_id' => null], 'id = ?', [$impostor]);
        $u = DB::row('SELECT * FROM users WHERE id = ?', [$impostor]);
        [$ready, $why] = LoginMethods::readiness($u, 'phone');
        $this->ok('без месенджера спосіб неготовий', $ready === false);
        $this->ok('причина інша — нікуди слати', str_contains($why, 'Немає куди'));

        echo "== підтвердження злетіло, поки код летів ==\n";
        // Між «Отримати код» і введенням коду минає час, і за цей час номер
        // могли змінити — Profile і Admin\Users скидають при цьому позначку.
        // Крок перевірки мусить питати про неї так само, як крок надсилання,
        // інакше вже виписаний код впустить у акаунт із чужим тепер номером.
        DB::update('users', ['phone_verified_at' => now(), 'tg_chat_id' => '111222333'], 'id = ?', [$impostor]);
        $this->ok('до зміни акаунт знаходиться', $this->lookup($phone) !== null);
        DB::update('users', ['phone_verified_at' => null], 'id = ?', [$impostor]);
        $this->ok('після скидання підтвердження — вже ні', $this->lookup($phone) === null);

        echo "== вимкнений акаунт не знаходиться навіть із підтвердженим номером ==\n";
        DB::update('users', ['active' => 0, 'phone_verified_at' => now()], 'id = ?', [$impostor]);
        $this->ok('вимкнений не знаходиться', $this->lookup($phone) === null);

        foreach ($this->ids as $id) DB::pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);

        echo $this->fail === 0
            ? "\nУСЕ ДОБРЕ: {$this->pass} перевірок\n"
            : "\nПРОВАЛЕНО: {$this->fail} із " . ($this->pass + $this->fail) . "\n";
        return $this->fail === 0 ? 0 : 1;
    }
}

return (new PhoneLoginVerifiedTest())->run();
