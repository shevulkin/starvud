<?php
/**
 * Курс — товар, але з трьома відмінностями, і кожна тут перевіряється.
 *
 *  1. Його не буває «мало»: складу немає, тож ані кошик, ані оформлення не
 *     мають рахувати залишок. Інакше курс, у якого нуль на складі (а він там
 *     завжди нуль), не продався б жодного разу.
 *  2. Його нікуди не везти: замовлення з самих курсів отримує спосіб
 *     'digital', і адреси в нього немає.
 *  3. Змішаний кошик цифровим НЕ стає: поруч із курсом лежить банка меду, і
 *     її все одно треба везти.
 *
 * Запуск: php bin/cli.php test
 */
declare(strict_types=1);

final class CoursesTest
{
    private int $pass = 0;
    private int $fail = 0;
    private array $products = [];
    private array $cats = [];
    private array $users = [];

    private function ok(string $what, bool $cond): void
    {
        if ($cond) { $this->pass++; echo "  ✓ $what\n"; }
        else { $this->fail++; echo "  ✗ $what\n"; }
    }

    private function group(string $n): void { echo "\n== $n ==\n"; }

    private function cat(string $type): int
    {
        $id = DB::insert('categories', ['name' => 'Тест ' . $type, 'type' => $type,
            'slug' => 'test-' . $type . '-' . bin2hex(random_bytes(4)), 'active' => 1]);
        $this->cats[] = $id;
        return $id;
    }

    /** Товар без жодного залишку й без «під замовлення» — найсуворіший випадок */
    private function product(string $type): array
    {
        $id = DB::insert('products', [
            'category_id' => $this->cat($type), 'name' => 'Тест ' . $type,
            'slug' => 'test-p-' . bin2hex(random_bytes(5)),
            'base_price' => 1000, 'type' => $type, 'active' => 1,
            'made_to_order' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->products[] = $id;
        return DB::row('SELECT * FROM products WHERE id = ?', [$id]);
    }

    /** Рядок кошика в тому вигляді, у якому його бачить Cart::detailed() */
    private function row(array $product, int $qty = 1): array
    {
        return ['product' => $product, 'variant' => null, 'qty' => $qty];
    }

    public function run(): int
    {
        $course = $this->product(Courses::TYPE);
        $honey  = $this->product('product');

        $this->group('перелік типів — один на всю систему');
        /*
         * Типи були переписані в шести місцях: двох перевірках при збереженні
         * товару, перевірці категорії, двох підписах у шаблонах і сідері. Саме
         * тому «Послуга» й «Відео» й дожили до сьогодні — прибрати їх означало
         * знайти всі шість. Тепер перелік один.
         */
        $this->ok('типів рівно два', array_keys(Catalog::TYPES) === ['product', 'course']);
        $this->ok('курс серед них', isset(Catalog::TYPES[Courses::TYPE]));
        // Прибрані типи не мають повертатись підміною в POST
        $this->ok('«відео» більше не приймається', Catalog::normType('video') === 'product');
        $this->ok('«послуга» теж', Catalog::normType('service') === 'product');
        $this->ok('сміття стає звичайним товаром', Catalog::normType('щось') === 'product');
        // При редагуванні невідоме значення не має скидати наявний тип курсу
        $this->ok('невідоме не збиває наявний тип',
            Catalog::normType('video', Courses::TYPE) === Courses::TYPE);
        $this->ok('відомий тип проходить як є', Catalog::normType('course') === 'course');

        $this->group('що вважається курсом');
        $this->ok('товар із type=course — курс', Courses::isCourse($course));
        $this->ok('звичайний товар — ні', !Courses::isCourse($honey));
        $this->ok('порожнеча не ламає перевірку', !Courses::isCourse(null));

        $this->group('курсу не буває «мало»');
        // Обидва без залишків і без «під замовлення» — різниця лише в типі
        $this->ok('кошик не обмежує курс',
            Cart::limit((int)$course['id'], null) === null);
        $this->ok('а звичайний товар без залишку — обмежує нулем',
            Cart::limit((int)$honey['id'], null) === 0);
        $this->ok('оформлення не рахує курс браком',
            OrderFlow::unavailable([$this->row($course)]) === []);
        $this->ok('а звичайний товар — рахує',
            count(OrderFlow::unavailable([$this->row($honey)])) === 1);

        $this->group('доставка цифрового замовлення');
        $this->ok('самі курси — цифрове', Courses::cartIsDigital([$this->row($course)]));
        $this->ok('курс і мед разом — ні (мед треба везти)',
            !Courses::cartIsDigital([$this->row($course), $this->row($honey)]));
        $this->ok('самий лише мед — ні', !Courses::cartIsDigital([$this->row($honey)]));
        // Порожній кошик цифровим не рахуємо: інакше чекаут сховав би доставку
        // ще до того, як покупець щось поклав
        $this->ok('порожній кошик — не цифровий', !Courses::cartIsDigital([]));

        $this->ok('спосіб «digital» відомий системі',
            isset(OrderFlow::DELIVERY['digital']));
        $this->ok('адреси в цифрового замовлення немає',
            OrderFlow::deliveryAddress(['delivery' => 'digital', 'city' => 'Київ']) === '');

        $this->group('курс не потрапляє в каталог');
        // У курсу своя сторінка, і в каталозі йому нема чого робити: ціна там
        // оцінюється поруч із банками меду, а фільтри («в наявності», вага,
        // бренд) до нього не застосовні взагалі
        $ids = fn(array $rows) => array_map(fn($r) => (int)$r['id'], $rows);
        $found = $ids(Catalog::search([]));
        $this->ok('пошуком по каталогу курс не знаходиться',
            !in_array((int)$course['id'], $found, true));
        $this->ok('а звичайний товар — знаходиться',
            in_array((int)$honey['id'], $found, true));
        // Пошук по назві теж не має віддавати курс: людина шукає мед
        $this->ok('і за назвою не спливає',
            !in_array((int)$course['id'], $ids(Catalog::search(['q' => 'Тест'])), true));
        /*
         * Два списки розділів, і плутати їх дорого в обидва боки.
         *
         * Вітрина не має показувати «Курси» розділом каталогу — туди вже веде
         * окремий пункт меню. Адмінка ж мусить бачити ВСІ розділи: інакше
         * курсу нема що призначити, і зберегти його неможливо взагалі. Саме
         * так і сталося, коли виключення, зроблене заради вітрини, потрапило
         * в спільний метод.
         */
        $courseCatId = (int)DB::val('SELECT category_id FROM products WHERE id = ?', [(int)$course['id']]);
        $inList = fn(array $rows) => in_array($courseCatId, array_map(fn($c) => (int)$c['id'], $rows), true);

        $this->ok('в адмінському списку курсова категорія Є', $inList(Catalog::categories()));
        $this->ok('у вітринному — немає', !$inList(Catalog::shopCategories()));
        $this->ok('на головній (кореневі розділи) — немає', !$inList(Catalog::rootCategories()));
        // Меню в шапці будується деревом без аргументу — саме там курс і виліз
        $treeIds = [];
        foreach (Catalog::categoryTree() as $root) {
            $treeIds[] = (int)$root['id'];
            foreach ($root['children'] ?? [] as $kid) $treeIds[] = (int)$kid['id'];
        }
        $this->ok('у меню «Магазин» — немає', !in_array($courseCatId, $treeIds, true));

        $this->group('доступ після оплати');
        $uid = DB::insert('users', ['email' => 'stud-' . bin2hex(random_bytes(4)) . '@example.com',
            'name' => 'Студент', 'role' => 'customer', 'active' => 1, 'created_at' => now()]);
        $this->users[] = $uid;
        $cid = (int)$course['id'];

        $this->ok('до покупки курс закритий', !Courses::isOpen($uid, $cid));

        Courses::grant($uid, $cid, null, null);            // безстроково
        $this->ok('після видачі — відкритий', Courses::isOpen($uid, $cid));
        $this->ok('зʼявився в «Моїх курсах»', count(Courses::forUser($uid)) === 1);

        // Повторна покупка не має плодити другий рядок: інакше кабінет показав
        // би той самий курс двічі, а «до якої дати» стало б питанням із двома
        // відповідями
        Courses::grant($uid, $cid, null, 30);
        $this->ok('друга видача не плодить рядків', count(Courses::forUser($uid)) === 1);
        $this->ok('і не робить безстроковий доступ строковим',
            Courses::forUser($uid)[0]['expires_at'] === null);

        // Строковий доступ перевіряємо на окремому курсі, щоб не чіпати той,
        // що вище відкритий назавжди
        $timed = $this->product(Courses::TYPE);
        $tid = (int)$timed['id'];
        Courses::grant($uid, $tid, null, 10);
        $only = fn(int $pid) => array_values(array_filter(Courses::forUser($uid),
            fn($r) => (int)$r['product']['id'] === $pid));
        $this->ok('строковий доступ має дату кінця', $only($tid)[0]['expires_at'] !== null);
        $this->ok('і поки не протух', !$only($tid)[0]['expired']);

        // Протухлий доступ лишається видимим — із позначкою, а не зникає:
        // «купив, а воно пропало» читається як обман, а не як кінець строку
        DB::query('UPDATE course_access SET expires_at = ? WHERE user_id = ? AND product_id = ?',
            [date('Y-m-d H:i:s', time() - 86400), $uid, $tid]);
        $this->ok('протухлий курс закритий', !Courses::isOpen($uid, $tid));
        $this->ok('але з кабінету не зникає',
            count($only($tid)) === 1 && $only($tid)[0]['expired']);

        // Продовження рахується від пізнішої дати, а не від «сьогодні»: інакше
        // друга покупка, зроблена завчасно, вкорочувала б доступ
        DB::query('UPDATE course_access SET expires_at = ? WHERE user_id = ? AND product_id = ?',
            [date('Y-m-d H:i:s', time() + 5 * 86400), $uid, $tid]);
        Courses::grant($uid, $tid, null, 10);
        $left = (strtotime((string)DB::val(
            'SELECT expires_at FROM course_access WHERE user_id = ? AND product_id = ?',
            [$uid, $tid])) - time()) / 86400;
        $this->ok('продовження додається до залишку, а не заміняє його', $left > 14);

        $this->group('що бачить той, хто вже купив');
        // «До кошика» на курсі, за який уже заплачено, — найгірше, що може
        // показати сторінка: пропонує купити вдруге те, що вже твоє. Тому
        // «куплений» — питання окреме від «відкритий зараз»: протухлий курс
        // купують ще раз свідомо, і напис на кнопці там інший.
        $this->ok('гість власником не рахується', !Courses::owned(null, $cid));
        $this->ok('чужий курс не вважається купленим', !Courses::owned($uid, (int)$honey['id']));
        Courses::grant($uid, $cid, null, null);
        $this->ok('після покупки — власник', Courses::owned($uid, $cid));
        DB::query('UPDATE course_access SET expires_at = ? WHERE user_id = ? AND product_id = ?',
            [date('Y-m-d H:i:s', time() - 86400), $uid, $cid]);
        $this->ok('протухлий лишається купленим', Courses::owned($uid, $cid));
        $this->ok('але вже не відкритим', !Courses::isOpen($uid, $cid));
        DB::query('DELETE FROM course_access WHERE user_id = ?', [$uid]);

        $this->group('сертифікати');
        $dn = 'TST-' . strtoupper(bin2hex(random_bytes(3)));
        $did = DB::insert('diplomas', ['number' => $dn, 'student' => 'Студент',
            'course' => 'Курс на бланку', 'user_id' => $uid, 'product_id' => $cid, 'active' => 1]);
        $this->ok('диплом видно у випускника', count(Diplomas::forUser($uid)) === 1);
        // Назва з бланка головніша за назву товару: курс у каталозі
        // перейменують, а на виданому дипломі має лишитись надрукований текст
        $this->ok('назва береться з бланка',
            Diplomas::courseLabel(Diplomas::forUser($uid)[0]) === 'Курс на бланку');
        $this->ok('без тексту підставляється назва курсу',
            Diplomas::courseLabel(['course' => '', 'product_id' => $cid]) === $course['name']);
        // Анульований диплом — не досягнення, і в кабінеті йому не місце
        DB::query('UPDATE diplomas SET active = 0 WHERE id = ?', [$did]);
        $this->ok('анульований у кабінеті не показується', Diplomas::forUser($uid) === []);
        DB::query('DELETE FROM diplomas WHERE id = ?', [$did]);
        DB::query('DELETE FROM course_access WHERE user_id = ?', [$uid]);

        $this->group('курси в замовленні знаходяться по всьому дереву');
        // Позиції лежать у ПІДзамовленні — саме там, де їх шукати найлегше забути
        $parent = DB::insert('orders', ['number' => 'TSTC-' . bin2hex(random_bytes(4)),
            'name' => 'Тест', 'phone' => '+380670000000', 'delivery' => 'digital',
            'status' => 'new', 'total' => 1000, 'created_at' => now()]);
        $child = DB::insert('orders', ['number' => 'TSTC-' . bin2hex(random_bytes(4)) . '/1',
            'parent_id' => $parent, 'name' => 'Тест', 'phone' => '+380670000000',
            'delivery' => 'digital', 'status' => 'new', 'total' => 1000, 'created_at' => now()]);
        DB::insert('order_items', ['order_id' => $child, 'product_id' => (int)$course['id'],
            'title' => $course['name'], 'price' => 1000, 'qty' => 1, 'sum' => 1000]);

        $this->ok('знаходиться за головним замовленням', count(Courses::inOrder($parent)) === 1);
        $this->ok('і за його частиною', count(Courses::inOrder($child)) === 1);

        DB::pdo()->prepare('DELETE FROM order_items WHERE order_id = ?')->execute([$child]);
        foreach ([$child, $parent] as $o) DB::pdo()->prepare('DELETE FROM orders WHERE id = ?')->execute([$o]);
        foreach ($this->users as $id) DB::pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        foreach ($this->products as $id) DB::pdo()->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
        foreach ($this->cats as $id) DB::pdo()->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);

        echo $this->fail === 0
            ? "\nУСЕ ДОБРЕ: {$this->pass} перевірок\n"
            : "\nПРОВАЛЕНО: {$this->fail} із " . ($this->pass + $this->fail) . "\n";
        return $this->fail === 0 ? 0 : 1;
    }
}

return (new CoursesTest())->run();
