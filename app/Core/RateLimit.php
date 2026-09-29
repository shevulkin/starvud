<?php
declare(strict_types=1);

/**
 * Обмеження частоти звернень — проти ботів, перебору й спаму замовленнями.
 * Рахуємо у БД, а не в сесії: бот просто не надсилає куки.
 */
class RateLimit
{
    /**
     * Зафіксувати спробу. Повертає false, якщо ліміт вичерпано.
     * $ident за замовчуванням — IP; для кодів на телефон логічніше передати сам номер.
     */
    public static function hit(string $action, int $limit, int $windowSec, ?string $ident = null): bool
    {
        $ident = $ident ?? self::ip();
        $since = date('Y-m-d H:i:s', time() - $windowSec);
        try {
            $n = (int)DB::val('SELECT COUNT(*) FROM rate_hits WHERE action = ? AND ident = ? AND created_at > ?',
                [$action, $ident, $since]);
            if ($n >= $limit) return false;
            DB::insert('rate_hits', ['action' => $action, 'ident' => $ident, 'created_at' => now()]);
        } catch (Throwable $e) {
            // не блокуємо покупця, якщо таблиця ще не створена
            return true;
        }
        self::gc();
        return true;
    }

    /**
     * Скільки секунд до звільнення місця — тобто доки найстаріша спроба у вікні
     * з нього не випаде. 0 — вже можна.
     *
     * Потрібно, щоб відмова називала строк. «Спробуйте за кілька хвилин» —
     * це не вказівка, а відмашка: людина не знає, чекати їй хвилину чи годину,
     * і тому просто йде.
     */
    public static function retryAfter(string $action, int $limit, int $windowSec, ?string $ident = null): int
    {
        $ident = $ident ?? self::ip();
        try {
            $oldest = DB::val('SELECT created_at FROM rate_hits WHERE action = ? AND ident = ? AND created_at > ?
                               ORDER BY created_at ASC LIMIT 1 OFFSET ' . max(0, $limit - 1),
                [$action, $ident, date('Y-m-d H:i:s', time() - $windowSec)]);
        } catch (Throwable $e) { return 0; }
        if (!$oldest) return 0;
        $wait = $windowSec - (time() - strtotime((string)$oldest));
        return $wait > 0 ? $wait : 0;
    }

    /** Ліміт вичерпано — відмова зі строком, а не «спробуйте колись» */
    public static function reject(bool $json = false, int $retryAfter = 0): never
    {
        http_response_code(429);
        if ($retryAfter > 0) header('Retry-After: ' . $retryAfter);
        // Хвилини, а не секунди: чекати доведеться довго, і «за 47 хвилин»
        // зрозуміліше за «через 2820 с»
        $min = (int)ceil($retryAfter / 60);
        $when = $retryAfter > 0
            ? ($min <= 1 ? ' Спробуйте за хвилину.' : ' Спробуйте за ' . $min . ' хв.')
            : ' Спробуйте за кілька хвилин.';
        if ($json) json_response(['ok' => false, 'retry_after' => $retryAfter ?: null,
            'error' => 'Забагато запитів.' . $when], 429);
        header('Content-Type: text/html; charset=utf-8');
        exit('Забагато запитів.' . $when);
    }

    /** hit() + reject() одним викликом */
    public static function guard(string $action, int $limit, int $windowSec, ?string $ident = null, bool $json = false): void
    {
        if (!self::hit($action, $limit, $windowSec, $ident)) {
            self::reject($json, self::retryAfter($action, $limit, $windowSec, $ident));
        }
    }

    /**
     * IP клієнта. Заголовкам проксі віримо лише коли це ввімкнено в конфігу:
     * інакше X-Forwarded-For підробляється і обхід ліміту стає тривіальним.
     */
    public static function ip(): string
    {
        if (cfg('trust_proxy')) {
            $fwd = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
            if ($fwd !== '') {
                $first = trim(explode(',', $fwd)[0]);
                if (filter_var($first, FILTER_VALIDATE_IP)) return $first;
            }
        }
        return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    /** Прибирання старих записів — зрідка, щоб не робити зайвих запитів */
    private static function gc(): void
    {
        if (random_int(1, 200) !== 1) return;
        try { DB::delete('rate_hits', 'created_at < ?', [date('Y-m-d H:i:s', time() - 86400)]); }
        catch (Throwable $e) { /* не критично */ }
    }
}
