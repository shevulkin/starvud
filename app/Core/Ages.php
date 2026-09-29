<?php
declare(strict_types=1);

/**
 * Вік дитини — головний фільтр магазину іграшок.
 *
 * Батьки шукають не «ляльки», а «щось для дворічки». Тому вік — не ще одна
 * характеристика серед десяти, а окремі два числа в товарі: з якого місяця
 * іграшка підходить (age_min) і до якого (age_max, порожньо — без верхньої
 * межі). Місяці, а не роки, бо половина позначок на упаковках саме така:
 * «від 6 місяців», «від 18 місяців».
 *
 * Смуги — чотири відрізки від народження до школи. Смуга показує іграшки,
 * РОЗРАХОВАНІ на цей вік, а не все, що дитині «вже можна»: брязкальце 0+
 * формально підходить і трирічці, але їй воно нецікаве, і батьки, обравши
 * «1–3», не хочуть гортати іграшки для немовлят. Тому:
 *   - товар потрапляє в смугу за віком, З ЯКОГО він розрахований (age_min);
 *   - старші смуги беруть ще й іграшки, що стартують трохи раніше (пазл
 *     «від 3 років» — добра іграшка й для пʼятирічки), див. START_FROM, але
 *     ніколи — іграшки для немовлят;
 *   - верхня межа товару (age_max), якщо вказана, теж має сягати смуги.
 * Товар без вікової позначки у смуги не потрапляє — лише в «будь-який вік»:
 * показати його в кожній смузі означало б засмітити саме ту видачу, заради
 * якої батьки й обрали вік.
 */
final class Ages
{
    /** ключ => [від, до (не включно), напис, підказка, колір смуги] — місяці */
    public const BANDS = [
        '0' => [0, 12, '0–1 рік', 'брязкальця, пупси, м’які', '#FFB59E'],
        '1' => [12, 36, '1–3 роки', 'гойдалки, каталки, сортери', '#FFD35C'],
        '3' => [36, 60, '3–5 років', 'пазли, ляльки, машинки', '#8ED9B5'],
        '5' => [60, 84, '5–7 років', 'настільні ігри, творчість, намети', '#9CC8FF'],
    ];

    /**
     * З якого віку-старту іграшка ще доречна в смузі, місяці. «3–5» бере
     * іграшки від 2 років (але не ходунки «від року»), «5–7» — від 3 років
     * (пазли й конструктори 3+ цікаві й шестирічці).
     */
    private const START_FROM = ['0' => 0, '1' => 12, '3' => 24, '5' => 36];

    public static function band(?string $key): ?array
    {
        return ($key !== null && isset(self::BANDS[$key])) ? self::BANDS[$key] : null;
    }

    /**
     * Те саме правило, що й sql(), але для вже вибраного рядка товару:
     * лічильники смуг рахуються з одного вибору, а не окремим запитом на смугу.
     */
    public static function matches(array $p, string $key): bool
    {
        $b = self::band($key);
        if (!$b) return true;
        [$from, $to] = $b;
        $low = self::START_FROM[$key] ?? $from;
        if (($p['age_min'] ?? null) === null || $p['age_min'] === '') return false;
        $min = (int)$p['age_min'];
        $max = $p['age_max'] ?? null;
        return $min >= $low && $min < $to && ($max === null || $max === '' || (int)$max >= $from);
    }

    /**
     * SQL-умова «товар підходить дитині з цієї смуги».
     * @return array{0:string,1:array}
     */
    public static function sql(string $key, string $alias = 'p'): array
    {
        $b = self::band($key);
        if (!$b) return ['1=1', []];
        [$from, $to] = $b;
        $low = self::START_FROM[$key] ?? $from;
        return ["($alias.age_min IS NOT NULL AND $alias.age_min >= ? AND $alias.age_min < ?
                 AND ($alias.age_max IS NULL OR $alias.age_max >= ?))", [$low, $to, $from]];
    }

    /**
     * Позначка віку з тексту — так, як її пишуть на упаковці й на Prom:
     * «З народження», «Від 6-ти місяців», «Від 2 до 4 років», «3+».
     *
     * @return array{0:int,1:?int}|null [від, до] у місяцях
     */
    public static function parse(string $text): ?array
    {
        $t = mb_strtolower(trim($text), 'UTF-8');
        if ($t === '') return null;
        if (str_contains($t, 'без обмеж') || str_contains($t, 'народж') || $t === '0+') return [0, null];
        $unit = static fn(string $num, string $rest): int =>
            (int)round((float)str_replace(',', '.', $num) * (preg_match('~міс~u', $rest) ? 1 : 12));
        if (preg_match('~від\s*([\d.,]+)\D*?до\s*([\d.,]+)\s*(.*)$~u', $t, $m)) {
            return [$unit($m[1], $m[3]), $unit($m[2], $m[3]) + (preg_match('~міс~u', $m[3]) ? 0 : 11)];
        }
        if (preg_match('~(?:від\s*)?([\d.,]+)\s*\+?\s*(.*)$~u', $t, $m)) {
            return [$unit($m[1], $m[2]), null];
        }
        return null;
    }

    /**
     * Вік, згаданий в описі, — для товарів, у яких окремого поля немає:
     * «Для дітей від 3 років», «Вік 5+», «від 6 місяців». Беремо перше
     * входження: у тексті Prom воно стоїть у характеристиках і стосується
     * самої іграшки, а не, скажімо, батарейок.
     *
     * @return array{0:int,1:?int}|null
     */
    public static function fromText(string $text): ?array
    {
        $t = mb_strtolower($text, 'UTF-8');
        if (preg_match('~від\s+(\d{1,2})(?:-?(?:ти|х|и))?\s*(міс\w*|рок\w*|рік)~u', $t, $m)) {
            return self::parse('від ' . $m[1] . ' ' . $m[2]);
        }
        if (preg_match('~(?:вік|віком)\D{0,12}(\d{1,2})\s*\+~u', $t, $m)) return [(int)$m[1] * 12, null];
        if (preg_match('~з\s+народження~u', $t)) return [0, null];
        return null;
    }

    /** «від 3 років», «0+», «від 6 місяців», «2–4 роки» — для бейджа на картці */
    public static function label(array $product): string
    {
        $min = $product['age_min'] ?? null;
        $max = $product['age_max'] ?? null;
        if ($min === null && $max === null) return '';
        $min = (int)$min;
        if ($max !== null) {
            // max зберігається як останній місяць проміжку (4 роки = 59 міс.)
            return self::years($min) . '–' . self::yearsWord(intdiv((int)$max, 12));
        }
        if ($min === 0) return '0+';
        if ($min < 12 || $min % 12 !== 0) return 'від ' . $min . ' міс.';
        return 'від ' . self::yearsWord(intdiv($min, 12), true);
    }

    /** Колір картки — смуга ростоміра, з якої іграшка «починається» */
    public static function tint(array $product): string
    {
        $min = $product['age_min'] ?? null;
        if ($min === null) return '#FFE8D1';
        foreach (self::BANDS as [$from, $to, , , $color]) {
            if ((int)$min < $to) return $color;
        }
        return '#9CC8FF';
    }

    private static function years(int $months): string
    {
        return (string)intdiv($months, 12);
    }

    /** 1 рік, 2 роки, 5 років; у родовому після «від» — 1 року, 2 років */
    private static function yearsWord(int $n, bool $genitive = false): string
    {
        if ($genitive) return $n . ' ' . ($n % 10 === 1 && $n % 100 !== 11 ? 'року' : 'років');
        $mod10 = $n % 10; $mod100 = $n % 100;
        $w = ($mod10 === 1 && $mod100 !== 11) ? 'рік'
            : (($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) ? 'роки' : 'років');
        return $n . ' ' . $w;
    }
}
