<?php
declare(strict_types=1);

/**
 * Відгуки про магазин.
 *
 * Показуємо лише схвалені. Перенесені з Prom схвалені від початку — їх уже
 * опублікувала площадка; нові з сайту чекають на погляд продавця (див.
 * Schema, таблиця reviews). Оцінок не «підкручуємо» і не ховаємо четвірки:
 * сторінка, де всі відгуки ідеальні, переконує гірше за чесну.
 */
final class Reviews
{
    public const LABELS = [5 => 'Відмінно', 4 => 'Добре', 3 => 'Нормально', 2 => 'Погано', 1 => 'Жахливо'];

    /** Теги, які покупець може поставити, — ті самі, що були на Prom */
    public const TAGS = ['Актуальний опис', 'Актуальна ціна', 'Товар був у наявності', 'Швидко відправили',
                         'Гарне обслуговування', 'Ввічливий продавець', 'Якісна упаковка'];

    /** @return array[] схвалені, найновіші зверху */
    public static function approved(int $limit = 0, int $offset = 0): array
    {
        $sql = 'SELECT * FROM reviews WHERE approved = 1 ORDER BY created_at DESC, id DESC';
        if ($limit > 0) $sql .= ' LIMIT ' . (int)$limit . ' OFFSET ' . max(0, $offset);
        return array_map([self::class, 'hydrate'], DB::all($sql));
    }

    /**
     * Для дошки на головній: лише ті, де покупець щось написав, — картка з
     * самою оцінкою на корковій дошці виглядала б порожнім стікером.
     */
    public static function withText(int $limit = 3): array
    {
        // Для дошки — розповіді, а не репліки: «Помилково зробила два
        // замовлення» чесно лишається на сторінці відгуків, але вітрину не
        // представляє. Найсвіжіші п'ятірки від 40 знаків; не набралось —
        // добираємо будь-якими з текстом.
        $rows = [];
        foreach (DB::all("SELECT * FROM reviews WHERE approved = 1 AND rating = 5 AND text IS NOT NULL
                          ORDER BY created_at DESC, id DESC") as $r) {
            $t = mb_strtolower(trim((string)$r['text']));
            // п'ятірка з застереженням («до компанії претензій немає, але…»)
            // чесно лишається на сторінці відгуків, але вітрину не представляє
            $cool = preg_match('~\b(але|проте|однак|недолік|дешев|претензі)~u', $t);
            if (mb_strlen($t) >= 40 && !$cool) $rows[] = $r;
            if (count($rows) >= $limit) break;
        }
        if (count($rows) < $limit) {
            $ids = array_column($rows, 'id') ?: [0];
            $rows = array_merge($rows, DB::all("SELECT * FROM reviews WHERE approved = 1 AND text IS NOT NULL AND text <> ''
                AND id NOT IN (" . implode(',', array_map('intval', $ids)) . ") ORDER BY created_at DESC LIMIT " . ($limit - count($rows))));
        }
        return array_map([self::class, 'hydrate'], $rows);
    }

    /** Відгуки, у яких згадано цей товар */
    public static function forProduct(int $productId): array
    {
        $out = [];
        foreach (DB::all("SELECT * FROM reviews WHERE approved = 1 AND product_ids IS NOT NULL ORDER BY created_at DESC") as $r) {
            if (in_array($productId, (array)json_decode((string)$r['product_ids'], true), true)) $out[] = self::hydrate($r);
        }
        return $out;
    }

    /** @return array{count:int,avg:float,by:array<int,int>,tags:array<string,int>} */
    public static function stats(): array
    {
        $by = array_fill_keys([5, 4, 3, 2, 1], 0);
        $tags = [];
        $sum = 0; $n = 0;
        foreach (DB::all('SELECT rating, tags FROM reviews WHERE approved = 1') as $r) {
            $rt = max(1, min(5, (int)$r['rating']));
            $by[$rt]++; $sum += $rt; $n++;
            foreach ((array)json_decode((string)$r['tags'], true) as $t) $tags[$t] = ($tags[$t] ?? 0) + 1;
        }
        arsort($tags);
        return ['count' => $n, 'avg' => $n ? round($sum / $n, 1) : 0.0, 'by' => $by, 'tags' => $tags];
    }

    public static function pendingCount(): int
    {
        return (int)DB::val('SELECT COUNT(*) FROM reviews WHERE approved = 0');
    }

    /**
     * Новий відгук із сайту — у чергу модерації.
     * @return string порожньо — прийнято; інакше текст помилки для покупця
     */
    public static function submit(array $in, ?int $userId): string
    {
        $author = trim(preg_replace('~\s+~u', ' ', (string)($in['author'] ?? '')));
        $text = trim((string)($in['text'] ?? ''));
        $rating = (int)($in['rating'] ?? 0);
        if (mb_strlen($author) < 2) return 'Вкажіть, будь ласка, як до вас звертатись.';
        if (!isset(self::LABELS[$rating])) return 'Оберіть оцінку від 1 до 5.';
        if (mb_strlen($text) < 5) return 'Напишіть хоч кілька слів — що сподобалось чи ні.';
        $tags = array_values(array_intersect(self::TAGS, (array)($in['tags'] ?? [])));
        DB::insert('reviews', [
            'author' => mb_substr($author, 0, 60), 'rating' => $rating,
            'text' => mb_substr($text, 0, 2000), 'reply' => null,
            'tags' => $tags ? json_encode($tags, JSON_UNESCAPED_UNICODE) : null,
            'product_ids' => null, 'user_id' => $userId,
            'source' => 'site', 'ext_id' => null, 'approved' => 0, 'created_at' => now(),
        ]);
        return '';
    }

    private static function hydrate(array $r): array
    {
        $r['rating'] = max(1, min(5, (int)$r['rating']));
        $r['label'] = self::LABELS[$r['rating']];
        $r['tags'] = (array)(json_decode((string)($r['tags'] ?? ''), true) ?: []);
        $r['product_ids'] = array_map('intval', (array)(json_decode((string)($r['product_ids'] ?? ''), true) ?: []));
        $r['date'] = date('d.m.Y', strtotime((string)$r['created_at']) ?: time());
        return $r;
    }
}
