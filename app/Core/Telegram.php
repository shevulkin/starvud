<?php
declare(strict_types=1);

/** Telegram Bot API: надсилання, авто-визначення chat_id через getUpdates (працює без webhook) */
class Telegram
{
    /**
     * Тимчасова підміна токена — лише для перевірки налаштувань
     * (IntegrationCheck). Дає перевірити те, що адмін щойно вписав у форму,
     * НЕ зберігаючи його: інакше «перевірити» тихо ставало б «зберегти».
     */
    private static ?string $override = null;
    public static function useToken(?string $token): void { self::$override = $token; }

    public static function token(): string
    { return self::$override ?? (string)Settings::get('telegram_bot_token', ''); }

    public static function configured(): bool
    { return self::token() !== ''; }

    public static function api(string $method, array $params = []): array
    {
        if (!self::configured()) return [];
        $ch = curl_init('https://api.telegram.org/bot' . self::token() . '/' . $method);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $params,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
        ]);
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        $json = json_decode((string)$resp, true);
        // Відмову Bot API раніше ніхто не бачив: кнопка-посилання на сайт не
        // надсилалась через «Wrong HTTP URL», а зовні це виглядало як просто
        // відсутнє повідомлення. Тепер причина лишається в логах.
        if ($err !== '' || !is_array($json) || !($json['ok'] ?? false)) {
            @file_put_contents(BOFU_ROOT . '/storage/logs/telegram.log',
                date('c') . ' ' . $method . ' ' . json_encode($params, JSON_UNESCAPED_UNICODE) . ' => '
                . ($err !== '' ? 'CURL: ' . $err : substr((string)$resp, 0, 500)) . "\n", FILE_APPEND);
        }
        return is_array($json) ? $json : [];
    }

    public static function send(string $chatId, string $text, ?array $markup = null): void
    {
        $p = ['chat_id' => $chatId, 'text' => $text];
        if ($markup !== null) $p['reply_markup'] = json_encode($markup, JSON_UNESCAPED_UNICODE);
        self::api('sendMessage', $p);
    }

    /**
     * Спитати «це справді ви?» перед входом у вже привʼязаний акаунт.
     *
     * Без цього досить було підсунути людині посилання t.me/<бот>?start=<токен>,
     * створене у СВОЄМУ браузері: вона тисне START у своєму Telegram — і в чужому
     * браузері відкривається її акаунт. Один дотик, жодного пароля. Тому останнє
     * слово лишається за кнопкою в чаті, а не за натисканням /start.
     */
    private static function askConfirm(string $chatId, array $row): void
    {
        $token = (string)$row['token'];
        self::send($chatId,
            BotAuth::text('bot_confirm_login', BotAuth::loginFrom($row) + ['messenger' => 'Telegram']),
            ['inline_keyboard' => [[
                ['text' => BotAuth::text('bot_confirm_btn'), 'callback_data' => 'ok:' . $token],
                ['text' => BotAuth::text('bot_decline_btn'), 'callback_data' => 'no:' . $token],
            ]]]);
    }

    /**
     * Клавіатура з єдиною кнопкою «поділитися номером».
     *
     * is_persistent, а не one_time_keyboard. На десктопі й у вебі Telegram не
     * розгортає одноразову клавіатуру сам: вона ховається за іконкою біля поля
     * вводу, і людина бачить прохання поділитися номером «кнопкою нижче», а
     * кнопки нема — вхід глухне. Постійна клавіатура показується одразу.
     * Прибираємо її все одно ми самі, у sayDone.
     */
    private static function askPhone(string $chatId): void
    {
        self::send($chatId, BotAuth::text('bot_ask_phone'), [
            'keyboard' => [[['text' => BotAuth::text('bot_ask_phone_btn'), 'request_contact' => true]]],
            'resize_keyboard' => true, 'is_persistent' => true,
        ]);
    }

    /** Підсумкове повідомлення: прибираємо клавіатуру й даємо кнопку-посилання на сайт */
    private static function sayDone(string $chatId, string $name, string $phone): void
    {
        self::send($chatId, BotAuth::text('bot_done', ['name' => $name, 'phone' => $phone]),
            ['remove_keyboard' => true]);
        $url = BotAuth::siteUrl();
        // Кнопка-посилання окремим повідомленням: inline-клавіатура й remove_keyboard
        // в одному reply_markup не поєднуються — це різні типи розмітки.
        if ($url !== '') {
            self::send($chatId, '👇', ['inline_keyboard' => [[
                ['text' => BotAuth::text('bot_done_btn'), 'url' => $url],
            ]]]);
        }
    }

    /** Імʼя бота (кешується) — для посилань t.me/<bot>?start=... */
    public static function username(): string
    {
        $u = Settings::get('tg_bot_username', '');
        if ($u) return (string)$u;
        $me = self::api('getMe');
        $u = $me['result']['username'] ?? '';
        if ($u) Settings::set('tg_bot_username', $u);
        return (string)$u;
    }

    /**
     * Обробити нові повідомлення бота: шукаємо "/start <token>" і привʼязуємо chat_id
     * до токенів (підключення профілю або вхід). Викликається при поллінгу зі сторінок.
     */
    public static function processUpdates(): void
    {
        if (!self::configured()) return;
        $offset = (int)Settings::get('tg_updates_offset', '0');
        $resp = self::api('getUpdates',
            ['offset' => $offset + 1, 'timeout' => 0, 'allowed_updates' => '["message","callback_query"]']);
        foreach (($resp['result'] ?? []) as $upd) {
            $offset = max($offset, (int)$upd['update_id']);
            if (isset($upd['callback_query'])) { self::onCallback($upd['callback_query']); continue; }
            $msg = $upd['message'] ?? null;
            if (!$msg) continue;
            $chatId = (string)($msg['chat']['id'] ?? '');
            if (!$chatId) continue;
            $name = trim(($msg['from']['first_name'] ?? '') . ' ' . ($msg['from']['last_name'] ?? ''));

            // Контакт приходить окремим повідомленням — це відповідь на нашу кнопку
            if (isset($msg['contact'])) { self::onContact($chatId, $msg, $name); continue; }

            $text = trim($msg['text'] ?? '');
            if (!preg_match('~^/start[ =_]?(.+)$~', $text, $m)) continue;
            $token = trim($m[1]);
            $row = DB::row("SELECT * FROM auth_tokens WHERE token = ? AND used = 0 AND expires_at > ?", [$token, now()]);
            if (!$row) { self::send($chatId, BotAuth::text('bot_expired')); continue; }

            if ($row['purpose'] === 'tg_link' && $row['user_id']) {
                /*
                 * Підключення до наявного акаунта: чіпляємо чат і більше нічого.
                 *
                 * Тут стояло «номер там уже є (без нього на сайт не пускає
                 * гейт)» — це вже неправда: гейт (App::run) пропускає й тих, у
                 * кого підтверджена пошта, тож акаунт, який підключає бота,
                 * цілком може бути без номера. Нічого не ламається — номер від
                 * підключення й не залежав, — але й підтвердженим він не стає:
                 * для цього треба контакт, а його просять лише на вході.
                 *
                 * Головне, що дає це підключення: наступний вхід через бота
                 * впізнає чат і сяде в ЦЕЙ акаунт, а не заведе другий (див.
                 * BotAuth::linkedUser нижче). Саме так і не виникає дубль у
                 * того, хто зареєструвався поштою.
                 */
                DB::update('users', ['tg_chat_id' => $chatId], 'id = ?', [$row['user_id']]);
                DB::update('auth_tokens', ['used' => 1, 'chat_id' => $chatId], 'id = ?', [$row['id']]);
                self::send($chatId, BotAuth::text('bot_linked', ['messenger' => 'Telegram']));
            } elseif ($row['purpose'] === 'tg_login') {
                DB::update('auth_tokens', ['chat_id' => $chatId], 'id = ?', [$row['id']]);
                // Токен у будь-якому разі лишається невикористаним: вхід завершує
                // не /start, а відповідь у чаті. Привʼязаному чату лишається
                // підтвердити, що це він (askConfirm); решті — надіслати контакт,
                // бо лише він доводить володіння номером: вписаний руками номер
                // не доводить нічого, саме так зʼявився акаунт із чужим +340…
                $known = BotAuth::linkedUser('tg_chat_id', $chatId);
                if ($known) {
                    self::askConfirm($chatId, $row);
                } else {
                    self::askPhone($chatId);
                }
            }
        }
        Settings::set('tg_updates_offset', (string)$offset);
    }

    /**
     * Відповідь на кнопки «це я» / «це не я».
     *
     * Токен беремо з callback_data, але звіряємо його з чатом: інакше той, кому
     * дані кнопки не належать, міг би підтвердити чужий вхід, підклавши свій
     * callback_data. Разом із перевіркою привʼязки це означає, що вхід завершує
     * рівно той чат, який і почав його своїм /start.
     */
    private static function onCallback(array $cq): void
    {
        $chatId = (string)($cq['message']['chat']['id'] ?? '');
        $data = (string)($cq['data'] ?? '');
        // Telegram крутить годинник на кнопці, доки бот не відповість
        self::api('answerCallbackQuery', ['callback_query_id' => (string)($cq['id'] ?? '')]);
        if ($chatId === '' || !preg_match('~^(ok|no):(\w+)$~', $data, $m)) return;

        $row = DB::row('SELECT * FROM auth_tokens WHERE token = ? AND purpose = ? AND chat_id = ? AND used = 0 AND expires_at > ?',
            [$m[2], 'tg_login', $chatId, now()]);
        if (!$row) { self::send($chatId, BotAuth::text('bot_expired')); return; }

        if ($m[1] === 'no') {
            // Токен гасимо: сторінка, що його чекає, більше нічого не дочекається
            DB::update('auth_tokens', ['used' => 1], 'id = ?', [(int)$row['id']]);
            self::send($chatId, BotAuth::text('bot_declined'));
            return;
        }

        $known = BotAuth::linkedUser('tg_chat_id', $chatId);
        if (!$known) { self::askPhone($chatId); return; }   // привʼязку зняли, доки думали
        self::confirm((int)$row['id'], (int)$known['id'], $chatId,
            (string)$known['name'], (string)$known['phone']);
    }

    /**
     * Людина натиснула «поділитися номером».
     *
     * Ключова перевірка — contact.user_id проти from.id: у Telegram можна
     * переслати ЧУЖИЙ контакт із адресної книги, і без цієї перевірки нею
     * можна було б увійти в чужий акаунт, знаючи лише номер.
     */
    private static function onContact(string $chatId, array $msg, string $name): void
    {
        $row = BotAuth::pendingLogin('tg_login', $chatId);
        if (!$row) return;                       // контакт без початого входу — ігноруємо

        $contact = $msg['contact'] ?? [];
        $ownerId = (string)($contact['user_id'] ?? '');
        $fromId = (string)($msg['from']['id'] ?? '');
        if ($ownerId === '' || $fromId === '' || $ownerId !== $fromId) {
            self::send($chatId, BotAuth::text('bot_foreign_contact'));
            self::askPhone($chatId);
            return;
        }
        $phone = AuthTokens::normPhoneAny((string)($contact['phone_number'] ?? ''));
        if (!$phone) { self::send($chatId, BotAuth::text('bot_bad_phone')); self::askPhone($chatId); return; }

        // Номер доведений: перевірка вище звірила, що контакт належить самому
        // співрозмовнику, а не переслано з адресної книги
        $uid = BotAuth::resolveUser('tg_chat_id', $chatId, $phone, $name, true);
        if (!$uid) { self::send($chatId, BotAuth::text('bot_expired')); return; }  // акаунт вимкнено
        self::confirm((int)$row['id'], $uid, $chatId, $name, $phone);
    }

    /** Позначити токен підтвердженим — сторінка входу побачить це своїм опитуванням */
    private static function confirm(int $tokenId, int $uid, string $chatId, string $name, string $phone): void
    {
        DB::update('auth_tokens',
            ['used' => 1, 'chat_id' => $chatId, 'confirmed_user_id' => $uid, 'phone' => $phone],
            'id = ?', [$tokenId]);
        self::sayDone($chatId, $name, $phone);
    }
}
