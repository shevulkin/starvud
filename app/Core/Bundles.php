<?php
declare(strict_types=1);

/**
 * Набори: разом дешевше.
 *
 * Опт відповідає на «скільки взяли одного», набір — на «що взяли разом».
 * Це різні покупці: мед ящиком бере перекупник, а мед із прополісом і
 * свічкою — той, хто шукає подарунок і не знає, з чого його скласти.
 *
 * Знижка рахується ПО ПОЗИЦІЯХ, а показується ОДНИМ рядком. Перше — щоб
 * вона впиралась у ту саму стелю, що акція, опт і промокод, і щоб чек
 * сходився по рядках. Друге — щоб покупець розумів, за що йому знизили:
 * набір є фактом про кошик, а не про товар, і тиха зміна ціни меду на
 * 162 грн не пояснює себе нічим.
 */
class Bundles
{
    /**
     * @var array<string,array> набори зі складом, кешовані на запит.
     * Наборів мало, а звертань за запит багато: кошик, підсумки, сторінка
     * товару. Ключ — чи беремо лише активні.
     */
    private static array $cache = [];

    /** Набори з їхнім складом (кешовано на запит) */
    public static function all(bool $activeOnly = true): array
    {
        $key = $activeOnly ? 'on' : 'all';
        if (isset(self::$cache[$key])) return self::$cache[$key];

        $rows = DB::all('SELECT * FROM bundles' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY sort, id');
        if (!$rows) return self::$cache[$key] = [];

        $items = [];
        foreach (DB::all('SELECT * FROM bundle_items ORDER BY id') as $it) {
            $items[(int)$it['bundle_id']][] = $it;
        }
        foreach ($rows as &$b) $b['items'] = $items[(int)$b['id']] ?? [];
        unset($b);

        return self::$cache[$key] = $rows;
    }

    /**
     * Забути кеш. Скидає його той, хто набори щойно переписав, — з тієї ж
     * причини, що й Catalog::forgetCaches(): читати в тому самому запиті те,
     * що вже неправда, не має жоден виклик, навіть якщо сьогодні таких немає.
     */
    public static function forget(): void { self::$cache = []; }

    /** Набір із його складом або null */
    public static function find(int $id): ?array
    {
        $b = DB::row('SELECT * FROM bundles WHERE id = ?', [$id]);
        if (!$b) return null;
        $b['items'] = DB::all('SELECT * FROM bundle_items WHERE bundle_id = ? ORDER BY id', [$id]);
        return $b;
    }

    /**
     * Що зібралось у цьому кошику.
     *
     * @param array $rows рядки Cart::detailed()
     * @return array{total:float,lines:array<string,float>,applied:array,suggest:array}
     *         lines — скільки гривень зняти з кожної позиції (за її ключем),
     *         applied — які набори спрацювали й по скільки разів,
     *         suggest — які майже зібрались (див. missing)
     */
    public static function match(array $rows): array
    {
        $byKey = []; $left = []; $index = []; $room = [];
        foreach ($rows as $r) {
            // Товар «за запитом» ціни не має, тож і в набір не потрапляє:
            // порахувати, наскільки він здешевшав, немає від чого.
            if (($r['price'] ?? null) === null) continue;
            $k = (string)$r['key'];
            $byKey[$k] = $r;
            $left[$k] = (int)$r['qty'];
            $room[$k] = self::room($r);
            $index[(int)$r['product']['id']][] = $k;
        }

        $lines = []; $applied = []; $suggest = []; $total = 0.0;

        foreach (self::all() as $b) {
            if (!$b['items']) continue;

            // Скільки повних наборів вміщається. Неповний набір знижки не
            // дає — інакше «разом дешевше» перестало б означати «разом».
            $sets = PHP_INT_MAX;
            foreach ($b['items'] as $it) {
                $have = 0;
                foreach ($index[(int)$it['product_id']] ?? [] as $k) {
                    if (self::fits($byKey[$k], $it)) $have += $left[$k];
                }
                $sets = min($sets, intdiv($have, max(1, (int)$it['qty'])));
                if ($sets < 1) break;
            }
            if ($sets < 1) {
                // Набір не зібрався — але, може, до нього лишився крок. Рахуємо
                // від ЗАЛИШКІВ: штуки, які вже пішли в інший набір, до цього
                // не належать, і обіцяти на них знижку було б неправдою.
                $miss = self::missing($b, $byKey, $index, $left);
                if ($miss) $suggest[] = $miss;
                continue;
            }

            // Забираємо штуки з кошика. Забрані не дістануться наступному
            // набору: та сама банка не може двічі бути «третьою в наборі»,
            // а без цього правила перетин двох наборів множив би знижку.
            $take = []; $content = 0.0;
            foreach ($b['items'] as $it) {
                $need = (int)$it['qty'] * $sets;
                foreach ($index[(int)$it['product_id']] ?? [] as $k) {
                    if ($need <= 0) break;
                    if (!self::fits($byKey[$k], $it)) continue;
                    $n = min($need, $left[$k]);
                    if ($n <= 0) continue;
                    $take[$k] = ($take[$k] ?? 0) + $n;
                    $left[$k] -= $n;
                    $need -= $n;
                    $content += $n * (float)$byKey[$k]['price'];
                }
            }
            if ($content <= 0) continue;

            $cut = self::cutFor($b, $content, $sets);
            if ($cut <= 0) continue;

            // Розкладаємо пропорційно внеску позиції й обрізаємо її стелею.
            // Те, що не вмістилось, просто не дається: перекласти його на
            // сусідню позицію означало б обійти стелю збоку.
            $got = 0.0;
            foreach ($take as $k => $n) {
                $share = $cut * ($n * (float)$byKey[$k]['price']) / $content;
                $share = round(min($share, $room[$k]), 2);
                if ($share <= 0) continue;
                $room[$k] = round($room[$k] - $share, 2);
                $lines[$k] = round(($lines[$k] ?? 0) + $share, 2);
                $got = round($got + $share, 2);
            }
            if ($got <= 0) continue;

            // Записуємо те, що справді дали, а не те, що збирались: інакше
            // рядок підсумків і сума позицій розійшлися б рівно на стелю.
            $applied[] = ['bundle' => $b, 'sets' => $sets, 'cut' => $got, 'keys' => array_keys($take)];
            $total = round($total + $got, 2);
        }

        return ['total' => $total, 'lines' => $lines, 'applied' => $applied, 'suggest' => $suggest];
    }

    /**
     * Скільки позицій може бракувати, щоб це ще була підказка.
     *
     * Одна-дві — «ви майже зібрали». Три й більше — перелік чужих товарів під
     * виглядом поради, тобто реклама решти каталогу. Різниця не в числі, а в
     * тому, чи впізнає покупець у пропозиції свій намір.
     */
    public const MAX_MISSING = 2;

    /**
     * Набір, до якого лишився крок: чого саме бракує й скільки це дасть.
     *
     * Найдорожча помилка набору — та, про яку покупець дізнається після
     * оформлення: «взяв би й прополіс, якби знав». Кошик — момент найвищої
     * готовності купити, і саме тут ми точно знаємо, чого бракує.
     *
     * Порожньо, коли підказувати нічого: у кошику немає жодної позиції набору
     * (тоді це не підказка, а реклама), бракує забагато, або набір узагалі
     * більше не збирається.
     *
     * @return array{bundle:array,need:array,cut:float,sum:float,total:float}|null
     */
    private static function missing(array $b, array $byKey, array $index, array $left): ?array
    {
        $shortOf = [];   // product_id => скількох штук бракує
        $present = 0;
        foreach ($b['items'] as $it) {
            $have = 0;
            foreach ($index[(int)$it['product_id']] ?? [] as $k) {
                if (self::fits($byKey[$k], $it)) $have += $left[$k];
            }
            if ($have > 0) $present++;
            $short = max(0, (int)$it['qty'] - $have);
            if ($short > 0) $shortOf[(int)$it['product_id']] = $short;
        }
        // Нічого не бракує — набір зібрався б і без підказки; нічого немає —
        // покупець цього набору не починав, і починати за нього ми не будемо.
        if (!$shortOf || !$present) return null;
        if (count($shortOf) > self::MAX_MISSING) return null;

        $full = self::expand($b);
        if (!$full || $full['cut'] <= 0) return null;

        // Назви беремо з розгорнутого складу — там уже підібрані фасовки й ціни
        $need = [];
        foreach ($full['expanded'] as $row) {
            $pid = (int)$row['product']['id'];
            if (!isset($shortOf[$pid])) continue;
            $need[] = $row + ['short' => $shortOf[$pid]];
        }
        if (!$need) return null;

        return ['bundle' => $b, 'need' => $need,
                'cut' => $full['cut'], 'sum' => $full['sum'], 'total' => $full['total']];
    }

    /**
     * Скільки штук цієї позиції набору вже лежить у кошику.
     * «Будь-яка фасовка» рахує всі: набір збереться з тієї, що вже взяли.
     */
    public static function inCart(array $item, array $rows): int
    {
        $n = 0;
        foreach ($rows as $r) {
            if (($r['price'] ?? null) === null) continue;
            if (self::fits($r, $item)) $n += (int)$r['qty'];
        }
        return $n;
    }

    /** Скільки повних комплектів набору вже зібрано з того, що в кошику */
    public static function setsIn(array $bundle, array $rows): int
    {
        $sets = PHP_INT_MAX;
        foreach ($bundle['items'] as $it) {
            $sets = min($sets, intdiv(self::inCart($it, $rows), max(1, (int)$it['qty'])));
        }
        return $bundle['items'] ? max(0, $sets) : 0;
    }

    /** Скільки гривень знижки дає набір за $sets комплектів на суму $content */
    public static function cutFor(array $bundle, float $content, int $sets = 1): float
    {
        $value = (float)$bundle['value'];
        $cut = ($bundle['kind'] ?? 'percent') === 'fixed'
            // Фіксована ціна — за ОДИН комплект: два комплекти по 500 коштують
            // 1000, а не 500, інакше другий їхав би задарма
            ? $content - $value * $sets
            : $content * $value / 100;
        return round(max(0.0, min($cut, $content)), 2);
    }

    /**
     * Скільки гривень ще можна зняти з позиції, поки не впремося в її стелю.
     *
     * Рахуємо в грошах, а не у відсотках: знижку набору відсотком не задають,
     * і переводити її туди-сюди означало б втрачати копійки на кожному кроці.
     */
    private static function room(array $r): float
    {
        $price = (float)($r['price'] ?? 0);
        $qty = (int)($r['qty'] ?? 0);
        $base = ($r['old'] ?? null) !== null && (float)$r['old'] > 0 ? (float)$r['old'] : $price;
        if ($base <= 0 || $qty <= 0) return 0.0;
        $cap = (float)($r['cap'] ?? Catalog::DEFAULT_MAX_DISCOUNT);
        $allowed = $base * $qty * $cap / 100;   // скільки стеля дозволяє всього
        $taken = ($base - $price) * $qty;       // скільки вже зняли акція й опт
        return max(0.0, round($allowed - $taken, 2));
    }

    /** Чи годиться рядок кошика на цю позицію набору */
    private static function fits(array $row, array $item): bool
    {
        if ((int)$row['product']['id'] !== (int)$item['product_id']) return false;
        // Порожня фасовка в наборі означає «будь-яка»
        if ($item['variant_id'] === null || $item['variant_id'] === '') return true;
        return (int)($row['variant']['id'] ?? 0) === (int)$item['variant_id'];
    }

    /**
     * Набори, у складі яких є цей товар, — для блока «Разом дешевше».
     * Разом із розгорнутим складом: назви, фасовки, ціни й підсумок.
     *
     * Набори, де щось із складу вже недоступне, не показуємо: пропозиція,
     * яку не можна прийняти, гірша за її відсутність.
     */
    public static function forProduct(int $productId): array
    {
        $out = [];
        foreach (self::all() as $b) {
            $mine = false;
            foreach ($b['items'] as $it) if ((int)$it['product_id'] === $productId) $mine = true;
            if (!$mine) continue;

            $full = self::expand($b);
            if ($full !== null) $out[] = $full;
        }
        return $out;
    }

    /**
     * Склад набору з цінами: [items => [...], sum => звичайна ціна,
     * total => ціна набором, cut => різниця]. null — щось із складу
     * недоступне, і набору більше немає.
     */
    public static function expand(array $bundle): ?array
    {
        $items = []; $sum = 0.0;
        foreach ($bundle['items'] as $it) {
            $p = DB::row('SELECT * FROM products WHERE id = ? AND active = 1', [(int)$it['product_id']]);
            if (!$p) return null;

            $v = null;
            if ($it['variant_id']) {
                $v = DB::row('SELECT * FROM product_variants WHERE id = ? AND product_id = ? AND active = 1',
                    [(int)$it['variant_id'], (int)$p['id']]);
                if (!$v) return null;
            } elseif (Catalog::hasVariants((int)$p['id'])) {
                // «Будь-яка фасовка» на вітрині мусить стати конкретною: ціну
                // показуємо за найдешевшою — це те, у що набір обійдеться
                // покупцю, якщо він нічого не змінить.
                $v = DB::row('SELECT * FROM product_variants WHERE product_id = ? AND active = 1
                              ORDER BY sort, id LIMIT 1', [(int)$p['id']]);
            }

            [$price] = Catalog::price($p, $v);
            if ($price === null) return null;   // «за запитом» набір не збирає

            $qty = max(1, (int)$it['qty']);
            $sum = round($sum + $price * $qty, 2);
            // Рядок набору лишаємо при собі: за ним рахується, скільки цієї
            // позиції вже в кошику («будь-яка фасовка» рахує всі фасовки),
            // а по підібраній тут $v — що саме класти, коли її не задавали.
            $items[] = ['product' => $p, 'variant' => $v, 'qty' => $qty, 'price' => $price,
                        'photo' => Catalog::photo($p, $v), 'item' => $it];
        }
        if (!$items) return null;

        $cut = self::cutFor($bundle, $sum);
        return $bundle + ['expanded' => $items, 'sum' => $sum,
                          'cut' => $cut, 'total' => round($sum - $cut, 2)];
    }

    /** «Набір «Подарунковий» ×2» — підпис рядка підсумків */
    public static function label(array $hit): string
    {
        $title = (string)($hit['bundle']['title'] ?? 'Набір');
        return 'Набір «' . $title . '»' . ((int)$hit['sets'] > 1 ? ' ×' . (int)$hit['sets'] : '');
    }
}
