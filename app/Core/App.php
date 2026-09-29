<?php
declare(strict_types=1);

class App
{
    public static function run(): void
    {
        // Найпершими: заголовки мають піти навіть із тією відповіддю, яка
        // впаде помилкою або редіректом. Усе, що нижче, вже може щось віддати.
        Security::headers();
        if (!self::dbReady()) return;
        $path = request_path();
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        /*
         * Відповіді платіжного шлюзу — до всього іншого й БЕЗ сесії.
         *
         * Обидві приходять із чужого сайту, а кука сесії має SameSite=Lax і в
         * крос-сайтовий POST не потрапляє. Тобто session_start() тут завів би
         * НОВУ порожню сесію й віддав браузеру її куку — покупець повернувся б
         * з оплати розлогіненим, з порожнім кошиком і без своїх адрес. Ціна
         * помилки більша за незручність: це виглядає як «магазин загубив мене
         * разом із грошима».
         *
         * Тому ці два входи не читають і не пишуть сесію взагалі: замовлення
         * вони знаходять за номером оплати, а покупця — за токеном замовлення.
         * CSRF тут теж не діє й не має діяти: шлюз не браузер і токена не має,
         * а справжність запиту доводить підпис (див. Acquiring).
         */
        $clean = rtrim($path, '/') ?: '/';
        if ($clean === '/pay/notify' && $method === 'POST') { Controllers\PayController::notify(); }
        if ($clean === '/pay/return') { Controllers\PayController::back(); }

        self::checkMethod($method);

        /*
         * Невалідний UTF-8 у формах (старий браузер, скрипт, cp1251 з буфера)
         * інакше доїжджав до бази й падав там SQL-помилкою. Биті байти
         * замінюємо «?» — одразу для всього сайту, а не в кожній формі.
         * Після платіжного шлюзу: його підпис рахується від сирих даних.
         */
        $scrub = static function (&$v): void { if (is_string($v) && !mb_check_encoding($v, 'UTF-8')) $v = mb_scrub($v, 'UTF-8'); };
        array_walk_recursive($_GET, $scrub);
        array_walk_recursive($_POST, $scrub);

        Auth::start();

        // Сайт закрито від пошуковиків: заголовок діє й там, де немає HTML (sitemap, JSON, файли)
        if (Settings::bool('seo_noindex')) header('X-Robots-Tag: noindex, nofollow');

        // Спільні дані для всіх шаблонів
        View::share('auth_user', Auth::user());
        View::share('cart_count', Cart::count());
        // Назва розділу над назвою товару в картці — усі картки сторінки
        // беруть її звідси, а не питають базу кожна окремо
        View::share('cardCats', array_column(DB::all('SELECT id, name FROM categories'), 'name', 'id'));

        /*
         * Як із покупцем звʼязатись: доки нема ані номера, ані підтвердженої
         * пошти — лише профіль. Номер перевіряємо саме на валідність, а не на
         * «непорожність», інакше сміття на кшталт '1' пройде гейт.
         *
         * Раніше тут вимагався саме телефон, і для тих, хто входить поштою, це
         * ставало глухим кутом: номер міг виявитись зайнятим (наприклад,
         * записом, який продавець завів на цю людину в точці), а віддати його
         * не можна — пошта не доводить володіння номером. Виходило, що людина
         * увійшла й лишилась замкненою в профілі назавжди.
         *
         * Сенс гейта від цього не змінився: підтверджена скринька — теж спосіб
         * дістати покупця. А номер отримувача в замовленні питається окремо, у
         * формі оформлення, і там він обовʼязковий, як і був.
         */
        $u = Auth::user();
        if ($u && AuthTokens::normPhoneAny((string)($u['phone'] ?? '')) === null && empty($u['email_verified_at'])) {
            $p = rtrim(request_path(), '/') ?: '/';
            $allowed = ['/profile', '/profile/tg/link', '/profile/tg/check', '/profile/viber/link', '/profile/viber/check', '/logout', '/sw.js', '/manifest.webmanifest'];
            // відписка від розсилки має працювати завжди — це вимога закону про згоду
            if (!in_array($p, $allowed, true) && !str_starts_with($p, '/unsubscribe/')) {
                flash('error', 'Вкажіть, будь ласка, номер телефону, щоб продовжити.');
                redirect('/profile');
            }
        }

        try {
            self::route($method, rtrim($path, '/') ?: '/');
        } catch (Throwable $e) {
            @file_put_contents(BOFU_ROOT . '/storage/logs/app-error.log',
                '[' . now() . '] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n", FILE_APPEND);
            if (cfg('debug')) throw $e;
            http_response_code(500);
            echo View::render('errors/500', [], 'layouts/main');
        }
    }

    /**
     * Методи, якими з сайтом узагалі можна розмовляти.
     *
     * Маршрути описані парами «шлях + метод» лише там, де метод важливий; для
     * решти умова виглядає як `if ($path === '/shop')` — тобто GET і DELETE для
     * неї одне й те саме, і `DELETE /` віддавав головну з кодом 200.
     *
     * Даних це не міняло: усе, що змінює стан, живе в POST і вимагає
     * CSRF-токена. Але сторінка, яку віддають на будь-який метод, — це
     * поверхня, якої не має бути: сканери й проміжні кеші поводяться з такими
     * відповідями по-різному, а кожен новий маршрут, дописаний «поруч» без
     * перевірки методу, успадковує це мовчки.
     *
     * HEAD не відкидаємо: це той самий GET без тіла, і саме ним ходять
     * перевірки доступності сайту. OPTIONS відповідає переліком і на цьому
     * зупиняється — так менше приводів вигадувати, що ще ми вміємо.
     */
    private const METHODS = ['GET', 'HEAD', 'POST'];

    private static function checkMethod(string $method): void
    {
        if (in_array($method, self::METHODS, true)) return;

        $allow = implode(', ', array_merge(self::METHODS, ['OPTIONS']));
        header('Allow: ' . $allow);
        if ($method === 'OPTIONS') { http_response_code(204); exit; }

        http_response_code(405);
        header('Content-Type: text/plain; charset=utf-8');
        exit("Метод не підтримується.\n");
    }

    /**
     * База готова до роботи.
     *
     * ДВІ РІЗНІ ПОВЕДІНКИ, і різниця між ними — не зручність, а ціна помилки.
     *
     * Локально порожня база означає «щойно клонував проєкт»: створити таблиці
     * й засіяти демо-дані — саме те, чого людина чекає, і вона це побачить.
     *
     * На бойовому сервері та сама порожня база означає щось інше: невдале
     * відновлення з копії, помилку в назві бази в config.local.php, або те, що
     * сайт відкрили раніше, ніж виконали міграцію. У жодному з цих випадків
     * правильна відповідь не «створити все заново» — а саме її система й
     * давала: на місці магазину тихо зʼявлялись демо-товари й демо-користувачі,
     * а справжні дані лишались там, де були, — тобто ніде їх не шукали.
     * Ця історія вже траплялась, і саме через неї існує команда `cli.php wipe`.
     *
     * Тому на production ми зупиняємось і кажемо про це. Схему створює людина
     * однією командою — `php bin/cli.php migrate`, — і робить це свідомо.
     *
     * Оновлення схеми (Schema::upgrade) виконується лише коли версія справді
     * стара. Раніше воно запускалось на КОЖЕН запит: зайва робота на кожне
     * відкриття сторінки й, гірше, вікно посеред розгортання, у яке половина
     * залитого коду могла почати міграцію.
     */
    private static function dbReady(): bool
    {
        try {
            DB::val('SELECT COUNT(*) FROM settings');
            if ((int)Settings::get('schema_version', '1') < Schema::VERSION) Schema::upgrade();
            return true;
        } catch (Throwable $e) {
            if (cfg('env') === 'production') {
                self::dbDown(new RuntimeException(
                    'База порожня або недоступна. Якщо це новий сервер — виконайте '
                    . 'php bin/cli.php migrate. Якщо ні — НЕ створюйте схему поверх: '
                    . 'спершу зʼясуйте, куди поділись дані.'));
                return false;
            }
            try {
                Schema::createAll();
                Seeder::run();
                DB::val('SELECT COUNT(*) FROM settings');
                return true;
            } catch (Throwable $e2) {
                self::dbDown($e2);
                return false;
            }
        }
    }

    /**
     * Сторінка «сайт тимчасово недоступний».
     *
     * Дві різні сторінки для двох різних людей.
     *
     * Розробнику потрібна причина й команда. Покупцю — ні: він не запускатиме
     * docker, а слова «C:\xampp\htdocs\bofu» на чужому сайті лише розкажуть
     * стороннім, як влаштована наша машина. Тому підказки видно рівно там, де
     * debug увімкнений, тобто локально, — а на бойовому сервері лишається одна
     * фраза без жодної технічної деталі.
     */
    private static function dbDown(Throwable $e): void
    {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        // Пошуковик має зрозуміти, що це тимчасово, і не викидати сторінки з індексу
        header('Retry-After: 300');

        $body = cfg('debug')
            ? '<h1 style="color:#f0b429">База даних недоступна</h1>'
              . '<p>Запустіть базу даних командою:</p>'
              . '<p><code style="background:#241d15;padding:8px 14px;border-radius:4px">docker compose up -d</code></p>'
              . '<p style="color:#9a8a6b;font-size:13px">у папці проєкту (' . htmlspecialchars(BOFU_ROOT) . '),'
              . ' зачекайте ~20 секунд і оновіть сторінку.<br>'
              // текст помилки PDO містить хост і користувача БД — лише в debug
              . htmlspecialchars($e->getMessage()) . '</p>'
            : '<h1 style="color:#f0b429">Сайт тимчасово недоступний</h1>'
              . '<p style="color:#9a8a6b">Ми вже про це знаємо. Спробуйте, будь ласка, за кілька хвилин.</p>';

        echo '<!DOCTYPE html><html lang="uk"><head><meta charset="utf-8">'
           . '<meta name="viewport" content="width=device-width, initial-scale=1">'
           . '<title>Сайт тимчасово недоступний</title></head>'
           . '<body style="font-family:sans-serif;background:#141110;color:#f6ecd9;display:flex;'
           . 'align-items:center;justify-content:center;min-height:100vh;text-align:center;margin:0">'
           . '<div style="padding:20px">' . $body . '</div></body></html>';
        @file_put_contents(BOFU_ROOT . '/storage/logs/app-error.log',
            '[' . date('Y-m-d H:i:s') . '] DB: ' . $e->getMessage() . "\n", FILE_APPEND);
    }

    private static function route(string $method, string $path): void
    {
        // --- статичні/системні ---
        if ($path === '/robots.txt') { Controllers\Seo::robots(); }
        if ($path === '/sitemap.xml') { Controllers\Seo::sitemap(); }
        if ($path === '/manifest.webmanifest') { Controllers\Seo::manifest(); }
        if ($path === '/sw.js') { Controllers\Seo::serviceWorker(); }

        // --- аутентифікація ---
        if ($path === '/auth/google') { Controllers\AuthController::google(); }
        if ($path === '/auth/google/callback') { Controllers\AuthController::googleCallback(); }
        /*
         * Входів навмисно менше, ніж каналів звʼязку.
         *
         * Демо-вхід прибрано: він давав адмін-права одним POST без пароля, а
         * стримував його один прапорець у конфігурації. Для локальної роботи
         * лишається `php bin/cli.php grant-admin`.
         *
         * Вхід через Viber прибрано теж, і причина не в реалізації, а в самому
         * Viber: він не має чим довести, що надісланий номер належить
         * співрозмовнику (у Telegram для цього є contact.user_id). Viber
         * лишається каналом сповіщень і доставки коду — там, де ми ПИШЕМО в
         * уже підтверджений чат, підробити нічого не можна.
         */
        // створення токенів входу — найдешевша для бота дія, тому з лімітом
        if ($path === '/auth/tg/start') { RateLimit::guard('login_start', 20, 3600, null, true); Controllers\AuthController::tgStart(); }
        if ($path === '/auth/tg/status') { Controllers\AuthController::tgStatus(); }
        /*
         * Вхід поштою за одноразовим кодом — для тих, у кого немає ані Google,
         * ані Telegram. Стеля по IP лишається: кожен код — це лист на вказану
         * адресу, тож без неї форма стає безкоштовною поштовою гарматою по
         * чужих скриньках. Але вона борониться саме від РОЗСІЮВАННЯ по багатьох
         * адресах — конкретну жертву захищають дві інші, всередині: пауза між
         * листами (AuthTokens::RESEND_SEC) і три коди на адресу за годину
         * (EmailAuth::PER_HOUR). Їх зміною IP не обійти.
         *
         * Було 5 на годину — і це виявилось стелею не для бота, а для покупця.
         * Українські мобільні мережі сидять під NAT, тож один IP — це часто
         * ціла стільникова сота чи офіс: пʼять листів на всіх, і решта бачить
         * «Забагато запитів», нічого не зробивши. Двадцять так само не дають
         * розсіювати (двадцять листів на годину з одного IP — не гармата), але
         * не замикають двері перед живими людьми.
         */
        if ($path === '/auth/email/start' && $method === 'POST') { RateLimit::guard('email_start', 20, 3600, null, true); Controllers\AuthController::emailStart(); }
        if ($path === '/auth/email/verify' && $method === 'POST') { RateLimit::guard('email_verify', 20, 3600, null, true); Controllers\AuthController::emailVerify(); }
        // Та сама причина, що й у пошти: NAT робить IP спільним для багатьох
        if ($path === '/auth/phone/start' && $method === 'POST') { RateLimit::guard('phone_start', 20, 3600, null, true); Controllers\AuthController::phoneStart(); }
        if ($path === '/auth/phone/verify' && $method === 'POST') { RateLimit::guard('phone_verify', 20, 3600, null, true); Controllers\AuthController::phoneVerify(); }
        if ($path === '/profile') { Controllers\Profile::index(); }
        if ($path === '/profile/tg/link') { Controllers\Profile::tgLink(); }
        if ($path === '/profile/tg/check') { Controllers\Profile::tgCheck(); }
        if ($path === '/profile/viber/link') { Controllers\Profile::viberLink(); }
        if ($path === '/profile/viber/check') { Controllers\Profile::viberCheck(); }
        if ($path === '/logout' && $method === 'POST') { Controllers\AuthController::logout(); }
        // режим перегляду — поза адмінкою: у режимі покупця стафних прав немає,
        // і під гейтом адмінки з нього не було б виходу
        if ($path === '/role/switch' && $method === 'POST') { Controllers\RoleController::change(); }
        if ($path === '/role/reset' && $method === 'POST') { Controllers\RoleController::reset(); }
        if ($path === '/role/store' && $method === 'POST') { Controllers\RoleController::store(); }

        // каса — теж поза адмінкою: чек набирають, ходячи вітриною, і смужка
        // продажу має працювати саме там, де продавець зараз стоїть
        if ($path === '/pos/state') { Controllers\PosController::state(); }
        if ($path === '/pos/off' && $method === 'POST') { Controllers\PosController::off(); }

        // режим редагування — теж поза адмінкою: правлять блоки на самій вітрині,
        // і гейт /admin закрив би збереження саме там, де воно потрібне
        if ($path === '/edit/on' && $method === 'POST') { Controllers\EditController::on(); }
        if ($path === '/edit/off' && $method === 'POST') { Controllers\EditController::off(); }
        if ($path === '/edit/blocks' && $method === 'GET') { Controllers\EditController::blocks(); }
        if ($path === '/edit/block' && $method === 'GET') { Controllers\EditController::block(); }
        if ($path === '/edit/save' && $method === 'POST') { Controllers\EditController::save(); }

        // --- вітрина ---
        if ($path === '/') { Controllers\Home::index(); }
        if ($path === '/shop') { Controllers\Shop::index(); }
        // Розділ каталогу окремою адресою: /shop/med замість /shop?cat=med.
        // Один сегмент вистачає й для підрозділів — slug категорії унікальний.
        // Старі адреси з ?cat= сюди ж і переїжджають, редиректом (Shop::index).
        if (preg_match('~^/shop/([a-z0-9-]+)$~', $path, $m)) { Controllers\Shop::index($m[1]); }
        if (preg_match('~^/product/([a-z0-9-]+)$~', $path, $m)) { Controllers\Shop::product($m[1]); }
        if ($path === '/about') { Controllers\Home::about(); }
        // Відгуки — тією самою адресою, що й на Prom: старі посилання з
        // месенджерів і видачі мають вести туди ж, куди й раніше
        if ($path === '/testimonials') { Controllers\Home::testimonials(); }
        if ($path === '/testimonials/new' && $method === 'POST') { RateLimit::guard('review', 5, 3600); Controllers\Home::reviewSubmit(); }
        if ($path === '/stores' || $path === '/contacts') { Controllers\Home::stores(); }
        // Адреси Prom (/ua/..., /product_list) — на відповідні сторінки тут
        if ($path === '/ua' || $path === '/product_list' || $path === '/ua/product_list') { redirect('/shop', 301); }
        if ($path === '/ua/testimonials') { redirect('/testimonials', 301); }
        if ($path === '/ua/delivery_info') { redirect('/delivery', 301); }
        if ($path === '/ua/about_us') { redirect('/about', 301); }
        if ($path === '/ua/contacts') { redirect('/contacts', 301); }
        if (preg_match('~^/(?:ua/)?p(\d+)-[^/]*\.html$~', $path, $m)) { Controllers\Shop::byPromId($m[1]); }
        // Правові сторінки. Умови продажу — частина товару, а не додаток до
        // нього: без них покупець не знає, з ким має справу, а закон вимагає
        // цієї інформації на сайті.
        if (preg_match('~^/(delivery|payment|returns|privacy|offer)$~', $path, $m)) { Controllers\Home::legal($m[1]); }

        // --- кошик і замовлення ---
        if ($path === '/cart') { Controllers\CartController::index(); }
        if ($path === '/cart/add' && $method === 'POST') { Controllers\CartController::add(); }
        // Набір кладеться однією дією: розкладати його на три кліки означало б
        // питати покупця про те, що вже вирішено складом набору
        if ($path === '/cart/add-bundle' && $method === 'POST') { Controllers\CartController::addBundle(); }
        // Домовлена ціна кладеться окремою дією: рядок кошика з нею живе за
        // іншими правилами, ніж звичайний, і плутати їх в одному вході
        // означало б щоразу з'ясовувати, який саме перед нами
        if ($path === '/cart/add-offer' && $method === 'POST') { Controllers\CartController::addOffer(); }
        if ($path === '/cart/update' && $method === 'POST') { Controllers\CartController::update(); }
        // сторінка оформлення лише читає; POST сюди приймати нема потреби, а без нього
        // стороння сторінка не може записати покупцеві промокод у сесію
        // У режимі продажу кошик — це чек покупця, а не власна покупка продавця.
        // Звичайне оформлення тут створило б замовлення на самого продавця, ще
        // й повз касу: без анонімності, без «віддано», без позначки «звідки».
        // Тому обидва входи в оформлення ведуть на касу.
        if (str_starts_with($path, '/checkout') && Pos::active()) redirect('/admin/orders/new');
        if ($path === '/checkout' && $method !== 'POST') { Controllers\Checkout::form(); }
        if ($path === '/checkout' . '/submit' && $method === 'POST') { Controllers\Checkout::submit(); }
        // промокод — коротка комбінація літер, тобто його можна підбирати; з лімітом
        if ($path === '/checkout/promo' && $method === 'POST') { RateLimit::guard('promo', 40, 3600, null, true); Controllers\Checkout::applyPromo(); }
        if (preg_match('~^/order/success/([a-f0-9]{32})$~', $path, $m)) { Controllers\Checkout::success($m[1]); }
        if ($path === '/orders') { Controllers\Checkout::myOrders(); }

        // --- оплата карткою ---
        // Замовлення впізнається за тим самим токеном, що й сторінка «прийнято»:
        // у гостя немає кабінету, і посилання з токеном — усе, що в нього є.
        // Два входи шлюзу (/pay/notify, /pay/return) обробляються вище, до
        // старту сесії, — див. пояснення в App::run().
        if (preg_match('~^/pay/([a-f0-9]{32})$~', $path, $m)) { Controllers\PayController::page($m[1]); }
        if ($path === '/pay/start' && $method === 'POST') { Controllers\PayController::start(); }
        // «повідомте, коли зʼявиться» — з лімітом: кнопка відкрита будь-кому,
        // хто увійшов, а запити дешеві й ідуть у чергу, яку читає продавець
        if ($path === '/stock/watch' && $method === 'POST') {
            RateLimit::guard('stock_watch', 30, 3600);
            Controllers\Shop::watch();
        }

        // --- торг ---
        // Адреси навмисно не /offer*: цей шлях уже зайнятий публічною офертою
        // (правовий документ вище). Два різні «offer» на одному сайті — це не
        // лише зіткнення маршрутів, а й зіткнення слів: «оферта» й
        // «пропозиція ціни» звучать однаково, а означають протилежне за
        // обовʼязковістю.
        //
        // Ліміт тут суворіший за решту: кожна пропозиція коштує продавцю
        // уваги живої людини. Двадцять ходів на добу — більше, ніж потрібно
        // будь-якому справжньому покупцю, і замало, щоб завалити чергу.
        if ($path === '/bargain') { Controllers\OfferController::index(); }
        if ($path === '/bargain/new' && $method === 'POST') { RateLimit::guard('offer', 20, 86400); Controllers\OfferController::propose(); }
        if ($path === '/bargain/accept' && $method === 'POST') { Controllers\OfferController::accept(); }
        if ($path === '/bargain/cancel' && $method === 'POST') { Controllers\OfferController::cancel(); }

        // --- розсилка ---
        if (preg_match('~^/unsubscribe/([a-f0-9]{32})$~', $path, $m)) { Controllers\NewsletterController::unsubscribe($m[1]); }

        // --- API ---
        // проксі до Нової Пошти витрачає наш API-ключ — не даємо викачувати довідник
        // Проксі до ЦСК для бібліотеки підпису КЕП (ПРРО ДПС). Поза /admin, бо
        // адмінка вимагає CSRF-токен, а запити шле воркер бібліотеки без нього;
        // права перевіряє сам маршрут (див. DpsSign::proxy)
        if ($path === '/fiscal/ca-proxy') { Controllers\Admin\DpsSign::proxy(); }
        if ($path === '/api/np/cities') { RateLimit::guard('np', 300, 3600, null, true); Controllers\Api::npCities(); }
        if ($path === '/api/np/warehouses') { RateLimit::guard('np', 300, 3600, null, true); Controllers\Api::npWarehouses(); }
        if ($path === '/api/np/streets') { RateLimit::guard('np', 300, 3600, null, true); Controllers\Api::npStreets(); }
        // Довідник відправників — вміст особистого кабінету НП, тож лише персоналу
        // й лише POST: право перевіряється всередині разом із CSRF
        if ($path === '/api/np/senders' && $method === 'POST') { Controllers\Api::npSenders(); }
        // Агент каси: єдине вікно між сайтом і ПРРО, у якого ключ лежить у
        // магазині. Без сесії й без CSRF — агент не браузер; його впізнають за
        // токеном точки. Ліміт усередині: агент стукає раз на пару секунд, і
        // це нормально, а от підбір токена має впертись у стелю.
        if ($path === '/api/fiscal/pull' && $method === 'POST') { Controllers\Api::fiscalPull(); }
        if ($path === '/api/fiscal/push' && $method === 'POST') { Controllers\Api::fiscalPush(); }
        if ($path === '/api/push/subscribe' && $method === 'POST') { Controllers\Api::pushSubscribe(); }
        if ($path === '/api/viber/webhook' && $method === 'POST') { Controllers\Api::viberWebhook(); }

        // --- адмінка ---
        if (str_starts_with($path, '/admin')) { self::admin($method, $path); }

        http_response_code(404);
        echo View::render('errors/404');
        exit;
    }

    private static function admin(string $method, string $path): void
    {
        Auth::requireStaff();
        $A = 'Controllers\\Admin\\';
        $post = $method === 'POST';
        if ($post) Csrf::verify();

        // Третій елемент — право, потрібне для маршруту. Правило навмисно суворе:
        // маршрут без права доступний лише тим, хто має всі права. Так забутий
        // запис закриває доступ, а не відкриває його тихо.
        // 'staff' — виняток для панелі: вона нічого не показує понад те, на що
        // права перевіряються всередині, і має бути доступна будь-якому персоналу.
        $routes = [
            '/admin'                    => [$A.'Dashboard', 'index', 'staff'],
            '/admin/products'           => [$A.'Products', 'index', 'products.view'],
            '/admin/products/new'       => [$A.'Products', 'create', 'products.manage'],
            '/admin/products/bulk'      => [$A.'Products', 'bulk', 'products.view'],
            '/admin/stock-requests'     => [$A.'StockRequests', 'index', 'products.view'],
            // Торг: черга пропозицій ціни. Своя сторінка, а не вкладка в
            // замовленнях, — це ще не замовлення, і поки триває розмова,
            // продавати нема чого
            '/admin/offers'             => [$A.'OffersAdmin', 'index', 'offers.manage'],
            // Черга розподілу: з якої точки поїде онлайн-замовлення
            '/admin/dispatch'           => [$A.'Dispatch', 'index', 'orders.manage'],
            '/admin/categories'         => [$A.'Categories', 'index', 'catalog.manage'],
            '/admin/attributes'         => [$A.'Attributes', 'index', 'catalog.manage'],
            '/admin/brands'             => [$A.'Brands', 'index', 'catalog.manage'],
            '/admin/partners'           => [$A.'Partners', 'index', 'content.manage'],
            '/admin/stores'             => [$A.'Stores', 'index', 'stores.manage'],
            // Власники точок: коли мережа — це більше ніж один платник податків
            '/admin/owners'             => [$A.'OwnersAdmin', 'index', 'stores.manage'],
            '/admin/orders'             => [$A.'Orders', 'index', 'orders.view'],
            '/admin/orders/new'         => [$A.'Orders', 'pos', 'orders.create'],
            '/admin/orders/search'      => [$A.'Orders', 'search', 'orders.create'],
            // Плитка однієї категорії: фільтр у касі не має відкривати сторінку
            // заново — разом з нею скидався крок і набране в полях
            '/admin/orders/tiles'       => [$A.'Orders', 'tiles', 'orders.create'],
            // Маршрут «каса на цьому пристрої»: до Device Manager на машині
            // продавця може достукатись лише його ж вкладка, тож завдання їй
            // видає сайт, а результат вона приносить назад.
            '/admin/fiscal/next'        => [$A.'Orders', 'fiscalNext', 'orders.fiscal'],
            '/admin/fiscal/done'        => [$A.'Orders', 'fiscalDone', 'orders.fiscal'],
            // Пробний запит до каси на пристрої: нічого не проводить, лише
            // показує, чи браузер узагалі пускає сторінку на localhost
            '/admin/fiscal/probe'       => [$A.'Orders', 'fiscalProbe', 'orders.fiscal'],
            // ПРРО ДПС: вкладка з ключем касира бере документ, підписує й віддає
            '/admin/fiscal/dps/jobs'    => [$A.'DpsSign', 'jobs', 'orders.fiscal'],
            '/admin/fiscal/dps/step'    => [$A.'DpsSign', 'step', 'orders.fiscal'],
            '/admin/fiscal/dps/submit'  => [$A.'DpsSign', 'submit', 'orders.fiscal'],
            '/admin/fiscal/dps/status'  => [$A.'DpsSign', 'status', 'orders.fiscal'],
            '/admin/products/codes'     => [$A.'Products', 'codes', 'products.manage'],
            // Каса «Вчасно»: зміна, звіти, звірка товарів. Чеки окремих
            // замовлень тут не живуть — вони в картках замовлень.
            '/admin/vchasno'            => [$A.'Kasa', 'index', 'fiscal.manage'],
            '/admin/vchasno/goods'      => [$A.'Kasa', 'goods', 'fiscal.manage'],
            '/admin/promos'             => [$A.'Promos', 'index', 'promos.manage'],
            // Набори «разом дешевше»: свій екран, бо в набору є склад —
            // список товарів, який не вміщається рядком серед акцій
            '/admin/bundles'            => [$A.'BundlesAdmin', 'index', 'promos.manage'],
            // Оптові шкали: своя сторінка, бо в них є питання, якого немає в
            // акцій, — котра шкала справді діє після перебивання ярусів
            '/admin/wholesale'          => [$A.'Wholesale', 'index', 'promos.manage'],
            // Відгуки: модерація нових і відповіді від імені магазину
            '/admin/reviews'            => [$A.'ReviewsAdmin', 'index', 'content.manage'],
            '/admin/users'              => [$A.'Users', 'index', 'users.manage'],
            '/admin/users/message'      => [$A.'Users', 'message', 'users.manage'],
            '/admin/subscribers'        => [$A.'Subscribers', 'index', 'subscribers.manage'],
            '/admin/content'            => [$A.'ContentAdmin', 'index', 'content.manage'],
            '/admin/media'              => [$A.'Media', 'index', 'media.manage'],
            '/admin/settings'           => [$A.'SettingsAdmin', 'index', 'settings.manage'],
            '/admin/settings/check'     => [$A.'SettingsAdmin', 'check', 'settings.manage'],
            '/admin/notifications'      => [$A.'Notifications', 'index', 'notifications.manage'],
        ];
        if (isset($routes[$path])) {
            [$cls, $fn] = $routes[$path];
            self::gate($routes[$path][2] ?? null);
            $cls::$fn();
        }
        if (preg_match('~^/admin/products/(\d+)$~', $path, $m)) {
            self::gate('products.view'); $cls = $A.'Products'; $cls::edit((int)$m[1]);
        }
        if (preg_match('~^/admin/orders/(\d+)$~', $path, $m)) {
            self::gate('orders.view'); $cls = $A.'Orders'; $cls::view((int)$m[1]);
        }
        // Рахунок і видаткова накладна: окрема сторінка без адмінського обрамлення,
        // щоб її можна було просто надрукувати або зберегти в PDF браузером
        if (preg_match('~^/admin/orders/(\d+)/invoice$~', $path, $m)) {
            self::gate('orders.view'); $cls = $A.'Orders'; $cls::invoice((int)$m[1]);
        }

        http_response_code(404);
        echo View::render('errors/404');
        exit;
    }

    /**
     * Гейт маршруту адмінки. Це перший із двох рубежів: другий стоїть усередині
     * кожної дії, що змінює дані, бо один маршрут обслуговує і показ, і POST
     * з різними діями, а приховати кнопку — не перевірка.
     */
    private static function gate(?string $cap): void
    {
        if ($cap === 'staff') return;          // достатньо requireStaff() вище
        Auth::requireCap($cap ?? '*');         // право не оголошене — лише повні права
    }
}
