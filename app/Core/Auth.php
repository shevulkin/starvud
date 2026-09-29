<?php
declare(strict_types=1);

/**
 * Автентифікація та права.
 *
 * Права — обʼєднання прав усіх ролей користувача (user_roles). Понад це є
 * необовʼязковий вибір робочої ролі: людина може діяти як інша роль, щоб перевірити,
 * як виглядає система її очима. Такий режим завжди лише ЗВУЖУЄ права
 * (Roles::narrow — перетин), тож підробка сесії не здатна нічого видати,
 * максимум — урізати права самому собі.
 */
class Auth
{
    /** Ролі за id користувача — у межах одного запиту вони не змінюються самі по собі */
    private static array $rolesCache = [];

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            $sessDir = BOFU_ROOT . '/storage/sessions';
            if (!is_dir($sessDir)) @mkdir($sessDir, 0775, true);
            if (is_writable($sessDir)) session_save_path($sessDir);
            session_name(cfg('session_name', 'starvud_sid'));
            // не приймати ідентифікатор сесії, якого ми не видавали (фіксація сесії)
            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');
            /*
             * Той самий спосіб визначення, що й у заголовках, — Security::https().
             *
             * Раніше тут заголовок X-Forwarded-Proto брався на віру завжди, і
             * це розходилось із рештою сайту. Наслідок був не теоретичний: на
             * сайті без проксі будь-хто міг надіслати цей заголовок, кука
             * отримувала прапорець Secure — і браузер переставав слати її по
             * http. Тобто чужим заголовком можна було вимкнути людині кошик і
             * вхід, а виглядало б це як «сайт зламався сам по собі».
             */
            $https = Security::https();
            session_set_cookie_params([
                'httponly' => true,
                'samesite' => 'Lax',
                // на HTTPS-хостингу кука не має витікати у відкритий http
                'secure' => $https,
                'path' => base_url('/'),
            ]);
            session_start();
            self::expireStale();
        }
    }

    /**
     * Скільки сесія живе без дії і скільки взагалі.
     *
     * Дві межі, бо вони про різне. Перерва (idle) закриває забутий кабінет:
     * продавець відійшов від каси, а комп'ютер у торговому залі. Загальний
     * строк (absolute) обмежує вкрадену куку: навіть якщо нею користуються
     * щохвилини, вона не працює вічно.
     *
     * Цифри — для магазину, а не для банку: 8 годин перерви це довша за зміну
     * пауза, 30 днів загалом — місяць, після якого варто увійти наново.
     * Покупця це майже не зачіпає: кошик у нього переживає перезаход, бо
     * лежить у тій самій сесії лише доки вона жива, а замовлення знаходяться
     * за посиланням із токеном.
     */
    private const IDLE_SECONDS = 8 * 3600;
    private const ABSOLUTE_SECONDS = 30 * 86400;

    /**
     * Закрити сесію, якщо її строк вийшов.
     *
     * Перевіряємо лише для тих, хто увійшов: у гостя в сесії лежить кошик, і
     * викидати його через вісім годин немає жодних підстав — ніяких прав ця
     * сесія не дає.
     */
    private static function expireStale(): void
    {
        if (empty($_SESSION['user_id'])) return;

        $now = time();
        $started = (int)($_SESSION['auth_started'] ?? 0);
        $seen = (int)($_SESSION['auth_seen'] ?? 0);

        // Сесії, що жили до появи міток, не викидаємо заднім числом —
        // просто починаємо рахувати їх від цієї миті
        if ($started === 0) { $_SESSION['auth_started'] = $now; $_SESSION['auth_seen'] = $now; return; }

        $idle = $seen > 0 && ($now - $seen) > self::IDLE_SECONDS;
        $old  = ($now - $started) > self::ABSOLUTE_SECONDS;
        if ($idle || $old) {
            $uid = (int)$_SESSION['user_id'];
            self::logout();
            AuthLog::write($uid, 'session_expired', $idle ? 'перерва в роботі' : 'вичерпано загальний строк');
            flash('error', $idle
                ? 'Сесію закрито через перерву в роботі. Увійдіть, будь ласка, ще раз.'
                : 'Строк цього входу вичерпано. Увійдіть, будь ласка, ще раз.');
            return;
        }
        $_SESSION['auth_seen'] = $now;
    }

    public static function user(): ?array
    {
        $id = $_SESSION['user_id'] ?? null;
        if (!$id) return null;
        static $cache = null;
        if ($cache && (int)$cache['id'] === (int)$id) return $cache;
        $cache = DB::row('SELECT * FROM users WHERE id = ? AND active = 1', [$id]);
        return $cache;
    }

    public static function id(): ?int { $u = self::user(); return $u ? (int)$u['id'] : null; }
    public static function check(): bool { return self::user() !== null; }

    // ── Ролі ────────────────────────────────────────────────────────────────

    /** Ролі користувача: рядки з user_roles плюс «покупець», який є в кожного, хто увійшов */
    public static function roles(?int $userId = null): array
    {
        $uid = $userId ?? self::id();
        if (!$uid) return [];
        if (isset(self::$rolesCache[$uid])) return self::$rolesCache[$uid];
        $rows = DB::all('SELECT role FROM user_roles WHERE user_id = ?', [$uid]);
        $roles = [];
        foreach ($rows as $r) {
            $role = (string)$r['role'];
            if (Roles::exists($role) && $role !== Roles::CUSTOMER) $roles[$role] = true;
        }
        $roles[Roles::CUSTOMER] = true;
        // впорядковуємо за старшинством з реєстру, щоб інтерфейс був передбачуваним
        return self::$rolesCache[$uid] = array_values(array_filter(Roles::all(), fn($r) => isset($roles[$r])));
    }

    /** Скинути кеш ролей — після зміни ролей у межах того самого запиту */
    public static function forgetRoles(?int $userId = null): void
    {
        if ($userId === null) self::$rolesCache = [];
        else unset(self::$rolesCache[$userId]);
    }

    /** Ролі, що дають доступ до кабінету (усе, крім покупця) */
    public static function staffRoles(?int $userId = null): array
    {
        return array_values(array_diff(self::roles($userId), [Roles::CUSTOMER]));
    }

    /** Повні права користувача — без урахування режиму перегляду */
    public static function ownCaps(): array
    {
        return Roles::capsOf(self::roles());
    }

    /** Чинні права: повні, звужені роллю, яку зараз вдають */
    public static function caps(): array
    {
        $own = self::ownCaps();
        $act = self::actingAs();
        return $act === null ? $own : Roles::narrow($own, Roles::caps($act));
    }

    public static function can(string $cap): bool { return Roles::allows(self::caps(), $cap); }

    /**
     * Роль для показу в інтерфейсі. Для перевірки прав НЕ використовувати —
     * для цього є can(): роль одна, а прав може бути кілька.
     */
    public static function role(): string
    {
        $act = self::actingAs();
        if ($act !== null) return $act;
        return self::staffRoles()[0] ?? Roles::CUSTOMER;
    }

    /** Чи має людина хоч якісь права в кабінеті (з урахуванням режиму перегляду) */
    public static function isStaff(): bool { return self::caps() !== []; }

    /** Повні права. Лишається для сумісності; у нових місцях — can() з конкретним правом */
    public static function isAdmin(): bool { return in_array('*', self::caps(), true); }

    // ── Робоча роль (симуляція) ─────────────────────────────────────────────

    /** Роль, яку зараз вдають, або null — діємо на повних правах */
    public static function actingAs(): ?string
    {
        $r = $_SESSION['act_as'] ?? null;
        return (is_string($r) && Roles::exists($r)) ? $r : null;
    }

    /**
     * Робоча точка. Для продавця з кількома магазинами це спосіб звузити кабінет
     * до однієї точки; для адміна в ролі продавця — те саме, лише для перевірки.
     * null = працюємо по всіх доступних.
     */
    public static function workStoreId(): ?int
    {
        $s = $_SESSION['act_store'] ?? null;
        return $s ? (int)$s : null;
    }

    /** Сумісність зі старою назвою */
    public static function actingStoreId(): ?int { return self::workStoreId(); }

    /** Обрати робочу точку. Приймаємо лише ту, до якої доступ уже є. */
    public static function setWorkStore(?int $storeId): bool
    {
        if ($storeId === null) { unset($_SESSION['act_store']); return true; }
        if (!in_array($storeId, self::authorityStoreIds(), true)) return false;
        $_SESSION['act_store'] = $storeId;
        return true;
    }

    /**
     * Вдавати можна будь-яку роль, чиї права є підмножиною власних.
     * Звідси само собою, без жодних винятків у коді: адмін (усі права) перевіряє
     * будь-яку роль; «провалитись» у покупця може кожен зі стафу, бо порожня
     * множина вкладена в будь-яку; а продавець не вдасть редактора.
     * Власні ролі теж проходять — їхні права за побудовою входять в обʼєднання.
     */
    public static function canSimulate(string $role): bool
    {
        if (!Roles::exists($role) || !self::check()) return false;
        if (self::staffRoles() === []) return false;   // покупцеві нема що вдавати
        return Roles::covers(self::ownCaps(), $role);
    }

    /**
     * Ролі для перемикача. Пропонуємо лише ті, що справді щось міняють:
     * власна роль нічого не звужує, а роль без прав (поки такий editor) у списку
     * лише збиває з пантелику. Тому — робочі ролі плюс покупець, і тільки ті,
     * від яких набір прав дійсно зменшується.
     */
    public static function simulatableRoles(): array
    {
        if (self::staffRoles() === []) return [];
        $own = self::ownCaps();
        sort($own);
        $offer = array_merge(Roles::assignable(), [Roles::CUSTOMER]);
        return array_values(array_filter(Roles::all(), function ($r) use ($offer, $own) {
            if (!in_array($r, $offer, true) || !self::canSimulate($r)) return false;
            $narrowed = Roles::narrow($own, Roles::caps($r));
            sort($narrowed);
            return $narrowed !== $own;
        }));
    }

    public static function simulate(string $role, ?int $storeId = null): bool
    {
        if (!self::canSimulate($role)) return false;
        // магазин для режиму продавця приймаємо лише з тих, до яких доступ уже є,
        // інакше перемикач став би способом зазирнути в чужу точку
        if ($role === Roles::SELLER && $storeId !== null && !in_array($storeId, self::authorityStoreIds(), true)) {
            return false;
        }
        $_SESSION['act_as'] = $role;
        $_SESSION['act_store'] = $role === Roles::SELLER ? $storeId : null;
        return true;
    }

    public static function stopSimulating(): void
    {
        unset($_SESSION['act_as'], $_SESSION['act_store']);
    }

    // ── Магазини ────────────────────────────────────────────────────────────

    /**
     * Магазини, доступні користувачу. Призначення (seller_stores) не повʼязане з роллю:
     * це окрема привʼязка людини до точки. Роль лише вирішує, чи діє обмеження взагалі.
     */
    /**
     * Магазини, доступні за СПРАВЖНІМИ правами. Обрану роль тут не враховуємо
     * навмисно: він обирає, на яку з доступних точок дивимось, але не може ні
     * відкрити чужу, ні відібрати власну.
     */
    public static function authorityStoreIds(): array
    {
        if (!self::check()) return [];
        if (Roles::allows(self::ownCaps(), 'stores.all')) {
            return array_map(fn($r) => (int)$r['id'], DB::all('SELECT id FROM stores'));
        }
        return self::assignedStoreIds();
    }

    public static function storeIds(): array
    {
        $authority = self::authorityStoreIds();
        // робоча точка діє лише в ролі продавця: адмін керує мережею цілком
        if (self::role() !== Roles::SELLER) return $authority;
        $sid = self::workStoreId();
        if ($sid === null) return $authority;   // точку не обрано — усі свої
        // обрана точка має бути серед доступних, інакше селектор став би
        // способом зазирнути в чужу через підміну сесії
        return in_array($sid, $authority, true) ? [$sid] : [];
    }

    /** Магазини, до яких людину призначено, — незалежно від ролі й режиму перегляду */
    public static function assignedStoreIds(?int $userId = null): array
    {
        $uid = $userId ?? self::id();
        if (!$uid) return [];
        return array_map(fn($r) => (int)$r['store_id'],
            DB::all('SELECT store_id FROM seller_stores WHERE user_id = ?', [$uid]));
    }

    // ── Вхід і вихід ────────────────────────────────────────────────────────

    public static function login(int $userId): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        // Годинник сесії заводиться саме тут, а не при першому запиті: строк
        // рахується від входу, а не від того, коли людина вперше відкрила сайт
        $_SESSION['auth_started'] = time();
        $_SESSION['auth_seen'] = time();
        self::stopSimulating();
        AuthLog::write($userId, 'login');
        /*
         * Курси, оплачені до того, як зʼявився акаунт.
         *
         * Гість купує курс, а кабінет заводить згодом — тією ж поштою, і
         * замовлення переходить йому (Customers::claimOrdersByEmail). На момент
         * оплати давати доступ не було кому, тож даємо тут: вхід — єдине місце,
         * де точно відомо і хто людина, і що вона вже за собою забрала.
         */
        Courses::claimAfterLogin($userId);
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
    }

    // ── Гейти ───────────────────────────────────────────────────────────────

    public static function requireStaff(): void
    {
        if (!self::isStaff()) { flash('error', 'Потрібен вхід із правами персоналу.'); redirect('/'); }
    }

    /**
     * Гейт «потрібні повні права». Лишається для наявних контролерів; у міру того,
     * як кожен дістане своє конкретне право, ці виклики заміняються на requireCap().
     */
    public static function requireAdmin(): void
    {
        if (!self::isAdmin()) self::deny();
    }

    /**
     * Гейт на конкретне право. Ставиться і на маршрут, і всередині кожної дії,
     * що змінює дані: приховати кнопку — не перевірка.
     */
    public static function requireCap(string $cap): void
    {
        if (!self::can($cap)) self::deny($cap);
    }

    /**
     * Відмова з поясненням. Якщо права насправді є і бракує їх лише через режим
     * перегляду — кажемо саме це: інакше людина вирішить, що режим зламаний,
     * і просто перестане ним користуватись.
     */
    private static function deny(?string $cap = null): void
    {
        $act = self::actingAs();
        $hasReally = $cap === null ? in_array('*', self::ownCaps(), true) : Roles::allows(self::ownCaps(), $cap);
        if ($act !== null && $hasReally) {
            flash('error', 'У ролі «' . Roles::label($act) . '» це недоступно. '
                . 'Поверніться до своїх прав перемикачем угорі.');
        } else {
            flash('error', 'Недостатньо прав для цієї дії.');
        }
        // на ту саму сторінку не кидаємо — інакше відмова зациклиться редіректом
        $home = (self::isStaff() && request_path() !== '/admin') ? '/admin' : '/';
        redirect($home);
    }

    /** Знайти або створити користувача Google */
    public static function loginWithGoogle(array $profile): void
    {
        /*
         * Спершу за google_id, і лише потім за поштою — двома запитами, а не
         * одним `OR`.
         *
         * З `OR` база вільна повернути будь-який із двох рядків, коли існують
         * обидва: один уже привʼязаний до цього Google, інший — просто з такою
         * самою адресою. Діставшись другого, ми писали б у нього google_id,
         * який уже стоїть у першого, і вхід падав би на унікальному індексі —
         * тобто рівно тоді, коли в людини два записи, вона не увійшла б взагалі.
         */
        $user = DB::row('SELECT * FROM users WHERE google_id = ?', [$profile['sub']])
             ?: DB::row('SELECT * FROM users WHERE email = ?', [$profile['email']]);
        if ($user) {
            /*
             * Вимкнений акаунт входу не дає — як і на решті шляхів (пошта,
             * телефон, бот кажуть про це прямо). Мовчазний session_regenerate
             * тут виглядав як успішний вхід із «Вітаємо!», після якого людина
             * бачила себе гостем: Auth::user() читає лише active = 1.
             */
            if (!$user['active']) {
                flash('error', 'Цей акаунт вимкнено. Зверніться, будь ласка, до магазину.');
                redirect('/');
            }
            DB::update('users', [
                'google_id' => $profile['sub'],
                'name' => $profile['name'] ?? $user['name'],
                'avatar' => $profile['picture'] ?? $user['avatar'],
                // Google віддає лише підтверджені адреси, тож скринька доведена.
                // Номер — ні: його Google не знає, і вписаний у профілі номер
                // так і лишається неперевіреним (див. users.phone_verified_at).
                'email_verified_at' => $user['email_verified_at'] ?? null ?: now(),
            ], 'id = ?', [$user['id']]);
            $id = (int)$user['id'];
        } else {
            $id = DB::insert('users', [
                'google_id' => $profile['sub'], 'email' => $profile['email'],
                'name' => $profile['name'] ?? $profile['email'], 'avatar' => $profile['picture'] ?? null,
                'role' => Roles::CUSTOMER, 'active' => 1,
                'email_verified_at' => now(), 'created_at' => now(),
            ]);
            Newsletter::linkUser((string)$profile['email'], $id);
        }
        // Google віддає лише підтверджені адреси — тобто скринька доведена так
        // само, як кодом із листа. Отже й замовлення, зроблені гостем на цю
        // адресу, належать цій людині (Customers::claimOrdersByEmail).
        Customers::claimOrdersByEmail($id, (string)$profile['email']);
        self::login($id);
    }
}
