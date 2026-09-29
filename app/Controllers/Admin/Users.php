<?php
declare(strict_types=1);

namespace Controllers\Admin;

use DB, View, Auth, Catalog, Roles, AuthTokens, AuthLog, Telegram, Viber, RateLimit;

class Users
{
    public static function index(): never
    {
        Auth::requireCap('users.manage');
        if (is_post()) {
            $uid = (int)($_POST['user_id'] ?? 0);
            $user = DB::row('SELECT * FROM users WHERE id = ?', [$uid]);
            if ($user) {
                $roles = array_values(array_intersect(
                    array_map('strval', (array)($_POST['roles'] ?? [])), Roles::assignable()));
                // інакше можна лишити систему без жодного адміністратора
                [$phone, $phoneErr] = self::readPhone($uid, $_POST['phone'] ?? null);
                if ((int)$user['id'] === (int)Auth::id() && !in_array(Roles::ADMIN, $roles, true)) {
                    flash('error', 'Не можна зняти адмін-права із самого себе');
                } elseif ($phoneErr !== null) {
                    // ролі й точки теж не чіпаємо: половина збереження гірша за жодну —
                    // людина бачить помилку і думає, що не збереглось нічого
                    flash('error', $phoneErr);
                } else {
                    $upd = [
                        'active' => isset($_POST['active']) ? 1 : 0,
                        'tg_chat_id' => trim($_POST['tg_chat_id'] ?? '') ?: null,
                    ];
                    // телефон пишемо, лише якщо поле було у формі
                    if (array_key_exists('phone', $_POST)) {
                        $upd['phone'] = $phone;
                        // Підтвердження належить номеру, а не акаунту: адмін
                        // вписує номер зі слів людини, і це не доказ володіння.
                        // Лишити стару позначку означало б видати чужий номер за
                        // підтверджений — і віддати цьому акаунту продажі, які
                        // продавець оформить на нього (Customers::ownsPhone).
                        if ($phone !== (string)($user['phone'] ?? '')) $upd['phone_verified_at'] = null;
                    }
                    DB::update('users', $upd, 'id = ?', [$uid]);
                    // Вимкнений акаунт не пускає в кабінет (див. Auth::user), тобто
                    // це така сама зміна доступу, як зняття ролі, — і питання
                    // «чому людина раптом не може увійти» ставлять частіше за всі інші
                    if ((int)$user['active'] !== (int)$upd['active']) {
                        AuthLog::write($uid, $upd['active'] ? 'user_enabled' : 'user_disabled', '', Auth::id());
                    }
                    $had = self::storeCount($uid);
                    self::saveRoles($uid, $roles);
                    self::saveStores($uid, (array)($_POST['stores'] ?? []), $roles);
                    Auth::forgetRoles($uid);
                    flash('success', $had > 0 && !in_array(Roles::SELLER, $roles, true)
                        ? 'Користувача оновлено. Роль продавця знято — доступ до магазинів відкликано, при поверненні ролі точки треба призначити наново.'
                        : 'Користувача оновлено');
                }
            }
            // повертаємось у той самий фільтр і пошук, а не на початок списку:
            // інакше після кожного збереження доводиться шукати людину заново
            redirect(safe_back($_POST['back'] ?? null, '/admin/users'));
        }

        $q = trim($_GET['q'] ?? '');
        $tab = in_array($_GET['tab'] ?? '', self::TABS, true) ? $_GET['tab'] : 'staff';
        $page = max(1, (int)($_GET['p'] ?? 1));

        [$where, $params] = self::filterSql($tab, $q);
        $total = (int)DB::val("SELECT COUNT(*) FROM users u WHERE $where", $params);
        $pages = max(1, (int)ceil($total / self::PER_PAGE));
        $page = min($page, $pages);
        $users = DB::all(
            "SELECT u.* FROM users u WHERE $where ORDER BY u.id LIMIT " . self::PER_PAGE .
            ' OFFSET ' . (($page - 1) * self::PER_PAGE), $params);

        $userRoles = [];
        foreach (DB::all('SELECT user_id, role FROM user_roles') as $r) {
            $userRoles[(int)$r['user_id']][] = (string)$r['role'];
        }
        $sellerStores = [];
        foreach (DB::all('SELECT * FROM seller_stores') as $r) $sellerStores[$r['user_id']][] = (int)$r['store_id'];
        View::show('admin/users', [
            'users' => $users,
            'stores' => Catalog::stores(),
            'seller_stores' => $sellerStores,
            'user_roles' => $userRoles,
            'assignable' => Roles::assignable(),
            'counts' => self::tabCounts($q),
            'tab' => $tab, 'q' => $q, 'page' => $page, 'pages' => $pages, 'total' => $total,
            'page_title' => 'Користувачі та ролі — адмінка',
        ], 'layouts/admin');
    }

    private const TABS = ['staff', 'customers', 'all'];
    private const PER_PAGE = 25;

    /**
     * Умова вибірки для вкладки й пошуку.
     *
     * «Персонал» — це наявність будь-якої ролі, а не поле в users: ролі лежать
     * окремо (user_roles), і саме вони визначають, чи людина щось адмініструє.
     * Тому вкладка рахується підзапитом, а не збереженим прапорцем, який довелось
     * би підтримувати в актуальному стані.
     */
    private static function filterSql(string $tab, string $q): array
    {
        $where = ['1=1'];
        $params = [];
        if ($tab === 'staff')     $where[] = 'u.id IN (SELECT user_id FROM user_roles)';
        if ($tab === 'customers') $where[] = 'u.id NOT IN (SELECT user_id FROM user_roles)';

        if ($q !== '') {
            $or = ['u.name LIKE ?', 'u.email LIKE ?'];
            $params[] = '%' . $q . '%';
            $params[] = '%' . $q . '%';
            // Телефон у базі лежить у E.164 (+380671234567), а шукають як звикли:
            // «067 123», «(067)», «+38 067». Тому шукаємо по цифрах, а варіант без
            // нуля додаємо окремо — інакше «0671234567» не знайде свій же номер.
            $digits = preg_replace('/\D/', '', $q);
            foreach (self::phoneNeedles((string)$digits) as $needle) {
                $or[] = 'u.phone LIKE ?';
                $params[] = '%' . $needle . '%';
            }
            $where[] = '(' . implode(' OR ', $or) . ')';
        }
        return [implode(' AND ', $where), $params];
    }

    /** Цифри запиту в тому вигляді, в якому вони можуть зустрітись у збереженому номері */
    private static function phoneNeedles(string $digits): array
    {
        if ($digits === '') return [];
        $out = [$digits];
        if (str_starts_with($digits, '0')) $out[] = substr($digits, 1);   // 067… → 67…
        if (str_starts_with($digits, '380')) $out[] = substr($digits, 3);
        return array_values(array_unique(array_filter($out, fn($s) => $s !== '')));
    }

    /** Скільки знайдеться в кожній вкладці — щоб не тицяти в порожню */
    private static function tabCounts(string $q): array
    {
        $out = [];
        foreach (self::TABS as $t) {
            [$where, $params] = self::filterSql($t, $q);
            $out[$t] = (int)DB::val("SELECT COUNT(*) FROM users u WHERE $where", $params);
        }
        return $out;
    }

    /**
     * Ролі зберігаємо різницею, а не «стерти й записати»: так ролі, яких немає
     * у формі (наприклад ще не задіяний editor), не зникнуть мовчки.
     */
    private static function saveRoles(int $uid, array $roles): void
    {
        $managed = Roles::assignable();
        $current = array_map(fn($r) => (string)$r['role'],
            DB::all('SELECT role FROM user_roles WHERE user_id = ?', [$uid]));
        $removed = array_diff(array_intersect($current, $managed), $roles);
        $added = array_diff($roles, $current);
        foreach ($removed as $r) {
            DB::delete('user_roles', 'user_id = ? AND role = ?', [$uid, $r]);
        }
        foreach ($added as $r) {
            DB::insert('user_roles', ['user_id' => $uid, 'role' => $r, 'created_at' => now()]);
        }
        /*
         * Видача прав — найважливіший рядок журналу з усіх.
         *
         * Пишемо лише зміну, а не кожне збереження форми: інакше журнал
         * заповнюється рядками «нічого не змінилось», і в ньому перестають
         * шукати. actor_id тут обов'язковий і не дорівнює user_id: суть події в
         * тому, що ОДНА людина змінила права ІНШІЙ.
         */
        if ($removed || $added) {
            $parts = [];
            if ($added) $parts[] = 'додано: ' . implode(', ', array_map([Roles::class, 'label'], $added));
            if ($removed) $parts[] = 'знято: ' . implode(', ', array_map([Roles::class, 'label'], $removed));
            AuthLog::write($uid, 'roles_changed', implode('; ', $parts), Auth::id());
        }
    }

    /**
     * Телефон із форми: [нормалізований або null, помилка або null].
     *
     * Правило те саме, що в checkout і профілі (normPhoneAny) — сюди номер вписує
     * адмін за людину, і розбіжність означала б, що адмін може завести номер,
     * який сама людина потім не збереже.
     */
    private static function readPhone(int $uid, $raw): array
    {
        if ($raw === null) return [null, null];              // поля не було у формі
        $t = trim((string)$raw);
        if ($t === '') return [null, null];                  // очистили — гейт попросить свій
        $phone = AuthTokens::normPhoneAny($t);
        if (!$phone) {
            return [null, 'Номер «' . $t . '» некоректний. Український — 067 123 45 67, іноземний — з кодом країни через +'];
        }
        // телефон = логін для входу за номером, тож він має бути в одного власника
        if (DB::row('SELECT id FROM users WHERE phone = ? AND id != ?', [$phone, $uid])) {
            return [null, 'Номер ' . $phone . ' вже привʼязаний до іншого акаунта'];
        }
        return [$phone, null];
    }

    /**
     * Написати людині від імені бота — туди, де вона вже привʼязана.
     * Каналів «про запас» тут немає: пишемо лише в підключений бот і лише тому,
     * хто його підключив, бо інакше повідомлення тихо зникне.
     */
    public static function message(): never
    {
        Auth::requireCap('users.manage');
        $uid = (int)($_POST['user_id'] ?? 0);
        $text = trim($_POST['text'] ?? '');
        $channel = (string)($_POST['channel'] ?? '');
        $user = DB::row('SELECT * FROM users WHERE id = ?', [$uid]);

        if (!$user || $text === '') {
            flash('error', 'Повідомлення порожнє — нічого не надіслано');
            redirect(safe_back($_POST['back'] ?? null, '/admin/users'));
        }
        RateLimit::guard('admin_msg', 60, 3600);
        $text = mb_substr($text, 0, 2000);

        if ($channel === 'telegram' && Telegram::configured() && !empty($user['tg_chat_id'])) {
            Telegram::send((string)$user['tg_chat_id'], $text);
            flash('success', 'Надіслано в Telegram: ' . $user['name']);
        } elseif ($channel === 'viber' && Viber::configured() && !empty($user['viber_id'])) {
            Viber::send((string)$user['viber_id'], $text);
            flash('success', 'Надіслано у Viber: ' . $user['name']);
        } else {
            flash('error', 'Цей канал недоступний: бот не налаштований або людина його не підключила');
        }
        redirect(safe_back($_POST['back'] ?? null, '/admin/users'));
    }

    /** Скільки точок закріплено за людиною зараз — щоб знати, чи було що відкликати */
    private static function storeCount(int $uid): int
    {
        return (int)DB::val('SELECT COUNT(*) FROM seller_stores WHERE user_id = ?', [$uid]);
    }

    /**
     * Призначення точок живе лише разом із роллю продавця. Знімають роль — людина
     * зникає з усіх магазинів; повернення ролі доступу не відновлює, точки треба
     * призначити наново.
     *
     * Так звільнений продавець не лишається тихо привʼязаним до точки: інакше
     * рядок у seller_stores пережив би звільнення, і роль, повернена через рік
     * зовсім для іншого магазину, мовчки відкрила б йому старі залишки й
     * замовлення. Право має закінчуватись разом із підставою, а не чекати,
     * поки хтось згадає почистити привʼязки.
     *
     * Єдиний вхід для зміни привʼязок — тому public: правило перевіряється тестом
     * (tests/roles.php), а не лише очима на формі.
     */
    public static function saveStores(int $uid, array $ids, array $roles): void
    {
        $before = array_map(fn($r) => (int)$r['store_id'],
            DB::all('SELECT store_id FROM seller_stores WHERE user_id = ?', [$uid]));
        DB::delete('seller_stores', 'user_id = ?', [$uid]);
        if (!in_array(Roles::SELLER, $roles, true)) {   // немає ролі — немає й точок
            if ($before) AuthLog::write($uid, 'stores_changed', 'доступ до точок відкликано разом із роллю продавця', Auth::id());
            return;
        }
        $valid = array_map(fn($r) => (int)$r['id'], DB::all('SELECT id FROM stores'));
        $want = array_values(array_unique(array_intersect(array_map('intval', $ids), $valid)));
        foreach ($want as $sid) {
            DB::insert('seller_stores', ['user_id' => $uid, 'store_id' => $sid]);
        }
        // Доступ до точки — це доступ до цін, залишків і замовлень цієї точки,
        // тобто така сама зміна повноважень, як і роль
        sort($before);
        $after = $want; sort($after);
        if ($before !== $after) {
            AuthLog::write($uid, 'stores_changed',
                'точки: ' . ($after ? implode(', ', $after) : 'жодної') .
                ' (було: ' . ($before ? implode(', ', $before) : 'жодної') . ')', Auth::id());
        }
    }
}
