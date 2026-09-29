<?php
declare(strict_types=1);

/**
 * Штрихкоди EAN-13: власні коди для товарів, у яких немає фабричної етикетки.
 *
 * Свою банку меду ніхто не реєструє в GS1 — і не треба. Стандарт залишив для
 * цього префікси 20–29: коди з них дійсні всередині магазину й нікому за його
 * межами не належать. Тобто такий код можна надрукувати й сканувати в себе,
 * не конфліктуючи з жодним чужим товаром у світі.
 *
 * Код виводимо з id позиції, а не з лічильника: тоді він завжди той самий, і
 * повторна генерація нічого не переставляє. Друга цифра після префікса каже, це
 * товар чи фасовка, — інакше товар №7 і варіант №7 отримали б один код.
 */
class Barcode
{
    /** GS1 лишив 20–29 для внутрішнього вжитку магазину */
    public const INTERNAL_PREFIX = '200';

    /** Контрольна цифра EAN-13 (і EAN-8): саме вона відрізняє код від опечатки */
    public static function checkDigit(string $digits): string
    {
        $sum = 0;
        $len = strlen($digits);
        for ($i = 0; $i < $len; $i++) {
            // вага 3 стоїть на других позиціях, рахуючи з кінця (перед контрольною)
            $weight = (($len - 1 - $i) % 2 === 0) ? 3 : 1;
            $sum += (int)$digits[$i] * $weight;
        }
        return (string)((10 - ($sum % 10)) % 10);
    }

    /**
     * Власний код позиції.
     *
     * @param string $kind 'p' — товар, 'v' — фасовка
     * @param int    $id   її id
     */
    public static function make(string $kind, int $id): string
    {
        $body = self::INTERNAL_PREFIX . ($kind === 'v' ? '2' : '1') . str_pad((string)$id, 8, '0', STR_PAD_LEFT);
        return $body . self::checkDigit($body);
    }

    public static function valid(string $code): bool
    {
        $code = trim($code);
        if (!preg_match('/^\d{8}$|^\d{13}$/', $code)) return false;
        return self::checkDigit(substr($code, 0, -1)) === substr($code, -1);
    }

    /**
     * Що не так із кодом — людськими словами. null — код придатний.
     *
     * Остання цифра штрихкоду не випадкова: вона рахується з попередніх саме
     * для того, щоб описку було видно. Тож коли код вводять руками, ця
     * перевірка ловить найдорожчу помилку — код, який виглядає правильним, але
     * жоден сканер його не знайде. І одразу каже, яким він має бути.
     */
    public static function problem(string $code): ?string
    {
        $code = trim($code);
        if ($code === '') return null;                  // порожнє поле — не помилка
        if (!preg_match('/^\d+$/', $code)) return 'Штрихкод складається лише з цифр';

        $len = strlen($code);
        if ($len !== 8 && $len !== 13) {
            return 'У штрихкоді 13 цифр (або 8 у короткому), а тут ' . $len;
        }
        if (self::valid($code)) return null;

        $body = substr($code, 0, -1);
        return 'Контрольна цифра не сходиться — схоже, має бути ' . $body . self::checkDigit($body);
    }

    /** Той самий код із виправленою останньою цифрою (порожньо — виправляти нічого) */
    public static function fixed(string $code): string
    {
        $code = trim($code);
        if (!preg_match('/^\d{8}$|^\d{13}$/', $code) || self::valid($code)) return '';
        $body = substr($code, 0, -1);
        return $body . self::checkDigit($body);
    }

    /** Чи це наш внутрішній код (а не фабричний з етикетки) */
    public static function isInternal(string $code): bool
    {
        return str_starts_with(trim($code), self::INTERNAL_PREFIX);
    }

    // ------------------------------------------------------------------ друк

    /** Бітові таблиці стандарту: 1 — чорний модуль */
    private const L = ['0001101','0011001','0010011','0111101','0100011',
                       '0110001','0101111','0111011','0110111','0001011'];
    private const G = ['0100111','0110011','0011011','0100001','0011101',
                       '0111001','0000101','0010001','0001001','0010111'];
    private const PARITY = ['LLLLLL','LLGLGG','LLGGLG','LLGGGL','LGLLGG',
                            'LGGLLG','LGGGLL','LGLGLG','LGLGGL','LGGLGL'];

    /** Права цифра — це ліва навиворіт */
    private static function right(int $d): string
    {
        return strtr(self::L[$d], ['0' => '1', '1' => '0']);
    }

    /** Малюнок коду: 95 модулів EAN-13 (67 для EAN-8) у вигляді рядка з 0 і 1 */
    public static function bits(string $code): string
    {
        $code = trim($code);
        if (!self::valid($code)) return '';
        $d = array_map('intval', str_split($code));

        if (count($d) === 8) {
            $bits = '101';
            for ($i = 0; $i < 4; $i++) $bits .= self::L[$d[$i]];
            $bits .= '01010';
            for ($i = 4; $i < 8; $i++) $bits .= self::right($d[$i]);
            return $bits . '101';
        }

        $parity = self::PARITY[$d[0]];
        $bits = '101';
        for ($i = 1; $i <= 6; $i++) {
            $bits .= $parity[$i - 1] === 'L' ? self::L[$d[$i]] : self::G[$d[$i]];
        }
        $bits .= '01010';
        for ($i = 7; $i <= 12; $i++) $bits .= self::right($d[$i]);
        return $bits . '101';
    }

    /**
     * Код картинкою — щоб його можна було надрукувати й наклеїти.
     *
     * SVG, а не PNG: він друкується різко на будь-якому принтері й не потребує
     * ні бібліотек, ні тимчасових файлів. Порожній рядок означає «код не
     * малюється» (не EAN) — шаблон тоді просто нічого не покаже.
     */
    public static function svg(string $code, int $height = 46, int $module = 2): string
    {
        $bits = self::bits($code);
        if ($bits === '') return '';
        $quiet = 9 * $module;
        $width = strlen($bits) * $module + $quiet * 2;
        $barH = $height - 14;                       // знизу лишаємо місце під цифри

        $rects = '';
        $len = strlen($bits);
        for ($i = 0; $i < $len; $i++) {
            if ($bits[$i] !== '1') continue;
            // роздільники тягнемо трохи нижче — так само, як на справжніх етикетках
            $long = $i < 3 || $i >= $len - 3 || ($i >= 45 && $i < 50 && $len === 95);
            $rects .= '<rect x="' . ($quiet + $i * $module) . '" y="0" width="' . $module
                . '" height="' . ($long ? $barH + 6 : $barH) . '"/>';
        }
        return '<svg class="bc" xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="' . $height
            . '" viewBox="0 0 ' . $width . ' ' . $height . '" role="img" aria-label="' . e($code) . '">'
            . '<rect width="' . $width . '" height="' . $height . '" fill="#fff"/>'
            . '<g fill="#000">' . $rects . '</g>'
            . '<text x="' . ($width / 2) . '" y="' . ($height - 1) . '" text-anchor="middle"'
            . ' font-family="monospace" font-size="11" fill="#000">' . e($code) . '</text></svg>';
    }
}
