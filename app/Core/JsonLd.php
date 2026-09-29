<?php
declare(strict_types=1);

/**
 * Розмітка для пошуковиків (schema.org / JSON-LD).
 *
 * Навіщо окремий клас, а не рядки в шаблоні: розмітки стало пʼять видів, вони
 * потрапляють на різні сторінки, і кожна вимагає абсолютних адрес та вичищених
 * порожніх полів. У шаблоні це перетворювалось би на тридцять рядків
 * array_filter між тегами <head>.
 *
 * Спільні правила всіх методів:
 *
 * - **порожнє поле не віддаємо взагалі**. Google читає `"brand": ""` як
 *   помилку розмітки, а не як «бренду немає», і може знецінити всю картку.
 *   Тому кожен блок проходить self::clean();
 * - **адреси лише абсолютні** (abs_url для сторінок, asset_abs для файлів) —
 *   відносні Google мовчки ігнорує;
 * - **нічого не вигадуємо**. Рейтингу немає, поки немає відгуків; строку
 *   доставки немає, поки його не вписали. Розмітка, яка обіцяє те, чого немає
 *   на сторінці, — привід для ручних санкцій, а не для зірочок у видачі.
 */
class JsonLd
{
    /** Прибирає порожні значення на всіх рівнях вкладеності */
    private static function clean(array $data): array
    {
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $v = self::clean($v);
                if ($v === []) { unset($data[$k]); continue; }
                $data[$k] = $v;
            } elseif ($v === null || $v === '' || $v === []) {
                unset($data[$k]);
            }
        }
        return $data;
    }

    /** Готовий тег <script> або порожній рядок, якщо виводити нема чого */
    public static function tag(array $data): string
    {
        $data = self::clean($data);
        if (count($data) <= 2) return '';   // лишились самі @context і @type
        return '<script type="application/ld+json">' . json_js($data) . '</script>' . "\n";
    }

    /**
     * Хто ми — на кожній сторінці.
     *
     * sameAs зі соцмережами Google використовує, щоб звести сайт, канал і
     * профілі в одну сутність: без нього YouTube-канал і магазин лишаються
     * для нього різними організаціями.
     */
    public static function organization(): array
    {
        $socials = array_values(array_filter([
            Content::title('social_instagram'),
            Content::title('social_youtube'),
            Content::title('social_tiktok'),
        ], fn($u) => $u !== '' && $u !== '#'));

        $phone = Content::title('contact_phone');
        $email = Content::title('contact_email');

        return [
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            '@id' => abs_url('/') . '#org',
            'name' => cfg('app_name'),
            'url' => abs_url('/'),
            'logo' => asset_abs('img/logo.png'),
            'description' => Settings::get('seo_description', ''),
            'sameAs' => $socials,
            'contactPoint' => ($phone !== '' || $email !== '') ? self::clean([
                '@type' => 'ContactPoint',
                'contactType' => 'customer service',
                'telephone' => $phone,
                'email' => $email,
                'areaServed' => 'UA',
                'availableLanguage' => 'Ukrainian',
            ]) : null,
        ];
    }

    /**
     * Пошук по сайту для Google.
     *
     * Дає рядок пошуку прямо у видачі. Адреса мусить збігатися з тією, яку
     * справді розуміє каталог (/shop?q=…), інакше посилання веде в нікуди.
     */
    public static function website(): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'url' => abs_url('/'),
            'name' => cfg('app_name'),
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => ['@type' => 'EntryPoint', 'urlTemplate' => abs_url('/shop') . '?q={search_term_string}'],
                'query-input' => 'required name=search_term_string',
            ],
        ];
    }

    /**
     * Магазин із середньою оцінкою покупців (Reviews::stats). Лише справжні
     * відгуки з бази: розмітка з рейтингом, якого немає на сторінці,
     * для Google — порушення.
     */
    public static function storeRating(array $stats): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'Store',
            '@id' => abs_url('/') . '#store',
            'name' => cfg('app_name'),
            'url' => abs_url('/'),
            'image' => asset_abs('img/logo.png'),
            'telephone' => Content::title('contact_phone'),
            'address' => self::clean(['@type' => 'PostalAddress', 'streetAddress' => Content::title('contact_address'), 'addressCountry' => 'UA']),
            'aggregateRating' => [
                '@type' => 'AggregateRating',
                'ratingValue' => (string)$stats['avg'],
                'reviewCount' => (int)$stats['count'],
                'bestRating' => '5', 'worstRating' => '1',
            ],
        ];
    }

    /** Хлібні крихти: [['назва', '/шлях'], …] — останній елемент без посилання */
    public static function breadcrumbs(array $items): array
    {
        $list = [];
        foreach (array_values($items) as $i => [$name, $path]) {
            $list[] = self::clean([
                '@type' => 'ListItem',
                'position' => $i + 1,
                'name' => $name,
                'item' => $path !== null ? abs_url($path) : null,
            ]);
        }
        return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $list];
    }

    /** Питання й відповіді — рівно ті, що показані на сторінці */
    public static function faq(array $pairs): array
    {
        $out = [];
        foreach ($pairs as $qa) {
            if (!isset($qa[0], $qa[1]) || trim((string)$qa[0]) === '' || trim((string)$qa[1]) === '') continue;
            $out[] = [
                '@type' => 'Question',
                'name' => $qa[0],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $qa[1]],
            ];
        }
        return ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $out];
    }

    /**
     * Курс.
     *
     * hasCourseInstance Google вимагає від початку 2024-го: без нього картка
     * курсу не показується взагалі. Формат ставимо blended — навчання має
     * практичну частину на пасіці, і називати його онлайновим було б неправдою.
     */
    public static function course(string $name, string $description): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'Course',
            'name' => $name,
            'description' => $description,
            'url' => abs_url('/courses'),
            'provider' => ['@type' => 'Organization', 'name' => cfg('app_name'), 'sameAs' => abs_url('/')],
            'hasCourseInstance' => [
                '@type' => 'CourseInstance',
                'courseMode' => 'blended',
                'courseWorkload' => 'P1D',
            ],
        ];
    }

    /** Точки продажу — по одній сутності на магазин */
    public static function stores(array $stores): array
    {
        $out = [];
        foreach ($stores as $s) {
            $out[] = self::clean([
                '@context' => 'https://schema.org',
                '@type' => 'Store',
                'name' => $s['name'],
                'telephone' => $s['phone'] ?? '',
                'openingHours' => $s['hours'] ?? '',
                'address' => self::clean([
                    '@type' => 'PostalAddress',
                    'addressLocality' => $s['city'] ?? '',
                    'streetAddress' => $s['address'] ?? '',
                    'addressCountry' => 'UA',
                ]),
                'geo' => (!empty($s['lat']) && !empty($s['lng'])) ? [
                    '@type' => 'GeoCoordinates',
                    'latitude' => (float)$s['lat'], 'longitude' => (float)$s['lng'],
                ] : null,
                'parentOrganization' => ['@id' => abs_url('/') . '#org'],
            ]);
        }
        return $out;
    }

    /**
     * Товар.
     *
     * Порівняно з попередньою версією додано те, без чого Google не пускає
     * позицію в безкоштовні картки товарів: sku, gtin (штрихкод, якщо він у
     * нас є) і строк дії ціни. priceValidUntil обовʼязкове поле — без нього
     * пропозиція вважається простроченою; ставимо рік уперед, бо ціни на сайті
     * не мають дати закінчення.
     *
     * Рейтингу тут немає навмисно: відгуків на сайті поки не існує, а
     * aggregateRating без жодного відгуку — саме та розмітка, за яку знімають
     * розширені результати вручну.
     */
    public static function product(array $p, array $images, ?float $price, array $brands, bool $inStock): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $p['name'],
            'description' => $p['short_desc'] ?? '',
            'sku' => $p['sku'] ?? '',
            'gtin13' => (isset($p['barcode']) && strlen((string)$p['barcode']) === 13) ? $p['barcode'] : '',
            'weight' => !empty($p['weight']) ? [
                '@type' => 'QuantitativeValue', 'value' => (float)$p['weight'], 'unitCode' => 'KGM',
            ] : null,
            'brand' => count($brands) === 1 ? $brands[0] : ($brands ?: null),
            'image' => array_map(fn($i) => asset_abs($i['path']), $images),
            'offers' => self::clean([
                '@type' => 'Offer',
                'url' => abs_url('/product/' . $p['slug']),
                'priceCurrency' => 'UAH',
                'price' => $price !== null ? (string)$price : '',
                // Рік уперед: ціни на сайті безстрокові, але поле обовʼязкове
                'priceValidUntil' => date('Y-m-d', strtotime('+1 year')),
                'availability' => $inStock ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
                'itemCondition' => 'https://schema.org/NewCondition',
                'seller' => ['@id' => abs_url('/') . '#org'],
            ]),
        ];
    }
}
