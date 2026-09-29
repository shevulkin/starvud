<?php
declare(strict_types=1);

/**
 * Розділення замовлення між магазинами.
 *
 * Два рівні, завжди:
 *   • головне замовлення (parent_id IS NULL) — те, що бачить покупець: контакти,
 *     доставка, промокод, підсумок і зведений статус;
 *   • підзамовлення (parent_id = головне) — завдання одного магазину: свої позиції,
 *     свій статус, свій продавець.
 *
 * Позиції (order_items) лежать тільки в підзамовленнях. Навіть коли магазин один,
 * підзамовлення теж створюється: тоді передача позиції в інший магазин нічого не
 * перебудовує, а доступи продавців, сповіщення й статуси працюють одним шляхом.
 *
 * Ціни фіксуються при оформленні й не змінюються від передач між магазинами —
 * покупець платить рівно те, що бачив у кошику.
 */
class OrderFlow
{
    public const STATUSES = [
        'new' => 'Нове', 'processing' => 'Обробляється', 'shipped' => 'В дорозі',
        'done' => 'Доставлено', 'canceled' => 'Скасовано',
    ];

    /**
     * 'digital' — нічого не везуть: курс відкривається в кабінеті.
     *
     * Це не «ще один спосіб доставки на вибір»: обрати його не можна, він
     * проставляється сам, коли в замовленні самі лише курси (Courses::
     * cartIsDigital). Пропонувати покупцеві меду «цифрову доставку» було б
     * пасткою, а питати адресу в того, хто купив відео, — питанням нізащо.
     */
    public const DELIVERY = ['np' => 'Нова Пошта', 'pickup' => 'Самовивіз',
                             'digital' => 'Онлайн-доступ', 'other' => 'Інше'];

    /**
     * Звідки замовлення. «Сайт» — покупець оформив сам; решту завів продавець:
     * прийняв дзвінок або продав людині, яка прийшла в точку.
     */
    public const SOURCES = ['site' => 'Сайт', 'phone' => 'Телефоном', 'offline' => 'У магазині'];

    /** Просування: головне замовлення показує найменш просунуте з підзамовлень */
    private const RANK = ['new' => 0, 'processing' => 1, 'shipped' => 2, 'done' => 3];

    /** Поля, які підзамовлення успадковує від головного (контакти й доставка не змінюються) */
    private const INHERITED = ['user_id', 'name', 'phone', 'email', 'delivery',
        'city', 'np_office', 'address', 'comment', 'promo_code',
        // Ref-и Нової Пошти теж успадковуються: накладну створюють саме в
        // підзамовленні, і бігати за адресою в головне довелося б щоразу
        'city_ref', 'np_office_ref', 'np_type', 'np_street', 'np_street_ref', 'np_house', 'np_flat'];

    public static function statusLabel(?string $status): string
    { return self::STATUSES[$status] ?? (string)$status; }

    public static function deliveryLabel(?string $delivery): string
    { return self::DELIVERY[$delivery] ?? (string)$delivery; }

    public static function sourceLabel(?string $source): string
    { return self::SOURCES[$source ?: 'site'] ?? (string)$source; }

    /**
     * Куди везти, одним рядком: «Київ, Відділення №5».
     *
     * Назви способу доставки тут немає — вона йде окремим полем, а тут лише
     * адреса. Самовивіз повертає порожній рядок: адреси в нього немає, і
     * вигадувати її не треба (порожній рядок шаблон приберає сам).
     */
    public static function deliveryAddress(array $o): string
    {
        if (($o['delivery'] ?? '') === 'pickup') return '';
        // Курс нікуди не їде — адреси в нього немає так само, як у самовивозу
        if (($o['delivery'] ?? '') === 'digital') return '';
        // Курʼєр — це вулиця з будинком, а не відділення. Показати замість
        // адреси саме місто означало б послати курʼєра в нікуди.
        if (($o['delivery'] ?? '') === 'np' && ($o['np_type'] ?? 'warehouse') === 'courier') {
            $house = trim((string)($o['np_house'] ?? ''));
            $flat = trim((string)($o['np_flat'] ?? ''));
            $parts = [$o['city'] ?? '', trim((string)($o['np_street'] ?? '') . ($house !== '' ? ', буд. ' . $house : ''))
                . ($flat !== '' ? ', кв. ' . $flat : '')];
        } else {
            $parts = [$o['city'] ?? '', $o['np_office'] ?? '', $o['address'] ?? ''];
        }
        return implode(', ', array_filter(array_map('trim', array_map('strval', $parts)), fn($p) => $p !== ''));
    }

    // ------------------------------------------------------------------ читання

    public static function order(int $id): ?array
    { return DB::row('SELECT * FROM orders WHERE id = ?', [$id]); }

    public static function children(int $parentId): array
    {
        return DB::all(
            'SELECT o.*, s.name AS store_name, s.city AS store_city
             FROM orders o LEFT JOIN stores s ON s.id = o.store_id
             WHERE o.parent_id = ? ORDER BY o.seq, o.id', [$parentId]);
    }

    public static function items(int $orderId): array
    { return DB::all('SELECT * FROM order_items WHERE order_id = ? ORDER BY id', [$orderId]); }

    /**
     * Що замовили — списком для сповіщення: «• Мед липовий, 0.5 л × 2».
     *
     * Довгі замовлення обрізаємо: у месенджері повідомлення однаково згорнеться,
     * а продавцю з першого погляду треба зрозуміти, чи є в нього це на складі —
     * решту він побачить в адмінці. Кількість показуємо завжди, навіть «× 1»:
     * без неї рядок «× 2» серед інших читається як частина назви.
     */
    public static function itemsSummary(int $orderId, int $limit = 8): string
    {
        $items = self::items($orderId);
        $lines = [];
        foreach (array_slice($items, 0, $limit) as $i) {
            $title = trim((string)$i['title']);
            if (($i['variant_name'] ?? '') !== '') $title .= ', ' . $i['variant_name'];
            $lines[] = '• ' . $title . ' × ' . (int)$i['qty'];
        }
        if (count($items) > $limit) $lines[] = '• …та ще ' . (count($items) - $limit) . ' поз.';
        return implode("\n", $lines);
    }

    /**
     * Чого не вистачило на складі: [['title'=>…, 'qty'=>5, 'taken'=>2, 'lack'=>3], …].
     *
     * Сайт дозволяє замовити більше, ніж є, — і це свідомо: виробник доробить.
     * Але мовчки такий продаж лишати не можна: хтось має або передати позицію
     * туди, де товар є, або стати за станок. stock_taken = NULL означає
     * замовлення, оформлене до появи цього обліку, — його не чіпаємо.
     */
    public static function shortage(int $orderId): array
    {
        // Скасована частина нічого зі складу не тримає — залишки з неї вже
        // повернулись. «Не вистачає» тут означало б лише те, що її скасували,
        // і гнало б продавця робити те, чого робити не треба.
        if (DB::val('SELECT status FROM orders WHERE id = ?', [$orderId]) === 'canceled') return [];
        $out = [];
        foreach (self::items($orderId) as $i) {
            if ($i['stock_taken'] === null) continue;
            $lack = (int)$i['qty'] - (int)$i['stock_taken'];
            if ($lack <= 0) continue;
            $title = trim((string)$i['title']);
            if (($i['variant_name'] ?? '') !== '') $title .= ', ' . $i['variant_name'];
            $out[] = ['title' => $title, 'qty' => (int)$i['qty'],
                      'taken' => (int)$i['stock_taken'], 'lack' => $lack];
        }
        return $out;
    }

    /** «Мед липовий, 0.5 л — 3 з 5» — одним рядком через кому */
    public static function shortageLine(array $lack): string
    {
        return implode(', ', array_map(
            fn($s) => $s['title'] . ' — ' . $s['lack'] . ' з ' . $s['qty'], $lack));
    }

    /**
     * Те саме для месенджера: окремим блоком і зі знаком, бо це єдиний рядок
     * сповіщення, який вимагає дії, а не просто повідомляє.
     */
    public static function shortageSummary(int $orderId): string
    {
        $lack = self::shortage($orderId);
        if (!$lack) return '';
        $lines = ['⚠️ Не вистачає на складі:'];
        foreach ($lack as $s) $lines[] = '• ' . $s['title'] . ' — є ' . $s['taken'] . ' з ' . $s['qty'];
        $lines[] = 'Передайте позицію іншому магазину або довиробіть.';
        return implode("\n", $lines);
    }

    /**
     * Скільки цієї позиції реально можна продати просто зараз: сума по активних
     * магазинах. Неактивні точки не рахуємо — з них не відвантажують.
     *
     * $lock — узяти рядки під блокування до кінця транзакції. Без цього двоє
     * покупців одночасно бачать останню банку й обоє її купують: перевірка
     * пройшла б у кожного, бо між нею й списанням є розрив. FOR UPDATE його
     * прибирає. На SQLite такого синтаксису немає, і він там не потрібен:
     * ця база тримає лише тести, які йдуть в один потік.
     */
    /** @var array<string,int> залишки для списку карток — див. preloadSellable() */
    private static array $sellableCache = [];

    /**
     * Залишки кількох товарів одним запитом — для списку карток.
     * Кешуються лише вони; перевірка під блокуванням ($lock) і будь-який інший
     * виклик завжди йдуть у базу.
     */
    public static function preloadSellable(array $productIds): void
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        $active = self::activeStoreIds();
        if (!$productIds) return;
        foreach ($productIds as $pid) self::$sellableCache[$pid . ':'] = 0;
        if (!$active) return;
        $inP = implode(',', array_fill(0, count($productIds), '?'));
        $inS = implode(',', array_fill(0, count($active), '?'));
        foreach (DB::all("SELECT product_id, variant_id, COALESCE(SUM(qty), 0) AS q FROM store_stock
                           WHERE product_id IN ($inP) AND store_id IN ($inS) GROUP BY product_id, variant_id",
                         array_merge($productIds, $active)) as $r) {
            self::$sellableCache[(int)$r['product_id'] . ':' . ($r['variant_id'] === null ? '' : (int)$r['variant_id'])] = (int)$r['q'];
        }
    }

    public static function sellable(int $productId, ?int $variantId, bool $lock = false): int
    {
        $key = $productId . ':' . ($variantId ?? '');
        if (!$lock && array_key_exists($productId . ':', self::$sellableCache)) {
            return self::$sellableCache[$key] ?? 0;
        }
        $active = self::activeStoreIds();
        if (!$active) return 0;
        $cond = $variantId === null ? 'variant_id IS NULL' : 'variant_id = ?';
        $args = [$productId];
        if ($variantId !== null) $args[] = $variantId;
        $in = implode(',', array_fill(0, count($active), '?'));
        $sql = "SELECT COALESCE(SUM(qty), 0) FROM store_stock
                 WHERE product_id = ? AND $cond AND store_id IN ($in)";
        if ($lock && DB::driver() === 'mysql') $sql .= ' FOR UPDATE';
        return (int)DB::val($sql, array_merge($args, $active));
    }

    /**
     * Позиції кошика, яких не вистачить і які ніде взяти.
     *
     * Правило навмисно вузьке. Не вистачає в одному магазині — це не привід
     * відмовляти: товар є в мережі, продавець передасть позицію, і для цього
     * все вже зроблено. Відмовляємо лише тоді, коли не вистачає на всю мережу
     * і товар не можна виготовити («Виготовляємо під замовлення» знято).
     *
     * Прапорець стоїть за замовчуванням, тож для більшості каталогу нічого не
     * змінюється, доки власник свідомо не зніме галку на конкретному товарі.
     *
     * @return array<array{title:string,want:int,have:int}>
     */
    public static function unavailable(array $rows, bool $lock = false): array
    {
        $out = [];
        foreach ($rows as $r) {
            // Курс не буває «закінчитись»: складу в нього немає, і рахувати
            // залишок нема по чому. Це та сама умова, що й у Cart::limit, —
            // без неї кошик курс приймав, а оформлення його відхиляло.
            if (Courses::isCourse($r['product'] ?? null)) continue;
            if (!empty($r['product']['made_to_order'])) continue;
            $pid = (int)$r['product']['id'];
            $vid = isset($r['variant']['id']) ? (int)$r['variant']['id'] : null;
            // товар із варіантами, але без обраного — рахувати нема по чому
            if ($vid === null && Catalog::hasVariants($pid)) continue;
            $want = (int)$r['qty'];
            $have = self::sellable($pid, $vid, $lock);
            if ($have >= $want) continue;
            $title = (string)$r['product']['name'];
            if (isset($r['variant']['name'])) $title .= ', ' . $r['variant']['name'];
            $out[] = ['title' => $title, 'want' => $want, 'have' => $have];
        }
        return $out;
    }

    /** «Мед липовий, 0.5 л — у наявності 2 шт., у кошику 5» */
    public static function unavailableLine(array $short): string
    {
        return implode('; ', array_map(
            fn($s) => $s['title'] . ' — у наявності ' . $s['have'] . ' шт., у кошику ' . $s['want'],
            $short));
    }

    /** Позиції всього замовлення (з усіх підзамовлень) — для списків і листів */
    public static function allItems(int $parentId): array
    {
        return DB::all(
            'SELECT i.*, o.store_id, s.name AS store_name
             FROM order_items i
             JOIN orders o ON o.id = i.order_id
             LEFT JOIN stores s ON s.id = o.store_id
             WHERE o.parent_id = ? OR o.id = ? ORDER BY o.seq, i.id', [$parentId, $parentId]);
    }

    /** Головне замовлення для будь-якого рядка (для головного — воно саме) */
    public static function head(array $order): array
    {
        return $order['parent_id'] ? (self::order((int)$order['parent_id']) ?? $order) : $order;
    }

    public static function events(int $parentId): array
    {
        return DB::all(
            'SELECT ev.*, u.name AS user_name FROM order_events ev
             LEFT JOIN users u ON u.id = ev.user_id
             WHERE ev.parent_id = ? ORDER BY ev.id DESC', [$parentId]);
    }

    public static function log(int $parentId, ?int $orderId, string $type, string $message, ?int $userId = null): void
    {
        DB::insert('order_events', [
            'parent_id' => $parentId, 'order_id' => $orderId, 'user_id' => $userId,
            // Роль, у якій діяли: одна людина може працювати і як адмін, і як продавець
            // точки, тож саме імені для історії замало. Пишемо лише тоді, коли дію
            // виконав власник поточної сесії, — інакше приписали б чужу роль.
            'role' => ($userId !== null && $userId === Auth::id()) ? Auth::role() : null,
            'type' => $type, 'message' => $message, 'created_at' => now(),
        ]);
    }

    // ------------------------------------------------------------------ оформлення

    /**
     * Номер замовлення: SV-ГГММДД-XXXXXX (префікс — cfg order_prefix).
     *
     * Раніше номер збирали в двох місцях (оформлення на сайті й каса) двома
     * однаковими рядками з чотирьох шістнадцяткових символів. Це 65 536
     * варіантів на добу — і при кількох сотнях замовлень на день збіг ставав
     * не рідкістю, а очікуваною подією: у задачі про день народження 300
     * замовлень дають приблизно 50 % шансу, що двом дістанеться один номер.
     *
     * Ціна збігу була неочевидною й тому найгіршою: стовпець `number` має
     * UNIQUE, вставка падала винятком, а покупець на останньому кроці бачив
     * «Змініть кількість у кошику» — фразу, яка не має жодного стосунку до
     * того, що сталося. Замовлення при цьому просто не створювалось.
     *
     * Тепер шість символів (16 мільйонів на добу) і, головне, перевірка з
     * повтором: випадковість не дає гарантії, а перевірка дає. Дата в номері
     * лишається — вона потрібна не для унікальності, а щоб продавець із
     * телефоном біля вуха одразу бачив, про який день ідеться.
     */
    public static function newNumber(): string
    {
        $prefix = cfg('order_prefix', 'SV') . '-' . date('ymd') . '-';
        for ($i = 0; $i < 8; $i++) {
            $n = $prefix . strtoupper(bin2hex(random_bytes(3)));
            if (!DB::val('SELECT 1 FROM orders WHERE number = ?', [$n])) return $n;
        }
        /*
         * Вісім збігів поспіль — це не «не пощастило», а щось інше: та сама
         * секунда, зламаний генератор випадкових чисел, база, яка повертає
         * старе. Здаватись тут не можна — покупець уже оплатив увагою цілу
         * форму, — тож беремо номер, який не може збігтися ні з чим: до нього
         * дописано мікросекунди.
         */
        return $prefix . strtoupper(bin2hex(random_bytes(3))) . '-' . substr((string)hrtime(true), -6);
    }

    /**
     * Оформлення: головне замовлення + підзамовлення по магазинах.
     * $head — готовий рядок orders, $rows — рядки Cart::detailed(),
     * $pickupStore — магазин самовивозу (тоді все виконує саме він).
     * Повертає ['id' => головне, 'children' => [підзамовлення]].
     */
    public static function place(array $head, array $rows, ?int $pickupStore): array
    {
        $parentId = DB::tx(function () use ($head, $rows, $pickupStore) {
            // Остання перевірка, і єдина, що чогось варта: та, що в Checkout,
            // могла застаріти, поки людина заповнювала форму. Тут рядки складу
            // вже під блокуванням, тож між перевіркою й списанням ніхто не
            // встигне забрати той самий товар.
            $short = self::unavailable($rows, true);
            if ($short) throw new RuntimeException(
                'Товару вже не вистачає: ' . self::unavailableLine($short));

            $parentId = DB::insert('orders', $head + ['parent_id' => null, 'seq' => 0]);

            // 1) кожна позиція дістає магазин-виконавця
            $groups = [];
            foreach ($rows as $r) {
                $pid = (int)$r['product']['id'];
                $vid = isset($r['variant']['id']) ? (int)$r['variant']['id'] : null;
                $sid = self::pickStore($pid, $vid, (int)$r['qty'], $pickupStore);
                $groups[$sid][] = $r;
            }
            ksort($groups); // магазини йдуть за id — порядок підзамовлень стабільний

            // 2) підзамовлення на кожен магазин
            $seq = 0;
            foreach ($groups as $sid => $group) {
                $seq++;
                $childId = self::createChild($parentId, $head, $sid ?: null, $seq);
                foreach ($group as $r) {
                    $pid = (int)$r['product']['id'];
                    $vid = isset($r['variant']['id']) ? (int)$r['variant']['id'] : null;
                    $qty = (int)$r['qty'];
                    // товар з варіантами, але без вибраного варіанта (старий кошик) —
                    // списувати нема з чого, і рахувати нестачу теж не по чому
                    $countable = !($vid === null && Catalog::hasVariants($pid));
                    $taken = $countable ? self::moveStock($pid, $vid, $sid ?: null, -$qty) : null;
                    DB::insert('order_items', [
                        'order_id' => $childId,
                        'product_id' => $pid, 'variant_id' => $vid,
                        'title' => $r['product']['name'], 'variant_name' => $r['variant']['name'] ?? null,
                        'price' => $r['price'] ?? 0, 'qty' => $qty, 'sum' => $r['sum'] ?? 0,
                        'stock_taken' => $taken,
                    ]);
                }
                // Продали більше, ніж лежало на складі. Пишемо в історію одразу:
                // сповіщення можна проґавити чи вимкнути, а замовлення однаково
                // комусь виконувати — і за тиждень має бути видно, чому воно
                // затрималось.
                $lack = self::shortage($childId);
                if ($lack) self::log($parentId, $childId, 'shortage',
                    'Замовлено понад залишок: ' . self::shortageLine($lack)
                    . '. Передайте позицію магазину, де товар є, або довиробіть її.');
            }

            // Домовлена ціна витрачається фактом продажу, а не тим, якою
            // кнопкою його оформили: сюди приходять і сайт, і каса. У історію
            // пишемо одразу — без цього рядка сума позиції не сходиться ні з
            // прайсом, ні з жодною знижкою, і пояснити її нічим.
            foreach (Offers::consume($rows, $parentId) as $note) {
                self::log($parentId, null, 'note', $note);
            }

            self::recalcTotals($parentId);
            $names = [];
            foreach (self::children($parentId) as $c) $names[] = ($c['store_name'] ?? 'Магазин') . ' — ' . $c['number'];
            self::log($parentId, null, 'created',
                count($names) > 1
                    ? 'Замовлення розділено між магазинами: ' . implode(', ', $names)
                    : 'Замовлення прийнято: ' . ($names[0] ?? '—'));
            return $parentId;
        });

        $parent = self::order($parentId);
        // Перше, чого чекає покупець після кнопки «Замовити», — підтвердження
        // не лише на екрані. Статус тут «Прийнято», а не «Нове»: «нове» — це
        // погляд магазину на свою чергу, людині воно нічого не каже.
        if ($parent) self::tellCustomer($parent, 'Прийнято');

        return ['id' => $parentId, 'children' => self::children($parentId)];
    }

    /** Нове (порожнє) підзамовлення магазину під тим самим головним */
    private static function createChild(int $parentId, array $head, ?int $storeId, int $seq): int
    {
        $data = ['parent_id' => $parentId, 'seq' => $seq,
            'number' => $head['number'] . '/' . $seq, 'token' => null,
            'store_id' => $storeId, 'status' => 'new',
            // Звідки замовлення — властивість усього замовлення, а не однієї
            // його частини: передана в іншу точку позиція не перестає бути
            // продажем із каси. Не через INHERITED: стовпець не приймає NULL,
            // а серед викликів є й такі, де головне ще не має цих полів.
            'source' => $head['source'] ?? 'site',
            'created_by_user_id' => $head['created_by_user_id'] ?? null,
            'subtotal' => 0, 'discount' => 0, 'total' => 0,
            'created_at' => now()];
        // Поля, яких у головному немає, не переписуємо на NULL, а лишаємо
        // стовпцю його замовчування. Інакше кожен стовпець зі значенням за
        // замовчуванням довелось би згадувати тут окремо — як це вже сталося
        // з source вище, і як ледве не сталося з np_type.
        foreach (self::INHERITED as $f) if (array_key_exists($f, $head)) $data[$f] = $head[$f];
        return DB::insert('orders', $data);
    }

    /**
     * Магазин-виконавець позиції: самовивіз — тільки обраний; інакше той, де вистачає
     * на всю кількість, далі — де хоч щось є, і як запасний варіант — магазин за
     * замовчуванням (позиція «під замовлення»).
     */
    public static function pickStore(int $productId, ?int $variantId, int $qty, ?int $preferred): int
    {
        if ($preferred) return $preferred;
        $active = self::activeStoreIds();
        $byStore = array_intersect_key(Catalog::stockByStore($productId, $variantId), array_flip($active));
        arsort($byStore);
        foreach ($byStore as $sid => $have) if ($have >= $qty) return (int)$sid;
        foreach ($byStore as $sid => $have) if ($have > 0) return (int)$sid;
        return self::defaultStoreId();
    }

    /** @return int[] */
    public static function activeStoreIds(): array
    {
        static $ids = null;
        return $ids ??= array_map(fn($r) => (int)$r['id'], DB::all('SELECT id FROM stores WHERE active = 1 ORDER BY sort, id'));
    }

    /** Магазин для позицій, яких немає ніде (виготовлення на замовлення). 0 = магазинів немає взагалі */
    public static function defaultStoreId(): int
    {
        $id = (int)(Settings::get('default_store_id', '0') ?: 0);
        if ($id && in_array($id, self::activeStoreIds(), true)) return $id;
        return self::activeStoreIds()[0] ?? 0;
    }

    // ------------------------------------------------------------------ підсумки й статуси

    /**
     * Суми підзамовлень. Позиції фіксовані, тож перераховуємо лише розкладку:
     * знижка головного ділиться пропорційно, залишок від округлення — найбільшій частині,
     * щоб сума підзамовлень завжди дорівнювала сумі головного.
     */
    public static function recalcTotals(int $parentId): void
    {
        $parent = self::order($parentId);
        if (!$parent) return;
        $children = self::children($parentId);
        if (!$children) return;

        $subs = [];
        foreach ($children as $c) {
            $s = 0.0;
            foreach (self::items((int)$c['id']) as $it) $s += (float)$it['sum'];
            $subs[(int)$c['id']] = round($s, 2);
        }
        $total = array_sum($subs);
        $discount = (float)$parent['discount'];

        arsort($subs); // найбільша частина йде першою — їй дістанеться залишок округлення
        $left = round($discount, 2);
        $i = 0; $n = count($subs);
        foreach ($subs as $cid => $sub) {
            $i++;
            $d = ($i === $n || $total <= 0) ? $left : round($discount * $sub / $total, 2);
            $d = min($d, $sub, $left);
            $left = round($left - $d, 2);
            DB::update('orders', [
                'subtotal' => $sub, 'discount' => $d, 'total' => max(0, round($sub - $d, 2)),
            ], 'id = ?', [$cid]);
        }
    }

    /**
     * Зведений статус головного: найменш просунутий серед живих підзамовлень,
     * усі скасовані — скасовано. Тобто «Доставлено» зʼявляється лише тоді,
     * коли всі магазини закрили свої частини.
     */
    public static function aggregateStatus(int $parentId): ?string
    {
        $sts = array_column(self::children($parentId), 'status');
        if (!$sts) return null;
        $live = array_values(array_filter($sts, fn($s) => $s !== 'canceled'));
        if (!$live) return 'canceled';
        usort($live, fn($a, $b) => (self::RANK[$a] ?? 0) <=> (self::RANK[$b] ?? 0));
        return $live[0];
    }

    /** Підтягнути головне під підзамовлення. Повертає новий статус, якщо він змінився */
    /**
     * $tellCustomer = false — коли покупцю вже сказали конкретніше.
     *
     * Такий випадок один: статус змінив трекінг Нової Пошти, і покупець тієї ж
     * секунди отримав повідомлення з номером накладної й посиланням на
     * відстеження. Друге, сухіше («замовлення — доставлено»), приходило б
     * слідом за першим і виглядало б як збій розсилки. Персоналу при цьому все
     * йде як завжди: для нього це різні новини.
     */
    public static function syncParent(int $parentId, ?int $userId = null, bool $tellCustomer = true): ?string
    {
        $parent = self::order($parentId);
        $new = self::aggregateStatus($parentId);
        if (!$parent || $new === null || $new === $parent['status']) return null;
        DB::update('orders', ['status' => $new], 'id = ?', [$parentId]);
        // Скасоване замовлення не витрачає промокод. Ставимо саме тут: через
        // syncParent() проходить кожна зміна статусу головного, звідки б вона
        // не прийшла — від магазину, від адміна чи каскадом.
        Promo::syncUse($parent, $new);
        self::log($parentId, null, 'status',
            'Статус замовлення оновлено автоматично: ' . self::statusLabel($new) .
            ' (усі магазини опрацювали свої частини)', $userId);
        Notify::fire('order_status', [
            'number' => $parent['number'], 'status' => self::statusLabel($new),
        ], $parent['store_id'] ? (int)$parent['store_id'] : null);
        // Покупцю це головна новина дня — і саме тут, а не в кожній частині:
        // рух усього замовлення важливіший за рух однієї з його половин.
        if ($tellCustomer) self::tellCustomer($parent, self::statusLabel($new));
        return $new;
    }

    /**
     * Зміна статусу. Для підзамовлення — своє + автопідтягування головного,
     * для головного — каскадом на всі підзамовлення (щоб «скасувати все» було одним рухом).
     */
    public static function setStatus(int $orderId, string $status, ?int $userId = null, bool $tellCustomer = true): bool
    {
        if (!isset(self::STATUSES[$status])) return false;
        $order = self::order($orderId);
        if (!$order) return false;

        if ($order['parent_id']) {
            if (!self::applyChildStatus($order, $status, $userId)) return false;
            // Головне змінилось — покупцю пішла новина про все замовлення, і
            // дублювати її розповіддю про одну частину не треба.
            if (self::syncParent((int)$order['parent_id'], $userId, $tellCustomer) === null && $tellCustomer) {
                self::tellCustomer(self::head($order), self::statusLabel($status), $order);
            }
            return true;
        }

        // головне: розкладаємо на всі частини, що ще в роботі
        $changed = 0;
        foreach (self::children($orderId) as $c) {
            if ($c['status'] === 'canceled' && $status !== 'canceled') continue; // скасоване не воскрешаємо
            if (self::applyChildStatus($c, $status, $userId)) $changed++;
        }
        if ($changed) self::log($orderId, null, 'status',
            'Статус проставлено всім підзамовленням: ' . self::statusLabel($status), $userId);
        self::syncParent($orderId, $userId, $tellCustomer);
        return $changed > 0;
    }

    /**
     * Статус однієї частини: склад, історія, сповіщення магазину.
     * Повертає false, якщо статус той самий (тоді нічого й не сталось).
     */
    private static function applyChildStatus(array $child, string $status, ?int $userId): bool
    {
        if ($child['status'] === $status) return false;
        $was = (string)$child['status'];
        $cid = (int)$child['id'];

        $note = ''; $freed = [];
        self::tx(function () use ($child, $cid, $status, $was, &$note, &$freed) {
            if ($status === 'canceled') {
                $freed = self::releaseStock($child);
                if ($freed) $note = '. Залишки повернено на склад: '
                    . implode(', ', array_column($freed, 'line'));
            } elseif ($was === 'canceled') {
                $lack = self::reserveStock($child);
                $note = $lack
                    ? '. Залишки списано знову, але вже не все: ' . self::shortageLine($lack)
                    : '. Залишки списано зі складу знову';
            }
            DB::update('orders', ['status' => $status], 'id = ?', [$cid]);
        });

        $store = $child['store_id'] ? DB::val('SELECT name FROM stores WHERE id = ?', [$child['store_id']]) : null;
        self::log((int)$child['parent_id'], $cid, 'status',
            ($store ? $store . ': ' : '') . 'статус підзамовлення ' . $child['number']
            . ' → ' . self::statusLabel($status) . $note, $userId);
        Notify::fire('order_status', [
            'number' => $child['number'], 'status' => self::statusLabel($status),
        ], $child['store_id'] ? (int)$child['store_id'] : null);

        // Товар знову на полиці — а на нього могли чекати. Після коміту:
        // fulfil() шле листи й закриває запити, і робити це всередині
        // транзакції, яку ще може відкотити, не можна.
        foreach ($freed as $f) StockWatch::fulfil($f['pid'], $f['vid']);
        return true;
    }

    /**
     * Скасування повертає товар на склад.
     *
     * Повертаємо рівно те, що з магазину колись зняли (stock_taken), а не всю
     * кількість позиції: замовлення, оформлене понад залишок, інакше подарувало б
     * точці неіснуючий товар. NULL — замовлення старіше за цей облік, там
     * вважаємо, що взяли все. Після повернення stock_taken = 0 — саме воно й
     * означає «скільки штук ця частина зараз тримає зі складу».
     *
     * @return array<array{pid:int,vid:?int,line:string}> що саме повернулось
     */
    private static function releaseStock(array $child): array
    {
        $storeId = $child['store_id'] ? (int)$child['store_id'] : null;
        $out = [];
        foreach (self::items((int)$child['id']) as $i) {
            $pid = $i['product_id'] ? (int)$i['product_id'] : null;
            $vid = $i['variant_id'] ? (int)$i['variant_id'] : null;
            if (!$pid || ($vid === null && Catalog::hasVariants($pid))) continue;
            $taken = $i['stock_taken'] === null ? (int)$i['qty'] : (int)$i['stock_taken'];
            $given = $taken > 0 ? self::moveStock($pid, $vid, $storeId, $taken) : 0;
            DB::update('order_items', ['stock_taken' => 0], 'id = ?', [(int)$i['id']]);
            if ($given > 0) {
                $title = trim((string)$i['title']);
                if (($i['variant_name'] ?? '') !== '') $title .= ', ' . $i['variant_name'];
                $out[] = ['pid' => $pid, 'vid' => $vid, 'line' => $title . ' × ' . $given];
            }
        }
        return $out;
    }

    /**
     * Зняття скасування забирає товар зі складу знову — і цілком може виявити,
     * що його вже немає: поки замовлення лежало скасованим, залишок міг піти
     * комусь іншому. Мовчати про це не можна, тому повертаємо нестачу.
     *
     * @return array<array{title:string,qty:int,taken:int,lack:int}>
     */
    private static function reserveStock(array $child): array
    {
        $storeId = $child['store_id'] ? (int)$child['store_id'] : null;
        foreach (self::items((int)$child['id']) as $i) {
            $pid = $i['product_id'] ? (int)$i['product_id'] : null;
            $vid = $i['variant_id'] ? (int)$i['variant_id'] : null;
            if (!$pid || ($vid === null && Catalog::hasVariants($pid))) continue;
            $taken = self::moveStock($pid, $vid, $storeId, -(int)$i['qty']);
            DB::update('order_items', ['stock_taken' => $taken], 'id = ?', [(int)$i['id']]);
        }
        // статус у базі ще 'canceled', тож рахуємо нестачу самі, а не через shortage()
        $out = [];
        foreach (self::items((int)$child['id']) as $i) {
            $lack = (int)$i['qty'] - (int)$i['stock_taken'];
            if ($lack <= 0) continue;
            $title = trim((string)$i['title']);
            if (($i['variant_name'] ?? '') !== '') $title .= ', ' . $i['variant_name'];
            $out[] = ['title' => $title, 'qty' => (int)$i['qty'],
                      'taken' => (int)$i['stock_taken'], 'lack' => $lack];
        }
        return $out;
    }

    /**
     * Транзакція, якщо ми ще не всередині чужої: статус міняють і окремо з
     * адмінки, і зсередини передачі позиції, а вкладену транзакцію PDO не
     * відкриє. Склад і статус мають змінитися разом або ніяк — інакше
     * скасування, що обірвалось посередині, поверне товар двічі.
     */
    private static function tx(callable $fn)
    {
        return DB::pdo()->inTransaction() ? $fn() : DB::tx($fn);
    }

    /**
     * Покупцю: що сталося з його замовленням. $child — коли рухається лише
     * одна частина; тоді називаємо магазин і перелічуємо саме її товари, бо
     * інакше «В дорозі» на весь номер збиває з пантелику: половина ще збирається.
     */
    private static function tellCustomer(array $parent, string $statusLabel, ?array $child = null): void
    {
        Notify::toCustomer(
            $parent['user_id'] ? (int)$parent['user_id'] : null,
            ((string)($parent['email'] ?? '')) !== '' ? (string)$parent['email'] : null,
            'order_customer', self::customerVars($parent, $statusLabel, $child));
    }

    /** Сума для листа: копійки лише тоді, коли вони справді є */
    private static function money(float $sum): string
    {
        return number_format($sum, fmod($sum, 1) !== 0.0 ? 2 : 0, '.', ' ');
    }

    /**
     * Що саме побачить покупець. Винесено окремо, бо тут важливий не факт
     * відправки, а текст: номер завжди один — той, який людина знає, — а от
     * сума й перелік товарів мають стосуватись того, про що йдеться.
     */
    public static function customerVars(array $parent, string $statusLabel, ?array $child = null): array
    {
        $store = '';
        if ($child) {
            $store = (string)($child['store_name']
                ?? ($child['store_id'] ? (DB::val('SELECT name FROM stores WHERE id = ?', [$child['store_id']]) ?? '') : ''));
        }
        return [
            'number' => $parent['number'],
            // Статус із малої: у шаблоні він стоїть усередині речення
            // («Ваше замовлення … — доставлено.»), а не окремою міткою
            'status' => mb_strtolower($statusLabel),
            'part'   => $child ? 'Частина від магазину «' . ($store ?: 'уточнюємо') . '»' : '',
            'items'  => $child ? self::itemsSummary((int)$child['id']) : '',
            // Копійки показуємо, лише коли вони є. «1 240.00 грн» у листі до
            // покупця виглядає роздруківкою з бухгалтерії, а не сумою покупки.
            'total'  => self::money((float)($child['total'] ?? $parent['total'])),
            // Підпис: людина має бачити, що з нею говорить магазин, а не система
            'shop'   => (string)cfg('app_name'),
        ];
    }

    // ------------------------------------------------------------------ передача позиції

    /**
     * Передати позицію іншому магазину: вона переїжджає в його підзамовлення
     * (створюється, якщо його ще немає), залишки повертаються старому магазину
     * й списуються в нового. Ціна позиції не змінюється.
     */
    public static function transferItem(int $itemId, int $toStoreId, ?int $userId = null): string
    {
        $result = DB::tx(function () use ($itemId, $toStoreId, $userId) {
            $item = DB::row('SELECT * FROM order_items WHERE id = ?', [$itemId]);
            if (!$item) throw new RuntimeException('Позицію не знайдено.');
            $from = self::order((int)$item['order_id']);
            if (!$from || !$from['parent_id']) throw new RuntimeException('Передавати можна лише позиції підзамовлення.');
            $parentId = (int)$from['parent_id'];
            $parent = self::order($parentId);
            if (!$parent) throw new RuntimeException('Головне замовлення не знайдено.');
            if ((int)$from['store_id'] === $toStoreId) throw new RuntimeException('Позиція вже в цьому магазині.');
            $store = DB::row('SELECT * FROM stores WHERE id = ? AND active = 1', [$toStoreId]);
            if (!$store) throw new RuntimeException('Магазин недоступний.');
            if (in_array($from['status'], ['done', 'canceled'], true))
                throw new RuntimeException('Підзамовлення вже закрите — передавати з нього не можна.');

            // Залишки: повертаємо старому магазину рівно те, що з нього колись
            // зняли, а не всю кількість позиції. Інакше передача позиції,
            // проданої понад залишок, дарувала б старій точці неіснуючий товар:
            // зняли 2 з 5 — повернули б 5. NULL — замовлення до цього обліку,
            // там поводимось як раніше й вважаємо, що взяли все.
            $pid = $item['product_id'] ? (int)$item['product_id'] : null;
            $vid = $item['variant_id'] ? (int)$item['variant_id'] : null;
            $qty = (int)$item['qty'];
            $got = null;
            if ($pid && !($vid === null && Catalog::hasVariants($pid))) {
                $taken = $item['stock_taken'] === null ? $qty : (int)$item['stock_taken'];
                self::moveStock($pid, $vid, $from['store_id'] ? (int)$from['store_id'] : null, $taken);
                $got = self::moveStock($pid, $vid, $toStoreId, -$qty);
                DB::update('order_items', ['stock_taken' => $got], 'id = ?', [$itemId]);
            }

            // підзамовлення-отримувач: чинне того ж магазину або нове
            $to = DB::row(
                "SELECT * FROM orders WHERE parent_id = ? AND store_id = ? AND status NOT IN ('done','canceled')
                 ORDER BY seq LIMIT 1", [$parentId, $toStoreId]);
            $created = false;
            if (!$to) {
                $seq = (int)(DB::val('SELECT MAX(seq) FROM orders WHERE parent_id = ?', [$parentId]) ?? 0) + 1;
                $to = self::order(self::createChild($parentId, $parent, $toStoreId, $seq));
                $created = true;
            }

            DB::update('order_items', ['order_id' => $to['id']], 'id = ?', [$itemId]);

            // підзамовлення без позицій більше не існує як завдання магазину
            $emptied = !DB::val('SELECT id FROM order_items WHERE order_id = ? LIMIT 1', [$from['id']]);
            if ($emptied) {
                DB::update('order_events', ['order_id' => null], 'order_id = ?', [$from['id']]);
                DB::delete('orders', 'id = ?', [$from['id']]);
            }

            self::recalcTotals($parentId);
            $fromName = $from['store_id'] ? (DB::val('SELECT name FROM stores WHERE id = ?', [$from['store_id']]) ?? '—') : 'Без магазину';
            $title = $item['title'] . ($item['variant_name'] ? ' · ' . $item['variant_name'] : '') . ' × ' . (int)$item['qty'];
            self::log($parentId, (int)$to['id'], 'transfer',
                'Позицію «' . $title . '» передано: ' . $fromName . ' → ' . $store['name'] .
                ' (' . $to['number'] . ($created ? ', нове підзамовлення' : '') . ')' .
                ($emptied ? '. Підзамовлення ' . $from['number'] . ' закрито — позицій не лишилось.' : '') .
                // Передали, а легше не стало: у новій точці товару теж бракує.
                // Сказати про це треба одразу, інакше позицію ганятимуть по колу.
                ($got !== null && $got < $qty
                    ? '. Увага: у «' . $store['name'] . '» теж не вистачає — є ' . $got . ' з ' . $qty . '.'
                    : ''), $userId);
            self::syncParent($parentId, $userId);

            return ['child' => self::order((int)$to['id']), 'created' => $created, 'store' => $store['name']];
        });

        // магазину-отримувачу це нове завдання — сповіщаємо як про нове замовлення
        if ($result['child']) self::notifyNew($result['child']);
        return ($result['created'] ? 'Створено підзамовлення для магазину «' : 'Позицію передано магазину «')
            . $result['store'] . '».';
    }

    /**
     * Зміна залишку в магазині. Тільки для наявних рядків store_stock: якщо магазин
     * ніколи не мав цього товару, списання нічого не робить (позиція «під замовлення»),
     * і повернення так само не має вигадувати залишок з нічого.
     *
     * Повертає, скільки штук справді пройшло. При списанні це може бути менше за
     * замовлене — рівно стільки, скільки лежало на складі. Раніше різниця просто
     * зникала в GREATEST(0, …), і про продаж понад залишок не дізнавався ніхто:
     * ні продавець, ні наступна передача позиції, яка повертала магазину більше,
     * ніж з нього колись зняли.
     */
    private static function moveStock(?int $productId, ?int $variantId, ?int $storeId, int $delta): int
    {
        if (!$productId || !$storeId || $delta === 0) return 0;
        $cond = $variantId === null ? 'variant_id IS NULL' : 'variant_id = ?';
        $where = [$productId, $storeId];
        if ($variantId !== null) $where[] = $variantId;

        $have = DB::val("SELECT SUM(qty) FROM store_stock WHERE product_id = ? AND store_id = ? AND $cond", $where);
        if ($have === null) return 0; // рядка немає — магазин цього товару не тримає

        $applied = $delta < 0 ? min((int)$have, -$delta) : $delta;
        if ($applied <= 0) return 0;

        $fn = DB::driver() === 'sqlite' ? 'MAX' : 'GREATEST';
        $expr = $delta < 0 ? "$fn(0, qty - ?)" : 'qty + ?';
        DB::query("UPDATE store_stock SET qty = $expr WHERE product_id = ? AND store_id = ? AND $cond",
            array_merge([$applied], $where));
        return $applied;
    }

    /** Сповіщення магазину про його частину замовлення */
    public static function notifyNew(array $child): void
    {
        $store = $child['store_name'] ?? ($child['store_id']
            ? (DB::val('SELECT name FROM stores WHERE id = ?', [$child['store_id']]) ?? '—') : 'Не призначено');
        Notify::fire('order_new', [
            'number' => $child['number'], 'name' => $child['name'], 'phone' => $child['phone'],
            'delivery' => self::deliveryLabel($child['delivery']),
            'address' => self::deliveryAddress($child),
            'items' => self::itemsSummary((int)$child['id']),
            // Порожньо, коли все на складі, — і рядок зникне сам (Notify::interpolate)
            'shortage' => self::shortageSummary((int)$child['id']),
            'total' => number_format((float)$child['total'], 2, '.', ' '),
            'store' => $store,
        ], $child['store_id'] ? (int)$child['store_id'] : null);
    }
}
