<?php
declare(strict_types=1);

/**
 * Номенклатура: наш каталог проти каталогу «Вчасно.Каси».
 *
 * У хмарного API «Вчасно.Каси» немає методів для товарів — позиції їдуть у
 * самому чеку рядками. Каталог у їхньому кабінеті ведуть руками або файлом
 * Excel. Тому «синхронізація товарів» тут — це файл в обидва боки й чесна
 * звірка між ними, а не вигаданий обмін, якого немає в чому вести.
 *
 * Навіщо це взагалі, якщо чек і так самодостатній: у кабінеті по товарах
 * будуються звіти, і поки наш «Мед липовий 0.5» і їхній «Мед лип. 0,5л» —
 * два різні рядки, звіт показує дві половинки одного товару. Зшиває їх код:
 * артикул або штрихкод, які ми передаємо в кожному чеку (code / code1).
 *
 * Звірка ЗІСТАВЛЯЄ ФАСОВКИ, а не товари. Каса продає банку, у банки свій
 * штрихкод і своя ціна — «мед узагалі» не має ні того, ні того.
 */
class VchasnoGoods
{
    /**
     * Як називаються колонки в їхньому файлі.
     *
     * Точного шаблону вони не публікують, а назви колонок у вивантаженні
     * різняться між версіями кабінету. Тому шукаємо за змістом назви, а не за
     * порядком: підпис «Штрих-код», «Штрихкод» і «ШК» означають одне й те саме,
     * а прив’язка до третьої колонки зламалась би від першої ж їхньої правки.
     *
     * @var array<string, string[]> наше поле => шматки їхніх підписів
     */
    private const COLUMNS = [
        'name'    => ['назва', 'наименован', 'товар', 'name'],
        'price'   => ['ціна', 'цена', 'price'],
        'taxgrp'  => ['податков', 'налогов', 'пдв', 'ндс', 'tax'],
        'sku'     => ['артикул', 'код товару', 'sku', 'code'],
        'barcode' => ['штрих', 'шк', 'barcode'],
        'uktzed'  => ['уктзед', 'укт зед', 'уктзэд'],
        'unit'    => ['одиниц', 'единиц', 'unit'],
        'ext_id'  => ['id товару', 'id товара', 'ідентифікатор'],
    ];

    /** Порядок колонок у файлі, який складаємо ми (перший рядок — підписи) */
    private const EXPORT_HEADER = [
        'Назва товару', 'Ціна товару', 'Податкова група', 'Артикул', 'Штрихкод', 'Код УКТ ЗЕД', 'Одиниця виміру',
    ];

    // ─────────────────────────────────────────────────────────── читання файлу

    /**
     * Розібрати їхнє вивантаження.
     *
     * Шапку шукаємо в перших рядках, а не беремо перший: у файлах із кабінету
     * над таблицею буває назва вивантаження й порожній рядок.
     *
     * @return array{goods:array, columns:array, error:string}
     */
    public static function parse(string $path): array
    {
        // Книгу Excel не читаємо навмисно (див. Sheet::read) — і кажемо це
        // словами, які підказують наступний крок, а не «файл не читається»
        if (Sheet::isXlsx($path)) {
            return ['goods' => [], 'columns' => [], 'error' =>
                'Це файл Excel (xlsx), а ми читаємо CSV. У кабінеті «Вчасно.Каси» під час '
                . 'вивантаження оберіть формат CSV — або відкрийте цей файл у Excel і збережіть '
                . 'як «CSV (розділювач — крапка з комою)».'];
        }
        $rows = Sheet::read($path);
        if (!$rows) return ['goods' => [], 'columns' => [], 'error' => 'Файл порожній або не читається.'];

        $headerAt = null;
        $map = [];
        foreach (array_slice($rows, 0, 10, true) as $i => $row) {
            $m = self::mapColumns($row);
            // Шапка — це рядок, у якому знайшлися принаймні назва й ще щось:
            // сама лише «назва» трапляється й у заголовку вивантаження
            if (isset($m['name']) && count($m) >= 2) { $headerAt = $i; $map = $m; break; }
        }
        if ($headerAt === null) {
            return ['goods' => [], 'columns' => [], 'error' =>
                'Не знайшли шапку таблиці. Потрібен рядок із підписами колонок — принаймні «Назва товару» '
                . 'і ще одна з: ціна, податкова група, артикул, штрихкод.'];
        }

        $goods = [];
        foreach (array_slice($rows, $headerAt + 1) as $row) {
            $name = trim((string)($row[$map['name']] ?? ''));
            if ($name === '') continue;
            $goods[] = [
                'name' => $name,
                'price' => isset($map['price']) ? self::num($row[$map['price']] ?? '') : null,
                'taxgrp' => isset($map['taxgrp']) ? self::tax($row[$map['taxgrp']] ?? '') : null,
                'sku' => isset($map['sku']) ? trim((string)($row[$map['sku']] ?? '')) : '',
                'barcode' => isset($map['barcode']) ? trim((string)($row[$map['barcode']] ?? '')) : '',
                'uktzed' => isset($map['uktzed']) ? trim((string)($row[$map['uktzed']] ?? '')) : '',
                'unit' => isset($map['unit']) ? trim((string)($row[$map['unit']] ?? '')) : '',
            ];
        }
        return ['goods' => $goods, 'columns' => array_keys($map), 'error' => ''];
    }

    /** Яка колонка що означає: [наше поле => номер колонки] */
    private static function mapColumns(array $row): array
    {
        $map = [];
        foreach ($row as $i => $cell) {
            $label = mb_strtolower(trim((string)$cell));
            if ($label === '') continue;
            foreach (self::COLUMNS as $field => $needles) {
                if (isset($map[$field])) continue;
                foreach ($needles as $needle) {
                    if (str_contains($label, $needle)) { $map[$field] = $i; break 2; }
                }
            }
        }
        return $map;
    }

    /** Число з клітинки: «180,50 грн» → 180.50 */
    private static function num($v): ?float
    {
        $s = preg_replace('/[^\d.,-]/u', '', (string)$v) ?? '';
        $s = str_replace(',', '.', $s);
        if ($s === '' || !is_numeric($s)) return null;
        return round((float)$s, 2);
    }

    /**
     * Податкова група з клітинки.
     *
     * У файлі вона буває і числом («2»), і назвою («Без ПДВ»): у кабінеті
     * групам дають власні підписи. Розпізнаємо обидва; чого не впізнали —
     * лишаємо порожнім, бо вгадана ставка гірша за незаповнену.
     */
    private static function tax($v): ?int
    {
        $s = trim((string)$v);
        if ($s === '') return null;
        if (ctype_digit($s) && isset(Vchasno::TAX_GROUPS[(int)$s])) return (int)$s;
        $low = mb_strtolower($s);
        foreach (Vchasno::TAX_GROUPS as $code => $label) {
            if (mb_strtolower($label) === $low) return $code;
        }
        // «ПДВ 20%» ↔ «20%»: відсоток — те, що люди пишуть найчастіше
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*%/u', $s, $m)) {
            $pct = (float)str_replace(',', '.', $m[1]);
            $byPct = [20.0 => 1, 7.0 => 4, 0.0 => 5, 14.0 => 9];
            if (isset($byPct[$pct]) && !str_contains($low, 'акциз')) return $byPct[$pct];
        }
        if (str_contains($low, 'без') && !str_contains($low, 'акциз')) return 2;
        return null;
    }

    // ─────────────────────────────────────────────────────────── наш каталог

    /**
     * Наші позиції очима каси: рядок на кожну фасовку.
     *
     * Ціна — того магазину, чий цінник звіряємо: у сусідній точці вона інша,
     * і «розбіжність цін» без вказаного магазину нічого не означала б.
     */
    public static function ours(?int $storeId = null): array
    {
        $out = [];
        foreach (DB::all('SELECT * FROM products WHERE active = 1 ORDER BY name') as $p) {
            $variants = Catalog::variants((int)$p['id']);
            foreach ($variants ?: [null] as $v) {
                [$price] = Catalog::price($p, $v, $storeId);
                $out[] = [
                    'product_id' => (int)$p['id'],
                    'variant_id' => $v ? (int)$v['id'] : null,
                    'name' => (string)$p['name'] . ($v ? ', ' . $v['name'] : ''),
                    'price' => $price !== null ? round((float)$price, 2) : null,
                    'sku' => trim((string)($v['sku'] ?? $p['sku'] ?? '')),
                    'barcode' => trim((string)($v['barcode'] ?? $p['barcode'] ?? '')),
                    'uktzed' => trim((string)($p['uktzed'] ?? '')),
                    'taxgrp' => $p['taxgrp'] !== null ? (int)$p['taxgrp'] : null,
                    'unit' => trim((string)($p['unit'] ?? '')) ?: 'шт',
                ];
            }
        }
        return $out;
    }

    // ────────────────────────────────────────────────────────────────── звірка

    /**
     * Зіставити два каталоги.
     *
     * Порядок ключів не випадковий: штрихкод — те, що надруковано на етикетці
     * й однакове в обох системах; артикул — наш власний код, теж надійний;
     * назва — остання надія, бо збіг за назвою легко помилковий («Мед 0.5»
     * буває і липовий, і гречаний). Тому збіг за назвою окремо позначається
     * як слабкий: приймати його чи ні, вирішує людина.
     *
     * @return array{rows:array, stats:array}
     */
    public static function compare(array $theirs, ?int $storeId = null): array
    {
        $ours = self::ours($storeId);

        $byBarcode = $bySku = $byName = [];
        foreach ($theirs as $i => $t) {
            if ($t['barcode'] !== '') $byBarcode[self::key($t['barcode'])] ??= $i;
            if ($t['sku'] !== '') $bySku[self::key($t['sku'])] ??= $i;
            $byName[self::key($t['name'])] ??= $i;
        }

        $rows = [];
        $used = [];
        foreach ($ours as $o) {
            $idx = null; $how = '';
            if ($o['barcode'] !== '' && isset($byBarcode[self::key($o['barcode'])])) {
                $idx = $byBarcode[self::key($o['barcode'])]; $how = 'штрихкод';
            } elseif ($o['sku'] !== '' && isset($bySku[self::key($o['sku'])])) {
                $idx = $bySku[self::key($o['sku'])]; $how = 'артикул';
            } elseif (isset($byName[self::key($o['name'])])) {
                $idx = $byName[self::key($o['name'])]; $how = 'назва';
            }

            $t = $idx !== null ? $theirs[$idx] : null;
            if ($idx !== null) $used[$idx] = true;

            $diff = [];
            if ($t) {
                if ($o['price'] !== null && $t['price'] !== null && abs($o['price'] - $t['price']) >= 0.01) {
                    $diff['price'] = [$o['price'], $t['price']];
                }
                // Порівнюємо ефективну групу: у товарі порожньо — діє магазинна,
                // і саме вона потрапить у чек
                $ourTax = $o['taxgrp'] ?? Fiscal::storeTaxGroup($storeId);
                if ($t['taxgrp'] !== null && $ourTax !== $t['taxgrp']) {
                    $diff['taxgrp'] = [$ourTax, $t['taxgrp']];
                }
                if ($o['barcode'] === '' && $t['barcode'] !== '') $diff['barcode'] = ['', $t['barcode']];
                if ($o['sku'] === '' && $t['sku'] !== '') $diff['sku'] = ['', $t['sku']];
            }

            $rows[] = [
                'state' => $t === null ? 'only_ours' : ($diff ? 'differs' : 'same'),
                'match' => $how,
                'ours' => $o,
                'theirs' => $t,
                'diff' => $diff,
            ];
        }

        foreach ($theirs as $i => $t) {
            if (isset($used[$i])) continue;
            $rows[] = ['state' => 'only_theirs', 'match' => '', 'ours' => null, 'theirs' => $t, 'diff' => []];
        }

        $stats = ['same' => 0, 'differs' => 0, 'only_ours' => 0, 'only_theirs' => 0, 'weak' => 0];
        foreach ($rows as $r) {
            $stats[$r['state']]++;
            if ($r['match'] === 'назва') $stats['weak']++;
        }
        return ['rows' => $rows, 'stats' => $stats];
    }

    /** Ключ зіставлення: різниця в регістрі й пробілах — не різниця товарів */
    private static function key(string $s): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $s) ?? $s));
    }

    // ─────────────────────────────────────────────────────────────── перенести

    /**
     * Перенести до себе те, чого в нас немає: коди й податкові групи.
     *
     * Заповнюємо лише ПОРОЖНЄ. Свої дані не перетираємо навіть тоді, коли вони
     * розходяться: у нас теж є артикули, і мовчазна заміна нашого коду їхнім —
     * найкоротший шлях до товару, який більше не знаходить сканер. Розбіжності
     * лишаються у звіті, де їх видно й де їх вирішує людина.
     *
     * Ціни не чіпаємо взагалі: ціна — це рішення магазину, а не запис у чужому
     * довіднику, і в різних точках вона різна.
     *
     * @return array{filled:int, notes:string[]}
     */
    public static function apply(array $compared): array
    {
        $filled = 0;
        $notes = [];
        foreach ($compared as $r) {
            if ($r['state'] !== 'differs' || !$r['ours'] || !$r['theirs']) continue;
            // Збіг лише за назвою надто хиткий, щоб на його підставі писати
            // штрихкод: помилка тут відправить сканер на чужий товар
            if ($r['match'] === 'назва') { $notes[] = $r['ours']['name'] . ' — збіг лише за назвою, не чіпали'; continue; }

            $o = $r['ours'];
            $t = $r['theirs'];
            $pPatch = [];
            $vPatch = [];

            if ($o['barcode'] === '' && $t['barcode'] !== '') {
                if ($o['variant_id']) $vPatch['barcode'] = $t['barcode'];
                else $pPatch['barcode'] = $t['barcode'];
            }
            if ($o['sku'] === '' && $t['sku'] !== '') {
                if ($o['variant_id']) $vPatch['sku'] = $t['sku'];
                else $pPatch['sku'] = $t['sku'];
            }
            // Податкова група й УКТЗЕД належать товару, а не фасовці: ставка не
            // залежить від того, у якій банці той самий мед
            if ($o['taxgrp'] === null && $t['taxgrp'] !== null) $pPatch['taxgrp'] = $t['taxgrp'];
            if ($o['uktzed'] === '' && ($t['uktzed'] ?? '') !== '') $pPatch['uktzed'] = $t['uktzed'];

            if ($pPatch) {
                $pPatch['updated_at'] = now();
                DB::update('products', $pPatch, 'id = ?', [(int)$o['product_id']]);
                $filled++;
            }
            if ($vPatch && $o['variant_id']) {
                DB::update('product_variants', $vPatch, 'id = ?', [(int)$o['variant_id']]);
                $filled++;
            }
        }
        return ['filled' => $filled, 'notes' => $notes];
    }

    // ───────────────────────────────────────────────────────────── вивантажити

    /**
     * Наш каталог у файл для їхнього імпорту.
     *
     * Податкову групу пишемо ефективну — ту, з якою товар піде в чек: у
     * файлі порожня клітинка означала б «не оподатковується», а не «як у
     * магазину», і кабінет прийняв би це за чисту монету.
     */
    public static function export(?int $storeId = null): array
    {
        $rows = [self::EXPORT_HEADER];
        $fallback = Fiscal::storeTaxGroup($storeId);
        foreach (self::ours($storeId) as $o) {
            $rows[] = [
                $o['name'],
                $o['price'] !== null ? number_format($o['price'], 2, '.', '') : '',
                (string)($o['taxgrp'] ?? $fallback),
                $o['sku'],
                $o['barcode'],
                $o['uktzed'],
                $o['unit'],
            ];
        }
        return $rows;
    }
}
