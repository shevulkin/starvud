<?php
declare(strict_types=1);

/**
 * Готовність до бойового сервера.
 *
 * Питання, на яке відповідає цей клас: «що на цьому сайті зараз не так, щоб
 * пускати на нього покупців». Тільки читає — нічого не вимикає й не видаляє
 * сам. Виправлення завжди рішення людини: те, що на локальній машині помилка,
 * на тестовому стенді може бути навмисним.
 *
 * Запуск: php bin/cli.php prod-check
 */
class ProdCheck
{
    public const OK = 'ok';        // усе гаразд
    public const WARN = 'warn';    // працювати можна, але варто глянути
    public const BAD = 'bad';      // на бойовий сервер у такому вигляді не можна

    /** @return array<int,array{level:string,title:string,note:string}> */
    public static function run(): array
    {
        return array_merge(
            self::config(),
            self::demoData(),
            self::integrations(),
            self::files(),
        );
    }

    private static function row(string $level, string $title, string $note): array
    {
        return ['level' => $level, 'title' => $title, 'note' => $note];
    }

    private static function config(): array
    {
        $out = [];

        /*
         * Демо-входу в системі більше немає — його прибрано разом із маршрутом.
         * Рядок лишається, бо перевірка має відповідати не «чи вимкнено», а «чи
         * не повернулось»: у config.local.php на старих серверах цей прапорець
         * ще лежить, і людина, побачивши його, вирішить, що вхід досі працює.
         */
        $out[] = cfg('demo_login') !== null
            ? self::row(self::WARN, 'Демо-вхід',
                'у config.local.php лишився ключ demo_login — самої функції вже немає, рядок можна видалити')
            : self::row(self::OK, 'Демо-вхід', 'функції немає в коді');

        // debug показує відвідувачу шлях до файлів і текст помилок БД
        $out[] = cfg('debug')
            ? self::row(self::BAD, 'Показ помилок', 'УВІМКНЕНИЙ — тексти помилок і шляхи до файлів видно відвідувачу.')
            : self::row(self::OK, 'Показ помилок', 'вимкнений');

        $out[] = cfg('env') === 'production'
            ? self::row(self::OK, 'Режим', 'production')
            : self::row(self::WARN, 'Режим', 'env = ' . var_export(cfg('env'), true) . ', очікується production');

        // config.local.php — єдине місце для паролів бази. Без нього сайт
        // працює на типових доступах із config.php, тобто, найімовірніше, ні.
        $out[] = is_file(BOFU_ROOT . '/config.local.php')
            ? self::row(self::OK, 'config.local.php', 'є — доступи до бази задані окремо від репозиторію')
            : self::row(self::WARN, 'config.local.php', 'немає — на хостингу він потрібен для доступів до бази');

        $out[] = cfg('hsts')
            ? self::row(self::OK, 'HSTS', 'увімкнений')
            : self::row(self::WARN, 'HSTS', 'вимкнений — вмикайте, коли HTTPS працює на всьому домені (див. README)');

        return $out;
    }

    /**
     * Демо-дані сідера. Вони потрібні, щоб порожній сайт було видно, — і
     * шкідливі рівно з моменту, коли на сайт пускають людей: демо-адмін це
     * чужий обліковий запис із повними правами.
     */
    private static function demoData(): array
    {
        $out = [];

        $demoUsers = DB::all("SELECT id, name, email FROM users WHERE email LIKE '%@bofu.local'");
        if ($demoUsers) {
            $names = implode(', ', array_column($demoUsers, 'email'));
            $out[] = self::row(self::BAD, 'Демо-користувачі',
                count($demoUsers) . ' шт. (' . $names . ') — видаліть в адмінці → Користувачі');
        } else {
            $out[] = self::row(self::OK, 'Демо-користувачі', 'немає');
        }

        // Адміністратор має бути хоч один справжній — інакше після видалення
        // демо-облікових записів у сайт не зайде ніхто
        $realAdmins = (int)DB::val(
            "SELECT COUNT(DISTINCT u.id) FROM users u
             JOIN user_roles r ON r.user_id = u.id AND r.role = 'admin'
             WHERE u.active = 1 AND u.email NOT LIKE '%@bofu.local'"
        );
        $out[] = $realAdmins > 0
            ? self::row(self::OK, 'Справжні адміністратори', $realAdmins . ' — є кому керувати сайтом')
            : self::row(self::BAD, 'Справжні адміністратори',
                'жодного! Спершу увійдіть своїм акаунтом і дайте собі роль, і лише потім видаляйте демо-користувачів');

        // Демо-крамниці впізнаються за адресою з сідера, а не за назвою:
        // назву могли вже поправити, адресу — навряд
        $demoStores = DB::all("SELECT name, city, address FROM stores WHERE address LIKE '%Прикладна%'");
        $out[] = $demoStores
            ? self::row(self::BAD, 'Демо-магазини', count($demoStores) . ' шт. з адресою «вул. Прикладна» — покупець побачить їх у самовивозі')
            : self::row(self::OK, 'Магазини', 'демонстраційних не знайдено');

        $stores = Catalog::stores();
        $withGeo = array_filter($stores, fn($s) => Geo::has($s));
        if ($stores) {
            $out[] = count($withGeo) === count($stores)
                ? self::row(self::OK, 'Координати магазинів', 'задані в усіх точках')
                : self::row(self::WARN, 'Координати магазинів',
                    count($withGeo) . ' з ' . count($stores) . ' — решта не матиме мітки на карті');
        }

        $orders = (int)DB::val('SELECT COUNT(*) FROM orders');
        $out[] = $orders > 0
            ? self::row(self::WARN, 'Замовлення в базі', $orders . ' — якщо це тестові, приберіть до запуску: вони підуть у звіти')
            : self::row(self::OK, 'Замовлення в базі', 'немає');

        return $out;
    }

    private static function integrations(): array
    {
        $out = [];
        /*
         * Оплата карткою. Помилка тут дзеркальна до касової й така сама
         * дорога: забутий ТЕСТОВИЙ шлюз на бойовому сайті. Оплати «проходять»,
         * замовлення позначаються оплаченими, покупці отримують товар — а
         * грошей немає взагалі, бо тестовий шлюз їх не рухає.
         *
         * Друга перевірка — про HTTPS і доступність ззовні: без публічної
         * адреси шлюз не достукається в NOTIFY_URL, і кожна оплата
         * зараховуватиметься лише звіркою по cron, тобто із запізненням.
         */
        if (!Settings::bool('acq_enabled')) {
            $out[] = self::row(self::WARN, 'Оплата карткою',
                'вимкнена — покупці платять при отриманні (якщо еквайринг не потрібен, це нормально)');
            // Вимкнення саме по собі нічого не ламає, але замовлення, які вже
            // чекали оплати посиланням, лишаються з обіцянкою, яку сайт більше
            // не виконає. Їх треба не «полагодити», а обдзвонити.
            $waiting = Acquiring::pending();
            if ($waiting) {
                $out[] = self::row(self::WARN, 'Замовлення без оплати',
                    count($waiting) . ' шт. чекали оплати карткою, а вона вимкнена — '
                    . 'узгодьте оплату з покупцями (напр. ' . $waiting[0]['number'] . ')');
            }
        } else {
            $gaps = Acquiring::missing();
            if ($gaps) {
                $out[] = self::row(self::BAD, 'Оплата карткою',
                    'увімкнена, але не працює: ' . implode('; ', $gaps));
            } elseif (Acquiring::env() === 'test') {
                $out[] = self::row(self::BAD, 'Оплата карткою',
                    'увімкнено ТЕСТОВИЙ шлюз — оплати проходитимуть, але грошей не буде. '
                    . 'Перед запуском перемкніть середовище на робоче й замініть Merchant ID, '
                    . 'Terminal ID, ключ і сертифікат на робочі');
            } else {
                $out[] = self::row(self::OK, 'Оплата карткою',
                    'робочий шлюз, Merchant ' . Acquiring::merchantId()
                    . ' — переконайтесь, що банку передані адреси ' . abs_url('/pay/notify')
                    . ' (NOTIFY_URL) і ' . abs_url('/pay/return') . ' (SUCCESS_URL/FAILURE_URL)');
            }
            // Приватний ключ у базі — не помилка, але дамп бази з ним у пошті
            // чи на ноутбуці дорівнює втраті ключа, яким підписуються списання
            if (trim((string)Settings::get('acq_key', '')) !== '') {
                $out[] = self::row(self::WARN, 'Ключ еквайрингу',
                    'приватний ключ зберігається в базі — надійніше покласти його файлом у '
                    . Acquiring::keyDir() . ', тоді він не потрапляє в дампи');
            }
        }

        /*
         * Каса. Найдорожча помилка тут — забутий токен ТЕСТОВОЇ каси: чеки
         * пробиваються, номери є, у картках усе зелене, а в ДПС не потрапляє
         * нічого. Виявляється це не одразу, тож питаємо саму касу, чи вона
         * фіскальна, — інакше перевірка звелася б до «токен непорожній».
         */
        if (FiscalProvider::current() === 'dps') {
            /*
             * ПРРО ДПС: ключ у касира, тож саму касу звідси не спитаєш — запит
             * стану підписується. Перевіряємо те, що в нашій владі: бібліотеку
             * підпису, звʼязок із фіскальним сервером, реквізити ПРРО точок і
             * тестовий режим (забутий — це чеки без юридичної сили).
             */
            $lib = BOFU_ROOT . '/assets/vendor/eusign/';
            $missingLib = array_filter(['euscp.js', 'euscp.worker.js', 'CAs.json', 'CACertificates.p7b'],
                fn($f) => !is_file($lib . $f) || filesize($lib . $f) < 1000);
            $out[] = $missingLib
                ? self::row(self::BAD, 'Бібліотека підпису КЕП', 'бракує: ' . implode(', ', $missingLib) . ' — виконайте php bin/cli.php dps:lib')
                : self::row(self::OK, 'Бібліотека підпису КЕП', 'на місці');
            $ping = Dps::ping();
            $out[] = $ping['ok']
                ? self::row(self::OK, 'Фіскальний сервер ДПС', 'відповідає (' . Dps::base() . ')')
                : self::row(self::BAD, 'Фіскальний сервер ДПС', 'не відповідає: ' . ($ping['error'] ?: '?')
                    . ' — якщо хостинг закриває порт 8643, у Налаштуваннях вкажіть http://fs.tax.gov.ua:8609/fs');
            foreach (DB::all('SELECT * FROM stores WHERE active = 1 ORDER BY sort, id') as $s) {
                $ctx = DpsDoc::ctx($s);
                $out[] = $ctx['ok']
                    ? self::row(self::OK, 'ПРРО: ' . $s['name'], '№ ' . $ctx['rro'] . ', локальний ' . $ctx['cashdesk'])
                    : self::row(self::WARN, 'ПРРО: ' . $s['name'], 'не налаштовано: ' . implode('; ', $ctx['missing']));
            }
            if (Settings::bool('dps_testing', true)) {
                $out[] = self::row(self::WARN, 'ПРРО: тестовий режим',
                    'УВІМКНЕНО — чеки без юридичної сили. Після перевірки закрийте тестову зміну Z-звітом і вимкніть у Налаштуваннях');
            }
            $stuck = (int)DB::val("SELECT COUNT(*) FROM fiscal_receipts WHERE status = 'error'");
            if ($stuck > 0) {
                $out[] = self::row(self::BAD, 'Непробиті чеки',
                    $stuck . ' — розберіться до запуску (адмінка → Каса → «Чеки, які не пройшли»)');
            }
        } elseif (!FiscalProvider::anyConfigured()) {
            $out[] = self::row(self::WARN, 'Каса (ПРРО)',
                'жодного маршруту не налаштовано — фіскальні чеки не пробиваються '
                . '(якщо ПРРО не потрібен, це нормально)');
        } else {
            /*
             * Питаємо стан по КОЖНІЙ точці окремо: каса належить точці, і
             * «десь у мережі все добре» тут нічого не означає.
             *
             * Спитати саму касу можна лише в хмарному маршруті. Там, де ключ у
             * магазині, наш сервер до неї не ходить — тому перевіряємо те, що
             * справді в нашій владі: чи заповнене все для маршруту і чи виходив
             * на звʼязок агент. Мовчазний агент — це точка, яка приймає гроші й
             * не пробиває чеків, і дізнатись про це з першої скарги дорого.
             */
            foreach (DB::all('SELECT id, name, agent_seen_at FROM stores WHERE active = 1 ORDER BY sort, id') as $s) {
                $storeId = (int)$s['id'];
                $title = 'Каса: ' . $s['name'];
                $route = FiscalProvider::route($storeId);
                $gaps = FiscalProvider::missing($route);
                if ($gaps) {
                    // Точка без каси — не помилка сама по собі: вона може не мати ПРРО
                    $out[] = self::row(self::WARN, $title, 'не налаштована: ' . implode('; ', $gaps));
                    continue;
                }

                if ($route['route'] !== 'cloud') {
                    $seen = trim((string)($s['agent_seen_at'] ?? ''));
                    if ($route['route'] === 'device') {
                        $out[] = self::row(self::OK, $title,
                            'чеки пробиває каса на пристрої продавця (' . $route['device'] . ')');
                    } elseif ($seen === '') {
                        $out[] = self::row(self::BAD, $title,
                            'агент жодного разу не виходив на звʼязок — чеки не буде кому пробити');
                    } elseif (strtotime($seen) < time() - 900) {
                        $out[] = self::row(self::BAD, $title,
                            'агент мовчить із ' . date('d.m H:i', strtotime($seen)) . ' — чеки стоятимуть у черзі');
                    } else {
                        $out[] = self::row(self::OK, $title, 'агент на звʼязку, каса ' . $route['device']);
                    }
                    continue;
                }

                // Найдорожча помилка — забутий токен ТЕСТОВОЇ каси: чеки
                // пробиваються, номери є, у картках усе зелене, а в ДПС не
                // потрапляє нічого. Тому питаємо саму касу, чи вона фіскальна.
                $st = Vchasno::status($storeId);
                if (!$st['ok']) {
                    $out[] = self::row(self::BAD, $title, 'не відповідає: ' . $st['error']);
                    continue;
                }
                $info = \VchasnoDoc::status($st['data']);
                if ($info['test']) {
                    $out[] = self::row(self::BAD, $title,
                        'токен ТЕСТОВОЇ каси — чеки не матимуть юридичної сили й у ДПС не підуть');
                } elseif (!$info['signed']) {
                    $out[] = self::row(self::WARN, $title,
                        'ключ касира не завантажено у сховище кабінету — перший чек не буде кому підписати');
                } else {
                    $out[] = self::row(self::OK, $title, 'каса ' . ($info['rro'] ?: '?') . ' відповідає');
                }
            }

            // Чеки, які так і не пробились: на бойовому сервері це не «дрібниця
            // в журналі», а продажі повз ДПС
            $stuck = (int)DB::val("SELECT COUNT(*) FROM fiscal_receipts WHERE status = 'error'");
            if ($stuck > 0) {
                $out[] = self::row(self::BAD, 'Непробиті чеки',
                    $stuck . ' — розберіться до запуску (адмінка → Каса → «Чеки, які не пройшли»)');
            }
        }

        // Пошуковий індекс: галку ставлять на час налагодження й забувають зняти
        $out[] = Settings::bool('seo_noindex')
            ? self::row(self::WARN, 'Індексація пошуковиками', 'ЗАБОРОНЕНА (seo_noindex) — зніміть, коли сайт готовий')
            : self::row(self::OK, 'Індексація пошуковиками', 'дозволена');

        return $out;
    }

    private static function files(): array
    {
        $out = [];

        // Теки, у які пише сайт. Недоступні на запис — це не «щось не працює»,
        // а сесії, які не зберігаються, тобто вхід, що постійно злітає.
        foreach (['storage/sessions' => 'сесії', 'storage/logs' => 'логи',
                  'storage/cache' => 'кеш', 'assets/uploads' => 'фото'] as $dir => $what) {
            $abs = BOFU_ROOT . '/' . $dir;
            if (!is_dir($abs)) { $out[] = self::row(self::BAD, "Тека $dir", 'немає — ' . $what . ' нікуди писати'); continue; }
            $out[] = is_writable($abs)
                ? self::row(self::OK, "Тека $dir", 'доступна на запис')
                : self::row(self::BAD, "Тека $dir", 'НЕ доступна на запис — ' . $what . ' не збережуться');
        }

        // .htaccess у цих теках — єдине, що закриває код і логи ззовні, якщо
        // піде щось не так із головним правилом маршрутизації
        foreach (['.htaccess', 'app/.htaccess', 'bin/.htaccess', 'storage/.htaccess',
                  'assets/uploads/.htaccess'] as $f) {
            if (!is_file(BOFU_ROOT . '/' . $f)) {
                $out[] = self::row(self::BAD, $f, 'НЕ ДОЇХАВ — найімовірніше, розгортання копіювало зірочкою (див. README)');
            }
        }

        return $out;
    }
}
