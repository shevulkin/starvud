<?php
declare(strict_types=1);

/**
 * Фіскальні чеки: що саме ми пробиваємо і що з цього виходить.
 *
 * Цей клас НЕ ЗНАЄ, хто його пробиває. Він складає нейтральний документ чека —
 * рядки, знижки, податкові групи, оплати, округлення — і віддає його
 * перекладачеві постачальника (див. FiscalProvider). Постачальника ПРРО
 * міняють, і це не має означати переписування логіки продажу; те, що коштувало
 * думання, лежить тут і переживе будь-яку заміну.
 *
 * Чек належить ПІДЗАМОВЛЕННЮ, а не замовленню. Гроші отримує конкретна точка
 * своєю касою, і фіскальний номер зі зміною належать саме тому ПРРО.
 * Замовлення, розділене між двома магазинами, — це два продажі й два чеки, як
 * і дві накладні (див. Shipments, влаштовані так само й з тієї самої причини).
 *
 * ЧОТИРИ СТАНИ ЧЕКА, і головні тут два останні:
 *   queued  — завдання складене й чекає, поки його забере агент точки або
 *             браузер продавця. У маршрутах, де ключ лежить у магазині, наш
 *             сервер до каси не ходить взагалі — і це нормальний стан, а не
 *             поломка;
 *   pending — надіслали, відповіді не було. Чек МІГ пробитись, тому повторюємо
 *             тим самим tag: ПРРО впізнає за ним ту саму спробу й віддасть той
 *             самий чек замість другого. Інакше обірваний звʼязок коштував би
 *             подвійного чека й ручного повернення в кабінеті;
 *   done    — є фіскальний номер, чек існує в ДПС;
 *   error   — ПРРО відмовив по суті (не зійшлась сума, немає ключа): чека
 *             немає, можна виправити й пробити знову.
 */
class Fiscal
{
    /** Скільки разів повторюємо непевний чек, перш ніж покликати людину */
    private const MAX_ATTEMPTS = 5;

    /** Крок округлення готівки: копійки дрібніші за 10 в обігу не ходять */
    private const CASH_STEP = 0.10;

    /**
     * Скільки хвилин чекати на агента, перш ніж повернути завдання в чергу.
     *
     * Агент міг забрати завдання й померти — вимкнули ПК, обірвався інтернет.
     * Повертати таке в чергу безпечно рівно тому, що tag незмінний: якщо чек
     * усе-таки пробився, друга спроба поверне його ж.
     */
    private const STALE_MINUTES = 3;

    // ─────────────────────────────────────────────────────────────── читання

    /** Останній чек продажу цієї частини (той, що показуємо в картці) */
    public static function forOrder(int $orderId): ?array
    {
        return DB::row("SELECT * FROM fiscal_receipts WHERE order_id = ? AND type = 'sell'
                        ORDER BY id DESC", [$orderId]);
    }

    /** Усі чеки частини разом із поверненнями — стрічкою, найновіші зверху */
    public static function allFor(int $orderId): array
    {
        return DB::all('SELECT * FROM fiscal_receipts WHERE order_id = ? ORDER BY id DESC', [$orderId]);
    }

    /** Чеки всього замовлення: [id підзамовлення => [чеки]] */
    public static function forParent(int $parentId): array
    {
        $out = [];
        foreach (DB::all('SELECT * FROM fiscal_receipts WHERE parent_id = ? ORDER BY id', [$parentId]) as $r) {
            $out[(int)$r['order_id']][] = $r;
        }
        return $out;
    }

    public static function byId(int $id): ?array
    {
        return DB::row('SELECT * FROM fiscal_receipts WHERE id = ?', [$id]);
    }

    /**
     * Чи є на цю частину живий чек продажу.
     *
     * Черга теж рахується: завдання складене й ось-ось піде в касу, і другий
     * чек на той самий продаж — це вже ручне повернення.
     */
    public static function hasSale(int $orderId): bool
    {
        return (bool)DB::val("SELECT 1 FROM fiscal_receipts
                              WHERE order_id = ? AND type = 'sell' AND status IN ('done','pending','queued') LIMIT 1",
                             [$orderId]);
    }

    /** Чи повернуто вже цей чек */
    public static function refunded(int $receiptId): bool
    {
        return (bool)DB::val("SELECT 1 FROM fiscal_receipts
                              WHERE of_receipt_id = ? AND status IN ('done','pending','queued') LIMIT 1", [$receiptId]);
    }

    /**
     * Непевні чеки, які час перепитати самим.
     *
     * Тільки хмарні: у решті маршрутів до каси ходить не наш сервер, і
     * повторювати за нього ми не можемо — там за це відповідає повернення
     * завдання в чергу (див. requeueStale).
     */
    public static function due(int $limit = 50): array
    {
        return DB::all("SELECT * FROM fiscal_receipts
                        WHERE status = 'pending' AND route = 'cloud' AND attempts < ?
                        ORDER BY updated_at, id LIMIT " . max(1, $limit), [self::MAX_ATTEMPTS]);
    }

    /**
     * Завдання, які агент забрав і не повернув. Повертаємо їх у чергу — інакше
     * вимкнений посеред продажу ПК лишив би чек висіти назавжди.
     *
     * @return int скільки повернули
     */
    public static function requeueStale(): int
    {
        $edge = date('Y-m-d H:i:s', time() - self::STALE_MINUTES * 60);
        return DB::update('fiscal_receipts', ['status' => 'queued', 'updated_at' => now()],
            "status = 'pending' AND route <> 'cloud' AND attempts < ? AND updated_at < ?",
            [self::MAX_ATTEMPTS, $edge]);
    }

    public static function statusLabel(array $r): string
    {
        $what = ($r['type'] ?? 'sell') === 'return' ? 'Повернення' : 'Чек';
        return match ((string)$r['status']) {
            'done' => $what === 'Чек' ? 'Чек фіскалізовано' : 'Повернення проведено',
            'error' => 'Помилка',
            'queued' => 'У черзі до каси',
            default => 'Відповіді ще немає',
        };
    }

    // ──────────────────────────────────────────────────────────── податкова група

    /**
     * Податкова група позиції: своя в товару, інакше типова цього магазину,
     * інакше загальна.
     *
     * Магазин попереду загальної навмисно: точки можуть належати різним ФОПам,
     * і платник ПДВ поруч із неплатником — звичайна для мережі річ. Товарна
     * група попереду обох: підакцизне чи пільгове не залежить від того, з якої
     * полиці його взяли.
     */
    public static function taxGroup(?array $product, ?int $storeId): int
    {
        $own = $product['taxgrp'] ?? null;
        if ($own !== null && isset(Vchasno::TAX_GROUPS[(int)$own])) return (int)$own;
        return self::storeTaxGroup($storeId);
    }

    /**
     * Чиїм іменем підписано документ.
     *
     * Чек продажу підписує ТОЙ, ХТО ПРОДАВ — його імʼя приходить сюди першим
     * аргументом і завжди перемагає. Решта — для документів, яких не пробивав
     * ніхто живий: нічного Z-звіту з cron. Там береться підпис точки (у мережі
     * точки бувають різних ФОПів, і бухгалтер у них різний), далі загальний, а
     * як і його немає — назва магазину.
     *
     * Вигадувати щось на кшталт «cron» не можна: у фіскальному документі це
     * читається як прізвище касира, якого не існує.
     */
    public static function cashier(?string $name, ?int $storeId): string
    {
        $name = trim((string)$name);
        if ($name !== '') return $name;
        if ($storeId) {
            $own = trim((string)(DB::val('SELECT vchasno_cashier FROM stores WHERE id = ?', [$storeId]) ?? ''));
            if ($own !== '') return $own;
        }
        // Далі — власник точки: у двох магазинів одного ФОПа підпис один, і
        // вписувати його двічі означало б рано чи пізно розійтись
        $owner = Owners::ofStore($storeId);
        if ($owner && trim((string)($owner['cashier'] ?? '')) !== '') return trim((string)$owner['cashier']);
        return trim((string)Settings::get('vchasno_cashier', ''));
    }

    /** Типова група магазину (або загальна, якщо в точки своєї немає) */
    public static function storeTaxGroup(?int $storeId): int
    {
        if ($storeId) {
            $own = DB::val('SELECT vchasno_taxgrp FROM stores WHERE id = ?', [$storeId]);
            if ($own !== null && isset(Vchasno::TAX_GROUPS[(int)$own])) return (int)$own;
        }
        // Ставка належить ПЛАТНИКУ, а не приміщенню: у двох точок одного ФОПа
        // вона одна й та сама, і різною бути не може.
        $owner = Owners::ofStore($storeId);
        if ($owner && $owner['taxgrp'] !== null && isset(Vchasno::TAX_GROUPS[(int)$owner['taxgrp']])) {
            return (int)$owner['taxgrp'];
        }
        $g = (int)Settings::get('vchasno_taxgrp', '2');
        return isset(Vchasno::TAX_GROUPS[$g]) ? $g : 2;
    }

    // ─────────────────────────────────────────────────────────────── рядки чека

    /**
     * Рядки чека з позицій підзамовлення.
     *
     * Знижку розкладаємо по рядках, а не ставимо загальною на чек: у чека
     * загальна знижка рахується інакше (сума оплат має відрізнятись від суми
     * чека рівно на неї), і одна помилка в цьому місці валить увесь чек.
     * Порядкова знижка ж дає рівно те, що бачив покупець у кошику:
     * перераховану ціну кожної позиції.
     *
     * Залишок від округлення дістається найбільшому рядку — той самий прийом,
     * що й у OrderFlow::recalcTotals, і з тієї самої причини: сума рядків
     * мусить збігатися з сумою чека до копійки, інакше ПРРО відмовить.
     *
     * @return array{rows:array,sum:float}
     */
    public static function rows(int $orderId, ?int $storeId, float $discount): array
    {
        $items = DB::all('SELECT * FROM order_items WHERE order_id = ? ORDER BY id', [$orderId]);
        if (!$items) return ['rows' => [], 'sum' => 0.0];

        $sums = [];
        foreach ($items as $i => $it) $sums[$i] = round((float)$it['sum'], 2);
        $total = array_sum($sums);

        // Найбільшій позиції — залишок: розподіл «по копійці» помітний саме на
        // ній найменше, а дрібну позицію знижка могла б з’їсти в мінус.
        $order = $sums;
        arsort($order);
        $left = round(min($discount, $total), 2);
        $cuts = [];
        $n = count($order); $k = 0;
        foreach ($order as $i => $sum) {
            $k++;
            $d = ($k === $n || $total <= 0) ? $left : round($discount * $sum / $total, 2);
            $cuts[$i] = max(0.0, min($d, $sum, $left));
            $left = round($left - $cuts[$i], 2);
        }

        $rows = []; $sum = 0.0;
        foreach ($items as $i => $it) {
            $product = $it['product_id']
                ? DB::row('SELECT * FROM products WHERE id = ?', [(int)$it['product_id']])
                : null;
            $variant = $it['variant_id']
                ? DB::row('SELECT * FROM product_variants WHERE id = ?', [(int)$it['variant_id']])
                : null;

            $name = trim((string)$it['title']);
            if (($it['variant_name'] ?? '') !== '') $name .= ', ' . $it['variant_name'];

            $row = [
                'name' => $name,
                'cnt' => (float)(int)$it['qty'],
                'price' => round((float)$it['price'], 2),
                'disc' => round($cuts[$i] ?? 0, 2),
                'taxgrp' => self::taxGroup($product, $storeId),
            ];
            // Коди — те, за чим позиція в чеку сходиться з номенклатурою в
            // кабінеті ПРРО. Належать фасовці, якщо вона є: етикетку зі
            // штрихкодом клеять на банку, а не на «мед узагалі».
            $code = trim((string)($variant['sku'] ?? '')) ?: trim((string)($product['sku'] ?? ''));
            $barcode = trim((string)($variant['barcode'] ?? '')) ?: trim((string)($product['barcode'] ?? ''));
            $uktzed = trim((string)($product['uktzed'] ?? ''));
            if ($code !== '') $row['code'] = $code;
            if ($barcode !== '') $row['code1'] = $barcode;
            if ($uktzed !== '') $row['code2'] = $uktzed;

            $rows[] = $row;
            $sum = round($sum + round($row['cnt'] * $row['price'], 2) - $row['disc'], 2);
        }
        return ['rows' => $rows, 'sum' => $sum];
    }

    // ──────────────────────────────────────────────────────────────── перешкоди

    /**
     * Чого бракує, щоб пробити чек. Список, а не «так/ні»: продавцю треба
     * знати, що саме заповнити, і чи це взагалі його робота.
     *
     * @return string[] порожній масив = все на місці
     */
    public static function missing(array $child, array $parent, ?int $userId = null): array
    {
        $storeId = $child['store_id'] ? (int)$child['store_id'] : null;
        $out = FiscalProvider::missing(FiscalProvider::route($storeId, $userId));

        if ((string)$child['status'] === 'canceled') $out[] = 'частину скасовано — продажу не було';
        if (!DB::val('SELECT COUNT(*) FROM order_items WHERE order_id = ?', [(int)$child['id']])) {
            $out[] = 'у частині немає позицій';
        }
        if (round((float)$child['total'], 2) <= 0) $out[] = 'сума частини нульова — фіскалізувати нічого';
        if (self::hasSale((int)$child['id'])) $out[] = 'чек на цю частину вже пробито';
        return $out;
    }

    // ─────────────────────────────────────────────────────────────── продаж

    /**
     * Пробити чек продажу.
     *
     * $in: ['pay_type' => int, 'got' => float (готівкою від покупця), 'cashier' => string]
     *
     * @return array{ok:bool,state:string,error:string,receipt:?array}
     */
    public static function sell(array $child, array $parent, array $in = [], ?int $userId = null): array
    {
        $gaps = self::missing($child, $parent, $userId);
        if ($gaps) return self::no('Чек не пробито: ' . implode('; ', $gaps) . '.');

        $storeId = $child['store_id'] ? (int)$child['store_id'] : null;
        $built = self::rows((int)$child['id'], $storeId, (float)$child['discount']);
        if (!$built['rows']) return self::no('У частині немає позицій.');

        $payType = (int)($in['pay_type'] ?? 0);
        if (!isset(Vchasno::PAY_TYPES[$payType])) $payType = 0;

        $sum = $built['sum'];
        // Готівка ходить кроком у 10 копійок. Округлення — окремий рядок чека,
        // а не мовчазна зміна ціни: покупець має бачити, звідки взялась
        // різниця, а ДПС — що товар коштує стільки ж, скільки на полиці.
        $paid = $payType === 0 && Settings::bool('vchasno_cash_round', true)
            ? round(round($sum / self::CASH_STEP) * self::CASH_STEP, 2)
            : $sum;
        $round = round($paid - $sum, 2);

        $pay = ['type' => $payType, 'sum' => round($paid, 2)];
        if ($payType === 0) {
            $got = round(max(0.0, (float)($in['got'] ?? 0)), 2);
            if ($got > $paid) $pay['change'] = round($got - $paid, 2);
        }

        $doc = [
            'task' => 'sell',
            'cashier' => self::cashier($in['cashier'] ?? null, $storeId),
            'sum' => round($sum, 2),
            'round' => $round,
            'comment_down' => (string)Settings::get('vchasno_comment_down', ''),
            'rows' => $built['rows'],
            'pays' => [$pay],
            'customer' => self::customer($parent),
        ];

        return self::send([
            'order_id' => (int)$child['id'], 'parent_id' => (int)$parent['id'], 'store_id' => $storeId,
            'type' => 'sell', 'task' => 'sell', 'of_receipt_id' => null,
            'sum' => round($paid, 2), 'pay_type' => $payType,
            'change' => (float)($pay['change'] ?? 0),
        ], $doc, $userId);
    }

    // ─────────────────────────────────────────────────────────────── повернення

    /**
     * Чек повернення на весь пробитий чек.
     *
     * Часткових повернень тут навмисно немає: у нас скасовують позицію (і тоді
     * замовлення перераховується), а не «половину чека». Коли така потреба
     * зʼявиться, це буде окрема дія з вибором рядків, а не прапорець тут.
     *
     * Повернення йде ТИМ САМИМ маршрутом і до ТОГО САМОГО постачальника, що й
     * продаж: чек, пробитий у одному ПРРО, іншим не повернеш. Тому і провайдер,
     * і маршрут беремо з самого чека, а не з поточних налаштувань — інакше
     * зміна постачальника ламала б повернення по всіх старих продажах.
     */
    public static function refund(array $receipt, ?int $userId = null, string $cashier = ''): array
    {
        if ((string)$receipt['type'] !== 'sell' || (string)$receipt['status'] !== 'done') {
            return self::no('Повертати можна лише пробитий чек продажу.');
        }
        if (self::refunded((int)$receipt['id'])) {
            return self::no('Повернення на цей чек уже проведено.');
        }
        $child = OrderFlow::order((int)$receipt['order_id']);
        $parent = $child ? OrderFlow::head($child) : null;
        if (!$child || !$parent) return self::no('Замовлення не знайдено.');

        // Рядки беремо з того, що надсилали, а не збираємо заново: позиції
        // могли передати в інший магазин або перерахувати після продажу, і
        // повернення мусить бути дзеркалом чека, а не поточного стану бази.
        $sold = json_decode((string)$receipt['doc'], true);
        $rows = is_array($sold) ? ($sold['rows'] ?? null) : null;
        if (!is_array($rows) || !$rows) {
            $built = self::rows((int)$child['id'], $child['store_id'] ? (int)$child['store_id'] : null,
                (float)$child['discount']);
            $rows = $built['rows'];
        }
        if (!$rows) return self::no('Немає рядків для повернення.');

        $payType = (int)$receipt['pay_type'];
        $sum = round((float)$receipt['sum'], 2);
        $doc = [
            'task' => 'return',
            'cashier' => self::cashier($cashier, $receipt['store_id'] ? (int)$receipt['store_id'] : null),
            'sum' => round((float)($sold['sum'] ?? $sum), 2),
            'round' => round((float)($sold['round'] ?? 0), 2),
            'comment_down' => 'Повернення за чеком ' . (string)$receipt['fiscal_number'],
            'rows' => $rows,
            'pays' => [['type' => $payType, 'sum' => $sum]],
            'customer' => self::customer($parent),
        ];

        return self::send([
            'order_id' => (int)$child['id'], 'parent_id' => (int)$parent['id'],
            'store_id' => $receipt['store_id'] ? (int)$receipt['store_id'] : null,
            'type' => 'return', 'task' => 'return', 'of_receipt_id' => (int)$receipt['id'],
            'sum' => $sum, 'pay_type' => $payType, 'change' => 0,
        ], $doc, $userId, [
            'provider' => (string)$receipt['provider'],
            'route' => (string)$receipt['route'],
        ]);
    }

    // ────────────────────────────────────────────────────────────────── відправка

    /**
     * Записати намір і — залежно від маршруту — або надіслати самим, або
     * лишити в черзі тому, у кого лежить ключ.
     *
     * Рядок у базі зʼявляється ДО будь-якої відправки й одразу з власним tag.
     * Це не облік заради обліку: якщо відповідь не дійде, у нас лишиться і те,
     * що ми надсилали, і мітка, за якою ПРРО впізнає повтор. Порядок навпаки
     * («спершу спитаємо, потім запишемо») втрачає саме ті чеки, заради яких усе
     * це й потрібно.
     *
     * @param array $force провайдер і маршрут із наявного чека (для повернень)
     */
    private static function send(array $meta, array $doc, ?int $userId, array $force = []): array
    {
        $route = FiscalProvider::route($meta['store_id'] ?? null, $userId);
        if ($force) {
            // Повернення йде туди ж, куди пішов продаж, — навіть якщо відтоді
            // маршрут чи постачальника змінили.
            $route['provider'] = $force['provider'] ?: $route['provider'];
            $route['route'] = $force['route'] ?: $route['route'];
            $route['class'] = FiscalProvider::docClass($route['provider']) ?: $route['class'];
        }
        $cls = (string)$route['class'];
        if ($cls === '' || !class_exists($cls)) {
            return self::no('Постачальника «' . $route['provider'] . '» система не знає.');
        }

        $tag = bin2hex(random_bytes(16));
        $doc['tag'] = $tag;
        $body = $cls::body($doc, $route);
        if (!$body) return self::no('Завдання «' . ($doc['task'] ?? '?') . '» цей постачальник не підтримує.');

        $cloud = $route['route'] === 'cloud';
        $id = DB::insert('fiscal_receipts', $meta + [
            'provider' => $route['provider'], 'route' => $route['route'],
            'tag' => $tag,
            'doc' => json_encode($doc, JSON_UNESCAPED_UNICODE),
            'payload' => json_encode($body, JSON_UNESCAPED_UNICODE),
            'status' => $cloud ? 'pending' : 'queued',
            'attempts' => 0, 'error' => null,
            'created_by_user_id' => $userId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        if (!$cloud) {
            // Ключ у магазині — до каси піде агент точки або браузер продавця.
            // Нам лишається сказати про це вголос, а не вдавати помилку.
            $row = self::byId((int)$id);
            return ['ok' => true, 'state' => 'queued', 'error' => '', 'receipt' => $row];
        }

        return self::applyRaw((int)$id, Vchasno::exec($body, $meta['store_id'] ?? null), $userId);
    }

    /**
     * Повторити чек — тим самим tag і тим самим тілом.
     *
     * Саме тут окупається збережений payload: ПРРО зіставляє повтор із першою
     * спробою за tag і, якщо чек тоді таки пробився, віддає його ж. Зібрати
     * тіло заново було б помилкою: позиції могли змінитись, і «той самий»
     * запит перестав би бути тим самим.
     */
    public static function retry(array $receipt, ?int $userId = null): array
    {
        if ((string)$receipt['status'] === 'done') {
            return ['ok' => true, 'state' => 'done', 'error' => '', 'receipt' => $receipt];
        }
        $body = json_decode((string)$receipt['payload'], true);
        if (!is_array($body) || !$body) {
            return self::no('Запит не збережено — повторити нічим. Пробийте чек заново.');
        }
        if ((string)$receipt['route'] !== 'cloud') {
            // До каси ходить не наш сервер — просто повертаємо завдання в чергу
            DB::update('fiscal_receipts', ['status' => 'queued', 'updated_at' => now()],
                'id = ?', [(int)$receipt['id']]);
            return ['ok' => true, 'state' => 'queued', 'error' => '', 'receipt' => self::byId((int)$receipt['id'])];
        }
        return self::applyRaw((int)$receipt['id'],
            Vchasno::exec($body, $receipt['store_id'] ? (int)$receipt['store_id'] : null), $userId);
    }

    /**
     * Розкласти відповідь ПРРО по рядку чека.
     *
     * Сюди приходить СИРА відповідь — і від нашого запиту в хмару, і від
     * агента, і від браузера. Порожній масив означає «відповіді не було».
     * Розбирає її перекладач постачальника: формат знає він, а не ми.
     */
    public static function applyRaw(int $id, array $raw, ?int $userId = null): array
    {
        $receipt = self::byId($id);
        if (!$receipt) return self::no('Чек зник із бази.');
        if ((string)$receipt['status'] === 'done') {
            // Могло прийти двічі: агент устиг надіслати, і браузер теж. Другий
            // раз нічого не міняємо — чек уже є.
            return ['ok' => true, 'state' => 'done', 'error' => '', 'receipt' => $receipt];
        }

        $cls = FiscalProvider::docClass((string)$receipt['provider']);
        $parsed = $cls !== '' && class_exists($cls)
            ? $cls::parse($raw)
            : ['ok' => false, 'error' => 'Невідомий постачальник', 'res' => 1, 'receipt' => []];

        $attempts = (int)$receipt['attempts'] + 1;

        // Службове завдання не має фіскального номера: Z-звіт і внесення нічого
        // не «пробивають». Тут успіх — це просто «каса зробила», і те, що вона
        // повернула (готівка в скриньці, номер зміни), лишаємо як є.
        if ((string)$receipt['type'] === 'service') {
            if ($parsed['ok']) {
                DB::update('fiscal_receipts', [
                    'status' => 'done', 'error' => null, 'attempts' => $attempts,
                    'result' => json_encode((array)($raw['info'] ?? []), JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                ], 'id = ?', [$id]);
                return ['ok' => true, 'state' => 'done', 'error' => '', 'receipt' => self::byId($id)];
            }
            $unclear = !$raw || (int)$parsed['res'] < 0;
            DB::update('fiscal_receipts', [
                'status' => $unclear ? 'pending' : 'error',
                'error' => mb_substr($parsed['error'] ?: 'Каса не відповіла', 0, 500),
                'attempts' => $attempts, 'updated_at' => now(),
            ], 'id = ?', [$id]);
            return ['ok' => false, 'state' => $unclear ? 'pending' : 'error',
                    'error' => $parsed['error'], 'receipt' => self::byId($id)];
        }

        if ($parsed['ok'] && $parsed['receipt']) {
            $r = $parsed['receipt'];
            DB::update('fiscal_receipts', [
                'status' => 'done',
                'fiscal_number' => (string)$r['fiscal_number'],
                'rro_number' => (string)$r['rro_number'],
                'shift_link' => $r['shift_link'],
                'doc_no' => $r['doc_no'],
                'receipt_dt' => (string)$r['dt'],
                'qr' => (string)$r['qr'],
                'cancel_id' => (string)$r['cancel_id'],
                'is_offline' => $r['is_offline'] ? 1 : 0,
                'is_test' => $r['is_test'] ? 1 : 0,
                'error' => null, 'attempts' => $attempts, 'updated_at' => now(),
            ], 'id = ?', [$id]);
            $fresh = self::byId($id);
            self::logToOrder($fresh, $userId);
            return ['ok' => true, 'state' => 'done', 'error' => '', 'receipt' => $fresh];
        }

        // «Все добре» без фіскального номера — теж невідомість, а не помилка:
        // чек міг пробитись, а номер загубитись дорогою. Списати таке в
        // «не вийшло» означало б пробити другий чек на той самий продаж.
        $unclear = !$raw || (int)$parsed['res'] < 0 || $parsed['ok'];
        DB::update('fiscal_receipts', [
            'status' => $unclear ? 'pending' : 'error',
            'error' => mb_substr($parsed['error'] ?: 'Каса не відповіла', 0, 500),
            'attempts' => $attempts, 'updated_at' => now(),
        ], 'id = ?', [$id]);
        $fresh = self::byId($id);
        self::alert($fresh, $unclear);
        return ['ok' => false, 'state' => $unclear ? 'pending' : 'error',
                'error' => $parsed['error'], 'receipt' => $fresh];
    }

    // ────────────────────────────────────────────────────── службові завдання

    /**
     * Відкрити зміну, зняти звіт, внести чи видати готівку.
     *
     * Ідуть тією самою чергою, що й чеки, і з тієї самої причини: коли ключ
     * лежить у магазині, наш сервер до каси не дістанеться ніколи — ні по чек,
     * ні по Z-звіт. А Z-звіт закон вимагає щодоби, тож «зробіть це руками на
     * касовому ПК» не відповідь: людина поїде додому й забуде.
     *
     * @param string $task shift_open|shift_close|x_report|cash_in|cash_out
     * @return array{ok:bool,state:string,error:string,receipt:?array}
     */
    public static function service(string $task, ?int $storeId, ?int $userId = null, array $extra = []): array
    {
        $allowed = ['shift_open', 'shift_close', 'x_report', 'cash_in', 'cash_out'];
        if (!in_array($task, $allowed, true)) return self::no('Невідоме службове завдання.');

        $route = FiscalProvider::route($storeId, $userId);
        $gaps = FiscalProvider::missing($route);
        if ($gaps) return self::no('Каса недоступна: ' . implode('; ', $gaps) . '.');

        $doc = ['task' => $task, 'cashier' => self::cashier($extra['cashier'] ?? null, $storeId)];
        if ($task === 'cash_in' || $task === 'cash_out') {
            $sum = round((float)($extra['sum'] ?? 0), 2);
            if ($sum <= 0) return self::no('Вкажіть суму більшу за нуль.');
            $doc['cash'] = ['type' => 0, 'sum' => $sum, 'comment' => (string)($extra['comment'] ?? '')];
        }

        return self::send([
            // Службове завдання не належить замовленню — воно належить касі.
            'order_id' => 0, 'parent_id' => 0, 'store_id' => $storeId,
            'type' => 'service', 'task' => $task, 'of_receipt_id' => null,
            'sum' => round((float)($extra['sum'] ?? 0), 2), 'pay_type' => 0, 'change' => 0,
        ], $doc, $userId);
    }

    /** Службові завдання каси — для сторінки каси: що чекає й що вийшло */
    public static function serviceLog(?int $storeId, int $limit = 10): array
    {
        $where = "type = 'service'" . ($storeId ? ' AND store_id = ?' : ' AND store_id IS NULL');
        return DB::all("SELECT * FROM fiscal_receipts WHERE $where ORDER BY id DESC LIMIT " . max(1, $limit),
                       $storeId ? [$storeId] : []);
    }

    // ──────────────────────────────────────────────────────────────── черга

    /**
     * Завдання для того, у кого лежить ключ: агента точки або браузера продавця.
     *
     * Віддаємо готове тіло, адресу й таймаут — той, хто це виконує, не має
     * знати ні про постачальника, ні про формат чека. Його робота — донести
     * запит до каси й повернути відповідь як є.
     *
     * @return array{id:int,tag:string,url:string,timeout:int,body:array}
     */
    public static function job(array $receipt): array
    {
        $route = FiscalProvider::route(
            $receipt['store_id'] ? (int)$receipt['store_id'] : null,
            $receipt['created_by_user_id'] ? (int)$receipt['created_by_user_id'] : null);
        $cls = FiscalProvider::docClass((string)$receipt['provider']);
        $url = $cls !== '' && class_exists($cls) ? $cls::url($route) : '';
        return [
            'id' => (int)$receipt['id'],
            'tag' => (string)$receipt['tag'],
            'url' => $url,
            // Каса при проблемах із ДПС чи АЦСК не відмовляє, а дотискає запит;
            // їхня документація радить не менше 20 секунд. Коротший таймаут
            // перетворив би вдалу фіскалізацію на «звʼязку немає».
            'timeout' => 25,
            'body' => json_decode((string)$receipt['payload'], true) ?: [],
        ];
    }

    /**
     * Забрати завдання для точки й позначити, що вони пішли.
     *
     * Позначаємо ДО виконання: якщо агент не повернеться, завдання висітиме в
     * «надіслано» рівно STALE_MINUTES, після чого повернеться в чергу
     * (requeueStale). Тримати його в черзі весь час, поки агент працює, не
     * можна — другий агент забрав би те саме.
     *
     * @return array список завдань
     */
    public static function takeForStore(int $storeId, int $limit = 5): array
    {
        $rows = DB::all("SELECT * FROM fiscal_receipts
                         WHERE status = 'queued' AND route = 'agent' AND store_id = ?
                         ORDER BY id LIMIT " . max(1, min(20, $limit)), [$storeId]);
        $jobs = [];
        foreach ($rows as $r) {
            DB::update('fiscal_receipts',
                ['status' => 'pending', 'attempts' => (int)$r['attempts'] + 1, 'updated_at' => now()],
                'id = ?', [(int)$r['id']]);
            $jobs[] = self::job($r);
        }
        return $jobs;
    }

    /** Завдання цієї людини, які має виконати її браузер (маршрут «на цьому пристрої») */
    public static function queuedForUser(int $userId, ?int $parentId = null): array
    {
        // ПРРО ДПС тут не рахується: його документи підписує вкладка з ключем
        // (DpsFlow::jobsFor), а не бігунок Device Manager
        $where = "status = 'queued' AND route = 'device' AND provider <> 'dps' AND created_by_user_id = ?";
        $args = [$userId];
        if ($parentId) { $where .= ' AND parent_id = ?'; $args[] = $parentId; }
        return DB::all("SELECT * FROM fiscal_receipts WHERE $where ORDER BY id", $args);
    }

    // ─────────────────────────────────────────────────────────────── дрібниці

    private static function no(string $error): array
    {
        return ['ok' => false, 'state' => 'error', 'error' => $error, 'receipt' => null];
    }

    /** Куди надіслати покупцю посилання на чек — якщо є куди й якщо ввімкнено */
    private static function customer(array $parent): array
    {
        if (!Settings::bool('vchasno_send_link', true)) return [];
        $out = [];
        $email = trim((string)($parent['email'] ?? ''));
        // Службова пошта офлайн-акаунта нікому не належить — лист у нікуди
        if ($email !== '' && !str_ends_with($email, '@offline.local')
            && filter_var($email, FILTER_VALIDATE_EMAIL)) $out['email'] = $email;
        $phone = AuthTokens::normPhoneAny((string)($parent['phone'] ?? ''));
        if ($phone) $out['phone'] = $phone;
        return $out;
    }

    /** Надіслати покупцю посилання на вже пробитий чек (просить пізніше — «загубив») */
    public static function sendLink(array $receipt, string $channel, string $recipient): array
    {
        if ((string)$receipt['status'] !== 'done' || (string)$receipt['fiscal_number'] === '') {
            return ['ok' => false, 'error' => 'Чек ще не пробито — надсилати нічого.'];
        }
        // Це вміє лише хмара постачальника: у Device Manager чек надсилає сам
        // ПРРО в мить фіскалізації, і пізніше попросити його про це нема як.
        if (!Vchasno::enabled($receipt['store_id'] ? (int)$receipt['store_id'] : null)) {
            return ['ok' => false, 'error' => 'Надіслати чек повторно вміє лише хмарна каса. '
                . 'Дайте покупцю посилання на електронний чек — воно поруч.'];
        }
        $r = Vchasno::sendLink((string)$receipt['fiscal_number'], $channel, $recipient,
            $receipt['store_id'] ? (int)$receipt['store_id'] : null);
        return ['ok' => $r['ok'], 'error' => $r['error']];
    }

    // ─────────────────────────────────────────────────────────────── наслідки

    /** Запис в історію замовлення: чек — така сама подія, як накладна чи статус */
    private static function logToOrder(array $receipt, ?int $userId): void
    {
        $store = $receipt['store_id']
            ? (string)(DB::val('SELECT name FROM stores WHERE id = ?', [(int)$receipt['store_id']]) ?? '')
            : '';
        $what = $receipt['type'] === 'return' ? 'чек повернення' : 'фіскальний чек';
        OrderFlow::log((int)$receipt['parent_id'], (int)$receipt['order_id'], 'fiscal',
            ($store !== '' ? $store . ': ' : '') . $what . ' ' . $receipt['fiscal_number']
            . ' на ' . price_fmt((float)$receipt['sum'])
            . (!empty($receipt['is_test']) ? ' (ТЕСТОВА каса — чек без сили)' : '')
            . (!empty($receipt['is_offline']) ? ' (проведено офлайн, піде в ДПС при зв’язку)' : ''),
            $userId);
    }

    /**
     * Сказати вголос, що чека немає.
     *
     * Непробитий чек — це не «щось не зберіглось»: покупець уже пішов із
     * товаром, а продаж не потрапив у ДПС. Мовчазна помилка тут коштує штрафу,
     * тому вона йде і в історію замовлення, і в сповіщення.
     */
    private static function alert(array $receipt, bool $unclear): void
    {
        $order = OrderFlow::order((int)$receipt['order_id']);
        $number = (string)($order['number'] ?? $receipt['order_id']);
        $text = $unclear
            ? 'каса не відповіла, чек ' . ($receipt['type'] === 'return' ? 'повернення ' : '')
              . 'у невідомому стані — перепитаємо автоматично'
            : 'чек не пробито: ' . (string)$receipt['error'];
        OrderFlow::log((int)$receipt['parent_id'], (int)$receipt['order_id'], 'fiscal', $text,
            $receipt['created_by_user_id'] ? (int)$receipt['created_by_user_id'] : null);

        if ($unclear) return;   // повтор іще попереду — тривожити рано
        Notify::fire('fiscal_error', [
            'number' => $number,
            'sum' => price_fmt((float)$receipt['sum']),
            'error' => (string)$receipt['error'],
            'link' => BotAuth::siteUrl() !== ''
                ? BotAuth::siteUrl() . base_url('/admin/orders/' . (int)$receipt['parent_id'])
                : '',
        ], $receipt['store_id'] ? (int)$receipt['store_id'] : null);
    }

    // ────────────────────────────────────────────────────────── автоматичний чек

    /**
     * Чек одразу після продажу на касі.
     *
     * Автоматично — лише те, за що гроші отримали при оформленні: видача з рук
     * у точці. Замовлення з каси на доставку оплатять при отриманні (і чек по
     * післяплаті пробиває перевізник), тож пробивати його тут означало б
     * фіскалізувати гроші, яких ще немає.
     *
     * Помилка чека не скасовує замовлення: товар уже віддали. Вона повертається
     * рядком, який каса покаже продавцю, і лишається кнопкою в картці.
     *
     * @return array{errors:string[],queued:int}
     */
    public static function afterPosSale(array $children, array $parent, array $pay, ?int $userId): array
    {
        if (!Settings::bool('vchasno_auto_pos', true)) return ['errors' => [], 'queued' => 0];
        $errors = []; $queued = 0;
        foreach ($children as $child) {
            $fresh = OrderFlow::order((int)$child['id']);
            if (!$fresh) continue;
            $storeId = $fresh['store_id'] ? (int)$fresh['store_id'] : null;
            // Точка без каси — не помилка: вона може працювати без ПРРО (продає
            // лише послуги, або каса ще не заведена). Мовчки пропускаємо.
            if (FiscalProvider::missing(FiscalProvider::route($storeId, $userId))) continue;
            $r = self::sell($fresh, $parent, $pay, $userId);
            if ($r['state'] === 'queued') $queued++;
            elseif (!$r['ok']) $errors[] = $r['error'];
        }
        return ['errors' => $errors, 'queued' => $queued];
    }
}
