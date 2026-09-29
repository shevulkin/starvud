<?php
declare(strict_types=1);

class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
        return $_SESSION['csrf'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(self::token()) . '">';
    }

    public static function verify(): void
    {
        $sent = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!is_string($sent) || $sent === '' || !hash_equals((string)($_SESSION['csrf'] ?? ''), $sent)) {
            // 403, а не 419: 419 — вигадка Laravel, Apache її не знає й
            // перетворює на 500, а моніторинг рахує такі відповіді аваріями
            http_response_code(403);
            $msg = 'Сторінка була відкрита занадто довго. Оновіть її та повторіть дію.';
            $ajax = str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'json')
                || isset($_SERVER['HTTP_X_CSRF_TOKEN'])
                || strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
            if ($ajax) {
                header('Content-Type: application/json; charset=utf-8');
                exit(json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE));
            }
            header('Content-Type: text/html; charset=utf-8');
            exit('<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
                . '<title>Оновіть сторінку</title><div style="font:17px/1.5 system-ui,sans-serif;max-width:460px;margin:15vh auto;padding:0 20px;text-align:center;color:#2b2b2b">'
                . '<p>' . $msg . '</p><p><a href="javascript:history.back()" style="color:#1D6B64">← Повернутись</a></p></div>');
        }
    }
}
