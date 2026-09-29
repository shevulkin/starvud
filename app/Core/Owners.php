<?php
declare(strict_types=1);

/**
 * Власники точок: хто саме продає й перед ким звітує.
 *
 * Мережа з трьох магазинів може бути двома різними платниками податків — два
 * ваші й один дружини. Для покупця це один сайт, для податкової — дві окремі
 * історії: свої ПРРО, свої ключі, свої декларації і, найважливіше, свої ліміти
 * доходу, які перевищуються КОЖЕН ОКРЕМО.
 *
 * Тому власник — не довідник заради довідника. Він відповідає на три питання,
 * на які магазин відповісти не може:
 *   — з якою ставкою пробивати чек (у двох точок одного ФОПа вона одна);
 *   — чиїм іменем підписані документи, яких не пробивав ніхто живий;
 *   — скільки цей платник уже наторгував цього року.
 *
 * Ланцюг налаштувань наскрізний і всюди однаковий: товар → магазин → ВЛАСНИК →
 * загальне налаштування. Порожнє поле означає «як ярусом вище», а не «нічого»:
 * інакше кожну нову точку довелося б заповнювати цілком, аби вона просто
 * працювала як усі.
 */
class Owners
{
    /** Групи єдиного податку — підписи для форми */
    public const EP_GROUPS = [
        1 => '1 група',
        2 => '2 група',
        3 => '3 група',
    ];

    public static function all(bool $activeOnly = false): array
    {
        $where = $activeOnly ? 'WHERE active = 1' : '';
        return DB::all("SELECT * FROM owners $where ORDER BY sort, id");
    }

    public static function byId(?int $id): ?array
    {
        return $id ? DB::row('SELECT * FROM owners WHERE id = ?', [$id]) : null;
    }

    /** Чия це точка; null — власника ще не вказали */
    public static function ofStore(?int $storeId): ?array
    {
        if (!$storeId) return null;
        $ownerId = DB::val('SELECT owner_id FROM stores WHERE id = ?', [$storeId]);
        return $ownerId ? self::byId((int)$ownerId) : null;
    }

    /** Точки власника — [id => назва] */
    public static function stores(int $ownerId): array
    {
        $out = [];
        foreach (DB::all('SELECT id, name FROM stores WHERE owner_id = ? ORDER BY sort, id', [$ownerId]) as $s) {
            $out[(int)$s['id']] = (string)$s['name'];
        }
        return $out;
    }

    /**
     * Каси власника — щоб було видно, який ключ до чого належить.
     *
     * ПРРО реєструють на торгову точку, тож у ФОПа з двома магазинами кас теж
     * дві — і в Device Manager вони звуться по-різному. Ключ при цьому один,
     * його ж і завантажують в обидві. Без цього переліку зʼясовувати, чий
     * «kasa2», доводилось би по картках магазинів по одній.
     *
     * @return array<int, array{store:string,route:string,device:string,ready:bool}>
     */
    public static function cashRegisters(int $ownerId): array
    {
        $out = [];
        foreach (DB::all('SELECT * FROM stores WHERE owner_id = ? ORDER BY sort, id', [$ownerId]) as $s) {
            $route = FiscalProvider::route((int)$s['id']);
            $out[(int)$s['id']] = [
                'store' => (string)$s['name'],
                'route' => FiscalProvider::routeLabel($route['route']),
                // Для хмари каса впізнається токеном, для решти — назвою в DM
                'device' => $route['route'] === 'cloud'
                    ? ($route['token'] !== '' ? 'за токеном' : '')
                    : $route['device'],
                'ready' => FiscalProvider::missing($route) === [],
            ];
        }
        return $out;
    }

    public static function label(?array $owner): string
    {
        if (!$owner) return 'власника не вказано';
        $bits = [(string)$owner['name']];
        if (($owner['ep_group'] ?? null) !== null) $bits[] = self::EP_GROUPS[(int)$owner['ep_group']] ?? '';
        if (!empty($owner['vat'])) $bits[] = 'платник ПДВ';
        return implode(', ', array_filter($bits));
    }

    /**
     * Скільки цей платник наторгував за рік — ЗА ДАНИМИ САЙТУ.
     *
     * Це управлінська цифра, а не декларація: сюди не входять продажі повз
     * сайт, і не враховано нічого, що вміє бухгалтер. Але саме її бракує, щоб
     * вчасно помітити наближення до ліміту єдиного податку — а ліміт у кожного
     * ФОПа свій і перевищується окремо.
     *
     * Рахуємо по ПІДЗАМОВЛЕННЯХ (parent_id IS NOT NULL): саме вони належать
     * точкам, а отже й власникам. Скасовані не рахуємо — грошей за ними немає.
     */
    public static function income(int $ownerId, ?int $year = null): float
    {
        $year = $year ?: (int)date('Y');
        $sum = DB::val(
            "SELECT COALESCE(SUM(o.total), 0) FROM orders o
             JOIN stores s ON s.id = o.store_id
             WHERE s.owner_id = ? AND o.parent_id IS NOT NULL
               AND o.status <> 'canceled' AND o.created_at >= ? AND o.created_at < ?",
            [$ownerId, $year . '-01-01 00:00:00', ($year + 1) . '-01-01 00:00:00']);
        return round((float)$sum, 2);
    }

    /**
     * Що не сходиться у власника.
     *
     * Найдорожча помилка тут одна, і вона тиха: людина бачить «2 група» чи
     * «3 група» у себе в голові й ставить те саме число в поле «податкова
     * група чека», де 3 означає «ПДВ 20% + акциз 5%». Чеки пробиваються, все
     * зелене, а в ДПС їде вигаданий податок. Тому перевіряємо саме поєднання.
     *
     * @return string[]
     */
    public static function problems(array $owner): array
    {
        $out = [];
        $ep = $owner['ep_group'] !== null ? (int)$owner['ep_group'] : null;
        $vat = !empty($owner['vat']);
        $tax = $owner['taxgrp'] !== null ? (int)$owner['taxgrp'] : null;

        // Перша й друга групи єдиного податку платниками ПДВ бути не можуть
        if ($vat && $ep !== null && $ep < 3) {
            $out[] = 'позначено платником ПДВ, хоча ' . (self::EP_GROUPS[$ep] ?? $ep)
                . ' єдиного податку платником ПДВ бути не може';
        }

        if ($tax !== null) {
            $withVat = in_array($tax, [1, 3, 4, 8, 9], true);   // ставки, у яких є ПДВ
            if ($withVat && !$vat) {
                $out[] = 'податкова група чека «' . (Vchasno::TAX_GROUPS[$tax] ?? $tax)
                    . '» містить ПДВ, а власник не позначений платником ПДВ'
                    . ($tax === 3 ? ' — схоже, «3» тут переплутали з третьою групою єдиного податку' : '');
            }
            if (!$withVat && $vat) {
                $out[] = 'власник — платник ПДВ, а в чеках стоїть ставка без ПДВ';
            }
        } elseif ($tax === null) {
            $out[] = 'не вказано податкову групу чека — братиметься загальна з налаштувань';
        }

        if (trim((string)($owner['tax_id'] ?? '')) === '') {
            $out[] = 'не вказано ІПН або ЄДРПОУ — це знадобиться при звірці з ДПС';
        }
        return $out;
    }
}
