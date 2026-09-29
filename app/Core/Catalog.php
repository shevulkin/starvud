<?php
declare(strict_types=1);

/** Логіка каталогу: ціни, акції, наявність, фільтри */
class Catalog
{
    /** Активні акції (кешовано на запит) */
    public static function activePromotions(): array
    {
        static $promos = null;
        if ($promos === null) {
            $today = date('Y-m-d');
            $promos = DB::all(
                "SELECT * FROM promotions WHERE active = 1
                 AND (starts_at IS NULL OR starts_at = '' OR starts_at <= ?)
                 AND (ends_at IS NULL OR ends_at = '' OR ends_at >= ?)", [$today, $today]);
        }
        return $promos;
    }

    /**
     * Найбільша знижка (%) для товару в контексті магазину (null = загальний сайт).
     *
     * $promos дозволяє передати готовий список замість запиту в базу — так само,
     * як у bannerWarning(): кеш activePromotions() живе на весь запит, і акція,
     * створена вже після першого звернення, інакше лишилась би непоміченою.
     */
    public static function promoPercent(array $product, ?int $storeId = null, ?array $promos = null): float
    {
        $best = 0.0;
        foreach ($promos ?? self::activePromotions() as $p) {
            if ($p['product_id'] && (int)$p['product_id'] !== (int)$product['id']) continue;
            // Акція на розділ дістає й підрозділи: власник, який поставив −10%
            // на «Мед», мав на увазі весь мед, а не лише те, що лишилось лежати
            // в самому розділі.
            if ($p['category_id']
                && !in_array((int)$product['category_id'], self::branchIds((int)$p['category_id']), true)) continue;
            if ($p['store_id'] && $storeId !== null && (int)$p['store_id'] !== $storeId) continue;
            if ($p['store_id'] && $storeId === null) continue; // акція конкретного магазину не діє на сайт загалом
            $best = max($best, (float)$p['percent']);
        }
        return $best;
    }

    /**
     * Чим банер обманює покупця — або null, якщо обіцянка збігається з цінами.
     *
     * Відсоток у банері нічого не рахує: у layouts/main.php він просто
     * дописується в текст смужки. Тому «−15%» угорі сайту й реальні ціни легко
     * розходяться — акцію забули створити, звузили до однієї категорії або вона
     * просто скінчилась, а смужка й далі обіцяє знижку.
     *
     * Дивимось рівно те, що бачить звичайний відвідувач каталогу: акції,
     * привʼязані до магазину, у загальний каталог не потрапляють (promoPercent),
     * тож обіцянку банера вони не виконують.
     *
     * $promos дозволяє передати готовий список замість запиту в базу — інакше
     * кеш activePromotions() не дав би перевірити більше одного розкладу за запуск.
     */
    public static function bannerWarning(?array $promos = null): ?string
    {
        if (!Settings::bool('sale_banner_active')) return null;

        $pct = (float)Settings::get('sale_banner_percent', '0');
        // Порожнє поле Settings::get віддає як '0', і банер малює «−0%» —
        // це та сама обіцянка ні про що, лише помітити її ще важче
        if ($pct <= 0) return 'Банер увімкнено, але відсоток не заданий — покупець бачить «−0%».';

        $whole = 0.0;  // акція на весь каталог
        $part  = 0.0;  // лише на категорію чи окремий товар
        foreach ($promos ?? self::activePromotions() as $p) {
            if ($p['store_id']) continue;
            $v = (float)$p['percent'];
            if ($p['category_id'] || $p['product_id']) $part = max($part, $v);
            else $whole = max($whole, $v);
        }
        if ($whole >= $pct) return null;

        $say = static fn(float $v): string => rtrim(rtrim(number_format($v, 2, ',', ''), '0'), ',');
        if ($part >= $pct) {
            return 'Банер обіцяє −' . $say($pct) . '% на всьому сайті, але така знижка діє лише на частину каталогу.';
        }
        $best = max($whole, $part);
        if ($best <= 0) {
            return 'Банер обіцяє −' . $say($pct) . '%, але жодної акції зараз немає — покупець бачить оголошення й повну ціну.';
        }
        return 'Банер обіцяє −' . $say($pct) . '%, а найбільша знижка зараз — ' . $say($best) . '%.';
    }

    /** Базова ціна з урахуванням перевизначення по магазину та варіанта */
    public static function rawPrice(array $product, ?array $variant = null, ?int $storeId = null): ?float
    {
        // 1) перевизначення по магазину (для варіанта чи товару)
        if ($storeId !== null) {
            $vid = $variant['id'] ?? null;
            $row = $vid
                ? DB::row('SELECT price FROM store_prices WHERE product_id = ? AND store_id = ? AND variant_id = ?', [$product['id'], $storeId, $vid])
                : DB::row('SELECT price FROM store_prices WHERE product_id = ? AND store_id = ? AND variant_id IS NULL', [$product['id'], $storeId]);
            if ($row && $row['price'] !== null) return (float)$row['price'];
        }
        // 2) ціна варіанта
        if ($variant && $variant['price'] !== null && $variant['price'] !== '') return (float)$variant['price'];
        // 3) базова ціна товару
        return ($product['base_price'] !== null && $product['base_price'] !== '') ? (float)$product['base_price'] : null;
    }

    /** Кінцева ціна з урахуванням акцій. Повертає [ціна, стара_ціна|null] */
    public static function price(array $product, ?array $variant = null, ?int $storeId = null): array
    {
        $raw = self::rawPrice($product, $variant, $storeId);
        if ($raw === null) return [null, null];
        $pct = self::promoPercent($product, $storeId);
        if ($pct > 0) return [round($raw * (1 - $pct / 100), 2), $raw];
        $old = ($product['old_price'] !== null && $product['old_price'] !== '' && (float)$product['old_price'] > $raw)
            ? (float)$product['old_price'] : null;
        return [$raw, $old];
    }

    // ------------------------------------------------------------------ опт

    /**
     * Стеля сумарної знижки на позицію, коли її не задали ніде, %.
     *
     * Тридцять — межа, за якою знижка перестає бути знижкою й починає бути
     * схожою на помилку в ціні. Нижче опт майже не відчувається на великих
     * партіях; вище акція, опт і промокод складаються в цифру, якої ніхто
     * не планував, — кожен ярус окремо здається невеликим. Значення можна
     * підняти будь-де; дефолт рятує від випадковості, а не від рішення.
     */
    public const DEFAULT_MAX_DISCOUNT = 30.0;

    /** @var array|null оптові шкали, кешовані на запит */
    private static ?array $qtyCache = null;

    /** Оптові шкали (кешовано на запит) */
    public static function qtyDiscounts(): array
    {
        return self::$qtyCache ??= DB::all('SELECT * FROM qty_discounts WHERE active = 1 ORDER BY min_qty, id');
    }

    /**
     * Забути все, що каталог памʼятає на час запиту: шкали, дерево розділів,
     * бренди товарів.
     *
     * Потрібно тому, хто щойно змінив саму структуру каталогу, — інакше в
     * тому самому запиті він прочитає те, що вже неправда. Адмінка після
     * збереження перенаправляє й цього не помітила б, але покладатися на
     * перенаправлення означає лишити пастку наступному виклику: кеші тут
     * повʼязані (шкала розділу шукається через дерево), тож чиститься все
     * разом — половина скинутих кешів гірша за жоден.
     */
    public static function forgetCaches(): void
    {
        self::$qtyCache = null;
        self::$catParents = null;
        self::$brandsCache = [];
        self::$imgCache = [];
    }

    /** Чи діє опт на цей товар. Немає стовпця (стара база, тест) — діє */
    public static function wholesale(array $product): bool
    {
        return !array_key_exists('wholesale', $product)
            || $product['wholesale'] === null
            || (int)$product['wholesale'] === 1;
    }

    /** Що рахувати для порогу: 'product' — усі фасовки разом, 'variant' — кожну окремо */
    public static function qtyScope(array $product): string
    {
        return ($product['qty_scope'] ?? 'product') === 'variant' ? 'variant' : 'product';
    }

    /**
     * Оптова шкала товару: рядки за зростанням порога.
     *
     * Ярусів три — товар, розділ (далі його батьківський), загальна шкала, —
     * і виграє НАЙБЛИЖЧИЙ заповнений, цілком. Не найбільший відсоток серед
     * усіх, як в акціях: там шкали накладаються на різні товари й перетин
     * випадковий, а тут ярус нижче — це навмисне уточнення яруса вище.
     * Брали б максимум — шкала розділу назавжди перебивала б шкалу товару,
     * і сказати «на цей сорт опт менший» стало б неможливо.
     *
     * Тому ж порожня шкала товару означає «як у розділі», а не «знижок
     * немає»: щоб сказати друге, є вимикач wholesale.
     */
    public static function qtyTiers(array $product): array
    {
        return self::qtyResolve($product)['tiers'];
    }

    /**
     * Те саме, але з відповіддю на «звідки взялась ця шкала»:
     * ['tiers' => [...], 'level' => 'off|product|category|global', 'category_id' => ?int].
     *
     * Потрібно адмінці: порожня шкала в картці товару означає «як у розділі»,
     * і людина мусить бачити, як саме — інакше порожнє поле читається як
     * «знижок немає», а насправді знижка є.
     */
    public static function qtyResolve(array $product): array
    {
        if (!self::wholesale($product)) return ['tiers' => [], 'level' => 'off', 'category_id' => null];
        $rows = self::qtyDiscounts();

        $sorted = static function (array $tiers): array {
            usort($tiers, static fn($a, $b) => (int)$a['min_qty'] <=> (int)$b['min_qty']);
            return $tiers;
        };
        $level = static function (callable $match) use ($rows): array {
            $out = [];
            foreach ($rows as $r) if ($match($r)) $out[] = $r;
            return $out;
        };

        $pid = (int)($product['id'] ?? 0);
        if ($pid > 0) {
            $own = $level(static fn(array $r): bool => (int)($r['product_id'] ?? 0) === $pid);
            if ($own) return ['tiers' => $sorted($own), 'level' => 'product', 'category_id' => null];
        }

        foreach (self::ancestorIds((int)($product['category_id'] ?? 0)) as $cid) {
            $cat = $level(static fn(array $r): bool =>
                (int)($r['product_id'] ?? 0) === 0 && (int)($r['category_id'] ?? 0) === $cid);
            if ($cat) return ['tiers' => $sorted($cat), 'level' => 'category', 'category_id' => $cid];
        }

        $all = $level(static fn(array $r): bool =>
            (int)($r['product_id'] ?? 0) === 0 && (int)($r['category_id'] ?? 0) === 0);
        return ['tiers' => $sorted($all), 'level' => $all ? 'global' : 'none', 'category_id' => null];
    }

    /** Скільки відсотків дає опт за таку кількість. 0 — шкала до неї ще не дійшла */
    public static function qtyPercent(array $product, int $qty): float
    {
        $best = 0.0;
        foreach (self::qtyTiers($product) as $t) {
            if ($qty >= (int)$t['min_qty']) $best = max($best, (float)$t['percent']);
        }
        return $best;
    }

    /**
     * Найближчий поріг, якого ще не досягли: ['need' => скільки ще штук,
     * 'min_qty' => поріг, 'percent' => відсоток]. null — шкали немає або
     * вона вже вичерпана.
     *
     * Потрібен покупцеві, а не розрахунку: знижка, про яку не сказали за крок
     * до неї, не працює як привід узяти більше.
     */
    public static function nextTier(array $product, int $qty): ?array
    {
        $have = self::qtyPercent($product, $qty);
        foreach (self::qtyTiers($product) as $t) {
            $min = (int)$t['min_qty'];
            if ($min > $qty && (float)$t['percent'] > $have) {
                return ['need' => $min - $qty, 'min_qty' => $min, 'percent' => (float)$t['percent']];
            }
        }
        return null;
    }

    /**
     * Стеля сумарної знижки на позицію, %. Варіація → товар → налаштування.
     * Порожнє поле означає «як ярусом вище», тому нуль тут задають явно.
     */
    public static function discountCap(array $product, ?array $variant = null): float
    {
        foreach ([$variant['max_discount'] ?? null, $product['max_discount'] ?? null] as $own) {
            if ($own !== null && $own !== '') return max(0.0, (float)$own);
        }
        $set = trim((string)Settings::get('max_discount_default', ''));
        return $set === '' ? self::DEFAULT_MAX_DISCOUNT : max(0.0, (float)$set);
    }

    /** Довідник брендів: усі або лише активні */
    public static function brands(bool $activeOnly = false): array
    {
        return DB::all('SELECT * FROM brands' . ($activeOnly ? ' WHERE active = 1' : '')
            . ' ORDER BY own DESC, sort, name');
    }

    /**
     * Бренди для панелі фільтрів: лише ті, під якими справді є активні товари,
     * зі скільки їх. Порожній бренд у фільтрі — це завжди «нічого не знайдено».
     */
    public static function filterableBrands(?int $categoryId = null): array
    {
        [$cond, $args] = self::branchSql($categoryId);
        $sql = 'SELECT b.*, COUNT(DISTINCT p.id) AS cnt
                  FROM brands b
                  JOIN product_brands pb ON pb.brand_id = b.id
                  JOIN products p ON p.id = pb.product_id AND p.active = 1'
             . ($cond ? ' AND ' . $cond : '')
             . ' WHERE b.active = 1 GROUP BY b.id ORDER BY b.own DESC, b.sort, b.name';
        return DB::all($sql, $args);
    }

    /** @var array<int,array> бренди за id товару; порожній масив — «питали, брендів немає» */
    private static array $brandsCache = [];

    /** Бренди товару (кешовано на запит). Свій — першим: із нього починається відповідь «чий це товар» */
    public static function brandsOf(array $product): array
    {
        $pid = (int)($product['id'] ?? 0);
        if (!$pid) return [];
        if (!array_key_exists($pid, self::$brandsCache)) self::preloadBrands([$product]);
        return self::$brandsCache[$pid];
    }

    /**
     * Бренди одразу для списку товарів — один запит замість запиту на картку.
     * Без цього каталог із 40 позицій робив би 40 звернень до бази (N+1).
     * Викликати перед відмальовуванням будь-якого списку карток.
     */
    public static function preloadBrands(array $products): void
    {
        $ids = [];
        foreach ($products as $p) {
            $id = (int)($p['id'] ?? 0);
            if ($id && !array_key_exists($id, self::$brandsCache)) $ids[$id] = true;
        }
        if (!$ids) return;
        $ids = array_keys($ids);
        // порожній масив наперед: товар без брендів інакше перепитувався б щоразу
        foreach ($ids as $id) self::$brandsCache[$id] = [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $rows = DB::all(
            "SELECT pb.product_id, b.* FROM product_brands pb JOIN brands b ON b.id = pb.brand_id
              WHERE pb.product_id IN ($in) ORDER BY b.own DESC, b.sort, b.name", $ids);
        foreach ($rows as $r) {
            $pid = (int)$r['product_id'];
            unset($r['product_id']);
            self::$brandsCache[$pid][] = $r;
        }
    }

    /** Один рядок довідника за id — для сторінок, де товару ще немає */
    public static function brand(int $id): ?array
    {
        return $id ? (DB::row('SELECT * FROM brands WHERE id = ?', [$id]) ?: null) : null;
    }

    /** «Beekeeper of Ukraine, Медоїжка» — для характеристик і розмітки */
    public static function brandNames(array $product): array
    {
        return array_map(fn($b) => (string)$b['name'], self::brandsOf($product));
    }

    /**
     * Назва бренду самого магазину — потрібна лише там, де рядок довідника ще
     * не заведений: перша установка й міграція старого текстового поля.
     * Далі «наше чи ні» вирішує прапорець own у самому бренді.
     */
    public static function ownBrandName(): string
    {
        $set = trim((string)Settings::get('brand_own', ''));
        return $set !== '' ? $set : (string)cfg('app_name');
    }

    /** Чи є серед брендів товару наш */
    public static function isOwnBrand(array $product): bool
    {
        foreach (self::brandsOf($product) as $b) if (!empty($b['own'])) return true;
        return false;
    }

    /** Бренди-партнери — усі, крім нашого */
    public static function partnerBrands(array $product): array
    {
        return array_values(array_filter(self::brandsOf($product), fn($b) => empty($b['own'])));
    }

    /**
     * Напис для товару, якого немає на складі, але який можна замовити.
     *
     * «Ми виробник» — твердження про походження товару, і казати його про
     * чужий воскопрес чи пошив у сторонній майстерні не можна. Для решти
     * важливо інше: товар усе одно буде, просто доведеться зачекати.
     */
    public static function madeToOrderNote(array $product): string
    {
        if (!self::isOwnBrand($product)) return 'Під замовлення — привеземо для вас';
        $partners = self::partnerBrands($product);
        if (!$partners) return 'Виготовимо під замовлення — ми виробник';
        // Спільне виробництво: назвати партнера чесніше, ніж і замовчати його,
        // і привласнити чужу роботу словом «ми виробник».
        $names = implode(' і ', array_map(fn($b) => '«' . $b['name'] . '»', $partners));
        return 'Виготовляємо разом із ' . $names . ' — під замовлення';
    }

    /** Те саме коротко — там, де напис доклеюється до іншого рядка */
    public static function madeToOrderShort(array $product): string
    {
        if (!self::isOwnBrand($product)) return 'під замовлення';
        return self::partnerBrands($product) ? 'виготовляємо спільно' : 'виготовимо під замовлення';
    }

    /** Чи має товар активні варіанти (кешовано на запит) */
    public static function hasVariants(int $productId): bool
    {
        static $cache = [];
        return $cache[$productId] ??= (bool)DB::val(
            'SELECT 1 FROM product_variants WHERE product_id = ? AND active = 1 LIMIT 1', [$productId]);
    }

    /**
     * Залишок по всіх магазинах чи конкретному.
     * Якщо в товару є варіанти — залишок беремо з них (сума по активних),
     * рядки без варіанта для такого товару ігноруємо.
     */
    public static function stock(int $productId, ?int $variantId = null, ?int $storeId = null): int
    {
        $byStore = self::stockByStore($productId, $variantId);
        return $storeId !== null ? ($byStore[$storeId] ?? 0) : array_sum($byStore);
    }

    /** Залишки товару одним запитом: [store_id][variant_id|0] = qty (лише активні варіанти) */
    public static function stockMap(int $productId): array
    {
        $rows = DB::all(
            'SELECT ss.store_id, ss.variant_id, SUM(ss.qty) AS qty
             FROM store_stock ss
             LEFT JOIN product_variants pv ON pv.id = ss.variant_id
             WHERE ss.product_id = ? AND (ss.variant_id IS NULL OR pv.active = 1)
             GROUP BY ss.store_id, ss.variant_id', [$productId]);
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r['store_id']][(int)($r['variant_id'] ?? 0)] = (int)$r['qty'];
        }
        return $out;
    }

    /**
     * Залишки по магазинах для конкретної позиції: [store_id => qty].
     * Варіант обовʼязковий для товарів з варіантами — інакше беремо рядки без варіанта.
     */
    public static function stockByStore(int $productId, ?int $variantId = null): array
    {
        $params = [$productId];
        if ($variantId !== null) {
            $sql = 'SELECT store_id, SUM(qty) AS qty FROM store_stock WHERE product_id = ? AND variant_id = ?';
            $params[] = $variantId;
        } elseif (self::hasVariants($productId)) {
            $sql = 'SELECT ss.store_id, SUM(ss.qty) AS qty FROM store_stock ss
                    JOIN product_variants pv ON pv.id = ss.variant_id AND pv.active = 1
                    WHERE ss.product_id = ?';
        } else {
            $sql = 'SELECT store_id, SUM(qty) AS qty FROM store_stock WHERE product_id = ? AND variant_id IS NULL';
        }
        $sql .= ' GROUP BY store_id';
        $out = [];
        foreach (DB::all($sql, $params) as $r) $out[(int)$r['store_id']] = (int)$r['qty'];
        return $out;
    }

    /**
     * Залишки всіх товарів по магазинах одним запитом: [product_id][store_id] = qty.
     * Для товарів з варіантами беруться рядки варіантів, для решти — рядки без варіанта.
     */
    public static function stockTotals(): array
    {
        $rows = DB::all(
            'SELECT ss.product_id, ss.store_id, SUM(ss.qty) AS qty
             FROM store_stock ss
             LEFT JOIN product_variants pv ON pv.id = ss.variant_id
             WHERE (ss.variant_id IS NULL OR pv.active = 1)
               AND (ss.variant_id IS NOT NULL
                    OR NOT EXISTS (SELECT 1 FROM product_variants v
                                   WHERE v.product_id = ss.product_id AND v.active = 1))
             GROUP BY ss.product_id, ss.store_id');
        $out = [];
        foreach ($rows as $r) $out[(int)$r['product_id']][(int)$r['store_id']] = (int)$r['qty'];
        return $out;
    }

    /** SQL-умова «є в наявності в магазині» з урахуванням варіантів (для p.id у зовнішньому запиті) */
    /**
     * Типи товару — і повний їх перелік.
     *
     * Було чотири: product, service, video, course. «Послуга» й «Відео» не
     * робили НІЧОГО: жоден рядок коду на них не дивився, товар із типом
     * «Відео» возився Новою Поштою, рахувався на складі й питав вагу. Тобто
     * обравши тип, людина нічого не вмикала — лише вірила, що ввімкнула.
     * Підпис, який нічого не означає, гірший за його відсутність.
     *
     * Лишились два, і обидва щось значать: звичайний товар і курс (див.
     * Courses — цифрова доставка, доступ після оплати, власна сторінка).
     *
     * Перелік тут один на всю систему. Доти він був переписаний у шести
     * місцях — двох перевірках при збереженні товару, перевірці категорії,
     * двох підписах у шаблонах і сідері, — і розходились вони мовчки.
     */
    public const TYPES = ['product' => 'Товар', 'course' => 'Курс'];

    /** Назва типу для людини; невідомий повертається як є */
    public static function typeLabel(string $type): string
    {
        return self::TYPES[$type] ?? $type;
    }

    /** Тип із форми: приймаємо лише відомий, решта — звичайний товар */
    public static function normType(?string $type, string $fallback = 'product'): string
    {
        return isset(self::TYPES[(string)$type]) ? (string)$type : $fallback;
    }

    /**
     * Та сама умова «є в цьому магазині» для запитів поза search().
     *
     * Один текст на обидва місця навмисно: блок порад під каталогом мусить
     * розуміти «є в наявності» так само, як сам каталог, — інакше товар, який
     * фільтр щойно прибрав, повертається порадою, і фільтр виглядає зламаним.
     * Очікує один параметр — id магазину.
     */
    public static function inStockSql(): string { return self::IN_STOCK_EXISTS; }

    private const IN_STOCK_EXISTS =
        'EXISTS (SELECT 1 FROM store_stock ss
                 LEFT JOIN product_variants pv ON pv.id = ss.variant_id
                 WHERE ss.product_id = p.id AND ss.store_id = ? AND ss.qty > 0
                   AND (ss.variant_id IS NULL OR pv.active = 1)
                   AND (ss.variant_id IS NOT NULL
                        OR NOT EXISTS (SELECT 1 FROM product_variants v
                                       WHERE v.product_id = p.id AND v.active = 1)))';

    /**
     * Активні категорії пласким списком, але в порядку дерева: підрозділ іде
     * одразу за своїм розділом і несе `depth` = 1. Так кожен випадний список
     * адмінки читається як дерево без жодної правки на місці.
     *
     * Підрозділ вимкненого розділу піднімається до верхнього рівня, а не
     * зникає: сховали «Мед» — «Липовий мед» лишається доступним, інакше
     * одна знята галка тихо ховала б півкаталогу.
     */
    /**
     * УСІ розділи, включно з курсами. Це список для адмінки: там треба
     * призначати категорію будь-якому товару, зокрема курсу.
     *
     * Вітрина натомість кличе shopCategories() — курсам у меню каталогу не
     * місце. Розділяти довелось після того, як виключення курсів, зроблене
     * заради вітрини, сховало курсові категорії ще й у формі товару: обрати
     * категорію для курсу стало неможливо взагалі.
     */
    public static function categories(): array
    {
        $rows = DB::all('SELECT * FROM categories WHERE active = 1 ORDER BY sort, id');
        $byParent = [];
        $ids = array_map(fn($r) => (int)$r['id'], $rows);
        foreach ($rows as $r) {
            $pid = (int)($r['parent_id'] ?? 0);
            $byParent[in_array($pid, $ids, true) ? $pid : 0][] = $r;
        }
        $out = [];
        foreach ($byParent[0] ?? [] as $root) {
            $root['depth'] = 0;
            $out[] = $root;
            foreach ($byParent[(int)$root['id']] ?? [] as $kid) {
                $kid['depth'] = 1;
                $out[] = $kid;
            }
        }
        return $out;
    }

    /**
     * Розділи для ВІТРИНИ — без курсів.
     *
     * Курси живуть на власній сторінці, і пункт «Курси» вже стоїть у шапці.
     * Розділ каталогу з тією ж назвою вів би в те саме місце другим шляхом, а
     * фільтри в ньому (наявність, вага, бренд) до курсу однаково не застосовні.
     */
    public static function shopCategories(): array
    {
        return array_values(array_filter(self::categories(),
            fn($c) => ($c['type'] ?? '') !== Courses::TYPE));
    }

    /** Лише верхній рівень — для місць, де дерево не розгортається (головна, добірки) */
    /**
     * Лише верхній рівень — для місць, де дерево не розгортається (головна,
     * добірки). Це теж вітрина, тож без курсів: плитка розділів на головній
     * вела б у розділ каталогу, якого для курсів не існує.
     */
    public static function rootCategories(): array
    {
        return array_values(array_filter(self::shopCategories(), fn($c) => !($c['depth'] ?? 0)));
    }

    /**
     * Категорії деревом: корені зі своїми `children`.
     * $cats — готовий список із categories(), щоб не ходити в базу вдруге.
     */
    public static function categoryTree(?array $cats = null): array
    {
        /*
         * Дерево розділів будують лише для вітрини — меню в шапці й панель
         * фільтрів. Тому за замовчуванням беремо shopCategories(), без курсів:
         * інакше «Курси» опинялись у випадному списку «Магазин» другим шляхом
         * до сторінки, яка вже є окремим пунктом меню поруч.
         */
        $cats ??= self::shopCategories();
        $tree = [];
        $pos = [];   // id кореня => його місце в $tree
        foreach ($cats as $c) {
            if (($c['depth'] ?? 0) === 0) {
                $c['children'] = [];
                $pos[(int)$c['id']] = count($tree);
                $tree[] = $c;
            } else {
                $pid = (int)($c['parent_id'] ?? 0);
                if (isset($pos[$pid])) $tree[$pos[$pid]]['children'][] = $c;
            }
        }
        return $tree;
    }

    /** @var array<int,int> id категорії => id батьківської (0 = верхній рівень) */
    private static ?array $catParents = null;

    /** Батьківські звʼязки всіх категорій одним запитом (кешовано на запит) */
    private static function catParents(): array
    {
        if (self::$catParents === null) {
            self::$catParents = [];
            foreach (DB::all('SELECT id, parent_id FROM categories') as $r) {
                self::$catParents[(int)$r['id']] = (int)($r['parent_id'] ?? 0);
            }
        }
        return self::$catParents;
    }

    /**
     * Гілка розділу: він сам і його підрозділи.
     *
     * Товар лежить рівно в одній категорії, тож «Мед» без цього показував би
     * порожню полицю, щойно власник розклав мед по підрозділах. Усе, що
     * відбирає товари категорією — каталог, фільтри, акції, каса — питає гілку.
     */
    public static function branchIds(int $id): array
    {
        $ids = [$id];
        foreach (self::catParents() as $cid => $pid) if ($pid === $id) $ids[] = $cid;
        return $ids;
    }

    /** Ланцюг угору: сама категорія, далі її розділ. Порожньо, якщо категорії немає */
    public static function ancestorIds(int $id): array
    {
        $parents = self::catParents();
        if (!isset($parents[$id])) return [];
        return $parents[$id] ? [$id, $parents[$id]] : [$id];
    }

    /** Розділ, у якому лежить підрозділ (null для верхнього рівня) */
    public static function parentCategory(?array $cat): ?array
    {
        $pid = (int)($cat['parent_id'] ?? 0);
        return $pid ? (DB::row('SELECT * FROM categories WHERE id = ?', [$pid]) ?: null) : null;
    }

    /**
     * Умова «товар усередині гілки» для WHERE: [умова, параметри].
     * Порожня категорія дає порожню умову — виклик просто нічого не додає.
     */
    public static function branchSql(?int $categoryId, string $col = 'p.category_id'): array
    {
        if (!$categoryId) return ['', []];
        $ids = self::branchIds($categoryId);
        return [$col . ' IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids];
    }

    public static function stores(): array
    {
        return DB::all('SELECT * FROM stores WHERE active = 1 ORDER BY sort, id');
    }

    /** Пошук/фільтрація товарів */
    public static function search(array $f): array
    {
        /*
         * Курси в каталог не потрапляють — у них своя сторінка.
         *
         * Річ не в дублюванні. Курс за 14 900 у ряду з банками по 180
         * оцінюється поруч із ними, а картка каталогу дає йому рівно рядок
         * опису й кнопку «До кошика» — нічим виправдати ціну. Плюс жоден
         * інструмент каталогу до нього не застосовний: ані «в наявності», ані
         * вага, ані бренд.
         *
         * Пошук по сайту курси теж не показує, і це навмисно: людина, яка шукає
         * «мед липовий», не має отримувати курс у видачі. На «Курси» ведуть
         * окремий пункт меню й окрема сторінка.
         */
        $where = ['p.active = 1', 'p.type <> ' . DB::pdo()->quote(Courses::TYPE)];
        $params = [];
        if (!empty($f['category_id'])) {
            // разом із підрозділами: обраний «Мед» показує і липовий, і гречаний
            [$cond, $args] = self::branchSql((int)$f['category_id']);
            $where[] = $cond;
            foreach ($args as $a) $params[] = $a;
        }
        if (!empty($f['q'])) {
            // seo_title — довга назва з Prom: у ній ті слова, якими батьки й
            // шукають («прорізувач», «на радіокеруванні»), тож шукаємо й по ній
            $where[] = '(p.name LIKE ? OR p.seo_title LIKE ? OR p.short_desc LIKE ? OR p.description LIKE ? OR p.sku LIKE ?)';
            $like = '%' . $f['q'] . '%';
            array_push($params, $like, $like, $like, $like, $like);
        }
        // Вік дитини — смуга ростоміра (див. Ages)
        // не empty(): смуга «0» — це «0–1 рік», а empty('0') у PHP — true
        if (isset($f['age']) && $f['age'] !== '' && Ages::band((string)$f['age'])) {
            [$cond, $args] = Ages::sql((string)$f['age']);
            $where[] = $cond;
            foreach ($args as $a) $params[] = $a;
        }
        // «Лише в наявності» — є бодай у одній точці
        if (!empty($f['instock'])) {
            $where[] = 'EXISTS (SELECT 1 FROM store_stock ss WHERE ss.product_id = p.id AND ss.qty > 0)';
        }
        if (isset($f['min']) && $f['min'] !== '') { $where[] = 'p.base_price >= ?'; $params[] = (float)$f['min']; }
        if (isset($f['max']) && $f['max'] !== '') { $where[] = 'p.base_price <= ?'; $params[] = (float)$f['max']; }
        if (!empty($f['store_id'])) {
            $where[] = self::IN_STOCK_EXISTS;
            $params[] = (int)$f['store_id'];
        }
        // Бренд: приймаємо і slug (посилання з картки товару), і id (адмінка),
        // і кілька значень одразу (галки у фільтрах) — між ними «або».
        // EXISTS, а не JOIN, — товар під двома брендами не має задвоюватись.
        $brands = array_values(array_filter(array_map('strval', (array)($f['brand'] ?? [])), fn($v) => $v !== ''));
        if ($brands) {
            $in = implode(',', array_fill(0, count($brands), '?'));
            $where[] = "EXISTS (SELECT 1 FROM product_brands pb JOIN brands b ON b.id = pb.brand_id
                                 WHERE pb.product_id = p.id AND (b.slug IN ($in) OR b.id IN ($in)))";
            foreach ($brands as $v) $params[] = $v;
            foreach ($brands as $v) $params[] = (int)$v;
        }
        if (!empty($f['attr']) && is_array($f['attr'])) {
            foreach ($f['attr'] as $slug => $values) {
                // приймаємо і один рядок (старі посилання), і масив значень
                $values = array_values(array_filter(array_map('strval', (array)$values), fn($v) => $v !== ''));
                if (!$values) continue;
                $in = implode(',', array_fill(0, count($values), '?'));
                // значення може бути як характеристикою товару, так і віссю варіанта
                // ключем може бути slug або назва — старі збережені посилання теж працюють
                $where[] = "(EXISTS (SELECT 1 FROM product_attrs pa JOIN attributes a ON a.id = pa.attribute_id
                                     WHERE pa.product_id = p.id AND (a.slug = ? OR a.name = ?) AND pa.value IN ($in))
                         OR EXISTS (SELECT 1 FROM variant_options vo
                                     JOIN product_variants pv ON pv.id = vo.variant_id AND pv.active = 1
                                     JOIN attributes a2 ON a2.id = vo.attribute_id
                                     WHERE pv.product_id = p.id AND (a2.slug = ? OR a2.name = ?) AND vo.value IN ($in)))";
                $params[] = (string)$slug; $params[] = (string)$slug;
                foreach ($values as $v) $params[] = $v;
                $params[] = (string)$slug; $params[] = (string)$slug;
                foreach ($values as $v) $params[] = $v;
            }
        }
        // Те, чого немає, — в кінець за будь-якого сортування: іграшка, яку не
        // можна купити, не має стояти першою лише тому, що вона дешевша
        $have = '(CASE WHEN EXISTS (SELECT 1 FROM store_stock s2 WHERE s2.product_id = p.id AND s2.qty > 0) OR p.made_to_order = 1 THEN 0 ELSE 1 END)';
        $order = $have . ', ' . match ($f['sort'] ?? '') {
            'price_asc' => 'p.base_price IS NULL, p.base_price ASC',
            'price_desc' => 'p.base_price IS NULL, p.base_price DESC',
            'new' => 'p.id DESC',
            default => 'p.featured DESC, p.id DESC',
        };
        $sql = 'SELECT p.* FROM products p WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . $order;
        return DB::all($sql, $params);
    }

    public static function variants(int $productId): array
    {
        // Із кешу — лише те, що підвантажив preloadCards() для списку карток.
        // Інші виклики йдуть у базу: після збереження в адмінці варіанти мають
        // бути свіжими в тому самому запиті.
        if (array_key_exists($productId, self::$variantsCache)) return self::$variantsCache[$productId];
        return DB::all('SELECT * FROM product_variants WHERE product_id = ? AND active = 1 ORDER BY sort, id', [$productId]);
    }

    /** @var array<int,array> варіанти, підвантажені для списку карток */
    private static array $variantsCache = [];

    /**
     * Усе, що потрібно карткам товарів, — кількома запитами на весь список.
     *
     * Без цього кожна картка питала базу сама: варіанти, наявність, бренди —
     * по три-чотири запити на картку, сотня на сторінку каталогу. На хостингу
     * з окремим сервером бази це сотні мілісекунд до першого байта.
     */
    public static function preloadCards(array $products): void
    {
        $ids = [];
        foreach ($products as $p) {
            $id = (int)($p['id'] ?? 0);
            if ($id && !array_key_exists($id, self::$variantsCache)) $ids[$id] = true;
        }
        self::preloadBrands($products);
        if (!$ids) return;
        $ids = array_keys($ids);
        foreach ($ids as $id) self::$variantsCache[$id] = [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        foreach (DB::all("SELECT * FROM product_variants WHERE product_id IN ($in) AND active = 1 ORDER BY sort, id", $ids) as $v) {
            self::$variantsCache[(int)$v['product_id']][] = $v;
        }
        OrderFlow::preloadSellable($ids);
    }

    public static function attrs(int $productId): array
    {
        return DB::all(
            'SELECT pa.*, a.slug AS attr_slug, a.unit, a.type AS attr_type, av.color
             FROM product_attrs pa
             LEFT JOIN attributes a ON a.id = pa.attribute_id
             LEFT JOIN attribute_values av ON av.id = pa.value_id
             WHERE pa.product_id = ? ORDER BY pa.sort, pa.id', [$productId]);
    }

    /**
     * Осі варіантів товару: [attribute_id => ['name'=>…, 'type'=>…, 'values'=>[value => ['value','color']]]].
     * Повертає порожньо, якщо варіанти не описані характеристиками (старі текстові варіанти).
     */
    public static function variantAxes(array $variants, array $optionsByVariant): array
    {
        $axes = [];
        foreach ($variants as $v) {
            $opts = $optionsByVariant[(int)$v['id']] ?? [];
            if (!$opts) return []; // хоч один варіант без характеристик — показуємо простим списком
            foreach ($opts as $o) {
                $aid = (int)$o['attribute_id'];
                $axes[$aid]['id'] = $aid;
                $axes[$aid]['name'] = $o['attr_name'];
                $axes[$aid]['type'] = $o['attr_type'];
                $axes[$aid]['values'][$o['value']] = ['value' => $o['value'], 'color' => $o['color'] ?? null];
            }
        }
        return $axes;
    }

    private static array $imgCache = [];

    /**
     * Фото товару списком. Памʼятається на час запиту: сторінка товару збирає
     * галерею окремо для кожної фасовки, а кошик — для кожного рядка, і всі
     * вони питають один і той самий список.
     */
    public static function images(int $productId): array
    {
        return self::$imgCache[$productId] ??=
            DB::all('SELECT * FROM product_images WHERE product_id = ? ORDER BY sort, id', [$productId]);
    }

    /**
     * Головне фото товару, а з фасовкою — її власне, якщо воно є.
     *
     * Фасовка без своїх кадрів показується фото товару: у кошику й на касі
     * рядок має бути впізнаваним завжди, а «немає фото саме цього кольору» —
     * причина показати загальне, а не заглушку.
     */
    public static function photo(array $product, ?array $variant = null): string
    {
        if ($variant) {
            foreach (self::images((int)$product['id']) as $im) {
                if ((int)($im['variant_id'] ?? 0) === (int)$variant['id']) return (string)$im['path'];
            }
        }
        if (!empty($product['image'])) return $product['image'];
        $img = DB::row('SELECT path FROM product_images WHERE product_id = ? ORDER BY sort, id LIMIT 1', [$product['id']]);
        return $img['path'] ?? 'img/no-photo.webp';
    }

    /**
     * Галерея товару: головне фото першим, далі додаткові в заданому порядку.
     * Без дублів; якщо фото немає взагалі — одна заглушка.
     *
     * З обраною фасовкою порядок інший: спершу її кадри, далі спільні (ті, що
     * не позначені жодною). Чужі при цьому не показуються зовсім — червона
     * шапка в галереї, поки обрано синю, це не «більше фото», а помилка.
     * Без фасовки список повний: так галерею бачить і адмінка, і той товар,
     * у якого фасовок немає.
     */
    public static function gallery(array $product, ?array $variant = null): array
    {
        $rows = self::images((int)$product['id']);
        $main = (string)($product['image'] ?? '');
        $vid  = $variant ? (int)$variant['id'] : 0;

        $own = $shared = $rest = [];
        foreach ($rows as $r) {
            $rv = (int)($r['variant_id'] ?? 0);
            if ($vid && $rv === $vid) $own[] = $r;
            elseif (!$rv)            $shared[] = $r;
            elseif (!$vid)           $rest[] = $r;
        }
        $ordered = array_merge($own, $shared, $rest);

        $out = []; $seen = [];
        // Головне фото веде галерею лише поки в обраної фасовки немає своїх
        // кадрів: інакше покупець обирає колір, а бачить загальний план.
        if (!$own && $main !== '') {
            $hit = null;
            foreach ($ordered as $r) if ($r['path'] === $main) { $hit = $r; break; }
            // головне фото могли призначити поза списком (банер, галерея
            // сайту) — воно нічиє, тож підходить будь-якій фасовці
            if (!$hit && !in_array($main, array_column($rows, 'path'), true)) {
                $hit = ['id' => 0, 'path' => $main, 'variant_id' => null, 'width' => 0, 'height' => 0, 'bytes' => 0];
            }
            if ($hit) { $out[] = $hit; $seen[$main] = true; }
        }
        foreach ($ordered as $r) {
            if (isset($seen[$r['path']])) continue;
            $seen[$r['path']] = true;
            $out[] = $r;
        }
        // Фасовка без своїх кадрів і без спільних (усі фото розібрані по інших)
        // показує головне фото товару: воно принаймні про цей товар, а заглушка
        // ні про що.
        if (!$out && $rows) $out[] = $rows[0];
        if (!$out) $out[] = ['id' => 0, 'path' => 'img/no-photo.webp', 'variant_id' => null, 'width' => 0, 'height' => 0, 'bytes' => 0];
        return $out;
    }

    /**
     * Характеристики для фільтрів: лише ті, що позначені у словнику й реально
     * зустрічаються серед активних товарів категорії. Значення — з кількістю товарів.
     * [['id','name','slug','unit','type','values'=>[['value','color','count'], …]], …]
     */
    public static function filterableAttrs(?int $categoryId): array
    {
        // гілка, а не одна категорія: у розділі з підрозділами фільтри мають
        // описувати те саме, що показано на полиці
        [$cond, $params] = self::branchSql($categoryId);
        $catSql = $cond ? ' AND ' . $cond : '';

        // значення з характеристик товару
        $rows = DB::all(
            'SELECT a.id, a.name, a.slug, a.unit, a.type, a.sort, pa.value, av.color, COUNT(DISTINCT p.id) AS cnt
             FROM product_attrs pa
             JOIN attributes a ON a.id = pa.attribute_id AND a.filterable = 1 AND a.active = 1
             JOIN products p ON p.id = pa.product_id AND p.active = 1
             LEFT JOIN attribute_values av ON av.id = pa.value_id
             WHERE 1=1' . $catSql . '
             GROUP BY a.id, a.name, a.slug, a.unit, a.type, a.sort, pa.value, av.color', $params);

        // значення, що є осями варіантів (розмір, колір)
        $rows = array_merge($rows, DB::all(
            'SELECT a.id, a.name, a.slug, a.unit, a.type, a.sort, vo.value, av.color, COUNT(DISTINCT p.id) AS cnt
             FROM variant_options vo
             JOIN attributes a ON a.id = vo.attribute_id AND a.filterable = 1 AND a.active = 1
             JOIN product_variants pv ON pv.id = vo.variant_id AND pv.active = 1
             JOIN products p ON p.id = pv.product_id AND p.active = 1
             LEFT JOIN attribute_values av ON av.id = vo.value_id
             WHERE 1=1' . $catSql . '
             GROUP BY a.id, a.name, a.slug, a.unit, a.type, a.sort, vo.value, av.color', $params));

        $out = [];
        foreach ($rows as $r) {
            $slug = $r['slug'];
            $out[$slug] ??= ['id' => (int)$r['id'], 'name' => $r['name'], 'slug' => $slug,
                             'unit' => $r['unit'], 'type' => $r['type'], 'sort' => (int)$r['sort'], 'values' => []];
            $v = $r['value'];
            if (isset($out[$slug]['values'][$v])) $out[$slug]['values'][$v]['count'] += (int)$r['cnt'];
            else $out[$slug]['values'][$v] = ['value' => $v, 'color' => $r['color'], 'count' => (int)$r['cnt']];
        }
        uasort($out, fn($a, $b) => [$a['sort'], $a['name']] <=> [$b['sort'], $b['name']]);
        foreach ($out as &$a) {
            uasort($a['values'], fn($x, $y) => strnatcasecmp($x['value'], $y['value']));
            $a['values'] = array_values($a['values']);
        }
        return $out;
    }
}
