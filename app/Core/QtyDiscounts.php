<?php
declare(strict_types=1);

/**
 * Редагування оптової шкали. Читає її Catalog (qtyResolve/qtyPercent) — тут
 * лише те, що потрібно адмінці: дістати шкалу одного яруса й записати її назад.
 *
 * Ярусів три — товар, розділ, загальна, — і форма в кожного однакова. Тому
 * один клас на всі три: різниця між ними лише в тому, що стоїть у product_id
 * та category_id, і розводити це на три майже однакові шматки коду означало б
 * тричі виправляти кожну зміну правил.
 */
class QtyDiscounts
{
    /**
     * Найменший поріг, який має сенс. «Від 1 шт» — це не опт, а просто нижча
     * ціна: така знижка діяла б завжди й непомітно підмінила б собою цінник.
     * Для «дешевше всім» є акція, і саме там її видно як акцію.
     */
    public const MIN_QTY = 2;

    /** Стеля відсотка в самій шкалі: 100% — це «безкоштовно», і це не знижка */
    public const MAX_PERCENT = 99.0;

    /** Шкала одного яруса, без наслідування. Порожньо — ярус не заповнений */
    public static function level(?int $productId, ?int $categoryId): array
    {
        if ($productId) {
            return DB::all('SELECT * FROM qty_discounts WHERE product_id = ? ORDER BY min_qty, id', [$productId]);
        }
        if ($categoryId) {
            return DB::all('SELECT * FROM qty_discounts WHERE product_id IS NULL AND category_id = ? ORDER BY min_qty, id',
                [$categoryId]);
        }
        return DB::all('SELECT * FROM qty_discounts WHERE product_id IS NULL AND category_id IS NULL ORDER BY min_qty, id');
    }

    /**
     * Записати шкалу яруса з форми. Ярус перезаписується цілком: рядок, який
     * прибрали у формі, має зникнути й з бази, а звіряти два списки порядково
     * тут нічого не дає — шкала коротка, і в неї немає власної історії.
     *
     * Порожній $input — ярус очищено, і це осмислена дія: товар повертається
     * до шкали розділу. «Опту немає» — це не порожня шкала, а знятий прапорець
     * wholesale у картці товару.
     *
     * @param array $input рядки виду ['min_qty' => '5', 'percent' => '5']
     * @return string[] що саме відкинули — щоб адмінка не мовчала про це
     */
    public static function save(?int $productId, ?int $categoryId, array $input): array
    {
        $errors = [];
        $tiers = [];
        foreach ($input as $row) {
            $qty = trim((string)($row['min_qty'] ?? ''));
            $pct = trim((string)($row['percent'] ?? ''));
            // Порожній рядок — це не помилка, а незаповнений запас у формі
            if ($qty === '' && $pct === '') continue;

            $qty = (int)$qty;
            $pct = (float)str_replace(',', '.', $pct);
            if ($qty < self::MIN_QTY) {
                $errors[] = 'Поріг «від ' . $qty . ' шт» пропущено: опт починається щонайменше з ' . self::MIN_QTY . ' шт.';
                continue;
            }
            if ($pct <= 0 || $pct > self::MAX_PERCENT) {
                $errors[] = 'Рядок «від ' . $qty . ' шт» пропущено: відсоток має бути від 0 до ' . (int)self::MAX_PERCENT . '.';
                continue;
            }
            // Два рядки на один поріг — це не дві знижки, а описка. Лишаємо
            // більший відсоток: він і виграв би при розрахунку (Catalog::qtyPercent),
            // тож база має показувати те саме, що порахує кошик.
            $tiers[$qty] = isset($tiers[$qty]) ? max($tiers[$qty], $pct) : $pct;
        }
        ksort($tiers);

        // Шкала, у якій більша партія дешевша не більше за меншу, — не шкала:
        // покупець побачив би «від 10 шт −5%» під «від 5 шт −7%» і мав рацію,
        // питаючи, навіщо тоді брати десять.
        $prev = 0.0;
        foreach ($tiers as $qty => $pct) {
            if ($pct <= $prev) {
                $errors[] = 'Поріг «від ' . $qty . ' шт» пропущено: він мусить давати більше за попередній.';
                unset($tiers[$qty]);
                continue;
            }
            $prev = $pct;
        }

        DB::tx(function () use ($productId, $categoryId, $tiers) {
            if ($productId) DB::delete('qty_discounts', 'product_id = ?', [$productId]);
            elseif ($categoryId) DB::delete('qty_discounts', 'product_id IS NULL AND category_id = ?', [$categoryId]);
            else DB::delete('qty_discounts', 'product_id IS NULL AND category_id IS NULL');

            foreach ($tiers as $qty => $pct) {
                DB::insert('qty_discounts', [
                    'product_id' => $productId ?: null,
                    'category_id' => $productId ? null : ($categoryId ?: null),
                    'min_qty' => $qty, 'percent' => $pct, 'active' => 1,
                ]);
            }
        });
        Catalog::forgetCaches();

        return $errors;
    }

    /** «від 5 шт −5%, від 10 шт −7%» — шкала одним рядком для списків і підказок */
    public static function line(array $tiers): string
    {
        $parts = [];
        foreach ($tiers as $t) {
            $parts[] = 'від ' . (int)$t['min_qty'] . ' шт −' . self::pct((float)$t['percent']) . '%';
        }
        return implode(', ', $parts);
    }

    /** Відсоток без хвостових нулів: 7.00 → «7», 7.50 → «7,5» */
    public static function pct(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, ',', ''), '0'), ',');
    }

    /**
     * Відсоток для поля редагування — з повною точністю бази (4 знаки).
     * pct() округлює до сотих для читання; у полі форми таке округлення при
     * кожному збереженні тихо зсувало б оптову ціну на копійку
     * (6,0606 % → 6,06 %: 1 550,00 → 1 550,01 грн).
     */
    public static function pctExact(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, ',', ''), '0'), ',');
    }
}
