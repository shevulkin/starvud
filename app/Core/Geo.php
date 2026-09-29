<?php
declare(strict_types=1);

/**
 * Координати точок продажу.
 *
 * Карти на сайті немає навмисно: заради неї доводилось пускати чужий скрипт на
 * сторінку оформлення й тримати чужий домен у connect-src, тобто готовий канал
 * витоку. Координати лишились — вони потрібні кнопці «прокласти маршрут», яка
 * відкриває карту в застосунку користувача, а не всередині нашої сторінки.
 *
 * Координати ніхто не набирає з голови — їх беруть у Google Maps і вставляють.
 * А вставляють різне: пару «50.4501, 30.5234», ту саму пару з комою замість
 * крапки (так її копіює система з українською локаллю), або просто посилання
 * на місце. Тому поле в адмінці одне й приймає всі три види: людина робить те,
 * що зручно їй, а розбирає це `parse`.
 *
 * Геокодування (адреса → координати) свідомо немає. Воно потребувало б ще
 * одного платного API, а головне — вгадувало б: «вул. Медова, 12» без міста
 * знайдеться в десятку населених пунктів, і мітка тихо стала б не там. Точку
 * ставить людина один раз і бачить, куди саме.
 */
class Geo
{
    /**
     * Координати з того, що вставили в поле.
     *
     * @return array{lat:float,lng:float}|null null — розібрати не вдалося
     */
    public static function parse(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '') return null;

        // Посилання на місце. Google дає кілька форм, і в них трапляється по дві
        // пари чисел: /@ — центр карти, !3d!4d — сама мітка. Мітка точніша, тож
        // її пробуємо першою.
        if (preg_match('~!3d(-?\d+\.\d+)!4d(-?\d+\.\d+)~', $raw, $m)) {
            return self::valid((float)$m[1], (float)$m[2]);
        }
        if (preg_match('~[/@?=,](-?\d{1,3}\.\d{3,}),\s*(-?\d{1,3}\.\d{3,})~', $raw, $m)) {
            return self::valid((float)$m[1], (float)$m[2]);
        }

        // Пара чисел. Кома буває і роздільником пари, і десятковою — розрізняємо
        // за кількістю чисел: «50,4501, 30,5234» дає чотири шматки, «50.45, 30.52» два.
        $parts = preg_split('~[^\d\-\.,]+~u', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $nums = [];
        foreach ($parts as $p) {
            foreach (explode(',', $p) as $chunk) {
                $chunk = trim($chunk, " \t.,");
                if ($chunk !== '' && preg_match('~^-?\d+(\.\d+)?$~', $chunk)) $nums[] = $chunk;
            }
        }
        if (count($nums) === 4) {                       // 50 , 4501 , 30 , 5234
            return self::valid((float)($nums[0] . '.' . $nums[1]), (float)($nums[2] . '.' . $nums[3]));
        }
        if (count($nums) === 2) {
            return self::valid((float)$nums[0], (float)$nums[1]);
        }
        return null;
    }

    /** Пара в межах глобуса. Нуль-нуль відкидаємо: це не Гвінейська затока, а порожнє поле. */
    private static function valid(float $lat, float $lng): ?array
    {
        if (abs($lat) > 90 || abs($lng) > 180) return null;
        if ($lat === 0.0 && $lng === 0.0) return null;
        return ['lat' => round($lat, 7), 'lng' => round($lng, 7)];
    }

    /** Чи має точка мітку на карті */
    public static function has(array $store): bool
    {
        return $store['lat'] !== null && $store['lat'] !== ''
            && $store['lng'] !== null && $store['lng'] !== '';
    }

    /** Як координати показуються в полі: у тому ж вигляді, у якому їх вставляють */
    public static function format(array $store): string
    {
        if (!self::has($store)) return '';
        return rtrim(rtrim(number_format((float)$store['lat'], 7, '.', ''), '0'), '.')
            . ', ' . rtrim(rtrim(number_format((float)$store['lng'], 7, '.', ''), '0'), '.');
    }

    /**
     * Посилання на маршрут у Google Maps.
     *
     * Саме маршрут, а не «показати місце»: людина відкриває карту з телефона,
     * коли вже зібралась їхати. Координати, а не адреса рядком, — щоб застосунок
     * не шукав повторно й не привів до іншої «Медової, 12».
     */
    public static function routeUrl(array $store): string
    {
        if (self::has($store)) {
            return 'https://www.google.com/maps/dir/?api=1&destination='
                . rawurlencode($store['lat'] . ',' . $store['lng']);
        }
        // Координат немає — ведемо хоч за адресою: це краще, ніж мертва кнопка
        $q = trim(implode(', ', array_filter([$store['city'] ?? '', $store['address'] ?? ''])));
        return $q === '' ? '' : 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode($q);
    }

    /** Точка на карті — лише те, що потрібно розмітці мітки */
    public static function points(array $stores): array
    {
        $out = [];
        foreach ($stores as $s) {
            if (!self::has($s)) continue;
            $out[] = [
                'id' => (int)$s['id'],
                'name' => (string)$s['name'],
                'address' => trim(implode(', ', array_filter([$s['city'] ?? '', $s['address'] ?? '']))),
                'phone' => (string)($s['phone'] ?? ''),
                'lat' => (float)$s['lat'],
                'lng' => (float)$s['lng'],
                'route' => self::routeUrl($s),
            ];
        }
        return $out;
    }
}
