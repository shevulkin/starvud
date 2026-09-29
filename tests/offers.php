<?php
/**
 * Торг: «моя ціна за стільки-то штук».  Запуск: php bin/cli.php test
 *
 * Доводимо чотири речі, кожна з яких коштує грошей, якщо зламається.
 *
 * Перше — межі входу. Пропозиція, нижча за підлогу, і пропозиція, не нижча за
 * вітринну ціну, до продавця не доходять: перша засмічує чергу, друга означає
 * заробіток на чужій описці.
 *
 * Друге — черга ходів. «Чий хід» вирішує все: без цієї перевірки покупець міг
 * би погодитись на власну ж пропозицію й вийти з ціною, якої ніхто не давав.
 *
 * Третє — межі самої угоди. Домовленість належить своєму власнику, живе до
 * свого строку й витрачається рівно один раз.
 *
 * Четверте — поведінка в кошику. Домовлена ціна не складається ні з чим і не
 * зливається з рядком того самого товару за звичайною ціною.
 */
declare(strict_types=1);

final class OffersTest
{
    private int $pass = 0;
    private int $fail = 0;
    private int $product = 0;
    private int $buyer = 0;
    private int $other = 0;
    private int $seller = 0;
    private string $notifyWas = '1';
    /** Торг у магазині може бути вимкнений — на час тесту вмикаємо */
    private string $offersWas = '1';
    private const LIST_PRICE = 500.0;

    public function run(): int
    {
        $this->setUp();
        try {
            $this->testLimits();
            $this->testRoundTrip();
            $this->testTurnOrder();
            $this->testMaxRounds();
            $this->testDealOwnership();
            $this->testCart();
            $this->testConsume();
            $this->testMaintain();
        } finally {
            $this->tearDown();
        }
        echo "\n" . ($this->fail === 0
            ? "УСЕ ДОБРЕ: {$this->pass} перевірок\n"
            : "ПРОВАЛЕНО: {$this->fail} з " . ($this->pass + $this->fail) . "\n");
        return $this->fail === 0 ? 0 : 1;
    }

    // ─────────────────────────────────────────────────────────────── межі входу

    private function testLimits(): void
    {
        $this->group('що взагалі не доходить до продавця');

        // Підлога — половина ціни за замовчуванням, тобто 250
        $low = Offers::propose($this->product, null, $this->buyer, 5, 100);
        $this->ok('занизька ціна не створює розмову', !$low['ok']);
        $this->ok('підлога в тексті відмови не називається',
            !str_contains($low['error'], '250'));

        $high = Offers::propose($this->product, null, $this->buyer, 5, self::LIST_PRICE);
        $this->ok('ціна не нижча за вітринну — теж відмова', !$high['ok']);

        $this->ok('жодного рядка після відмов не лишилось',
            (int)DB::val('SELECT COUNT(*) FROM offers WHERE product_id = ?', [$this->product]) === 0);

        $zero = Offers::propose($this->product, null, $this->buyer, 0, 400);
        $this->ok('кількість менша за одиницю — відмова', !$zero['ok']);

        // Товар, виведений із торгу карткою
        DB::update('products', ['bargain' => 0], 'id = ?', [$this->product]);
        Catalog::forgetCaches();
        $off = Offers::propose($this->product, null, $this->buyer, 5, 400);
        $this->ok('вимкнений у картці торг не приймає пропозицій', !$off['ok']);
        DB::update('products', ['bargain' => 1], 'id = ?', [$this->product]);
        Catalog::forgetCaches();
    }

    // ────────────────────────────────────────────────────────────── коло торгу

    private function testRoundTrip(): void
    {
        $this->group('коло: пропозиція → зустрічна → зустрічна → згода');

        $r = Offers::propose($this->product, null, $this->buyer, 10, 400, 'беру щомісяця');
        $this->ok('пропозицію прийнято', $r['ok']);
        $id = (int)$r['offer']['id'];
        $o = Offers::find($id);
        $this->ok('хід за продавцем', (string)$o['turn'] === 'seller' && $o['status'] === 'open');
        $this->ok('вітринну ціну збережено поруч', (float)$o['list_price'] === self::LIST_PRICE);
        $this->ok('коментар покупця лежить у ході розмови',
            str_contains((string)(Offers::rounds($id)[0]['note'] ?? ''), 'щомісяця'));

        // Друга пропозиція тієї ж людини про ту саму позицію не заводить
        // другої розмови — інакше в черзі лежали б однакові рядки
        $dup = Offers::propose($this->product, null, $this->buyer, 10, 390);
        $this->ok('поки хід за продавцем, покупець не ходить двічі', !$dup['ok']);
        $this->ok('другої розмови не зʼявилось',
            (int)DB::val('SELECT COUNT(*) FROM offers WHERE product_id = ?', [$this->product]) === 1);

        $c = Offers::counter($id, 20, 430, 'за 20 шт віддамо по 430', $this->seller);
        $this->ok('зустрічні умови прийнято', $c['ok']);
        $o = Offers::find($id);
        $this->ok('хід повернувся до покупця', (string)$o['turn'] === 'buyer');
        $this->ok('умови стали продавцевими', (int)$o['qty'] === 20 && (float)$o['price'] === 430.0);

        $b = Offers::propose($this->product, null, $this->buyer, 20, 415);
        $this->ok('покупець ходить своєю зустрічною', $b['ok']);
        $o = Offers::find($id);
        $this->ok('розмова та сама, не нова', (int)$b['offer']['id'] === $id);
        $this->ok('хід знову за продавцем', (string)$o['turn'] === 'seller');

        $a = Offers::accept($id, 'seller', $this->seller);
        $this->ok('продавець погодився', $a['ok']);
        $o = Offers::find($id);
        $this->ok('статус — домовились', $o['status'] === 'accepted');
        $this->ok('строк дії проставлено', !empty($o['expires_at'])
            && strtotime((string)$o['expires_at']) > time());
        $this->ok('ходи розмови збереглися всі', count(Offers::rounds($id)) === 4);

        $this->wipeOffers();
    }

    private function testTurnOrder(): void
    {
        $this->group('чий хід — те й вирішує');

        $r = Offers::propose($this->product, null, $this->buyer, 5, 400);
        $id = (int)$r['offer']['id'];

        $self = Offers::accept($id, 'buyer', null, $this->buyer);
        $this->ok('покупець не погоджується на власну пропозицію', !$self['ok']);
        $this->ok('статус не змінився', Offers::find($id)['status'] === 'open');

        Offers::counter($id, 5, 450, '', $this->seller);
        $foreign = Offers::accept($id, 'buyer', null, $this->other);
        $this->ok('чужу розмову погодити не можна', !$foreign['ok']);

        $mine = Offers::accept($id, 'buyer', null, $this->buyer);
        $this->ok('свою — можна, коли хід твій', $mine['ok']);
        $this->ok('домовились на умовах продавця',
            (float)Offers::find($id)['price'] === 450.0);

        $this->wipeOffers();
    }

    private function testMaxRounds(): void
    {
        $this->group('коло, яке не закривається, — не переговори');

        $r = Offers::propose($this->product, null, $this->buyer, 5, 400);
        $id = (int)$r['offer']['id'];
        // Добиваємо розмову до стелі ходів
        for ($i = 0; $i < 10 && (int)Offers::find($id)['rounds'] < Offers::MAX_ROUNDS; $i++) {
            Offers::counter($id, 5, 450 + $i, '', $this->seller);
            Offers::propose($this->product, null, $this->buyer, 5, 400 + $i);
        }
        $o = Offers::find($id);
        $this->ok('ходів рівно стільки, скільки дозволено', (int)$o['rounds'] === Offers::MAX_ROUNDS);

        $more = (string)$o['turn'] === 'seller'
            ? Offers::counter($id, 5, 440, '', $this->seller)
            : Offers::propose($this->product, null, $this->buyer, 5, 410);
        $this->ok('наступний хід уже неможливий', !$more['ok']);

        // Але погодитись або відмовитись можна завжди — інакше розмова зависла б
        $close = (string)$o['turn'] === 'seller'
            ? Offers::accept($id, 'seller', $this->seller)
            : Offers::accept($id, 'buyer', null, $this->buyer);
        $this->ok('погодитись після стелі ходів можна', $close['ok']);

        $this->wipeOffers();
    }

    private function testDealOwnership(): void
    {
        $this->group('угода: чия, доки й скільки разів');

        $r = Offers::propose($this->product, null, $this->buyer, 4, 400);
        $id = (int)$r['offer']['id'];
        Offers::accept($id, 'seller', $this->seller);

        $this->ok('власник бачить свою угоду', Offers::deal($id, $this->buyer) !== null);
        $this->ok('стороння людина — ні', Offers::deal($id, $this->other) === null);
        $this->ok('гість — ні', Offers::deal($id, null) === null);

        DB::update('offers', ['expires_at' => date('Y-m-d H:i:s', time() - 60)], 'id = ?', [$id]);
        $this->ok('протермінована угода не діє', Offers::deal($id, $this->buyer) === null);

        DB::update('offers', ['expires_at' => date('Y-m-d H:i:s', time() + 3600)], 'id = ?', [$id]);
        DB::update('products', ['active' => 0], 'id = ?', [$this->product]);
        $this->ok('знятий з продажу товар угоду теж закриває',
            Offers::deal($id, $this->buyer) === null);
        DB::update('products', ['active' => 1], 'id = ?', [$this->product]);

        $this->wipeOffers();
    }

    // ─────────────────────────────────────────────────────────────────── кошик

    private function testCart(): void
    {
        $this->group('кошик: домовлена ціна не складається ні з чим');

        $_SESSION['user_id'] = $this->buyer;
        Cart::clear();

        $r = Offers::propose($this->product, null, $this->buyer, 10, 400);
        $id = (int)$r['offer']['id'];
        Offers::accept($id, 'seller', $this->seller);

        Cart::add($this->product, null, 10, $id);
        Cart::add($this->product, null, 2);          // ще дві за звичайною ціною

        $rows = Cart::detailed();
        $this->ok('це два різні рядки, а не один', count($rows) === 2);

        $deal = null; $plain = null;
        foreach ($rows as $row) { if (!empty($row['offer_id'])) $deal = $row; else $plain = $row; }

        $this->ok('домовлений рядок іде за своєю ціною', $deal && (float)$deal['price'] === 400.0);
        $this->ok('кількість — та, про яку домовились', $deal && (int)$deal['qty'] === 10);
        $this->ok('стара ціна показує, від чого домовились',
            $deal && (float)$deal['old'] === self::LIST_PRICE);
        $this->ok('опт на домовлений рядок не діє', $deal && (float)$deal['wholesale'] === 0.0);
        $this->ok('решта йде за вітринною ціною',
            $plain && (float)$plain['price'] === self::LIST_PRICE);
        // Десять домовлених штук не мають підштовхувати ці дві до оптового
        // порогу: одна знижка не купує другу
        $this->ok('домовлені штуки не рахуються в оптовий поріг',
            $plain && (int)$plain['tier_qty'] === 2);

        // Кількість домовленої партії не міняється
        Cart::setQty((string)$deal['key'], 3);
        $again = Cart::detailed();
        $kept = null;
        foreach ($again as $row) if (!empty($row['offer_id'])) $kept = $row;
        $this->ok('кількість домовленої партії не правиться', $kept && (int)$kept['qty'] === 10);

        // Промокод не має чіпати домовлену позицію
        $promo = ['id' => 0, 'code' => 'TEST', 'percent' => 20.0,
                  'stackable' => 1, 'max_total_percent' => null];
        $totals = Cart::breakdown(null, $promo);
        $sums = [];
        foreach ($totals['rows'] as $row) $sums[!empty($row['offer_id']) ? 'deal' : 'plain'] = $row;
        $this->ok('промокод не знижує домовлену ціну',
            (float)($sums['deal']['promo_cut'] ?? -1) === 0.0);
        $this->ok('на звичайний рядок промокод діє',
            (float)($sums['plain']['promo_cut'] ?? 0) > 0);

        // Угода зникла — рядок теж має зникнути, і мовчки цього робити не можна
        DB::update('offers', ['status' => 'cancelled'], 'id = ?', [$id]);
        Cart::clear();
        $_SESSION['cart'] = [
            $this->product . ':0:o' . $id => ['product_id' => $this->product, 'variant_id' => null,
                                              'qty' => 10, 'offer_id' => $id],
        ];
        $this->resetCartCache();
        $this->ok('скасована угода прибирає рядок із кошика', Cart::items() === []);

        Cart::clear();
        $this->wipeOffers();
    }

    /**
     * Нагадування. Найдорожча помилка тут не «не нагадали», а «нагадали
     * вдруге»: розмова, що пише щогодини, це не турбота, це спам, і після
     * нього людина вимикає канал цілком.
     */
    private function testMaintain(): void
    {
        $this->group('мовчанка: нагадати один раз і сказати про закриття');

        // 1. Пропозиція чекає на продавця довше за обіцяний строк
        $r = Offers::propose($this->product, null, $this->buyer, 5, 400);
        $id = (int)$r['offer']['id'];
        $this->age($id, Offers::replyHours() + 1);

        $out = Offers::maintain();
        $this->ok('нагадали продавцю про зависле', $out['stalled'] === 1);
        $this->ok('мітку про нагадування проставлено', Offers::find($id)['reminded_at'] !== null);

        $again = Offers::maintain();
        $this->ok('удруге про те саме не нагадуємо', $again['stalled'] === 0);

        // Хід скидає відлік: після відповіді чекати починаємо заново
        Offers::counter($id, 5, 450, '', $this->seller);
        $this->ok('хід знімає мітку нагадування', Offers::find($id)['reminded_at'] === null);

        // 2. Тепер уже покупець мовчить на наші умови
        $this->age($id, Offers::replyHours() + 1);
        $out = Offers::maintain();
        $this->ok('нагадали покупцю про наші умови', $out['waiting'] === 1);

        // 3. Погодженій ціні лишились години
        Offers::accept($id, 'buyer', null, $this->buyer);
        DB::update('offers', [
            'expires_at' => date('Y-m-d H:i:s', time() + 60),
            'reminded_at' => null,
        ], 'id = ?', [$id]);
        $out = Offers::maintain();
        $this->ok('попередили, що ціна спливає', $out['expiring'] === 1);

        // 4. Строк вийшов — угода стає «lapsed», і про це кажуть уголос
        DB::update('offers', ['expires_at' => date('Y-m-d H:i:s', time() - 60)], 'id = ?', [$id]);
        $out = Offers::maintain();
        $this->ok('погоджена ціна, що згоріла, — це lapsed, а не expired',
            Offers::find($id)['status'] === 'lapsed');
        $this->ok('покупцю сказали про закриття', $out['closed'] === 1);
        $this->ok('мітку про сказане збережено', Offers::find($id)['notified_at'] !== null);

        $this->ok('удруге про закриття не пишемо', Offers::maintain()['closed'] === 0);

        // 5. Розмова, на яку ми так і не відповіли, — це вже expired
        $this->wipeOffers();
        $r2 = Offers::propose($this->product, null, $this->buyer, 5, 400);
        $id2 = (int)$r2['offer']['id'];
        DB::update('offers', ['expires_at' => date('Y-m-d H:i:s', time() - 60)], 'id = ?', [$id2]);
        Offers::maintain();
        $this->ok('розмова без відповіді — expired', Offers::find($id2)['status'] === 'expired');

        $this->wipeOffers();
    }

    /** Зістарити розмову: наче останній хід був стільки-то годин тому */
    private function age(int $offerId, int $hours): void
    {
        DB::update('offers', ['updated_at' => date('Y-m-d H:i:s', time() - $hours * 3600)],
            'id = ?', [$offerId]);
    }

    private function testConsume(): void
    {
        $this->group('угода витрачається рівно один раз');

        $r = Offers::propose($this->product, null, $this->buyer, 3, 400);
        $id = (int)$r['offer']['id'];
        Offers::accept($id, 'seller', $this->seller);

        Offers::consume([['offer_id' => $id]], 777);
        $o = Offers::find($id);
        $this->ok('статус — замовлено', $o['status'] === 'ordered');
        $this->ok('замовлення записане поруч', (int)$o['order_id'] === 777);
        $this->ok('після замовлення угода в кошик не кладеться',
            Offers::deal($id, $this->buyer) === null);

        // Друге замовлення тією ж угодою не переписує перше
        Offers::consume([['offer_id' => $id]], 888);
        $this->ok('витратити вдруге не можна', (int)Offers::find($id)['order_id'] === 777);

        $this->wipeOffers();
    }

    // ────────────────────────────────────────────────────────────── обслуговування

    private function setUp(): void
    {
        // Торг пише і продавцям, і покупцю — на тесті це полетіло б живим людям
        $this->notifyWas = (string)Settings::get('notify_all_enabled', '1');
        Settings::set('notify_all_enabled', '0');
        $this->offersWas = (string)Settings::get('offers_enabled', '1');
        Settings::set('offers_enabled', '1');
        if (!isset($_SESSION)) $_SESSION = [];

        $cat = (int)(DB::val('SELECT id FROM categories ORDER BY id LIMIT 1') ?? 0);
        $this->product = DB::insert('products', [
            'category_id' => $cat, 'name' => 'Тестовий мед (торг)',
            'slug' => 'test-offer-' . bin2hex(random_bytes(3)),
            'base_price' => self::LIST_PRICE, 'active' => 1,
            // «під замовлення» знімає питання залишків: тут перевіряється ціна,
            // а не склад — його межі має свій набір тестів
            'made_to_order' => 1, 'wholesale' => 0, 'bargain' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (['buyer', 'other', 'seller'] as $who) {
            $this->$who = DB::insert('users', [
                'name' => 'Тест торг ' . $who,
                'email' => 'offer-' . $who . '-' . bin2hex(random_bytes(3)) . '@bofu.local',
                'active' => 1, 'created_at' => now(),
            ]);
        }
    }

    private function tearDown(): void
    {
        Cart::clear();
        $this->wipeOffers();
        if ($this->product) DB::delete('products', 'id = ?', [$this->product]);
        foreach ([$this->buyer, $this->other, $this->seller] as $uid) {
            if (!$uid) continue;
            DB::delete('user_notify_prefs', 'user_id = ?', [$uid]);
            DB::delete('users', 'id = ?', [$uid]);
        }
        unset($_SESSION['user_id']);
        Settings::set('notify_all_enabled', $this->notifyWas);
        Settings::set('offers_enabled', $this->offersWas);
    }

    /** Розмови між перевірками не переносяться: кожна починає з чистого столу */
    private function wipeOffers(): void
    {
        foreach (DB::all('SELECT id FROM offers WHERE product_id = ?', [$this->product]) as $o) {
            DB::delete('offer_rounds', 'offer_id = ?', [(int)$o['id']]);
        }
        DB::delete('offers', 'product_id = ?', [$this->product]);
    }

    /**
     * Кошик нормалізує рядки один раз на запит і памʼятає це прапорцем.
     * У тесті «запитів» кілька, тож прапорець доводиться знімати руками.
     */
    private function resetCartCache(): void
    {
        $ref = new ReflectionProperty(Cart::class, 'normalized');
        $ref->setAccessible(true);
        $ref->setValue(null, false);
    }

    private function ok(string $what, bool $cond): void
    {
        if ($cond) { $this->pass++; echo "  ok   $what\n"; }
        else { $this->fail++; echo "  FAIL $what\n"; }
    }

    private function group(string $name): void { echo "\n== $name ==\n"; }
}

return (new OffersTest())->run();
