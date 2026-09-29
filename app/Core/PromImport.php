<?php
declare(strict_types=1);

/**
 * Перенос магазину з Prom.ua: розділи, товари, фото, опт і відгуки.
 *
 * Дані лежать у database/prom/ — це знімок сайту starvud.prom.ua, зроблений
 * під час переїзду (catalog.json, reviews.json, names.json). Фото — окремо
 * (storage/import/prom/img, понад сотню мегабайт), бо в git їм не місце.
 *
 * Повторний запуск безпечний: товар знаходиться за ext_id (його id на Prom) і
 * ОНОВЛЮЄТЬСЯ — ціна, наявність, опис, опт. Фото й характеристики при цьому
 * не чіпаються, якщо в товару вони вже є: їх могли поправити в адмінці, і
 * імпорт не має перетирати ручну роботу. Відгуки впізнаються за ext_id так само.
 *
 * Чого на Prom немає і що ми НЕ вигадуємо: точних залишків (Prom каже лише
 * «в наявності» — ставимо $stock штук, і в README сказано, що це треба
 * звірити), ваги, кодів УКТЗЕД.
 */
final class PromImport
{
    /** Розділи в порядку меню Prom; ключ — назва на Prom (з її пробілами) */
    public const CATEGORIES = [
        'Для малюків' => ['dlia-maliukiv', 'Для малюків'],
        'Ляльки та пупси' => ['lialky-ta-pupsy', 'Ляльки та пупси'],
        'Настільні ігри та пазли' => ['nastilni-ihry-ta-pazly', 'Настільні ігри та пазли'],
        'Гойдалки' => ['hoidalky', 'Гойдалки'],
        'Творчість і розвиток' => ['tvorchist-i-rozvytok', 'Творчість і розвиток'],
        'Ігрові намети' => ['ihrovi-namety', 'Ігрові намети'],
        'Машинки / іграшкова зброя' => ['mashynky-ta-zbroia', 'Машинки та іграшкова зброя'],
        "М'які іграшки, фігурки" => ['miaki-ihrashky', 'Мʼякі іграшки та фігурки'],
    ];

    /** Характеристики, за якими є сенс фільтрувати; «Стан: Новий» — у всіх однаковий, не беремо */
    private const FILTERABLE = ['Матеріал', 'Тип', 'Країна виробник', 'Стать', 'Тип живлення', 'Персонажі'];
    private const SKIP_ATTRS = ['Стан', 'Виробник'];

    /** Назва акцій, перенесених з Prom: за нею повторний імпорт знаходить і оновлює саме їх */
    public const PROMO_TITLE = 'Акція з Prom';

    private const RATING =['Відмінно' => 5, 'Добре' => 4, 'Нормально' => 3, 'Погано' => 2, 'Жахливо' => 1];

    /** @var callable|null */
    private static $log = null;

    /**
     * @param string $dir     тека з catalog.json / reviews.json / names.json
     * @param string $imgDir  тека з фото ({id}.jpg); порожньо — без фото
     * @param int    $stock   скільки штук ставити товару «в наявності»
     * @return array{categories:int,created:int,updated:int,images:int,reviews:int}
     */
    public static function run(string $dir, string $imgDir = '', int $stock = 10, ?callable $log = null): array
    {
        self::$log = $log;
        $catalog = self::json("$dir/catalog.json");
        $names = is_file("$dir/names.json") ? self::json("$dir/names.json") : [];
        $stats = ['categories' => 0, 'created' => 0, 'updated' => 0, 'images' => 0, 'reviews' => 0, 'promos' => 0];

        $store = (int)DB::val('SELECT id FROM stores WHERE active = 1 ORDER BY sort, id LIMIT 1');
        if (!$store) throw new RuntimeException('Немає жодної точки продажу — залишкам нема де лежати.');

        $cats = self::categories($stats);
        $brandIds = [];

        foreach ($catalog as $i => $item) {
            $ext = (string)$item['id'];
            $catName = trim((string)$item['category']);
            $catId = $cats[$catName] ?? reset($cats);
            $price = round((float)$item['price'], 2);
            // Тимчасова акція на Prom: «150 ₴ → 142,50 ₴ до 30.09». У нас це
            // звичайна ціна плюс акція з датою кінця — тоді знижка зникне сама,
            // а не лишиться назавжди зашитою в ціну.
            $old = round((float)($item['old_price'] ?? 0), 2);
            $promoPct = ($old > $price && $price > 0) ? round((1 - $price / $old) * 100, 4) : 0.0;
            if ($promoPct > 0) $price = $old;
            $inStock = ($item['availability'] ?? '') === 'InStock';
            $attrs = array_values(array_filter((array)$item['attrs'], static fn($a) => !in_array($a[0], self::SKIP_ATTRS, true)));
            $age = null;
            foreach ((array)$item['attrs'] as [$an, $av]) {
                if ($an === 'Вікова група' || $an === 'Вік') { $age = Ages::parse((string)$av) ?? $age; }
            }
            // Поля «Вікова група» немає — шукаємо вік в описі й назві
            $age ??= Ages::fromText((string)($item['description_text'] ?? '') . ' ' . (string)$item['name']);
            $seo = trim(preg_replace('~\s+~u', ' ', (string)$item['name']));
            $name = trim((string)($names[$ext] ?? '')) ?: self::shorten($seo);
            [$short, $desc] = self::texts((string)($item['description_html'] ?? ''), (string)($item['description_text'] ?? ''), $seo);

            $fields = [
                'category_id' => $catId, 'name' => $name, 'seo_title' => mb_substr($seo, 0, 250),
                'sku' => (string)($item['sku'] ?? '') ?: null,
                'short_desc' => $short, 'description' => $desc,
                'base_price' => $price, 'old_price' => null,
                'age_min' => $age[0] ?? null, 'age_max' => $age[1] ?? null,
                'made_to_order' => 0, 'active' => 1, 'type' => 'product',
                'updated_at' => now(),
            ];

            $pid = (int)DB::val('SELECT id FROM products WHERE ext_id = ?', [$ext]);
            if ($pid) {
                DB::update('products', $fields, 'id = ?', [$pid]);
                $stats['updated']++;
            } else {
                $pid = (int)DB::insert('products', $fields + [
                    'slug' => self::slug($name), 'ext_id' => $ext, 'featured' => 0,
                    'created_at' => now(),
                ]);
                $stats['created']++;
                foreach ($attrs as $k => [$an, $av]) {
                    DB::insert('product_attrs', ['product_id' => $pid, 'name' => $an, 'value' => mb_substr((string)$av, 0, 250),
                        'filterable' => in_array($an, self::FILTERABLE, true) ? 1 : 0, 'sort' => $k]);
                }
            }

            // Бренд — лише справжній: «Без бренду» не бренд, а його відсутність
            foreach ((array)$item['attrs'] as [$an, $av]) {
                if ($an !== 'Виробник' || trim((string)$av) === '' || $av === 'Без бренду') continue;
                $bid = $brandIds[$av] ??= self::brand((string)$av);
                if (!DB::val('SELECT 1 FROM product_brands WHERE product_id = ? AND brand_id = ?', [$pid, $bid])) {
                    DB::insert('product_brands', ['product_id' => $pid, 'brand_id' => $bid]);
                }
            }

            // Наявність — на головній точці
            $qty = $inStock ? $stock : 0;
            if (DB::val('SELECT 1 FROM store_stock WHERE product_id = ? AND store_id = ? AND variant_id IS NULL', [$pid, $store])) {
                DB::query('UPDATE store_stock SET qty = ? WHERE product_id = ? AND store_id = ? AND variant_id IS NULL', [$qty, $pid, $store]);
            } else {
                DB::insert('store_stock', ['product_id' => $pid, 'variant_id' => null, 'store_id' => $store, 'qty' => $qty]);
            }

            self::tiers($pid, $price, (array)($item['tiers'] ?? []));

            DB::query("DELETE FROM promotions WHERE product_id = ? AND title = ?", [$pid, self::PROMO_TITLE]);
            if ($promoPct > 0) {
                DB::insert('promotions', ['title' => self::PROMO_TITLE, 'percent' => $promoPct,
                    'store_id' => null, 'category_id' => null, 'product_id' => $pid,
                    'starts_at' => null, 'ends_at' => $item['promo_ends'] ?? null, 'active' => 1]);
                $stats['promos']++;
            }

            if ($imgDir !== '' && !DB::val('SELECT 1 FROM product_images WHERE product_id = ?', [$pid])) {
                $stats['images'] += self::images($pid, (array)$item['images'], $imgDir);
            }
            if (($i + 1) % 10 === 0) self::say(($i + 1) . ' / ' . count($catalog));
        }

        Attrs::backfill();
        if (is_file("$dir/reviews.json")) $stats['reviews'] = self::reviews(self::json("$dir/reviews.json"));
        self::featureReviewed();
        Catalog::forgetCaches();
        return $stats;
    }

    // ─────────────────────────────────────────────────────────── частини

    /** @return array<string,int> назва на Prom => id розділу */
    private static function categories(array &$stats): array
    {
        $out = []; $sort = 0;
        foreach (self::CATEGORIES as $promName => [$slug, $name]) {
            $id = (int)DB::val('SELECT id FROM categories WHERE slug = ?', [$slug]);
            if (!$id) {
                $id = (int)DB::insert('categories', ['name' => $name, 'slug' => $slug, 'type' => 'product',
                    'parent_id' => null, 'sort' => $sort, 'active' => 1]);
                $stats['categories']++;
            }
            $out[trim($promName)] = $id;
            $sort++;
        }
        return $out;
    }

    private static function brand(string $name): int
    {
        $slug = slugify($name);
        $id = (int)DB::val('SELECT id FROM brands WHERE slug = ?', [$slug]);
        return $id ?: (int)DB::insert('brands', ['name' => $name, 'slug' => $slug, 'own' => 0, 'active' => 1, 'sort' => 0]);
    }

    /**
     * Оптова шкала Prom («від 3 шт — 35 ₴») → наша шкала у відсотках.
     *
     * У нас опт — знижка від ціни, а не друга ціна, тож перераховуємо. Відсоток
     * з чотирма знаками дає ту саму ціну до копійки: 1120 ₴ − 15.625 % = 945 ₴.
     * Стелю знижки товару піднімаємо до найглибшого ярусу, інакше загальна
     * стеля (30 %) тихо зрізала б ціну, яку покупці бачили на Prom.
     */
    private static function tiers(int $pid, float $price, array $tiers): void
    {
        DB::query('DELETE FROM qty_discounts WHERE product_id = ?', [$pid]);
        if ($price <= 0 || !$tiers) return;
        $max = 0.0;
        foreach ($tiers as [$qty, $tierPrice]) {
            $tp = (float)$tierPrice;
            if ((int)$qty < 2 || $tp <= 0 || $tp >= $price) continue;
            $pct = round((1 - $tp / $price) * 100, 4);
            DB::insert('qty_discounts', ['product_id' => $pid, 'category_id' => null,
                'min_qty' => (int)$qty, 'percent' => $pct, 'active' => 1]);
            $max = max($max, $pct);
        }
        if ($max > Catalog::DEFAULT_MAX_DISCOUNT) {
            DB::update('products', ['max_discount' => ceil($max)], 'id = ?', [$pid]);
        }
    }

    private static function images(int $pid, array $images, string $imgDir): int
    {
        $n = 0; $first = null;
        foreach ($images as $k => $img) {
            $file = rtrim($imgDir, '/\\') . '/' . $img['id'] . '.' . ($img['ext'] ?? 'jpg');
            if (!is_file($file)) continue;
            $saved = Images::saveFile($file, 'p' . $pid . '-' . ($k + 1));
            if (!$saved) continue;
            [$path, $w, $h, $bytes] = $saved;
            DB::insert('product_images', ['product_id' => $pid, 'path' => $path, 'variant_id' => null,
                'width' => $w, 'height' => $h, 'bytes' => $bytes, 'sort' => $k]);
            $first ??= $path;
            $n++;
        }
        if ($first) DB::update('products', ['image' => $first], 'id = ?', [$pid]);
        return $n;
    }

    /** @return int скільки відгуків додано */
    private static function reviews(array $rows): int
    {
        $n = 0;
        foreach ($rows as $r) {
            $ext = substr(sha1($r['author'] . '|' . $r['date'] . '|' . $r['text'] . '|' . implode(',', $r['products'] ?? [])), 0, 20);
            if (DB::val('SELECT 1 FROM reviews WHERE ext_id = ?', [$ext])) continue;
            $pids = [];
            foreach ((array)($r['products'] ?? []) as $promId) {
                $id = DB::val('SELECT id FROM products WHERE ext_id = ?', [(string)$promId]);
                if ($id) $pids[] = (int)$id;
            }
            $d = DateTime::createFromFormat('d.m.Y', (string)$r['date']);
            DB::insert('reviews', [
                'author' => trim(preg_replace('~\s+~u', ' ', (string)$r['author'])),
                'rating' => self::RATING[$r['rating']] ?? 5,
                'text' => trim((string)$r['text']) ?: null,
                'reply' => trim((string)$r['reply']) ?: null,
                'tags' => $r['tags'] ? json_encode(array_values($r['tags']), JSON_UNESCAPED_UNICODE) : null,
                'product_ids' => $pids ? json_encode($pids) : null,
                'source' => 'prom', 'ext_id' => $ext, 'approved' => 1,
                'created_at' => $d ? $d->format('Y-m-d 12:00:00') : now(),
            ]);
            $n++;
        }
        return $n;
    }

    /**
     * «Хіти» — не вибір навмання, а ті товари, про які покупці писали відгуки
     * на Prom: це найчесніший сигнал популярності, який у нас є.
     */
    private static function featureReviewed(): void
    {
        $count = [];
        foreach (DB::all('SELECT product_ids FROM reviews WHERE product_ids IS NOT NULL') as $r) {
            foreach ((array)json_decode((string)$r['product_ids'], true) as $pid) $count[(int)$pid] = ($count[(int)$pid] ?? 0) + 1;
        }
        arsort($count);
        foreach (array_slice(array_keys($count), 0, 8) as $pid) {
            DB::update('products', ['featured' => 1], 'id = ?', [$pid]);
        }
    }

    // ─────────────────────────────────────────────────────────── тексти

    /**
     * Опис з HTML Prom → наш простий текст: абзаци через порожній рядок,
     * пункти списку — рядками з «•». Заголовок h2 на Prom повторює назву
     * товару слово в слово, тож його прибираємо.
     *
     * @return array{0:string,1:string} [короткий опис, повний]
     */
    public static function texts(string $html, string $plain, string $title): array
    {
        if ($html !== '') {
            $t = preg_replace('~<h\d[^>]*>.*?</h\d>~isu', '', $html);
            $t = preg_replace('~<li[^>]*>~iu', "\n• ", $t);
            $t = preg_replace('~</(p|ul|ol|div|tr)>|<br\s*/?>~iu', "\n", $t);
            $t = strip_tags($t);
        } else {
            $t = $plain;
            if (str_starts_with($t, $title)) $t = substr($t, strlen($title));
            $t = preg_replace('~^\t+~mu', '• ', $t);
        }
        $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = preg_replace("~[ \t\x{00A0}]+~u", ' ', $t);
        $lines = array_map('trim', explode("\n", $t));
        // абзаци: порожні рядки схлопуються в один, пункти списку йдуть підряд
        $out = []; $blank = false;
        foreach ($lines as $l) {
            if ($l === '' || $l === '•') { $blank = (bool)$out; continue; }
            $isItem = str_starts_with($l, '• ');
            if ($out && ($blank && !($isItem && str_starts_with(end($out), '• ')))) $out[] = '';
            $out[] = $l; $blank = false;
        }
        $desc = trim(implode("\n", $out));
        // Короткий опис — перше речення першого справжнього абзацу
        $short = '';
        foreach ($out as $l) {
            if ($l === '' || str_starts_with($l, '• ') || mb_strlen($l) < 25) continue;
            $short = preg_match('~^(.{25,180}?[.!?])(\s|$)~us', $l, $m) ? $m[1] : mb_substr($l, 0, 160);
            break;
        }
        return [$short, $desc];
    }

    /** Запасний варіант, якщо короткої назви в names.json немає: перші 7 слів */
    private static function shorten(string $seo): string
    {
        $w = preg_split('~\s+~u', $seo);
        return implode(' ', array_slice($w, 0, 7));
    }

    private static function slug(string $name): string
    {
        $base = mb_substr(slugify($name), 0, 80);
        $slug = $base; $i = 2;
        while (DB::val('SELECT 1 FROM products WHERE slug = ?', [$slug])) $slug = $base . '-' . $i++;
        return $slug;
    }

    private static function json(string $file): array
    {
        if (!is_file($file)) throw new RuntimeException("Немає файлу $file");
        $d = json_decode((string)file_get_contents($file), true);
        if (!is_array($d)) throw new RuntimeException("Не JSON: $file");
        return $d;
    }

    private static function say(string $msg): void
    {
        if (self::$log) (self::$log)($msg);
    }
}
