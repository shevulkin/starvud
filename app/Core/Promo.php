<?php
declare(strict_types=1);

/**
 * Промокоди. Одне місце перевірки на всіх: і на кнопці «Застосувати», і при
 * підтвердженні замовлення. Інакше форма показувала б одну знижку, а в
 * замовлення потрапляла б інша.
 *
 * Головне правило: діє рівно той код, що зараз у полі. Сесія лише памʼятає
 * його між сторінками, але сама по собі знижки не дає.
 */
class Promo
{
    /**
     * Значення з форми. Кодом є лише рядок: `promo_code[]=A&promo_code[]=B` —
     * спроба протягнути два коди одним запитом, і масив тут стає порожнечею,
     * а не «Array». Діяти може рівно один код, бо знижка рахується з одного.
     */
    public static function fromInput($value): string
    {
        return is_string($value) ? $value : '';
    }

    /** Код із бази, якщо він існує, увімкнений і не протермінований */
    public static function find(?string $code): ?array
    {
        $code = mb_strtoupper(trim((string)$code));
        if ($code === '') return null;
        $row = DB::row('SELECT * FROM promo_codes WHERE code = ? AND active = 1', [$code]);
        if (!$row) return null;
        $exp = trim((string)($row['expires_at'] ?? ''));
        if ($exp !== '' && $exp < date('Y-m-d')) return null;
        return $row;
    }

    /**
     * Чи можна скористатися кодом саме цій людині зараз.
     *
     * Ліміти два й вони незалежні: max_uses — скільки разів кодом скористаються
     * всі разом (1 = код для однієї людини), per_user_limit — скільки разів та
     * сама людина (1 = кожному по разу, порожньо = хоч при кожній покупці).
     *
     * @return array{0:?array,1:string} код і порожній рядок — або null і причина
     */
    public static function check(?string $code, ?int $userId = null, ?string $phone = null): array
    {
        $row = self::find($code);
        if (!$row) return [null, 'Такий промокод не діє — перевірте написання або строк дії'];

        $max = (int)($row['max_uses'] ?? 0);
        if ($max > 0 && self::usedTotal((int)$row['id']) >= $max) {
            return [null, $max === 1
                ? 'Цей код одноразовий і вже використаний'
                : 'Ліміт використань цього коду вичерпано'];
        }
        // Гостя без номера порахувати нічим — тоді ліміт «на людину» перевіримо
        // при підтвердженні замовлення, коли номер уже введено.
        $per = (int)($row['per_user_limit'] ?? 0);
        if ($per > 0 && ($userId || $phone) && self::usedBy((int)$row['id'], $userId, $phone) >= $per) {
            return [null, $per === 1
                ? 'Цим кодом ви вже скористались — він діє один раз на людину'
                : 'Ви вже використали цей код максимальну кількість разів'];
        }
        return [$row, ''];
    }

    /** Скільки разів кодом скористались усі разом */
    public static function usedTotal(int $promoId): int
    {
        return (int)DB::val('SELECT COUNT(*) FROM promo_uses WHERE promo_id = ?', [$promoId]);
    }

    /**
     * Скільки разів кодом скористалась ця людина. Залогінену впізнаємо за
     * акаунтом, гостя — за номером: іншого сталого імені в нас немає.
     */
    public static function usedBy(int $promoId, ?int $userId, ?string $phone): int
    {
        $where = []; $args = [$promoId];
        if ($userId) { $where[] = 'user_id = ?'; $args[] = $userId; }
        if ($phone) { $where[] = 'phone = ?'; $args[] = $phone; }
        if (!$where) return 0;
        return (int)DB::val('SELECT COUNT(*) FROM promo_uses WHERE promo_id = ? AND (' . implode(' OR ', $where) . ')', $args);
    }

    /** Записати факт використання — рівно один рядок на замовлення */
    public static function recordUse(array $promo, ?int $orderId, ?int $userId, ?string $phone): void
    {
        DB::insert('promo_uses', [
            'promo_id' => (int)$promo['id'], 'code' => (string)$promo['code'],
            'order_id' => $orderId, 'user_id' => $userId, 'phone' => $phone,
            'created_at' => now(),
        ]);
    }

    /**
     * Привести облік використань до стану замовлення.
     *
     * Ліміт коду має рахувати покупки, а не спроби. Скасоване замовлення —
     * не покупка: знижки ніхто не отримав, товар лишився на складі. А ліміт
     * при цьому зʼїдався назавжди, і найгірше це працювало саме там, де
     * найболючіше: одноразовий код зникав від замовлення, яке скасували
     * через пʼять хвилин, і людина більше не могла ним скористатись.
     *
     * Дія симетрична: підзамовлення можна повернути в роботу, і тоді
     * використання має відновитись — інакше скасування й поновлення стало б
     * способом користуватися одним кодом безкінечно.
     */
    public static function syncUse(array $order, string $status): void
    {
        $code = mb_strtoupper(trim((string)($order['promo_code'] ?? '')));
        if ($code === '') return;
        $orderId = (int)$order['id'];

        if ($status === 'canceled') {
            DB::delete('promo_uses', 'order_id = ?', [$orderId]);
            return;
        }
        if (DB::val('SELECT id FROM promo_uses WHERE order_id = ?', [$orderId])) return;

        // Беремо код напряму, а не через find(): він міг відтоді вичерпатись
        // чи протермінуватись, але це замовлення оформили, коли він ще діяв,
        // і повертати треба саме той факт.
        $promo = DB::row('SELECT * FROM promo_codes WHERE code = ?', [$code]);
        if (!$promo) return;
        self::recordUse($promo, $orderId,
            $order['user_id'] ? (int)$order['user_id'] : null,
            ($order['phone'] ?? '') !== '' ? (string)$order['phone'] : null);
    }

    /**
     * Перевірити код і запамʼятати робочий у сесії (хибний або вичерпаний — забути).
     * @return array{0:?array,1:string} код і порожній рядок — або null і причина
     */
    public static function apply(?string $code, ?int $userId = null, ?string $phone = null): array
    {
        [$row, $err] = self::check($code, $userId, $phone);
        if ($row) $_SESSION['promo_code'] = (string)$row['code'];
        else unset($_SESSION['promo_code']);
        return [$row, $err];
    }

    /**
     * Код, що діє зараз. Якщо форма щось прислала — головне вона (у полі
     * покупець бачить те, за що погоджується платити). Без POST показуємо
     * збережений у сесії — але так само перевіреним: код міг вичерпатись,
     * поки сторінка лежала відкритою.
     */
    public static function current(?string $posted = null, ?int $userId = null, ?string $phone = null): ?array
    {
        if ($posted !== null) return self::apply($posted, $userId, $phone)[0];
        return self::check($_SESSION['promo_code'] ?? null, $userId, $phone)[0];
    }

    public static function forget(): void { unset($_SESSION['promo_code']); }

    /**
     * Знижка на суму позиції. $ownPct — знижка, яка на цій позиції вже є
     * (акція магазину чи стара ціна); від неї залежить, чи додасться код і
     * наскільки. Округлення тут одне на весь код — див. Cart::total.
     */
    public static function cut(float $sum, ?array $promo, float $ownPct = 0.0, ?float $itemCap = null): float
    {
        if (!$promo || $sum <= 0) return 0.0;
        $pct = self::extraPercent($promo, $ownPct, $itemCap);
        return $pct > 0 ? round($sum * $pct / 100, 2) : 0.0;
    }

    /**
     * Скільки відсотків код реально додасть до позиції, яка вже має знижку $ownPct.
     * Не сумується — 0 на будь-якій зниженій ціні; є стеля — код обрізається
     * рівно до неї (акція 20% + код 15% зі стелею 25% дають 5, а не 15).
     *
     * Стель дві й вони з різних місць: у коду своя (max_total_percent), у
     * товару своя (Catalog::discountCap). Діє найменша — стеля означає «не
     * більше ніж», і дві такі умови інакше складатись не вміють.
     */
    public static function extraPercent(array $promo, float $ownPct = 0.0, ?float $itemCap = null): float
    {
        $pct = (float)$promo['percent'];
        if ($ownPct > 0 && !self::stacks($promo)) return 0.0;
        $cap = self::cap($promo, $itemCap);
        if ($cap !== null) $pct = min($pct, max(0.0, $cap - $ownPct));
        return $pct;
    }

    /** Стеля, що справді діє на позицію: найменша з коду й товару. null — стелі немає */
    public static function cap(array $promo, ?float $itemCap = null): ?float
    {
        $own = $promo['max_total_percent'] ?? null;
        if ($own === null || $own === '') return $itemCap;
        return $itemCap === null ? (float)$own : min($itemCap, (float)$own);
    }

    /** Чи складається код із наявними знижками (за замовчуванням — так) */
    public static function stacks(array $promo): bool
    {
        return !array_key_exists('stackable', $promo) || $promo['stackable'] === null || (int)$promo['stackable'] === 1;
    }

    /** Знижка, яка вже є на позиції: скільки відсотків від старої ціни зняли */
    public static function ownPercent(array $row): float
    {
        $old = (float)($row['old'] ?? 0);
        $price = (float)($row['price'] ?? 0);
        if ($old <= 0 || $price <= 0 || $old <= $price) return 0.0;
        return round(($old - $price) / $old * 100, 2);
    }

    /**
     * Чому знижка вийшла меншою за відсоток коду (або її немає зовсім).
     * Порожній рядок — коли код ліг на все й повністю, і пояснювати нічого.
     */
    public static function note(?array $promo, array $rows): string
    {
        if (!$promo) return '';
        $skipped = 0; $applied = 0; $capped = 0; $blocked = 0; $negotiated = 0;
        $capHit = null;
        foreach ($rows as $r) {
            $sum = (float)($r['sum'] ?? 0);
            if ($sum <= 0) continue;
            // Позиція з домовленою ціною — не «товар зі знижкою», якому не
            // пощастило з кодом: там ціну назвала людина, і пояснювати це
            // словами про акції означало б збрехати про причину
            if (!empty($r['offer_id'])) { $negotiated++; continue; }
            $own = self::ownPercent($r);
            $itemCap = $r['cap'] ?? null;
            $eff = self::extraPercent($promo, $own, $itemCap);
            if ($eff <= 0) {
                // Дві різні причини нуля, і покупцеві вони кажуть різне: код
                // не складається зі знижкою — чи складається, але стеля вже
                // вибрана акцією та оптом.
                if ($own > 0 && !self::stacks($promo)) $skipped++;
                else { $blocked++; $capHit ??= self::cap($promo, $itemCap); }
                continue;
            }
            $applied++;
            if ($eff < (float)$promo['percent'] - 0.001) { $capped++; $capHit ??= self::cap($promo, $itemCap); }
        }
        if ($negotiated && !$applied && !$skipped && !$blocked) {
            return 'На позиції з домовленою ціною код не поширюється — ціна вже кінцева';
        }
        if ($skipped && !$applied) return 'Код не діє на товари, які вже продаються зі знижкою';
        if ($skipped) return 'На товари, що вже зі знижкою, код не поширюється';
        if (($capped || $blocked) && $capHit !== null) {
            $cap = rtrim(rtrim(number_format($capHit, 2, ',', ''), '0'), ',');
            return $blocked && !$applied
                ? 'Сумарна знижка на товар обмежена ' . $cap . '%, і її вже вибрано — код нічого не додає'
                : 'Сумарна знижка на товар обмежена ' . $cap . '%, тож на частину позицій код додав менше';
        }
        return '';
    }

    /** «Знижка (MED10 −10%)» — підпис для рядка підсумків */
    public static function label(array $promo): string
    {
        $pct = rtrim(rtrim(number_format((float)$promo['percent'], 2, ',', ''), '0'), ',');
        return 'Знижка (' . $promo['code'] . ' −' . $pct . '%)';
    }
}
