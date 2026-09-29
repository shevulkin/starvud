<?php
/**
 * Гостьові замовлення знаходять свого господаря і за поштою, а не лише за номером.
 *
 * Половина покупців приходить не через месенджер: замовляє гостем, а потім
 * заводить кабінет кодом із листа. Доти claimOrders() шукав винятково за
 * номером — і така людина бачила порожню історію, хоча її замовлення лежали
 * поруч із її ж адресою в orders.email.
 *
 * Межі тут ті самі, що й у номера, і саме вони перевіряються нижче: чіпляємо
 * лише анонімні замовлення й лише за адресою, яку скринька щойно довела.
 *
 * Запуск: php bin/cli.php test
 */
declare(strict_types=1);

final class ClaimOrdersByEmailTest
{
    private int $pass = 0;
    private int $fail = 0;
    private array $users = [];
    private array $orders = [];

    private function ok(string $what, bool $cond): void
    {
        if ($cond) { $this->pass++; echo "  ✓ $what\n"; }
        else { $this->fail++; echo "  ✗ $what\n"; }
    }

    private function mkUser(string $email): int
    {
        $id = DB::insert('users', [
            'email' => $email, 'name' => 'Тест', 'role' => 'customer',
            'active' => 1, 'email_verified_at' => now(), 'created_at' => now(),
        ]);
        $this->users[] = $id;
        return $id;
    }

    private function mkOrder(?string $email, ?int $userId = null, string $phone = '+380670000777'): int
    {
        $id = DB::insert('orders', [
            'number' => 'TST-' . bin2hex(random_bytes(5)),
            'user_id' => $userId, 'name' => 'Гість', 'phone' => $phone, 'email' => $email,
            'delivery' => 'np', 'status' => 'new', 'total' => 100, 'created_at' => now(),
        ]);
        $this->orders[] = $id;
        return $id;
    }

    private function ownerOf(int $orderId): ?int
    {
        $v = DB::val('SELECT user_id FROM orders WHERE id = ?', [$orderId]);
        return $v === null ? null : (int)$v;
    }

    public function run(): int
    {
        $mail = 'claim-' . bin2hex(random_bytes(4)) . '@example.com';

        echo "== анонімне замовлення чіпляється за поштою ==\n";
        $guest = $this->mkOrder($mail);
        $uid = $this->mkUser($mail);
        $n = Customers::claimOrdersByEmail($uid, $mail);
        $this->ok('повернуто кількість', $n === 1);
        $this->ok('замовлення тепер належить акаунту', $this->ownerOf($guest) === $uid);

        echo "== чуже замовлення не перечіпляється ніколи ==\n";
        $other = $this->mkUser('other-' . bin2hex(random_bytes(4)) . '@example.com');
        $taken = $this->mkOrder($mail, $other);          // та сама пошта, але власник уже є
        Customers::claimOrdersByEmail($uid, $mail);
        $this->ok('власник лишився попереднім', $this->ownerOf($taken) === $other);

        echo "== чужа адреса не зачіпається ==\n";
        $alien = $this->mkOrder('alien-' . bin2hex(random_bytes(4)) . '@example.com');
        Customers::claimOrdersByEmail($uid, $mail);
        $this->ok('замовлення з іншою поштою лишилось анонімним', $this->ownerOf($alien) === null);

        echo "== регістр і пробіли не заважають ==\n";
        $upper = $this->mkOrder($mail);
        $n2 = Customers::claimOrdersByEmail($uid, '  ' . mb_strtoupper($mail) . ' ');
        $this->ok('адреса нормалізується так само, як на чекауті', $n2 === 1);
        $this->ok('і замовлення причепилось', $this->ownerOf($upper) === $uid);

        echo "== порожнє й технічне не чіпляють нічого ==\n";
        $noMail = $this->mkOrder(null);
        $this->ok('порожня адреса — нуль', Customers::claimOrdersByEmail($uid, '') === 0);
        $this->ok('технічна .local — нуль', Customers::claimOrdersByEmail($uid, 'abc@offline.local') === 0);
        $this->ok('замовлення без пошти лишилось анонімним', $this->ownerOf($noMail) === null);

        foreach ($this->orders as $id) DB::pdo()->prepare('DELETE FROM orders WHERE id = ?')->execute([$id]);
        foreach ($this->users as $id) DB::pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);

        echo $this->fail === 0
            ? "\nУСЕ ДОБРЕ: {$this->pass} перевірок\n"
            : "\nПРОВАЛЕНО: {$this->fail} із " . ($this->pass + $this->fail) . "\n";
        return $this->fail === 0 ? 0 : 1;
    }
}

return (new ClaimOrdersByEmailTest())->run();
