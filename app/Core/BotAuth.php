<?php
declare(strict_types=1);

/**
 * Спільна частина входу через месенджер: тексти, посилання на сайт і те,
 * як номер телефону перетворюється на акаунт.
 *
 * Чому взагалі просимо номер. Раніше бот створював акаунт одразу після /start,
 * і людина потрапляла на сайт без телефону — а він обовʼязковий (гейт у App::run
 * не пускає далі профілю). Виходило: увійшов, і одразу впираєшся у форму. Гірше,
 * що той самий покупець, який колись замовляв за номером, отримував ДРУГИЙ акаунт:
 * замовлення лишались у старому.
 *
 * Тепер номер — частина входу, а не наслідок. Він же й склеює акаунти:
 * знайшли за номером — заходимо в наявний, ні — створюємо новий уже з номером.
 *
 * Telegram і Viber відрізняються лише способом попросити контакт, тому все,
 * що не стосується конкретного API, живе тут.
 */
class BotAuth
{
    /** Тексти бота. Адмін править їх у Налаштуваннях; тут — розумні значення за замовчуванням. */
    public const TEXTS = [
        'bot_ask_phone' => [
            'Підтвердіть, що це ви: поділіться номером телефону кнопкою нижче. Без номера ми не зможемо повідомити про замовлення.'
            . "\nНе бачите кнопки? У веб-версії Telegram вона схована за іконкою клавіатури біля поля вводу.",
            'Прохання поділитися номером',
        ],
        'bot_ask_phone_btn' => ['📱 Поділитися номером', 'Напис на кнопці «поділитися номером»'],
        'bot_done' => [
            "✅ Готово, {name}! Ви увійшли на сайт {site_name}.\nМожна повертатися — усе вже працює.",
            'Успішний вхід (доступні {name}, {phone}, {site_name})',
        ],
        'bot_done_btn' => ['Повернутись на сайт', 'Напис на кнопці-посиланні після входу'],
        'bot_confirm_login' => [
            "Хтось починає вхід на сайт {site_name} з вашим {messenger}:\n{device}, IP {ip}\n\n"
            . 'Якщо це ви — підтвердіть. Якщо ні — натисніть «Це не я», і вхід не відбудеться.',
            'Підтвердження входу, коли месенджер уже привʼязаний (доступні {device}, {ip}, {messenger})',
        ],
        'bot_confirm_btn' => ['✅ Так, це я', 'Напис на кнопці підтвердження входу'],
        'bot_decline_btn' => ['🚫 Це не я', 'Напис на кнопці відмови від входу'],
        'bot_declined' => [
            'Вхід скасовано — ми нікого не пустили. Робити більше нічого не треба.',
            'Людина відмовилась підтвердити вхід',
        ],
        'bot_bad_phone' => [
            'Не вдалося прочитати номер. Спробуйте ще раз кнопкою нижче — надішліть саме свій контакт.',
            'Номер не розпізнано',
        ],
        'bot_foreign_contact' => [
            'Це чужий контакт. Щоб увійти, поділіться власним номером.',
            'Надіслано чужий контакт',
        ],
        'bot_expired' => [
            'Посилання застаріло. Поверніться на сайт і почніть вхід заново.',
            'Посилання застаріло',
        ],
        'bot_linked' => [
            '✅ {messenger} підключено до вашого акаунта {site_name}. Сюди приходитимуть сповіщення та коди входу.',
            'Месенджер підключено до наявного акаунта (доступний {messenger})',
        ],
    ];

    /**
     * Текст із налаштувань або типовий, з підставленими {плейсхолдерами}.
     *
     * Весь набір плейсхолдерів відомий заздалегідь і завжди підставляється —
     * навіть порожнім. Інакше адмін, який вписав {phone} у текст, де номера
     * немає, показав би покупцю дослівне «{phone}».
     */
    public static function text(string $key, array $vars = []): string
    {
        $t = trim((string)Settings::get($key, ''));
        if ($t === '') $t = self::TEXTS[$key][0] ?? '';
        $vars += [
            'name' => '', 'phone' => '', 'messenger' => '', 'device' => '', 'ip' => '',
            'site_name' => (string)cfg('app_name'), 'site' => self::siteUrl(),
        ];
        foreach ($vars as $k => $v) $t = str_replace('{' . $k . '}', (string)$v, $t);
        return $t;
    }

    /**
     * Адреса сайту для кнопки в боті.
     *
     * Обчислити з поточного запиту можна не завжди: Viber стукає у webhook сам,
     * а Telegram опитується з будь-якої сторінки. Тому адміну дано поле — воно
     * головніше. Автовизначення лишається як зручність для локальної роботи.
     */
    public static function siteUrl(): string
    {
        $set = trim((string)Settings::get('bot_site_url', ''));
        if ($set !== '') return rtrim($set, '/');
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if ($host === '' || !self::isPublicHost($host)) return '';
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return rtrim($scheme . '://' . $host . base_url(''), '/');
    }

    /**
     * Чи годиться цей хост для кнопки-посилання в месенджері.
     *
     * На локальній машині автовизначення дає https://localhost/bofu, і Telegram
     * відхиляє таку кнопку («Wrong HTTP URL») — разом із усім повідомленням.
     * Виглядало це як «бот не дав посилання на сайт», а причини не було видно
     * ніде. Локальна адреса покупцеві все одно не відкриється, тож кнопку
     * краще не слати взагалі; для тестів є поле bot_site_url у Налаштуваннях.
     */
    private static function isPublicHost(string $host): bool
    {
        $host = strtolower(preg_replace('~:\d+$~', '', $host));
        if (!str_contains($host, '.')) return false;                 // localhost, myhost
        foreach (['.local', '.localhost', '.test', '.internal'] as $suffix) {
            if (str_ends_with($host, $suffix)) return false;
        }
        if (str_starts_with($host, '127.') || $host === '::1') return false;
        return true;
    }

    /**
     * Знайти або створити акаунт за номером і привʼязкою до месенджера.
     *
     * Порядок пошуку важливий: спершу за номером, і лише потім за id месенджера.
     * Номер — це те, чим людина себе називає всюди (замовлення, вхід за кодом),
     * тож саме він склеює її записи. Якби шукали спершу за chat_id, покупець із
     * замовленнями на +380… отримав би окремий акаунт лише тому, що вперше
     * прийшов через бота.
     *
     * @param string $idField 'tg_chat_id' | 'viber_id'
     * @param bool   $phoneVerified чи довів месенджер, що номер належить саме
     *        співрозмовнику. Окремий параметр, а не «раз прийшло з бота — значить
     *        доведено»: у Telegram доказ є (contact.user_id проти from.id), у
     *        Viber його немає взагалі — саме тому вхід через Viber і прибрано
     *        (66a8175). Наступний месенджер, який тут зʼявиться, муситиме сказати
     *        це вголос, а не успадкувати чужу довіру мовчки.
     * @return int id акаунта
     */
    public static function resolveUser(string $idField, string $extId, string $phone, string $name, bool $phoneVerified = false): int
    {
        if (!in_array($idField, ['tg_chat_id', 'viber_id'], true)) {
            throw new InvalidArgumentException('Невідоме поле месенджера: ' . $idField);
        }
        $user = DB::row('SELECT * FROM users WHERE phone = ? ORDER BY id LIMIT 1', [$phone])
             ?: DB::row("SELECT * FROM users WHERE $idField = ? ORDER BY id LIMIT 1", [$extId]);

        if ($user) {
            if (!$user['active']) return 0;               // вимкнений акаунт входу не дає
            $upd = [$idField => $extId, 'phone' => $phone];
            if ($phoneVerified) $upd['phone_verified_at'] = now();
            // Імʼя з месенджера не затирає вже введене: людина могла назватись
            // у профілі як їй треба, а в Telegram у неї нік із емодзі.
            if (trim((string)$user['name']) === '' && $name !== '') $upd['name'] = $name;
            DB::update('users', $upd, 'id = ?', [(int)$user['id']]);
            self::detachFrom($idField, $extId, (int)$user['id']);
            if ($phoneVerified) Customers::claimOrders((int)$user['id'], $phone);
            return (int)$user['id'];
        }

        $suffix = $idField === 'tg_chat_id' ? '@telegram.local' : '@viber.local';
        $uid = DB::insert('users', [
            'email' => substr(md5($extId), 0, 12) . $suffix,
            'name' => $name !== '' ? $name : 'Покупець',
            'role' => 'customer', 'active' => 1,
            $idField => $extId, 'phone' => $phone,
            'phone_verified_at' => $phoneVerified ? now() : null,
            'created_at' => now(),
        ]);
        Notify::fire('user_new', ['name' => $name, 'email' => $idField === 'tg_chat_id' ? 'Telegram' : 'Viber']);
        self::detachFrom($idField, $extId, $uid);
        if ($phoneVerified) Customers::claimOrders($uid, $phone);
        return $uid;
    }

    /**
     * Один chat_id — один акаунт.
     *
     * Коли номер привів нас до іншого запису (а саме так і склеюються дублікати),
     * стара привʼязка мусить зникнути. Інакше два рядки мають однаковий chat_id,
     * і подальші пошуки «за месенджером» повертають який доведеться — вхід починає
     * то пускати в правильний акаунт, то в покинутий дубль.
     */
    private static function detachFrom(string $idField, string $extId, int $keepUserId): void
    {
        DB::query("UPDATE users SET $idField = NULL WHERE $idField = ? AND id <> ?", [$extId, $keepUserId]);
    }

    /**
     * Акаунт, до якого цей месенджер уже привʼязаний.
     *
     * Якщо привʼязка є — контакт просити нема за що: сам факт, що ми пишемо
     * саме в цей чат, доводить, що людина ним володіє. А привʼязка не береться
     * нізвідки: її ставить або підтверджений контакт (resolveUser), або
     * підключення месенджера зі сторінки профілю, куди ще треба увійти.
     *
     * Раніше тут була схожа перевірка, і її довелось прибрати (d290405): вона
     * питала не про привʼязку, а про наявність телефону в акаунті — а телефон
     * можна було вписати руками, і вхід потрапляв у дубль із одруківкою в
     * номері. Тепер ми не віримо збереженому номеру взагалі й дивимось лише на
     * привʼязку; дублів же по номеру більше не буває — телефон унікальний.
     *
     * @param string $idField 'tg_chat_id' | 'viber_id'
     */
    public static function linkedUser(string $idField, string $extId): ?array
    {
        if (!in_array($idField, ['tg_chat_id', 'viber_id'], true)) {
            throw new InvalidArgumentException('Невідоме поле месенджера: ' . $idField);
        }
        return DB::row("SELECT * FROM users WHERE $idField = ? AND active = 1 ORDER BY id LIMIT 1", [$extId]);
    }

    /**
     * Звідки почався вхід — підстановки для тексту підтвердження.
     *
     * Пусті поля бувають у токенів, створених до появи цих колонок, і в тих,
     * що народились не з браузера. Прочерк чесніший за вигадану деталь: людина
     * має бачити, що саме ми знаємо, і не більше.
     */
    public static function loginFrom(array $token): array
    {
        return [
            'device' => trim((string)($token['agent'] ?? '')) ?: 'невідомий пристрій',
            'ip' => trim((string)($token['ip'] ?? '')) ?: '—',
        ];
    }

    /** Незавершений вхід цього чату: /start уже був, номера ще немає */
    public static function pendingLogin(string $purpose, string $chatId): ?array
    {
        return DB::row(
            'SELECT * FROM auth_tokens WHERE purpose = ? AND chat_id = ? AND used = 0 AND expires_at > ?
             ORDER BY id DESC LIMIT 1', [$purpose, $chatId, now()]);
    }
}
