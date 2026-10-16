<?php
declare(strict_types=1);

namespace Controllers;

use DB, WebPush, Settings, Catalog, Images;

class Seo
{
    public static function robots(): never
    {
        header('Content-Type: text/plain; charset=utf-8');
        if (Settings::bool('seo_noindex')) {
            echo "User-agent: *\nDisallow: /\n";
            exit;
        }
        $host = ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        echo "User-agent: *\nDisallow: /admin\nDisallow: /cart\nDisallow: /checkout\n";
        echo "Sitemap: $scheme://$host" . base_url('/sitemap.xml') . "\n";
        exit;
    }

    /**
     * Фід товарів для Google Merchant Center (RSS 2.0 + простір імен g:).
     *
     * Лише активні товари в наявності й з ціною: Google відхиляє позиції без
     * ціни, а «немає в наявності» у фіді краще не показувати зовсім. Бренд
     * береться з картки; без бренду підставляється назва магазину й ставиться
     * identifier_exists=no (Google вимагає або бренд, або позначку про її відсутність).
     */
    public static function googleFeed(): never
    {
        header('Content-Type: application/xml; charset=utf-8');
        $shop = (string)cfg('app_name');
        $cats = [];
        foreach (DB::all('SELECT id, name FROM categories') as $c) $cats[(int)$c['id']] = (string)$c['name'];
        $esc = static fn($s) => htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $out = '<?xml version="1.0" encoding="UTF-8"?>' . "
"
             . '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0"><channel>' . "
"
             . '<title>' . $esc($shop) . '</title><link>' . $esc(abs_url('/')) . '</link>'
             . '<description>' . $esc($shop) . ' — дитячі іграшки</description>' . "
";
        $rows = DB::all("SELECT * FROM products WHERE active = 1 AND type <> 'course' ORDER BY id");
        foreach ($rows as $p) {
            $pid = (int)$p['id'];
            [$price] = Catalog::price($p);
            if ($price === null || (float)$price <= 0) continue;
            if (Catalog::stock($pid) <= 0 && empty($p['made_to_order'])) continue;
            $desc = trim(preg_replace('~\s+~u', ' ', strip_tags((string)($p['description'] ?: ($p['short_desc'] ?? '')))));
            if ($desc === '') $desc = (string)$p['name'];
            $desc = mb_substr($desc, 0, 4500);
            $photo = Catalog::photo($p);
            $item = '<item>'
                . '<g:id>' . $esc($p['sku'] !== null && $p['sku'] !== '' ? $p['sku'] : 'P' . $pid) . '</g:id>'
                . '<title>' . $esc(mb_substr((string)$p['name'], 0, 150)) . '</title>'
                . '<description>' . $esc($desc) . '</description>'
                . '<link>' . $esc(abs_url('/product/' . $p['slug'])) . '</link>'
                . '<g:image_link>' . $esc(asset_abs($photo)) . '</g:image_link>';
            $n = 0;
            foreach (Catalog::images($pid) as $im) {
                if ($im['path'] === $photo || $n >= 10) continue;
                $item .= '<g:additional_image_link>' . $esc(asset_abs((string)$im['path'])) . '</g:additional_image_link>';
                $n++;
            }
            $item .= '<g:availability>in_stock</g:availability>'
                . '<g:price>' . number_format((float)$price, 2, '.', '') . ' UAH</g:price>'
                . '<g:condition>new</g:condition>';
            $brands = Catalog::brandsOf($p);
            $brand = $brands ? (string)$brands[0]['name'] : '';
            $item .= '<g:brand>' . $esc($brand !== '' ? $brand : $shop) . '</g:brand>';
            $gtin = preg_replace('~\D~', '', (string)($p['barcode'] ?? ''));
            if (in_array(strlen($gtin), [8, 12, 13, 14], true)) $item .= '<g:gtin>' . $gtin . '</g:gtin>';
            elseif ($brand === '') $item .= '<g:identifier_exists>no</g:identifier_exists>';
            if (!empty($p['sku'])) $item .= '<g:mpn>' . $esc($p['sku']) . '</g:mpn>';
            $item .= '<g:google_product_category>1239</g:google_product_category>';
            if (isset($cats[(int)$p['category_id']])) $item .= '<g:product_type>' . $esc($cats[(int)$p['category_id']]) . '</g:product_type>';
            if ($p['age_min'] !== null && $p['age_min'] !== '') {
                $m = (int)$p['age_min'];
                $item .= '<g:age_group>' . ($m < 3 ? 'newborn' : ($m < 12 ? 'infant' : ($m < 60 ? 'toddler' : 'kids'))) . '</g:age_group>';
            }
            $out .= $item . "</item>
";
        }
        echo $out . '</channel></rss>';
        exit;
    }

    public static function sitemap(): never
    {
        header('Content-Type: application/xml; charset=utf-8');
        if (Settings::bool('seo_noindex')) {
            echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
               . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>';
            exit;
        }
        $host = ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $abs = fn(string $p) => $scheme . '://' . $host . base_url($p);
        // Правові сторінки й «Де нас знайти» теж у карті: за запитами «доставка
        // мед», «повернення» і назвою міста люди приходять саме на них, а
        // Google Merchant Center вимагає, щоб умови доставки й повернення були
        // доступні окремими адресами.
        $urls = [
            ['/', '1.0'], ['/shop', '0.9'], ['/about', '0.6'], ['/testimonials', '0.6'],
            ['/stores', '0.5'], ['/contacts', '0.5'],
            ['/delivery', '0.5'], ['/payment', '0.5'], ['/returns', '0.5'],
            ['/privacy', '0.3'], ['/offer', '0.3'],
        ];
        foreach (DB::all('SELECT slug, updated_at FROM products WHERE active = 1') as $p) {
            $urls[] = ['/product/' . $p['slug'], '0.8', $p['updated_at']];
        }
        foreach (DB::all('SELECT slug FROM categories WHERE active = 1') as $c) {
            $urls[] = [shop_path($c['slug']), '0.7'];
        }
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as $u) {
            echo '<url><loc>' . htmlspecialchars($abs($u[0])) . '</loc><priority>' . $u[1] . '</priority>';
            if (!empty($u[2])) echo '<lastmod>' . date('Y-m-d', strtotime($u[2])) . '</lastmod>';
            echo "</url>\n";
        }
        echo '</urlset>';
        exit;
    }

    public static function manifest(): never
    {
        header('Content-Type: application/manifest+json; charset=utf-8');
        echo json_encode([
            'name' => cfg('app_name'), 'short_name' => 'Старвуд',
            'start_url' => base_url('/admin'), 'scope' => base_url('/'),
            'display' => 'standalone', 'background_color' => '#141110', 'theme_color' => '#141110',
            'icons' => [
                ['src' => asset('img/avatar.png'), 'sizes' => '400x400', 'type' => 'image/png', 'purpose' => 'any maskable'],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function serviceWorker(): never
    {
        header('Content-Type: application/javascript; charset=utf-8');
        header('Service-Worker-Allowed: ' . base_url('/'));
        readfile(BOFU_ROOT . '/assets/js/sw.js');
        exit;
    }
}
