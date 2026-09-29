<?php
declare(strict_types=1);

/**
 * Останні відео каналу YouTube через публічний RSS (без API-ключів), кеш 1 година.
 *
 * RSS віддає рівно 15 останніх завантажень — і Shorts у ньому лежать поряд зі
 * звичайними відео. На каналі, де Shorts виходять частіше, вони займають майже
 * всі 15 позицій, і довгих відео в стрічці лишається два-три. Саме тому список
 * не будується щоразу з нуля: нові довгі відео **додаються** до вже відомих, і
 * добірка наростає до потрібної кількості, замість залежати від того, скільки
 * Shorts вийшло цього тижня.
 */
class YouTube
{
    /** Скільки відео тримаємо в памʼяті: більше за будь-який показ на сторінках */
    private const KEEP = 12;

    /**
     * Відео для сторінки. Ходить у мережу лише тоді, коли кешу немає взагалі.
     *
     * Раніше протухлий кеш означав, що перший відвідувач після цього моменту
     * чекає на 16 послідовних запитів до YouTube (RSS плюс HEAD на кожне з
     * 15 відео) — і платив за це секундами свого відкриття головної. Тепер
     * оновлення живе в cron (`php bin/cli.php yt:refresh`), а сторінка бере
     * те, що лежить, навіть якщо воно вчорашнє: застарілий список відео —
     * дрібниця, а сторінка, що вантажиться пʼять секунд, — ні.
     *
     * $force = true — це і є виклик із cron: тільки він має право чекати.
     */
    public static function latest(int $limit = 3, bool $force = false): array
    {
        $channel = trim((string)Settings::get('youtube_channel', ''));
        if ($channel === '') return [];

        $cache = json_decode((string)Settings::get('yt_cache', ''), true);
        $fresh = (int)Settings::get('yt_cache_time', '0') > time() - 3600;
        // старий формат кешу (без is_short) вважаємо застарілим
        if (is_array($cache) && $cache && !array_key_exists('is_short', $cache[0])) $fresh = false;
        if (is_array($cache) && $fresh) return self::pick($cache, $limit);
        // Кеш є, але протух — віддаємо його. Оновить cron; сторінка не чекає.
        if (!$force && is_array($cache) && $cache) return self::pick($cache, $limit);

        $videos = self::fetch($channel);
        if ($videos) {
            $store = self::merge(is_array($cache) ? $cache : [], $videos);
            Settings::set('yt_cache', json_encode($store, JSON_UNESCAPED_UNICODE));
            Settings::set('yt_cache_time', (string)time());
            return self::pick($store, $limit);
        }
        // мережа недоступна — віддаємо старий кеш, якщо був
        return is_array($cache) ? self::pick($cache, $limit) : [];
    }

    /**
     * Що зберігаємо між оновленнями.
     *
     * Довгі відео накопичуємо: стрічка тримає лише 15 останніх завантажень, і
     * новий Short витісняє звідти торішній огляд. Раз побачивши відео, ми його
     * вже не втратимо.
     *
     * Shorts — навпаки, беремо лише ті, що у стрічці зараз. Накопичувати їх
     * немає сенсу: вони виходять часто, і за місяць список складався б із самих
     * Shorts, тобто рівно те, від чого ми йдемо.
     *
     * @param array $cached те, що вже знали   @param array $fetched те, що у стрічці зараз
     */
    public static function merge(array $cached, array $fetched): array
    {
        $long = $short = [];
        // свіжі попереду: у них актуальна назва, якщо відео перейменували
        foreach (array_merge($fetched, $cached) as $v) {
            $id = (string)($v['id'] ?? '');
            if ($id === '' || !empty($v['is_short'])) continue;
            $long[$id] ??= $v;
        }
        foreach ($fetched as $v) {
            $id = (string)($v['id'] ?? '');
            if ($id === '' || empty($v['is_short'])) continue;
            $short[$id] ??= $v;
        }
        $byDate = fn($a, $b) => strcmp((string)($b['published'] ?? ''), (string)($a['published'] ?? ''));
        usort($long, $byDate);
        usort($short, $byDate);
        return array_merge(array_slice($long, 0, self::KEEP), array_slice($short, 0, self::KEEP));
    }

    /**
     * Що показуємо.
     *
     * Спершу довгі відео — заради них на сторінку й заходять. Shorts ідуть лише
     * тим, чого не вистачило до потрібної кількості: канал, де довгих відео вже
     * шість, покаже шість довгих, і Shorts зникнуть самі, без жодного
     * перемикача.
     */
    public static function pick(array $items, int $limit): array
    {
        $long = $short = [];
        foreach ($items as $v) {
            if (empty($v['is_short'])) $long[] = $v; else $short[] = $v;
        }
        $out = array_slice($long, 0, $limit);
        if (count($out) < $limit) {
            $out = array_merge($out, array_slice($short, 0, $limit - count($out)));
        }
        return $out;
    }

    private static function fetch(string $channel): array
    {
        $channelId = self::channelId($channel);
        if (!$channelId) return [];
        $xml = self::get('https://www.youtube.com/feeds/videos.xml?channel_id=' . urlencode($channelId));
        if (!$xml) return [];
        $doc = @simplexml_load_string($xml);
        if (!$doc) return [];
        $out = [];
        foreach ($doc->entry as $e) {
            $media = $e->children('http://search.yahoo.com/mrss/');
            $yt = $e->children('http://www.youtube.com/xml/schemas/2015');
            $vid = (string)$yt->videoId;
            if (!$vid) continue;
            // Shorts більше не відкидаємо, а позначаємо: вони згодяться, якщо
            // довгих відео не набереться на весь ряд (див. pick()).
            // Ознака: /shorts/<id> віддає 200, для звичайного відео — редірект.
            $isShort = self::headStatus('https://www.youtube.com/shorts/' . $vid) === 200;
            $out[] = [
                'id' => $vid,
                'title' => (string)$e->title,
                'url' => 'https://www.youtube.com/' . ($isShort ? 'shorts/' . $vid : 'watch?v=' . $vid),
                'thumb' => 'https://i.ytimg.com/vi/' . $vid . '/hqdefault.jpg',
                'published' => substr((string)$e->published, 0, 10),
                'is_short' => $isShort,
            ];
        }
        return $out;
    }

    /** Приймає ID (UC...), URL каналу або @handle — повертає channel_id */
    private static function channelId(string $input): ?string
    {
        if (preg_match('/^UC[\w-]{20,}$/', $input)) return $input;
        $cached = Settings::get('yt_channel_id_for');
        if ($cached) {
            [$src, $id] = array_pad(explode('|', (string)$cached, 2), 2, '');
            if ($src === $input && $id) return $id;
        }
        if (preg_match('~/channel/(UC[\w-]{20,})~', $input, $m)) {
            Settings::set('yt_channel_id_for', $input . '|' . $m[1]);
            return $m[1];
        }
        // handle або посилання на нього — дістаємо channelId зі сторінки каналу
        $handle = null;
        if (preg_match('~@([\w.\-]+)~u', $input, $m)) $handle = $m[1];
        elseif (preg_match('/^[\w.\-]+$/', $input)) $handle = $input;
        if (!$handle) return null;
        $html = self::get('https://www.youtube.com/@' . rawurlencode($handle) . '/about');
        foreach (['/"channelId":"(UC[\w-]{20,})"/', '/"externalId":"(UC[\w-]{20,})"/',
                  '/"browseId":"(UC[\w-]{20,})"/', '~youtube\.com/channel/(UC[\w-]{20,})~'] as $re) {
            if ($html && preg_match($re, $html, $m)) {
                Settings::set('yt_channel_id_for', $input . '|' . $m[1]);
                return $m[1];
            }
        }
        return null;
    }

    private static function headStatus(string $url): int
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36',
            CURLOPT_COOKIE => 'SOCS=CAI; CONSENT=YES+cb.20240101-00-p0.uk+FX+000',
            CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code;
    }

    private static function get(string $url, bool $insecureRetry = true): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36',
            CURLOPT_COOKIE => 'SOCS=CAI; CONSENT=YES+cb.20240101-00-p0.uk+FX+000',
            CURLOPT_HTTPHEADER => ['Accept-Language: uk-UA,uk;q=0.9,en;q=0.8'],
        ]);
        $resp = curl_exec($ch);
        $errno = curl_errno($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp !== false && $code === 200) return (string)$resp;
        // XAMPP часто без CA-сертифікатів: повторюємо без перевірки SSL (лише публічний RSS)
        if ($insecureRetry && in_array($errno, [60, 77], true)) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
                CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36',
                CURLOPT_COOKIE => 'SOCS=CAI; CONSENT=YES+cb.20240101-00-p0.uk+FX+000',
                CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0,
            ]);
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($resp !== false && $code === 200) return (string)$resp;
        }
        @file_put_contents(BOFU_ROOT . '/storage/logs/youtube.log',
            '[' . now() . "] fail $url errno=$errno http=$code\n", FILE_APPEND);
        return null;
    }
}
