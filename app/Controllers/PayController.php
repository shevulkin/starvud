<?php
declare(strict_types=1);

namespace Controllers;

use DB, View, Csrf, Auth, Acquiring, OrderFlow, RateLimit, Settings;

/**
 * Оплата карткою: три входи й жодного зайвого.
 *
 *   /pay/{token}        — сторінка оплати замовлення (покупець приходить сюди
 *                         одразу після оформлення або повертається пізніше);
 *   /pay/start          — наш власний POST, який створює спробу оплати й
 *                         відправляє браузер на шлюз;
 *   /pay/notify         — шлюз повідомляє результат НАШОМУ СЕРВЕРУ;
 *   /pay/return         — шлюз повертає БРАУЗЕР покупця.
 *
 * Замовлення впізнається за тим самим токеном, що й сторінка «Замовлення
 * прийнято»: у гостя немає кабінету, і посилання з токеном — єдине, що в нього
 * є. Номер замовлення для цього не годиться — він короткий і передбачуваний.
 *
 * Створення спроби навмисно живе в POST, а не в GET сторінки: інакше кожне
 * оновлення вкладки або перехід «назад» заводили б нову спробу, і в журналі
 * платежів замість двох справжніх спроб лежало б двадцять.
 */
class PayController
{
    /** Сторінка оплати: скільки, за що і кнопка «Оплатити» */
    public static function page(string $token): never
    {
        $order = self::order($token);
        $payments = Acquiring::forParent((int)$order['id']);
        $last = $payments[0] ?? null;

        // Уже оплачено — не показуємо кнопку вдруге. Людина, що двічі
        // натиснула оплату, не має отримати два списання.
        if (Acquiring::settled((int)$order['id'])) redirect('/order/success/' . $token);

        View::show('pay/index', [
            'order' => $order,
            'token' => $token,
            'last' => $last,
            'attempts' => $payments,
            'enabled' => Acquiring::enabled(),
            'gaps' => Acquiring::missing(),
            'test' => Acquiring::env() === 'test',
            'page_title' => 'Оплата замовлення ' . $order['number'] . ' — ' . cfg('app_name'),
        ]);
    }

    /**
     * Створити спробу оплати й віддати браузеру форму на шлюз.
     *
     * Ліміт тут не проти ботів, а проти самої людини: кожне натискання створює
     * операцію в банку, і десяток спроб поспіль виглядає для антифрод-системи
     * еквайєра як перебір карток — після чого блокують не покупця, а магазин.
     */
    public static function start(): never
    {
        Csrf::verify();
        RateLimit::guard('pay_start', 12, 3600);
        $token = trim((string)($_POST['token'] ?? ''));
        $order = self::order($token);

        if (Acquiring::settled((int)$order['id'])) redirect('/order/success/' . $token);

        $res = Acquiring::start($order, Auth::id());
        if (!$res['ok']) {
            flash('error', $res['error']);
            redirect('/pay/' . $token);
        }
        // Сторінка нічого не показує довше за мить: її єдина робота —
        // відправити форму на шлюз. Кнопка під нею потрібна для браузерів із
        // вимкненим JavaScript, і саме через неї сторінка не може бути
        // редіректом.
        View::show('pay/redirect', [
            'action' => $res['action'],
            'fields' => $res['fields'],
            'order' => $order,
            'page_title' => 'Переходимо до оплати — ' . cfg('app_name'),
        ], null);
    }

    /**
     * NOTIFY_URL: шлюз говорить із нашим сервером напряму.
     *
     * Ні сесії, ні CSRF: це не браузер. Відповідь — простий текст, який шлюз
     * розбирає рядок за рядком; будь-який HTML навколо зробив би оплату
     * «непідтвердженою магазином», і шлюз скасував би списання.
     *
     * Викликається з App::run() ДО Auth::start() — див. пояснення там.
     */
    public static function notify(): never
    {
        /*
         * Стеля для чужих.
         *
         * Вхід відкритий для всього інтернету — інакше шлюз до нас не
         * достукається. Підпис не пропустить підробку, але сам перебір номерів
         * оплати нічого не коштує тому, хто його робить, і коштує нам записом у
         * журнал на кожен запит.
         *
         * Адреси шлюзу з документації ліміту не підлягають: краще прийняти
         * зайвий запит, ніж відкинути справжнє «оплату отримано». А якщо
         * перелік адрес застарів, шлюз усе одно має триста запитів на годину —
         * при кількох на платіж це запас у сотні разів.
         */
        $ip = self::ip();
        $known = Acquiring::NOTIFY_IPS[Acquiring::env()] ?? [];
        if ($ip !== '' && !in_array($ip, $known, true)) {
            if (!RateLimit::hit('pay_notify', 300, 3600, $ip)) {
                Acquiring::log("notify: стеля запитів вичерпана для $ip");
                http_response_code(429);
                header('Content-Type: text/plain; charset=utf-8');
                exit("Too many requests\n");
            }
        }

        $res = Acquiring::handleNotify($_POST, $ip);
        header('Content-Type: text/plain; charset=utf-8');
        // 200 навіть на відмову: код відповіді тут нічого не означає, рішення
        // магазину лежить у тілі (Response.action). Помилка HTTP змусила б
        // шлюз повторювати запит, замість того щоб прочитати нашу відповідь.
        http_response_code(200);
        echo $res['body'];
        exit;
    }

    /**
     * Повернення покупця з платіжної сторінки.
     *
     * Параметрів тут мінімум (OrderID, TranCode, SD) і підпису серед них може
     * не бути взагалі — тож НІЧОГО, крім пошуку платежу, ми з них не беремо.
     * Стан з'ясовуємо в шлюзу самі, якщо NOTIFY ще не дійшов: питання «чи
     * оплачено» вирішує банк, а не рядок у формі, яку може підробити будь-хто.
     *
     * Викликається з App::run() ДО Auth::start().
     */
    public static function back(): never
    {
        $ref = trim((string)($_POST['OrderID'] ?? ($_GET['OrderID'] ?? '')));
        $payment = $ref !== '' ? Acquiring::byRef($ref) : null;
        if (!$payment) {
            Acquiring::log("return: невідомий номер оплати «$ref»");
            self::go('/');
        }

        // NOTIFY міг не дійти: локальна розробка без публічної адреси, впав
        // наш сервер, брандмауер відкинув чужий IP. Питаємо шлюз самі — це
        // те саме джерело, тільки іншим боком.
        if (in_array((string)$payment['status'], ['new', 'sent'], true)) {
            $r = Acquiring::sync($payment);
            $payment = $r['payment'];
        }

        $parent = OrderFlow::order((int)$payment['parent_id']);
        $token = (string)($parent['token'] ?? '');
        if ($token === '') self::go('/');

        self::go(in_array((string)$payment['status'], ['paid', 'held'], true)
            ? '/order/success/' . $token
            : '/pay/' . $token);
    }

    /**
     * Редірект без сесії.
     *
     * Звичайний redirect() тим і відрізняється, що дорогою пише flash у
     * $_SESSION, а сесії тут навмисно немає (див. App::run). Стан оплати
     * покупець побачить на сторінці, куди йде, — вона читає його з бази.
     */
    private static function go(string $path): never
    {
        header('Location: ' . base_url($path));
        exit;
    }

    /** IP того, хто стукає: для журналу, а не для рішення (див. Acquiring) */
    private static function ip(): string
    {
        if (cfg('trust_proxy') && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $first = explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR'])[0];
            return trim($first);
        }
        return (string)($_SERVER['REMOTE_ADDR'] ?? '');
    }

    /** Головне замовлення за токеном; інакше — на головну */
    private static function order(string $token): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) { flash('error', 'Замовлення не знайдено.'); redirect('/'); }
        $order = DB::row('SELECT * FROM orders WHERE token = ? AND parent_id IS NULL', [$token]);
        if (!$order) { flash('error', 'Замовлення не знайдено.'); redirect('/'); }
        if ((string)$order['status'] === 'canceled') {
            flash('error', 'Це замовлення скасоване — оплачувати його не потрібно.');
            redirect('/');
        }
        return $order;
    }
}
