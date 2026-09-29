<?php
declare(strict_types=1);

namespace Controllers\Admin;

use DB, View, Auth, Barcode, Catalog, Images, Attrs, StockWatch, QtyDiscounts;

class Products
{
    /**
     * Вага з форми, кг. Порожньо й нуль — це «не знаю», а не «нуль грамів»:
     * такий товар має брати типову вагу з налаштувань, а не робити всю посилку
     * невагомою. Кома як десятковий знак приймається — саме її дає українська
     * розкладка, і вимагати крапку означало б ловити «0,5» як нуль.
     */
    private static function weight($raw): ?float
    {
        $v = (float)str_replace(',', '.', trim((string)$raw));
        return $v > 0 ? round(min($v, 1000), 3) : null;
    }

    /**
     * Стеля знижки, %.
     *
     * Порожньо — «як ярусом вище» (варіація бере від товару, товар — із
     * налаштувань), тому null тут не те саме, що 0. Нуль — це осмислене
     * «знижок на цю позицію не буває взагалі», і задають його явно.
     */
    private static function percent($raw): ?float
    {
        $raw = trim((string)$raw);
        if ($raw === '') return null;
        return max(0.0, min(100.0, (float)str_replace(',', '.', $raw)));
    }

    /**
     * Податкова група для фіскального чека.
     *
     * Порожньо (і будь-що не з переліку ДПС) означає «як у магазину» — саме
     * так стоїть у більшості товарів, і проставляти те саме число в кожній
     * картці ніхто не стане. Чуже число мовчки не зберігаємо: ПРРО відхилив би
     * його вже на живому чеку, тобто посеред черги.
     */
    private static function taxGroup($raw): ?int
    {
        $v = (int)$raw;
        return isset(\Vchasno::TAX_GROUPS[$v]) ? $v : null;
    }

    public static function index(): never
    {
        $q = trim($_GET['q'] ?? '');
        $cat = (int)($_GET['cat'] ?? 0);
        $brand = (int)($_GET['brand'] ?? 0);   // з довідника брендів — «показати ці товари»
        $where = ['1=1'];
        $params = [];
        if ($q !== '') { $where[] = '(p.name LIKE ? OR p.sku LIKE ?)'; $params[] = "%$q%"; $params[] = "%$q%"; }
        if ($cat) {
            // разом із підрозділами — фільтр по «Меду» має показати весь мед
            [$cond, $args] = Catalog::branchSql($cat);
            $where[] = $cond;
            foreach ($args as $a) $params[] = $a;
        }
        if ($brand) {
            $where[] = 'EXISTS (SELECT 1 FROM product_brands pb WHERE pb.product_id = p.id AND pb.brand_id = ?)';
            $params[] = $brand;
        }
        $products = DB::all(
            'SELECT p.*, c.name AS cat_name FROM products p LEFT JOIN categories c ON c.id = p.category_id
             WHERE ' . implode(' AND ', $where) . ' ORDER BY p.id DESC', $params);
        $variantCount = [];
        foreach (DB::all('SELECT product_id, COUNT(*) AS c FROM product_variants WHERE active = 1 GROUP BY product_id') as $r) {
            $variantCount[(int)$r['product_id']] = (int)$r['c'];
        }
        View::show('admin/products/index', [
            'products' => $products, 'categories' => Catalog::categories(),
            'stores' => Catalog::stores(), 'stocks' => Catalog::stockTotals(), 'variant_count' => $variantCount,
            'q' => $q, 'cat' => $cat,
            'brand' => Catalog::brand($brand),
            'page_title' => 'Товари — Адміністрування',
        ], 'layouts/admin');
    }

    /** Масове редагування: назва/ціна/залишки по магазинах у таблиці (варіанти — окремими рядками) */
    public static function bulk(): never
    {
        $stores = Catalog::stores();
        if (is_post()) {
            // Картка товару (назва, базова ціна, видимість) — спільна для всього сайту,
            // тож потребує products.manage. Продавцю лишаються ціни й залишки його точок.
            $canCard = Auth::can('products.manage');
            $rows = $_POST['p'] ?? [];
            foreach ($rows as $id => $data) {
                $id = (int)$id;
                $upd = [];
                if ($canCard) {
                    if (isset($data['name']) && trim($data['name']) !== '') $upd['name'] = trim($data['name']);
                    if (array_key_exists('base_price', $data)) $upd['base_price'] = $data['base_price'] === '' ? null : (float)$data['base_price'];
                    if (isset($data['active'])) $upd['active'] = (int)(bool)$data['active'];
                }
                if ($upd) { $upd['updated_at'] = now(); DB::update('products', $upd, 'id = ?', [$id]); }

                $variantIds = array_map('intval', array_column(
                    DB::all('SELECT id FROM product_variants WHERE product_id = ? AND active = 1', [$id]), 'id'));

                // Власна ціна варіанта — така сама частина картки товару, як і
                // базова ціна, тож і право те саме. Порожнє поле означає «діє
                // базова ціна товару», а не нуль. WHERE по product_id не дає
                // підробленою формою переставити ціну чужому варіанту.
                if ($canCard) {
                    foreach ((array)($data['vbase'] ?? []) as $vid => $val) {
                        $vid = (int)$vid;
                        if (!in_array($vid, $variantIds, true)) continue;
                        DB::update('product_variants',
                            ['price' => ($val === '' ? null : (float)$val)],
                            'id = ? AND product_id = ?', [$vid, $id]);
                    }
                }
                // ціни та залишки товару без варіанта (залишок — лише коли варіантів немає)
                self::syncStore($id, null, (array)($data['store_price'] ?? []), $variantIds ? [] : (array)($data['stock'] ?? []));
                // ціни та залишки по кожному варіанту
                foreach ($variantIds as $vid) {
                    self::syncStore($id, $vid, (array)($data['vprice'][$vid] ?? []), (array)($data['vstock'][$vid] ?? []));
                }
            }
            flash('success', 'Зміни збережено');
            // Фільтр переживає збереження: інакше після кожного «Зберегти все»
            // людину викидало б у повний список і добірку треба було б робити
            // заново — а саме заради неї сюди й заходять.
            redirect('/admin/products/bulk' . self::bulkQuery());
        }

        [$where, $params] = self::bulkFilter();
        $products = DB::all(
            'SELECT p.*, c.name AS cat_name FROM products p LEFT JOIN categories c ON c.id = p.category_id
              WHERE ' . implode(' AND ', $where) . ' ORDER BY c.sort, p.name', $params);
        $variants = [];
        foreach (DB::all('SELECT * FROM product_variants WHERE active = 1 ORDER BY sort, id') as $v) {
            $variants[(int)$v['product_id']][] = $v;
        }
        $prices = []; $stocks = []; $vprices = []; $vstocks = [];
        foreach (DB::all('SELECT * FROM store_prices') as $r) {
            if ($r['variant_id'] === null) $prices[(int)$r['product_id']][(int)$r['store_id']] = $r['price'];
            else $vprices[(int)$r['variant_id']][(int)$r['store_id']] = $r['price'];
        }
        foreach (DB::all('SELECT * FROM store_stock') as $r) {
            if ($r['variant_id'] === null) $stocks[(int)$r['product_id']][(int)$r['store_id']] = $r['qty'];
            else $vstocks[(int)$r['variant_id']][(int)$r['store_id']] = $r['qty'];
        }
        // Колонки лишає обрана точка. Дані інших магазинів від цього не
        // страждають: syncStore() чіпає лише ті store_id, що прийшли у формі.
        $f = self::bulkInput();
        $shown = $f['store']
            ? array_values(array_filter($stores, fn($s) => (int)$s['id'] === $f['store']))
            : $stores;

        View::show('admin/products/bulk', [
            'products' => $products, 'stores' => $shown, 'all_stores' => $stores, 'variants' => $variants,
            'prices' => $prices, 'stocks' => $stocks, 'vprices' => $vprices, 'vstocks' => $vstocks,
            'categories' => Catalog::categories(), 'brands' => Catalog::brands(),
            'f' => self::bulkInput(), 'query' => self::bulkQuery(),
            'page_title' => 'Масове редагування — Адміністрування',
        ], 'layouts/admin');
    }

    /** Значення фільтра як їх увів користувач — і для запиту, і для форми */
    /**
     * Коди й штрихкоди окремим екраном.
     *
     * У масовому редагуванні їм не місце: там ціни й залишки по магазинах, і два
     * зайві стовпці роблять таблицю нечитаною. А головне — заповнюють коди інакше,
     * ніж ціни: береш товар, підносиш сканер до етикетки, переходиш до наступного.
     * Для цього потрібен вузький список і Tab, що йде рівно по потрібних полях.
     *
     * Дублікати показуємо одразу: два товари з однаковим штрихкодом означають, що
     * каса щоразу братиме той, який трапиться першим, — і продавець цього не помітить.
     */
    public static function codes(): never
    {
        Auth::requireCap('products.manage');

        // Код для однієї позиції — окремою відповіддю, без перезавантаження
        // сторінки. Нічого не змінює: код виводиться з номера позиції (див.
        // Barcode::make), тож його можна порахувати скільки завгодно разів і
        // отримати те саме. Записується він лише кнопкою «Зберегти».
        $gen = trim((string)($_GET['gen'] ?? ''));
        if ($gen !== '' && preg_match('/^([pv])(\d+)$/', $gen, $m)) {
            json_response(['code' => Barcode::make($m[1], (int)$m[2])]);
        }

        if (is_post()) {
            $clean = fn($v) => (trim((string)$v) !== '' ? trim((string)$v) : null);
            foreach ((array)($_POST['p'] ?? []) as $id => $d) {
                DB::update('products', [
                    'sku' => $clean($d['sku'] ?? ''), 'barcode' => $clean($d['barcode'] ?? ''),
                    'updated_at' => now(),
                ], 'id = ?', [(int)$id]);
            }
            foreach ((array)($_POST['v'] ?? []) as $id => $d) {
                DB::update('product_variants', [
                    'sku' => $clean($d['sku'] ?? ''), 'barcode' => $clean($d['barcode'] ?? ''),
                ], 'id = ?', [(int)$id]);
            }
            flash('success', 'Коди збережено');
            redirect('/admin/products/codes' . self::codesQuery());
        }

        [$products, $variants] = self::codesRows(self::codesFilter());

        View::show('admin/products/codes', [
            'products' => $products, 'variants' => $variants,
            'categories' => Catalog::categories(),
            'f' => self::codesFilter(),
            'query' => self::codesQuery(),
            'dupes' => self::duplicateCodes(),
            'page_title' => 'Коди й штрихкоди — Адміністрування',
        ], 'layouts/admin');
    }

    /** Що зараз шукають на екрані кодів */
    private static function codesFilter(): array
    {
        return [
            'q' => trim((string)($_GET['q'] ?? '')),
            'cat' => (int)($_GET['cat'] ?? 0),
            // Заради чого сюди заходять: «кому ще не проставив», «де помилка»
            'only' => in_array($_GET['only'] ?? '', ['nocode', 'code', 'bad', 'own'], true)
                ? (string)$_GET['only'] : '',
        ];
    }

    /** Фільтр у адресі — щоб після збереження лишитись у тій самій добірці */
    private static function codesQuery(): string
    {
        $f = array_filter(self::codesFilter(), fn($v) => $v !== '' && $v !== 0);
        return $f ? '?' . http_build_query($f) : '';
    }

    /**
     * Рядки під фільтр.
     *
     * Фільтруємо позиціями (товар без фасовок або окрема фасовка), а не самими
     * товарами: питання «де немає коду» стосується саме тієї штуки, на яку
     * клеять етикетку. Тому товар лишається у списку, поки під фільтр підпадає
     * хоч одна його фасовка, — інакше рядок фасовки не було б де показати.
     *
     * @return array{0:array,1:array} товари та їхні фасовки
     */
    private static function codesRows(array $f): array
    {
        $where = ['1=1'];
        $args = [];
        if ($f['cat']) { $where[] = 'p.category_id = ?'; $args[] = $f['cat']; }
        if ($f['q'] !== '') {
            $like = '%' . $f['q'] . '%';
            $where[] = '(p.name LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ?
                         OR EXISTS (SELECT 1 FROM product_variants v2 WHERE v2.product_id = p.id
                                    AND (v2.name LIKE ? OR v2.sku LIKE ? OR v2.barcode LIKE ?)))';
            array_push($args, $like, $like, $like, $like, $like, $like);
        }

        $products = DB::all('SELECT p.*, c.name AS cat_name FROM products p
                             LEFT JOIN categories c ON c.id = p.category_id
                             WHERE ' . implode(' AND ', $where) . '
                             ORDER BY c.sort, p.name', $args);

        $variants = [];
        foreach (DB::all('SELECT * FROM product_variants ORDER BY sort, id') as $v) {
            $variants[(int)$v['product_id']][] = $v;
        }

        if ($f['only'] === '') return [$products, $variants];

        // Добірки рахуємо вже в PHP: «з помилкою» питає контрольну цифру, а її
        // в SQL не порахуєш
        $dupes = self::duplicateCodes();
        $match = function (array $row) use ($f, $dupes): bool {
            $code = trim((string)($row['barcode'] ?? ''));
            return match ($f['only']) {
                'nocode' => $code === '',
                'code' => $code !== '',
                'own' => $code !== '' && Barcode::isInternal($code),
                'bad' => $code !== '' && (Barcode::problem($code) !== null
                    || in_array(mb_strtolower($code), $dupes['barcode'], true)),
                default => true,
            };
        };

        $out = [];
        foreach ($products as $p) {
            $pid = (int)$p['id'];
            $vs = $variants[$pid] ?? [];
            if (!$vs) {
                if ($match($p)) $out[] = $p;
                continue;
            }
            $keep = array_values(array_filter($vs, $match));
            // Код самого товару теж рахується: він лишається робочим, поки
            // фасовка одна, і саме там ховаються помилки на кшталт «код на
            // товарі, а фасовок кілька»
            if ($keep || $match($p)) {
                $variants[$pid] = $keep ?: $vs;
                // Товар потрапив у добірку лише заради своїх фасовок — тоді його
                // власний рядок лише заголовок. Інакше в добірці «без штрихкоду»
                // висіли б поля з кодами, і незрозуміло, що ж саме знайдено.
                $p['header_only'] = $keep && !$match($p);
                $out[] = $p;
            }
        }
        return [$out, $variants];
    }

    /**
     * Коди, які зустрічаються більш ніж раз, — по одному переліку на артикули
     * й штрихкоди. Порівнюємо без урахування регістру: «med-05» і «MED-05» для
     * людини один код, і саме так їх і плутатимуть.
     *
     * @return array{sku:string[],barcode:string[]} коди у нижньому регістрі
     */
    public static function duplicateCodes(): array
    {
        $out = ['sku' => [], 'barcode' => []];
        foreach (['sku', 'barcode'] as $field) {
            $seen = [];
            foreach ([['products', ''], ['product_variants', '']] as [$table, $_]) {
                foreach (DB::all("SELECT $field AS code FROM $table WHERE $field IS NOT NULL AND $field <> ''") as $r) {
                    $code = mb_strtolower(trim((string)$r['code']));
                    if ($code === '') continue;
                    $seen[$code] = ($seen[$code] ?? 0) + 1;
                }
            }
            $out[$field] = array_keys(array_filter($seen, fn($n) => $n > 1));
        }
        return $out;
    }

    /**
     * Тип товару й тип його категорії мусять збігатися.
     *
     * Курс у категорії «Мед» нічого не ламає — на вітрину він однаково не
     * потрапляє, бо відсіюється за типом. Ламається інше: облік. Фільтр за
     * категорією перестає відповідати на питання «скільки в мене курсів», а
     * той, хто відкриє «Мед» в адмінці, побачить там курс і вирішить, що це
     * помилка даних.
     *
     * Перевірка саме на сервері, а не лише вибором у формі: адреса збереження
     * відома, і category_id підставляється в POST руками. Форма фільтрує
     * список для зручності, забороняє — цей метод.
     *
     * @return string порожньо, якщо все гаразд; інакше — що саме не так
     */
    private static function categoryTypeError(string $type, int $categoryId): string
    {
        $labels = Catalog::TYPES;
        $type = Catalog::normType($type);
        $cat = $categoryId ? DB::row('SELECT name, type FROM categories WHERE id = ?', [$categoryId]) : null;
        if (!$cat) return 'Оберіть категорію.';
        $catType = (string)($cat['type'] ?? 'product');
        if ($catType === $type) return '';

        // Немає жодної придатної категорії — кажемо про це прямо, бо інакше
        // людина шукатиме в списку те, чого там не буде
        $has = (int)DB::val('SELECT COUNT(*) FROM categories WHERE type = ? AND active = 1', [$type]);
        return $has === 0
            ? 'Для типу «' . $labels[$type] . '» ще немає жодної категорії. '
              . 'Створіть її в розділі «Категорії» з типом «' . $labels[$type] . '», а тоді поверніться сюди.'
            : 'Категорія «' . $cat['name'] . '» призначена для іншого типу ('
              . ($labels[$catType] ?? $catType) . '). Оберіть категорію типу «' . $labels[$type] . '».';
    }

    private static function bulkInput(): array
    {
        return [
            'q' => trim((string)($_GET['q'] ?? '')),
            'cat' => (int)($_GET['cat'] ?? 0),
            'brand' => (int)($_GET['brand'] ?? 0),
            // магазин звужує таблицю до однієї точки: із двома колонками замість
            // восьми видно, що робиш, і не треба цілитись у потрібну пару
            'store' => (int)($_GET['store'] ?? 0),
            // «є що правити» — найчастіша причина сюди зайти
            'only' => in_array($_GET['only'] ?? '', ['zero', 'noprice', 'variants'], true) ? $_GET['only'] : '',
        ];
    }

    /** @return array{0:string[],1:array} умови та параметри для вибірки товарів */
    private static function bulkFilter(): array
    {
        $f = self::bulkInput();
        $where = ['1=1'];
        $params = [];
        if ($f['q'] !== '') {
            // штрихкод теж: найпростіший спосіб знайти товар — піднести до нього сканер
            $where[] = '(p.name LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ?)';
            $params[] = '%' . $f['q'] . '%';
            $params[] = '%' . $f['q'] . '%';
            $params[] = '%' . $f['q'] . '%';
        }
        if ($f['cat']) {
            [$cond, $args] = Catalog::branchSql($f['cat']);
            $where[] = $cond;
            foreach ($args as $a) $params[] = $a;
        }
        if ($f['brand']) {
            $where[] = 'EXISTS (SELECT 1 FROM product_brands pb WHERE pb.product_id = p.id AND pb.brand_id = ?)';
            $params[] = $f['brand'];
        }
        // Порожній склад рахуємо по всій мережі — саме так, як його бачить
        // покупець. Але коли обрано точку, питання інше: «чого немає ТУТ», і
        // рахувати треба по ній, інакше добірка суперечила б колонкам на екрані.
        if ($f['only'] === 'zero') {
            if ($f['store']) {
                $where[] = 'COALESCE((SELECT SUM(ss.qty) FROM store_stock ss
                                       WHERE ss.product_id = p.id AND ss.store_id = ?), 0) <= 0';
                $params[] = $f['store'];
            } else {
                $where[] = 'COALESCE((SELECT SUM(ss.qty) FROM store_stock ss WHERE ss.product_id = p.id), 0) <= 0';
            }
        }
        if ($f['only'] === 'noprice') $where[] = '(p.base_price IS NULL OR p.base_price = 0)';
        if ($f['only'] === 'variants') {
            $where[] = 'EXISTS (SELECT 1 FROM product_variants pv WHERE pv.product_id = p.id AND pv.active = 1)';
        }
        return [$where, $params];
    }

    /** Рядок запиту поточного фільтра — щоб він пережив збереження */
    private static function bulkQuery(): string
    {
        $f = array_filter(self::bulkInput(), fn($v) => $v !== '' && $v !== 0);
        return $f ? '?' . http_build_query($f) : '';
    }

    public static function create(): never
    {
        Auth::requireCap('products.manage');
        if (is_post()) {
            $name = trim($_POST['name'] ?? '');
            if ($name === '') { flash('error', 'Вкажіть назву'); redirect('/admin/products/new'); }
            $typeErr = self::categoryTypeError($_POST['type'] ?? 'product', (int)($_POST['category_id'] ?? 0));
            if ($typeErr !== '') { flash('error', $typeErr); redirect('/admin/products/new'); }
            $slug = slugify($name);
            $i = 1; $base = $slug;
            while (DB::row('SELECT 1 FROM products WHERE slug = ?', [$slug])) $slug = $base . '-' . (++$i);
            $id = DB::insert('products', [
                'category_id' => (int)($_POST['category_id'] ?? 0),
                'name' => $name, 'slug' => $slug,
                'sku' => trim($_POST['sku'] ?? '') ?: null,
                'barcode' => trim($_POST['barcode'] ?? '') ?: null,
                'short_desc' => trim($_POST['short_desc'] ?? '') ?: null,
                'description' => trim($_POST['description'] ?? '') ?: null,
                'base_price' => $_POST['base_price'] === '' ? null : (float)$_POST['base_price'],
                'type' => Catalog::normType($_POST['type'] ?? null),
                'active' => isset($_POST['active']) ? 1 : 0,
                'featured' => isset($_POST['featured']) ? 1 : 0,
                'made_to_order' => isset($_POST['made_to_order']) ? 1 : 0,
                'low_stock_threshold' => ($_POST['low_stock_threshold'] ?? '') === '' ? null : (int)$_POST['low_stock_threshold'],
                // Вік дитини: у формі роки (з кроком 0.5), у базі — місяці (див. Ages)
                ...self::agesFromPost(),
                // Строк доступу до курсу; порожньо — назавжди (див. Courses::grant)
                'access_days' => ($_POST['access_days'] ?? '') === '' ? null : max(1, (int)$_POST['access_days']),
                // Тексти сторінки курсу. array_key_exists, а не ?? '': блок
                // показується лише курсам, і для решти товарів полів у формі
                // немає — беззастережний запис затер би їх порожнечею при
                // кожному збереженні картки меду.
                ...(array_key_exists('learn_outcomes', $_POST)
                    ? ['learn_outcomes' => trim((string)$_POST['learn_outcomes']) ?: null] : []),
                ...(array_key_exists('program', $_POST)
                    ? ['program' => trim((string)$_POST['program']) ?: null] : []),
                // Вага однієї штуки — за нею форма накладної рахує вагу посилки
                'weight' => self::weight($_POST['weight'] ?? ''),
                'taxgrp' => self::taxGroup($_POST['taxgrp'] ?? ''),
                'uktzed' => trim($_POST['uktzed'] ?? '') ?: null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            self::syncBrands($id, (array)($_POST['brand_ids'] ?? []));
            flash('success', 'Товар створено — додайте фото, атрибути та ціни');
            redirect('/admin/products/' . $id);
        }
        View::show('admin/products/form', [
            'p' => null, 'categories' => Catalog::categories(), 'stores' => Catalog::stores(),
            'variants' => [], 'attrs' => [], 'images' => [], 'store_prices' => [], 'store_stock' => [],
            'variant_options' => [], 'variant_prices' => [], 'variant_stock' => [], 'dict' => Attrs::all(),
            'page_title' => 'Новий товар — Адміністрування',
        ], 'layouts/admin');
    }

    public static function edit(int $id): never
    {
        $p = DB::row('SELECT * FROM products WHERE id = ?', [$id]);
        if (!$p) redirect('/admin/products');

        if (is_post()) { self::save($id, $p); }

        $store_prices = []; $store_stock = [];
        $variant_prices = []; $variant_stock = [];
        foreach (DB::all('SELECT * FROM store_prices WHERE product_id = ?', [$id]) as $r) {
            if ($r['variant_id'] === null) $store_prices[$r['store_id']] = $r['price'];
            else $variant_prices[(int)$r['variant_id']][(int)$r['store_id']] = $r['price'];
        }
        foreach (DB::all('SELECT * FROM store_stock WHERE product_id = ?', [$id]) as $r) {
            if ($r['variant_id'] === null) $store_stock[$r['store_id']] = $r['qty'];
            else $variant_stock[(int)$r['variant_id']][(int)$r['store_id']] = $r['qty'];
        }

        View::show('admin/products/form', [
            'p' => $p, 'categories' => Catalog::categories(), 'stores' => Catalog::stores(),
            'variants' => DB::all('SELECT * FROM product_variants WHERE product_id = ? ORDER BY sort, id', [$id]),
            'variant_options' => Attrs::variantOptionsFor($id),
            'attrs' => DB::all('SELECT * FROM product_attrs WHERE product_id = ? ORDER BY sort, id', [$id]),
            'images' => DB::all('SELECT * FROM product_images WHERE product_id = ? ORDER BY sort, id', [$id]),
            'store_prices' => $store_prices, 'store_stock' => $store_stock,
            'variant_prices' => $variant_prices, 'variant_stock' => $variant_stock,
            'dict' => Attrs::all(),
            // Своя шкала окремо від успадкованої: порожнє поле в картці означає
            // «як у розділі», і форма мусить показати, як саме, — інакше порожнеча
            // читається як «знижок немає», а знижка при цьому діє.
            'qty_tiers' => QtyDiscounts::level($id, null),
            'qty_inherit' => Catalog::qtyResolve($p),
            'page_title' => 'Товар: ' . $p['name'] . ' — Адміністрування',
        ], 'layouts/admin');
    }

    /**
     * Хто що редагує в товарі.
     * Картка товару (назва, опис, ціни, варіанти, характеристики, фото) — спільна для
     * всього сайту, тож нею керує лише адмін. Продавцю лишаються ціни й залишки його
     * магазинів: саме їх він і веде щодня. Перевірка тут — джерело істини, форма лише
     * повторює її візуально.
     */
    private static function save(int $id, array $p): void
    {
        $action = $_POST['_action'] ?? 'save';
        $canCard = Auth::can('products.manage');

        // Дозволений список, а не заборонений: без products.manage проходить лише
        // звичайне збереження, і в ньому далі приймаються самі ціни та залишки.
        // Нова дія, додана сюди пізніше, за замовчуванням буде закрита — саме так
        // і треба: помилка має відмовляти, а не відкривати доступ.
        if (!$canCard && $action !== 'save') {
            flash('error', 'Змінювати картку товару може лише адміністратор.');
            redirect('/admin/products/' . $id);
        }

        if ($action === 'delete' && $canCard) {
            $paths = array_column(DB::all('SELECT path FROM product_images WHERE product_id = ?', [$id]), 'path');
            foreach (DB::all('SELECT id FROM product_variants WHERE product_id = ?', [$id]) as $v) {
                DB::delete('variant_options', 'variant_id = ?', [(int)$v['id']]);
            }
            foreach (['product_images','product_variants','product_attrs','product_brands','store_prices','store_stock'] as $t) DB::delete($t, 'product_id = ?', [$id]);
            DB::delete('products', 'id = ?', [$id]);
            // видаляти файл лише якщо фото не прикріплене ще до якогось іншого товару/банера/галереї
            foreach (array_unique($paths) as $path) if (!Media::usage($path)) Images::delete($path);
            flash('success', 'Товар видалено');
            redirect('/admin/products');
        }

        if ($action === 'attach_image') {
            $path = (string)($_POST['media_path'] ?? '');
            $abs = BOFU_ROOT . '/assets/' . $path;
            if ((str_starts_with($path, 'uploads/') || str_starts_with($path, 'img/')) && !str_contains($path, '..') && is_file($abs)) {
                $size = @getimagesize($abs);
                DB::insert('product_images', [
                    'product_id' => $id, 'path' => $path,
                    'width' => $size[0] ?? 0, 'height' => $size[1] ?? 0, 'bytes' => filesize($abs) ?: 0,
                    'sort' => (int)DB::val('SELECT COALESCE(MAX(sort),0)+1 FROM product_images WHERE product_id = ?', [$id]),
                ]);
                self::syncImages($id);
                flash('success', 'Фото додано до товару');
            } else flash('error', 'Фото не знайдено');
            redirect('/admin/products/' . $id);
        }

        if ($action === 'upload_image') {
            $res = Images::saveUpload($_FILES['image'] ?? [], 'p' . $id);
            if ($res) {
                [$path, $w, $h, $bytes] = $res;
                DB::insert('product_images', ['product_id' => $id, 'path' => $path, 'width' => $w, 'height' => $h, 'bytes' => $bytes, 'sort' => (int)DB::val('SELECT COALESCE(MAX(sort),0)+1 FROM product_images WHERE product_id = ?', [$id])]);
                self::syncImages($id);
                flash('success', 'Фото додано (' . $w . '×' . $h . ', ' . round($bytes/1024) . ' КБ)');
            } else flash('error', 'Не вдалося завантажити фото');
            redirect('/admin/products/' . $id);
        }

        if ($action === 'delete_image') {
            $imgId = (int)($_POST['image_id'] ?? 0);
            $img = DB::row('SELECT * FROM product_images WHERE id = ? AND product_id = ?', [$imgId, $id]);
            if ($img) {
                DB::delete('product_images', 'id = ?', [$imgId]);
                self::syncImages($id);
                // видаляти файл лише якщо це фото не прикріплене ще десь (інший товар, банер, галерея)
                if (!Media::usage($img['path'])) Images::delete($img['path']);
                flash('success', 'Фото видалено');
            }
            redirect('/admin/products/' . $id);
        }

        // Головне фото — це перше в списку: робимо його головним, посунувши на початок
        if ($action === 'main_image') {
            $imgId = (int)($_POST['image_id'] ?? 0);
            if (DB::row('SELECT 1 FROM product_images WHERE id = ? AND product_id = ?', [$imgId, $id])) {
                DB::update('product_images', ['sort' => -1], 'id = ?', [$imgId]);
                self::syncImages($id);
                flash('success', 'Головне фото змінено');
            }
            redirect('/admin/products/' . $id);
        }

        /*
         * Кому належить кадр: конкретній фасовці чи всім одразу.
         *
         * Прибирати мітку треба так само легко, як ставити, — тому це один
         * вибір зі списком «спільне» на початку, а не окрема кнопка «відвʼязати».
         */
        if ($action === 'image_variant') {
            $imgId = (int)($_POST['image_id'] ?? 0);
            $vid = (int)($_POST['variant_id'] ?? 0);
            // Фасовка мусить бути саме цього товару: id приходить із форми, а
            // формі вірити не можна — інакше фото чіплялось би до чужої картки.
            if ($vid && !DB::row('SELECT 1 FROM product_variants WHERE id = ? AND product_id = ?', [$vid, $id])) $vid = 0;
            if (DB::row('SELECT 1 FROM product_images WHERE id = ? AND product_id = ?', [$imgId, $id])) {
                DB::update('product_images', ['variant_id' => $vid ?: null], 'id = ?', [$imgId]);
                self::syncImages($id);   // головним лишається спільне фото
                flash('success', $vid ? 'Фото закріплено за варіантом' : 'Фото знову спільне');
            }
            redirect('/admin/products/' . $id);
        }

        // Порядок додаткових фото: міняємо місцями із сусідом
        if ($action === 'move_image') {
            $imgId = (int)($_POST['image_id'] ?? 0);
            $up = ($_POST['dir'] ?? 'up') === 'up';
            $ids = array_map('intval', array_column(
                DB::all('SELECT id FROM product_images WHERE product_id = ? ORDER BY sort, id', [$id]), 'id'));
            $i = array_search($imgId, $ids, true);
            $j = $i === false ? false : $i + ($up ? -1 : 1);
            if ($i !== false && $j !== false && isset($ids[$j])) {
                [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
                foreach ($ids as $sort => $iid) DB::update('product_images', ['sort' => $sort], 'id = ?', [$iid]);
                self::syncImages($id);
                flash('success', $i === 0 || $j === 0 ? 'Порядок змінено — головним стало інше фото' : 'Порядок фото змінено');
            }
            redirect('/admin/products/' . $id);
        }

        // Генератор варіантів: усі комбінації обраних значень. Працює разом зі збереженням,
        // тому незбережені правки у формі не губляться.
        $generated = 0;
        if ($action === 'gen_variants') {
            $selection = [];
            foreach ((array)($_POST['gen'] ?? []) as $aid => $valueIds) {
                $ids = array_filter(array_map('intval', (array)$valueIds));
                if ($ids) $selection[(int)$aid] = $ids;
            }
            if ($selection) $generated = Attrs::generateVariants($id, $selection);
        }

        // Основне збереження (картка товару — лише з products.manage)
        if ($canCard) {
            $typeErr = self::categoryTypeError($_POST['type'] ?? $p['type'], (int)($_POST['category_id'] ?? $p['category_id']));
            if ($typeErr !== '') { flash('error', $typeErr); redirect('/admin/products/' . $id); }
            DB::update('products', [
                'name' => trim($_POST['name'] ?? $p['name']),
                'category_id' => (int)($_POST['category_id'] ?? $p['category_id']),
                'sku' => trim($_POST['sku'] ?? '') ?: null,
                'barcode' => trim($_POST['barcode'] ?? '') ?: null,
                'short_desc' => trim($_POST['short_desc'] ?? '') ?: null,
                'description' => trim($_POST['description'] ?? '') ?: null,
                'base_price' => ($_POST['base_price'] ?? '') === '' ? null : (float)$_POST['base_price'],
                'old_price' => ($_POST['old_price'] ?? '') === '' ? null : (float)$_POST['old_price'],
                'type' => Catalog::normType($_POST['type'] ?? null, (string)$p['type']),
                'active' => isset($_POST['active']) ? 1 : 0,
                'featured' => isset($_POST['featured']) ? 1 : 0,
                'made_to_order' => isset($_POST['made_to_order']) ? 1 : 0,
                'low_stock_threshold' => ($_POST['low_stock_threshold'] ?? '') === '' ? null : (int)$_POST['low_stock_threshold'],
                // Вік дитини: у формі роки (з кроком 0.5), у базі — місяці (див. Ages)
                ...self::agesFromPost(),
                // Строк доступу до курсу; порожньо — назавжди (див. Courses::grant)
                'access_days' => ($_POST['access_days'] ?? '') === '' ? null : max(1, (int)$_POST['access_days']),
                // Тексти сторінки курсу. array_key_exists, а не ?? '': блок
                // показується лише курсам, і для решти товарів полів у формі
                // немає — беззастережний запис затер би їх порожнечею при
                // кожному збереженні картки меду.
                ...(array_key_exists('learn_outcomes', $_POST)
                    ? ['learn_outcomes' => trim((string)$_POST['learn_outcomes']) ?: null] : []),
                ...(array_key_exists('program', $_POST)
                    ? ['program' => trim((string)$_POST['program']) ?: null] : []),
                'weight' => self::weight($_POST['weight'] ?? ''),
                'taxgrp' => self::taxGroup($_POST['taxgrp'] ?? ''),
                'uktzed' => trim($_POST['uktzed'] ?? '') ?: null,
                // Опт. Порожня шкала означає «як у розділі», тому «знижки за
                // кількість немає» доводиться казати окремим прапорцем.
                'wholesale' => isset($_POST['wholesale']) ? 1 : 0,
                'qty_scope' => ($_POST['qty_scope'] ?? '') === 'variant' ? 'variant' : 'product',
                'max_discount' => self::percent($_POST['max_discount'] ?? ''),
                // Торг. Окремо від опту: перше — правило для всіх, друге —
                // жива розмова з однією людиною, і вимикають їх незалежно
                'bargain' => isset($_POST['bargain']) ? 1 : 0,
                'updated_at' => now(),
            ], 'id = ?', [$id]);
            self::syncBrands($id, (array)($_POST['brand_ids'] ?? []));
            foreach (QtyDiscounts::save($id, null, (array)($_POST['tier'] ?? [])) as $err) {
                flash('error', $err);
            }

            // Варіанти: назви з характеристик не чіпаємо — вони збираються автоматично
            $withOptions = Attrs::variantOptionsFor($id);
            $sort = 0;
            $freedImages = false;   // видалення фасовки звільняє її фото
            foreach ((array)($_POST['variant'] ?? []) as $vid => $v) {
                if (str_starts_with((string)$vid, 'new')) continue;
                $vid = (int)$vid;
                if (!empty($v['_delete'])) { self::deleteVariant($id, $vid); $freedImages = true; continue; }
                $name = trim($v['name'] ?? '');
                $auto = !empty($withOptions[$vid]);
                if (!$auto && $name === '') continue; // текстовий варіант без назви — не чіпаємо
                $upd = [
                    'price' => ($v['price'] ?? '') === '' ? null : (float)$v['price'],
                    'sku' => trim($v['sku'] ?? '') ?: null,
                    'barcode' => trim($v['barcode'] ?? '') ?: null,
                    // Вага належить фасовці: банка на 0.5 і на 1.5 кг — це
                    // різні посилки, хоч мед у них однаковий
                    'weight' => self::weight($v['weight'] ?? ''),
                    'max_discount' => self::percent($v['max_discount'] ?? ''),
                    'active' => !empty($v['active']) ? 1 : 0,
                    'sort' => $sort++,
                ];
                if (!$auto) $upd['name'] = $name;
                DB::update('product_variants', $upd, 'id = ? AND product_id = ?', [$vid, $id]);
            }
            foreach ((array)($_POST['variant'] ?? []) as $vid => $v) {
                if (!str_starts_with((string)$vid, 'new')) continue;
                if (trim($v['name'] ?? '') === '') continue;
                DB::insert('product_variants', [
                    'product_id' => $id, 'name' => trim($v['name']),
                    'price' => ($v['price'] ?? '') === '' ? null : (float)$v['price'],
                    'sku' => trim($v['sku'] ?? '') ?: null,
                    'barcode' => trim($v['barcode'] ?? '') ?: null,
                    'weight' => self::weight($v['weight'] ?? ''),
                    'max_discount' => self::percent($v['max_discount'] ?? ''),
                    'active' => !empty($v['active']) ? 1 : 0,
                    'sort' => $sort++,
                ]);
            }

            // Фото, що лишились без своєї фасовки, стали спільними — а серед
            // спільних обирається головне, тож перерахувати його треба тут же.
            if ($freedImages) self::syncImages($id);

            // Характеристики: словник + значення зі списку (рядки перезаписуються цілком)
            Attrs::saveProductAttrs($id, (array)($_POST['attr'] ?? []), (int)($_POST['category_id'] ?? $p['category_id']));
        }

        // Ціни та залишки: по товару загалом і окремо по кожному варіанту.
        // Якщо варіанти є — залишок товару без варіанта не приймаємо: наявність рахується з варіантів.
        $hasVariants = (bool)DB::val('SELECT 1 FROM product_variants WHERE product_id = ? AND active = 1 LIMIT 1', [$id]);
        self::syncStore($id, null, (array)($_POST['store_price'] ?? []), $hasVariants ? [] : (array)($_POST['store_stock'] ?? []));
        $variantIds = array_map('intval', array_column(DB::all('SELECT id FROM product_variants WHERE product_id = ?', [$id]), 'id'));
        foreach ((array)($_POST['vprice'] ?? []) as $vid => $prices) {
            if (!in_array((int)$vid, $variantIds, true)) continue;
            self::syncStore($id, (int)$vid, (array)$prices, (array)($_POST['vstock'][$vid] ?? []));
        }

        if ($action === 'gen_variants') {
            flash($generated ? 'success' : 'error', $generated
                ? 'Збережено. Створено варіантів: ' . $generated . ' — задайте їм ціни й залишки.'
                : 'Збережено, але нових комбінацій не додано: оберіть значення, яких ще немає.');
        } else {
            flash('success', 'Збережено');
        }
        redirect('/admin/products/' . $id);
    }

    /**
     * Наводить порядок у фото товару: sort = 0,1,2… без дірок,
     * а `products.image` (головне фото) — перше спільне в списку.
     *
     * Саме спільне, а не просто перше: головне фото стоїть у каталозі, у
     * кошику й у соцмережах, тобто представляє товар цілком. Кадр, знятий
     * заради одного кольору, у цій ролі обманює — картка обіцяє синє, а
     * товар продається в пʼяти кольорах. Якщо спільних немає зовсім, беремо
     * перше будь-яке: показати хоч щось краще, ніж заглушку.
     */
    private static function syncImages(int $productId): void
    {
        $rows = DB::all('SELECT id, path, sort, variant_id FROM product_images WHERE product_id = ? ORDER BY sort, id', [$productId]);
        foreach ($rows as $i => $r) {
            if ((int)$r['sort'] !== $i) DB::update('product_images', ['sort' => $i], 'id = ?', [(int)$r['id']]);
        }
        $main = null;
        foreach ($rows as $r) if (!(int)($r['variant_id'] ?? 0)) { $main = $r['path']; break; }
        DB::update('products', ['image' => $main ?? ($rows[0]['path'] ?? null), 'updated_at' => now()], 'id = ?', [$productId]);
    }

    /** Видалення варіанта разом з його цінами, залишками та характеристиками */
    private static function deleteVariant(int $productId, int $variantId): void
    {
        DB::delete('variant_options', 'variant_id = ?', [$variantId]);
        DB::delete('store_prices', 'product_id = ? AND variant_id = ?', [$productId, $variantId]);
        DB::delete('store_stock', 'product_id = ? AND variant_id = ?', [$productId, $variantId]);
        // Фото переживає свою фасовку: файл лишається придатним товару, а мітка
        // на неіснуючий варіант зробила б кадр невидимим — ні спільний, ні чийсь.
        DB::update('product_images', ['variant_id' => null], 'product_id = ? AND variant_id = ?', [$productId, $variantId]);
        DB::delete('product_variants', 'id = ? AND product_id = ?', [$variantId, $productId]);
    }

    /** Ціни/залишки по магазинах для товару ($variantId = null) або конкретного варіанта */
    private static function syncStore(int $productId, ?int $variantId, array $prices, array $stock): void
    {
        $cond = $variantId === null ? 'variant_id IS NULL' : 'variant_id = ?';
        $extra = $variantId === null ? [] : [$variantId];
        // Право на дію і межі, у яких вона дозволена, — різні речі, тому перевіряємо
        // обидві: чи взагалі можна правити ціни/залишки і чи належить точка тобі.
        // storeIds() для адміна повертає всі магазини, тож окремої гілки не треба.
        $canPrice = Auth::can('products.price');
        $canStock = Auth::can('products.stock');
        $mine = Auth::storeIds();

        foreach ($canPrice ? $prices : [] as $sid => $priceVal) {
            $sid = (int)$sid;
            if (!in_array($sid, $mine, true)) continue;
            $exists = DB::row("SELECT id FROM store_prices WHERE product_id = ? AND store_id = ? AND $cond",
                array_merge([$productId, $sid], $extra));
            if ($priceVal === '') { if ($exists) DB::delete('store_prices', 'id = ?', [$exists['id']]); }
            elseif ($exists) DB::update('store_prices', ['price' => (float)$priceVal], 'id = ?', [$exists['id']]);
            else DB::insert('store_prices', ['product_id' => $productId, 'store_id' => $sid, 'variant_id' => $variantId, 'price' => (float)$priceVal]);
        }
        foreach ($canStock ? $stock : [] as $sid => $qty) {
            $sid = (int)$sid;
            if (!in_array($sid, $mine, true)) continue;
            $exists = DB::row("SELECT id FROM store_stock WHERE product_id = ? AND store_id = ? AND $cond",
                array_merge([$productId, $sid], $extra));
            if ($qty === '') { if ($exists) DB::delete('store_stock', 'id = ?', [$exists['id']]); continue; }
            if ($exists) DB::update('store_stock', ['qty' => (int)$qty], 'id = ?', [$exists['id']]);
            else DB::insert('store_stock', ['product_id' => $productId, 'store_id' => $sid, 'variant_id' => $variantId, 'qty' => (int)$qty]);
        }
        // Товар міг щойно зʼявитись — тим, хто його чекав, час дізнатись.
        // Ставимо саме тут: це єдине місце, через яке проходить і збереження
        // картки, і масове редагування. StockWatch сам перевірить, чи є що
        // повідомляти, тож зайвого виклику не буде.
        if ($canStock) StockWatch::fulfil($productId, $variantId);
    }

    /**
     * Бренди товару: приймаємо лише ті id, що справді є в довіднику, —
     * інакше підробленою формою можна було б звʼязати товар із чим завгодно.
     */
    private static function syncBrands(int $productId, array $ids): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        DB::delete('product_brands', 'product_id = ?', [$productId]);
        foreach ($ids as $bid) {
            if (!DB::row('SELECT id FROM brands WHERE id = ?', [$bid])) continue;
            DB::insert('product_brands', ['product_id' => $productId, 'brand_id' => $bid]);
        }
    }

    /**
     * «Вік від / до» з форми: люди думають роками («від 1,5 року»), а зберігаємо
     * місяці — так, як їх потребує фільтр (Ages). Порожнє поле — без межі.
     */
    private static function agesFromPost(): array
    {
        $m = static function (string $k): ?int {
            $v = trim(str_replace(',', '.', (string)($_POST[$k] ?? '')));
            return $v === '' ? null : max(0, (int)round((float)$v * 12));
        };
        $min = $m('age_from'); $max = $m('age_to');
        // «до 4 років» включно — це останній місяць четвертого року
        if ($max !== null) $max = max($max + 11, (int)$min);
        return ['age_min' => $min, 'age_max' => $max];
    }
}
