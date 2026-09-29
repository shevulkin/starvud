<?php
declare(strict_types=1);

/**
 * Якими способами дозволено входити в цей акаунт.
 *
 * Пароля в системі немає взагалі: увійти можна через Google, через бота
 * Telegram, кодом на телефон (він приходить у підключений месенджер) або кодом
 * на пошту. Кожен спосіб — окремий вхід, і кожен рівносильний решті: хто пройшов
 * будь-яким, той усередині.
 *
 * Звідси й потреба у виборі. Людина, яка користується лише Telegram, не має
 * підстав лишати відкритим вхід поштою: скринька живе довше за інтерес до неї,
 * пароль від неї міг витекти роками раніше, і саме вона стає найслабшою ланкою
 * акаунта. Вимкнений спосіб — це не косметика в інтерфейсі, а на один шлях
 * усередину менше.
 *
 * ДВА ПРАВИЛА, ЯКІ ТУТ ГОЛОВНІ:
 *
 * 1. Спосіб, який НЕ НАЛАШТОВАНИЙ, не можна ані обрати, ані використати.
 *    Дозволити вхід через Viber тому, хто Viber не підключав, означає рядок у
 *    базі, який нічого не робить, — а виглядає як діючий дозвіл.
 *
 * 2. Заборона діє НА СЕРВЕРІ, у кожному вході окремо (див. permits() і виклики
 *    з AuthController). Сховати кнопку — не заборона: адреси входів відомі, і
 *    сторінка з кнопкою тут ні до чого.
 */
class LoginMethods
{
    /**
     * Способи входу. Ключі збігаються з маршрутами (/auth/google, /auth/tg/…,
     * /auth/phone/…, /auth/email/…) — так їх не переплутати при перевірці.
     */
    public const METHODS = [
        'google'   => 'Google',
        'telegram' => 'Telegram',
        'phone'    => 'Код на телефон',
        'email'    => 'Код на пошту',
    ];

    /** Пояснення для профілю: що саме означає кожен спосіб */
    public const ABOUT = [
        'google'   => 'Кнопка «Увійти через Google». Працює, лише якщо акаунт створений через Google або колись до нього приєднаний.',
        'telegram' => 'Бот запитує підтвердження й впускає без коду.',
        'phone'    => 'Код приходить у підключений месенджер — Telegram або Viber.',
        'email'    => 'Код приходить листом на пошту акаунта.',
    ];

    /**
     * Чи налаштований спосіб у цієї людини.
     *
     * Дві умови разом: інтеграція увімкнена на сайті І в акаунті є те, чим
     * цей спосіб користується. Перша без другої дає кнопку, яка нікуди не
     * веде; друга без першої — спосіб, який мовчки не спрацює.
     *
     * @return array{0:bool,1:string} [готовий, чому ні]
     */
    public static function readiness(array $user, string $method): array
    {
        $email = Newsletter::normEmail((string)($user['email'] ?? ''));
        return match ($method) {
            'google' => [
                !empty($user['google_id']) && GoogleAuth::configured(),
                empty($user['google_id'])
                    ? 'Акаунт не пов’язаний із Google'
                    : 'Вхід через Google не налаштований на сайті',
            ],
            'telegram' => [
                !empty($user['tg_chat_id']) && Telegram::configured(),
                empty($user['tg_chat_id'])
                    ? 'Telegram не підключений — підключіть його нижче'
                    : 'Telegram-бот не налаштований на сайті',
            ],
            /*
             * Дві умови, і вони про різне.
             *
             * КУДИ слати — потрібен підключений месенджер: у номер ми писати не
             * вміємо, а бот пише лише в чат, який людина відкрила сама.
             *
             * ЧИЙ номер — потрібен phone_verified_at. Вписаний руками номер не
             * доводить нічого (див. users.phone_verified_at і
             * Customers::ownsPhone, де це правило вже діє для замовлень). Без
             * цієї умови виходило так: підключаю свій Telegram, вписую в
             * профіль чужий номер — і власник номера, намагаючись увійти своїм
             * номером, надсилає код у МІЙ месенджер. Він не входить нікуди, але
             * до свого номера більше не має доступу, а я дізнаюсь, що він
             * пробував.
             */
            'phone' => [
                !empty($user['phone_verified_at']) && self::codeChannel($user) !== null,
                empty($user['phone_verified_at'])
                    ? 'Номер не підтверджений — підтвердьте його через Telegram, бот попросить поділитися номером'
                    : 'Немає куди надіслати код — підключіть Telegram або Viber нижче',
            ],
            'email' => [
                $email !== null && Notify::channelEnabled('email'),
                $email === null
                    ? 'В акаунті немає справжньої пошти'
                    : 'Надсилання пошти вимкнене на сайті',
            ],
            default => [false, ''],
        };
    }

    /**
     * Куди піде код на телефон: ['telegram'|'viber', назва, куди саме].
     *
     * Telegram перший не з симпатії: його доставка миттєва й не залежить від
     * того, чи відкритий застосунок, а Viber буває вимкнений на комп'ютері.
     * Обидва канали рівноцінні за довірою — обидва вже підтверджені.
     *
     * null — надіслати нікуди.
     *
     * @return array{channel:string,label:string,to:string}|null
     */
    public static function codeChannel(array $user): ?array
    {
        if (!empty($user['tg_chat_id']) && Telegram::configured()) {
            return ['channel' => 'telegram', 'label' => 'Telegram', 'to' => (string)$user['tg_chat_id']];
        }
        if (!empty($user['viber_id']) && Viber::configured()) {
            return ['channel' => 'viber', 'label' => 'Viber', 'to' => (string)$user['viber_id']];
        }
        return null;
    }

    /**
     * Дозволені способи.
     *
     * Порожньо в базі означає «усі, які налаштовані», а не «жодного»: людина,
     * яка ніколи не відкривала цей блок, має входити як раніше. Вибір
     * зʼявляється в базі лише тоді, коли його зробили свідомо.
     *
     * @return string[]
     */
    public static function allowed(int $userId): array
    {
        $rows = DB::all('SELECT method FROM user_login_methods WHERE user_id = ?', [$userId]);
        $out = [];
        foreach ($rows as $r) {
            $m = (string)$r['method'];
            if (isset(self::METHODS[$m])) $out[] = $m;
        }
        return $out;
    }

    /**
     * Чи можна увійти цим способом.
     *
     * Тут же — запобіжник від замкнених дверей. Якщо ЖОДЕН із дозволених
     * способів більше не працює (наприклад, адмін вимкнув на сайті ту єдину
     * інтеграцію, яку людина лишила), ми не перетворюємо акаунт на мертвий, а
     * повертаємось до «усі налаштовані» й пишемо про це в журнал.
     *
     * Дірки тут немає: щоб запобіжник спрацював, обраний спосіб має перестати
     * працювати САМ — власними діями цього не досягти, бо відключити
     * месенджер можна лише зсередини акаунта, тобто вже увійшовши.
     */
    public static function permits(array $user, string $method): bool
    {
        if (!isset(self::METHODS[$method])) return false;
        [$ready] = self::readiness($user, $method);
        if (!$ready) return false;

        $allowed = self::allowed((int)$user['id']);
        if ($allowed === []) return true;                       // вибору не робили
        if (in_array($method, $allowed, true)) return true;

        foreach ($allowed as $m) {
            [$r] = self::readiness($user, $m);
            if ($r) return false;                               // є робочий дозволений — цей справді заборонений
        }
        AuthLog::write((int)$user['id'], 'login_fallback',
            'жоден із дозволених способів більше не працює — вхід відкрито всіма налаштованими');
        return true;
    }

    /**
     * Стан усіх способів для сторінки профілю.
     *
     * @return array<string,array{label:string,about:string,on:bool,ready:bool,hint:string}>
     */
    public static function forUser(array $user): array
    {
        $allowed = self::allowed((int)$user['id']);
        $out = [];
        foreach (self::METHODS as $method => $label) {
            [$ready, $hint] = self::readiness($user, $method);
            $out[$method] = [
                'label' => $label,
                'about' => self::ABOUT[$method] ?? '',
                // Ненастроєний спосіб не показуємо ввімкненим, навіть якщо він
                // лишився в базі з часів, коли месенджер був підключений
                'on' => $ready && ($allowed === [] || in_array($method, $allowed, true)),
                'ready' => $ready,
                'hint' => $ready ? '' : $hint,
            ];
        }
        return $out;
    }

    /** Скільки способів зараз налаштовано — щоб не пропонувати вибір там, де вибирати нема з чого */
    public static function readyCount(array $user): int
    {
        $n = 0;
        foreach (array_keys(self::METHODS) as $m) {
            [$ready] = self::readiness($user, $m);
            if ($ready) $n++;
        }
        return $n;
    }

    /**
     * Зберегти вибір. Повертає текст помилки або '' — якщо все гаразд.
     *
     * Дві перевірки, і обидві не про зручність:
     *
     *   • приймаємо лише НАЛАШТОВАНІ способи — інакше формою можна записати
     *     собі дозвіл на те, чого в акаунті немає, і він тихо чекав би, доки
     *     месенджер підключать;
     *   • лишаємо щонайменше один робочий — інакше людина одним збереженням
     *     замикає себе назовні, і відчинити нема кому.
     */
    public static function save(array $user, array $checked): string
    {
        $uid = (int)$user['id'];
        $want = [];
        foreach (array_map('strval', $checked) as $m) {
            if (!isset(self::METHODS[$m])) continue;
            [$ready] = self::readiness($user, $m);
            if ($ready) $want[$m] = true;
        }
        $want = array_keys($want);

        if ($want === []) {
            return 'Лишіть хоча б один спосіб входу — інакше ви не зможете увійти в цей акаунт.';
        }

        $before = self::allowed($uid);
        DB::delete('user_login_methods', 'user_id = ?', [$uid]);
        foreach ($want as $m) {
            DB::insert('user_login_methods', ['user_id' => $uid, 'method' => $m, 'created_at' => now()]);
        }

        sort($before);
        $after = $want; sort($after);
        if ($before !== $after) {
            // Зміна способів входу — подія того ж рівня, що й зміна прав:
            // саме нею закривають чужий доступ, і саме її корисно бачити в журналі
            AuthLog::write($uid, 'login_methods_changed',
                'дозволено: ' . implode(', ', array_map(fn($m) => self::METHODS[$m], $after)));
        }
        return '';
    }

    /** Людська назва способу — для повідомлень про відмову */
    public static function label(string $method): string
    {
        return self::METHODS[$method] ?? $method;
    }

    /**
     * Відмова у вході цим способом — однаковим текстом скрізь.
     *
     * Кажемо прямо, який спосіб вимкнений і де його ввімкнути: людина, яка сама
     * поставила заборону півроку тому, інакше вирішить, що зламався вхід.
     */
    public static function denial(string $method): string
    {
        return 'Вхід способом «' . self::label($method) . '» вимкнений у налаштуваннях цього акаунта. '
             . 'Скористайтесь іншим дозволеним способом, а змінити вибір можна в профілі.';
    }
}
