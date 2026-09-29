<?php
declare(strict_types=1);
define('BOFU_ROOT', dirname(__DIR__));
require BOFU_ROOT . '/app/Core/bootstrap.php';

$cmd = $argv[1] ?? 'help';
switch ($cmd) {
    case 'migrate':
        Schema::createAll();
        // upgrade() наприкінці ставить schema_version. Без нього перший же
        // запит із браузера вважав би базу за версію 1 і прогнав усі історичні
        // кроки поспіль — вони нешкідливі, але це зайвий шум у логах саме тоді,
        // коли дивишся, чи все гаразд із новим сервером.
        Schema::upgrade();
        echo "Міграції: таблиці створено/оновлено (схема " . Schema::VERSION . ")\n";
        break;
    case 'wipe':
        /*
         * Порожня база одним рухом — щоб одразу за нею йшла міграція.
         *
         * Потрібно це рівно в одному випадку: коли на новому сервері сайт
         * відкрили раніше, ніж створили таблиці, і App::dbReady() прийняв
         * порожню базу за перший запуск та засіяв демо-дані. Прибирати їх
         * руками через phpMyAdmin можна, але між очищенням і міграцією
         * лишається вікно, у яке встигає будь-яка відкрита вкладка чи бот, —
         * і демо-дані повертаються. Тому команда й існує: `wipe && migrate`
         * не лишає такого вікна взагалі.
         *
         * Захист від випадкового запуску — назва бази аргументом. Не прапорець
         * «--force», який дописують не читаючи, а саме назва: її треба свідомо
         * подивитись і надрукувати, і на чужій базі вона не збігається.
         */
        $want = trim((string)($argv[2] ?? ''));
        $real = (string)cfg('db.database', '');
        if ($want === '' || $want !== $real) {
            echo "Це видалить УСІ таблиці бази. Відновити буде нічим.\n"
               . "Щоб підтвердити, вкажіть назву бази з config.local.php:\n\n"
               . "    php bin/cli.php wipe {$real}\n\n"
               . "Далі одразу створіть схему: php bin/cli.php migrate\n";
            exit(1);
        }
        $driver = DB::driver();
        // Порядок таблиць невідомий, а зовнішні ключі не дадуть видаляти
        // «батьків» раніше за «дітей» — тож на час видалення знімаємо перевірку
        if ($driver === 'mysql') DB::query('SET FOREIGN_KEY_CHECKS = 0');
        // Беремо те, що реально є в базі, а не список зі схеми: у ній не буде
        // таблиць, які лишились від старих версій, і вони пережили б очищення
        $rows = $driver === 'sqlite'
            ? DB::all("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")
            : DB::all('SHOW TABLES');
        $n = 0;
        foreach ($rows as $row) {
            $t = (string)reset($row);
            if ($t === '') continue;
            DB::query('DROP TABLE IF EXISTS ' . ($driver === 'sqlite' ? '"' . $t . '"' : '`' . $t . '`'));
            $n++;
        }
        if ($driver === 'mysql') DB::query('SET FOREIGN_KEY_CHECKS = 1');
        echo "Базу «{$real}» очищено: видалено таблиць — {$n}.\n"
           . "ОДРАЗУ виконайте: php bin/cli.php migrate\n"
           . "Поки таблиць немає, перше ж відкриття сайту засіє демо-дані наново.\n";
        exit(0);
    case 'grant-admin':
        /*
         * Перший адміністратор на новому сервері.
         *
         * Будь-який вхід — через Google чи за кодом на пошту — створює людину
         * покупцем (Auth::loginWithGoogle, EmailAuth::verify), і правильно
         * робить: інакше будь-хто, хто натиснув «Увійти», ставав би
         * адміністратором. Але на порожній базі це замикає коло: щоб роздати
         * ролі, треба вже мати доступ до адмінки. Ця команда його розмикає —
         * рівно один раз, з консолі, де вона доступна лише власнику хостингу.
         *
         * Роль пишемо і в user_roles (звідки її читає Auth), і в users.role —
         * друге лишилось з часів, коли роль була одна, і його читає ще частина
         * старих запитів.
         */
        $email = trim((string)($argv[2] ?? ''));
        if ($email === '') {
            echo "Вкажіть пошту: php bin/cli.php grant-admin ваша@пошта\n";
            exit(1);
        }
        $u = DB::row('SELECT * FROM users WHERE email = ?', [$email]);
        if (!$u) {
            // Фігурні дужки обовʼязкові: у назвах змінних PHP дозволені байти
            // 0x80–0xFF, тож «$email»» прочиталось би як змінна разом із лапкою
            echo "Користувача з поштою «{$email}» немає.\n"
               . "Спершу увійдіть на сайт цією поштою — «Увійти» → «Увійти за поштою» (або через\n"
               . "Google), акаунт створиться сам, після чого повторіть команду.\n";
            exit(1);
        }
        $uid = (int)$u['id'];
        if (!DB::row('SELECT id FROM user_roles WHERE user_id = ? AND role = ?', [$uid, Roles::ADMIN])) {
            DB::insert('user_roles', ['user_id' => $uid, 'role' => Roles::ADMIN, 'created_at' => now()]);
        }
        DB::update('users', ['role' => Roles::ADMIN, 'active' => 1], 'id = ?', [$uid]);
        echo "Готово: {$u['name']} <$email> тепер адміністратор.\n"
           . "Перезайдіть на сайт, якщо ви вже були залогінені.\n";
        exit(0);
    case 'prod-check':
        // Нічого не змінює: показує, що заважає пускати на сайт покупців.
        // Код виходу ненульовий, якщо є хоч одне «не можна» — щоб перевірку
        // можна було поставити останнім кроком розгортання.
        $rows = ProdCheck::run();
        $mark = [ProdCheck::OK => '  ✓', ProdCheck::WARN => '  !', ProdCheck::BAD => '  ✗'];
        // %-28s рахує байти, а кирилична літера важить два — колонка «поїхала б»
        // рівно на тих рядках, які тут усі
        $pad = fn(string $s, int $w) => $s . str_repeat(' ', max(1, $w - mb_strlen($s)));
        $bad = $warn = 0;
        foreach ($rows as $r) {
            if ($r['level'] === ProdCheck::BAD) $bad++;
            if ($r['level'] === ProdCheck::WARN) $warn++;
            echo $mark[$r['level']], ' ', $pad($r['title'], 26), $r['note'], "\n";
        }
        echo "\n" . str_repeat('─', 48) . "\n";
        echo $bad
            ? "НЕ ГОТОВО: критичних — $bad, попереджень — $warn\n"
            : ($warn ? "Можна запускати, але перегляньте попередження ($warn)\n" : "Готово до бойового сервера\n");
        exit($bad ? 1 : 0);
    case 'yt:refresh':
        /*
         * Оновити список відео каналу.
         *
         * Ставиться в cron раз на годину — рівно з тією ж частотою, з якою
         * протухає кеш:
         *
         *     30 * * * * php /home/USER/site/bin/cli.php yt:refresh
         *
         * Причина існування: RSS віддає 15 відео, і на кожне йде окремий запит
         * до YouTube, щоб зрозуміти, Short це чи ні. Шістнадцять послідовних
         * запитів — це секунди, і платив за них випадковий покупець, який
         * першим відкрив головну після протухання кешу. Тепер платить cron.
         *
         * Хвилину варто взяти не нульову: о рівній годині на shared-хостингу
         * стартують крони всіх сусідів одночасно.
         */
        if (trim((string)Settings::get('youtube_channel', '')) === '') {
            echo "YouTube: канал не вказано в налаштуваннях — оновлювати нічого.\n";
            exit(0);
        }
        $was = count((array)json_decode((string)Settings::get('yt_cache', ''), true));
        $videos = YouTube::latest(100, true);   // force: саме цьому виклику дозволено чекати
        $now = count((array)json_decode((string)Settings::get('yt_cache', ''), true));
        if (!$videos) {
            echo "YouTube не відповів — на сторінці лишається попередній список ($was відео).\n";
            exit(0);
        }
        echo "YouTube: у кеші відео — $now (було $was).\n";
        exit(0);
    case 'np:track':
        /*
         * Статуси посилок Нової Пошти.
         *
         * Ставиться в cron раз на годину — частіше немає сенсу: посилка не
         * змінює стан щохвилини, а ліміт запитів у НП спільний на весь сайт.
         *
         *     0 * * * * php /home/USER/site/bin/cli.php np:track
         *
         * Питаємо лише ті накладні, стан яких ще може змінитись: отримані й
         * видалені пропускаємо назавжди. За один прохід — до 100 штук (стільки
         * НП приймає за запит); решта дочекається наступної години, а всередині
         * пачки першими йдуть ті, кого перевіряли найдавніше.
         *
         * Змінені статуси самі рухають замовлення й пишуть покупцю — тому цю
         * команду не можна запускати «про всяк випадок» двічі поспіль на тій
         * самій хвилині: перша вже все зробила, друга лише витратить ліміт.
         */
        if (!NovaPoshta::enabled()) {
            echo "Нова Пошта: немає API-ключа в налаштуваннях — трекати нічим.\n";
            exit(0);
        }
        $due = Shipments::due((int)($argv[2] ?? 100));
        if (!$due) {
            echo "Активних накладних немає — усе доставлено.\n";
            exit(0);
        }
        $changed = Shipments::refresh($due);
        echo "Перевірено накладних: " . count($due) . ", змінили стан: $changed\n";
        exit(0);
    case 'pay:sync':
        /*
         * Оплати, про які шлюз не встиг сказати.
         *
         * NOTIFY-повідомлення — головний і найнадійніший шлях, але не єдиний
         * можливий: сервер міг лежати саме в ту хвилину, брандмауер міг
         * відкинути чужий IP, а на локальній машині публічної адреси немає
         * взагалі. Наслідок завжди один і найгірший з можливих: гроші з картки
         * списані, а замовлення виглядає неоплаченим — покупець дзвонить, і
         * продавцю нічим відповісти.
         *
         * Тому раз на чверть години питаємо сам шлюз про ті спроби, з яких
         * покупець пішов і не повернувся. Відповідь застосовується так само, як
         * NOTIFY: та сама перевірка сум, той самий чек, той самий лист. Повтору
         * не буде — позначка про оплату ставиться один раз (Acquiring::apply).
         *
         *     7,22,37,52 * * * * php /home/USER/site/bin/cli.php pay:sync
         *
         * Хвилини перелічені, а не «кожні 15» через скісну риску: рядок із
         * такою рискою всередині блокового коментаря PHP закриває сам коментар.
         *
         * Старші за добу не чіпаємо: шлюз до того часу закриває сесію сам, а
         * незавершена спроба зі вчора — це вже не «ще платить», а відмова.
         */
        if (Acquiring::missing()) {
            echo "Еквайринг не налаштований: " . implode('; ', Acquiring::missing()) . "\n";
            exit(0);
        }
        $due = Acquiring::due((int)($argv[2] ?? 50));
        if (!$due) {
            echo "Незавершених оплат немає.\n";
            exit(0);
        }
        $paid = 0; $failed = 0;
        foreach ($due as $p) {
            $r = Acquiring::sync($p);
            if ($r['ok']) $paid++;
            elseif ($r['code'] !== '') $failed++;
        }
        echo "Перевірено оплат: " . count($due) . ", підтверджено — $paid, відмовлено — $failed.\n";
        exit(0);
    case 'offers:remind':
        /*
         * Торг, який ніхто не веде.
         *
         * Найгірший сценарій торгу — не «незручно відповідати», а мовчанка:
         * покупець запропонував ціну, продавець не зайшов в адмінку, через два
         * тижні розмова тихо згасла. Людина не отримала нічого, тобто ми
         * запросили до розмови й не прийшли. Одна відсутня функція коштує
         * магазину менше, ніж один такий випадок.
         *
         * Команда проходить чотири мовчанки: пропозиція чекає на нас довше за
         * обіцяний строк, наші умови так само довго чекають на покупця,
         * погодженій ціні лишились години, розмова вже закрилась сама й про це
         * ніхто не сказав. Кожне повідомлення одноразове — мітки в самому
         * рядку (Offers::maintain).
         *
         * У cron раз на годину: обіцянка міряється добою, тож частіше немає
         * сенсу, а рідше — прострочить попередження про кінець строку. Хвилина
         * не нульова з тієї ж причини, що і в yt:refresh, — о рівній годині на
         * shared-хостингу стартують крони всіх сусідів одночасно.
         *
         *     20 * * * * php /home/USER/site/bin/cli.php offers:remind
         */
        $r = Offers::maintain();
        $total = array_sum($r);
        echo $total === 0
            ? "Торг: нагадувати нема про що.\n"
            : "Торг: нагадали продавцю — {$r['stalled']}, покупцю про наші умови — {$r['waiting']}, "
              . "про кінець строку — {$r['expiring']}, повідомили про закриття — {$r['closed']}.\n";
        exit(0);
    case 'vchasno:retry':
        /*
         * Чеки, на які каса не відповіла.
         *
         * Такий чек МІГ пробитись — зв’язок обірвався вже після того, як ПРРО
         * прийняв завдання. Тому повторюємо запит із тією самою міткою (tag):
         * «Вчасно.Каса» впізнає її й віддасть той самий чек, а не пробʼє
         * другий. Без цієї команди кожен обрив лишав би продаж без чека, а
         * продавця — з кнопкою, яку треба не забути натиснути.
         *
         * У cron кожні пʼять хвилин — цього досить, щоб покупець ще стояв біля
         * каси, коли чек нарешті знайдеться:
         *
         *     *_/5 * * * * php /home/USER/site/bin/cli.php vchasno:retry
         */
        $due = Fiscal::due((int)($argv[2] ?? 50));
        $done = $left = 0;
        foreach ($due as $r) {
            $res = Fiscal::retry($r);
            $res['ok'] ? $done++ : $left++;
        }
        /*
         * Окремо — завдання, які забрав агент і не повернув: вимкнули касовий
         * ПК, обірвався інтернет. Повертаємо їх у чергу; це безпечно рівно
         * тому, що мітка незмінна — якщо чек усе-таки пробився, друга спроба
         * поверне його ж, а не пробʼє новий.
         */
        $requeued = Fiscal::requeueStale();
        if (!$due && !$requeued) {
            echo "Непевних чеків немає.\n";
            exit(0);
        }
        echo "Перепитано: " . count($due) . ", пройшло: $done, досі без відповіді: $left"
           . ($requeued ? ", повернуто в чергу: $requeued" : '') . "\n";
        exit(0);
    case 'vchasno:z':
    case 'fiscal:z':
        /*
         * Z-звіт: закриття зміни.
         *
         * Закон вимагає закривати зміну щонайменше раз на добу — інакше каса
         * перестане приймати чеки просто посеред робочого дня. Ставте в cron
         * на час, коли точка вже точно не продає:
         *
         *     50 23 * * * php /home/USER/site/bin/cli.php vchasno:z
         *
         * Закриваємо КОЖНУ налаштовану касу: зміна належить касі, і одна
         * закрита за всіх нічого не означає. Закриту повторно не чіпаємо —
         * про це скаже сама каса, і це не помилка.
         */
        $stores = DB::all('SELECT id, name FROM stores WHERE active = 1 ORDER BY sort, id');
        $any = false; $bad = 0;
        foreach ($stores as $s) {
            $storeId = (int)$s['id'];
            $name = (string)$s['name'];
            $route = FiscalProvider::route($storeId);
            if (FiscalProvider::missing($route)) continue;   // каси в цієї точки немає
            $any = true;

            // Там, де до каси ходить наш сервер, спершу питаємо, чи зміна
            // взагалі відкрита: зайвий Z-звіт нічого не зламає, але й нічого
            // не дасть, а в журналі виглядатиме як помилка.
            if ($route['route'] === 'cloud') {
                $st = Vchasno::status($storeId);
                if (!$st['ok']) { echo "  ✗ $name: $st[error]\n"; $bad++; continue; }
                if ((int)($st['data']['info']['shift_status'] ?? -1) !== Vchasno::SHIFT_OPEN) {
                    echo "  · $name: зміна вже закрита\n";
                    continue;
                }
            }
            // ПРРО ДПС: ключ у продавця, тож Z-звіт лише ставимо в чергу — його
            // підпише вкладка «Каса», щойно відкриється. Закриту зміну й уже
            // поставлене закриття не дублюємо.
            if ($route['provider'] === 'dps') {
                if (!(int)DB::val('SELECT dps_shift_open FROM stores WHERE id = ?', [$storeId])) {
                    echo "  · $name: зміна вже закрита
";
                    continue;
                }
                if (DB::val("SELECT 1 FROM fiscal_receipts WHERE store_id = ? AND task = 'shift_close' AND status IN ('queued','pending') LIMIT 1", [$storeId])) {
                    echo "  · $name: закриття зміни вже чекає на вкладку «Каса»
";
                    continue;
                }
            }
            // Касира не вигадуємо: нічний Z-звіт не пробивав жоден продавець.
            // Порожнє імʼя підставить те, що вказано в Налаштуваннях
            // («Підпис для автоматичних операцій»), а якщо й там порожньо —
            // назву магазину. «cron» у фіскальному документі виглядав би як
            // прізвище касира, якого не існує.
            $r = Fiscal::service('shift_close', $storeId, null, ['cashier' => '']);
            if ($r['state'] === 'queued') echo "  → $name: завдання в черзі — його виконає агент точки чи вкладка «Каса» з ключем\n";
            elseif ($r['ok']) echo "  ✓ $name: зміну закрито\n";
            else { echo "  ✗ $name: $r[error]\n"; $bad++; }
        }
        if (!$any) {
            echo "Жодної каси не налаштовано — закривати нічого.\n";
            exit(0);
        }
        exit($bad ? 1 : 0);
    case 'dps:lib':
        /*
         * Бібліотека підпису КЕП (ІІТ) і файли ЦСК для ПРРО ДПС.
         *
         * У git їх немає (пропрієтарна бібліотека), тож на новому сервері —
         * один раз, а далі раз на кілька місяців, коли ДПС додає ЦСК:
         *
         *     php bin/cli.php dps:lib [версія npm-пакета]
         *
         * Файл спершу качається поруч і лише цілим заміняє старий: обірване
         * завантаження не має лишити касу без бібліотеки посеред дня.
         */
        $dir = BOFU_ROOT . '/assets/vendor/eusign';
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        $ver = preg_match('/^\d+\.\d+\.\d+$/', (string)($argv[2] ?? '')) ? $argv[2] : '1.5.2';
        $npm = "https://cdn.jsdelivr.net/npm/@it-enterprise/digital-signature@$ver";
        $cab = 'https://cabinet.tax.gov.ua/ws/api/crypto/public_sign/data';
        $files = ['euscp.js' => "$npm/euscp/euscp.js", 'euscp.worker.js' => "$npm/dist/euscp.worker.js",
                  'CAs.json' => "$cab/CAs.json", 'CACertificates.p7b' => "$cab/CACertificates.p7b"];
        $bad = 0;
        foreach ($files as $name => $src) {
            $ch = curl_init($src);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 180, CURLOPT_USERAGENT => 'Mozilla/5.0 (starvud dps:lib)']);
            $data = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            $okData = $data !== false && $code === 200 && strlen((string)$data) > 1000;
            if ($okData && $name === 'CAs.json') $okData = is_array(json_decode(preg_replace('/^\xEF\xBB\xBF/', '', (string)$data), true));
            if ($okData && $name === 'euscp.worker.js' && !str_contains((string)$data, 'EU_CTX_HASH_ALGO_DSTU7564')) {
                echo "  ✗ $name: збірка без ДСТУ 7564 — ключами нового зразка не підписати, файл не замінено\n";
                $bad++; continue;
            }
            if (!$okData) { echo "  ✗ $name: не завантажено (HTTP $code)\n"; $bad++; continue; }
            file_put_contents("$dir/$name.part", $data);
            rename("$dir/$name.part", "$dir/$name");
            printf("  ✓ %-20s %8.1f КБ  sha256 %s\n", $name, strlen((string)$data) / 1024, substr(hash('sha256', (string)$data), 0, 16));
        }
        echo $bad ? "Є помилки — див. вище.\n" : "Бібліотека підпису на місці (npm $ver).\n";
        exit($bad ? 1 : 0);
    case 'import:prom':
        /*
         * Перенос каталогу з Prom: php bin/cli.php import:prom [--stock=10] [--no-images]
         *
         * Дані — database/prom/*.json (знімок starvud.prom.ua), фото —
         * storage/import/prom/img. Запуск повторно оновлює ціни, наявність
         * і опт, не дублюючи товарів (див. PromImport).
         */
        $opts = getopt('', ['stock::', 'no-images', 'dir::', 'img::'], $rest);
        $dir = (string)($opts['dir'] ?? BOFU_ROOT . '/database/prom');
        $img = isset($opts['no-images']) ? '' : (string)($opts['img'] ?? BOFU_ROOT . '/storage/import/prom/img');
        if ($img !== '' && !is_dir($img)) {
            echo "Теки з фото немає ($img) — імпортую без фото. Фото можна докинути повторним запуском.
";
            $img = '';
        }
        Schema::createAll();
        Schema::upgrade();
        $r = PromImport::run($dir, $img, (int)($opts['stock'] ?? 10), static function (string $m) { echo "  … $m
"; });
        echo "Імпорт з Prom: розділів +{$r['categories']}, товарів +{$r['created']} (оновлено {$r['updated']}), "
           . "фото {$r['images']}, відгуків +{$r['reviews']}, акцій {$r['promos']}
";
        break;
    case 'seed':
        Schema::createAll();
        Seeder::run();
        break;
    case 'fresh':
        $c = cfg('db');
        if (DB::driver() === 'sqlite' && is_file($c['sqlite_path'])) unlink($c['sqlite_path']);
        else {
            foreach (array_keys(Schema::tables()) as $t) {
                try { DB::pdo()->exec("DROP TABLE IF EXISTS `$t`"); } catch (Throwable $e) {}
            }
        }
        Schema::createAll();
        Seeder::run();
        echo "Fresh: базу перестворено\n";
        break;
    case 'test':
        Schema::upgrade();
        $code = 0; $files = 0;
        foreach (glob(BOFU_ROOT . '/tests/*.php') ?: [] as $file) {
            echo "\n### " . basename($file) . "\n";
            $code = max($code, (int)require $file);
            $files++;
        }
        // підсумок наприкінці: інакше видно лише результат останнього файлу
        echo "\n" . str_repeat('─', 48) . "\n"
            . ($code === 0 ? "ВСІ НАБОРИ ПРОЙДЕНО ($files)" : "Є ПРОВАЛЕНІ НАБОРИ — дивіться вище") . "\n";
        exit($code);
    default:
        echo "Використання: php bin/cli.php [migrate|seed|fresh|import:prom|test|prod-check|wipe|grant-admin"
           . "|np:track|pay:sync|offers:remind|yt:refresh|vchasno:retry|fiscal:z|dps:lib]\n";
}
