<?php
/**
 * Оплата карткою: інтернет-еквайринг Raiffeisen Bank.  Запуск: php bin/cli.php test
 *
 * Живого шлюзу тут немає й бути не може: кожен запит туди — це або справжнє
 * списання, або дія над справжнім платежем. Тому транспорт підмінено, а ключі
 * генеруються прямо в наборі — і саме це робить перевірки змістовними: ми
 * перевіряємо не «чи відповів сервер», а ЩО САМЕ ми підписуємо і КОГО пускаємо
 * до позначки «оплачено».
 *
 * Найдорожчі помилки в цій інтеграції — тихі, і їх тут чотири:
 *
 *   1) Рядок для підпису. Одна зайва кома дає код 405, і покупець бачить
 *      «оплата не пройшла» на робочому сайті, а не в тесті. Формат UPC
 *      неочевидний саме комами: відсутнє поле прибирає кому, але не крапку з
 *      комою.
 *   2) Підроблена відповідь. Якщо ми повіримо будь-кому, хто постукав у
 *      /pay/notify, замовлення стане оплаченим без грошей. Тому перевіряється
 *      не лише «правильний підпис проходить», а й що НЕправильний не проходить
 *      і що чужа сума не проходить.
 *   3) Повторне зарахування. Шлюз надсилає сповіщення двічі, покупець оновлює
 *      сторінку, cron звіряє стан — і всі троє приходять з тим самим успіхом.
 *      Двічі позначена оплата означає два фіскальні чеки на один продаж.
 *   4) Копійки. Сума підписується цілими копійками; перетворення туди-назад
 *      через float не має її зрушити навіть на одиницю.
 *
 * Набір створює власні товари й замовлення і прибирає їх за собою.
 */
declare(strict_types=1);

final class AcquiringTest
{
    private int $pass = 0;
    private int $fail = 0;
    private int $store = 0;
    private int $product = 0;
    private array $parentIds = [];
    private array $settingsWas = [];
    private string $notifyWas = '1';

    /** Ключі магазину й «шлюзу»: справжня криптографія на несправжніх ключах */
    private $shopKey = null;
    private string $shopCert = '';
    private $gateKey = null;
    private string $gateCert = '';

    /** Що пішло на шлюз останнім разом */
    private array $sent = [];

    public function run(): int
    {
        if (!extension_loaded('openssl')) { echo "  — немає openssl, пропускаємо\n"; return 0; }
        $stores = Catalog::stores();
        if (!$stores) { echo "  — немає активних магазинів, пропускаємо\n"; return 0; }
        $this->store = (int)$stores[0]['id'];

        if (!$this->makeKeys()) { echo "  — openssl не зміг згенерувати ключі, пропускаємо\n"; return 0; }

        $this->setUp();
        try {
            $this->testSignData();
            $this->testNotifyData();
            $this->testRefundData();
            $this->testSignVerify();
            $this->testMinor();
            $this->testRef();
            $this->testStart();
            $this->testNotifyPaid();
            $this->testNotifyTwice();
            $this->testForgedSignature();
            $this->testAmountMismatch();
            $this->testUnknownOrder();
            $this->testDeclined();
            $this->testParse();
            $this->testSync();
            $this->testHoldAndCapture();
            $this->testRefund();
            $this->testDisabled();
            $this->testEnvFollowsPayment();
            $this->testForeignProvider();
        } finally {
            $this->tearDown();
        }
        echo "\n" . ($this->fail === 0
            ? "УСЕ ДОБРЕ: {$this->pass} перевірок\n"
            : "ПРОВАЛЕНО: {$this->fail} з " . ($this->pass + $this->fail) . "\n");
        return $this->fail === 0 ? 0 : 1;
    }

    // ─────────────────────────────────────────────────────────────── оснастка

    /**
     * Дві пари ключів: наша (нею підписуємо запити) і «шлюзова» (нею
     * підписуються відповіді). Саме дві, а не одна: підміна їх місцями —
     * найпростіший спосіб зробити перевірку підпису безглуздою, і набір має
     * ловити такий обмін.
     */
    private function makeKeys(): bool
    {
        $conf = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        // Windows-XAMPP: openssl не знаходить свій openssl.cnf і не вміє
        // СТВОРЮВАТИ ключі (підписувати вже наявним — вміє). Та сама історія,
        // що й у WebPush, тож і лікування те саме
        if (@openssl_pkey_new($conf) === false) {
            while (openssl_error_string()) {}
            foreach ([getenv('OPENSSL_CONF') ?: null,
                      'C:/xampp/apache/conf/openssl.cnf',
                      'C:/xampp/php/extras/ssl/openssl.cnf'] as $cnf) {
                if (!$cnf || !is_file($cnf)) continue;
                if (@openssl_pkey_new($conf + ['config' => $cnf]) !== false) { $conf['config'] = $cnf; break; }
                while (openssl_error_string()) {}
            }
        }
        foreach (['shop', 'gate'] as $who) {
            $res = @openssl_pkey_new($conf);
            if (!$res) return false;
            // $conf передається і сюди: без нього на тому ж Windows-XAMPP
            // експорт тихо лишає порожній рядок, і набір падає далі, у місці,
            // яке про ключі вже нічого не каже
            $pem = '';
            if (!@openssl_pkey_export($res, $pem, null, $conf) || $pem === '') return false;
            $csr = @openssl_csr_new(['commonName' => $who . '.test'], $res, $conf);
            $crt = $csr ? @openssl_csr_sign($csr, null, $res, 365, $conf) : null;
            if (!$crt) return false;
            $certPem = '';
            if (!@openssl_x509_export($crt, $certPem) || $certPem === '') return false;
            if ($who === 'shop') { $this->shopKey = $pem; $this->shopCert = $certPem; }
            else { $this->gateKey = $pem; $this->gateCert = $certPem; }
        }
        return true;
    }

    private function setUp(): void
    {
        $this->notifyWas = (string)Settings::get('notify_all_enabled', '1');
        Settings::set('notify_all_enabled', '0');
        foreach (['acq_enabled', 'acq_env', 'acq_merchant_id', 'acq_terminal_id',
                  'acq_key', 'acq_cert', 'acq_hold', 'acq_auto_fiscal', 'acq_desc'] as $k) {
            $this->settingsWas[$k] = Settings::get($k, null);
        }
        Settings::set('acq_enabled', '1');
        Settings::set('acq_env', 'test');
        Settings::set('acq_merchant_id', '1752493');
        Settings::set('acq_terminal_id', 'E7880293');
        Settings::set('acq_key', $this->shopKey);
        Settings::set('acq_cert', $this->gateCert);
        Settings::set('acq_hold', '0');
        // Чеки — предмет окремого набору. Тут вони лише додали б звернень до
        // каси, якої в тесті немає, і жодної перевірки не посилили б.
        Settings::set('acq_auto_fiscal', '0');
        Settings::set('acq_desc', '');

        $cat = (int)(DB::val('SELECT id FROM categories ORDER BY id LIMIT 1') ?? 0);
        $this->product = DB::insert('products', [
            'category_id' => $cat, 'name' => 'Тест: мед для оплати',
            'slug' => 'test-pay-' . bin2hex(random_bytes(3)),
            'sku' => 'TEST-PAY-1', 'base_price' => 250, 'active' => 1, 'made_to_order' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::insert('store_stock', ['product_id' => $this->product, 'variant_id' => null,
                                   'store_id' => $this->store, 'qty' => 100]);
    }

    private function tearDown(): void
    {
        Acquiring::$transport = null;
        foreach ($this->parentIds as $pid) {
            DB::delete('payments', 'parent_id = ?', [$pid]);
            foreach (DB::all('SELECT id FROM orders WHERE parent_id = ? OR id = ?', [$pid, $pid]) as $o) {
                DB::delete('order_items', 'order_id = ?', [(int)$o['id']]);
            }
            DB::delete('order_events', 'parent_id = ?', [$pid]);
            DB::delete('orders', 'parent_id = ?', [$pid]);
            DB::delete('orders', 'id = ?', [$pid]);
        }
        if ($this->product) {
            DB::delete('store_stock', 'product_id = ?', [$this->product]);
            DB::delete('products', 'id = ?', [$this->product]);
        }
        foreach ($this->settingsWas as $k => $v) Settings::set($k, $v ?? '');
        Settings::set('notify_all_enabled', $this->notifyWas);
    }

    private function ok(string $what, bool $cond): void
    {
        if ($cond) { $this->pass++; echo "  ok   $what\n"; }
        else { $this->fail++; echo "  FAIL $what\n"; }
    }

    private function group(string $name): void { echo "\n== $name ==\n"; }

    /** Замовлення на вказану суму */
    private function order(float $price, int $qty = 1): array
    {
        $p = DB::row('SELECT * FROM products WHERE id = ?', [$this->product]);
        $sum = round($price * $qty, 2);
        $placed = OrderFlow::place([
            'number' => 'PAY-' . bin2hex(random_bytes(3)), 'token' => bin2hex(random_bytes(16)),
            'user_id' => null, 'name' => 'Покупець', 'phone' => '+380670000001', 'email' => null,
            'delivery' => 'pickup', 'city' => null, 'np_office' => null, 'address' => null,
            'comment' => null, 'store_id' => $this->store,
            'source' => 'site', 'created_by_user_id' => null,
            'status' => 'new', 'promo_code' => null,
            'subtotal' => $sum, 'discount' => 0, 'total' => $sum, 'created_at' => now(),
        ], [['product' => $p, 'variant' => null, 'qty' => $qty, 'price' => $price, 'old' => null, 'sum' => $sum]],
           $this->store);
        $this->parentIds[] = (int)$placed['id'];
        return OrderFlow::order((int)$placed['id']);
    }

    /**
     * Відповідь шлюзу, підписана «його» ключем.
     *
     * Складається з тих самих полів, які шлюз повертає насправді, — інакше
     * перевірка підпису доводила б лише те, що ми вміємо підписати власну
     * вигадку.
     */
    private function notifyPost(array $payment, string $code = '000', array $over = []): array
    {
        $post = array_merge([
            'MerchantID' => (string)$payment['merchant_id'],
            'TerminalID' => (string)$payment['terminal_id'],
            'TotalAmount' => (string)Acquiring::minor((float)$payment['amount']),
            'Currency' => (string)$payment['currency'],
            'PurchaseTime' => (string)$payment['purchase_time'],
            'OrderID' => (string)$payment['order_ref'],
            'XID' => '23030319-718559',
            'SD' => '',
            'ApprovalCode' => $code === '000' ? '748063' : '',
            'Rrn' => $code === '000' ? '306219798564' : '',
            'ProxyPan' => '499999******0011',
            'TranCode' => $code,
        ], $over);
        if (!empty($payment['hold'])) $post['Delay'] = '1';

        $data = Acquiring::notifyData($post);
        $sig = '';
        openssl_sign($data, $sig, $this->gateKey, OPENSSL_ALGO_SHA512);
        $post['Signature'] = base64_encode($sig);
        return $post;
    }

    /** Шлюз, який відповідає сторінкою з прихованими полями */
    private function answering(array $vals): void
    {
        $this->sent = [];
        Acquiring::$transport = function (array $req) use ($vals) {
            $this->sent = $req;
            $html = '<html><body><form>';
            foreach ($vals as $k => $v) $html .= "<input type='hidden' name='$k' value='$v' />";
            return ['ok' => true, 'body' => $html . '</form></body></html>', 'error' => ''];
        };
    }

    // ─────────────────────────────────────────────────────────────── підпис

    /**
     * Формат рядка для підпису.
     *
     * Правило UPC: крапок з комою завжди стільки ж, а необов'язкові поля
     * (Delay, AltCurrency, AltAmount) туляться до сусіда через кому — і без
     * них кома теж зникає. Саме тут з'являється код 405 «шлюз не прийняв
     * підпис», і саме його найважче знайти на робочому сайті.
     */
    private function testSignData(): void
    {
        $this->group('рядок для підпису платежу');
        $base = ['merchant_id' => '1752493', 'terminal_id' => 'E7880293',
                 'purchase_time' => '240101120000', 'order_ref' => 'BOFU-1',
                 'currency' => '980', 'amount_minor' => 12550, 'sd' => ''];

        $this->ok('звичайний платіж — без зайвих ком',
            Acquiring::payData($base) === '1752493;E7880293;240101120000;BOFU-1;980;12550;;');

        $this->ok('преавторизація дописує Delay через кому після OrderID',
            Acquiring::payData($base + ['hold' => true])
                === '1752493;E7880293;240101120000;BOFU-1,1;980;12550;;');

        $this->ok('SessionData стає на своє місце, не зсуваючи решту',
            Acquiring::payData(array_merge($base, ['sd' => 'abc123']))
                === '1752493;E7880293;240101120000;BOFU-1;980;12550;abc123;');

        $this->ok('Ref3 дописується в кінець окремим полем',
            Acquiring::payData(array_merge($base, ['ref3' => 'X-1']))
                === '1752493;E7880293;240101120000;BOFU-1;980;12550;;X-1;');

        // Порожня друга валюта не має лишати кому: «980,» шлюз читає як
        // зіпсований рядок, і відмова приходить уже на живій оплаті
        $this->ok('порожня альтернативна валюта коми не лишає',
            Acquiring::payData(array_merge($base, ['alt_currency' => '', 'alt_amount' => '']))
                === '1752493;E7880293;240101120000;BOFU-1;980;12550;;');
    }

    private function testNotifyData(): void
    {
        $this->group('рядок для перевірки відповіді');
        $post = [
            'MerchantID' => '1752493', 'TerminalID' => 'E7880293', 'PurchaseTime' => '240101120000',
            'OrderID' => 'BOFU-1', 'XID' => '23030319-718559', 'Currency' => '980',
            'TotalAmount' => '12550', 'SD' => '', 'TranCode' => '000', 'ApprovalCode' => '748063',
        ];
        $this->ok('XID усередині, TranCode і ApprovalCode у кінці',
            Acquiring::notifyData($post)
                === '1752493;E7880293;240101120000;BOFU-1;23030319-718559;980;12550;;000;748063;');

        // Delay=0 — це «звичайний платіж», а не «преавторизація з нулем»:
        // дописати кому тут означало б не зійтися підписом на кожній оплаті
        $this->ok('Delay=0 у рядок не потрапляє',
            Acquiring::notifyData($post + ['Delay' => '0'])
                === Acquiring::notifyData($post));

        $this->ok('Delay=1 дописується комою після OrderID',
            str_contains(Acquiring::notifyData($post + ['Delay' => '1']), 'BOFU-1,1;'));
    }

    /**
     * Повернення й реверсал відрізняються одним полем, і плутати їх не можна:
     * повне повернення суми не передає взагалі, часткове — передає.
     */
    private function testRefundData(): void
    {
        $this->group('повернення');
        $order = $this->order(100.00);
        $res = Acquiring::start($order);
        $p = $res['payment'];
        Acquiring::apply($p, $this->notifyPost($p));
        $p = Acquiring::byId((int)$p['id']);

        $this->answering(['TranCode' => '000', 'CardScheme' => 'VISA']);
        $r = Acquiring::refund($p, null);
        $this->ok('повне повернення прийняте', $r['ok']);
        $this->ok('повне повернення йде без RefundAmount',
            !isset($this->sent['fields']['RefundAmount']));
        $this->ok('повне повернення закриває платіж',
            (string)Acquiring::byId((int)$p['id'])['status'] === 'refunded');
        $this->ok('позначка про оплату знімається з замовлення',
            (string)(OrderFlow::order((int)$order['id'])['paid_at'] ?? '') === '');

        // Часткове — з іншого платежу: попередній уже повернений повністю
        $order2 = $this->order(100.00);
        $p2 = Acquiring::start($order2)['payment'];
        Acquiring::apply($p2, $this->notifyPost($p2));
        $p2 = Acquiring::byId((int)$p2['id']);

        $this->answering(['TranCode' => '000']);
        $r2 = Acquiring::refund($p2, 30.00);
        $this->ok('часткове повернення прийняте', $r2['ok']);
        $this->ok('часткове повернення передає RefundAmount у копійках',
            ($this->sent['fields']['RefundAmount'] ?? '') === '3000');
        $fresh = Acquiring::byId((int)$p2['id']);
        $this->ok('решта суми лишається оплаченою',
            (string)$fresh['status'] === 'paid' && abs((float)$fresh['refunded'] - 30.0) < 0.005);
        $this->ok('після часткового повернення замовлення лишається оплаченим',
            (string)(OrderFlow::order((int)$order2['id'])['paid_at'] ?? '') !== '');

        $over = Acquiring::refund($fresh, 500.00);
        $this->ok('повернути більше, ніж лишилось, не дає', !$over['ok']);
    }

    /**
     * Криптографія по-справжньому: підписуємо своїм ключем, перевіряємо
     * «шлюзовим». Головне тут — не «підпис сходиться», а що чужий ключ НЕ
     * проходить: інакше вся перевірка відповіді нічого не варта.
     */
    private function testSignVerify(): void
    {
        $this->group('підпис і перевірка');
        $sig = Acquiring::sign('bofu-test-data');
        $this->ok('підпис формується й кодується в base64',
            $sig !== '' && base64_decode($sig, true) !== false);

        $good = '';
        openssl_sign('дані відповіді', $good, $this->gateKey, OPENSSL_ALGO_SHA512);
        $this->ok('підпис шлюзу приймається', Acquiring::verify('дані відповіді', base64_encode($good)));
        $this->ok('той самий підпис під іншими даними не приймається',
            !Acquiring::verify('інші дані', base64_encode($good)));

        // Підписано НАШИМ ключем, а не шлюзовим — так виглядала б спроба
        // видати себе за шлюз, маючи доступ лише до нашої половини
        $wrong = '';
        openssl_sign('дані відповіді', $wrong, $this->shopKey, OPENSSL_ALGO_SHA512);
        $this->ok('підпис чужим ключем не приймається',
            !Acquiring::verify('дані відповіді', base64_encode($wrong)));

        $this->ok('сміття замість підпису не приймається',
            !Acquiring::verify('дані відповіді', 'не-підпис'));
    }

    private function testMinor(): void
    {
        $this->group('копійки');
        $cases = [[125.50, 12550], [0.01, 1], [1234.56, 123456], [999.99, 99999], [100.0, 10000]];
        foreach ($cases as [$grn, $kop]) {
            $this->ok("$grn грн = $kop коп.", Acquiring::minor($grn) === $kop);
        }
        // Класична пастка float: 0.1+0.2 і копійки, які «майже» ціле число
        $this->ok('сума, зібрана з дробів, не втрачає копійки',
            Acquiring::minor(0.1 + 0.2) === 30);
    }

    private function testRef(): void
    {
        $this->group('номер оплати');
        $this->ok('перша спроба — це номер замовлення без додатків',
            Acquiring::makeRef('BOFU-260801-A3F2', 1) === 'BOFU-260801-A3F2');
        $this->ok('друга спроба відрізняється від першої',
            Acquiring::makeRef('BOFU-260801-A3F2', 2) === 'BOFU-260801-A3F2-2');
        $this->ok('довжина не перевищує 20 символів, які приймає шлюз',
            mb_strlen(Acquiring::makeRef('BOFU-260801-A3F2', 12)) <= 20);
    }

    // ─────────────────────────────────────────────────────────────── платіж

    private function testStart(): void
    {
        $this->group('початок оплати');
        $order = $this->order(125.50);
        $res = Acquiring::start($order);
        $this->ok('оплата починається', $res['ok']);

        $f = $res['fields'];
        $this->ok('сума йде в копійках', ($f['TotalAmount'] ?? '') === '12550');
        $this->ok('валюта — гривня (980)', ($f['Currency'] ?? '') === '980');
        $this->ok('версія протоколу — 1', ($f['Version'] ?? '') === '1');
        $this->ok('мова сторінки оплати — українська', ($f['locale'] ?? '') === 'uk');
        $this->ok('номер оплати збігається з номером замовлення',
            ($f['OrderID'] ?? '') === (string)$order['number']);
        $this->ok('підпис у формі є', ($f['Signature'] ?? '') !== '');
        $this->ok('преавторизації немає, поки її не ввімкнули', !isset($f['Delay']));
        $this->ok('телефон покупця розкладено на код країни й номер',
            ($f['phoneCountryCode'] ?? '') === '380' && ($f['phoneNumber'] ?? '') === '670000001');
        $this->ok('призначення платежу називає номер замовлення',
            str_contains($f['PurchaseDesc'] ?? '', (string)$order['number']));
        $this->ok('форма йде на тестовий шлюз',
            str_starts_with((string)$res['action'], Acquiring::BASE['test']));

        // Підпис має покривати рівно те, що поїхало у формі: інакше шлюз
        // порахує його від власних значень і відмовить
        $data = Acquiring::payData([
            'merchant_id' => $f['MerchantID'], 'terminal_id' => $f['TerminalID'],
            'purchase_time' => $f['PurchaseTime'], 'order_ref' => $f['OrderID'],
            'currency' => $f['Currency'], 'amount_minor' => $f['TotalAmount'], 'sd' => '',
        ]);
        $raw = base64_decode($f['Signature'], true);
        $this->ok('підпис перевіряється сертифікатом магазину',
            openssl_verify($data, $raw, $this->shopCert, OPENSSL_ALGO_SHA512) === 1);

        // Друга спроба після невдалої першої — інший номер, інакше шлюз
        // відмовить кодом 412
        Acquiring::apply($res['payment'], $this->notifyPost($res['payment'], '116'));
        $again = Acquiring::start(OrderFlow::order((int)$order['id']));
        $this->ok('друга спроба отримує інший номер оплати',
            $again['ok'] && $again['fields']['OrderID'] !== $f['OrderID']);
        $this->ok('перша спроба лишається в журналі',
            count(Acquiring::forParent((int)$order['id'])) === 2);
    }

    private function testNotifyPaid(): void
    {
        $this->group('оплата пройшла');
        $order = $this->order(250.00);
        $p = Acquiring::start($order)['payment'];

        $res = Acquiring::handleNotify($this->notifyPost($p), '217.13.180.171');
        $this->ok('шлюзу відповідаємо згодою', str_contains($res['body'], 'Response.action=approve'));
        $this->ok('відповідь містить номер оплати', str_contains($res['body'], 'OrderID=' . $p['order_ref']));

        $fresh = Acquiring::byId((int)$p['id']);
        $this->ok('платіж позначено оплаченим', (string)$fresh['status'] === 'paid');
        $this->ok('код авторизації збережено', (string)$fresh['approval_code'] === '748063');
        $this->ok('RRN збережено — без нього неможливе повернення', (string)$fresh['rrn'] === '306219798564');
        $this->ok('маскований номер картки збережено', (string)$fresh['proxy_pan'] === '499999******0011');
        $this->ok('підпис у журнал відповіді не потрапляє',
            !str_contains((string)$fresh['raw'], 'Signature'));

        $parent = OrderFlow::order((int)$order['id']);
        $this->ok('замовлення позначене оплаченим', (string)($parent['paid_at'] ?? '') !== '');
        $this->ok('спосіб розрахунку — картка', (string)($parent['payment_kind'] ?? '') === 'card');
        $this->ok('у стрічці подій є запис про оплату',
            (bool)DB::val("SELECT COUNT(*) FROM order_events WHERE parent_id = ? AND message LIKE '%карткою%'",
                [(int)$order['id']]));
    }

    /**
     * Повтор.
     *
     * Шлюз надсилає сповіщення ще раз, коли не впевнений, що ми його почули.
     * Друге зарахування коштувало б другого фіскального чека на той самий
     * продаж — і повертати його довелось би руками, чеком повернення.
     */
    private function testNotifyTwice(): void
    {
        $this->group('повторне сповіщення');
        $order = $this->order(80.00);
        $p = Acquiring::start($order)['payment'];
        $post = $this->notifyPost($p);

        Acquiring::handleNotify($post, '217.13.180.171');
        $first = (string)OrderFlow::order((int)$order['id'])['paid_at'];
        $events = (int)DB::val('SELECT COUNT(*) FROM order_events WHERE parent_id = ?', [(int)$order['id']]);

        Acquiring::handleNotify($post, '217.13.180.171');
        $this->ok('час оплати не переписується',
            (string)OrderFlow::order((int)$order['id'])['paid_at'] === $first);
        $this->ok('другого запису в стрічці не з\'являється',
            (int)DB::val('SELECT COUNT(*) FROM order_events WHERE parent_id = ?', [(int)$order['id']]) === $events);
        $this->ok('спроба оплати лишається однією',
            count(Acquiring::forParent((int)$order['id'])) === 1);
    }

    /**
     * Підроблене сповіщення.
     *
     * Адреса /pay/notify відкрита назовні — інакше шлюз до неї не достукається.
     * Тому єдине, що відрізняє шлюз від будь-кого іншого, — підпис.
     */
    private function testForgedSignature(): void
    {
        $this->group('підроблене сповіщення');
        $order = $this->order(500.00);
        $p = Acquiring::start($order)['payment'];

        $post = $this->notifyPost($p);
        $post['Signature'] = base64_encode('підробка');
        $res = Acquiring::handleNotify($post, '203.0.113.7');

        $this->ok('шлюзу відповідаємо скасуванням', str_contains($res['body'], 'Response.action=reverse'));
        $this->ok('платіж не став оплаченим',
            !in_array((string)Acquiring::byId((int)$p['id'])['status'], ['paid', 'held'], true));
        /*
         * І стан платежу теж не змінився.
         *
         * Раніше тут очікувалось 'failed' — підроблене сповіщення позначало
         * спробу невдалою. Це був єдиний спосіб змінити стан платежу без
         * жодної автентифікації: номер оплати збігається з номером замовлення,
         * тобто підбирається. Грошей це не крало й полагодилось би справжнім
         * NOTIFY, але продавець тим часом бачив у картці «оплата не пройшла».
         *
         * Тепер стан платежу міняє лише те, що доведено підписом, тож спроба
         * лишається такою, якою була, — 'sent'. Невдала оплата й далі стає
         * 'failed', але з відповіді шлюзу, а не з чийогось POST (див. нижче
         * набір про відмову банку).
         */
        $this->ok('стан платежу не змінився від підробки',
            (string)Acquiring::byId((int)$p['id'])['status'] === 'sent');
        $this->ok('замовлення НЕ позначене оплаченим',
            (string)(OrderFlow::order((int)$order['id'])['paid_at'] ?? '') === '');
    }

    /**
     * Правильний підпис, але чужа сума.
     *
     * Так виглядав би платіж, підписаний шлюзом для іншого замовлення й
     * підставлений сюди. Підпис зійдеться — а гроші будуть не ті.
     */
    private function testAmountMismatch(): void
    {
        $this->group('сума не збігається');
        $order = $this->order(300.00);
        $p = Acquiring::start($order)['payment'];

        $post = $this->notifyPost($p, '000', ['TotalAmount' => '100']);
        $res = Acquiring::handleNotify($post, '217.13.180.171');
        $this->ok('оплату на чужу суму скасовуємо', str_contains($res['body'], 'Response.action=reverse'));
        $this->ok('замовлення не стає оплаченим',
            (string)(OrderFlow::order((int)$order['id'])['paid_at'] ?? '') === '');
        $this->ok('причина записана в платіж',
            str_contains((string)Acquiring::byId((int)$p['id'])['error'], 'сума'));
    }

    private function testUnknownOrder(): void
    {
        $this->group('невідомий платіж');
        $res = Acquiring::handleNotify(['OrderID' => 'ЩОСЬ-ЧУЖЕ', 'TranCode' => '000'], '217.13.180.171');
        $this->ok('гроші за чуже замовлення просимо повернути',
            str_contains($res['body'], 'Response.action=reverse'));
    }

    private function testDeclined(): void
    {
        $this->group('відмова банку');
        $order = $this->order(60.00);
        $p = Acquiring::start($order)['payment'];

        Acquiring::handleNotify($this->notifyPost($p, '116'), '217.13.180.171');
        $fresh = Acquiring::byId((int)$p['id']);
        $this->ok('платіж позначено невдалим', (string)$fresh['status'] === 'failed');
        $this->ok('код відмови перекладено для покупця',
            str_contains((string)$fresh['error'], 'Недостатньо коштів'));
        $this->ok('замовлення лишається неоплаченим',
            (string)(OrderFlow::order((int)$order['id'])['paid_at'] ?? '') === '');
        $this->ok('невідомий код не губиться, а називається числом',
            str_contains(Acquiring::codeLabel('777'), '777'));
    }

    /**
     * Шлюз відповідає то сторінкою з прихованими полями, то простими рядками —
     * залежно від налаштувань терміналу. Не вгадати наперед, тож приймаємо оба.
     */
    private function testParse(): void
    {
        $this->group('розбір відповіді шлюзу');
        $html = "<html><body><form name='back' action='/x' method='POST'>"
              . "<input type='hidden' name='TranCode' value='000' />"
              . "<input type=\"hidden\" name=\"ApprovalCode\" value=\"554632\" />"
              . "</form></body></html>";
        $a = Acquiring::parse($html);
        $this->ok('поля з HTML-форми читаються',
            ($a['TranCode'] ?? '') === '000' && ($a['ApprovalCode'] ?? '') === '554632');

        $b = Acquiring::parse("TranCode=000\nApprovalCode=554632\nRrn=7753335670\n");
        $this->ok('рядки «ключ=значення» читаються',
            ($b['TranCode'] ?? '') === '000' && ($b['Rrn'] ?? '') === '7753335670');

        $this->ok('порожня відповідь не ламає розбір', Acquiring::parse('') === []);
    }

    /**
     * Звірка.
     *
     * Потрібна саме там, де сповіщення не дійшло: покупець заплатив, а
     * замовлення виглядає неоплаченим. Результат має застосовуватись так само,
     * як сповіщення, — інакше звірка лише «показує», а не лікує.
     */
    private function testSync(): void
    {
        $this->group('звірка зі шлюзом');
        $order = $this->order(199.99);
        $p = Acquiring::start($order)['payment'];

        $this->ok('незавершена спроба потрапляє в чергу звірки',
            in_array((int)$p['id'], array_map(fn($r) => (int)$r['id'], Acquiring::due(100)), true));

        $this->answering(['XID' => 'X-1', 'TranCode' => '000', 'ApprovalCode' => '450482', 'Rrn' => '601411039294']);
        $r = Acquiring::sync($p);
        $this->ok('звірка підтверджує оплату', $r['ok']);
        $this->ok('запит іде на службову адресу шлюзу', ($this->sent['path'] ?? '') === '/go/service/01');
        $this->ok('у запиті — сума з оригінальної операції',
            ($this->sent['fields']['TotalAmount'] ?? '') === '19999');
        $this->ok('замовлення стає оплаченим після звірки',
            (string)(OrderFlow::order((int)$order['id'])['paid_at'] ?? '') !== '');
        $this->ok('звірений платіж із черги зникає',
            !in_array((int)$p['id'], array_map(fn($r) => (int)$r['id'], Acquiring::due(100)), true));
    }

    /**
     * Преавторизація.
     *
     * Заблоковані кошти — ще не виручка, і різниця тут не термінологічна:
     * відвантажити за ними можна, а вважати оплаченими для звітності — ні,
     * доки не зроблено списання.
     */
    private function testHoldAndCapture(): void
    {
        $this->group('блокування коштів');
        Settings::set('acq_hold', '1');
        $order = $this->order(400.00);
        $res = Acquiring::start($order);
        $this->ok('у запиті стоїть ознака преавторизації', ($res['fields']['Delay'] ?? '') === '1');

        $p = $res['payment'];
        Acquiring::handleNotify($this->notifyPost($p), '217.13.180.171');
        $held = Acquiring::byId((int)$p['id']);
        $this->ok('платіж у стані «кошти заблоковано»', (string)$held['status'] === 'held');
        $this->ok('замовлення все одно позначене оплаченим — гроші вже недоступні покупцю',
            (string)(OrderFlow::order((int)$order['id'])['paid_at'] ?? '') !== '');

        $this->answering(['TranCode' => '000']);
        $tooMuch = Acquiring::capture($held, 600.00);
        $this->ok('списати понад +20% не дає', !$tooMuch['ok']);

        $r = Acquiring::capture($held, 350.00);
        $this->ok('списання меншої суми проходить', $r['ok']);
        $this->ok('запит іде на адресу завершення преавторизації',
            ($this->sent['path'] ?? '') === '/go/capture');
        $done = Acquiring::byId((int)$p['id']);
        $this->ok('платіж стає оплаченим', (string)$done['status'] === 'paid');
        $this->ok('сума платежу — та, що списана', abs((float)$done['amount'] - 350.0) < 0.005);

        Settings::set('acq_hold', '0');
    }

    private function testRefund(): void
    {
        $this->group('повернення без реквізитів');
        $order = $this->order(70.00);
        $p = Acquiring::start($order)['payment'];
        // Оплата пройшла, але шлюз не назвав ні коду авторизації, ні RRN —
        // через API таке повернути неможливо, і сказати це треба до спроби
        Acquiring::apply($p, $this->notifyPost($p, '000', ['ApprovalCode' => '', 'Rrn' => '']));
        $r = Acquiring::refund(Acquiring::byId((int)$p['id']));
        $this->ok('без коду авторизації повернення не починається', !$r['ok']);
        $this->ok('пояснюємо, куди йти замість цього', str_contains($r['error'], 'банку'));
    }

    /**
     * Вимкнення оплати карткою.
     *
     * Власник має право передумати, і сайт після цього мусить працювати, а не
     * «майже працювати». Головна пастка тут не в тому, що зникне кнопка, а в
     * тому, ЩО ЛИШИТЬСЯ: оплати, початі за хвилину до вимкнення, і гроші,
     * прийняті вчора. Зупиняти треба нові спроби, а не вже проведені платежі —
     * інакше вимкнення оплати означало б заморожені чужі гроші.
     */
    private function testDisabled(): void
    {
        $this->group('вимкнена оплата карткою');
        $order = $this->order(150.00);
        $started = Acquiring::start($order);          // спроба почалась, поки оплата ще працювала
        $p = $started['payment'];

        Settings::set('acq_enabled', '0');
        $this->ok('оплата вважається вимкненою', !Acquiring::enabled());

        // Кнопки покупець не побачить, але посилання з листа в нього лишилось
        $blocked = Acquiring::start($this->order(90.00));
        $this->ok('нова спроба оплати не починається', !$blocked['ok']);
        $this->ok('покупцю сказано, що буде замість оплати',
            str_contains($blocked['error'], 'зателефонує'));

        // А ось це — найважливіше: гроші вже пішли з картки
        $res = Acquiring::handleNotify($this->notifyPost($p), '217.13.180.171');
        $this->ok('початій оплаті вимикач не заважає', str_contains($res['body'], 'Response.action=approve'));
        $this->ok('замовлення все одно позначене оплаченим',
            (string)(OrderFlow::order((int)$order['id'])['paid_at'] ?? '') !== '');

        // Повернення по вже проведеній оплаті теж мусить працювати
        $this->answering(['TranCode' => '000']);
        $back = Acquiring::refund(Acquiring::byId((int)$p['id']), 50.00);
        $this->ok('повернення по старій оплаті працює при вимкненому еквайрингу', $back['ok']);

        // Замовлення, які лишились чекати оплати, магазин має побачити списком
        $waiting = $this->order(120.00);
        DB::update('orders', ['payment_kind' => 'card'], 'id = ?', [(int)$waiting['id']]);
        $numbers = array_map(fn($o) => (string)$o['number'], Acquiring::pending());
        $this->ok('замовлення, що чекає оплати, потрапляє в список для дзвінка',
            in_array((string)$waiting['number'], $numbers, true));
        $this->ok('оплачене замовлення в цей список не потрапляє',
            !in_array((string)$order['number'], $numbers, true));

        Settings::set('acq_enabled', '1');
    }

    /**
     * Перемикання середовища (і, у майбутньому, постачальника).
     *
     * Платіж має питати той шлюз, який його прийняв. Інакше перший же перехід
     * із тестового шлюзу на робочий робить учорашні оплати незворотними:
     * робочий шлюз про них не знає й відповість «операцію не знайдено».
     */
    private function testEnvFollowsPayment(): void
    {
        $this->group('платіж памʼятає свій шлюз');
        $order = $this->order(210.00);
        $p = Acquiring::start($order)['payment'];
        $this->ok('платіж записав середовище, у якому створений', (string)$p['env'] === 'test');

        Settings::set('acq_env', 'prod');   // магазин перейшов на робочий шлюз
        $this->ok('поточне середовище змінилось', Acquiring::env() === 'prod');
        $this->ok('адреса платежу лишилась тестовою',
            Acquiring::baseFor($p) === Acquiring::BASE['test']);

        $this->answering(['TranCode' => '000', 'ApprovalCode' => '1', 'Rrn' => '2']);
        Acquiring::sync(Acquiring::byId((int)$p['id']));
        $this->ok('запит пішов на шлюз, який прийняв платіж',
            ($this->sent['base'] ?? '') === Acquiring::BASE['test']);

        Settings::set('acq_env', 'test');
    }

    /**
     * Платіж від постачальника, якого ця збірка більше не знає.
     *
     * Так виглядатиме історія після переходу на іншого еквайєра. Мовчки
     * надіслати такий запит «кудись» гірше, ніж відмовитись: у кращому разі
     * це помилка, у гіршому — операція над чужим платежем із тим самим номером.
     */
    private function testForeignProvider(): void
    {
        $this->group('чужий постачальник');
        $order = $this->order(100.00);
        $p = Acquiring::start($order)['payment'];
        Acquiring::apply($p, $this->notifyPost($p));
        DB::update('payments', ['provider' => 'liqpay'], 'id = ?', [(int)$p['id']]);
        $foreign = Acquiring::byId((int)$p['id']);

        $this->answering(['TranCode' => '000']);
        $this->sent = [];
        $r = Acquiring::refund($foreign, 10.00);
        $this->ok('повернення чужого платежу не робимо', !$r['ok']);
        $this->ok('до шлюзу при цьому не звертаємось', $this->sent === []);
        $this->ok('кажемо, де його шукати', str_contains($r['error'], 'кабінеті'));
        $this->ok('звірка такого платежу теж відмовляє', !Acquiring::sync($foreign)['ok']);
        $this->ok('назва постачальника не губиться',
            str_contains(Acquiring::providerLabel('liqpay'), 'liqpay'));
    }
}

return (new AcquiringTest())->run();
