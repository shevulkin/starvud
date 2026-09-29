<?php
declare(strict_types=1);

namespace Controllers\Admin;

use View, Auth, AuthLog, Settings, WebPush, BotAuth, IntegrationCheck, RateLimit, Catalog, OrderFlow, Acquiring;

class SettingsAdmin
{
    private const TEXT_KEYS = [
        'seo_title' => 'SEO: заголовок сайту',
        'seo_description' => 'SEO: опис сайту',
        'telegram_bot_token' => 'Telegram: токен бота',
        'viber_bot_token' => 'Viber: токен бота',
        'np_api_key' => 'Нова Пошта: API-ключ',
        // Токен каси. Загальний — для магазину з однією касою; у точки може
        // бути свій (Магазини → картка точки), і він старший за цей.
        'vchasno_token' => 'Вчасно.Каса: токен каси',
        // Ключа Google Maps тут більше немає: карту зі сторінок прибрано, а
        // разом із нею — чужий скрипт і чужий домен у CSP. Адреси й кнопки
        // «прокласти маршрут» працюють без жодного ключа.
        'youtube_channel' => 'YouTube: канал (@handle, посилання або UC-ID)',
        // Три поштові поля, а не одне. Загальна скринька магазину — те, що
        // покупець бачить у листі про замовлення; окрема скринька входу возить
        // коди (чому саме так — Notify::AUTH_EVENTS); Reply-To веде відповідь
        // туди, де її прочитають. Друге й третє поля необовʼязкові: порожні —
        // працює як раніше, усе з однієї адреси.
        'mail_from' => 'Email відправника: замовлення й сповіщення',
        'mail_from_auth' => 'Email відправника: коди входу (можна не заповнювати)',
        'mail_reply_to' => 'Email для відповідей покупця (Reply-To)',
        'google_client_id' => 'Google OAuth: Client ID',
        'google_client_secret' => 'Google OAuth: Client Secret',
        'bot_site_url' => 'Адреса сайту для кнопки в боті (напр. https://bofu.ua)',
    ];

    /**
     * Ключі, які НЕ повертаються у форму.
     *
     * Раніше кожне таке поле рендерилось як `value="<справжній ключ>"` —
     * крапками його ховав лише type=password, тобто ключ лежав у розмітці
     * сторінки цілком. Прочитати його там може будь-яке розширення браузера, і
     * будь-яка вставка скрипта на цю сторінку вивозить усі ключі одразу.
     *
     * Приватний ключ платіжного шлюзу так ніколи й не робив (див. поле acq_key
     * у вигляді): порожнє поле означає «не міняти», а прибрати збережене можна
     * окремою галкою. Ці два місця розійшлися — тепер правило одне на всіх.
     *
     * Чого тут НЕМАЄ і чому:
     *
     *   google_client_id — публічний за побудовою OAuth, його видно в адресі
     *     кнопки «Увійти через Google».
     */
    private const SECRET_KEYS = [
        'telegram_bot_token',
        'viber_bot_token',
        'np_api_key',
        'vchasno_token',
        'google_client_secret',
    ];

    public static function isSecret(string $key): bool
    {
        return in_array($key, self::SECRET_KEYS, true);
    }

    /**
     * Відбиток збереженого ключа: «46 символів, …4f2a».
     *
     * Потрібен рівно для того, заради чого раніше показували значення: звірити
     * збережене з тим, що в кабінеті сервісу. Чотирьох останніх символів для
     * цього досить — переплутані ключі відрізняються в них майже завжди, — а
     * відновити ключ із відбитка неможливо.
     *
     * Короткі значення показуємо самим лише фактом: у ключі з десяти символів
     * чотири — це вже майже половина.
     */
    public static function secretHint(string $key): string
    {
        $v = trim((string)Settings::get($key, ''));
        if ($v === '') return '';
        $len = mb_strlen($v);
        return $len < 12
            ? 'збережено'
            : 'збережено · ' . $len . ' символів, закінчується на …' . mb_substr($v, -4);
    }

    /**
     * Відправник для накладних Нової Пошти.
     *
     * Окремо від TEXT_KEYS, бо це не «ключ інтеграції», а те, що друкується на
     * кожній посилці: контрагент, контактна особа, звідки відправляємо. Поля
     * зберігаються парами «ref + назва»: ref потрібен API, назву читає людина
     * в налаштуваннях, і без неї список показував би UUID.
     */
    private const NP_KEYS = [
        'np_sender_ref', 'np_sender_name',
        'np_sender_contact_ref', 'np_sender_contact_name', 'np_sender_phone',
        'np_sender_city', 'np_sender_city_ref',
        'np_sender_warehouse', 'np_sender_warehouse_ref',
        'np_description', 'np_weight_default', 'np_seats_default',
    ];

    /**
     * Те, що друкується в кожному чеку «Вчасно.Каси».
     *
     * Окремо від TEXT_KEYS, як і відправник НП: це не ключ інтеграції, який
     * копіюють із кабінету, а підпис під чеком, який читає покупець.
     */
    private const VCHASNO_KEYS = ['vchasno_cashier', 'vchasno_comment_down'];

    private const TOGGLES = [
        'notify_all_enabled' => 'Усі сповіщення (головний вимикач)',
        'notify_telegram_enabled' => 'Канал Telegram',
        'notify_viber_enabled' => 'Канал Viber',
        'notify_email_enabled' => 'Канал Email',
        'notify_push_enabled' => 'Канал Push',
    ];

    /**
     * Перевірка інтеграцій без збереження: беремо те, що зараз у формі.
     * Ліміт — бо кожен виклик ходить у три чужі API, і кнопку легко затиснути.
     */
    public static function check(): never
    {
        Auth::requireCap('settings.manage');
        RateLimit::guard('settings_check', 30, 3600);
        // Поля еквайрингу лежать в іншій групі форми (acq[…]), але перевіряти
        // їх треба так само незбереженими — інакше «перевірити» означало б
        // «спершу збережіть», а зберігати неперевірений ключ якраз і не хочеться
        json_response(['ok' => true, 'rows' => IntegrationCheck::run(
            (array)($_POST['text'] ?? []) + ['acq' => (array)($_POST['acq'] ?? [])])]);
    }

    public static function index(): never
    {
        Auth::requireCap('settings.manage');
        if (is_post()) {
            // Головний вимикач — гейт над канальними (Notify::fire), тож у формі
            // вони неактивні, поки він вимкнений. Неактивне поле в POST не
            // приходить, тому за вимкненого головного канальні НЕ чіпаємо:
            // інакше одне вимкнення мовчки стирало б усі налаштування каналів,
            // і після повернення головного все прийшлося б розставляти заново.
            $master = isset($_POST['toggle']['notify_all_enabled']);
            Settings::set('notify_all_enabled', $master ? '1' : '0');
            if ($master) {
                foreach (self::TOGGLES as $key => $label) {
                    if ($key === 'notify_all_enabled') continue;
                    Settings::set($key, isset($_POST['toggle'][$key]) ? '1' : '0');
                }
            }
            // окремо від TOGGLES: там усе типово увімкнене, а індексація має бути дозволена за замовчуванням
            Settings::set('seo_noindex', isset($_POST['seo_noindex']) ? '1' : '0');
            /*
             * Торг. Головний вимикач і дві межі, які роблять із нього торг:
             * підлога осмисленої пропозиції й строк, доки діє домовлена ціна.
             *
             * Порожнє поле означає «як у коді», а не «без обмежень», тому
             * зберігаємо порожнім, а не нулем: нуль тут читався б як «підлоги
             * немає» — тобто «приймаємо будь-яку ціну».
             */
            Settings::set('offers_enabled', isset($_POST['offers_enabled']) ? '1' : '0');
            $minPct = trim((string)($_POST['offers_min_percent'] ?? ''));
            Settings::set('offers_min_percent',
                $minPct === '' ? '' : (string)max(0, min(100, (int)$minPct)));
            $hold = trim((string)($_POST['offers_hold_hours'] ?? ''));
            Settings::set('offers_hold_hours', $hold === '' ? '' : (string)max(1, (int)$hold));
            // Обіцянка покупцю й будильник продавцю — одне число, див. Offers
            $reply = trim((string)($_POST['offers_reply_hours'] ?? ''));
            Settings::set('offers_reply_hours', $reply === '' ? '' : (string)max(1, min(720, (int)$reply)));
            // Магазин, якому дістаються позиції, яких немає ніде. Приймаємо лише
            // чинну активну точку: неіснуючий id мовчки відкотив би нас до
            // «першої активної», і власник вважав би, що вибір діє.
            $store = (int)($_POST['default_store_id'] ?? 0);
            $valid = $store && in_array($store, OrderFlow::activeStoreIds(), true);
            Settings::set('default_store_id', $valid ? (string)$store : '');
            // Нова Пошта: відправник і типові значення накладної. Списки
            // приймаємо лише зі своїх — підставлений «платник» чи «спосіб
            // оплати» НП відхилила б уже при створенні накладної, тобто тоді,
            // коли продавець стоїть із коробкою у відділенні.
            foreach (self::NP_KEYS as $key) {
                if (isset($_POST['np'][$key])) Settings::set($key, trim((string)$_POST['np'][$key]));
            }
            $payer = (string)($_POST['np']['np_payer'] ?? '');
            Settings::set('np_payer', isset(\Shipments::PAYERS[$payer]) ? $payer : 'Recipient');
            $payment = (string)($_POST['np']['np_payment'] ?? '');
            Settings::set('np_payment', isset(\Shipments::PAYMENTS[$payment]) ? $payment : 'Cash');
            Settings::set('np_cod_default', isset($_POST['np_cod_default']) ? '1' : '0');

            // Вчасно.Каса. Податкову групу приймаємо лише з їхнього переліку:
            // підставлене число ПРРО відхилив би вже на живому чеку, тобто
            // тоді, коли покупець стоїть біля каси.
            $tax = (int)($_POST['vchasno_taxgrp'] ?? 0);
            Settings::set('vchasno_taxgrp', (string)(isset(\Vchasno::TAX_GROUPS[$tax]) ? $tax : 2));
            // Постачальник ПРРО й маршрут за замовчуванням. Приймаємо лише з
            // переліку: підставлене значення означало б чеки, які нікуди не
            // йдуть, і зʼясувалось би це на першому ж продажі.
            $prov = (string)($_POST['fiscal_provider'] ?? '');
            Settings::set('fiscal_provider', isset(\FiscalProvider::PROVIDERS[$prov]) ? $prov : 'dps');
            $route = (string)($_POST['fiscal_route'] ?? '');
            if (isset(\FiscalProvider::ROUTES[$route])) Settings::set('fiscal_route', $route);
            // ПРРО ДПС: тестовий режим і адреса фіскального сервера. Адресу
            // приймаємо лише на tax.gov.ua — поле, у яке можна вписати будь-що,
            // тихо відвело б підписані чеки на чужий сервер.
            if (isset($_POST['dps_fs_url'])) {
                Settings::set('dps_testing', isset($_POST['dps_testing']) ? '1' : '0');
                $fs = trim((string)$_POST['dps_fs_url']);
                $host = strtolower((string)parse_url($fs, PHP_URL_HOST));
                $ok = $fs === '' || (preg_match('~^https?://~i', $fs) && ($host === 'tax.gov.ua' || str_ends_with($host, '.tax.gov.ua')));
                Settings::set('dps_fs_url', $ok ? rtrim($fs, '/') : '');
                if (!$ok) flash('error', 'Адреса фіскального сервера має бути на tax.gov.ua — лишили типову.');
            }
            foreach (self::VCHASNO_KEYS as $key) {
                // Чистимо тим самим фільтром, що й самі чеки: ПРРО має вузьку
                // абетку, і емодзі в підписі касира завалило б кожен чек —
                // причому не тут, а на касі, посеред черги.
                if (isset($_POST['vch'][$key])) Settings::set($key, \Vchasno::clean((string)$_POST['vch'][$key], 120));
            }
            foreach (['vchasno_auto_pos', 'vchasno_send_link', 'vchasno_cash_round'] as $key) {
                Settings::set($key, isset($_POST[$key]) ? '1' : '0');
            }

            /*
             * Інтернет-еквайринг Raiffeisen Bank.
             *
             * Середовище приймаємо лише з переліку: підставлене значення
             * означало б справжні гроші там, де очікували тест, — і навпаки,
             * тобто «оплачені» замовлення без грошей.
             *
             * Ключ і сертифікат ЗАПИСУЄМО ЛИШЕ НЕПОРОЖНІМИ. Назад у форму вони
             * не виводяться (приватний ключ не має вдруге їхати в браузер), і
             * без цієї умови кожне збереження налаштувань стирало б їх
             * порожньою textarea — тобто вимикало оплату на всьому сайті
             * будь-якою правкою сусіднього поля.
             */
            Settings::set('acq_enabled', isset($_POST['acq_enabled']) ? '1' : '0');
            $env = (string)($_POST['acq_env'] ?? '');
            Settings::set('acq_env', isset(\Acquiring::BASE[$env]) ? $env : 'test');
            foreach (['acq_merchant_id', 'acq_terminal_id', 'acq_desc'] as $key) {
                if (isset($_POST['acq'][$key])) Settings::set($key, trim((string)$_POST['acq'][$key]));
            }
            foreach (['acq_key', 'acq_cert'] as $key) {
                $pem = trim((string)($_POST['acq'][$key] ?? ''));
                if ($pem !== '') Settings::set($key, $pem);
            }
            // Явне прибирання ключа — окремою галкою, а не порожнім полем:
            // випадково стерти оплату не має бути так само легко, як зберегти
            foreach (['acq_key' => 'acq_key_clear', 'acq_cert' => 'acq_cert_clear'] as $key => $flag) {
                if (isset($_POST[$flag])) Settings::set($key, '');
            }
            Settings::set('acq_hold', isset($_POST['acq_hold']) ? '1' : '0');
            Settings::set('acq_auto_fiscal', isset($_POST['acq_auto_fiscal']) ? '1' : '0');

            $oldViber = Settings::get('viber_bot_token', '');
            foreach (self::TEXT_KEYS as $key => $label) {
                if (!isset($_POST['text'][$key])) continue;
                $val = trim((string)$_POST['text'][$key]);

                if (self::isSecret($key)) {
                    /*
                     * Секрети зберігаються за іншим правилом, ніж решта полів.
                     *
                     * Форма їх не показує, тож порожнє поле означає «не чіпав»,
                     * а не «зітри». Інакше правка сусіднього рядка мовчки
                     * вимикала б Telegram чи Нову Пошту — і зʼясувалось би це
                     * на першому замовленні, яке нікуди не поїхало.
                     *
                     * Прибрати збережений ключ можна, але лише окремою галкою:
                     * вимкнення інтеграції має бути свідомою дією, а не
                     * побічним ефектом збереження форми.
                     */
                    $clear = !empty($_POST['secret_clear'][$key]);
                    $old = trim((string)Settings::get($key, ''));
                    $had = $old !== '';
                    if ($clear) {
                        Settings::set($key, '');
                        if ($had) AuthLog::write(Auth::id(), 'secret_changed', 'прибрано: ' . $label);
                    } elseif ($val !== '' && $val !== $old) {
                        Settings::set($key, $val);
                        // У журнал іде назва поля, а не значення: журнал читає
                        // людина, і він потрапляє в кожен дамп бази
                        AuthLog::write(Auth::id(), 'secret_changed', ($had ? 'замінено: ' : 'задано: ') . $label);
                    }
                    continue;
                }

                Settings::set($key, $val);
            }
            // Тексти бота: порожнє поле = повернути типовий текст, а не показати
            // людині порожнє повідомлення. Тому зберігаємо '' і підставляємо
            // замовчування при читанні (BotAuth::text).
            foreach (array_keys(BotAuth::TEXTS) as $key) {
                if (isset($_POST['bot'][$key])) Settings::set($key, trim($_POST['bot'][$key]));
            }
            Settings::set('yt_cache_time', '0'); // оновити кеш YouTube після зміни налаштувань
            // Viber: при зміні токена реєструємо webhook (працює лише на публічному HTTPS)
            $newViber = Settings::get('viber_bot_token', '');
            if ($newViber && $newViber !== $oldViber) {
                // Токен змінився — бот може бути вже інший, тож кеш URI викидаємо
                // й питаємо заново. Без URI кнопка входу через Viber не показується,
                // і мовчазна невдача тут виглядала б як «вхід кудись подівся».
                \Viber::forgetUri();
                $uri = \Viber::uri();
                if ($uri === '') flash('error', 'Viber: не вдалося отримати адресу бота — перевірте токен. Кнопка входу через Viber поки не показуватиметься.');

                $res = \Viber::setWebhook();
                if (($res['status'] ?? -1) === 0) flash('success', 'Viber webhook зареєстровано' . ($uri ? ', бот: ' . $uri : ''));
                else flash('error', 'Viber: webhook не зареєстровано (локально це нормально — запрацює на хостингу). ' . ($res['status_message'] ?? ''));
            }
            AuthLog::write(Auth::id(), 'settings_changed');
            flash('success', 'Налаштування збережено');
            redirect('/admin/settings');
        }
        [$vapidPub] = WebPush::ensureKeys();
        View::show('admin/settings', [
            'toggles' => self::TOGGLES, 'text_keys' => self::TEXT_KEYS,
            'stores' => Catalog::stores(),
            'default_store_id' => OrderFlow::defaultStoreId(),
            'default_store_set' => Settings::get('default_store_id', '') !== '',
            'bot_texts' => BotAuth::TEXTS, 'bot_site' => BotAuth::siteUrl(),
            'np_enabled' => \NovaPoshta::enabled(),
            'np_payers' => \Shipments::PAYERS, 'np_payments' => \Shipments::PAYMENTS,
            'tax_groups' => \Vchasno::TAX_GROUPS,
            'fiscal_providers' => \FiscalProvider::PROVIDERS,
            'fiscal_routes' => \FiscalProvider::ROUTES,
            // Точки з власною касою: щоб було видно, на кого загальний токен
            // не поширюється, і не довелось шукати це по картках магазинів
            'vchasno_own' => \DB::all("SELECT name, vchasno_taxgrp FROM stores
                                       WHERE vchasno_token IS NOT NULL AND vchasno_token <> ''
                                       ORDER BY sort, id"),
            'vapid_public' => $vapidPub,
            // Еквайринг. Самі ключі у форму не повертаємо — лише те, чи вони є
            // й звідки читаються: приватний ключ, відданий у браузер, живе
            // потім у кожному кеші й кожному розширенні цього браузера.
            'acq_envs' => Acquiring::ENVS,
            'acq_env' => Acquiring::env(),
            'acq_gaps' => Acquiring::missing(),
            'acq_has_key' => Acquiring::privateKey() !== '',
            'acq_has_cert' => Acquiring::certificate() !== '',
            'acq_key_dir' => Acquiring::keyDir(),
            'acq_notify_url' => abs_url('/pay/notify'),
            'acq_return_url' => abs_url('/pay/return'),
            // Замовлення, які чекають на оплату карткою. Потрібні рівно тоді,
            // коли оплату вимикають: сам вимикач нічого не ламає, але ці
            // замовлення лишаються з обіцянкою, яку сайт більше не виконає, і
            // комусь треба зателефонувати їхнім покупцям.
            'acq_pending' => Acquiring::pending(),
            'page_title' => 'Налаштування — Адміністрування',
        ], 'layouts/admin');
    }
}
