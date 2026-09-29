<?php
declare(strict_types=1);

/**
 * Торг: «моя ціна за стільки-то штук».
 *
 * Знижки, які вже є, відповідають на питання магазину: що ми зараз продаємо
 * дешевше (акція), хто має право на знижку (промокод), скільки взяли (опт),
 * що взяли разом (набір). На питання покупця — «а за стільки віддасте?» — не
 * відповідало ніщо. Домовлялись голосом: людина писала в дірект, продавець
 * рахував у голові, ціну правили руками в замовленні. Слід від такої угоди не
 * лишався ніде, і за місяць ніхто не міг сказати, чому саме цей мед пішов по
 * 480.
 *
 * Тут той самий діалог, але записаний. Один рядок offers — одна розмова про
 * одну позицію, offer_rounds — усі її ходи. Розмова закінчується одним із
 * трьох: погодились (і тоді ціна стає справжньою — з нею можна оформити
 * замовлення), відмовились, або протермінувалась.
 *
 * Три межі роблять із торгу торг, а не спосіб отримати знижку задарма:
 *
 * — Ціна нижча за підлогу (offers_min_percent від вітринної) не доходить до
 *   продавця взагалі. Це не бізнес-правило, а фільтр від «мед за 10 грн»:
 *   такі пропозиції не обговорюють, їх видаляють, і десять таких у черзі
 *   ховають одинадцяту, справжню.
 *
 * — Погоджена ціна живе offers_hold_hours годин. Домовленість — це не нова
 *   ціна товару назавжди; вона про сьогодні, про цю кількість і про цю людину.
 *
 * — Ходів не більше MAX_ROUNDS. Після них лишається погодитись або
 *   відмовитись: коло, яке не закривається, — це не переговори.
 *
 * Домовлена ціна не складається ні з чим. Акція, опт, набір і промокод на таку
 * позицію не діють — не через жадібність, а тому що продавець назвав кінцеве
 * число, дивлячись на кількість і на цю людину. Відняти від нього ще десять
 * відсотків означало б, що названа ціна нічого не означала.
 */
class Offers
{
    /**
     * Скільки ходів витримує розмова. Шість — це тричі кожній стороні:
     * запит, зустрічна, уточнення, зустрічна, останнє слово, відповідь.
     * Далі торг перестає бути торгом і стає перевіркою, хто перший втомиться.
     */
    public const MAX_ROUNDS = 6;

    /** Скільки днів чекає пропозиція, до якої продавець не дійшов */
    public const OPEN_DAYS = 14;

    /** Скільки годин діє погоджена ціна, коли не налаштовано інакше */
    public const DEFAULT_HOLD_HOURS = 48;

    /**
     * За скільки годин обіцяємо відповісти.
     *
     * Це число робить дві роботи одночасно, і саме тому воно одне. Покупець
     * бачить його у формі як обіцянку — «відповідаємо протягом доби», — а
     * продавець отримує по ньому нагадування, що пропозиція чекає. Розведи їх
     * на два налаштування, і одне з них рано чи пізно почне брехати.
     *
     * Доба, а не година: торг веде жива людина, у якої є ще й магазин. Обіцяне
     * «за годину» ламається першим же вихідним, а обіцяне й дотримане «за добу»
     * коштує дорожче за швидкість без обіцянки.
     */
    public const DEFAULT_REPLY_HOURS = 24;

    /**
     * Підлога торгу за замовчуванням, % від вітринної ціни.
     *
     * Половина — не «наша мінімальна ціна», а межа осмисленої розмови.
     * Пропозиція нижче не про ціну, вона про те, що людина не дивилась на
     * товар. Значення налаштовується; дефолт рятує від випадковості.
     */
    public const DEFAULT_MIN_PERCENT = 50.0;

    // ─────────────────────────────────────────────────────────── чи можна тут

    /** Чи торг узагалі ввімкнено в магазині */
    public static function enabled(): bool
    {
        return Settings::bool('offers_enabled', true);
    }

    /**
     * Чи можна торгуватись за цей товар.
     *
     * Вимикач у картці (bargain) — на кшталт оптового: є позиції, де торг
     * недоречний, і сказати це треба окремо від «торг вимкнено скрізь».
     * Товар без ціни («за запитом») з торгу випадає сам: немає від чого
     * відштовхуватись ні покупцю, ні підлозі.
     */
    public static function allowed(array $product, ?array $variant = null): bool
    {
        if (!self::enabled()) return false;
        if (!self::bargain($product)) return false;
        return Catalog::price($product, $variant)[0] !== null;
    }

    /** Прапорець картки. Порожній стовпець (стара база) означає «можна» */
    public static function bargain(array $product): bool
    {
        return !array_key_exists('bargain', $product)
            || $product['bargain'] === null
            || (int)$product['bargain'] === 1;
    }

    /** Найнижча ціна за штуку, яку приймає форма. null — торг тут не діє */
    public static function floor(array $product, ?array $variant = null): ?float
    {
        [$price] = Catalog::price($product, $variant);
        if ($price === null) return null;
        return round((float)$price * self::minPercent() / 100, 2);
    }

    public static function minPercent(): float
    {
        $set = trim((string)Settings::get('offers_min_percent', ''));
        return $set === '' ? self::DEFAULT_MIN_PERCENT : max(0.0, min(100.0, (float)$set));
    }

    public static function holdHours(): int
    {
        $set = (int)Settings::get('offers_hold_hours', 0);
        return $set > 0 ? $set : self::DEFAULT_HOLD_HOURS;
    }

    public static function replyHours(): int
    {
        $set = (int)Settings::get('offers_reply_hours', 0);
        return $set > 0 ? $set : self::DEFAULT_REPLY_HOURS;
    }

    /**
     * Обіцянка строку людською мовою — «протягом доби», а не «протягом 24 год».
     *
     * Обіцянка, сказана вголос до того, як людина натиснула кнопку, робить для
     * довіри більше, ніж швидкість без обіцянки: мовчання після відправленої
     * пропозиції тривожить саме тим, що незрозуміло, скільки чекати.
     */
    public static function replyPromise(): string
    {
        $h = self::replyHours();
        return match (true) {
            $h <= 1  => 'протягом години',
            $h < 24  => 'протягом ' . $h . ' ' . plural($h, 'години', 'годин', 'годин'),
            $h == 24 => 'протягом доби',
            $h < 48  => 'протягом доби-двох',
            default  => 'протягом ' . intdiv($h, 24) . ' '
                        . plural(intdiv($h, 24), 'дня', 'днів', 'днів'),
        };
    }

    /**
     * За скільки годин до кінця попереджати покупця, що ціна спливає.
     *
     * Чверть строку, але не менше години й не більше доби. Попередження за
     * фіксовані шість годин було б безглуздим при строку в чотири й запізнілим
     * при строку в тиждень: людині потрібен не проміжок, а відчуття «часу ще
     * трохи, але вже треба вирішувати».
     */
    public static function warnBeforeHours(): int
    {
        return max(1, min(24, intdiv(self::holdHours(), 4)));
    }

    // ───────────────────────────────────────────────────────────── хід покупця

    /**
     * Пропозиція покупця: перша або чергова в тій самій розмові.
     *
     * Другу розмову про ту саму позицію не заводимо. Інакше людина, якій
     * відповіли «ні», відкривала б нову гілку з тією ж ціною, а продавець
     * бачив би в черзі десять однакових рядків від одного імені й не знав би,
     * котрий із них живий.
     *
     * @return array{ok:bool, error:string, offer:?array}
     */
    public static function propose(int $productId, ?int $variantId, int $userId,
                                   int $qty, float $price, string $note = ''): array
    {
        $p = DB::row('SELECT * FROM products WHERE id = ? AND active = 1', [$productId]);
        if (!$p) return self::fail('Товар не знайдено.');

        $variantId = self::resolveVariant($productId, $variantId);
        $v = $variantId ? DB::row('SELECT * FROM product_variants WHERE id = ?', [$variantId]) : null;

        if (!self::allowed($p, $v)) return self::fail('Про ціну цього товару ми, на жаль, не торгуємось.');

        $qty = (int)$qty;
        if ($qty < 1 || $qty > Cart::MAX_QTY) return self::fail('Вкажіть кількість від 1 до ' . Cart::MAX_QTY . ' шт.');

        $price = round((float)$price, 2);
        if ($price <= 0) return self::fail('Вкажіть ціну за штуку.');

        [$list] = Catalog::price($p, $v);
        $list = (float)$list;
        // Ціна вища за вітринну — це не торг, а описка: людина або переплутала
        // «за штуку» із сумою, або вписала не те поле. Мовчки прийняти таку
        // пропозицію означало б заробити на чужій помилці.
        if ($price >= $list) {
            return self::fail('Ця ціна не нижча за нашу — цей товар можна замовити просто так, без торгу.');
        }
        // Підлогу не називаємо. Скажи ми «мінімум 300», кожна наступна
        // пропозиція була б рівно на 300, і торгу не лишилось би зовсім.
        if ($price < self::floor($p, $v)) {
            return self::fail('Ця ціна занадто низька — таких пропозицій ми не розглядаємо. '
                . 'Спробуйте ближче до нашої.');
        }

        $open = self::threadFor($productId, $variantId, $userId);

        if ($open === null) {
            $id = DB::insert('offers', [
                'product_id' => $productId, 'variant_id' => $variantId, 'user_id' => $userId,
                'qty' => $qty, 'price' => $price, 'list_price' => $list,
                'turn' => 'seller', 'status' => 'open', 'rounds' => 1,
                'expires_at' => date('Y-m-d H:i:s', strtotime('+' . self::OPEN_DAYS . ' days')),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            self::round($id, 'buyer', 'offer', $qty, $price, $note, $userId);
            self::tellStaff(self::find($id), 'Нова пропозиція ціни');
            return ['ok' => true, 'error' => '', 'offer' => self::find($id)];
        }

        if ($open['status'] !== 'open') {
            return self::fail($open['status'] === 'accepted'
                ? 'Ви вже домовились про ціну на цю позицію — вона чекає у ваших пропозиціях.'
                : 'Ця розмова вже закрита.');
        }
        if ((string)$open['turn'] === 'seller') {
            return self::fail('Ваша пропозиція вже в продавця — зачекайте, будь ласка, на відповідь.');
        }
        if ((int)$open['rounds'] >= self::MAX_ROUNDS) {
            return self::fail('Ходів у цій розмові більше немає — лишилось погодитись на умови продавця або відмовитись.');
        }

        DB::update('offers', [
            'qty' => $qty, 'price' => $price, 'turn' => 'seller',
            'rounds' => (int)$open['rounds'] + 1, 'updated_at' => now(), 'reminded_at' => null,
            // Кожен хід відсуває термін: розмова, у якій щойно говорили,
            // не має протермінуватись завтра лише тому, що почалась давно.
            'expires_at' => date('Y-m-d H:i:s', strtotime('+' . self::OPEN_DAYS . ' days')),
        ], 'id = ?', [(int)$open['id']]);
        self::round((int)$open['id'], 'buyer', 'offer', $qty, $price, $note, $userId);
        self::tellStaff(self::find((int)$open['id']), 'Покупець дав зустрічну');
        return ['ok' => true, 'error' => '', 'offer' => self::find((int)$open['id'])];
    }

    // ───────────────────────────────────────────────────────────── хід продавця

    /**
     * Зустрічні умови продавця. Кількість він теж може змінити — «за десять
     * не віддам, за тридцять віддам» це нормальна відповідь, і без права
     * назвати свою кількість вона не висловлюється.
     */
    public static function counter(int $offerId, int $qty, float $price, string $note, int $staffId): array
    {
        $o = self::find($offerId);
        if (!$o || $o['status'] !== 'open') return self::fail('Ця розмова вже закрита.');
        if ((string)$o['turn'] !== 'seller') return self::fail('Хід зараз за покупцем.');
        if ((int)$o['rounds'] >= self::MAX_ROUNDS) {
            return self::fail('Ходів більше немає — лишилось погодитись або відмовити.');
        }
        $qty = (int)$qty;
        $price = round((float)$price, 2);
        if ($qty < 1 || $qty > Cart::MAX_QTY) return self::fail('Кількість — від 1 до ' . Cart::MAX_QTY . ' шт.');
        if ($price <= 0) return self::fail('Вкажіть ціну за штуку.');

        DB::update('offers', [
            'qty' => $qty, 'price' => $price, 'turn' => 'buyer',
            'rounds' => (int)$o['rounds'] + 1, 'answered_by' => $staffId, 'updated_at' => now(), 'reminded_at' => null,
            'expires_at' => date('Y-m-d H:i:s', strtotime('+' . self::OPEN_DAYS . ' days')),
        ], 'id = ?', [$offerId]);
        self::round($offerId, 'seller', 'offer', $qty, $price, $note, $staffId);

        $o = self::find($offerId);
        self::tellBuyer($o, 'Магазин пропонує свої умови', $note);
        return ['ok' => true, 'error' => '', 'offer' => $o];
    }

    // ─────────────────────────────────────────────────────── погодження й відмова

    /**
     * «Згоден» — від того, чий хід. Погоджуються завжди на умови іншої
     * сторони, тому окремих чисел тут немає: вони вже лежать у розмові.
     *
     * $side — 'seller' або 'buyer'. Перевірка «чий хід» тут не формальність:
     * без неї покупець міг би «погодитись» на власну ж пропозицію й вийти з
     * домовленою ціною, якої ніхто не давав.
     */
    public static function accept(int $offerId, string $side, ?int $staffId = null, ?int $userId = null): array
    {
        $o = self::find($offerId);
        if (!$o || $o['status'] !== 'open') return self::fail('Ця розмова вже закрита.');
        if ((string)$o['turn'] !== $side) return self::fail('Зараз не ваш хід.');
        if ($side === 'buyer' && (int)$o['user_id'] !== (int)$userId) return self::fail('Це не ваша пропозиція.');

        DB::update('offers', [
            'status' => 'accepted', 'updated_at' => now(), 'reminded_at' => null,
            'answered_by' => $side === 'seller' ? $staffId : $o['answered_by'],
            'expires_at' => date('Y-m-d H:i:s', strtotime('+' . self::holdHours() . ' hours')),
        ], 'id = ?', [$offerId]);
        self::round($offerId, $side, 'accept', (int)$o['qty'], (float)$o['price'], '',
            $side === 'seller' ? $staffId : $userId);

        $o = self::find($offerId);
        if ($side === 'seller') self::tellBuyer($o, 'Ми погоджуємось на вашу ціну', '');
        else self::tellStaff($o, 'Покупець прийняв ваші умови');
        return ['ok' => true, 'error' => '', 'offer' => $o];
    }

    /**
     * Відмова. У продавця це «ні» (declined), у покупця — «передумав»
     * (cancelled): для черги це різні факти, і зводити їх в один статус
     * означало б втратити відповідь на питання, чому розмов стало менше.
     */
    public static function decline(int $offerId, string $side, string $note = '',
                                   ?int $staffId = null, ?int $userId = null): array
    {
        $o = self::find($offerId);
        if (!$o || !in_array($o['status'], ['open', 'accepted'], true)) return self::fail('Ця розмова вже закрита.');
        if ($side === 'buyer' && (int)$o['user_id'] !== (int)$userId) return self::fail('Це не ваша пропозиція.');

        DB::update('offers', [
            'status' => $side === 'seller' ? 'declined' : 'cancelled',
            'turn' => $side === 'seller' ? 'buyer' : 'seller',
            'answered_by' => $side === 'seller' ? $staffId : $o['answered_by'],
            'updated_at' => now(),
        ], 'id = ?', [$offerId]);
        self::round($offerId, $side, 'decline', null, null, $note,
            $side === 'seller' ? $staffId : $userId);

        $o = self::find($offerId);
        if ($side === 'seller') self::tellBuyer($o, 'На жаль, цього разу не вийде', $note);
        return ['ok' => true, 'error' => '', 'offer' => $o];
    }

    // ──────────────────────────────────────────────────────────────── читання

    public static function find(int $id): ?array
    {
        return DB::row('SELECT * FROM offers WHERE id = ?', [$id]);
    }

    /** Ходи розмови, найдавніші згори — читається як листування */
    public static function rounds(int $offerId): array
    {
        return DB::all('SELECT * FROM offer_rounds WHERE offer_id = ? ORDER BY id', [$offerId]);
    }

    /**
     * Жива розмова цієї людини про цю позицію: та, що триває, або та, у якій
     * уже домовились і ще не скористались. Саме її показує картка товару.
     */
    public static function threadFor(int $productId, ?int $variantId, ?int $userId): ?array
    {
        if (!$userId) return null;
        self::expireStale();
        [$cond, $args] = self::variantCond($variantId);
        return DB::row(
            "SELECT * FROM offers
              WHERE product_id = ? AND user_id = ? AND status IN ('open','accepted') AND $cond
           ORDER BY id DESC LIMIT 1",
            array_merge([$productId, $userId], $args));
    }

    /**
     * Живі розмови цієї людини про цей товар, розкладені по фасовках:
     * [variant_id або 0 => рядок offers].
     *
     * Одним запитом, а не по разу на фасовку: у товару їх буває десяток, а
     * картка товару відкривається на кожен перегляд.
     */
    public static function threadsForProduct(int $productId, ?int $userId): array
    {
        if (!$userId) return [];
        self::expireStale();
        $out = [];
        foreach (DB::all(
            "SELECT * FROM offers
              WHERE product_id = ? AND user_id = ? AND status IN ('open','accepted')
           ORDER BY id", [$productId, $userId]) as $o) {
            $out[(int)($o['variant_id'] ?? 0)] = $o;
        }
        return $out;
    }

    /** Кабінет покупця: усі його розмови, живі згори */
    public static function forUser(int $userId): array
    {
        self::expireStale();
        $rows = DB::all(
            "SELECT o.*, p.name AS product_name, p.slug, v.name AS variant_name
               FROM offers o
               JOIN products p ON p.id = o.product_id
          LEFT JOIN product_variants v ON v.id = o.variant_id
              WHERE o.user_id = ?
           ORDER BY CASE WHEN o.status IN ('open','accepted') THEN 0 ELSE 1 END, o.updated_at DESC, o.id DESC",
            [$userId]);
        foreach ($rows as &$r) $r['rounds_log'] = self::rounds((int)$r['id']);
        return $rows;
    }

    /**
     * Черга продавця. Спершу те, де хід за ним, — решта рядків нічого від
     * нього зараз не потребує й лише відсуває вниз ті, що потребують.
     */
    public static function queue(string $filter = 'todo'): array
    {
        self::expireStale();
        $where = match ($filter) {
            'todo'   => "o.status = 'open' AND o.turn = 'seller'",
            'wait'   => "o.status = 'open' AND o.turn = 'buyer'",
            'deals'  => "o.status IN ('accepted','ordered')",
            default  => '1 = 1',
        };
        $rows = DB::all(
            "SELECT o.*, p.name AS product_name, p.slug, v.name AS variant_name,
                    u.name AS user_name, u.phone AS user_phone, u.email AS user_email
               FROM offers o
               JOIN products p ON p.id = o.product_id
          LEFT JOIN product_variants v ON v.id = o.variant_id
          LEFT JOIN users u ON u.id = o.user_id
              WHERE $where
           ORDER BY o.updated_at DESC, o.id DESC");
        foreach ($rows as &$r) $r = self::withContext($r);
        return $rows;
    }

    /**
     * Скільки розмов чекають ходу саме цієї людини — число для шапки сайту.
     *
     * Рахуємо і «магазин відповів, ваш хід», і «домовились, лишилось замовити»:
     * обидва стани чекають дії покупця, і обидва мовчки протермінуються, якщо
     * він про них не згадає.
     */
    public static function myTurnCount(?int $userId): int
    {
        if (!$userId || !self::enabled()) return 0;
        self::expireStale();
        return (int)DB::val(
            "SELECT COUNT(*) FROM offers
              WHERE user_id = ? AND (status = 'accepted' OR (status = 'open' AND turn = 'buyer'))",
            [$userId]);
    }

    /** Скільки розмов чекають відповіді — число для меню адмінки */
    public static function todoCount(): int
    {
        if (!self::enabled()) return 0;
        self::expireStale();
        return (int)DB::val("SELECT COUNT(*) FROM offers WHERE status = 'open' AND turn = 'seller'");
    }

    /**
     * Те, без чого відповідати наосліп: що зараз коштує, що дала б автоматична
     * оптова знижка на цю ж кількість, скільки лежить на складі й хто просить.
     *
     * Оптова ціна тут головна. Продавець має бачити, що система і без нього
     * віддала б цю партію по 465, — інакше він погоджує 470 як поступку, хоча
     * насправді щойно продав дорожче за власний прайс.
     */
    private static function withContext(array $r): array
    {
        $p = DB::row('SELECT * FROM products WHERE id = ?', [(int)$r['product_id']]);
        $v = $r['variant_id'] ? DB::row('SELECT * FROM product_variants WHERE id = ?', [(int)$r['variant_id']]) : null;
        $list = $p ? Catalog::price($p, $v)[0] : null;

        $r['list_now'] = $list;
        $r['stock'] = $p ? Catalog::stock((int)$r['product_id'],
            $r['variant_id'] !== null ? (int)$r['variant_id'] : null) : 0;
        $r['cut_percent'] = ($list !== null && (float)$list > 0)
            ? round(((float)$list - (float)$r['price']) / (float)$list * 100, 1) : 0.0;
        $r['sum'] = (float)$r['price'] * (int)$r['qty'];

        // Що дав би опт на цю ж кількість — без нього поступка вимірюється
        // ні від чого
        $wholesale = ($p && $list !== null && Catalog::wholesale($p))
            ? Catalog::qtyPercent($p, (int)$r['qty']) : 0.0;
        $r['wholesale_percent'] = $wholesale;
        $r['wholesale_price'] = $wholesale > 0 && $list !== null
            ? round((float)$list * (1 - $wholesale / 100), 2) : null;

        // Хто просить. Постійний покупець і людина з вулиці — це різні розмови,
        // і різницю між ними видно лише з історії замовлень.
        $uid = (int)$r['user_id'];
        $r['buyer_orders'] = (int)DB::val(
            'SELECT COUNT(*) FROM orders WHERE user_id = ? AND parent_id IS NULL', [$uid]);
        $r['buyer_spent'] = (float)DB::val(
            "SELECT COALESCE(SUM(total), 0) FROM orders
              WHERE user_id = ? AND parent_id IS NULL AND status NOT IN ('cancelled')", [$uid]);
        $r['rounds_log'] = self::rounds((int)$r['id']);
        return $r;
    }

    // ────────────────────────────────────────────────────────── угода в кошику

    /**
     * Домовленість, з якою можна йти в кошик, — або null.
     *
     * Перевіряємо все й щоразу: угода належить цій людині, ще не використана,
     * не протермінована, а товар і фасовка досі продаються. Ціну, яку не можна
     * перевірити на місці, у кошик пускати не можна: рядок кошика живе в
     * сесії, а сесія переживає і скасування угоди, і зняття товару з продажу.
     */
    public static function deal(int $offerId, ?int $userId): ?array
    {
        if (!$offerId || !$userId) return null;
        $o = self::find($offerId);
        if (!$o || (int)$o['user_id'] !== $userId || $o['status'] !== 'accepted') return null;
        if (self::overdue($o)) return null;
        $p = DB::row('SELECT id FROM products WHERE id = ? AND active = 1', [(int)$o['product_id']]);
        if (!$p) return null;
        if ($o['variant_id'] !== null && !DB::row('SELECT 1 FROM product_variants WHERE id = ? AND active = 1',
                [(int)$o['variant_id']])) return null;
        return $o;
    }

    /** Час рядка минув. Назва навмисно не «lapsed»: так зветься статус, і це різні речі — тут ідеться про годинник, а не про долю розмови */
    public static function overdue(array $o): bool
    {
        return !empty($o['expires_at']) && strtotime((string)$o['expires_at']) < time();
    }

    /**
     * Домовленості, що пішли в замовлення. Викликається з OrderFlow::place —
     * тобто й для сайту, і для каси: угода витрачається фактом продажу, а не
     * тим, якою кнопкою його оформили.
     *
     * Повертає рядки для історії замовлення. Без них продавець, який відкриє
     * картку через тиждень, побачить ціну, що не збігається ні з прайсом, ні з
     * жодною знижкою, — і не матиме де дізнатись, звідки вона взялась.
     *
     * @param array $rows рядки Cart::detailed()
     * @return string[] пояснення, по одному на домовлену позицію
     */
    public static function consume(array $rows, int $orderId): array
    {
        $notes = [];
        foreach ($rows as $r) {
            $id = (int)($r['offer_id'] ?? 0);
            if (!$id) continue;
            $changed = DB::update('offers',
                ['status' => 'ordered', 'order_id' => $orderId, 'updated_at' => now()],
                "id = ? AND status = 'accepted'", [$id]);
            if (!$changed) continue;
            $o = self::find($id);
            if (!$o) continue;
            $notes[] = 'Домовлена ціна: ' . self::title($o) . ' — ' . self::terms($o)
                . ($o['list_price'] !== null ? ' (у прайсі ' . price_fmt($o['list_price']) . ')' : '');
        }
        return $notes;
    }

    /**
     * Закрити те, чий час вийшов. Робимо ліниво, при читанні, а не за
     * розкладом: cron на спільному хостингу є не завжди, а протермінована
     * угода, яку ще можна оформити, гірша за зайвий UPDATE.
     */
    public static function expireStale(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        self::closeOverdue();
    }

    /**
     * Власне закриття, без сторожа «раз на запит».
     *
     * Сторож належить показу сторінок: там expireStale() смикають десять разів
     * за запит, і десять однакових UPDATE поспіль ні до чого. Але робота, яка
     * вміє виконатись лише раз на процес, — погана основа для команди cron,
     * тож маршрут за розкладом ходить сюди.
     *
     * Два різні кінці, і плутати їх не можна. 'expired' — розмова згасла, не
     * дійшовши до згоди. 'lapsed' — домовились, але покупець не встиг
     * оформити. Для покупця це протилежні новини («ми так і не відповіли»
     * проти «ваша ціна більше не діє»), а після UPDATE відрізнити їх уже
     * нічим: попереднього статусу рядок не памʼятає.
     */
    private static function closeOverdue(): void
    {
        DB::query("UPDATE offers SET status = 'lapsed', updated_at = ?
                    WHERE status = 'accepted' AND expires_at IS NOT NULL AND expires_at < ?",
            [now(), now()]);
        DB::query("UPDATE offers SET status = 'expired', updated_at = ?
                    WHERE status = 'open' AND expires_at IS NOT NULL AND expires_at < ?",
            [now(), now()]);
    }

    /**
     * Прохід за розкладом: нагадати тим, від кого чекають, і сказати вголос
     * про те, що закрилось само.
     *
     * Це головна робота над торгом після того, як він запрацював. Найгірший
     * його сценарій — не «незручно відповідати», а мовчанка: людина
     * запропонувала ціну, ніхто не зайшов в адмінку, за два тижні розмова
     * тихо згасла. Покупець не отримав нічого — тобто ми запросили до розмови
     * й не прийшли. Одна відсутня функція коштує магазину менше, ніж один
     * такий випадок.
     *
     * Чотири мовчанки, які тут закриваються:
     *   — пропозиція чекає на нас довше за обіцяний строк;
     *   — наші зустрічні умови чекають на покупця так само довго;
     *   — погодженій ціні лишились години;
     *   — розмова вже закрилась сама, і про це ніхто не сказав.
     *
     * Мітки тримають кожне повідомлення одноразовим: reminded_at обнуляється
     * будь-яким ходом (після відповіді відлік починається заново), notified_at
     * не обнуляється ніколи — закриття буває один раз.
     *
     * @return array{stalled:int,waiting:int,expiring:int,closed:int}
     */
    public static function maintain(): array
    {
        if (!self::enabled()) return ['stalled' => 0, 'waiting' => 0, 'expiring' => 0, 'closed' => 0];

        $out = ['stalled' => 0, 'waiting' => 0, 'expiring' => 0, 'closed' => 0];
        $late = date('Y-m-d H:i:s', time() - self::replyHours() * 3600);

        // 1. Чекають на нас довше, ніж ми обіцяли
        foreach (DB::all("SELECT * FROM offers
                           WHERE status = 'open' AND turn = 'seller'
                             AND reminded_at IS NULL AND updated_at < ?", [$late]) as $o) {
            self::tellStaff($o, 'Нагадування: пропозиція чекає відповіді з '
                . date('d.m H:i', strtotime((string)$o['updated_at'])));
            DB::update('offers', ['reminded_at' => now()], 'id = ?', [(int)$o['id']]);
            $out['stalled']++;
        }

        // 2. Наші умови чекають на покупця. Нагадуємо один раз і без тиску:
        //    людина могла просто не побачити повідомлення.
        foreach (DB::all("SELECT * FROM offers
                           WHERE status = 'open' AND turn = 'buyer'
                             AND reminded_at IS NULL AND updated_at < ?", [$late]) as $o) {
            self::tellBuyer($o, 'Нагадуємо про наші умови — чекаємо на вашу відповідь', '');
            DB::update('offers', ['reminded_at' => now()], 'id = ?', [(int)$o['id']]);
            $out['waiting']++;
        }

        // 3. Погодженій ціні лишились години. Продаж тут уже відбувся —
        //    лишилось не дати йому згоріти на тому, що людина забула.
        $soon = date('Y-m-d H:i:s', time() + self::warnBeforeHours() * 3600);
        foreach (DB::all("SELECT * FROM offers
                           WHERE status = 'accepted' AND reminded_at IS NULL
                             AND expires_at IS NOT NULL AND expires_at < ?", [$soon]) as $o) {
            self::tellBuyer($o, 'Домовлена ціна скоро перестане діяти', '');
            DB::update('offers', ['reminded_at' => now()], 'id = ?', [(int)$o['id']]);
            $out['expiring']++;
        }

        // 4. Те, що закрилось саме. Спершу закриваємо, потім говоримо — так
        //    сюди потрапляють і рядки, які встиг погасити ліниво сам сайт.
        self::closeOverdue();
        foreach (DB::all("SELECT * FROM offers
                           WHERE status IN ('expired','lapsed') AND notified_at IS NULL") as $o) {
            self::tellBuyer($o, $o['status'] === 'lapsed'
                ? 'Строк домовленої ціни вийшов'
                // Не ховаємось за словом «протерміновано»: не відповіли ми, і
                // сказати це прямо чесніше, ніж лишити людину гадати
                : 'Ми не встигли відповісти на вашу пропозицію, вибачте', '');
            DB::update('offers', ['notified_at' => now()], 'id = ?', [(int)$o['id']]);
            $out['closed']++;
        }
        return $out;
    }

    // ────────────────────────────────────────────────────────────── допоміжне

    /** Людською мовою: «10 шт × 480 грн = 4 800 грн» */
    public static function terms(array $o): string
    {
        return (int)$o['qty'] . ' шт × ' . price_fmt($o['price'])
            . ' = ' . price_fmt((float)$o['price'] * (int)$o['qty']);
    }

    public static function statusLabel(array $o): string
    {
        return match ((string)$o['status']) {
            'open'     => (string)$o['turn'] === 'seller' ? 'Чекає на відповідь магазину' : 'Чекає на вашу відповідь',
            'accepted' => 'Ціну погоджено',
            'declined' => 'Магазин відмовив',
            'cancelled'=> 'Ви скасували',
            // Два різні кінці: у першому ми не встигли відповісти, у другому
            // домовленість була, але не дійшла до замовлення
            'expired'  => 'Ми не встигли відповісти',
            'lapsed'   => 'Строк домовленої ціни вийшов',
            'ordered'  => 'Замовлено',
            default    => (string)$o['status'],
        };
    }

    /** Назва позиції одним рядком — для листів і черги */
    public static function title(array $o): string
    {
        $name = $o['product_name'] ?? (string)DB::val('SELECT name FROM products WHERE id = ?', [(int)$o['product_id']]);
        $var = $o['variant_name'] ?? ($o['variant_id']
            ? DB::val('SELECT name FROM product_variants WHERE id = ?', [(int)$o['variant_id']]) : null);
        return (string)$name . ($var ? ', ' . $var : '');
    }

    private static function fail(string $error): array
    {
        return ['ok' => false, 'error' => $error, 'offer' => null];
    }

    /** NULL не порівнюється через `=` — та сама умова, що в StockWatch */
    private static function variantCond(?int $variantId): array
    {
        return $variantId === null ? ['variant_id IS NULL', []] : ['variant_id = ?', [$variantId]];
    }

    /**
     * Фасовку в торзі вибирає покупець, як і в кошику: ціна й наявність
     * належать їй, а не товару. Чужа або вимкнена — беремо першу активну,
     * інакше домовлятись довелось би про позицію, якої немає в продажу.
     */
    private static function resolveVariant(int $productId, ?int $variantId): ?int
    {
        if ($variantId !== null && DB::row('SELECT 1 FROM product_variants WHERE id = ? AND product_id = ? AND active = 1',
                [$variantId, $productId])) return $variantId;
        $first = DB::val('SELECT id FROM product_variants WHERE product_id = ? AND active = 1 ORDER BY sort, id LIMIT 1',
            [$productId]);
        return $first !== null ? (int)$first : null;
    }

    private static function round(int $offerId, string $side, string $action,
                                  ?int $qty, ?float $price, string $note, ?int $userId): void
    {
        DB::insert('offer_rounds', [
            'offer_id' => $offerId, 'side' => $side, 'action' => $action,
            'qty' => $qty, 'price' => $price,
            'note' => trim($note) !== '' ? mb_substr(trim($note), 0, 500) : null,
            'user_id' => $userId, 'created_at' => now(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────── сповіщення

    private static function tellStaff(?array $o, string $what): void
    {
        if (!$o) return;
        $buyer = DB::row('SELECT name, phone FROM users WHERE id = ?', [(int)$o['user_id']]);
        Notify::fire('offer_new', [
            'what' => $what,
            'product' => self::title($o),
            'terms' => self::terms($o),
            'list' => (string)price_fmt($o['list_price'] ?? 0),
            'buyer' => trim((string)($buyer['name'] ?? '')) . ' ' . (string)($buyer['phone'] ?? ''),
            'note' => self::lastNote($o),
            'link' => self::staffLink($o),
        ]);
    }

    private static function tellBuyer(?array $o, string $what, string $note): void
    {
        if (!$o) return;
        Notify::toUser((int)$o['user_id'], 'offer_reply', [
            'what' => $what,
            'product' => self::title($o),
            'terms' => self::terms($o),
            'note' => trim($note) !== '' ? 'Коментар: ' . trim($note) : '',
            'until' => $o['status'] === 'accepted' && !empty($o['expires_at'])
                ? 'Ціна діє до ' . date('d.m.Y H:i', strtotime((string)$o['expires_at'])) : '',
            'url' => self::buyerLink($o),
            'shop' => cfg('app_name'),
        ]);
    }

    /** Останній коментар у розмові — те, чого немає в цифрах */
    private static function lastNote(array $o): string
    {
        $note = DB::val('SELECT note FROM offer_rounds WHERE offer_id = ? AND note IS NOT NULL ORDER BY id DESC LIMIT 1',
            [(int)$o['id']]);
        return $note ? 'Коментар: ' . $note : '';
    }

    /**
     * Посилання просто на цю розмову, а не на розділ.
     *
     * Різниця не косметична. Сповіщення приходить у месенджер, тобто на
     * телефон, і людина відповідає з нього ж. Посилання на список означає, що
     * після тапу треба знайти потрібний рядок серед десятка інших — на
     * маленькому екрані це і є та незручність, через яку відповідь
     * відкладають «до компʼютера», а звідти — на завтра.
     *
     * Вкладку добираємо за станом: якір знайдеться лише на тій сторінці, де
     * рядок справді показаний, а показує його рівно та вкладка, до якої він
     * зараз належить.
     *
     * Порожньо на локальній машині (немає публічної адреси) — рядок зникне
     * сам, Notify::interpolate прибирає порожні підстановки.
     */
    private static function staffLink(array $o): string
    {
        $site = BotAuth::siteUrl();
        if ($site === '') return '';
        $tab = $o['status'] === 'open'
            ? ((string)$o['turn'] === 'seller' ? 'todo' : 'wait')
            : (in_array($o['status'], ['accepted', 'ordered'], true) ? 'deals' : 'all');
        return $site . '/admin/offers?tab=' . $tab . '#o' . (int)$o['id'];
    }

    private static function buyerLink(array $o): string
    {
        $site = BotAuth::siteUrl();
        return $site === '' ? '' : 'Відповісти: ' . $site . '/bargain#o' . (int)$o['id'];
    }
}
