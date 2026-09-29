<?php
declare(strict_types=1);

/**
 * Нотифікації: Telegram / Email / Web Push.
 * Події та шаблони налаштовуються в адмінці (notification_rules).
 * Глобальні перемикачі: settings notify_telegram_enabled / notify_email_enabled / notify_push_enabled / notify_all_enabled
 */
class Notify
{
    public const EVENTS = [
        'order_new'      => 'Нове замовлення',
        'order_status'   => 'Зміна статусу замовлення',
        'order_customer' => 'Покупцю: рух його замовлення',
        'order_shipment' => 'Покупцю: накладна й рух посилки',
        'user_new'       => 'Новий користувач',
        'stock_low'      => 'Закінчується товар',
        'stock_wanted'   => 'Просять повідомити про наявність',
        'stock_back'     => 'Товар знову в наявності',
        'offer_new'      => 'Торг: хід покупця',
        'offer_reply'    => 'Покупцю: відповідь на його пропозицію ціни',
        'payment_paid'   => 'Оплату карткою на сайті отримано',
        'fiscal_error'   => 'Чек не пробився в касі (ПРРО)',
        'review_new'     => 'Новий відгук чекає на модерацію',
    ];

    /**
     * Типове правило події: [кому, чи вмикати одразу]. Одне джерело і для
     * першої установки (Seeder), і для міграцій — інакше нова подія працює
     * лише на оновлених базах або лише на нових, і різницю помічають нескоро.
     *
     * 'customer' — не група, а адресат: подія стосується однієї конкретної
     * людини й іде через Notify::toCustomer()/toUser(). Такі події адмін не
     * може перевести на себе — див. isCustomerEvent().
     */
    public const DEFAULT_RULES = [
        'order_new'      => ['admins_sellers', true],
        'order_status'   => ['admins_sellers', false],
        'order_customer' => ['customer', true],
        'order_shipment' => ['customer', true],
        'user_new'       => ['admins_sellers', false],
        'stock_low'      => ['admins_sellers', false],
        'stock_wanted'   => ['sellers', true],
        'stock_back'     => ['customer', true],
        // Торг чекає відповіді живої людини, і чекає його теж жива людина.
        // Обидві події ввімкнені одразу: пропозиція, помічена через тиждень,
        // дорівнює відмові — покупець уже купив деінде.
        //
        // Адресат той самий, що в нового замовлення, і не той, що в черги
        // очікувань. «sellers» означає буквально людей із роллю «продавець», а
        // в магазині, де власник один і він адміністратор, таких немає жодного:
        // пропозиція мовчки лежала б у черзі, поки хтось не зайде в адмінку.
        // Для попиту на відсутній товар це терпимо, для угоди, яка діє дві
        // доби, — ні.
        'offer_new'      => ['admins_sellers', true],
        'offer_reply'    => ['customer', true],
        // Гроші вже прийшли, а товар ще на полиці. Про це треба знати відразу,
        // а не побачити в звіті наприкінці дня: оплачене замовлення чекає
        // відправки, і кожна година тиші тут — це година, за яку покупець уже
        // заплатив. Ввімкнена одразу з тієї ж причини, що й наступна.
        'payment_paid'   => ['admins_sellers', true],
        // Ввімкнена одразу, на відміну від решти «службових» подій: непробитий
        // чек — це продаж повз ДПС, і дізнатись про нього через тиждень із
        // журналу означає дізнатись пізно.
        'fiscal_error'   => ['admins_sellers', true],
        // Відгук, що висить непрочитаним тиждень, — це покупець, якому ніхто
        // не відповів. Ввімкнено одразу.
        'review_new'     => ['admins', true],
    ];

    /** Подія адресована покупцю, а не персоналу: одержувача міняти нема сенсу */
    public static function isCustomerEvent(string $event): bool
    {
        return (self::DEFAULT_RULES[$event][0] ?? '') === 'customer';
    }

    public const DEFAULT_TEMPLATES = [
        // Порядок рядків — це порядок питань, які ставить собі продавець: чи моє
        // це замовлення (магазин), що саме замовили, на скільки, як і куди
        // везти, кому дзвонити. Телефон окремим рядком, з його початку і перед
        // імʼям: у месенджері він стає посиланням «подзвонити», у яке треба
        // влучити пальцем, а внизу повідомлення до нього найлегше дотягнутись.
        // Приліплений комою до імені, він то ховався в кінці рядка, то
        // переносився.
        // {shortage} стоїть одразу під складом замовлення й порожній, коли все є:
        // рядок зникне сам (interpolate). Це єдиний блок, що вимагає дії, тож
        // він має трапитись на очі раніше за суму й адресу.
        'order_new'    => "🛒 Нове замовлення {number}\nМагазин: {store}\n{items}\n{shortage}\nСума: {total} грн\n"
                        . "Доставка: {delivery}\n{address}\n{phone}\nКлієнт: {name}",
        'order_status' => "📦 Замовлення {number}: статус змінено на «{status}»",
        // Покупцю. Він не знає слова «підзамовлення» й не має його вчити: коли
        // рухається лише частина, {part} називає магазин і перелічує саме її
        // товари, а коли ціле замовлення — обидва рядки порожні й зникають.
        //
        // Тон цих двох подій відрізняється від решти навмисно. Усе вище читає
        // персонал, і там цінується стислість телеграми. Ці два читає покупець,
        // і для нього це лист від магазину, у якому він щойно лишив гроші, —
        // тобто розмова, а не рядок із журналу. Звідси цілі речення, звертання
        // на «ви» й підпис: людина має бачити, що з нею говорить магазин, а не
        // спрацював тригер.
        'order_customer' => "Ваше замовлення {number} — {status}.\n{part}\n{items}\nСума: {total} грн\n\n{shop}",
        // Посилка. Номер накладної — головне: саме його переписують у трекінг і
        // називають у відділенні, тож він стоїть окремим рядком і читається як
        // документ, а не як частина фрази. {estimated}, {cod} і {part}
        // порожніють самі, коли нема чого сказати: рядок із самих підстановок
        // зникає (interpolate).
        'order_shipment' => "{status}\n\nЗамовлення: {number}\n{part}\nЕкспрес-накладна: {ttn}\n{estimated}\n{cod}\n"
                          . "\n{url}\n\n{shop}",
        'user_new'     => "👤 Новий користувач: {name} ({email})",
        'stock_low'    => "⚠️ Товар «{product}» закінчується: залишилось {qty} шт. ({store})",
        // Це не побажання клієнта, а сигнал попиту: {waiting} відповідає на
        // питання «чи варто заради цього ставати за станок»
        'stock_wanted' => "🔔 Просять повідомити про наявність: «{product}»\n"
                        . "Уже чекають: {waiting}\nЗапит із точки: {store}",
        // {url} приходить уже з підписом або порожній: на локальній машині
        // абсолютної адреси немає, і рядок має зникнути, а не світити «Замовити:»
        'stock_back'   => "✅ «{product}» знову в наявності!\n{where}\n{url}",
        // Продавцю. Умови стоять одразу під товаром, вітринна ціна — під ними:
        // без неї «10 шт × 480» нічого не каже, а рішення приймається саме з
        // різниці. Коментар покупця останнім рядком перед посиланням: у ньому
        // те, чого немає в цифрах, і саме він частіше за все й вирішує.
        'offer_new'    => "🤝 {what}\n{product}\n{terms}\nЦіна на сайті: {list}\n{buyer}\n{note}\n{link}",
        // Покупцю. Тон інший, ніж у службових подій вище: це відповідь живої
        // людини на його прохання, і читається вона як лист, а не як рядок
        // журналу. {until} порожній, коли ще не домовились, — рядок зникне.
        'offer_reply'  => "{what}\n\n{product}\n{terms}\n{note}\n{until}\n\n{url}\n\n{shop}",
        // Причина стоїть вище за посилання: продавець має одразу зрозуміти, це
        // «немає ключа в сховищі» (біжи в кабінет) чи «не зійшлась сума» (це вже
        // до розробника). {link} порожній на локальній машині — рядок зникне.
        // Сума й номер — перше, що звіряють із випискою еквайєра. Маскований
        // номер картки лишається, бо саме ним покупець доводить, що платив
        // саме він, коли дзвонить уточнити. {state} каже головне: гроші вже
        // наші чи поки лише заблоковані й потребують списання.
        'payment_paid' => "💳 Оплату отримано: {number}\nСума: {total} грн ({state})\nКартка: {card}\nНомер оплати: {ref}",
        'fiscal_error' => "🧾 Чек не пробито: {number}, {sum} грн\n{error}\n{link}",
        'review_new'   => "⭐ Новий відгук ({rating}/5) від {author}\n{text}\n{link}",
    ];

    /**
     * Тексти, які колись були типовими, а тепер замінені кращими.
     *
     * Правило зберігає свій шаблон копією в базі — щоб правка адміна пережила
     * оновлення. Наслідок: зміна тексту в коді не доходить до тих, у кого рядок
     * уже створений, тобто до всіх, крім нової установки. Раніше це лікували
     * міграцією з UPDATE, але міграція — річ разова, а тут ідеться про
     * формулювання, які ще не раз перепишуться.
     *
     * Тому просто: якщо в базі лежить дослівно старий типовий текст — його
     * ніхто не редагував, і показувати треба новий. Щойно адмін змінить бодай
     * літеру, рядок перестає збігатися й лишається його.
     */
    private const LEGACY_TEMPLATES = [
        'order_customer' => ["📦 Замовлення {number} — {status}\n{part}\n{items}\nСума: {total} грн"],
        'order_shipment' => ["🚚 Замовлення {number}\n{part}\nНакладна: {ttn}\n{status}\n{estimated}\n{cod}\n{url}"],
    ];

    /** Який текст показувати: збережений адміном, а інакше — типовий із коду */
    public static function template(string $event, ?string $stored): string
    {
        $stored = (string)$stored;
        $default = self::DEFAULT_TEMPLATES[$event] ?? '';
        if ($stored === '') return $default;
        if (in_array($stored, self::LEGACY_TEMPLATES[$event] ?? [], true)) return $default;
        return $stored;
    }

    /** Головна точка виклику: Notify::fire('order_new', ['number'=>..., ...], $storeId) */
    public static function fire(string $event, array $vars, ?int $storeId = null): void
    {
        if (!Settings::bool('notify_all_enabled', true)) return;
        $rules = DB::all('SELECT * FROM notification_rules WHERE event = ? AND enabled = 1', [$event]);
        foreach ($rules as $rule) {
            $channelOn = match ($rule['channel']) {
                'telegram' => Settings::bool('notify_telegram_enabled', true),
                'viber'    => Settings::bool('notify_viber_enabled', true),
                'email'    => Settings::bool('notify_email_enabled', true),
                'push'     => Settings::bool('notify_push_enabled', true),
                default    => false,
            };
            if (!$channelOn) continue;
            $tpl = self::template($event, $rule['template'] ?? null);
            $text = self::interpolate($tpl, $vars);
            $recipients = self::recipients($rule['recipients'], $storeId);
            foreach ($recipients as $user) {
                // особистий вибір людини може лише прибрати зайве з того, що дозволив адмін
                if (!self::wants((int)$user['id'], $event, (string)$rule['channel'])) continue;
                try { self::send($rule['channel'], $user, $text, $vars, $event); }
                catch (Throwable $e) { self::log("send fail {$rule['channel']} u{$user['id']}: " . $e->getMessage()); }
            }
        }
    }

    /**
     * Те саме, але одній конкретній людині: подія стосується її особисто
     * («ваш товар зʼявився»), тож розкладка по групах отримувачів тут ні до чого.
     * Шаблони, глобальні перемикачі й особисті галки в кабінеті — усе як завжди.
     */
    public static function toUser(int $userId, string $event, array $vars): void
    {
        if (!Settings::bool('notify_all_enabled', true)) return;
        $user = DB::row('SELECT * FROM users WHERE id = ? AND active = 1', [$userId]);
        if (!$user) return;
        foreach (DB::all('SELECT * FROM notification_rules WHERE event = ? AND enabled = 1', [$event]) as $rule) {
            $channel = (string)$rule['channel'];
            if (!self::channelEnabled($channel)) continue;
            if (!self::wants($userId, $event, $channel)) continue;
            $tpl = self::template($event, $rule['template'] ?? null);
            try { self::send($channel, $user, self::interpolate($tpl, $vars), $vars, $event); }
            catch (Throwable $e) { self::log("send fail $channel u$userId: " . $e->getMessage()); }
        }
    }

    /**
     * Покупцю про його замовлення.
     *
     * Зареєстрованому — усіма каналами, які він лишив собі в кабінеті. Гостю —
     * лише на пошту, вказану в замовленні: акаунта, а отже й вибору каналів,
     * у нього немає, і нічого, крім цієї адреси, ми про нього не знаємо.
     * Пошти теж немає — замовлення лишається тільки на телефоні, і це
     * нормальний шлях: подзвонять.
     */
    public static function toCustomer(?int $userId, ?string $email, string $event, array $vars): void
    {
        if ($userId) { self::toUser($userId, $event, $vars); return; }
        if (!$email || !Settings::bool('notify_all_enabled', true)) return;
        if (!self::channelEnabled('email')) return;
        foreach (DB::all('SELECT * FROM notification_rules WHERE event = ? AND channel = ? AND enabled = 1',
                 [$event, 'email']) as $rule) {
            $tpl = self::template($event, $rule['template'] ?? null);
            try { self::email(['email' => $email], self::interpolate($tpl, $vars), $vars, $event); }
            catch (Throwable $e) { self::log("send fail email guest ($event): " . $e->getMessage()); }
        }
    }

    // ── Особисті налаштування ───────────────────────────────────────────────

    public const CHANNELS = [
        'telegram' => 'Telegram',
        'viber'    => 'Viber',
        'email'    => 'Email',
        'push'     => 'Сповіщення в браузері',
    ];

    /** Чи глобально увімкнений канал (перемикач адміна) */
    public static function channelEnabled(string $channel): bool
    {
        return match ($channel) {
            'telegram' => Settings::bool('notify_telegram_enabled', true),
            'viber'    => Settings::bool('notify_viber_enabled', true),
            'email'    => Settings::bool('notify_email_enabled', true),
            'push'     => Settings::bool('notify_push_enabled', true),
            default    => false,
        };
    }

    /** Особисті налаштування користувача: ['подія|канал' => bool] */
    private static array $prefsCache = [];

    public static function prefs(int $userId): array
    {
        if (isset(self::$prefsCache[$userId])) return self::$prefsCache[$userId];
        $out = [];
        foreach (DB::all('SELECT event, channel, enabled FROM user_notify_prefs WHERE user_id = ?', [$userId]) as $r) {
            $out[$r['event'] . '|' . $r['channel']] = (bool)(int)$r['enabled'];
        }
        return self::$prefsCache[$userId] = $out;
    }

    public static function forgetPrefs(?int $userId = null): void
    {
        if ($userId === null) self::$prefsCache = [];
        else unset(self::$prefsCache[$userId]);
    }

    /**
     * Подія в рядку налаштувань, коли вибір стосується каналу цілком.
     * Людина обирає СПОСІБ отримання, а не перелік подій: які саме події
     * розсилати — рішення адміністратора, і дублювати його в кабінеті
     * означало б давати покупцю налаштування, яких він не просив.
     */
    public const ANY_EVENT = '*';

    /**
     * Чи хоче людина отримувати сповіщення цим каналом. Відсутність рядка =
     * хоче: інакше ввімкнення нового каналу адміном мовчки нікому б не дійшло.
     */
    public static function wantsChannel(int $userId, string $channel): bool
    {
        return self::prefs($userId)[self::ANY_EVENT . '|' . $channel] ?? true;
    }

    /**
     * Чи хоче людина цю подію цим каналом. Подія лишається в сигнатурі, бо
     * саме парами (подія, канал) розсилка й ходить, — але вирішує тепер лише
     * канал. Старі рядки з конкретними подіями не читаються: saveChannels()
     * прибирає їх при першому ж збереженні.
     */
    public static function wants(int $userId, string $event, string $channel): bool
    {
        return self::wantsChannel($userId, $channel);
    }

    /** Чи входить користувач у групу отримувачів правила */
    public static function inGroup(int $userId, string $mode): bool
    {
        // 'customer' — не група, а сам адресат: подія стосується конкретної
        // людини (їй чекався товар), і розсилати її «всім покупцям» безглуздо.
        // Тут відповідаємо true, щоб подія показалась у кабінеті кожному, хто
        // може її отримати; кому саме слати, вирішує toUser().
        if ($mode === 'customer') return true;
        $roles = Auth::roles($userId);
        $isAdmin = in_array(Roles::ADMIN, $roles, true);
        $isSeller = in_array(Roles::SELLER, $roles, true);
        return match ($mode) {
            'admins'         => $isAdmin,
            'sellers'        => $isSeller,
            'admins_sellers' => $isAdmin || $isSeller,
            default          => false,
        };
    }

    /**
     * Що саме ця людина може отримувати — для сторінки профілю.
     * Показуємо лише реально можливі пари: подія увімкнена адміном, канал
     * увімкнений глобально і людина входить у групу отримувачів. Немає сенсу
     * пропонувати вимкнути те, що й так ніколи не прийде.
     * Повертає [подія => [канал => ['on' => bool, 'ready' => bool, 'hint' => string]]]
     */
    public static function optionsFor(array $user): array
    {
        if (!Settings::bool('notify_all_enabled', true)) return [];
        $uid = (int)$user['id'];
        $out = [];
        foreach (DB::all('SELECT * FROM notification_rules WHERE enabled = 1') as $rule) {
            $event = (string)$rule['event'];
            $channel = (string)$rule['channel'];
            if (!isset(self::EVENTS[$event], self::CHANNELS[$channel])) continue;
            if (!self::channelEnabled($channel)) continue;
            if (!self::canSubscribe($uid, $channel)) continue;
            if (!self::inGroup($uid, (string)$rule['recipients'])) continue;
            [$ready, $hint] = self::readiness($user, $channel);
            $out[$event][$channel] = [
                'on' => self::wants($uid, $event, $channel),
                'ready' => $ready,
                'hint' => $hint,
            ];
        }
        return $out;
    }

    /**
     * Чи може ця людина взагалі підключити канал.
     *
     * Push — лише персоналу, і це не забаганка: підписатися можна тільки
     * кнопкою в адмінпанелі, а Api::pushSubscribe() відповідає стороннім 403.
     * Пропонувати галку тому, хто фізично не має де підписатись, гірше за
     * відсутність каналу: людина ставить її й чекає сповіщень, яких не буде.
     * Покупцю ті самі події приходять у Telegram і на пошту.
     *
     * Якщо колись зʼявиться підписка з кабінету — знімати обмеження треба
     * тут і в Api::pushSubscribe() разом, інакше знову розʼїдеться.
     */
    private static function canSubscribe(int $userId, string $channel): bool
    {
        // роль беремо ту, що людина МАЄ, а не обрану робочу — з тієї ж причини,
        // що й у inGroup(): адмін, який дивиться очима покупця, не перестає
        // отримувати сповіщення й не має втрачати свої налаштування
        return $channel !== 'push' || self::inGroup($userId, 'admins_sellers');
    }

    /** Чи налаштований канал у конкретної людини */
    private static function readiness(array $user, string $channel): array
    {
        return match ($channel) {
            'telegram' => [!empty($user['tg_chat_id']), 'Підключіть Telegram нижче'],
            'viber'    => [!empty($user['viber_id']), 'Підключіть Viber нижче'],
            'email'    => [!empty($user['email']), 'В акаунті немає пошти'],
            'push'     => [
                (bool)DB::val('SELECT 1 FROM push_subscriptions WHERE user_id = ? LIMIT 1', [(int)$user['id']]),
                'Натисніть «Увімкнути пуші» в адмінпанелі — з того браузера, де хочете їх бачити',
            ],
            default    => [false, ''],
        };
    }

    /**
     * Способи отримання, доступні цій людині, — те, що показуємо в кабінеті.
     * Канал потрапляє сюди, якщо ним справді може щось прийти: увімкнений
     * глобально і хоч одне ввімкнене правило адресоване цій людині.
     *
     * Які саме події прийдуть — не її вибір, тож перелічуємо їх лише як
     * пояснення («Нове замовлення, Товар знову в наявності»): інакше з двох
     * галок «Email» і «Telegram» не видно, про що взагалі йдеться.
     *
     * @return array<string,array{on:bool,ready:bool,hint:string,events:string[]}>
     */
    public static function channelsFor(array $user): array
    {
        $uid = (int)$user['id'];
        $out = [];
        foreach (self::optionsFor($user) as $event => $channels) {
            foreach (array_keys($channels) as $channel) {
                if (!isset($out[$channel])) {
                    [$ready, $hint] = self::readiness($user, $channel);
                    $out[$channel] = [
                        'on' => self::wantsChannel($uid, $channel),
                        'ready' => $ready, 'hint' => $hint, 'events' => [],
                    ];
                }
                $out[$channel]['events'][] = self::EVENTS[$event] ?? $event;
            }
        }
        // порядок як у CHANNELS — щоб не стрибав від того, які правила ввімкнені
        return array_replace(array_intersect_key(self::CHANNELS, $out), $out);
    }

    /**
     * Зберігає вибір способів. Приймаємо лише канали, які людині справді
     * доступні, — інакше формою можна було б записати собі згоду на те, що
     * адмін вимкнув. Рядки старого формату (вибір по кожній події) заразом
     * прибираються: інакше подія, вимкнена колись, глушила б увімкнений канал.
     */
    public static function saveChannels(array $user, array $checked): void
    {
        $uid = (int)$user['id'];
        $allowed = self::channelsFor($user);   // рахуємо ДО видалення, поки кеш ще чинний
        DB::delete('user_notify_prefs', 'user_id = ?', [$uid]);
        self::forgetPrefs($uid);
        foreach (array_keys($allowed) as $channel) {
            if (empty($checked[$channel])) {
                DB::insert('user_notify_prefs', [
                    'user_id' => $uid, 'event' => self::ANY_EVENT, 'channel' => $channel, 'enabled' => 0,
                ]);
            }
        }
    }

    public static function interpolate(string $tpl, array $vars): string
    {
        $out = [];
        foreach (explode("\n", $tpl) as $line) {
            $filled = $line;
            foreach ($vars as $k => $v) $filled = str_replace('{' . $k . '}', (string)$v, $filled);
            // Рядок, що складався з самих підстановок і спорожнів, викидаємо:
            // інакше в самовивозі замість адреси лишався б порожній рядок, а в
            // замовленні без телефону — діра посеред повідомлення. Рядок із
            // власним підписом («Доставка: ») лишається — там видно, чого бракує.
            if (trim($filled) === '' && trim($line) !== '') continue;
            $out[] = $filled;
        }
        /*
         * Порожні рядки, які лишились між зниклими підстановками, склеюємо в один.
         *
         * Шаблон розділяє блоки порожнім рядком навмисно, але коли зникає цілий
         * блок (у отриманій посилці — і дата доставки, і сума, і посилання),
         * поруч опиняються два-три таких розділювачі. У месенджері це виглядає
         * як обрив повідомлення. Один порожній рядок — пауза, три — недогляд.
         */
        return trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $out)) ?? '');
    }

    /** Одержувачі: адміни та/або продавці магазину */
    private static function recipients(string $mode, ?int $storeId): array
    {
        // Ролі беремо з user_roles — тобто ті, що людина МАЄ. Обрана робоча роль тут
        // ні до чого: адмін, який зараз дивиться очима покупця, має й далі
        // отримувати сповіщення про замовлення.
        //
        // Режиму 'customer' тут навмисно немає, і додавати його НЕ треба: такі
        // події адресні («ваш товар зʼявився»), їх шле Notify::toUser() одній
        // людині. Гілка «всі покупці» перетворила б це на масову розсилку по
        // всій базі. inGroup() відповідає для 'customer' true лише для того,
        // щоб подія зʼявилась у кабінеті — на добір отримувачів це не впливає.
        $users = [];
        if (in_array($mode, ['admins', 'admins_sellers'], true)) {
            $users = DB::all(
                "SELECT u.* FROM users u JOIN user_roles r ON r.user_id = u.id
                 WHERE r.role = ? AND u.active = 1", [Roles::ADMIN]);
        }
        if (in_array($mode, ['sellers', 'admins_sellers'], true)) {
            if ($storeId !== null) {
                $sellers = DB::all(
                    "SELECT u.* FROM users u
                     JOIN user_roles r ON r.user_id = u.id AND r.role = ?
                     JOIN seller_stores ss ON ss.user_id = u.id
                     WHERE ss.store_id = ? AND u.active = 1", [Roles::SELLER, $storeId]);
            } else {
                $sellers = DB::all(
                    "SELECT u.* FROM users u JOIN user_roles r ON r.user_id = u.id
                     WHERE r.role = ? AND u.active = 1", [Roles::SELLER]);
            }
            foreach ($sellers as $s) $users[] = $s;
        }
        // унікальні
        $seen = []; $out = [];
        foreach ($users as $u) { if (!isset($seen[$u['id']])) { $seen[$u['id']] = 1; $out[] = $u; } }
        return $out;
    }

    private static function send(string $channel, array $user, string $text, array $vars, string $event = ''): void
    {
        switch ($channel) {
            case 'telegram': self::telegram($user, $text); break;
            case 'viber':    if (!empty($user['viber_id'])) Viber::send($user['viber_id'], $text); break;
            case 'email':    self::email($user, $text, $vars, $event); break;
            case 'push':     self::push($user, $text); break;
        }
    }

    /**
     * Тема листа. Одна на подію, з підстановками з того самого набору, що й тіло.
     *
     * До цього кожен лист із сайту приходив із темою «Beekeeper of Ukraine —
     * сповіщення». У скриньці, де двадцять непрочитаних, це рівно нуль
     * інформації: людина мусить відкрити лист, щоб дізнатись, чи він про її
     * замовлення, чи про розсилку. Тема — єдине, що видно до відкриття, і
     * зайняти її словом «сповіщення» — це змарнувати єдиний рядок, який
     * гарантовано прочитають.
     */
    public const SUBJECTS = [
        'order_new'      => 'Нове замовлення {number}',
        'order_status'   => 'Замовлення {number} — {status}',
        'order_customer' => 'Ваше замовлення {number} — {status}',
        // {headline} — коротка фраза про саме цю подію з посилкою; тримати в
        // темі повний статус («прибуло у відділення Нової Пошти за адресою…»)
        // означало б обрізаний рядок у більшості поштових програм
        'order_shipment' => 'Замовлення {number} — {headline}',
        'user_new'       => 'Новий користувач: {name}',
        'stock_low'      => 'Закінчується: {product}',
        'stock_wanted'   => 'Просять повідомити про наявність: {product}',
        'stock_back'     => '«{product}» знову в наявності',
        'payment_paid'   => 'Оплата карткою: {number} на {total} грн',
        // Код у темі — не недогляд. Лист із кодом входу часто читають зі списку
        // (а через слабку доставку mail() ще й із теки «Спам»), і тема з кодом
        // рятує від відкривання листа взагалі. Це власна скринька людини:
        // хто її бачить, той і так прочитає лист.
        'auth_code'      => 'Код входу: {code}',
    ];

    public static function subject(string $event, array $vars): string
    {
        $tpl = self::SUBJECTS[$event] ?? '';
        if ($tpl === '') return cfg('app_name');
        // Незаповнена підстановка лишила б у темі «{number}» — краще вже
        // просто назва магазину, ніж службові дужки в скриньці клієнта
        $out = trim(self::interpolate($tpl, $vars));
        return ($out === '' || str_contains($out, '{')) ? cfg('app_name') : $out;
    }

    public static function telegram(array $user, string $text): void
    {
        $chat = $user['tg_chat_id'] ?? null;
        if (!$chat || !Telegram::configured()) return;
        Telegram::send((string)$chat, $text);
    }

    /**
     * Події, які має слати «скринька входу», а не загальна скринька магазину.
     *
     * Лист із кодом мусить дійти завжди — на ньому тримається вхід в акаунт.
     * Розсилки й листи про замовлення дійти можуть і не завжди: людина натисне
     * «Спам» на листі про акцію, і репутація адреси просяде. Якщо адреса одна,
     * просяде вона й для кодів — і людина просто не зможе увійти. Тому коди
     * ходять окремою адресою: скарга на одну не топить другу.
     *
     * Це не безкоштовно: власник має завести дві скриньки. Тому друга —
     * необовʼязкова: не заповнена, коди підуть загальною (див. mailFrom).
     */
    private const AUTH_EVENTS = ['auth_code'];

    /**
     * Локальні частини типових адрес — на випадок, коли в налаштуваннях порожньо.
     *
     * Свідомо НЕ noreply@. По-перше, на листи про замовлення люди відповідають
     * («а можна на завтра?»), і відповідь у noreply зникає безслідно — власник
     * навіть не дізнається, що йому писали. По-друге, частина фільтрів дивиться
     * на noreply/no-reply скоса, а нам сюди складати коди входу.
     */
    public const DEFAULT_USER = 'shop';
    public const DEFAULT_AUTH_USER = 'login';

    /**
     * Домен, з якого будуються типові адреси відправника.
     *
     * Спершу — явно вписана адреса сайту (bot_site_url): у крон-задачах
     * HTTP_HOST відсутній узагалі, і без неї нічні листи йшли б від
     * «shop@localhost». www. прибираємо: скриньки заводять на домені, а не на
     * піддомені сайту.
     */
    public static function mailHost(): string
    {
        $host = (string)parse_url((string)Settings::get('bot_site_url', ''), PHP_URL_HOST);
        if ($host === '') $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        $host = strtolower(preg_replace('~:\d+$~', '', trim($host)) ?? '');
        $host = preg_replace('~^www\.~', '', $host) ?? '';
        return $host !== '' ? $host : 'localhost';
    }

    /**
     * Адреса у полі From для цієї події.
     *
     * Перенос рядка в адресі вирізається не для краси: заголовки листа
     * розділяються саме ним, і адреса «a@b\r\nBcc: …» з адмінки перетворила б
     * кожне сповіщення на розсилку кудись іще.
     */
    public static function mailFrom(string $event = ''): string
    {
        $auth = in_array($event, self::AUTH_EVENTS, true);
        foreach ($auth ? ['mail_from_auth', 'mail_from'] : ['mail_from'] as $key) {
            $addr = self::cleanAddress((string)Settings::get($key, ''));
            if ($addr !== '') return $addr;
        }
        return ($auth ? self::DEFAULT_AUTH_USER : self::DEFAULT_USER) . '@' . self::mailHost();
    }

    /**
     * Куди піде відповідь, якщо людина натисне «Відповісти». '' — заголовка не буде.
     *
     * Сенс має рівно тоді, коли відрізняється від From: лист із кодом приходить
     * зі скриньки входу, читати яку нікому, а відповідь має потрапити туди, де
     * її побачить продавець.
     */
    public static function mailReplyTo(): string
    {
        $addr = self::cleanAddress((string)Settings::get('mail_reply_to', ''));
        return $addr !== '' ? $addr : self::cleanAddress((string)Settings::get('mail_from', ''));
    }

    /** Адреса, придатна для заголовка листа, або '' */
    public static function cleanAddress(string $raw): string
    {
        $addr = trim(str_replace(["\r", "\n", "\0"], '', $raw));
        return filter_var($addr, FILTER_VALIDATE_EMAIL) ? $addr : '';
    }

    /**
     * Кирилиця в заголовку листа інакше приїжджає крякозяброю в частині
     * поштових програм — тому base64 і позначка кодування.
     */
    private static function mimeWord(string $text): string
    {
        $text = trim(str_replace(["\r", "\n", "\0"], '', $text));
        return $text === '' ? '' : '=?UTF-8?B?' . base64_encode($text) . '?=';
    }

    /**
     * Підміна відправлення — для тестів.
     *
     * Той самий прийом, що й Telegram::useToken(): назовні нічого не міняється,
     * а набір перевірок перестає залежати від того, чи налаштована пошта на
     * машині, де його запускають. Без цього тести входу проходили б на сервері
     * й падали в розробника — або, що гірше, писали б комусь справжні листи.
     *
     * @var null|callable(string $to, string $subject, string $text):bool
     */
    private static $mailer = null;

    /** Підмінити відправника листів (null — повернути звичайний mail()) */
    public static function useMailer(?callable $fn): void { self::$mailer = $fn; }

    /**
     * @return bool чи прийняв сервер лист до відправлення. Для листів про
     *         замовлення це довідка, і викликач має право її не читати; для
     *         коду входу — ні: доки sendCode() вірив у безумовний успіх, форма
     *         казала «код надіслано» навіть тоді, коли mail() повернув false, і
     *         людина чекала листа, якого ніхто не відправляв.
     */
    public static function email(array $user, string $text, array $vars, string $event = ''): bool
    {
        if (empty($user['email'])) return false;
        $to = filter_var((string)$user['email'], FILTER_VALIDATE_EMAIL);
        if (!$to) return false;

        $from  = self::mailFrom($event);
        $reply = self::mailReplyTo();
        // Імʼя відправника поруч з адресою: у списку листів видно «Beekeeper of
        // Ukraine», а не «login@…». Це той самий рядок, за яким людина відрізняє
        // лист магазину від спаму, — і єдиний, який видно до відкриття.
        $name  = self::mimeWord(cfg('app_name'));
        $headers = "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n"
                 . 'From: ' . ($name !== '' ? $name . ' <' . $from . '>' : $from) . "\r\n";
        if ($reply !== '' && strcasecmp($reply, $from) !== 0) {
            $headers .= 'Reply-To: ' . ($name !== '' ? $name . ' <' . $reply . '>' : $reply) . "\r\n";
        }
        $subject = self::mimeWord(self::subject($event, $vars));

        /*
         * Пʼятий аргумент mail() — це відправник КОНВЕРТА (Return-Path), і він
         * інший, ніж From у заголовку. За замовчуванням туди стає користувач
         * веб-сервера (www-data@сервер), і перевірка SPF робиться саме за ним:
         * домен не збігається з нашим — лист летить у «Спам» ще до того, як
         * хтось прочитає тему.
         *
         * Частина хостингів підміняти конверт забороняє, і тоді mail() просто
         * повертає false — лист не піде взагалі. Для коду входу це означало б
         * «увійти неможливо», тож при невдачі повторюємо без конверта: гірша
         * доставка краща за її відсутність.
         */
        $envelope = preg_match('~^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+$~', $from) === 1;
        if (self::$mailer !== null) {
            $sent = (bool)(self::$mailer)($to, $subject, $text);
        } else {
            $sent = $envelope
                ? @mail($to, $subject, $text, $headers, '-f' . $from)
                : @mail($to, $subject, $text, $headers);
            if (!$sent && $envelope) $sent = @mail($to, $subject, $text, $headers);
        }

        // Мовчазна невдача тут виглядає як «код не приходить», і причини не
        // видно ніде. В лог іде подія й прикрита адреса: журнал читає людина,
        // і він потрапляє в кожен дамп.
        if (!$sent) self::log('email fail (' . ($event ?: 'без події') . ') → ' . EmailAuth::maskEmail($to));
        return $sent;
    }

    public static function push(array $user, string $text): void
    {
        // Web Push (VAPID) — активується після деплою на HTTPS. Локально пропускаємо.
        $subs = DB::all('SELECT * FROM push_subscriptions WHERE user_id = ?', [$user['id']]);
        if (!$subs) return;
        WebPush::sendToAll($subs, ['title' => cfg('app_name'), 'body' => $text]);
    }

    private static function httpPost(string $url, array $data): void
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $data,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    public static function log(string $msg): void
    {
        @file_put_contents(BOFU_ROOT . '/storage/logs/notify.log',
            '[' . now() . '] ' . $msg . "\n", FILE_APPEND);
    }
}
