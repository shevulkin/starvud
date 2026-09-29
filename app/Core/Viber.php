<?php
declare(strict_types=1);

/**
 * Viber Bot API: сповіщення й підключення до акаунта.
 *
 * ВХОДУ через Viber тут немає навмисно — див. пояснення в handleEvent().
 * Коротко: Viber не має чим довести, що надісланий номер належить
 * співрозмовнику, тож ним можна лише ПИСАТИ у вже підтверджений чат.
 */
class Viber
{
    /** Підміна токена для перевірки налаштувань — див. Telegram::useToken() */
    private static ?string $override = null;
    public static function useToken(?string $token): void { self::$override = $token; }

    public static function token(): string
    { return self::$override ?? (string)Settings::get('viber_bot_token', ''); }

    public static function configured(): bool
    { return self::token() !== ''; }

    public static function api(string $method, array $data): array
    {
        if (!self::configured()) return [];
        // (object), а не сам масив: json_encode([]) дає "[]", і Viber відповідає
        // на це «400 Bad Request». Саме через це get_account_info(<порожньо>)
        // мовчки не працював, а разом із ним зникала кнопка входу через Viber.
        $ch = curl_init('https://chatapi.viber.com/pa/' . $method);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode((object)$data, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Viber-Auth-Token: ' . self::token()],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        $json = json_decode((string)$resp, true);
        return is_array($json) ? $json : [];
    }

    public static function send(string $viberId, string $text, ?array $keyboard = null): void
    {
        $p = [
            'receiver' => $viberId, 'type' => 'text', 'text' => $text,
            'sender' => ['name' => mb_substr(cfg('app_name'), 0, 28)],
        ];
        if ($keyboard !== null) $p['keyboard'] = $keyboard;
        self::api('send_message', $p);
    }

    /**
     * URI бота для deep-link viber://pa?chatURI=...&context=token.
     *
     * Питається один раз і кешується. Невдалу спробу теж запамʼятовуємо на
     * 10 хвилин: без цього кожен рендер сторінки з вікном входу йшов у мережу
     * і чекав таймауту, тобто одна помилка в налаштуваннях гальмувала весь сайт.
     */
    public static function uri(): string
    {
        $u = (string)Settings::get('viber_bot_uri', '');
        if ($u !== '') return $u;
        if (time() - (int)Settings::get('viber_uri_tried', '0') < 600) return '';

        Settings::set('viber_uri_tried', (string)time());
        $info = self::api('get_account_info', []);
        $u = (string)($info['uri'] ?? '');
        if ($u !== '') Settings::set('viber_bot_uri', $u);
        return $u;
    }

    /** Забути кеш URI — після зміни токена бот може бути вже інший */
    public static function forgetUri(): void
    {
        Settings::set('viber_bot_uri', '');
        Settings::set('viber_uri_tried', '0');
    }

    /** Виклик після збереження токена: реєструє webhook */
    public static function setWebhook(): array
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $url = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '') . base_url('/api/viber/webhook');
        return self::api('set_webhook', ['url' => $url, 'event_types' => ['conversation_started', 'subscribed', 'message'], 'send_name' => true]);
    }

    public static function verifySignature(string $body, string $signature): bool
    {
        return hash_equals(hash_hmac('sha256', $body, self::token()), $signature);
    }

    /** Обробка події webhook: привʼязка viber_id до акаунта за context-токеном */
    public static function handleEvent(array $ev): void
    {
        $type = $ev['event'] ?? '';
        $viberId = $ev['user']['id'] ?? $ev['sender']['id'] ?? null;
        if (!$viberId) return;

        /*
         * Viber тут ЛИШЕ ПІДКЛЮЧАЄТЬСЯ до наявного акаунта — увійти ним не можна.
         *
         * Причина не в реалізації, а в самому Viber: у повідомленні з контактом
         * немає поля, яким можна довести, що номер належить співрозмовнику. У
         * Telegram таке поле є (contact.user_id звіряється з from.id), і саме
         * тому вхід через Telegram лишився.
         *
         * Раніше вхід тут був, і коштував дорого: почати вхід на сайті,
         * відкрити бота, надіслати картку жертви з адресної книги — і сайт
         * впускав у чужий акаунт, ще й привʼязував viber_id зловмисника до
         * нього назавжди разом зі сповіщеннями.
         *
         * Тому обробка контактів прибрана цілком, а не полагоджена: полагодити
         * її нічим. Лишилась одна гілка — viber_link, і вона безпечна за
         * побудовою: токен видає сторінка профілю, тобто людина вже увійшла на
         * сайт іншим способом, і Viber тут лише каже, у який чат писати.
         *
         * Код входу за номером Viber і далі доставляє (Notify, phoneStart) —
         * там ми ПИШЕМО в уже підтверджений чат, і підробити нічого не можна.
         */
        $token = trim((string)($ev['context'] ?? ($type === 'message' ? ($ev['message']['text'] ?? '') : '')));
        if ($token === '') return;

        $row = DB::row("SELECT * FROM auth_tokens WHERE token = ? AND purpose = 'viber_link' AND used = 0 AND expires_at > ?",
            [$token, now()]);
        if (!$row || !$row['user_id']) return;

        // акаунт уже є, номер у ньому теж — питати вдруге немає за що
        DB::update('users', ['viber_id' => $viberId], 'id = ?', [$row['user_id']]);
        DB::update('auth_tokens', ['used' => 1, 'chat_id' => $viberId], 'id = ?', [$row['id']]);
        \AuthLog::write((int)$row['user_id'], 'messenger_linked', 'Viber підключено до акаунта');
        self::send($viberId, BotAuth::text('bot_linked', ['messenger' => 'Viber']));
    }
}
