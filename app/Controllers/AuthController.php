<?php
declare(strict_types=1);

namespace Controllers;

use DB, Auth, GoogleAuth, Csrf, Notify, AuthTokens, AuthLog, EmailAuth, LoginMethods, Telegram, Viber;

class AuthController
{
    public static function google(): never
    {
        if (!GoogleAuth::configured()) {
            flash('error', 'Вхід через Google ще не налаштовано. Скористайтесь демо-входом або зверніться до адміністратора.');
            redirect('/');
        }
        redirect(GoogleAuth::authUrl());
    }

    public static function googleCallback(): never
    {
        $profile = GoogleAuth::configured() ? GoogleAuth::handleCallback() : null;
        if (!$profile) { flash('error', 'Не вдалося увійти через Google.'); redirect('/'); }
        $existing = DB::row('SELECT * FROM users WHERE google_id = ? OR email = ?', [$profile['sub'], $profile['email']]);
        $isNew = !$existing;
        // Заборона діє на СЕРВЕРІ, а не приховуванням кнопки: адреса /auth/google
        // відома, і відкрити її може будь-хто. Нового акаунта це не стосується —
        // забороняти вхід у те, чого ще немає, нема чого.
        if ($existing && !LoginMethods::permits($existing, 'google')) {
            AuthLog::write((int)$existing['id'], 'login_blocked', 'Google');
            flash('error', LoginMethods::denial('google'));
            redirect('/');
        }
        Auth::loginWithGoogle($profile);
        if ($isNew) Notify::fire('user_new', ['name' => $profile['name'] ?? '', 'email' => $profile['email']]);
        flash('success', 'Вітаємо, ' . ($profile['name'] ?? $profile['email']) . '!');
        redirect('/');
    }

    /** Крок 1 входу через Telegram: токен + посилання на бота */
    public static function tgStart(): never
    {
        if (!Telegram::configured()) json_response(['ok' => false, 'error' => 'Telegram-бот ще не налаштований'], 400);
        $t = AuthTokens::create('tg_login');
        $_SESSION['login_token'] = $t['token'];
        json_response(['ok' => true, 'url' => 'https://t.me/' . Telegram::username() . '?start=' . $t['token']]);
    }

    /** Крок 2: сторінка полить, чи підтверджено вхід у боті */
    public static function tgStatus(): never
    {
        $token = $_SESSION['login_token'] ?? '';
        if (!$token) json_response(['ok' => false], 400);
        Telegram::processUpdates();
        self::loginIfConfirmed($token, 'tg_login');
    }

    private static function loginIfConfirmed(string $token, string $purpose): never
    {
        $row = DB::row('SELECT * FROM auth_tokens WHERE token = ? AND purpose = ? AND expires_at > ?',
            [$token, $purpose, now()]);
        if ($row && $row['confirmed_user_id']) {
            $user = DB::row('SELECT * FROM users WHERE id = ? AND active = 1', [(int)$row['confirmed_user_id']]);
            // Бот уже підтвердив особу — але дозвіл входити САМЕ ЦИМ способом
            // перевіряємо тут, бо саме тут відбувається вхід
            if ($user && !LoginMethods::permits($user, 'telegram')) {
                AuthLog::write((int)$user['id'], 'login_blocked', 'Telegram');
                unset($_SESSION['login_token']);
                json_response(['ok' => false, 'error' => LoginMethods::denial('telegram')]);
            }
            Auth::login((int)$row['confirmed_user_id']);
            unset($_SESSION['login_token']);
            json_response(['ok' => true, 'logged_in' => true]);
        }
        json_response(['ok' => true, 'logged_in' => false]);
    }

    /**
     * Вхід поштою, крок 1: надіслати одноразовий код.
     *
     * Відповідь однакова і для наявної адреси, і для незнайомої: цей вхід
     * заразом і реєстрація, тож розрізняти нема чого — а відмінність у
     * відповідях перетворила б форму на спосіб перевіряти чужі адреси.
     */
    public static function emailStart(): never
    {
        Csrf::verify();
        $res = EmailAuth::sendCode($_POST['email'] ?? '');
        if (!$res['ok']) {
            json_response(['ok' => false, 'error' => $res['error'] ?? 'Помилка',
                // скільки чекати до наступного листа — кнопка «ще раз» покаже відлік
                'retry_after' => $res['retry_after'] ?? null]);
        }
        // Токен живе в сесії, а не в формі: інакше його видно в розмітці, і
        // код можна було б звіряти з чужим листом у тому ж браузері.
        if (!empty($res['token'])) $_SESSION['email_login'] = ['token' => $res['token'], 'tries' => 0];
        json_response(['ok' => true]);
    }

    /** Вхід поштою, крок 2: звірити код */
    public static function emailVerify(): never
    {
        Csrf::verify();
        $st = $_SESSION['email_login'] ?? null;
        if (!$st) json_response(['ok' => false, 'error' => 'Сесія входу застаріла. Попросіть, будь ласка, новий код.']);
        if ($st['tries'] >= 5) { unset($_SESSION['email_login']); json_response(['ok' => false, 'error' => 'Забагато спроб']); }
        $_SESSION['email_login']['tries']++;

        $res = EmailAuth::verify((string)$st['token'], (string)($_POST['code'] ?? ''));
        if (!$res['ok']) json_response(['ok' => false, 'error' => $res['error'] ?? 'Невірний код']);

        // Перевіряємо після коду, а не до нього: інакше форма стала б способом
        // дізнатися, які способи входу ввімкнені в чужому акаунті
        $user = DB::row('SELECT * FROM users WHERE id = ?', [(int)$res['user_id']]);
        if ($user && !LoginMethods::permits($user, 'email')) {
            AuthLog::write((int)$user['id'], 'login_blocked', 'Код на пошту');
            unset($_SESSION['email_login']);
            json_response(['ok' => false, 'error' => LoginMethods::denial('email')]);
        }

        unset($_SESSION['email_login']);
        Auth::login((int)$res['user_id']);
        json_response(['ok' => true, 'logged_in' => true]);
    }

    /** Вхід за телефоном: надіслати код у месенджер */
    public static function phoneStart(): never
    {
        Csrf::verify();
        // normPhoneAny, а не normPhone: у базі номер уже лежить у E.164, і код
        // летить у Telegram чи Viber — країна тут ні на що не впливає. З вузьким
        // правилом закордонний покупець, який замовляв із номером +49, не міг
        // увійти власним номером: сайт його приймав, а вхід — ні.
        $phone = AuthTokens::normPhoneAny($_POST['phone'] ?? '');
        if (!$phone) json_response(['ok' => false, 'error' => 'Некоректний номер']);
        /*
         * Тільки підтверджений номер, і саме в пошуку, а не перевіркою після.
         *
         * Номер у профілі можна вписати будь-який — свій, чужий, з оголошення.
         * Підтверджує його лише Telegram, який засвідчує contact.user_id проти
         * from.id (див. users.phone_verified_at). Доки пошук цього не питав,
         * акаунт із вписаним чужим номером перехоплював вхід його справжнього
         * власника: код летів у месенджер того, хто номер вписав.
         *
         * Непідтверджений номер провалюється в «акаунта ще немає» нижче — і це
         * не відмовка, а рівно та відповідь, яка потрібна: там написано увійти
         * через Telegram, а це і є спосіб довести номер. Заразом такий вхід
         * нічого не розповідає про чужий акаунт.
         */
        $user = DB::row('SELECT * FROM users WHERE phone = ? AND active = 1
                         AND phone_verified_at IS NOT NULL', [$phone]);
        /*
         * Код можна надіслати лише в чат, який уже підтверджений.
         *
         * Це не наша вигадка й не те, що можна «докрутити»: ані Telegram, ані
         * Viber не дають боту написати за номером телефону. Бот відповідає лише
         * тому, хто перший написав йому сам. Тому «зареєструватися за номером,
         * підтвердивши в месенджері» працює тільки в один бік — від месенджера
         * до сайту, і саме так зроблено вхід через Telegram: людина натискає в
         * боті «поділитися номером», а Telegram сам засвідчує, що номер її
         * (contact.user_id проти from.id).
         *
         * Тому тут не «помилка», а вказівка на робочий шлях. Telegram названий
         * першим навмисно: він і створює акаунт, і робить це з підтвердженим
         * номером — тобто рівно те, чого людина хотіла, натиснувши «увійти за
         * номером».
         */
        if (!$user) {
            // kind=info, бо це не помилка людини: акаунта на цей номер справді
            // ще немає, і нижче написано, як його завести. Червона рамка тут
            // казала б «ви зробили не так», хоча зробити інакше було й ніяк.
            json_response(['ok' => false, 'kind' => 'info', 'error' =>
                'На цей номер ще немає акаунта. Створіть його через Telegram — бот попросить '
                . 'поділитися номером, і акаунт зʼявиться вже з підтвердженим номером. '
                . 'Або увійдіть за поштою: код прийде листом.']);
        }
        /*
         * Куди слати код — вирішує те, що людина сама підключила.
         *
         * Месенджер стає «підключеним» не вписуванням номера, а тим, що людина
         * відкрила бота зі свого профілю й підтвердила: у базі зʼявляється
         * chat_id саме того чату. Тому надіслати код туди — безпечно: цей чат
         * уже доведено її власним входом.
         *
         * Порядок і сам вибір лежать у LoginMethods::codeChannel(), поруч із
         * перевіркою готовності способів, — інакше «куди можна надіслати» і
         * «куди справді шлемо» розійшлися б на першій же правці.
         */
        $ch = LoginMethods::codeChannel($user);
        if ($ch === null) {
            // Теж вказівка, а не помилка: спосіб не працює, але поруч названо
            // той, що спрацює, і що зробити, аби запрацював і цей
            json_response(['ok' => false, 'kind' => 'info', 'error' =>
                'До цього акаунта не підключений жоден месенджер, тож надіслати код нікуди. '
                . 'Увійдіть через Google або поштою, а тоді підключіть Telegram чи Viber у профілі.']);
        }
        // Спосіб міг бути вимкнений самою людиною в профілі. Кажемо про це
        // прямо: інакше вона вирішить, що зламався вхід, а не що вона його
        // колись сама й закрила.
        if (!LoginMethods::permits($user, 'phone')) {
            AuthLog::write((int)$user['id'], 'login_blocked', 'Код на телефон');
            json_response(['ok' => false, 'error' => LoginMethods::denial('phone')]);
        }

        $c = AuthTokens::createPhoneCode($phone);
        if ($c === false) {
            // Дві причини відмови, і людині вони не однакові: одна минає за
            // хвилину, друга — за годину. Мовчазне «забагато спроб» на обидві
            // означало б, що той, кому лишилось чекати 40 секунд, іде геть.
            $wait = AuthTokens::resendWait('phone_code', 'phone', $phone);
            json_response($wait > 0
                ? ['ok' => false, 'retry_after' => $wait,
                   'error' => 'Код уже надіслано. Попросити новий можна через ' . $wait . ' с.']
                : ['ok' => false, 'error' => 'Забагато спроб. Спробуйте за годину.']);
        }
        $text = 'Код входу на сайт ' . cfg('app_name') . ': ' . $c['code'] . ' (діє 5 хвилин)';

        if ($ch['channel'] === 'telegram') Telegram::send($ch['to'], $text);
        else Viber::send($ch['to'], $text);

        $_SESSION['phone_login'] = ['token' => $c['token'], 'phone' => $phone, 'tries' => 0];
        // via — назва каналу для повідомлення «код надіслано у …»; where — те
        // саме людськими словами, разом із замаскованим номером, щоб людина
        // бачила, у який саме акаунт месенджера дивитись
        json_response([
            'ok' => true,
            'via' => $ch['label'],
            'where' => 'Код надіслано у ' . $ch['label'] . ' на номер ' . self::maskPhone($phone) . '.',
        ]);
    }

    public static function phoneVerify(): never
    {
        Csrf::verify();
        $st = $_SESSION['phone_login'] ?? null;
        $code = trim($_POST['code'] ?? '');
        if (!$st || !$code) json_response(['ok' => false, 'error' => 'Сесія входу застаріла']);
        if ($st['tries'] >= 5) { unset($_SESSION['phone_login']); json_response(['ok' => false, 'error' => 'Забагато спроб']); }
        $_SESSION['phone_login']['tries']++;
        $row = DB::row("SELECT * FROM auth_tokens WHERE token = ? AND purpose = 'phone_code' AND used = 0 AND expires_at > ?", [$st['token'], now()]);
        if (!$row || !hash_equals($row['code'], $code)) {
            // Номер у журналі маскуємо: серія невдалих спроб має бути видною,
            // а сам журнал не має перетворюватись на список телефонів покупців
            AuthLog::write(null, 'login_failed', 'невірний код для номера ' . self::maskPhone((string)$st['phone']));
            json_response(['ok' => false, 'error' => 'Невірний код']);
        }
        /*
         * Та сама умова, що й у phoneStart, і повторена вона не про всяк випадок.
         *
         * Між надсиланням коду й його введенням минає час, і за цей час
         * підтвердження могло злетіти — його скидає будь-яка зміна номера
         * (Profile, Admin\Users). Без умови тут код, виписаний ще підтвердженому
         * акаунту, впускав би в нього вже після того, як номер став чужим.
         */
        $user = DB::row('SELECT * FROM users WHERE phone = ? AND active = 1
                         AND phone_verified_at IS NOT NULL', [$row['phone']]);
        if (!$user) json_response(['ok' => false, 'error' => 'Акаунт не знайдено']);
        DB::update('auth_tokens', ['used' => 1], 'id = ?', [$row['id']]);
        unset($_SESSION['phone_login']);
        Auth::login((int)$user['id']);
        json_response(['ok' => true, 'logged_in' => true]);
    }

    /** +380671234567 → +38067…4567: досить, щоб упізнати свій номер, і замало, щоб зібрати чужі */
    private static function maskPhone(string $phone): string
    {
        $len = strlen($phone);
        return $len <= 8 ? '…' : substr($phone, 0, 6) . '…' . substr($phone, -4);
    }

    public static function logout(): never
    {
        Csrf::verify();
        // Пишемо ДО logout(): після нього сесії вже немає, і хто саме вийшов —
        // теж невідомо
        AuthLog::write(Auth::id(), 'logout');
        Auth::logout();
        redirect('/');
    }
}
