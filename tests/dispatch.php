<?php
/**
 * Черга розподілу онлайн-замовлень.
 *
 * Головне, що тут стережеться, — межі черги. У неї має потрапляти рівно те, де
 * розподіл має сенс: замовлення з сайту, які треба везти. Самовивіз розподіляти
 * нема чого (магазин обрав покупець), курс і поготів (його нікуди не везуть), а
 * продаж із каси приходить уже з відомою точкою.
 *
 * Друге — що автоматичний вибір магазину лишається. Спокуса «хай вирішує
 * розподільник» коштувала б резерву складу: списання відбувається при
 * оформленні, і замовлення без магазину нема з чого списувати.
 *
 * Запуск: php bin/cli.php test
 */
declare(strict_types=1);

final class DispatchTest
{
    private int $pass = 0;
    private int $fail = 0;
    private array $orders = [];

    private function ok(string $what, bool $cond): void
    {
        if ($cond) { $this->pass++; echo "  ✓ $what\n"; }
        else { $this->fail++; echo "  ✗ $what\n"; }
    }

    private function group(string $n): void { echo "\n== $n ==\n"; }

    /** Головне замовлення в тому вигляді, у якому його бачить черга */
    private function order(array $over = []): array
    {
        $id = DB::insert('orders', array_merge([
            'number' => 'DSP-' . bin2hex(random_bytes(4)),
            'name' => 'Тест', 'phone' => '+380670000000',
            'delivery' => 'np', 'source' => 'site', 'status' => 'new',
            'total' => 100, 'created_at' => now(),
        ], $over));
        $this->orders[] = $id;
        return DB::row('SELECT * FROM orders WHERE id = ?', [$id]);
    }

    private function inQueue(int $id): bool
    {
        return in_array($id, array_map(fn($o) => (int)$o['id'], Dispatch::queue()), true);
    }

    public function run(): int
    {
        $this->group('що потрапляє в чергу');
        $np = $this->order();
        $this->ok('замовлення з сайту з доставкою — так', $this->inQueue((int)$np['id']));
        $this->ok('і needsRouting погоджується', Dispatch::needsRouting($np));

        $pickup = $this->order(['delivery' => 'pickup']);
        $this->ok('самовивіз — ні (магазин обрав покупець)', !$this->inQueue((int)$pickup['id']));

        $digital = $this->order(['delivery' => 'digital']);
        $this->ok('курс — ні (везти нічого)', !$this->inQueue((int)$digital['id']));

        $pos = $this->order(['source' => 'offline']);
        $this->ok('продаж із каси — ні (точка відома одразу)', !$this->inQueue((int)$pos['id']));

        $child = $this->order(['parent_id' => (int)$np['id'], 'seq' => 1]);
        $this->ok('частина замовлення — ні (розподіляють ціле)', !$this->inQueue((int)$child['id']));

        $canceled = $this->order(['status' => 'canceled']);
        $this->ok('скасоване — ні', !$this->inQueue((int)$canceled['id']));

        $this->group('підтвердження');
        $before = Dispatch::todoCount();
        $this->ok('лічильник бачить чергу', $before > 0);
        $this->ok('підтвердження спрацювало', Dispatch::confirm((int)$np['id'], null));
        $this->ok('замовлення пішло з черги', !$this->inQueue((int)$np['id']));
        $this->ok('лічильник зменшився', Dispatch::todoCount() === $before - 1);
        // Підтверджує той, хто перший подивився: друге натискання нічого не
        // переписує, інакше в журналі опинилось би останнє імʼя замість того,
        // хто справді ухвалив рішення
        $this->ok('повторне підтвердження нічого не робить', !Dispatch::confirm((int)$np['id'], null));
        $fresh = DB::row('SELECT * FROM orders WHERE id = ?', [(int)$np['id']]);
        $this->ok('позначку збережено', !empty($fresh['routed_at']));
        $this->ok('needsRouting більше не спрацьовує', !Dispatch::needsRouting($fresh));

        $this->group('право на цю роботу');
        // Роль зʼявилась не заради нового права: orders.manage існувало від
        // початку, але його мав лише адміністратор
        $this->ok('розподільник може передавати між магазинами',
            in_array('orders.manage', Roles::caps(Roles::DISPATCHER), true));
        $this->ok('і бачить усю мережу',
            in_array('orders.view_all', Roles::caps(Roles::DISPATCHER), true));
        $this->ok('але не пробиває чеки',
            !in_array('orders.fiscal', Roles::caps(Roles::DISPATCHER), true));
        $this->ok('і не створює накладних',
            !in_array('orders.ship', Roles::caps(Roles::DISPATCHER), true));
        $this->ok('продавець розподіляти не може',
            !in_array('orders.manage', Roles::caps(Roles::SELLER), true));
        $this->ok('роль можна призначити в адмінці',
            in_array(Roles::DISPATCHER, Roles::assignable(), true));

        foreach (array_reverse($this->orders) as $id) {
            DB::query('DELETE FROM order_events WHERE parent_id = ? OR order_id = ?', [$id, $id]);
            DB::query('DELETE FROM orders WHERE id = ?', [$id]);
        }

        echo $this->fail === 0
            ? "\nУСЕ ДОБРЕ: {$this->pass} перевірок\n"
            : "\nПРОВАЛЕНО: {$this->fail} із " . ($this->pass + $this->fail) . "\n";
        return $this->fail === 0 ? 0 : 1;
    }
}

return (new DispatchTest())->run();
