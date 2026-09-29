<?php
declare(strict_types=1);

namespace Controllers;

use DB, View, Catalog, Content, Attrs, Auth, Csrf, StockWatch, JsonLd, Bundles, Images, Offers, Ages, Reviews;

class Shop
{
    /** Скільки карток на сторінці каталогу */
    private const PER_PAGE = 24;

    public static function index(?string $pathSlug = null): never
    {
        // Саме shopCategories: у меню каталогу курсам не місце (див. Catalog)
        $cats = Catalog::shopCategories();

        /*
         * Категорія приходить зі шляху (/shop/med). Параметр ?cat= лишається
         * робочим, але живе рівно стільки, скільки треба на редирект: адреси
         * з ним уже роздані посиланнями й лежать у чужих закладках, а дві
         * адреси однієї сторінки — це дублікат для пошуковика й розбіжна
         * статистика для нас.
         *
         * 301, а не 302: адреса змінилась назавжди, і саме так її має
         * запамʼятати і браузер, і Google. Решта параметрів (сортування,
         * фільтри) переїжджає разом із нею — інакше редирект з'їдав би вибір
         * покупця.
         */
        if ($pathSlug === null && qs('cat') !== '') {
            $rest = $_GET;
            unset($rest['cat']);
            http_response_code(301);
            header('Location: ' . shop_url(qs('cat'), $rest));
            exit;
        }

        $catSlug = $pathSlug ?? '';
        $current = null;
        foreach ($cats as $c) if ($c['slug'] === $catSlug) $current = $c;
        // Неіснуючий розділ — саме 404, а не мовчазний показ усього каталогу:
        // інакше будь-яка описка в адресі віддавала б «усі товари» під виглядом
        // сторінки розділу, і пошуковик індексував би їх сотнями копій.
        if ($catSlug !== '' && !$current) { http_response_code(404); View::show('errors/404'); }
        // Розділ обраного підрозділу — для крихт і для панелі, яка має
        // відкритись саме на тій гілці, де людина зараз стоїть
        $parentCat = Catalog::parentCategory($current);

        $filters = [
            'category_id' => $current['id'] ?? null,
            'q' => mb_substr(qs('q'), 0, 100),
            'min' => is_numeric(qs('min')) ? qs('min') : '',
            'max' => is_numeric(qs('max')) ? qs('max') : '',
            'store_id' => (int)qs('store') ?: null,
            'sort' => in_array(qs('sort'), ['price_asc', 'price_desc', 'new'], true) ? qs('sort') : '',
            'age' => Ages::band(qs('age')) ? qs('age') : '',
            'instock' => qs('instock') !== '',
            'attr' => is_array($_GET['attr'] ?? null) ? $_GET['attr'] : [],
            // і один slug із посилання, і галки в панелі фільтрів
            'brand' => array_values(array_filter(array_map(
                fn($v) => is_scalar($v) ? trim((string)$v) : '', (array)($_GET['brand'] ?? [])), fn($v) => $v !== '')),
        ];
        // Один вибір без віку — з нього і видача, і лічильники смуг ростоміра
        // (раніше це були шість окремих пошуків на кожне відкриття каталогу)
        $all = Catalog::search(['age' => ''] + $filters);
        $found = $filters['age'] === '' ? $all
            : array_values(array_filter($all, static fn($p) => Ages::matches($p, $filters['age'])));
        $total = count($found);

        /*
         * Сторінками, а не всім каталогом одразу: сто дев'ять карток — це
         * 230 КБ розмітки й півтори секунди на відповідь, а розгледіти щось у
         * такій стрічці неможливо. 24 ділиться і на дві, і на три, і на чотири
         * колонки, тож повна сторінка ніколи не закінчується неповним рядом.
         */
        $perPage = self::PER_PAGE;
        $pages = max(1, (int)ceil($total / $perPage));
        $page = min($pages, max(1, (int)($_GET['page'] ?? 1)));
        $products = array_slice($found, ($page - 1) * $perPage, $perPage);
        // бренди, варіанти й залишки списку — кількома запитами на всю сторінку,
        // інакше кожна картка ходила б у базу сама
        Catalog::preloadCards($products);

        // назва бренду для заголовка, коли обрано рівно один: людина прийшла
        // сюди з картки товару й має бачити, де опинилась
        $brand = count($filters['brand']) === 1
            ? DB::row('SELECT * FROM brands WHERE slug = ? OR id = ?',
                [$filters['brand'][0], (int)$filters['brand'][0]])
            : null;

        // Обрані товари інших категорій (як у дизайні).
        //
        // Виключаємо не лише поточну категорію, а й усе, що вже стоїть вище на
        // цій самій сторінці. Без цього на «Всіх товарах» (де категорія не
        // обрана й фільтрувати не було чим) блок «Вас може зацікавити»
        // показував ті самі чотири позиції з мітками «ХІТ», які покупець
        // щойно проминув, — цілий екран без жодної нової позиції.
        $shownIds = array_map(fn($r) => (int)$r['id'], $found);
        $skip = $shownIds ? ' AND id NOT IN (' . implode(',', $shownIds) . ')' : '';
        // «Інші категорії» — це вся гілка, а не сама категорія: підрозділ того
        // самого розділу для покупця не «інше», він щойно звідти
        $branch = $current ? Catalog::branchIds((int)$current['id']) : [];
        /*
         * Курси в каталозі не показуються взагалі — і в цьому блоці теж.
         *
         * Обраний магазин цей блок теж мусить поважати. Інакше виходило так:
         * покупець фільтрує «Магазин №1», товар зникає з видачі, бо його там
         * немає, — і той самий товар одразу повертається порадою внизу. Фільтр
         * при цьому виглядає зламаним, хоч і працює: людина бачить не «його
         * тут немає», а «сайт мене не слухає».
         */
        $otherWhere = ''; $otherArgs = [];
        if (!empty($filters['store_id'])) {
            $otherWhere = ' AND ' . Catalog::inStockSql();
            $otherArgs[] = (int)$filters['store_id'];
        }
        // Обраний вік — теж: порада «кульки 3+» під видачею «0–1 рік» радила б
        // батькам немовляти те, що йому не можна
        if ($filters['age'] !== '') {
            [$ageCond, $ageArgs] = Ages::sql($filters['age'], 'p');
            $otherWhere .= ' AND ' . $ageCond;
            $otherArgs = array_merge($otherArgs, $ageArgs);
        }
        // Псевдонім p обовʼязковий: умову «є в цьому магазині» написано через
        // нього (Catalog::inStockSql), і без аліаса запит падає на p.id
        $other = $page !== $pages ? [] : DB::all("SELECT p.* FROM products p WHERE p.active = 1 AND p.featured = 1 AND p.type <> 'course'" .
            ($branch ? ' AND p.category_id NOT IN (' . implode(',', $branch) . ')' : '')
            . ($shownIds ? ' AND p.id NOT IN (' . implode(',', $shownIds) . ')' : '') . $otherWhere
            . ' ORDER BY p.id LIMIT 4', $otherArgs);
        Catalog::preloadCards($other);

        // Скільки іграшок у кожній смузі ростоміра — з рештою обраних фільтрів,
        // щоб «0» було видно до кліку, а не після
        $ageCounts = [];
        foreach (array_keys(Ages::BANDS) as $k) {
            $ageCounts[$k] = count(array_filter($all, static fn($p) => Ages::matches($p, (string)$k)));
        }
        $ageCounts[''] = count($all);
        $band = Ages::band($filters['age']);

        View::show('shop/index', [
            'age_counts' => $ageCounts,
            'categories' => $cats,
            'cat_tree' => Catalog::categoryTree($cats),
            'current_cat' => $current,
            'parent_cat' => $parentCat,
            'products' => $products,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'per_page' => $perPage,
            // порада «Вас може зацікавити» — лише наприкінці списку: посеред
            // нього вона обривала б перегортання
            'other_products' => $other,
            'filters' => $filters,
            'brand' => $brand,
            'stores' => Catalog::stores(),
            'attr_options' => Catalog::filterableAttrs($current['id'] ?? null),
            'brand_options' => Catalog::filterableBrands($current['id'] ?? null),
            'page_title' => ($current ? $current['name'] . ($band ? ' для дітей ' . $band[2] : '') . ' — '
                    : ($band ? 'Іграшки для дітей ' . $band[2] . ' — ' : ''))
                . 'Каталог іграшок' . ($page > 1 ? ' — сторінка ' . $page : '') . ' — ' . cfg('app_name'),
            // Свій опис на категорію й на вік: інакше Google бачить десяток
            // сторінок з однаковим описом і вважає їх дублікатами.
            'meta_description' => ($current
                ? mb_substr($current['name'], 0, 1) . mb_strtolower(mb_substr($current['name'], 1))
                : 'Дитячі іграшки') . ($band ? ' для дітей ' . $band[2] : '') . ': ' . $total . ' '
                    . plural($total, 'іграшка', 'іграшки', 'іграшок')
                    . '. Доставка Новою Поштою по всій Україні, безкоштовно від 2500 ₴.',
            'jsonld' => [JsonLd::breadcrumbs(array_values(array_filter([
                ['Головна', '/'],
                ['Каталог', $current ? '/shop' : null],
                // підрозділ веде крихти через свій розділ — тим самим шляхом,
                // яким людина сюди дійшла панеллю каталогу
                $parentCat ? [$parentCat['name'], shop_path($parentCat['slug'])] : null,
                $current ? [$current['name'], null] : null,
            ])))],
        ]);
    }

    /**
     * Чи вага вже описана характеристикою товару.
     *
     * Колонка weight зʼявилась пізніше за характеристики, і в багатьох картках
     * вага живе в обох місцях одразу. Показувати треба щось одне — лишаємо те,
     * що вписав власник руками: у характеристиці він міг уточнити («350 г ± 5»),
     * а колонка зберігає лише число.
     */
    private static function weightIsAttr(array $attrs): bool
    {
        foreach ($attrs as $a) {
            $name = mb_strtolower(trim((string)($a['name'] ?? '')));
            // Обидва написання апострофа навмисно: у старих картках вага
            // заведена звичайною лапкою, у нових — правильним U+02BC.
            if (in_array($name, ['вага', 'маса', 'обʼєм', "об'єм", 'объем'], true)) return true;
        }
        return false;
    }

    public static function product(string $slug): never
    {
        $p = DB::row('SELECT * FROM products WHERE slug = ? AND active = 1', [$slug]);
        if (!$p) { http_response_code(404); View::show('errors/404'); }
        $cat = DB::row('SELECT * FROM categories WHERE id = ?', [$p['category_id']]);
        $catParent = Catalog::parentCategory($cat);   // «Мед» над «Липовим» — для крихт
        $variants = Catalog::variants((int)$p['id']);
        $attrs = Catalog::attrs((int)$p['id']);
        $stores = Catalog::stores();

        // варіанти як комбінації характеристик (розмір, колір…)
        $variantOptions = Attrs::variantOptionsFor((int)$p['id']);
        $axes = Catalog::variantAxes($variants, $variantOptions);
        $stockMap = Catalog::stockMap((int)$p['id']);

        // варіант, обраний за замовчуванням — з нього беремо ціну й наявність до першого кліку
        $first = $variants[0] ?? null;

        // Галерея — теж від обраної фасовки: сторінка відкривається на першій,
        // тож і кадри показує її. Далі їх переставляє JS разом із ціною.
        $images = Catalog::gallery($p, $first);   // головне фото першим, далі додаткові
        // Пошуковикам віддаємо всі фото товару, а не лише кадри першої
        // фасовки: розмітка описує товар цілком.
        $allImages = Catalog::gallery($p);

        // наявність по магазинах: для обраного варіанта (або товару без варіантів)
        $availability = [];
        foreach ($stores as $s) {
            $sid = (int)$s['id'];
            $availability[] = [
                'store' => $s,
                'qty' => Catalog::stock((int)$p['id'], $first['id'] ?? null, $sid),
                'price' => Catalog::price($p, $first, $sid)[0],
                'by_variant' => $stockMap[$sid] ?? [],
            ];
        }
        [$price, $old] = Catalog::price($p, $first);

        // дані для миттєвого перерахунку ціни й наявності при виборі варіанта
        $variantData = [];
        foreach ($variants as $v) {
            $vid = (int)$v['id'];
            [$vp, $vo] = Catalog::price($p, $v);
            $opts = [];
            foreach ($variantOptions[$vid] ?? [] as $o) $opts[(int)$o['attribute_id']] = $o['value'];
            $qty = 0;
            $storePrices = [];
            foreach ($stores as $s) {
                $sid = (int)$s['id'];
                $qty += (int)($stockMap[$sid][$vid] ?? 0);
                $sp = Catalog::price($p, $v, $sid)[0];
                $storePrices[$sid] = ($sp !== null && $sp != $vp) ? price_fmt($sp) : '';
            }
            // Вага належить фасовці: банка 0,5 і банка 1,5 — це різна вага й
            // різна ціна за 100 г. Порожня у варіанта — беремо товарну.
            $vw = ($v['weight'] ?? null) !== null && $v['weight'] > 0 ? (float)$v['weight'] : (float)($p['weight'] ?? 0);
            $variantData[] = [
                'id' => $vid, 'name' => $v['name'], 'sku' => $v['sku'] ?? '',
                'price' => $vp, 'price_fmt' => $vp !== null ? price_fmt($vp) : 'Ціна за запитом',
                'old_fmt' => $vo !== null ? price_fmt($vo) : '',
                'qty' => $qty, 'opts' => $opts, 'store_price' => $storePrices,
                'weight_fmt' => weight_fmt($vw),
                'per_100g' => price_per_100g($vp, $vw),
                // Готова галерея фасовки: свої кадри, далі спільні. Рахуємо
                // тут, а не в браузері, бо порядок і заглушка — те саме
                // правило, що й для першого показу, і роздвоювати його між
                // PHP та JS означає рано чи пізно розвести їх.
                'photos' => array_map(fn($im) => [
                    'full'  => asset($im['path']),
                    'thumb' => asset(Images::displayThumb($im['path'])),
                ], Catalog::gallery($p, $v)),
            ];
        }

        $related = DB::all('SELECT * FROM products WHERE active = 1 AND category_id = ? AND id != ? LIMIT 4', [$p['category_id'], $p['id']]);
        Catalog::preloadCards($related);

        View::show('shop/product', [
            'p' => $p, 'cat' => $cat, 'variants' => $variants, 'attrs' => $attrs,
            'variant_axes' => $axes, 'variant_data' => $variantData,
            'images' => $images, 'availability' => $availability,
            'price' => $price, 'old_price' => $old, 'related' => $related,
            // Набори, у які входить цей товар. Пропозиція комбінації працює
            // лише поруч із товаром: у кошику покупець уже вирішив, що бере.
            'bundles' => Bundles::forProduct((int)$p['id']),
            // Вага й ціна за 100 г для стану «до вибору варіанта»; далі їх
            // переставляє JS разом із ціною — так само, як наявність.
            //
            // Рядок ваги не показуємо, якщо вагу вже описали характеристикою:
            // її заводили руками задовго до появи колонки, і два однакові рядки
            // «Вага 350 г» підряд читаються як помилка, а не як подробиця.
            'weight_fmt' => self::weightIsAttr($attrs) ? '' : weight_fmt($first['weight'] ?? $p['weight'] ?? null),
            'per_100g' => price_per_100g($price, ($first['weight'] ?? null) ?: ($p['weight'] ?? null)),
            // У <title> — довга назва з Prom: у ній слова, якими шукають
            'page_title' => (trim((string)($p['seo_title'] ?? '')) ?: $p['name']) . ' — ' . cfg('app_name'),
            'meta_description' => $p['short_desc'] ?? '',
            'product_reviews' => Reviews::forProduct((int)$p['id']),
            'jsonld_product' => true,
            'jsonld' => [
                JsonLd::product(
                    $p, $allImages, $price,
                    array_map(fn($n) => ['@type' => 'Brand', 'name' => $n], Catalog::brandNames($p)),
                    Catalog::stock((int)$p['id']) > 0 || !empty($p['made_to_order'])
                ),
                // Крихти повторюють шлях, яким людина сюди дійшла: головна →
                // категорія → товар. Google показує їх замість голої адреси.
                JsonLd::breadcrumbs(array_values(array_filter([
                    ['Головна', '/'],
                    ['Магазин', '/shop'],
                    $catParent ? [$catParent['name'], shop_path($catParent['slug'])] : null,
                    $cat ? [$cat['name'], shop_path($cat['slug'])] : null,
                    [$p['name'], null],
                ]))),
            ],
            // чи ця людина вже чекає обраний варіант — щоб не пропонувати вдруге
            'watching' => StockWatch::isWaiting((int)$p['id'], $first['id'] ?? null, Auth::id()),
            // Торг. Стан рахуємо на кожну фасовку окремо — розмова ведеться
            // саме про неї, і перемикач фасовки має міняти й цей блок разом із
            // ціною, інакше покупець бачив би чужу домовленість.
            'offer_allowed' => Offers::allowed($p, $first),
            'offer_states' => self::offerStates($p, $variants),
        ]);
    }

    /**
     * Стара адреса товару на Prom (/ua/p1775418335-….html) — на його сторінку
     * тут. Товар знаходиться за ext_id, який лишив імпорт. 301: адреса
     * змінилась назавжди, і пошуковик має перенести на нову все, що знав.
     */
    public static function byPromId(string $promId): never
    {
        $slug = DB::val('SELECT slug FROM products WHERE ext_id = ? AND active = 1', [$promId]);
        redirect($slug ? '/product/' . $slug : '/shop', 301);
    }

    /**
     * Стан торгу по кожній фасовці: [id фасовки або 0 => що показати].
     *
     * Чотири стани, і кожен вимагає від людини різного: нічого немає (можна
     * запропонувати), чекаємо на магазин (нічого не робіть), магазин відповів
     * (ваш хід), домовились (кладіть у кошик). Рахуємо всі одразу, бо фасовку
     * перемикають на місці — і блок має мінятись разом із ціною, а не після
     * перезавантаження сторінки.
     */
    private static function offerStates(array $p, array $variants): array
    {
        $threads = Offers::threadsForProduct((int)$p['id'], Auth::id());
        $keys = $variants ? array_map(fn($v) => (int)$v['id'], $variants) : [0];
        $out = [];
        foreach ($keys as $k) {
            $o = $threads[$k] ?? null;
            if (!$o) { $out[$k] = ['state' => 'none']; continue; }
            $out[$k] = [
                'state' => $o['status'] === 'accepted' ? 'deal'
                    : ((string)$o['turn'] === 'seller' ? 'wait' : 'yours'),
                'id' => (int)$o['id'],
                'terms' => Offers::terms($o),
                'until' => $o['status'] === 'accepted' && !empty($o['expires_at'])
                    ? date('d.m.Y H:i', strtotime((string)$o['expires_at'])) : '',
            ];
        }
        return $out;
    }

    /**
     * «Повідомте, коли зʼявиться».
     *
     * Тільки для тих, хто увійшов: канали сповіщень людина обирає в кабінеті,
     * а в гостя кабінету немає. Гостю кажемо це прямо й відправляємо на вхід —
     * мовчазна відмова виглядала б як поламана кнопка.
     */
    public static function watch(): never
    {
        Csrf::verify();
        $back = safe_back($_POST['back'] ?? null, '/shop');
        if (!Auth::check()) {
            flash('error', 'Увійдіть, щоб ми могли вас сповістити — у кабінеті ви оберете, куди саме писати.');
            redirect($back);
        }
        $pid = (int)($_POST['product_id'] ?? 0);
        $vid = (int)($_POST['variant_id'] ?? 0) ?: null;
        $sid = (int)($_POST['store_id'] ?? 0) ?: null;

        $p = DB::row('SELECT id FROM products WHERE id = ? AND active = 1', [$pid]);
        if (!$p) { flash('error', 'Товар не знайдено.'); redirect($back); }
        // Варіант мусить належати цьому товару: інакше в чергу очікувань
        // потрапила б чужа позиція, і продавець виробляв би не те
        if ($vid !== null && !DB::row('SELECT 1 FROM product_variants WHERE id = ? AND product_id = ?', [$vid, $pid])) {
            $vid = null;
        }
        if ($sid !== null && !DB::row('SELECT 1 FROM stores WHERE id = ? AND active = 1', [$sid])) {
            $sid = null;
        }

        flash('success', StockWatch::add($pid, $vid, $sid, (int)Auth::id())
            ? 'Добре — напишемо, щойно зʼявиться. Куди саме писати, можна змінити в кабінеті.'
            : 'Ви вже в черзі на цю позицію — повідомимо, щойно вона зʼявиться.');
        redirect($back);
    }
}
