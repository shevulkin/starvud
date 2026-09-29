<?php
declare(strict_types=1);

namespace Controllers;

use DB, View, Cart, Csrf, Auth, Catalog, OrderFlow, Settings, AuthTokens, Newsletter, RateLimit, Addresses, Promo, Geo, Acquiring, Courses;

class Checkout
{
    public static function form(): never
    {
        if (!Cart::items()) redirect('/cart');
        // Рядки беремо з тих самих підсумків, що й числа внизу форми: інакше
        // список позицій і «до сплати» рахувалися б двома різними шляхами й
        // розійшлися б на першій же новій знижці.
        $totals = Cart::breakdown(null, self::promo());
        $rows = $totals['rows'];
        $stores = Catalog::stores();
        // Чого бракує в кожному магазині для самовивозу (з урахуванням варіанта).
        // Разом із тим, де цю позицію можна забрати натомість: людині, що вже
        // обрала самовивіз, сусідня точка майже завжди краща за очікування.
        $missing = [];
        foreach ($stores as $s) {
            $sid = (int)$s['id'];
            foreach ($rows as $r) {
                if ((int)($r['stock'][$sid] ?? 0) >= $r['qty']) continue;
                $title = $r['product']['name'] . ($r['variant'] ? ' — ' . $r['variant']['name'] : '');
                $alt = [];
                foreach ($stores as $o) {
                    $oid = (int)$o['id'];
                    if ($oid !== $sid && (int)($r['stock'][$oid] ?? 0) >= $r['qty']) {
                        $alt[] = $o['city'] ?: $o['name'];
                    }
                }
                $missing[$sid][] = $alt
                    ? $title . (count($alt) > 1 ? ' — є в інших точках: ' : ' — є в іншій точці: ') . implode(', ', $alt)
                    : $title . ' — немає в жодній точці';
            }
        }
        // Дані залогіненого покупця підставляємо самі: email приходить з Google,
        // телефон уже перевірений гейтом у App::run()
        $u = Auth::user();
        $email = $u && !str_ends_with((string)$u['email'], '.local') ? (string)$u['email'] : '';

        // Адреса підставляється, отримувач — ні: див. коментар у Addresses
        $addresses = Addresses::forUser(Auth::id());

        View::show('cart/checkout', [
            'rows' => $rows,
            'totals' => $totals,
            'stores' => $stores, 'missing' => $missing,
            // Карта самовивозу: питання «яка точка до мене ближча» списком назв
            // не вирішується, а саме його й ставлять, обираючи самовивіз
            // Посилання на маршрут до обраної точки самовивозу; карти немає
            'map_points' => Geo::points($stores),
            'promo' => self::promo(),
            'addresses' => $addresses,
            'sel' => $addresses[0] ?? null,   // основна (Addresses::forUser сортує її першою)
            'np_enabled' => Settings::get('np_api_key') !== null && Settings::get('np_api_key') !== '',
            // Оплата карткою показується, лише коли вона справді працює:
            // кнопка «Оплатити», що веде в помилку, коштує магазину дорожче,
            // ніж її відсутність — людина вже дійшла до останнього кроку
            'card_enabled' => Acquiring::enabled(),
            'card_test' => Acquiring::env() === 'test',
            // Самі лише курси — везти нічого, і весь блок доставки зайвий.
            // Вирішує вміст кошика, а не вибір покупця (див. Courses).
            'digital' => Courses::cartIsDigital($rows),
            'pre' => ['name' => $u['name'] ?? '', 'phone' => $u['phone'] ?? '', 'email' => $email],
            'subscribed' => Newsletter::isSubscribed($email ?: null),
            'page_title' => 'Оформлення замовлення — ' . cfg('app_name'),
        ]);
    }

    private static function promo(): ?array
    {
        return Promo::current(
            isset($_POST['promo_code']) ? Promo::fromInput($_POST['promo_code']) : null,
            Auth::id(), self::buyerPhone());
    }

    /**
     * Кого вважаємо «людиною» для ліміту «один раз на людину»: залогіненого —
     * за акаунтом, гостя — за номером із форми (у профілі номер уже перевірений).
     * Номер отримувача теж годиться: іншого сталого імені гостя ми не знаємо.
     */
    private static function buyerPhone(): ?string
    {
        $u = Auth::user();
        if ($u && !empty($u['phone'])) return (string)$u['phone'];
        return AuthTokens::normPhoneAny($_POST['phone'] ?? '');
    }

    /**
     * Кнопка «Застосувати» біля промокоду. Відповідає перерахованими сумами, а не
     * перезавантаженням сторінки: інакше все вже введене в форму (отримувач,
     * адреса) зникло б заради перевірки коду.
     */
    public static function applyPromo(): never
    {
        Csrf::verify();
        $posted = Promo::fromInput($_POST['promo_code'] ?? '');
        [$promo, $error] = Promo::apply($posted, Auth::id(), self::buyerPhone());
        $totals = Cart::breakdown(null, $promo);
        $rows = $totals['rows'];

        $items = [];
        foreach ($rows as $r) {
            $items[$r['key']] = [
                'sum' => price_fmt($r['final']),
                'old' => $r['cut'] > 0 ? price_fmt($r['sum']) : null,
            ];
        }
        json_response([
            'ok' => $promo !== null,
            'empty' => trim($posted) === '',
            'code' => $promo['code'] ?? '',
            'label' => $promo ? Promo::label($promo) : '',
            // причина відмови приходить з сервера: «вже використаний» і
            // «такого коду немає» — різні речі, і людина має бачити яка саме
            'error' => $error,
            // Код робочий, але ліг не на все або не повністю: інакше «ви
            // заощадили 0 грн» чи менша за очікувану сума виглядали б як
            // поломка, а не як умова коду.
            'note' => Promo::note($promo, $rows),
            // Саме промокодова частина: набори стоять у підсумках окремими
            // рядками й від застосування коду не змінюються.
            // Нульову знижку price_fmt показав би як «За запитом» — це про ціну, не про суму
            'discount' => $totals['promo_discount'] > 0 ? price_fmt($totals['promo_discount']) : '0 грн',
            'subtotal' => price_fmt($totals['subtotal']),
            'total' => price_fmt($totals['total']),
            'items' => $items,
        ]);
    }

    public static function submit(): never
    {
        Csrf::verify();
        if (!Cart::items()) redirect('/cart');
        // приманка для ботів: поле сховане стилями, людина його не заповнить
        if (trim($_POST['website'] ?? '') !== '') { flash('error', 'Не вдалося оформити замовлення.'); redirect('/checkout'); }
        RateLimit::guard('checkout', 15, 3600);

        $name = trim($_POST['name'] ?? '');
        // Замовлення без робочого телефону неможливе: номер нормалізуємо до +380XXXXXXXXX
        // (або міжнародного E.164) і зберігаємо вже у цьому вигляді
        $phone = AuthTokens::normPhoneAny($_POST['phone'] ?? '');
        $emailRaw = trim($_POST['email'] ?? '');
        $email = $emailRaw === '' ? null : Newsletter::normEmail($emailRaw);
        $delivery = $_POST['delivery'] ?? 'np';
        /*
         * Спосіб оплати.
         *
         * Приймаємо «карткою» лише тоді, коли еквайринг справді налаштований:
         * інакше підставлене в POST значення відправляло б покупця на сторінку
         * оплати, якої немає, — тобто ламало б замовлення саме після того, як
         * воно вже створене. Невідоме значення тихо стає оплатою при отриманні:
         * це поведінка за замовчуванням, а не помилка, про яку варто кричати.
         */
        $payOnline = ($_POST['payment'] ?? 'later') === 'card' && Acquiring::enabled();
        $errors = [];
        if (mb_strlen($name) < 2) $errors[] = 'Вкажіть ім\'я отримувача';
        if (!$phone) $errors[] = 'Вкажіть коректний номер телефону — без нього ми не зможемо підтвердити замовлення';
        if ($emailRaw !== '' && !$email) $errors[] = 'Email виглядає некоректним — виправте або залиште поле порожнім';
        if (!in_array($delivery, ['np', 'pickup', 'other'], true)) $delivery = 'other';

        /*
         * Самі лише курси — доставки немає, і вибору теж.
         *
         * Спосіб визначає ВМІСТ кошика, а не поле у формі: 'digital' немає
         * серед варіантів на екрані, і підставити його в POST для замовлення з
         * медом не вийде — так само, як не вийде обрати Нову Пошту для курсу.
         * Обидві підміни закінчуються тим, що вирішує сервер за кошиком.
         *
         * Ставимо ДО перевірок нижче, щоб вони не питали ані міста, ані
         * відділення, ані магазину: жодне з цих питань до відео не стосується.
         */
        if (Courses::cartIsDigital()) $delivery = 'digital';
        elseif ($delivery === 'digital') $delivery = 'np';

        // Нова Пошта: у відділення чи курʼєром. Ref-и приймаємо лише схожі на
        // справжні (НП роздає UUID) — підставлений рядок інакше ліг би в
        // накладну й відмовив би вже на створенні, коли виправляти пізно.
        $npType = ($_POST['np_type'] ?? 'warehouse') === 'courier' ? 'courier' : 'warehouse';
        $uuid = static fn($v) => preg_match('/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i', trim((string)$v))
            ? trim((string)$v) : null;
        $npRef = $uuid($_POST['city_ref'] ?? '');
        $npOfficeRef = $uuid($_POST['np_office_ref'] ?? '');
        $npStreetRef = $uuid($_POST['np_street_ref'] ?? '');
        if ($delivery === 'np') {
            if (trim($_POST['np_city'] ?? '') === '') $errors[] = 'Вкажіть місто доставки';
            if ($npType === 'courier') {
                if (trim($_POST['np_street'] ?? '') === '') $errors[] = 'Вкажіть вулицю доставки';
                if (trim($_POST['np_house'] ?? '') === '') $errors[] = 'Вкажіть номер будинку';
            } elseif (trim($_POST['np_office'] ?? '') === '') {
                $errors[] = 'Вкажіть відділення або поштомат';
            }
        }

        // Магазин впливає на ціну (store_prices + акції магазину), тож приймаємо лише
        // існуючий активний і лише для самовивозу — інакше id підбирається руками в POST
        $storeId = null;
        if ($delivery === 'pickup') {
            $sid = (int)($_POST['store_id'] ?? 0);
            if ($sid && DB::row('SELECT id FROM stores WHERE id = ? AND active = 1', [$sid])) $storeId = $sid;
            if (!$storeId) $errors[] = 'Оберіть магазин для самовивозу';
        }

        if ($errors) {
            flash('error', implode('. ', $errors));
            redirect('/checkout');
        }

        // Останнє слово за сервером: код міг вичерпатись, поки людина заповнювала
        // форму. Мовчки прибрати знижку не можна — вона підтверджує суму, яку
        // бачить, тож повертаємо на форму з поясненням і вже без коду.
        $postedCode = Promo::fromInput($_POST['promo_code'] ?? '');
        [$promo, $promoError] = Promo::apply($postedCode, Auth::id(), $phone);
        if ($promoError !== '' && trim($postedCode) !== '') {
            flash('error', $promoError . '. Перевірте суму й підтвердіть замовлення ще раз.');
            redirect('/checkout');
        }
        // Товар міг розібратись, поки людина заповнювала форму. Кажемо це до
        // оформлення й повертаємо в кошик — виправляти треба саме кількість.
        // Мовчки її не зменшуємо: чужий кошик правити не наша справа.
        $short = OrderFlow::unavailable(Cart::detailed($storeId));
        if ($short) {
            flash('error', 'На жаль, товару вже не вистачає. ' . OrderFlow::unavailableLine($short)
                . '. Змініть кількість у кошику або приберіть позицію.');
            redirect('/cart');
        }

        $totals = Cart::total($storeId, $promo);
        $number = OrderFlow::newNumber();
        // Адреса сторінки підтвердження: номер короткий і передбачуваний, тому
        // посилання йде за окремим випадковим токеном
        $token = bin2hex(random_bytes(16));

        // Замовлення завжди розкладається на підзамовлення по магазинах-виконавцях:
        // покупець бачить одне замовлення, кожен продавець — свою частину (див. OrderFlow).
        // Перевірка вище могла й не спіймати: між нею та транзакцією лишається
        // мить, у яку останню банку забирає хтось інший. Усередині place()
        // перевірка йде вже під блокуванням і кидає виняток — ловимо його тут,
        // щоб покупець побачив пояснення, а не сторінку помилки.
        try {
        $placed = OrderFlow::place([
            'number' => $number, 'token' => $token, 'user_id' => Auth::id(),
            'name' => $name, 'phone' => $phone, 'email' => $email,
            'delivery' => $delivery,
            // np_city, а не city: назва «city» вмикала автопідстановку адрес Chrome
            'city' => trim($_POST['np_city'] ?? '') ?: null,
            'np_office' => trim($_POST['np_office'] ?? '') ?: null,
            'address' => trim($_POST['address'] ?? '') ?: null,
            // Посилання на довідник НП. Без них накладну потім не створити:
            // API приймає Ref, а «Відділення №5» у місті буває не одне. Порожні
            // вони лишаються, коли людина вписала адресу руками, не обравши
            // підказку, — тоді відділення дообирає продавець у картці.
            'city_ref' => $npRef,
            'np_type' => $npType,
            'np_office_ref' => $npType === 'warehouse' ? $npOfficeRef : null,
            'np_street' => $npType === 'courier' ? (trim($_POST['np_street'] ?? '') ?: null) : null,
            'np_street_ref' => $npType === 'courier' ? $npStreetRef : null,
            'np_house' => $npType === 'courier' ? (trim($_POST['np_house'] ?? '') ?: null) : null,
            'np_flat' => $npType === 'courier' ? (trim($_POST['np_flat'] ?? '') ?: null) : null,
            'comment' => trim($_POST['comment'] ?? '') ?: null,
            'store_id' => $storeId,
            // Обраний спосіб розрахунку — не факт оплати: paid_at лишається
            // порожнім, доки гроші не прийшли. Продавець при цьому одразу
            // бачить у картці «картка, чекаємо», а не гадає, чому замовлення
            // висить без дзвінка.
            'payment_kind' => $payOnline ? 'card' : null,
            'status' => 'new', 'promo_code' => $promo['code'] ?? null,
            'subtotal' => $totals['subtotal'], 'discount' => $totals['discount'], 'total' => $totals['total'],
            'created_at' => now(),
        ], Cart::detailed($storeId), $storeId);
        } catch (\PDOException $e) {
            // PDOException — теж RuntimeException, але її текст (SQLSTATE, імена
            // колонок) покупцю не показуємо: у журнал, а людині — людське
            error_log('Checkout: ' . $e->getMessage());
            flash('error', 'Не вдалося оформити замовлення через технічну помилку. Спробуйте ще раз або зателефонуйте нам.');
            redirect('/checkout');
        } catch (\RuntimeException $e) {
            flash('error', $e->getMessage() . '. Змініть кількість у кошику або приберіть позицію.');
            redirect('/cart');
        }

        // Використання записуємо лише разом із замовленням — ліміти рахуються
        // по фактах покупок, а не по спробах ввести код у формі
        if ($promo) Promo::recordUse($promo, (int)$placed['id'], Auth::id(), $phone);

        // Адресу зберігаємо лише за явною галкою і лише залогіненим: гостю
        // її нікуди привʼязати. Самовивіз не зберігаємо — це адреса магазину.
        if (Auth::id() && $delivery !== 'pickup') {
            $addrId = (int)($_POST['address_id'] ?? 0);
            if (!empty($_POST['save_address'])) {
                // id не передаємо навмисно: правка збереженої адреси — дія кабінету.
                // Тут зміна відділення дає нову адресу, а незмінена просто не
                // дублюється (dedupe в Addresses::save) і піднімається в списку.
                Addresses::save(Auth::id(), [
                    'delivery' => $delivery,
                    'city' => $_POST['np_city'] ?? '',
                    'city_ref' => $npRef ?? '',
                    'np_office' => $_POST['np_office'] ?? '',
                    'np_office_ref' => $npOfficeRef ?? '',
                    'np_type' => $npType,
                    'np_street' => $_POST['np_street'] ?? '',
                    'np_street_ref' => $npStreetRef ?? '',
                    'np_house' => $_POST['np_house'] ?? '',
                    'np_flat' => $_POST['np_flat'] ?? '',
                    'address' => $_POST['address'] ?? '',
                ]);
            } elseif ($addrId) {
                Addresses::touch(Auth::id(), $addrId);
            }
        }

        // Розсилка — лише за явною галкою і лише коли вказано email
        if ($email) {
            Newsletter::apply(!empty($_POST['newsletter']), $email, $name, Auth::id(), 'checkout');
        }

        // Сповіщення йде окремо на кожен магазин — це його завдання, а не все замовлення
        foreach ($placed['children'] as $child) OrderFlow::notifyNew($child);

        Cart::clear();
        unset($_SESSION['promo_code']);
        /*
         * Оплата карткою — окремим кроком, а не редіректом просто на шлюз.
         *
         * Замовлення вже створене й товар за ним зарезервований: якщо покупець
         * закриє сторінку банку, продавець побачить неоплачене замовлення й
         * зателефонує, а не втратить його мовчки. Кошик при цьому чистимо в
         * обох випадках — повторне оформлення з нього створило б друге
         * замовлення на ті самі товари, а оплатити перше можна за посиланням.
         */
        redirect(($payOnline ? '/pay/' : '/order/success/') . $token);
    }

    /** Підтвердження замовлення — лише за токеном із редіректу, номер для цього не годиться */
    public static function success(string $token): never
    {
        $order = DB::row('SELECT * FROM orders WHERE token = ? AND parent_id IS NULL', [$token]);
        if (!$order) { flash('error', 'Замовлення не знайдено.'); redirect('/'); }
        $children = OrderFlow::children((int)$order['id']);
        View::show('cart/success', [
            'order' => $order,
            'children' => $children,
            'items' => self::itemsByOrder($children),
            // Для гостя ця сторінка — єдиний спосіб подивитись, де посилка:
            // кабінету в нього немає, а посилання з токеном лишається робочим.
            // Одразу після оформлення накладних ще немає — блок просто не
            // зʼявиться, а на повторному заході вже буде.
            'shipments' => \Shipments::forParent((int)$order['id']),
            // Оплата карткою: покупець має бачити не «замовлення прийнято»
            // взагалі, а що саме сталося з його грошима. Неоплачене замовлення
            // з обраною карткою лишає посилання на оплату — воно те саме, і
            // спробувати ще раз можна хоч наступного дня.
            'payment' => Acquiring::last((int)$order['id']),
            'card_enabled' => Acquiring::enabled(),
            'page_title' => 'Замовлення прийнято — ' . cfg('app_name'),
        ]);
    }

    /** Кабінет покупця: одне замовлення = одна картка, всередині — частини магазинів */
    public static function myOrders(): never
    {
        if (!Auth::check()) { flash('error', 'Увійдіть, щоб бачити свої замовлення.'); redirect('/'); }
        $orders = DB::all('SELECT * FROM orders WHERE user_id = ? AND parent_id IS NULL ORDER BY id DESC', [Auth::id()]);
        $children = []; $items = [];
        foreach ($orders as $o) {
            $kids = OrderFlow::children((int)$o['id']);
            $children[$o['id']] = $kids;
            $items += self::itemsByOrder($kids);
        }
        // Накладні одним запитом на всі замовлення: у людини їх бувають десятки,
        // і запит на кожне перетворив би сторінку історії на найповільнішу в кабінеті
        $shipments = [];
        foreach (\Shipments::forParents(array_column($orders, 'id')) as $rows) {
            foreach ($rows as $s) $shipments[(int)$s['order_id']] = $s;
        }
        View::show('account/orders', [
            'orders' => $orders, 'children' => $children, 'items' => $items,
            'shipments' => $shipments,
            // Кнопка «оплатити» біля неоплаченого замовлення показується, лише
            // поки оплата карткою справді працює: інакше вона вела б у глухий
            // кут людину, яка вже чекає на цю посилку
            'card_enabled' => Acquiring::enabled(),
            'page_title' => 'Мої замовлення — ' . cfg('app_name'),
        ]);
    }

    /** @return array<int,array> позиції, згруповані за id підзамовлення */
    private static function itemsByOrder(array $orders): array
    {
        $out = [];
        foreach ($orders as $o) $out[(int)$o['id']] = OrderFlow::items((int)$o['id']);
        return $out;
    }
}
