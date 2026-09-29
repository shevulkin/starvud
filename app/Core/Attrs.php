<?php
declare(strict_types=1);

/**
 * Словник характеристик (attributes + attribute_values) та звʼязки з товарами й варіантами.
 *
 * Ідея: назва характеристики й перелік її значень — окремі обʼєкти, які продавець
 * обирає зі списку, а не набирає щоразу руками. Тому фільтри не ламаються від одруківок,
 * а варіанти можна згенерувати комбінаціями значень.
 */
class Attrs
{
    public const TYPES = [
        'select' => 'Список значень',
        'text'   => 'Довільний текст',
        'number' => 'Число',
        'color'  => 'Колір (зі зразком)',
    ];

    /** Усі характеристики зі значеннями. Кешується на запит. */
    public static function all(bool $onlyActive = true): array
    {
        static $cache = [];
        $key = $onlyActive ? 'active' : 'all';
        if (isset($cache[$key])) return $cache[$key];

        $sql = 'SELECT * FROM attributes' . ($onlyActive ? ' WHERE active = 1' : '') . ' ORDER BY sort, name';
        $list = DB::all($sql);
        $values = $cats = [];
        foreach (DB::all('SELECT * FROM attribute_values ORDER BY sort, value') as $v) {
            $values[(int)$v['attribute_id']][] = $v;
        }
        foreach (DB::all('SELECT * FROM attribute_categories') as $c) {
            $cats[(int)$c['attribute_id']][] = (int)$c['category_id'];
        }
        foreach ($list as &$a) {
            $a['values'] = $values[(int)$a['id']] ?? [];
            $a['category_ids'] = $cats[(int)$a['id']] ?? [];
        }
        unset($a);
        return $cache[$key] = $list;
    }

    /**
     * Характеристики, доречні для категорії: привʼязані до неї + загальні (без привʼязки).
     * Так для меду не пропонується «Матеріал», а «Термін зберігання» лишається всюди.
     *
     * Привʼязка до розділу діє й на його підрозділи: «Сорт меду» заводять один
     * раз на «Мед», а не по разу на кожен сорт.
     */
    public static function forCategory(?int $categoryId, bool $onlyActive = true): array
    {
        $chain = $categoryId ? Catalog::ancestorIds($categoryId) : [];
        $out = [];
        foreach (self::all($onlyActive) as $a) {
            if (!$a['category_ids'] || array_intersect($chain, $a['category_ids'])) $out[] = $a;
        }
        return $out;
    }

    /** Замінити перелік категорій характеристики */
    public static function setCategories(int $attributeId, array $categoryIds): void
    {
        DB::delete('attribute_categories', 'attribute_id = ?', [$attributeId]);
        foreach (array_unique(array_map('intval', $categoryIds)) as $cid) {
            if ($cid > 0) DB::insert('attribute_categories', ['attribute_id' => $attributeId, 'category_id' => $cid]);
        }
    }

    public static function find(int $id): ?array
    {
        foreach (self::all(false) as $a) if ((int)$a['id'] === $id) return $a;
        return null;
    }

    public static function values(int $attributeId): array
    {
        return DB::all('SELECT * FROM attribute_values WHERE attribute_id = ? ORDER BY sort, value', [$attributeId]);
    }

    /**
     * Знайти характеристику за назвою або створити нову.
     * $categoryId — нова характеристика одразу привʼязується до категорії товару,
     * щоб не засмічувати списки інших категорій.
     */
    public static function ensure(string $name, string $type = 'select', ?int $categoryId = null): ?array
    {
        $name = trim($name);
        if ($name === '') return null;
        $slug = slugify($name);
        $row = DB::row('SELECT * FROM attributes WHERE slug = ?', [$slug]);
        if (!$row) {
            $id = DB::insert('attributes', [
                'name' => $name, 'slug' => $slug,
                'type' => isset(self::TYPES[$type]) ? $type : 'select',
                'filterable' => 1, 'active' => 1,
                'sort' => (int)DB::val('SELECT COALESCE(MAX(sort),0)+1 FROM attributes'),
            ]);
            if ($categoryId) DB::insert('attribute_categories', ['attribute_id' => $id, 'category_id' => $categoryId]);
            $row = DB::row('SELECT * FROM attributes WHERE id = ?', [$id]);
        }
        return $row;
    }

    /** Знайти значення характеристики за текстом або додати його у словник */
    public static function ensureValue(int $attributeId, string $value): ?array
    {
        $value = trim($value);
        if ($value === '' || !$attributeId) return null;
        foreach (self::values($attributeId) as $v) {
            if (mb_strtolower($v['value']) === mb_strtolower($value)) return $v;
        }
        $id = DB::insert('attribute_values', [
            'attribute_id' => $attributeId, 'value' => $value,
            'sort' => (int)DB::val('SELECT COALESCE(MAX(sort),0)+1 FROM attribute_values WHERE attribute_id = ?', [$attributeId]),
        ]);
        return DB::row('SELECT * FROM attribute_values WHERE id = ?', [$id]);
    }

    /** Скільки товарів/варіантів використовують характеристику */
    public static function usage(int $attributeId): array
    {
        return [
            'products' => (int)DB::val('SELECT COUNT(DISTINCT product_id) FROM product_attrs WHERE attribute_id = ?', [$attributeId]),
            'variants' => (int)DB::val('SELECT COUNT(*) FROM variant_options WHERE attribute_id = ?', [$attributeId]),
        ];
    }

    public static function valueUsage(int $valueId): array
    {
        return [
            'products' => (int)DB::val('SELECT COUNT(DISTINCT product_id) FROM product_attrs WHERE value_id = ?', [$valueId]),
            'variants' => (int)DB::val('SELECT COUNT(*) FROM variant_options WHERE value_id = ?', [$valueId]),
        ];
    }

    /** Видалити характеристику разом з її значеннями та звʼязками */
    public static function deleteAttribute(int $id): void
    {
        DB::delete('product_attrs', 'attribute_id = ?', [$id]);
        self::detachVariants('attribute_id = ?', [$id]);
        DB::delete('attribute_values', 'attribute_id = ?', [$id]);
        DB::delete('attribute_categories', 'attribute_id = ?', [$id]);
        DB::delete('attributes', 'id = ?', [$id]);
    }

    public static function deleteValue(int $valueId): void
    {
        DB::delete('product_attrs', 'value_id = ?', [$valueId]);
        self::detachVariants('value_id = ?', [$valueId]);
        DB::delete('attribute_values', 'id = ?', [$valueId]);
    }

    /** Прибрати звʼязки варіантів і перерахувати їх назви (сам варіант лишається) */
    private static function detachVariants(string $where, array $params): void
    {
        $ids = array_map('intval', array_column(DB::all("SELECT DISTINCT variant_id FROM variant_options WHERE $where", $params), 'variant_id'));
        DB::delete('variant_options', $where, $params);
        foreach ($ids as $vid) self::renameVariant($vid);
    }

    /** Назва варіанта = значення його характеристик через « / » */
    public static function variantLabel(int $variantId): string
    {
        $parts = array_column(self::variantOptions($variantId), 'value');
        return implode(' / ', array_filter($parts));
    }

    public static function variantOptions(int $variantId): array
    {
        return DB::all(
            'SELECT vo.*, a.name AS attr_name, a.slug AS attr_slug, a.type AS attr_type, av.color
             FROM variant_options vo
             JOIN attributes a ON a.id = vo.attribute_id
             LEFT JOIN attribute_values av ON av.id = vo.value_id
             WHERE vo.variant_id = ? ORDER BY a.sort, a.name', [$variantId]);
    }

    /** Опції всіх варіантів товару, згруповані за variant_id */
    public static function variantOptionsFor(int $productId): array
    {
        $rows = DB::all(
            'SELECT vo.*, a.name AS attr_name, a.slug AS attr_slug, a.type AS attr_type, av.color
             FROM variant_options vo
             JOIN product_variants pv ON pv.id = vo.variant_id
             JOIN attributes a ON a.id = vo.attribute_id
             LEFT JOIN attribute_values av ON av.id = vo.value_id
             WHERE pv.product_id = ? ORDER BY a.sort, a.name', [$productId]);
        $out = [];
        foreach ($rows as $r) $out[(int)$r['variant_id']][] = $r;
        return $out;
    }

    /** Оновити назву варіанта з його опцій (якщо опції є) */
    public static function renameVariant(int $variantId): void
    {
        $label = self::variantLabel($variantId);
        if ($label !== '') DB::update('product_variants', ['name' => $label], 'id = ?', [$variantId]);
    }

    /**
     * Створити варіанти як усі комбінації обраних значень.
     * $selection: [attribute_id => [value_id, ...]]. Повертає кількість створених.
     */
    public static function generateVariants(int $productId, array $selection): int
    {
        $axes = [];
        foreach ($selection as $aid => $valueIds) {
            $aid = (int)$aid;
            $vals = [];
            foreach ((array)$valueIds as $vid) {
                $v = DB::row('SELECT * FROM attribute_values WHERE id = ? AND attribute_id = ?', [(int)$vid, $aid]);
                if ($v) $vals[] = $v;
            }
            if ($vals) $axes[$aid] = $vals;
        }
        if (!$axes) return 0;

        // декартів добуток
        $combos = [[]];
        foreach ($axes as $aid => $vals) {
            $next = [];
            foreach ($combos as $combo) foreach ($vals as $v) $next[] = $combo + [$aid => $v];
            $combos = $next;
        }

        // вже наявні комбінації, щоб не дублювати
        $existing = [];
        foreach (self::variantOptionsFor($productId) as $vid => $opts) {
            $existing[self::comboKey(array_column($opts, 'value_id', 'attribute_id'))] = true;
        }

        $created = 0;
        $sort = (int)DB::val('SELECT COALESCE(MAX(sort),0) FROM product_variants WHERE product_id = ?', [$productId]);
        foreach ($combos as $combo) {
            $key = self::comboKey(array_map(fn($v) => $v['id'], $combo));
            if (isset($existing[$key])) continue;
            $vid = DB::insert('product_variants', [
                'product_id' => $productId,
                'name' => implode(' / ', array_column($combo, 'value')),
                'price' => null, 'sort' => ++$sort, 'active' => 1,
            ]);
            foreach ($combo as $aid => $v) {
                DB::insert('variant_options', [
                    'variant_id' => $vid, 'attribute_id' => (int)$aid,
                    'value_id' => (int)$v['id'], 'value' => $v['value'],
                ]);
            }
            $existing[$key] = true;
            $created++;
        }
        return $created;
    }

    /** Стабільний ключ комбінації [attribute_id => value_id] */
    public static function comboKey(array $map): string
    {
        $map = array_map('intval', $map);
        ksort($map);
        $parts = [];
        foreach ($map as $aid => $vid) $parts[] = $aid . ':' . $vid;
        return implode('|', $parts);
    }

    /**
     * Перезаписати характеристики товару за рядками форми.
     * Рядок: ['attribute_id'=>int|0, 'new_name'=>string, 'value_id'=>int|0, 'value'=>string]
     */
    public static function saveProductAttrs(int $productId, array $rows, ?int $categoryId = null): void
    {
        $clean = [];
        foreach ($rows as $r) {
            $attr = (int)($r['attribute_id'] ?? 0)
                ? self::find((int)$r['attribute_id'])
                : self::ensure(trim($r['new_name'] ?? ''), $r['new_type'] ?? 'select', $categoryId);
            if (!$attr) continue;

            $valueId = (int)($r['value_id'] ?? 0);
            $text = trim((string)($r['value'] ?? ''));
            if ($valueId) {
                $v = DB::row('SELECT * FROM attribute_values WHERE id = ? AND attribute_id = ?', [$valueId, $attr['id']]);
                if ($v) $text = $v['value']; else $valueId = 0;
            } elseif ($text !== '' && in_array($attr['type'], ['select', 'color'], true)) {
                $v = self::ensureValue((int)$attr['id'], $text);
                $valueId = (int)($v['id'] ?? 0);
                $text = $v['value'] ?? $text;
            }
            if ($text === '') continue;
            $clean[] = ['attr' => $attr, 'value_id' => $valueId ?: null, 'value' => $text];
        }

        DB::delete('product_attrs', 'product_id = ?', [$productId]);
        foreach ($clean as $i => $c) {
            DB::insert('product_attrs', [
                'product_id' => $productId,
                'attribute_id' => (int)$c['attr']['id'],
                'name' => $c['attr']['name'],
                'value_id' => $c['value_id'],
                'value' => $c['value'],
                'filterable' => (int)$c['attr']['filterable'],
                'sort' => $i,
            ]);
        }
    }

    /** Синхронізувати кеш name/filterable у товарах після зміни словника */
    public static function resync(int $attributeId): void
    {
        $a = DB::row('SELECT * FROM attributes WHERE id = ?', [$attributeId]);
        if (!$a) return;
        DB::update('product_attrs', ['name' => $a['name'], 'filterable' => (int)$a['filterable']], 'attribute_id = ?', [$attributeId]);
        foreach (self::values($attributeId) as $v) {
            DB::update('product_attrs', ['value' => $v['value']], 'value_id = ?', [(int)$v['id']]);
            DB::update('variant_options', ['value' => $v['value']], 'value_id = ?', [(int)$v['id']]);
        }
        foreach (DB::all('SELECT DISTINCT variant_id FROM variant_options WHERE attribute_id = ?', [$attributeId]) as $r) {
            self::renameVariant((int)$r['variant_id']);
        }
    }

    /** Разова міграція: зібрати словник із наявних текстових характеристик товарів */
    public static function backfill(): void
    {
        $names = DB::all('SELECT name, MAX(filterable) AS f FROM product_attrs GROUP BY name');
        foreach ($names as $n) {
            $attr = self::ensure((string)$n['name']);
            if (!$attr) continue;
            $aid = (int)$attr['id'];
            DB::update('attributes', ['filterable' => (int)$n['f']], 'id = ?', [$aid]);

            $values = DB::all('SELECT DISTINCT value FROM product_attrs WHERE name = ?', [$n['name']]);
            // довгі описові значення краще лишити текстом, ніж пхати у список
            $isText = false;
            foreach ($values as $v) if (mb_strlen((string)$v['value']) > 60) $isText = true;
            if ($isText) {
                DB::update('attributes', ['type' => 'text', 'filterable' => 0], 'id = ?', [$aid]);
                DB::query('UPDATE product_attrs SET attribute_id = ?, filterable = 0 WHERE name = ?', [$aid, $n['name']]);
                continue;
            }
            foreach ($values as $v) {
                $val = self::ensureValue($aid, (string)$v['value']);
                if ($val) DB::query('UPDATE product_attrs SET attribute_id = ?, value_id = ? WHERE name = ? AND value = ?',
                    [$aid, (int)$val['id'], $n['name'], $v['value']]);
            }
            DB::query('UPDATE product_attrs SET attribute_id = ? WHERE name = ? AND attribute_id IS NULL', [$aid, $n['name']]);
        }
        self::relinkCategories();
    }

    /**
     * Привʼязати кожну характеристику до категорій, де вона реально вживається.
     * Якщо вживається в усіх категоріях — лишаємо загальною (без привʼязки).
     */
    public static function relinkCategories(): void
    {
        $total = (int)DB::val('SELECT COUNT(*) FROM categories');
        foreach (DB::all('SELECT id FROM attributes') as $a) {
            $aid = (int)$a['id'];
            if (DB::val('SELECT COUNT(*) FROM attribute_categories WHERE attribute_id = ?', [$aid])) continue;
            $cats = array_column(DB::all(
                'SELECT DISTINCT p.category_id FROM product_attrs pa JOIN products p ON p.id = pa.product_id
                 WHERE pa.attribute_id = ?', [$aid]), 'category_id');
            if ($cats && count($cats) < $total) self::setCategories($aid, $cats);
        }
    }
}
