<?php
declare(strict_types=1);

/**
 * Таблиці: прочитати XLSX або CSV і скласти XLSX.
 *
 * Потрібне рівно для одного — обміну номенклатурою з кабінетом «Вчасно.Каси»:
 * товари туди заводять файлом, і назад вивантажують теж файлом. API для
 * номенклатури в них немає, тож інакше звірити наш каталог з їхнім не вийде.
 *
 * Чому власний XLSX, а не бібліотека: у проєкті немає залежностей — його
 * ставлять копіюванням на shared-хостинг, і composer там може не бути взагалі.
 * А чому не самий лише CSV: кабінет чекає Excel, і змушувати людину щоразу
 * перезберігати файл — це і зайвий крок, і зайва нагода зіпсувати кодування.
 *
 * ZipArchive теж не вимагаємо: розширення zip вимкнене в багатьох збірках
 * (зокрема в XAMPP за замовчуванням). Zip тут зроблений руками — формат
 * простий, а deflate уміє сам PHP (gzdeflate/gzinflate). Коли розширення є,
 * користуємось ним: чужий перевірений код кращий за свій.
 */
class Sheet
{
    /**
     * Прочитати таблицю. Формат визначаємо за вмістом, а не за назвою: файл
     * із кабінету цілком може приїхати як «expor.xls», будучи насправді CSV.
     *
     * @return array<int, array<int, string>> рядки клітинок
     */
    /**
     * Прочитати таблицю. Приймаємо лише CSV.
     *
     * XLSX ми ЧИТАТИ перестали, хоч і далі складаємо (writeXlsx) — кабінет
     * «Вчасно.Каси» чекає саме Excel. Розбирати ж чужий xlsx означало власний
     * розпакувальник ZIP і `gzinflate()` над завантаженим файлом: вісім
     * дозволених мегабайт стиснутого архіву розгортаються в гігабайти, і сайт
     * лягає від одного натискання «Завантажити».
     *
     * Обійти це можна було б лімітом на розгорнутий розмір, але тоді в проєкті
     * лишався б саморобний парсер бінарного формату заради дії, яку роблять раз
     * на квартал. Кабінет уміє зберігати вивантаження і в CSV — це той самий
     * файл, тільки без парсера.
     */
    public static function read(string $path): array
    {
        $raw = (string)@file_get_contents($path);
        if ($raw === '') return [];
        // Порожній масив тут означає «не змогли прочитати»; людське пояснення
        // дає VchasnoGoods::parse, який знає, про який саме файл ідеться
        if (str_starts_with($raw, "PK\x03\x04")) return [];
        return self::readCsv($raw);
    }

    /** Чи це книга Excel — щоб сказати про це людськими словами, а не «файл порожній» */
    public static function isXlsx(string $path): bool
    {
        $fh = @fopen($path, 'rb');
        if (!$fh) return false;
        $head = (string)fread($fh, 4);
        fclose($fh);
        return $head === "PK\x03\x04";
    }

    // ─────────────────────────────────────────────────────────────────── CSV

    /**
     * CSV із будь-якої Excel-збірки.
     *
     * Дві пастки, через які «файл не читається»: роздільник (український
     * Excel пише крапку з комою) і кодування (він же зберігає у win-1251).
     * Обидві визначаємо самі — питати про це людину, яка просто натиснула
     * «Зберегти як CSV», немає сенсу.
     */
    private static function readCsv(string $raw): array
    {
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = (string)mb_convert_encoding($raw, 'UTF-8', 'Windows-1251');
        }
        // Роздільник рахуємо по всьому файлу, а не по першому рядку: над
        // таблицею у вивантаженнях буває назва звіту («Вивантаження товарів»),
        // у якій немає жодного роздільника — і вибір за нею завжди хибний.
        // Крапка з комою переважує кому й нарівні: у нашій локалі коми
        // трапляються в самих значеннях («Ціна, грн», «120,50»).
        $sep = substr_count($raw, ';') >= substr_count($raw, ',') ? ';' : ',';

        $rows = [];
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $raw);
        rewind($fh);
        while (($cells = fgetcsv($fh, 0, $sep)) !== false) {
            if ($cells === [null]) continue;                      // порожній рядок
            $rows[] = array_map(fn($c) => trim((string)$c), $cells);
        }
        fclose($fh);
        return $rows;
    }

    /**
     * Клітинка, яку Excel не сприйме за формулу.
     *
     * Excel виконує вміст клітинки, якщо той починається з `=`, `+`, `-` або
     * `@` — і робить це мовчки, при самому відкритті файлу. Назва товару
     * «=HYPERLINK(...)» перетворюється на дію на комп'ютері того, хто відкрив
     * вивантаження, тобто зазвичай бухгалтера.
     *
     * Заводити такий товар може лише той, хто має право правити каталог, тож
     * це не діра для сторонніх — це шлях від одного нашого користувача до
     * іншого. Але вивантаження ходять поштою й переживають звільнення, а
     * захист коштує одного апострофа: Excel показує значення як текст і рівно
     * так само його читає людина.
     *
     * Табуляція й переноси на початку — той самий трюк іншим боком: Excel
     * зрізає їх і бачить формулу, наївна перевірка першого символу — ні.
     *
     * XLSX цього не потребує: там клітинки пишуться як inlineStr, тобто
     * оголошено текстом (див. writeXlsx).
     */
    private static function csvCell($value): string
    {
        $s = (string)$value;
        if ($s === '') return $s;
        // Звичайне від'ємне число («-2», «-0.5») формулою не є, і псувати його
        // апострофом означало б зіпсувати саме те, заради чого файл роблять:
        // у колонці залишків воно перестало б додаватись
        if (is_numeric(trim($s))) return $s;
        $probe = ltrim($s, " \t\r\n");
        if ($probe !== '' && str_contains("=+-@", $probe[0])) return "'" . $s;
        return $s;
    }

    public static function writeCsv(array $rows): string
    {
        $fh = fopen('php://temp', 'r+');
        // BOM і крапка з комою — щоб файл відкрився в Excel одразу, а не
        // «кракозябрами в одній колонці»
        fwrite($fh, "\xEF\xBB\xBF");
        foreach ($rows as $row) fputcsv($fh, array_map([self::class, 'csvCell'], $row), ';');
        rewind($fh);
        $out = (string)stream_get_contents($fh);
        fclose($fh);
        return $out;
    }

    // ────────────────────────────────────────────────────────────────── XLSX

    private static function colName(int $index): string
    {
        $s = '';
        $n = $index + 1;
        while ($n > 0) { $r = ($n - 1) % 26; $s = chr(65 + $r) . $s; $n = intdiv($n - 1, 26); }
        return $s;
    }

    /**
     * Скласти XLSX.
     *
     * Усе пишемо рядками (inlineStr): книга без спільних рядків трохи більша,
     * але вдвічі простіша, а головне — артикули з нулями попереду й довгі
     * штрихкоди лишаються тим, чим вони є, а не числами в експоненті.
     */
    public static function writeXlsx(array $rows, string $sheetName = 'Товари'): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ($rows as $i => $row) {
            $r = $i + 1;
            $xml .= '<row r="' . $r . '">';
            $col = 0;
            foreach ($row as $cell) {
                $ref = self::colName($col) . $r;
                $xml .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">'
                      . htmlspecialchars((string)$cell, ENT_QUOTES | ENT_XML1, 'UTF-8')
                      . '</t></is></c>';
                $col++;
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData></worksheet>';

        $name = htmlspecialchars(mb_substr($sheetName, 0, 31), ENT_QUOTES | ENT_XML1, 'UTF-8');
        return self::zipWrite([
            '[Content_Types].xml' =>
                '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                . '<Default Extension="xml" ContentType="application/xml"/>'
                . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                . '</Types>',
            '_rels/.rels' =>
                '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                . '</Relationships>',
            'xl/workbook.xml' =>
                '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
                . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                . '<sheets><sheet name="' . $name . '" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' =>
                '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                . '</Relationships>',
            'xl/worksheets/sheet1.xml' => $xml,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────── ZIP

    /** @param array<string,string> $files */
    private static function zipWrite(array $files): string
    {
        if (class_exists('ZipArchive')) {
            $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
            $zip = new ZipArchive();
            if ($zip->open($tmp, ZipArchive::OVERWRITE) === true) {
                foreach ($files as $name => $content) $zip->addFromString($name, $content);
                $zip->close();
                $out = (string)file_get_contents($tmp);
                @unlink($tmp);
                return $out;
            }
            @unlink($tmp);
        }

        // Свій ZIP: локальний заголовок на кожен файл, потім центральний
        // каталог і кінцевий запис. Дати ставимо однакові — вміст файлу від
        // часу створення не залежить, а однаковий байт у байт результат
        // зручніший, коли доводиться порівнювати два вивантаження.
        $local = '';
        $central = '';
        $offset = 0;
        $count = 0;
        [$dosTime, $dosDate] = [0, 0x21];   // 1980-01-01, найраніша дата формату

        foreach ($files as $name => $content) {
            $crc = crc32($content);
            $raw = strlen($content);
            $deflated = gzdeflate($content, 9);
            // Стиснення без виграшу лишає файл як є: метод 0 читає будь-що
            $useDeflate = $deflated !== false && strlen($deflated) < $raw;
            $data = $useDeflate ? $deflated : $content;
            $method = $useDeflate ? 8 : 0;
            $size = strlen($data);

            $header = pack('vvvvvVVVvv', 20, 0, $method, $dosTime, $dosDate, $crc, $size, $raw, strlen($name), 0);
            $local .= "PK\x03\x04" . $header . $name . $data;

            $central .= "PK\x01\x02" . pack('v', 20) . $header
                      . pack('vvvVV', 0, 0, 0, 0, $offset) . $name;
            $offset = strlen($local);
            $count++;
        }
        return $local . $central
             . "PK\x05\x06" . pack('vvvvVVv', 0, 0, $count, $count, strlen($central), strlen($local), 0);
    }
}
