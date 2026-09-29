<?php
declare(strict_types=1);

/**
 * Рахунок на оплату й видаткова накладна.
 *
 * Потрібні там, де покупець — не людина з карткою, а ФОП чи юрособа: їм
 * платіж треба чимось обґрунтувати у своєму обліку, і без рахунку вони просто
 * не куплять.
 *
 * Документ виставляється на ПІДЗАМОВЛЕННЯ, а не на замовлення. Причина та
 * сама, що й у чека з накладною: продавець тут — конкретний ФОП, власник
 * точки, і саме його IBAN, назва й підпис стоять у документі. Замовлення,
 * розділене між двома власниками, — це два рахунки від двох різних продавців,
 * і звести їх в один означало б, що хтось виставляє рахунок за чужий товар.
 *
 * Грошей цей клас не рухає й нічого не фіскалізує. Він лише збирає те, що має
 * бути надруковано, і каже, коли документ виставляти НЕ МОЖНА.
 */
class Invoice
{
    /**
     * Хто покупець. Від цього залежить не оформлення, а те, чи взагалі можна
     * продавати: ФОП на другій групі єдиного податку має право продавати
     * населенню й платникам єдиного податку, але не тим, хто на загальній
     * системі. Помилка тут коштує не документа, а права на спрощену систему.
     */
    public const BUYER_TYPES = [
        'person'  => 'Фізична особа',
        'ep'      => 'ФОП на єдиному податку',
        'general' => 'Юрособа або ФОП на загальній системі',
    ];

    /** Чим розраховуються. Рахунок — єдиний спосіб, що потребує документів наперед. */
    public const KINDS = [
        'cash'    => 'Готівка',
        'card'    => 'Картка',
        'invoice' => 'Рахунок (IBAN)',
        'cod'     => 'Накладений платіж',
    ];

    public static function kindLabel(?string $kind): string
    {
        return self::KINDS[(string)$kind] ?? '—';
    }

    public static function buyerLabel(?string $type): string
    {
        return self::BUYER_TYPES[(string)$type] ?? 'не вказано';
    }

    /**
     * Номер документа.
     *
     * Беремо номер підзамовлення й дописуємо префікс: покупець і продавець
     * мають упізнавати документ за тим самим номером, що й замовлення, інакше
     * при звірці доводиться тримати в голові дві нумерації. Слеш у номері
     * частини бухгалтерські програми не люблять, тому міняємо на дефіс.
     */
    public static function number(array $child, string $kind = 'inv'): string
    {
        $base = str_replace('/', '-', (string)$child['number']);
        return ($kind === 'inv' ? 'Р-' : 'В-') . $base;
    }

    /**
     * Чого бракує, щоб виставити рахунок.
     *
     * Список, а не «так/ні»: продавцю треба знати, що саме заповнити й чи це
     * взагалі його робота.
     *
     * @return string[]
     */
    public static function missing(array $child, array $parent): array
    {
        $out = [];
        $owner = Owners::ofStore($child['store_id'] ? (int)$child['store_id'] : null);

        if (!$owner) {
            $out[] = 'у точки не вказано власника — рахунок виставляти нема від кого'
                . ' (Мережа → Власники)';
            return $out;
        }
        if (trim((string)($owner['iban'] ?? '')) === '') $out[] = 'у власника не вказано IBAN';
        if (trim((string)($owner['full_name'] ?? '')) === '' && trim((string)$owner['name']) === '') {
            $out[] = 'у власника не вказано повної назви';
        }
        if (trim((string)($owner['tax_id'] ?? '')) === '') $out[] = 'у власника не вказано ІПН/ЄДРПОУ';
        if (round((float)$child['total'], 2) <= 0) $out[] = 'сума нульова';

        // Найважливіше — і це не про заповненість полів
        $forbidden = self::forbidden($owner, (string)($parent['buyer_type'] ?? ''));
        if ($forbidden !== '') $out[] = $forbidden;
        return $out;
    }

    /**
     * Чи має цей продавець право продавати такому покупцю.
     *
     * Друга група єдиного податку може обслуговувати населення й платників
     * єдиного податку. Продаж юрособі на загальній системі позбавляє права на
     * спрощену систему — це не «зауваження до документа», а втрата статусу,
     * і дізнатись про неї з перевірки коштує значно дорожче, ніж побачити тут.
     *
     * Перша група ще вужча: лише роздріб населенню.
     *
     * @return string порожньо — можна
     */
    public static function forbidden(array $owner, string $buyerType): string
    {
        $ep = $owner['ep_group'] !== null ? (int)$owner['ep_group'] : null;
        if ($ep === null) return '';            // загальна система — обмежень немає
        if ($buyerType === '' || $buyerType === 'person') return '';

        if ($ep === 1) {
            return 'перша група єдиного податку торгує лише населенню — цей продаж їй заборонений';
        }
        if ($ep === 2 && $buyerType === 'general') {
            return 'друга група єдиного податку не має права продавати юрособам і ФОПам на '
                . 'загальній системі — оформіть продаж від іншого власника';
        }
        return '';
    }

    /**
     * Усе, що треба надрукувати.
     *
     * Суми беремо з підзамовлення, а не рахуємо заново: покупець платить рівно
     * те, що бачив, і документ мусить це повторювати, а не перевіряти.
     *
     * @return array{number:string,date:string,seller:array,buyer:array,rows:array,total:float,discount:float}
     */
    public static function build(array $child, array $parent, string $kind = 'inv'): array
    {
        $owner = Owners::ofStore($child['store_id'] ? (int)$child['store_id'] : null) ?? [];
        $items = DB::all('SELECT * FROM order_items WHERE order_id = ? ORDER BY id', [(int)$child['id']]);

        $rows = [];
        $no = 0;
        foreach ($items as $it) {
            $title = trim((string)$it['title']);
            if (($it['variant_name'] ?? '') !== '') $title .= ', ' . $it['variant_name'];
            $rows[] = [
                'no' => ++$no,
                'title' => $title,
                'unit' => (string)(DB::val('SELECT unit FROM products WHERE id = ?',
                    [(int)$it['product_id']]) ?: 'шт'),
                'qty' => (int)$it['qty'],
                'price' => round((float)$it['price'], 2),
                'sum' => round((float)$it['sum'], 2),
            ];
        }

        return [
            'number' => self::number($child, $kind),
            'date' => date('d.m.Y'),
            'seller' => [
                'name' => trim((string)($owner['full_name'] ?? '')) ?: (string)($owner['name'] ?? ''),
                'tax_id' => (string)($owner['tax_id'] ?? ''),
                'iban' => (string)($owner['iban'] ?? ''),
                'bank' => (string)($owner['bank'] ?? ''),
                'address' => (string)($owner['address'] ?? ''),
                'signer' => (string)($owner['signer'] ?? ''),
                'vat' => !empty($owner['vat']),
            ],
            'buyer' => [
                'name' => trim((string)($parent['buyer_name'] ?? '')) ?: (string)$parent['name'],
                'tax_id' => (string)($parent['buyer_tax_id'] ?? ''),
                'type' => (string)($parent['buyer_type'] ?? ''),
                'phone' => (string)$parent['phone'],
            ],
            'rows' => $rows,
            'subtotal' => round((float)$child['subtotal'], 2),
            'discount' => round((float)$child['discount'], 2),
            'total' => round((float)$child['total'], 2),
        ];
    }

    /**
     * Сума прописом — без неї рахунок не приймають.
     *
     * Копійки лишаємо цифрами: так друкують усі бухгалтерські програми, і так
     * менше шансів помилитись у відмінюванні.
     */
    public static function words(float $sum): string
    {
        $hrn = (int)floor(round($sum, 2));
        $kop = (int)round((round($sum, 2) - $hrn) * 100);
        return trim(self::intWords($hrn) . ' грн ' . str_pad((string)$kop, 2, '0', STR_PAD_LEFT) . ' коп.');
    }

    private static function intWords(int $n): string
    {
        if ($n === 0) return 'нуль';
        $ones = ['', 'один', 'два', 'три', 'чотири', 'пʼять', 'шість', 'сім', 'вісім', 'девʼять'];
        $onesF = ['', 'одна', 'дві', 'три', 'чотири', 'пʼять', 'шість', 'сім', 'вісім', 'девʼять'];
        $teens = ['десять', 'одинадцять', 'дванадцять', 'тринадцять', 'чотирнадцять',
                  'пʼятнадцять', 'шістнадцять', 'сімнадцять', 'вісімнадцять', 'девʼятнадцять'];
        $tens = ['', '', 'двадцять', 'тридцять', 'сорок', 'пʼятдесят', 'шістдесят',
                 'сімдесят', 'вісімдесят', 'девʼяносто'];
        $hunds = ['', 'сто', 'двісті', 'триста', 'чотириста', 'пʼятсот', 'шістсот',
                  'сімсот', 'вісімсот', 'девʼятсот'];

        // Тисячі жіночого роду («дві тисячі»), решта — чоловічого («два мільйони»)
        $groups = [
            [1000000000, ['мільярд', 'мільярди', 'мільярдів'], false],
            [1000000, ['мільйон', 'мільйони', 'мільйонів'], false],
            [1000, ['тисяча', 'тисячі', 'тисяч'], true],
        ];

        $out = [];
        foreach ($groups as [$size, $forms, $female]) {
            $count = intdiv($n, $size);
            if ($count === 0) continue;
            $n %= $size;
            $out[] = self::under1000($count, $female ? $onesF : $ones, $teens, $tens, $hunds);
            $out[] = self::plural($count, $forms);
        }
        if ($n > 0) $out[] = self::under1000($n, $ones, $teens, $tens, $hunds);
        return implode(' ', array_filter($out));
    }

    private static function under1000(int $n, array $ones, array $teens, array $tens, array $hunds): string
    {
        $out = [];
        if ($n >= 100) { $out[] = $hunds[intdiv($n, 100)]; $n %= 100; }
        if ($n >= 10 && $n <= 19) { $out[] = $teens[$n - 10]; $n = 0; }
        if ($n >= 20) { $out[] = $tens[intdiv($n, 10)]; $n %= 10; }
        if ($n > 0) $out[] = $ones[$n];
        return implode(' ', array_filter($out));
    }

    /** «1 тисяча, 2 тисячі, 5 тисяч» — правило те саме для всіх розрядів */
    private static function plural(int $n, array $forms): string
    {
        $n = abs($n) % 100;
        if ($n >= 11 && $n <= 19) return $forms[2];
        $n %= 10;
        if ($n === 1) return $forms[0];
        if ($n >= 2 && $n <= 4) return $forms[1];
        return $forms[2];
    }
}
