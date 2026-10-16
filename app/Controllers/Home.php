<?php
declare(strict_types=1);

namespace Controllers;

use DB, View, Catalog, Content, Settings, Csrf, JsonLd, Reviews, Ages, Auth;

class Home
{
    public static function index(): never
    {
        /*
         * «Хіти» — позначені в адмінці (після переносу з Prom це товари, про
         * які покупці писали відгуки). Доки їх менше чотирьох, добираємо
         * найновішими в наявності: порожнє місце в ряду виглядає як поломка.
         */
        // Лише те, що можна купити просто зараз: хіт «скоро буде» на головній —
        // це обіцянка, яку сторінка не виконує
        $featured = DB::all("SELECT p.* FROM products p WHERE p.active = 1 AND p.featured = 1 AND p.type <> 'course'
                             AND EXISTS (SELECT 1 FROM store_stock s WHERE s.product_id = p.id AND s.qty > 0)
                             ORDER BY p.id DESC LIMIT 4");
        // Один ряд з чотирьох карток: більше на головній — це вже каталог,
        // а каталог має власну сторінку з фільтрами
        $want = 4;
        if (count($featured) < $want) {
            $have = array_map(static fn($p) => (int)$p['id'], $featured) ?: [0];
            $more = DB::all("SELECT p.* FROM products p WHERE p.active = 1 AND p.type <> 'course'
                             AND p.id NOT IN (" . implode(',', $have) . ")
                             AND EXISTS (SELECT 1 FROM store_stock s WHERE s.product_id = p.id AND s.qty > 0)
                             ORDER BY p.id DESC LIMIT " . ($want - count($featured)));
            $featured = array_merge($featured, $more);
        }
        Catalog::preloadCards($featured);

        $stats = Reviews::stats();
        $featured = array_slice($featured, 0, 4);
        $shownIds = array_map(static fn($p) => (int)$p['id'], $featured) ?: [0];
        // Нові надходження — без тих, що вже стоять у хітах
        $fresh = DB::all("SELECT p.* FROM products p WHERE p.active = 1 AND p.type <> 'course'
                          AND p.id NOT IN (" . implode(',', $shownIds) . ")
                          AND EXISTS (SELECT 1 FROM store_stock s WHERE s.product_id = p.id AND s.qty > 0)
                          ORDER BY p.created_at DESC, p.id DESC LIMIT 4");
        Catalog::preloadCards($fresh);
        // Лічильники смуг — з одного вибору, а не пошуком на кожну смугу
        $aged = Catalog::search([]);
        $ageCount = [];
        foreach (array_keys(Ages::BANDS) as $k) $ageCount[$k] = count(array_filter($aged, static fn($p) => Ages::matches($p, (string)$k)));
        View::show('home/index', [
            'categories' => self::shelves(),
            'featured' => $featured,
            'fresh' => $fresh,
            'age_count' => $ageCount,
            // Перший екран показує справжні іграшки, а не малюнки: чотири хіти
            'hero_photos' => array_map(static fn($p) => ['photo' => Catalog::photo($p), 'name' => $p['name'], 'slug' => $p['slug']],
                array_slice($featured, 0, 4)),
            'reviews' => Reviews::withText(3),
            'review_stats' => $stats,
            'page_title' => Settings::get('seo_title', cfg('app_name')),
            'meta_description' => Settings::get('seo_description', ''),
            'jsonld' => $stats['count'] ? [JsonLd::storeRating($stats)] : [],
        ]);
    }

    /**
     * Розділи для «полиць» на головній: з кількістю товарів, щоб порожня
     * коробка не обіцяла того, чого немає.
     */
    private static function shelves(): array
    {
        // Один вибір на всі полиці: раніше було по два запити на розділ.
        // Порядок — той, яким обирається «обличчя» розділу: спершу хіт у
        // наявності, далі будь-який товар.
        $rows = DB::all("SELECT p.id, p.category_id, p.image, p.featured,
                           (CASE WHEN EXISTS (SELECT 1 FROM store_stock s WHERE s.product_id = p.id AND s.qty > 0) THEN 0 ELSE 1 END) AS out_of_stock
                         FROM products p WHERE p.active = 1
                         ORDER BY p.featured DESC, out_of_stock, p.id DESC");
        $out = [];
        foreach (Catalog::rootCategories() as $c) {
            $branch = array_flip(array_map('intval', Catalog::branchIds((int)$c['id'])));
            $in = array_filter($rows, static fn($p) => isset($branch[(int)$p['category_id']]));
            $c['count'] = count($in);
            $face = null;
            foreach ($in as $p) if (!empty($p['image'])) { $face = $p; break; }
            $c['photo'] = $face ? Catalog::photo($face) : 'img/no-photo.webp';
            if ($c['count'] > 0) $out[] = $c;
        }
        return $out;
    }

    public static function about(): never
    {
        View::show('home/about', [
            'gallery' => json_decode(Content::get('gallery', 'body', '[]'), true) ?: [],
            'faq' => json_decode(Content::get('faq', 'body', '[]'), true) ?: [],
            'review_stats' => Reviews::stats(),
            'products_count' => (int)DB::val('SELECT COUNT(*) FROM products WHERE active = 1'),
            'page_title' => 'Про нас — ' . cfg('app_name'),
            'meta_description' => 'Старвуд-М — магазин дитячих іграшок від народження до 7 років. Київ, вул. Пустинська, 8. Доставка по всій Україні.',
            'jsonld' => [JsonLd::breadcrumbs([['Головна', '/'], ['Про нас', null]])],
        ]);
    }

    /**
     * Відгуки покупців: перенесені з Prom і залишені тут.
     *
     * Сторінка адресою /testimonials — як була на Prom, щоб старі посилання
     * (з месенджерів, закладок, видачі) привели туди ж, куди й раніше.
     */
    public static function testimonials(): never
    {
        $stats = Reviews::stats();
        $all = Reviews::approved();
        // назви товарів, згаданих у відгуках, — одним запитом
        $ids = array_values(array_unique(array_merge(...array_map(static fn($r) => $r['product_ids'], $all ?: [['product_ids' => []]]))));
        $products = [];
        if ($ids) {
            $in = implode(',', array_map('intval', $ids));
            foreach (DB::all("SELECT id, name, slug FROM products WHERE id IN ($in) AND active = 1") as $p) $products[(int)$p['id']] = $p;
        }
        View::show('home/testimonials', [
            'reviews' => $all,
            'stats' => $stats,
            'products' => $products,
            'page_title' => 'Відгуки покупців — ' . cfg('app_name'),
            'meta_description' => 'Відгуки покупців магазину іграшок Старвуд-М: ' . $stats['count'] . ' відгуків, середня оцінка ' . $stats['avg'] . ' з 5.',
            'jsonld' => array_values(array_filter([
                $stats['count'] ? JsonLd::storeRating($stats) : null,
                JsonLd::breadcrumbs([['Головна', '/'], ['Відгуки', null]]),
            ])),
        ]);
    }

    public static function reviewSubmit(): never
    {
        Csrf::verify();
        $err = Reviews::submit($_POST, Auth::id());
        if ($err !== '') {
            flash('error', $err);
            $_SESSION['review_draft'] = array_intersect_key($_POST, array_flip(['author', 'text', 'rating']));
        } else {
            unset($_SESSION['review_draft']);
            flash('success', 'Дякуємо! Відгук з’явиться на сторінці, щойно ми його прочитаємо.');
            \Notify::fire('review_new', [
                'author' => mb_substr(trim((string)($_POST['author'] ?? '')), 0, 60),
                'rating' => (int)($_POST['rating'] ?? 0),
                'text' => mb_substr(trim((string)($_POST['text'] ?? '')), 0, 300),
                'link' => abs_url('/admin/reviews'),
            ]);
        }
        redirect('/testimonials#write');
    }

    /**
     * Де нас знайти: точки продажу списком (адреса, телефон, графік, маршрут).
     */
    public static function stores(): never
    {
        $stores = Catalog::stores();
        View::show('home/stores', [
            'stores' => $stores,
            'map_points' => \Geo::points($stores),
            'page_title' => 'Контакти — ' . cfg('app_name'),
            'meta_description' => 'Адреса, телефони двох відділів продажу й графік роботи магазину іграшок Старвуд-М.',
            'jsonld' => array_merge(JsonLd::stores($stores), [
                JsonLd::breadcrumbs([['Головна', '/'], ['Контакти', null]]),
            ]),
        ]);
    }

    /**
     * Правові сторінки: доставка, оплата, повернення, приватність, оферта.
     *
     * Один метод на всі пʼять, бо відрізняються вони лише текстом, який власник
     * править в адмінці. Сторінки не декоративні: закони «Про електронну
     * комерцію» та «Про захист персональних даних» прямо вимагають цієї
     * інформації на сайті.
     */
    private const LEGAL_PAGES = [
        'delivery' => ['page_delivery', 'Доставка', 'Доставка іграшок Новою Поштою по всій Україні: строки, вартість, безкоштовна доставка від 2500 ₴.'],
        'payment'  => ['page_payment',  'Оплата', 'Способи оплати: післяплата, оплата на рахунок ФОП, картка.'],
        'returns'  => ['page_returns',  'Обмін і повернення', 'Умови обміну та повернення іграшок згідно із Законом «Про захист прав споживачів».'],
        'privacy'  => ['page_privacy',  'Політика конфіденційності', 'Які персональні дані ми збираємо, навіщо, кому передаємо і як їх видалити.'],
        'offer'    => ['page_offer',    'Публічна оферта', 'Договір купівлі-продажу, який покупець приймає, оформлюючи замовлення.'],
    ];

    /** Оферта й політика — стандартні документи з коду (LegalText); блок контенту старший */
    private const BUILT_IN = [
        'page_offer' => [\LegalText::class, 'offer'],
        'page_privacy' => [\LegalText::class, 'privacy'],
    ];

    public static function legal(string $slug): never
    {
        $def = self::LEGAL_PAGES[$slug] ?? null;
        if (!$def) { http_response_code(404); View::show('errors/404', ['page_title' => 'Сторінку не знайдено']); }
        [$key, $title, $description] = $def;
        $text = Content::get($key);
        if ($text === '' && isset(self::BUILT_IN[$key])) $text = (self::BUILT_IN[$key])();
        View::show('home/legal', [
            'block' => $key,
            'slug' => $slug,
            'heading' => $title,
            'text' => $text,
            'faq' => $slug === 'delivery' ? (json_decode(Content::get('faq', 'body', '[]'), true) ?: []) : [],
            'entity' => Content::title('legal_entity'),
            'entity_details' => Content::get('legal_entity'),
            'page_title' => $title . ' — ' . cfg('app_name'),
            'meta_description' => $description,
        ]);
    }
}
