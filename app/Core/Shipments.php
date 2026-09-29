<?php
declare(strict_types=1);

/**
 * Накладні: що саме ми відправляємо Новою Поштою і що з цього виходить.
 *
 * Накладна належить ПІДЗАМОВЛЕННЮ, а не замовленню. Кожен магазин збирає свою
 * частину й несе її у своє відділення — це різні коробки, різні дні й різні
 * номери. Одного номера на дві посилки з різних міст не буває, тож і тут його
 * немає: покупець бачить список накладних із назвою магазину біля кожної.
 *
 * NovaPoshta вміє лише говорити з API. Тут вирішується, що йому сказати:
 * звідки взяти відправника, скільки важить посилка, коли рухати статус
 * замовлення й про що повідомити покупця.
 */
class Shipments
{
    /** Куди їде посилка. Поштомат для НП — те саме відділення, тому окремо не виділяємо. */
    public const SERVICE = ['warehouse' => 'Відділення / поштомат', 'courier' => 'Курʼєром на адресу'];

    public const PAYERS = ['Recipient' => 'Отримувач', 'Sender' => 'Відправник'];

    public const PAYMENTS = ['Cash' => 'Готівкою', 'NonCash' => 'Безготівково'];

    /** Стани, у яких накладну ще має сенс перепитувати в НП */
    public const LIVE = ['new', 'transit', 'arrived'];

    // ─────────────────────────────────────────────────────────────────── читання

    public static function forOrder(int $orderId): ?array
    {
        return DB::row('SELECT * FROM shipments WHERE order_id = ? ORDER BY id DESC', [$orderId]);
    }

    /** Накладні всього замовлення: [id підзамовлення => рядок] */
    public static function forParent(int $parentId): array
    {
        $out = [];
        foreach (DB::all('SELECT * FROM shipments WHERE parent_id = ? ORDER BY id', [$parentId]) as $s) {
            $out[(int)$s['order_id']] = $s;
        }
        return $out;
    }

    /** Накладні кількох замовлень одним запитом — для списків, щоб не було N+1 */
    public static function forParents(array $parentIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $parentIds)));
        if (!$ids) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        foreach (DB::all("SELECT * FROM shipments WHERE parent_id IN ($in) ORDER BY id", $ids) as $s) {
            $out[(int)$s['parent_id']][] = $s;
        }
        return $out;
    }

    public static function byNumber(string $number): ?array
    {
        return DB::row('SELECT * FROM shipments WHERE number = ?', [trim($number)]);
    }

    public static function trackUrl(string $number): string
    {
        return NovaPoshta::TRACK_URL . rawurlencode(trim($number));
    }

    /** Підпис стану для людини: беремо свій, а не той, що НП дописує датами */
    public static function statusLabel(array $s): string
    {
        return NovaPoshta::statusLabel(
            $s['status_code'] !== null ? (int)$s['status_code'] : null,
            (string)($s['status_text'] ?? ''));
    }

    // ─────────────────────────────────────────────────────────────── відправник

    /**
     * Хто відправник для цього магазину.
     *
     * Контрагент і контактна особа — спільні: вони належать кабінету НП, а
     * кабінет (тобто API-ключ) у сайту один на всі точки. А от відділення й
     * телефон у магазину свої: посилку несуть у сусіднє відділення, а не через
     * пів країни, і дзвонити при потребі мають туди, де вона лежить. Порожні
     * поля точки добираються із загальних — щоб одну не доводилось заповнювати
     * цілком заради іншого телефону.
     */
    public static function sender(?int $storeId): array
    {
        $g = [
            'ref' => (string)Settings::get('np_sender_ref', ''),
            'contact' => (string)Settings::get('np_sender_contact_ref', ''),
            'phone' => (string)Settings::get('np_sender_phone', ''),
            'city' => (string)Settings::get('np_sender_city', ''),
            'city_ref' => (string)Settings::get('np_sender_city_ref', ''),
            'warehouse' => (string)Settings::get('np_sender_warehouse', ''),
            'warehouse_ref' => (string)Settings::get('np_sender_warehouse_ref', ''),
        ];
        if (!$storeId) return $g;

        $s = DB::row('SELECT * FROM stores WHERE id = ?', [$storeId]);
        if (!$s) return $g;
        $pick = static fn($own, string $fallback) => trim((string)$own) !== '' ? trim((string)$own) : $fallback;
        // Відділення береться разом із містом: відділення №5 у Львові при
        // «місті Києві» — це накладна в нікуди. Заповнена точка перекриває
        // загальні налаштування обома полями або жодним.
        $ownPlace = $pick($s['np_city_ref'] ?? null, '') !== '' && $pick($s['np_warehouse_ref'] ?? null, '') !== '';
        return [
            'ref' => $g['ref'],
            'contact' => $g['contact'],
            'phone' => $pick($s['np_sender_phone'] ?? null, $g['phone']),
            'city' => $ownPlace ? (string)$s['np_city'] : $g['city'],
            'city_ref' => $ownPlace ? (string)$s['np_city_ref'] : $g['city_ref'],
            'warehouse' => $ownPlace ? (string)$s['np_warehouse'] : $g['warehouse'],
            'warehouse_ref' => $ownPlace ? (string)$s['np_warehouse_ref'] : $g['warehouse_ref'],
        ];
    }

    /**
     * Чи готовий відправник — те, що налаштовується один раз і на весь сайт.
     *
     * Окремо від missing(), бо це інший рід перешкоди: решту продавець полагодить
     * сам у картці замовлення, а відправника заповнює той, хто має доступ до
     * налаштувань. Не сказати про це — лишити людину клацати по власній картці
     * в пошуках кнопки, якої там немає.
     */
    public static function senderReady(?int $storeId): bool
    {
        $s = self::sender($storeId);
        return $s['ref'] !== '' && $s['contact'] !== '' && $s['city_ref'] !== ''
            && $s['warehouse_ref'] !== '' && NovaPoshta::phone($s['phone']) !== '';
    }

    /**
     * Чого бракує, щоб створити накладну. Список, а не «так/ні»: продавцю треба
     * знати, що саме заповнити, і хто це може зробити — він сам чи адмін.
     *
     * @return string[] порожній масив = все на місці
     */
    public static function missing(array $child, array $parent): array
    {
        $out = [];
        if (!NovaPoshta::enabled()) $out[] = 'у налаштуваннях немає API-ключа Нової Пошти';

        $sender = self::sender($child['store_id'] ? (int)$child['store_id'] : null);
        if ($sender['ref'] === '') $out[] = 'не обрано відправника (Налаштування → Нова Пошта)';
        if ($sender['contact'] === '') $out[] = 'не обрано контактну особу відправника';
        if ($sender['city_ref'] === '') $out[] = 'не вказано місто відправлення';
        if ($sender['warehouse_ref'] === '') $out[] = 'не вказано відділення відправлення';
        if (NovaPoshta::phone($sender['phone']) === '') $out[] = 'не вказано телефон відправника';

        if ((string)($parent['delivery'] ?? '') !== 'np') $out[] = 'замовлення не на Нову Пошту';
        if (trim((string)($parent['city_ref'] ?? '')) === '') {
            $out[] = 'у замовленні немає міста з довідника НП — оберіть його в блоці доставки';
        }
        if (self::serviceOf($parent) === 'courier') {
            if (trim((string)($parent['np_street_ref'] ?? '')) === '') $out[] = 'не обрано вулицю доставки';
        } elseif (trim((string)($parent['np_office_ref'] ?? '')) === '') {
            $out[] = 'у замовленні немає відділення з довідника НП — оберіть його в блоці доставки';
        }
        if (NovaPoshta::phone((string)($parent['phone'] ?? '')) === '') $out[] = 'немає телефону отримувача';
        return $out;
    }

    public static function serviceOf(array $order): string
    {
        return ($order['np_type'] ?? 'warehouse') === 'courier' ? 'courier' : 'warehouse';
    }

    // ───────────────────────────────────────────────────────────── передзаповнення

    /**
     * Чим заповнити форму накладної, щоб продавцю лишалось тільки звірити.
     *
     * Вага береться з товарів, якщо вона в них проставлена; решта — типова з
     * налаштувань. Вигадувати вагу з нічого не можна: НП порахує за нею гроші.
     */
    public static function defaults(array $child, array $parent): array
    {
        $items = OrderFlow::items((int)$child['id']);
        $weight = 0.0;
        $known = false;
        foreach ($items as $it) {
            $w = self::itemWeight($it);
            if ($w > 0) { $known = true; $weight += $w * (int)$it['qty']; }
        }
        $fallback = (float)Settings::get('np_weight_default', '0.5');
        if (!$known || $weight <= 0) $weight = max(0.1, $fallback);

        $total = (float)($child['total'] ?? 0);
        return [
            'service' => self::serviceOf($parent),
            'weight' => round($weight, 2),
            'seats' => max(1, (int)Settings::get('np_seats_default', '1')),
            'description' => (string)Settings::get('np_description', 'Дитячі іграшки'),
            'cost' => round($total, 2),
            'payer' => (string)Settings::get('np_payer', 'Recipient'),
            'payment' => (string)Settings::get('np_payment', 'Cash'),
            // Післяплата — за замовчуванням на всю суму частини: замовлення без
            // передоплати саме так і возять. Продавець може обнулити поле.
            'cod' => Settings::bool('np_cod_default', false) ? round($total, 2) : 0.0,
        ];
    }

    /** Вага однієї штуки: у фасовки своя, інакше товарна */
    private static function itemWeight(array $item): float
    {
        if (!empty($item['variant_id'])) {
            $w = DB::val('SELECT weight FROM product_variants WHERE id = ?', [(int)$item['variant_id']]);
            if ($w !== null && (float)$w > 0) return (float)$w;
        }
        if (!empty($item['product_id'])) {
            $w = DB::val('SELECT weight FROM products WHERE id = ?', [(int)$item['product_id']]);
            if ($w !== null && (float)$w > 0) return (float)$w;
        }
        return 0.0;
    }

    // ──────────────────────────────────────────────────────────────── створення

    /**
     * Створити накладну через API.
     *
     * @return array{ok:bool,error:string,shipment:?array}
     */
    public static function create(array $child, array $parent, array $in, ?int $userId = null): array
    {
        if (self::forOrder((int)$child['id'])) {
            return ['ok' => false, 'error' => 'Накладна для цієї частини вже є.', 'shipment' => null];
        }
        if ($child['status'] === 'canceled') {
            return ['ok' => false, 'error' => 'Частину скасовано — накладна їй не потрібна.', 'shipment' => null];
        }
        $gaps = self::missing($child, $parent);
        if ($gaps) {
            return ['ok' => false, 'error' => 'Не вистачає даних: ' . implode('; ', $gaps) . '.', 'shipment' => null];
        }

        $sender = self::sender($child['store_id'] ? (int)$child['store_id'] : null);
        $form = self::sanitize($in, $child, $parent);

        // Отримувач має бути в довіднику НП до накладної: у полі Recipient
        // очікується Ref, а не імʼя. Повторний виклик тим самим телефоном НП
        // зводить на наявний запис сама.
        $rcp = NovaPoshta::recipient((string)$parent['name'], (string)$parent['phone']);
        if (!$rcp['ok']) return ['ok' => false, 'error' => 'Отримувач: ' . $rcp['error'], 'shipment' => null];

        if ($form['service'] === 'courier') {
            $addr = NovaPoshta::recipientAddress($rcp['ref'], (string)$parent['np_street_ref'],
                (string)($parent['np_house'] ?? ''), (string)($parent['np_flat'] ?? ''));
            if (!$addr['ok']) return ['ok' => false, 'error' => 'Адреса: ' . $addr['error'], 'shipment' => null];
            $recipientAddress = $addr['ref'];
            $service = 'WarehouseDoors';
        } else {
            $recipientAddress = (string)$parent['np_office_ref'];
            $service = 'WarehouseWarehouse';
        }

        $doc = NovaPoshta::createDocument([
            'payer' => $form['payer'],
            'payment' => $form['payment'],
            // День відправлення — сьогодні. Накладна, датована вчорашнім, у НП
            // не приймається, а майбутньою датою продавець нічого не виграє.
            'date' => date('d.m.Y'),
            'weight' => $form['weight'],
            'service' => $service,
            'seats' => $form['seats'],
            'description' => $form['description'],
            'cost' => $form['cost'],
            'sender_city' => $sender['city_ref'],
            'sender' => $sender['ref'],
            'sender_address' => $sender['warehouse_ref'],
            'sender_contact' => $sender['contact'],
            'sender_phone' => $sender['phone'],
            'city' => (string)$parent['city_ref'],
            'recipient' => $rcp['ref'],
            'recipient_address' => $recipientAddress,
            'recipient_contact' => $rcp['contact'],
            'recipient_phone' => (string)$parent['phone'],
            'cod' => $form['cod'],
        ]);
        if (!$doc['ok']) return ['ok' => false, 'error' => $doc['error'], 'shipment' => null];

        $id = DB::insert('shipments', [
            'order_id' => (int)$child['id'], 'parent_id' => (int)$parent['id'],
            'carrier' => 'np', 'number' => $doc['number'], 'doc_ref' => $doc['ref'], 'source' => 'api',
            'service' => $form['service'], 'payer' => $form['payer'], 'payment' => $form['payment'],
            'cod' => $form['cod'], 'weight' => $form['weight'], 'seats' => $form['seats'],
            'description' => $form['description'], 'cost' => $form['cost'],
            'delivery_cost' => $doc['cost'], 'estimated_at' => self::npDate($doc['date']),
            'status_code' => 1, 'status_text' => NovaPoshta::statusLabel(1), 'phase' => 'new',
            'created_by_user_id' => $userId, 'created_at' => now(),
        ]);
        $shipment = DB::row('SELECT * FROM shipments WHERE id = ?', [$id]);

        self::afterCreate($shipment, $child, $parent, $userId);
        return ['ok' => true, 'error' => '', 'shipment' => $shipment];
    }

    /**
     * Вписати номер накладної, створеної в кабінеті НП.
     *
     * Це не «гірший спосіб», а звичайний: частину посилок оформлюють просто на
     * відділенні. Трекінг далі працює однаково — для нього потрібен лише номер.
     */
    public static function attach(array $child, array $parent, string $number, ?int $userId = null): array
    {
        $number = preg_replace('/\D+/', '', $number) ?? '';
        if (strlen($number) !== 14) {
            return ['ok' => false, 'error' => 'Номер накладної — це 14 цифр. Перевірте, будь ласка.', 'shipment' => null];
        }
        if (self::forOrder((int)$child['id'])) {
            return ['ok' => false, 'error' => 'Накладна для цієї частини вже є.', 'shipment' => null];
        }
        if (self::byNumber($number)) {
            return ['ok' => false, 'error' => 'Ця накладна вже прикріплена до іншого замовлення.', 'shipment' => null];
        }

        $d = self::defaults($child, $parent);
        $id = DB::insert('shipments', [
            'order_id' => (int)$child['id'], 'parent_id' => (int)$parent['id'],
            'carrier' => 'np', 'number' => $number, 'doc_ref' => null, 'source' => 'manual',
            'service' => $d['service'], 'payer' => $d['payer'], 'payment' => $d['payment'],
            'cod' => 0, 'weight' => 0, 'seats' => 1,
            'description' => $d['description'], 'cost' => $d['cost'],
            'delivery_cost' => 0, 'estimated_at' => null,
            'status_code' => null, 'status_text' => null, 'phase' => 'new',
            'created_by_user_id' => $userId, 'created_at' => now(),
        ]);
        $shipment = DB::row('SELECT * FROM shipments WHERE id = ?', [$id]);

        // Вписаний номер нічого про себе не знає — питаємо НП одразу, щоб
        // продавець побачив, що номер робочий, а не чекав першого проходу cron.
        self::refresh([$shipment], $userId);
        $shipment = DB::row('SELECT * FROM shipments WHERE id = ?', [$id]) ?: $shipment;

        self::afterCreate($shipment, $child, $parent, $userId);
        return ['ok' => true, 'error' => '', 'shipment' => $shipment];
    }

    /** Спільне для обох способів: історія, сповіщення покупцю, статус частини */
    private static function afterCreate(array $shipment, array $child, array $parent, ?int $userId): void
    {
        $store = $child['store_id'] ? (DB::val('SELECT name FROM stores WHERE id = ?', [$child['store_id']]) ?? '') : '';
        OrderFlow::log((int)$parent['id'], (int)$child['id'], 'shipment',
            ($store !== '' ? $store . ': ' : '') . 'накладна ' . $shipment['number']
            . ($shipment['source'] === 'manual' ? ' (вписано вручну)' : '')
            . (((float)$shipment['cod']) > 0 ? ', післяплата ' . price_fmt($shipment['cod']) : ''),
            $userId);

        self::tellCustomer($shipment, $child, $parent);
        // Накладна є — отже, посилку зібрано й передають перевізнику. Статус
        // рухаємо самі: змушувати продавця робити ту саму дію двічі означає
        // рано чи пізно отримати замовлення з накладною, але «в обробці».
        if (in_array((string)$child['status'], ['new', 'processing'], true)) {
            OrderFlow::setStatus((int)$child['id'], 'shipped', $userId);
        }
    }

    /**
     * Скасувати накладну. У НП це можливо, поки посилку не прийняли; далі
     * лишається тільки відкріпити номер у себе — і саме це тут і робиться,
     * інакше замовлення назавжди лишиться з мертвим номером.
     */
    public static function remove(array $shipment, ?int $userId = null): array
    {
        $note = '';
        if ($shipment['source'] === 'api' && (string)$shipment['doc_ref'] !== '') {
            $r = NovaPoshta::deleteDocument((string)$shipment['doc_ref']);
            if (!$r['ok']) {
                // Найчастіша причина — посилку вже прийняли у відділенні.
                // Про це варто сказати вголос: відкріплений номер не скасовує
                // відправлення, і повертати посилку доведеться іншим шляхом.
                $note = ' Нова Пошта не скасувала накладну (' . $r['error']
                    . ') — номер відкріплено лише в замовленні.';
            }
        }
        DB::delete('shipments', 'id = ?', [(int)$shipment['id']]);
        OrderFlow::log((int)$shipment['parent_id'], (int)$shipment['order_id'], 'shipment',
            'накладну ' . $shipment['number'] . ' відкріплено від замовлення.' . $note, $userId);
        return ['ok' => true, 'error' => '', 'note' => trim($note)];
    }

    // ───────────────────────────────────────────────────────────────── трекінг

    /**
     * Спитати НП про стан посилок і записати те, що змінилось.
     *
     * @param array<int,array> $shipments рядки таблиці
     * @return int скільки накладних змінили стан
     */
    public static function refresh(array $shipments, ?int $userId = null): int
    {
        if (!$shipments || !NovaPoshta::enabled()) return 0;

        // Телефони одним запитом: цим ходить cron по сотні накладних за раз,
        // і сто дрібних запитів заради одного поля — рівно те, з чого
        // починаються «чомусь усе гальмує»
        $ids = array_values(array_unique(array_map(fn($s) => (int)$s['parent_id'], $shipments)));
        $in = implode(',', array_fill(0, count($ids), '?'));
        $phones = [];
        foreach (DB::all("SELECT id, phone FROM orders WHERE id IN ($in)", $ids) as $o) {
            $phones[(int)$o['id']] = (string)$o['phone'];
        }

        $docs = $rows = [];
        foreach ($shipments as $s) {
            $docs[] = ['number' => (string)$s['number'], 'phone' => $phones[(int)$s['parent_id']] ?? ''];
            $rows[(string)$s['number']] = $s;
        }

        $answers = NovaPoshta::track($docs);
        $changed = 0;
        foreach ($answers as $number => $a) {
            $s = $rows[$number] ?? null;
            if (!$s) continue;
            if (self::apply($s, $a, $userId)) $changed++;
        }
        return $changed;
    }

    /** Одна відповідь НП → рядок таблиці. true, якщо стан справді змінився. */
    private static function apply(array $s, array $answer, ?int $userId): bool
    {
        $code = (int)($answer['StatusCode'] ?? 0);
        if ($code === 0) return false;
        $phase = NovaPoshta::phase($code);
        $text = (string)($answer['Status'] ?? '');

        $patch = [
            'status_code' => $code,
            'status_text' => $text,
            'phase' => $phase,
            'tracked_at' => now(),
            'updated_at' => now(),
        ];
        // Дату доставки НП уточнює дорогою — беремо свіжу, якщо вона є
        $est = self::npDate((string)($answer['ScheduledDeliveryDate'] ?? ''));
        if ($est !== null) $patch['estimated_at'] = $est;
        if ($phase === 'done' && !$s['delivered_at']) {
            $patch['delivered_at'] = self::npDate((string)($answer['RecipientDateTime'] ?? '')) ?? now();
        }
        // Вартість доставки в накладній, вписаній руками, ми не знаємо — НП знає
        $cost = (float)($answer['DocumentCost'] ?? 0);
        if ($cost > 0 && (float)$s['delivery_cost'] <= 0) $patch['delivery_cost'] = $cost;

        DB::update('shipments', $patch, 'id = ?', [(int)$s['id']]);

        $before = (string)$s['phase'];
        if ($before === $phase) return false;

        $fresh = DB::row('SELECT * FROM shipments WHERE id = ?', [(int)$s['id']]);
        if (!$fresh) return true;
        $child = OrderFlow::order((int)$s['order_id']);
        $parent = OrderFlow::order((int)$s['parent_id']);
        if (!$child || !$parent) return true;

        OrderFlow::log((int)$parent['id'], (int)$child['id'], 'shipment',
            'накладна ' . $s['number'] . ': ' . NovaPoshta::statusLabel($code, $text), $userId);

        self::tellCustomer($fresh, $child, $parent);
        self::moveOrder($fresh, $child, $userId);
        return true;
    }

    /**
     * Статус замовлення за станом посилки.
     *
     * Рухаємо лише вперед і лише те, що ще не закрите: продавець міг поставити
     * «Доставлено» руками (покупець забрав у точці), і відкочувати його рішення
     * через те, що НП ще не оновила статус, не можна.
     */
    private static function moveOrder(array $shipment, array $child, ?int $userId): void
    {
        $status = (string)$child['status'];
        if (in_array($status, ['done', 'canceled'], true)) return;

        $want = match ((string)$shipment['phase']) {
            'transit', 'arrived' => 'shipped',
            'done' => 'done',
            default => null,
        };
        if ($want === null || $want === $status) return;
        if ($want === 'shipped' && $status === 'shipped') return;
        // Покупцю про це вже сказано — конкретніше, з номером накладної й
        // посиланням на відстеження (tellCustomer вище). Друге повідомлення
        // про ту саму подію, ще й сухіше, виглядало б як збій розсилки.
        OrderFlow::setStatus((int)$child['id'], $want, $userId, false);
    }

    /**
     * Покупцю про посилку — але не про кожен чих.
     *
     * Значущих новин рівно три: накладна створена (є номер), посилка чекає у
     * відділенні, посилку отримано. «Виїхала з сортувального центру» покупець
     * подивиться в застосунку НП, якщо схоче; від нас це було б спамом.
     */
    private static function tellCustomer(array $shipment, array $child, array $parent): void
    {
        $phase = (string)$shipment['phase'];
        if (!in_array($phase, ['new', 'arrived', 'done'], true)) return;
        if ((string)($shipment['notified_phase'] ?? '') === $phase) return;

        DB::update('shipments', ['notified_phase' => $phase], 'id = ?', [(int)$shipment['id']]);

        Notify::toCustomer(
            $parent['user_id'] ? (int)$parent['user_id'] : null,
            ((string)($parent['email'] ?? '')) !== '' ? (string)$parent['email'] : null,
            'order_shipment', self::vars($shipment, $child, $parent));
    }

    /** Що побачить покупець у повідомленні */
    public static function vars(array $shipment, array $child, array $parent): array
    {
        $store = $child['store_name']
            ?? ($child['store_id'] ? (DB::val('SELECT name FROM stores WHERE id = ?', [$child['store_id']]) ?? '') : '');
        $many = count(OrderFlow::children((int)$parent['id'])) > 1;

        // Речення, а не мітка стану. Це те, з чого людина почне читати
        // повідомлення, і воно має звучати як фраза від магазину, а не як
        // значення поля в базі.
        [$status, $headline] = match ((string)$shipment['phase']) {
            'new' => ['Ваше замовлення передано Новій Пошті.', 'передано Новій Пошті'],
            'arrived' => ['Ваше замовлення прибуло у відділення Нової Пошти.', 'прибуло у відділення'],
            'done' => ['Ваше замовлення отримано. Дякуємо за довіру!', 'отримано'],
            default => [self::statusLabel($shipment), self::statusLabel($shipment)],
        };
        $est = $shipment['estimated_at'] ? date('d.m.Y', strtotime((string)$shipment['estimated_at'])) : '';
        $done = (string)$shipment['phase'] === 'done';

        return [
            'number' => (string)$parent['number'],
            'ttn' => (string)$shipment['number'],
            'status' => $status,
            // Коротка форма того самого — для теми листа, де довге речення
            // обрізається на півслові
            'headline' => $headline,
            // Магазин називаємо лише в розділеному замовленні: інакше це зайве
            // слово про те, чого покупець не помічав
            'part' => $many && $store !== '' ? 'Відправлення від магазину «' . $store . '»' : '',
            // Усе, що втратило сенс після отримання, зникає з листа. Обіцяти
            // дату доставки, просити гроші й пропонувати «відстежити» людині,
            // яка вже тримає посилку в руках, — це не увага, а неуважність.
            // Рядки несуть власний підпис і порожніють цілком: у шаблоні
            // лишається сама підстановка, тож порожня зникає разом із рядком.
            'estimated' => $est !== '' && !$done ? 'Орієнтовна дата доставки: ' . $est : '',
            'cod' => ((float)$shipment['cod']) > 0 && !$done
                ? 'До сплати при отриманні: ' . price_fmt($shipment['cod']) : '',
            'url' => $done ? '' : 'Відстежити: ' . self::trackUrl((string)$shipment['number']),
            'shop' => (string)cfg('app_name'),
        ];
    }

    /**
     * Накладні, які варто перепитати. Готові й видалені пропускаємо — їхній стан
     * уже не зміниться, а ліміт запитів у НП спільний на всіх.
     */
    public static function due(int $limit = 100): array
    {
        $in = implode(',', array_fill(0, count(self::LIVE), '?'));
        return DB::all(
            "SELECT * FROM shipments WHERE phase IN ($in) ORDER BY tracked_at IS NULL DESC, tracked_at ASC, id ASC LIMIT " . max(1, $limit),
            self::LIVE);
    }

    // ─────────────────────────────────────────────────────────────── допоміжне

    /**
     * Дані з форми → те, що можна віддати в API. Довіряти POST тут не можна
     * взагалі: вага й післяплата — це гроші, а платник і спосіб оплати мають
     * бути з відомого списку, інакше НП відмовить уже після створення отримувача.
     */
    private static function sanitize(array $in, array $child, array $parent): array
    {
        $d = self::defaults($child, $parent);
        $num = static fn($v, float $min, float $max, float $def) => max($min, min($max, (float)str_replace(',', '.', (string)($v ?? $def))));

        $payer = isset(self::PAYERS[$in['payer'] ?? '']) ? (string)$in['payer'] : $d['payer'];
        $payment = isset(self::PAYMENTS[$in['payment'] ?? '']) ? (string)$in['payment'] : $d['payment'];
        $desc = trim((string)($in['description'] ?? '')) ?: $d['description'];

        return [
            // Спосіб доставки задає замовлення, а не форма: покупець обрав
            // відділення, і міняти це на курʼєра за нього ми не будемо
            'service' => $d['service'],
            'weight' => round($num($in['weight'] ?? null, 0.1, 1000, $d['weight']), 2),
            'seats' => (int)max(1, min(50, (int)($in['seats'] ?? $d['seats']))),
            'description' => mb_substr($desc, 0, 120),
            'cost' => round($num($in['cost'] ?? null, 1, 500000, $d['cost']), 2),
            'payer' => $payer,
            'payment' => $payment,
            'cod' => round($num($in['cod'] ?? null, 0, 500000, 0), 2),
        ];
    }

    /** Дата від НП («18.08.2026» або «2026-08-18 12:00:00») у наш формат */
    private static function npDate(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') return null;
        $ts = strtotime($raw);
        if ($ts === false && preg_match('~^(\d{2})\.(\d{2})\.(\d{4})~', $raw, $m)) {
            $ts = strtotime("$m[3]-$m[2]-$m[1]");
        }
        return $ts ? date('Y-m-d H:i:s', $ts) : null;
    }
}
