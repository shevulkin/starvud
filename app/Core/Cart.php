<?php
declare(strict_types=1);

class Cart
{
    /** Стеля на позицію: захист від сміттєвих значень у POST і від обнулення залишків ботом */
    public const MAX_QTY = 999;

    private static bool $normalized = false;

    public static function items(): array { self::normalize(); return $_SESSION['cart'] ?? []; }

    /**
     * Ключ рядка. Домовлена ціна робить рядок окремим навіть для того самого
     * товару й тієї самої фасовки: у кошику можуть цілком законно лежати
     * десять банок за домовленою ціною і ще дві за звичайною, і зливати їх в
     * один рядок означало б продати за домовленою всі дванадцять.
     */
    private static function key(int $productId, ?int $variantId, int $offerId = 0): string
    { return $productId . ':' . ($variantId ?? 0) . ($offerId ? ':o' . $offerId : ''); }

    /**
     * Варіант у рядку кошика обовʼязковий, коли він є в товару:
     * саме від нього залежать ціна й наявність по магазинах.
     */
    private static function resolveVariant(int $productId, ?int $variantId): ?int
    {
        if ($variantId !== null && DB::row('SELECT 1 FROM product_variants WHERE id = ? AND product_id = ? AND active = 1',
                [$variantId, $productId])) return $variantId;
        $first = DB::val('SELECT id FROM product_variants WHERE product_id = ? AND active = 1 ORDER BY sort, id LIMIT 1', [$productId]);
        return $first !== null ? (int)$first : null;
    }

    /**
     * Старі рядки без варіанта (або з вимкненим) переводимо на актуальний варіант.
     *
     * Тут же перевіряються рядки з домовленою ціною. Перевіряти їх треба
     * щоразу, а не при додаванні: кошик живе в сесії, а сесія переживає і
     * скасування угоди продавцем, і кінець її терміну, і зняття товару з
     * продажу. Ціна, яку не можна підтвердити просто зараз, у кошику не
     * лишається — але й не зникає мовчки: людина клала її свідомо й має
     * дізнатись, чому рядка більше немає.
     */
    private static function normalize(): void
    {
        if (self::$normalized) return;
        self::$normalized = true;
        $cart = $_SESSION['cart'] ?? [];
        if (!$cart) return;
        $out = []; $changed = false; $dropped = [];
        foreach ($cart as $key => $item) {
            $pid = (int)$item['product_id'];
            $offerId = (int)($item['offer_id'] ?? 0);
            if ($offerId) {
                $deal = Offers::deal($offerId, Auth::id());
                if (!$deal) {
                    $dropped[] = (string)DB::val('SELECT name FROM products WHERE id = ?', [$pid]);
                    $changed = true;
                    continue;
                }
                // Фасовку й кількість диктує угода, а не сесія: домовлялись про
                // конкретні числа, і зміна будь-якого з них робить ціну чужою
                $item['variant_id'] = $deal['variant_id'] !== null ? (int)$deal['variant_id'] : null;
                if ((int)$item['qty'] !== (int)$deal['qty']) { $item['qty'] = (int)$deal['qty']; $changed = true; }
                $k = self::key($pid, $item['variant_id'], $offerId);
                if ($k !== $key) $changed = true;
                $out[$k] = $item;
                continue;
            }
            $vid = self::resolveVariant($pid, isset($item['variant_id']) ? (int)$item['variant_id'] : null);
            if ($vid !== ($item['variant_id'] ?? null)) { $item['variant_id'] = $vid; $changed = true; }
            $k = self::key($pid, $vid);
            if ($k !== $key) $changed = true;
            if (isset($out[$k])) $out[$k]['qty'] += $item['qty'];
            else $out[$k] = $item;
        }
        if ($dropped) {
            flash('error', 'Домовлена ціна більше не діє — прибрали з кошика: '
                . implode(', ', array_filter($dropped))
                . '. Товар можна замовити за звичайною ціною або домовитись наново.');
        }
        if ($changed) $_SESSION['cart'] = $out;
    }

    /**
     * Скільки цієї позиції можна покласти в кошик. null — без обмежень:
     * «виготовимо під замовлення» від залишку не залежить.
     *
     * Рахуємо тим самим OrderFlow::sellable(), що вирішує на оформленні, —
     * інакше кошик приймав би те, що потім відхиляє підтвердження замовлення.
     */
    public static function limit(int $productId, ?int $variantId, ?array $product = null): ?int
    {
        // Картка вже має рядок товару — передає його, щоб не питати базу вдруге
        $p = ($product && isset($product['made_to_order'], $product['active']) && (int)($product['id'] ?? 0) === $productId)
            ? ((int)$product['active'] === 1 ? $product : null)
            : DB::row('SELECT made_to_order, type FROM products WHERE id = ? AND active = 1', [$productId]);
        if (!$p) return 0;
        // Курсу не буває «мало»: доступ до відео продається скільки завгодно
        // разів. Це властивість самого курсу, а не галка в картці, — інакше
        // забута галка «під замовлення» робила б курс «немає в наявності».
        if (Courses::isCourse($p)) return null;
        if (!empty($p['made_to_order'])) return null;
        return min(self::MAX_QTY, OrderFlow::sellable($productId, $variantId));
    }

    /**
     * Кладе позицію в кошик і повертає, скільки саме поклали: 0 означає
     * «нічого немає», менше за $qty — «стільки й лишилось». Це відповідь
     * покупцеві, тож приймати рішення про повідомлення має він, не кошик.
     */
    public static function add(int $productId, ?int $variantId = null, int $qty = 1, int $offerId = 0): int
    {
        $variantId = self::resolveVariant($productId, $variantId);
        $key = self::key($productId, $variantId, $offerId);
        $cart = self::items();
        $was = (int)($cart[$key]['qty'] ?? 0);
        $want = min(self::MAX_QTY, $was + max(1, $qty));

        // Порожній склад — не привід тримати позицію в кошику: інакше людина
        // заповнить усю форму й дізнається про відмову на останньому кліку.
        $limit = self::limit($productId, $variantId);
        if ($limit !== null) $want = min($want, $limit);
        if ($want <= $was) return 0;

        if ($was) $cart[$key]['qty'] = $want;
        else $cart[$key] = ['product_id' => $productId, 'variant_id' => $variantId, 'qty' => $want];
        if ($offerId) $cart[$key]['offer_id'] = $offerId;
        $_SESSION['cart'] = $cart;
        return $want - $was;
    }

    public static function setQty(string $key, int $qty): void
    {
        if (!isset($_SESSION['cart'][$key])) return;
        $item = $_SESSION['cart'][$key];
        // Кількість домовленої позиції не міняється: ціну назвали саме за цю
        // партію. Прибрати рядок можна завжди — це вже інша дія.
        if (!empty($item['offer_id'])) {
            if ($qty <= 0) unset($_SESSION['cart'][$key]);
            return;
        }
        $limit = self::limit((int)$item['product_id'], isset($item['variant_id']) ? (int)$item['variant_id'] : null);
        $qty = min(self::MAX_QTY, $qty);
        if ($limit !== null) $qty = min($qty, $limit);
        if ($qty <= 0) unset($_SESSION['cart'][$key]);
        else $_SESSION['cart'][$key]['qty'] = $qty;
    }

    public static function remove(string $key): void { unset($_SESSION['cart'][$key]); }
    public static function clear(): void { unset($_SESSION['cart']); }

    public static function count(): int
    {
        return array_sum(array_map(fn($i) => $i['qty'], self::items()));
    }

    /**
     * Розгорнуті рядки кошика з цінами.
     *
     * Опт рахується саме тут, а не в Catalog::price: ціна залежить від
     * кількості, а кількість знає лише кошик. Для товарів із qty_scope =
     * 'product' вона ще й збирається з кількох рядків — пʼять свічок різного
     * кольору мають дати оптову ціну, хоч лежать окремими позиціями.
     */
    public static function detailed(?int $storeId = null): array
    {
        $items = self::items();

        // Кількість, за якою шукається поріг. Рахуємо до цін: рядок не може
        // визначити свою знижку, поки невідомо, скільки того ж товару поруч.
        // Домовлені штуки сюди не входять: вони вже мають свою ціну й не
        // повинні заодно підштовхувати сусідній звичайний рядок до оптового
        // порогу — інакше одна знижка тихо купувала б другу.
        $scoped = [];
        foreach ($items as $item) {
            if (!empty($item['offer_id'])) continue;
            $pid = (int)$item['product_id'];
            $scoped[$pid] = ($scoped[$pid] ?? 0) + (int)$item['qty'];
        }

        $rows = [];
        foreach ($items as $key => $item) {
            $p = DB::row('SELECT * FROM products WHERE id = ? AND active = 1', [$item['product_id']]);
            if (!$p) continue;
            $v = $item['variant_id'] ? DB::row('SELECT * FROM product_variants WHERE id = ?', [$item['variant_id']]) : null;
            [$price, $old] = Catalog::price($p, $v, $storeId);
            $qty = (int)$item['qty'];
            $offerId = (int)($item['offer_id'] ?? 0);

            /*
             * Домовлена ціна не рахується, вона вже названа.
             *
             * Продавець дивився на цю кількість, на цю людину й на свою маржу
             * і сказав кінцеве число. Опт, акція, набір і промокод на такий
             * рядок не діють — не через жадібність, а тому що інакше названа
             * ціна нічого не означала б: до неї щоразу додавалося б ще
             * скільки-небудь, і торгуватись довелось би з урахуванням цього.
             *
             * Стару ціну лишаємо вітринною — саме вона показує, наскільки
             * домовились дешевше, і саме її людина бачила, коли починала торг.
             */
            if ($offerId) {
                $deal = Offers::deal($offerId, Auth::id());
                if (!$deal) continue;              // normalize уже прибрав би, але рядок міг протухнути щойно
                $old = ($price !== null && (float)$price > (float)$deal['price']) ? (float)$price : null;
                $price = (float)$deal['price'];
                $qty = (int)$deal['qty'];
                $rows[] = [
                    'key' => $key, 'product' => $p, 'variant' => $v, 'qty' => $qty,
                    'price' => $price, 'old' => $old, 'sum' => $price * $qty,
                    'photo' => Catalog::photo($p, $v),
                    'stock' => Catalog::stockByStore((int)$p['id'], $v ? (int)$v['id'] : null),
                    'wholesale' => 0.0, 'tier_qty' => $qty, 'next_tier' => null,
                    // Стеля знижок домовленої позиції не стосується: вона
                    // обмежує те, що складається саме, а тут нічого не складається
                    'cap' => 0.0,
                    'offer_id' => $offerId, 'offer' => $deal,
                ];
                continue;
            }

            $tierQty = Catalog::qtyScope($p) === 'variant' ? $qty : (int)$scoped[(int)$p['id']];
            $cap = Catalog::discountCap($p, $v);
            $applied = 0.0;
            $next = Catalog::nextTier($p, $tierQty);

            if ($price !== null) {
                // Ціна «до знижок» — та сама, від якої рахує знижку решта коду
                // (Promo::ownPercent). Двох різних відліків тут бути не може:
                // стеля тоді означала б різне залежно від того, який ярус у неї впреться.
                $base = ($old !== null && (float)$old > 0) ? (float)$old : (float)$price;
                $ownPct = $base > 0 ? ($base - (float)$price) / $base * 100 : 0.0;
                // Стеля обрізає те, що опт ДОДАЄ. Зменшувати вже призначену
                // акційну ціну вона не має права: це рішення власника, а не
                // випадковий перебір ярусів.
                $priceBefore = (float)$price;
                $applied = max(0.0, min(Catalog::qtyPercent($p, $tierQty), $cap - $ownPct));
                if ($applied > 0) {
                    // Знімаємо від base, а не перераховуємо всю ціну заново:
                    // так на копійках не зʼявляється розбіжність із $ownPct.
                    $price = round((float)$price - $base * $applied / 100, 2);
                    if ($old === null) $old = $base;
                }
                // Скільки наступний поріг дасть насправді — рахуємо тут, поки
                // під рукою й відлік, і стеля. Підказка «ще 2 шт → −7%» мусить
                // обіцяти рівно те, що покупець побачить, дійшовши до неї.
                if ($next) {
                    $next['effective'] = max(0.0, min((float)$next['percent'], $cap - $ownPct));
                    if ($next['effective'] <= $applied) $next = null;   // стеля вже вибрана
                    // ціна за штуку на наступному порозі — покупцю зрозуміліша за відсоток
                    else $next['each'] = round($priceBefore - $base * $next['effective'] / 100, 2);
                }
            }

            $rows[] = [
                'key' => $key, 'product' => $p, 'variant' => $v, 'qty' => $qty,
                'price' => $price, 'old' => $old,
                'sum' => $price !== null ? $price * $qty : null,
                'photo' => Catalog::photo($p, $v),
                // наявність саме цього варіанта по магазинах: [store_id => qty]
                'stock' => Catalog::stockByStore((int)$p['id'], $v ? (int)$v['id'] : null),
                // опт: скільки відсотків він дав, за якою кількістю й що буде далі
                'wholesale' => $applied, 'tier_qty' => $tierQty,
                'next_tier' => $next,
                'cap' => $cap,
            ];
        }
        return $rows;
    }

    /**
     * Підсумки. Знижку рахуємо по кожній позиції окремо, а не від загальної суми:
     * покупець бачить перераховану ціну кожного товару, і ці числа мають скластися
     * рівно в «до сплати» — інакше на копійках форма не сходиться сама з собою.
     *
     * Порядок знижок: акція й опт уже сидять у ціні позиції (Cart::detailed),
     * далі набір, і останнім промокод. Не довільний: кожна наступна бачить, що
     * на позиції вже є, і бере лише те, що лишилось до стелі. Промокод останній
     * тому, що він єдиний, який покупець приносить сам, — і саме йому чесно
     * дістатись залишку, а не витіснити те, що діяло й без нього.
     *
     * @return array{subtotal:float,discount:float,total:float,bundles:array}
     */
    public static function breakdown(?int $storeId = null, ?array $promoCode = null): array
    {
        $rows = self::detailed($storeId);
        // Домовлені позиції в наборах не беруть участі — ні як знижка, ні як
        // складник: банка, ціну якої назвали руками, не може заодно зробити
        // комусь «разом дешевше», а підказка «доберіть до набору» на ній
        // читалась би як спроба переграти щойно укладену угоду.
        $hits = Bundles::match(array_values(array_filter($rows, fn($r) => empty($r['offer_id']))));

        $subtotal = 0.0; $fromBundles = 0.0; $fromPromo = 0.0;
        foreach ($rows as &$r) {
            $sum = (float)($r['sum'] ?? 0);
            $subtotal += $sum;

            // Знижка набору вже порахована по позиціях і вже обрізана стелею
            $inSet = (float)($hits['lines'][$r['key']] ?? 0);

            // Коду лишається те, що не забрали акція, опт і набір. $sum без
            // знятого набором — щоб код не знижував удруге ті самі гривні.
            // Домовлена позиція коду не бачить зовсім: її ціну назвала людина.
            $cut = empty($r['offer_id']) ? Promo::cut(
                max(0.0, $sum - $inSet), $promoCode,
                Promo::ownPercent($r) + self::pctOf($r, $inSet),
                $r['cap'] ?? null) : 0.0;

            $r['bundle_cut'] = $inSet;
            $r['promo_cut'] = $cut;
            $r['cut'] = round($inSet + $cut, 2);
            $r['final'] = max(0.0, round($sum - $r['cut'], 2));
            $fromBundles += $inSet;
            $fromPromo += $cut;
        }
        unset($r);

        $discount = round($fromBundles + $fromPromo, 2);
        return [
            'rows' => $rows,
            'subtotal' => $subtotal,
            // Знижка одним числом — для замовлення; окремо по джерелах — для
            // підсумків: покупцю показують не «−240 грн», а за що саме.
            'discount' => $discount,
            'bundle_discount' => round($fromBundles, 2),
            'promo_discount' => round($fromPromo, 2),
            'total' => max(0, round($subtotal - $discount, 2)),
            // які набори спрацювали — підсумкам є що назвати поіменно
            'bundles' => $hits['applied'],
            // а до яких лишився крок — кошику є що запропонувати
            'bundle_suggest' => $hits['suggest'] ?? [],
        ];
    }

    /** Самі числа, без рядків: те, що потрібно підсумкам і замовленню */
    public static function total(?int $storeId = null, ?array $promoCode = null): array
    {
        $b = self::breakdown($storeId, $promoCode);
        unset($b['rows']);
        return $b;
    }

    /**
     * Скільки відсотків від ціни «до знижок» становлять ці гривні. Потрібно,
     * щоб стеля рахувалась в одних одиницях: набір знімає гроші, а акція,
     * опт і код міряються у відсотках.
     */
    private static function pctOf(array $r, float $money): float
    {
        if ($money <= 0) return 0.0;
        $price = (float)($r['price'] ?? 0);
        $qty = (int)($r['qty'] ?? 0);
        $base = ($r['old'] ?? null) !== null && (float)$r['old'] > 0 ? (float)$r['old'] : $price;
        return $base > 0 && $qty > 0 ? $money / ($base * $qty) * 100 : 0.0;
    }
}
