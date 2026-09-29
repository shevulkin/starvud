<?php
declare(strict_types=1);

/**
 * Портативна схема БД: генерує SQL для MySQL та SQLite з одного опису.
 * Типи: id, int, num(10,2), geo, str(255), text, bool, ts
 */
class Schema
{
    public const VERSION = 58;

    /** Оновлення існуючої бази до поточної версії без втрати даних */
    public static function upgrade(): void
    {
        $ver = (int)(Settings::get('schema_version', '1'));
        if ($ver >= self::VERSION) return;
        if ($ver < 2) {
            self::addColumn('users', 'viber_id', 'str null');
            self::addColumn('users', 'phone', 'str null');
            // нові таблиці створяться через createAll (IF NOT EXISTS)
            self::createAll();
            // демо-користувачам телефони, щоб гейт не блокував
            // (|| — конкатенація лише в SQLite, у MySQL це логічне «або»)
            $cat = DB::driver() === 'sqlite' ? "'+38067000000' || id" : "CONCAT('+38067000000', id)";
            DB::query("UPDATE users SET phone = $cat WHERE email LIKE '%@bofu.local' AND (phone IS NULL OR phone = '')");
            // правила сповіщень для каналу viber
            foreach (Notify::EVENTS as $event => $label) {
                $exists = DB::row('SELECT id FROM notification_rules WHERE event = ? AND channel = ?', [$event, 'viber']);
                if (!$exists) {
                    DB::insert('notification_rules', [
                        'event' => $event, 'channel' => 'viber', 'enabled' => 0,
                        'recipients' => 'admins_sellers', 'template' => Notify::DEFAULT_TEMPLATES[$event] ?? '',
                    ]);
                }
            }
        }
        if ($ver < 3) {
            // словник характеристик + звʼязки варіантів
            self::createAll();
            self::addColumn('product_attrs', 'attribute_id', 'int null');
            self::addColumn('product_attrs', 'value_id', 'int null');
            Attrs::backfill();
        }
        if ($ver < 4) {
            self::addColumn('products', 'low_stock_threshold', 'int null');
        }
        if ($ver < 5) {
            // наявність товарів з варіантами тепер рахується по варіантах у кожному магазині
            self::moveStockToVariants();
        }
        if ($ver < 6) {
            // згода на розсилку: окрема таблиця, бо підписатись може й гість без акаунта
            self::createAll();
        }
        if ($ver < 7) {
            // наслідок бага з `||` вище: у MySQL телефони демо-акаунтів записались як '1'.
            // Чистимо — гейт у App::run() змусить вказати справжній номер.
            self::fixBrokenPhones();
        }
        if ($ver < 8) {
            // сторінка «замовлення прийнято» більше не адресується номером,
            // який можна перебрати, — тільки випадковим токеном
            self::addColumn('orders', 'token', 'str null');
            self::createAll(); // rate_hits
            foreach (DB::all("SELECT id FROM orders WHERE token IS NULL OR token = ''") as $o) {
                DB::update('orders', ['token' => bin2hex(random_bytes(16))], 'id = ?', [$o['id']]);
            }
        }
        if ($ver < 9) {
            // після чистки в ver<7 демо-акаунти лишились без телефону й упирались у гейт —
            // повертаємо те, що мала зробити зламана міграція ver<2
            foreach (DB::all("SELECT id FROM users WHERE email LIKE '%@bofu.local' AND (phone IS NULL OR phone = '')") as $u) {
                DB::update('users', ['phone' => '+3806700000' . str_pad((string)$u['id'], 2, '0', STR_PAD_LEFT)], 'id = ?', [$u['id']]);
            }
        }
        if ($ver < 10) {
            // замовлення розділяється на підзамовлення по магазинах
            self::addColumn('orders', 'parent_id', 'int null');
            self::addColumn('orders', 'seq', 'int default 0');
            self::createAll(); // order_events
            self::splitLegacyOrders();
        }
        if ($ver < 11) {
            // ролі переїжджають в окрему таблицю: одна людина може мати їх кілька
            self::createAll(); // user_roles
            self::moveRolesToUserRoles();
        }
        if ($ver < 12) {
            // одна людина може працювати і як адмін, і як продавець точки —
            // без ролі запис «хто змінив статус» став неоднозначним
            self::addColumn('order_events', 'role', 'str null');
        }
        if ($ver < 13) {
            // хто взяв підзамовлення в роботу — мітка без замка, лише щоб було видно,
            // що воно не лежить без нагляду і що двоє не роблять те саме
            self::addColumn('orders', 'assigned_user_id', 'int null');
            self::addColumn('orders', 'assigned_at', 'str null');
        }
        if ($ver < 14) {
            // особисті налаштування сповіщень; порожньо = усе, що дозволив адмін
            self::createAll(); // user_notify_prefs
        }
        if ($ver < 15) {
            // Привʼязка до точки тепер живе лише разом із роллю продавця
            // (Controllers\Admin\Users::saveStores). Прибираємо рядки, що лишились
            // від тих, хто роль уже втратив: інакше правило діяло б тільки для
            // нових змін, а старі «вічні» доступи тихо пережили б його.
            DB::query('DELETE FROM seller_stores WHERE user_id NOT IN
                       (SELECT user_id FROM user_roles WHERE role = ?)', [Roles::SELLER]);
        }
        if ($ver < 16) {
            // Девʼять цифр із нулем попереду раніше давали '+380067…' — синтаксично
            // схоже на номер, тому в базу воно потрапляло мовчки. Проганяємо телефони
            // через виправлену нормалізацію: такі записи обнуляться, і гейт у App::run()
            // попросить людину вказати номер ще раз.
            self::fixBrokenPhones();
        }
        if ($ver < 17) {
            // Телефон стає унікальним. Перед тим розводимо наявні збіги: у
            // старших акаунтів номер лишається, у молодших — обнуляється, і
            // гейт попросить людину вказати свій. Втратити тут нічого: акаунт
            // із чужим номером усе одно не мав на нього права.
            self::dedupePhones();
            self::createAll();
        }
        if ($ver < 18) {
            // збережені адреси доставки покупця
            self::createAll(); // user_addresses
        }
        if ($ver < 19) {
            // звідки почався вхід — це показується людині в боті, коли вона
            // підтверджує «це я»: без такої деталі підтвердження перетворюється
            // на «натисніть ОК», а саме воно й має відрізняти свою спробу входу
            // від чужої. Старі токени лишаються без цих полів і живуть 15 хвилин.
            self::addColumn('auth_tokens', 'ip', 'str null');
            self::addColumn('auth_tokens', 'agent', 'str null');
        }
        if ($ver < 20) {
            // Телефон у сповіщенні про замовлення переїхав на власний рядок.
            // Правила зберігають свій текст копією, тож без цього оновлення
            // зміна дійшла б лише до нових установок. Переписуємо рівно ті,
            // де текст лишився типовим: правлений адміном — його справа.
            DB::query('UPDATE notification_rules SET template = ? WHERE event = ? AND template = ?', [
                Notify::DEFAULT_TEMPLATES['order_new'], 'order_new',
                "🛒 Нове замовлення {number}\nКлієнт: {name}, {phone}\nДоставка: {delivery}\nСума: {total} грн\nМагазин: {store}",
            ]);
        }
        if ($ver < 21) {
            // ліміти промокодів, сумування зі знижками + журнал використань
            self::addColumn('promo_codes', 'max_uses', 'int null');
            self::addColumn('promo_codes', 'per_user_limit', 'int null');
            self::addColumn('promo_codes', 'stackable', 'bool default 1');
            self::addColumn('promo_codes', 'max_total_percent', 'num null');
            // наявні коди працювали без обмежень — лишаємо їх такими явно
            DB::query('UPDATE promo_codes SET stackable = 1 WHERE stackable IS NULL');
            self::createAll(); // promo_uses
        }
        if ($ver < 22) {
            // Продаж понад залишок більше не мовчить: рахуємо, скільки з позиції
            // справді зняли зі складу. Старим замовленням лишаємо NULL — що там
            // сталося, ми вже не знаємо, і вигадувати числа гірше, ніж зізнатись.
            self::addColumn('order_items', 'stock_taken', 'int null');
            // Рядок про нестачу в шаблоні сповіщення. Правила тримають текст
            // копією, тож переписуємо рівно ті, де він лишився типовим.
            DB::query('UPDATE notification_rules SET template = ? WHERE event = ? AND template = ?', [
                Notify::DEFAULT_TEMPLATES['order_new'], 'order_new',
                "🛒 Нове замовлення {number}\nМагазин: {store}\n{items}\nСума: {total} грн\n"
                . "Доставка: {delivery}\n{address}\n{phone}\nКлієнт: {name}",
            ]);
        }
        if ($ver < 23) {
            self::createAll(); // stock_requests
            // Правила для двох нових подій. Продавцю про попит пишемо одразу,
            // покупцю про наявність — теж: обидві новини нікому не потрібні
            // через тиждень, тож вимкненими за замовчуванням їх робити нема сенсу.
            foreach (['stock_wanted' => 'sellers', 'stock_back' => 'customer'] as $event => $to) {
                foreach (array_keys(Notify::CHANNELS) as $channel) {
                    if (DB::row('SELECT id FROM notification_rules WHERE event = ? AND channel = ?', [$event, $channel])) continue;
                    DB::insert('notification_rules', [
                        'event' => $event, 'channel' => $channel,
                        // viber вимкнений так само, як його заводила міграція 2:
                        // канал є не в кожного магазину, а зайвий шум дратує
                        'enabled' => $channel === 'viber' ? 0 : 1,
                        'recipients' => $to, 'template' => Notify::DEFAULT_TEMPLATES[$event] ?? '',
                    ]);
                }
            }
        }
        if ($ver < 24) {
            // Покупець нарешті дізнається, що з його замовленням: досі всі
            // сповіщення йшли лише персоналу.
            foreach (array_keys(Notify::CHANNELS) as $channel) {
                if (DB::row('SELECT id FROM notification_rules WHERE event = ? AND channel = ?',
                    ['order_customer', $channel])) continue;
                DB::insert('notification_rules', [
                    'event' => 'order_customer', 'channel' => $channel,
                    'enabled' => $channel === 'viber' ? 0 : 1,
                    'recipients' => 'customer',
                    'template' => Notify::DEFAULT_TEMPLATES['order_customer'],
                ]);
            }
            // Форма в адмінці приймала лише групи персоналу, тож будь-яке
            // збереження правил мовчки перекидало адресні події на адмінів —
            // і покупець переставав отримувати те, що адресовано тільки йому.
            // Тепер форма їх не чіпає (Notify::isCustomerEvent), а тут
            // виправляємо вже зіпсоване.
            foreach (Notify::EVENTS as $event => $label) {
                if (!Notify::isCustomerEvent($event)) continue;
                DB::query('UPDATE notification_rules SET recipients = ? WHERE event = ?', ['customer', $event]);
            }
        }
        if ($ver < 25) {
            // Viber заводили вимкненим «щоб не шуміти», і власник цього не
            // просив — а бот у нього налаштований, і покупці ним користуються.
            // Вирівнюємо на Telegram: де подія йде в Telegram, туди ж і Viber.
            // Правила, які адмін уже ввімкнув сам, лишаються ввімкненими.
            foreach (DB::all('SELECT event FROM notification_rules WHERE channel = ? AND enabled = 1',
                     ['telegram']) as $r) {
                DB::query('UPDATE notification_rules SET enabled = 1 WHERE channel = ? AND event = ?',
                    ['viber', (string)$r['event']]);
            }
        }
        if ($ver < 26) {
            // Чий товар. Досі сайт про кожну позицію «під замовлення» писав
            // «ми виробник», хоча воскопрес і пошив костюма роблять інші.
            self::addColumn('products', 'brand', 'str null');
            // Свої підписуємо самі — за тим, що вже вказано в характеристиках:
            // «Походження: Власна пасіка» ставив власник, і це рівно та ознака.
            // Решта лишається порожньою: вгадувати чужі бренди не можна.
            $own = (int)(DB::val('SELECT id FROM attributes WHERE slug = ?', ['pokhodzhennia']) ?? 0);
            $rows = $own
                ? DB::all('SELECT product_id FROM product_attrs WHERE attribute_id = ? AND value LIKE ?',
                    [$own, 'Власна пасіка%'])
                : DB::all('SELECT product_id FROM product_attrs WHERE name = ? AND value LIKE ?',
                    ['Походження', 'Власна пасіка%']);
            foreach ($rows as $r) {
                DB::query('UPDATE products SET brand = ? WHERE id = ? AND (brand IS NULL OR brand = ?)',
                    [Catalog::ownBrand(), (int)$r['product_id'], '']);
            }
        }
        if ($ver < 27) {
            // Бренд стає довідником: сам по собі текст у картці не давав ні
            // списку, ні захисту від «SINCERA» проти «Sincera» в сусідніх позиціях.
            self::createAll(); // brands
            self::addColumn('products', 'brand_id', 'int null');
            foreach (DB::all("SELECT DISTINCT brand FROM products WHERE brand IS NOT NULL AND brand <> ''") as $r) {
                $name = trim((string)$r['brand']);
                if ($name === '') continue;
                $id = (int)(DB::val('SELECT id FROM brands WHERE name = ?', [$name]) ?? 0);
                if (!$id) {
                    $id = DB::insert('brands', [
                        'name' => $name, 'slug' => slugify($name) ?: 'brand-' . random_int(100, 999),
                        // свій бренд упізнаємо один раз — далі вирішує прапорець
                        'own' => mb_strtolower($name) === mb_strtolower(Catalog::ownBrandName()) ? 1 : 0,
                        'active' => 1, 'sort' => 0,
                    ]);
                }
                DB::query('UPDATE products SET brand_id = ? WHERE brand = ?', [$id, $name]);
            }
        }
        if ($ver < 28) {
            // Спільне виробництво: воскопрес роблять разом, і покупець має
            // знайти його і за нашим брендом, і за брендом партнера. Один
            // brand_id цього не вміє, а склеєна назва «A & B» не бренд:
            // за пошуком «Медоїжка» такий товар не знайдеться взагалі.
            self::createAll(); // product_brands
            foreach (DB::all('SELECT id, brand_id FROM products WHERE brand_id IS NOT NULL') as $p) {
                $exists = DB::row('SELECT id FROM product_brands WHERE product_id = ? AND brand_id = ?',
                    [(int)$p['id'], (int)$p['brand_id']]);
                if (!$exists) {
                    DB::insert('product_brands', ['product_id' => (int)$p['id'], 'brand_id' => (int)$p['brand_id']]);
                }
            }
        }
        if ($ver < 29) {
            // Бренд — не лише назва: покупцю, який уперше бачить «Медоїжка»,
            // потрібні лого й пояснення, хто це.
            self::addColumn('brands', 'logo', 'str null');
            self::addColumn('brands', 'description', 'text null');
        }
        if ($ver < 30) {
            // Продавець оформлює замовлення сам — по телефону або на місці.
            // Звідки воно взялося, видно з рядка, а не з здогадок: «клієнт не
            // відповідає» до замовлення, яке людина зробила стоячи біля каси,
            // не застосовне, а до телефонного — цілком.
            self::addColumn('orders', 'source', "str default 'site'");
            self::addColumn('orders', 'created_by_user_id', 'int null');
            // Усе, що вже є, прийшло з сайту: інших шляхів досі не було.
            DB::query("UPDATE orders SET source = 'site' WHERE source IS NULL OR source = ''");
        }
        if ($ver < 31) {
            // Штрихкод — окремо від артикулу, і це не дублювання. Артикул
            // придумуємо ми («MED-LIP-05»), штрихкод друкує виробник на
            // етикетці. Сканер шукає саме етикетку, а обліковець — свій код;
            // в одному полі вони рано чи пізно перетруть один одного.
            self::addColumn('products', 'barcode', 'str null');
            self::addColumn('product_variants', 'barcode', 'str null');
            self::createAll();   // індекси по sku/barcode: за ними шукає каса
        }
        if ($ver < 32) {
            // Координати точки — окремо від адреси, а не замість неї. Адресу
            // людина читає й називає таксисту; координати потрібні карті, і
            // виводити їх з адреси щоразу означало б ходити в чуже API за тим,
            // що не змінюється роками. Порожні координати — нормальний стан:
            // точка тоді просто не має мітки на карті.
            self::addColumn('stores', 'lat', 'geo null');
            self::addColumn('stores', 'lng', 'geo null');
        }
        if ($ver < 33) {
            self::createAll();   // partners
        }
        if ($ver < 34) {
            // Накладні Нової Пошти.
            //
            // Текстового «відділення» для накладної замало: НП приймає Ref, а не
            // назву, і зіставити «Відділення №5» з довідником заднім числом не
            // вийде — таких у місті буває кілька, у різних районах. Тому поруч із
            // назвою, яку читає людина, тепер лежить ref, який читає API. Старі
            // замовлення лишаються без ref: накладну по них створять, обравши
            // відділення в самій картці.
            self::addColumn('orders', 'city_ref', 'str null');
            self::addColumn('orders', 'np_office_ref', 'str null');
            self::addColumn('orders', 'np_type', "str default 'warehouse'"); // warehouse|courier
            self::addColumn('orders', 'np_street', 'str null');
            self::addColumn('orders', 'np_street_ref', 'str null');
            self::addColumn('orders', 'np_house', 'str null');
            self::addColumn('orders', 'np_flat', 'str null');
            // Те саме в збережених адресах кабінету — інакше швидкий вибір
            // підставляв би адресу, з якої накладну знову не створити
            self::addColumn('user_addresses', 'np_office_ref', 'str null');
            self::addColumn('user_addresses', 'np_type', "str default 'warehouse'");
            self::addColumn('user_addresses', 'np_street', 'str null');
            self::addColumn('user_addresses', 'np_street_ref', 'str null');
            self::addColumn('user_addresses', 'np_house', 'str null');
            self::addColumn('user_addresses', 'np_flat', 'str null');
            // Відділення відправлення в магазину своє: посилку несуть у сусіднє,
            // а не через пів країни. Порожньо — беремо загальне (Shipments::sender).
            // Контрагента тут немає навмисно: він належить кабінету НП, а кабінет
            // (тобто API-ключ) у сайту один на всі точки.
            self::addColumn('stores', 'np_sender_phone', 'str null');
            self::addColumn('stores', 'np_city', 'str null');
            self::addColumn('stores', 'np_city_ref', 'str null');
            self::addColumn('stores', 'np_warehouse', 'str null');
            self::addColumn('stores', 'np_warehouse_ref', 'str null');
            // Вага товару — щоб форма накладної не питала те, що ми вже знаємо.
            // Порожня вага не заважає: тоді береться типова з налаштувань.
            self::addColumn('products', 'weight', 'num null');
            self::addColumn('product_variants', 'weight', 'num null');
            self::createAll();   // shipments + індекси
            self::seedRules();   // нова подія «накладна й рух посилки»
        }
        if ($ver < 35) {
            // Фіскальні чеки.
            //
            // ПРРО належить КАСІ, а каса — торговій точці: гроші отримує
            // конкретний магазин своїм ПРРО, і фіскальний номер зі зміною
            // належать саме йому. Тому все, що описує касу, лежить у точці, а
            // не лише в загальних налаштуваннях.
            //
            // Маршрут — це відповідь на питання «як ми доходимо до каси»:
            //   cloud  — наш сервер говорить з хмарою постачальника (потрібен токен);
            //   agent  — каса стоїть на ПК точки, ключ там же, а завдання їй
            //            приносить агент, який сам стукає до нас; назовні нічого
            //            не відкрито;
            //   device — каса на тому ж пристрої, де працює продавець, і його
            //            браузер звертається на localhost.
            // Порожньо в точці — береться загальний маршрут із налаштувань,
            // порожньо в людини — маршрут її точки.
            self::addColumn('stores', 'fiscal_route', 'str null');
            self::addColumn('stores', 'dm_url', 'str null');
            self::addColumn('stores', 'dm_device', 'str null');
            // Токен агента цієї точки. Ним агент доводить, що він саме звідти,
            // і забирає лише її завдання. Зберігаємо хеш, а не сам токен:
            // база з токенами — це база ключів до всіх кас мережі.
            self::addColumn('stores', 'agent_hash', 'str null');
            self::addColumn('stores', 'agent_seen_at', 'str null');
            // Власна каса людини: продавець за своїм ПК або з телефона, у якому
            // стоїть Device Manager зі своїм ключем.
            self::addColumn('users', 'fiscal_route', 'str null');
            self::addColumn('users', 'dm_url', 'str null');
            self::addColumn('users', 'dm_device', 'str null');
            self::addColumn('stores', 'vchasno_token', 'str null');
            // Податкова група теж у точці: точки можуть належати різним ФОПам,
            // і платник ПДВ поруч із неплатником — звичайна для мережі річ.
            self::addColumn('stores', 'vchasno_taxgrp', 'int null');
            // Підпис під автоматичними операціями цієї точки (нічний Z-звіт).
            // Свій у кожної, бо точки можуть належати різним ФОПам — і бухгалтер
            // у них теж різний. Чеки продажу цього поля не торкаються: там
            // завжди імʼя того, хто продав.
            self::addColumn('stores', 'vchasno_cashier', 'str null');
            // А в товару — своя, коли вона не залежить від полиці: підакцизне
            // й пільгове лишається таким у будь-якій точці. Порожньо — береться
            // група магазину (див. Fiscal::taxGroup).
            self::addColumn('products', 'taxgrp', 'int null');
            self::addColumn('products', 'uktzed', 'str null');
            // Постачальник ПРРО пишеться в сам чек: його міняють, а повернення
            // робить той самий ПРРО, що й продаж.
            self::addColumn('fiscal_receipts', 'provider', "str default 'vchasno'");
            self::addColumn('fiscal_receipts', 'route', "str default 'cloud'");
            self::addColumn('fiscal_receipts', 'doc', 'text null');
            self::addColumn('fiscal_receipts', 'task', "str default 'sell'");
            self::addColumn('fiscal_receipts', 'result', 'text null');
            self::createAll();   // fiscal_receipts + індекси
            self::seedRules();   // нова подія «чек не пробито»
        }
        if ($ver < 36) {
            /*
             * Власники точок.
             *
             * Мережа буває однією юридичною особою лише на папері: два магазини
             * можуть належати одному ФОПу, третій — іншому (наприклад, дружині),
             * і це вже ДВА платники податків. У них різні ПРРО, різні ключі,
             * різні ставки, різні декларації й — головне — РІЗНІ ЛІМІТИ доходу,
             * які перевищуються кожен окремо.
             *
             * До цієї міграції все, що описує касу, лежало в точці. Це працювало,
             * але змушувало вписувати одне й те саме в кожен магазин одного
             * власника й нічого не казало про те, що вони — один платник.
             * Тепер ланцюг такий: товар → магазин → ВЛАСНИК → загальне
             * налаштування. Порожнє поле означає «як ярусом вище», тож нічого
             * заповнювати наново не треба: чого немає у власника, візьметься
             * з магазину, як і бралось.
             */
            self::createAll();                       // owners
            self::addColumn('stores', 'owner_id', 'int null');
        }
        if ($ver < 37) {
            /*
             * Розрахунок за рахунком (IBAN).
             *
             * У роздрібі гроші й товар зустрічаються в одну мить, тому станів
             * оплати не було взагалі. Клієнт-ФОП, який просить рахунок, ламає
             * це припущення: рахунок виставили сьогодні, гроші прийшли за три
             * дні, товар поїхав на четвертий — і всі три дні хтось має бачити,
             * що замовлення чекає грошей.
             */
            self::addColumn('orders', 'payment_kind', 'str null');
            self::addColumn('orders', 'paid_at', 'ts null');
            self::addColumn('orders', 'buyer_type', 'str null');
            self::addColumn('orders', 'buyer_name', 'str null');
            self::addColumn('orders', 'buyer_tax_id', 'str null');
            // Реквізити продавця — у власника: рахунок виставляє ФОП, а не сайт
            foreach (['full_name', 'iban', 'bank', 'address', 'signer'] as $col) {
                self::addColumn('owners', $col, 'str null');
            }
        }
        if ($ver < 38) {
            /*
             * Графік роботи точки.
             *
             * У картці магазину були координати, телефон, відправник Нової
             * Пошти й навіть налаштування ПРРО — усе, що потрібно нам. Не було
             * єдиного, що потрібно покупцеві: чи відчинено. Сторінка «Де нас
             * знайти» не відповідала на найчастіше питання фізичної точки, і
             * людина їхала навмання.
             *
             * Один рядок, а не сім полів на дні тижня: точок кілька, графік у
             * них простий, а конструктор розкладу коштував би дорожче за
             * користь. Той самий рядок іде в розмітку LocalBusiness.
             */
            self::addColumn('stores', 'hours', 'str null');
        }
        if ($ver < 39) {
            /*
             * Підкатегорії.
             *
             * Категорії лежали одним рядом: десяток розділів поруч, і людина,
             * якій потрібен саме липовий мед, відкривала «Мед» цілком — сотню
             * позицій, з яких дивилась чотири. Тепер розділ може мати підрозділи,
             * а панель каталогу розгортає їх стрілкою: вибір звужується до
             * потрібного ще до того, як сторінка щось завантажить.
             *
             * Рівно один рівень углиб, не дерево: другий крок уже вимагав би
             * крихт у самій панелі, а підрозділи підрозділів дрібнішають до
             * порожніх полиць. Обмеження тримає адмінка, а не база.
             */
            self::addColumn('categories', 'parent_id', 'int null');
            self::createAll();   // індекс по parent_id
        }
        if ($ver < 40) {
            /*
             * Оферта й політика конфіденційності переїжджають у код.
             *
             * Обидва документи стандартні: їхній зміст диктують закон і GDPR,
             * а не бізнес. Тому вони живуть у LegalText і показуються самі,
             * без налаштування, — а блок контенту лишається перевизначенням
             * для того, хто напише свій текст.
             *
             * Щоб старі бази теж дістали повний текст, чистимо блок — тоді
             * сторінка бере вбудований. Але чистимо **лише** чорновик, який
             * туди поклали ми: якщо власник уже написав своє, міграція, що це
             * затирає, гірша за відсутню. Впізнаємо чорновик за першими його
             * словами — цього досить і не вимагає зберігати весь старий текст.
             */
            $drafts = [
                'page_offer' => 'Цей документ є публічною пропозицією (офертою) укласти договір',
                'page_privacy' => 'Які дані ми збираємо.',
            ];
            foreach ($drafts as $key => $signature) {
                $body = (string)DB::val(DB::driver() === 'sqlite'
                    ? 'SELECT body FROM content_blocks WHERE "key" = ?'
                    : 'SELECT body FROM content_blocks WHERE `key` = ?', [$key]);
                if ($body === '' || str_starts_with(ltrim($body), $signature)) {
                    Content::set($key, ['body' => null]);
                }
            }
        }
        if ($ver < 41) {
            /*
             * Оптові знижки: чим більше штук, тим дешевша штука.
             *
             * Знижки досі відповідали на питання «що зараз продаємо дешевше»
             * (акція) і «хто має право на знижку» (промокод). На «скільки
             * узяли» не відповідало ніщо, і опт домовлявся голосом: покупець
             * писав, продавець рахував у голові й правив суму руками.
             *
             * Шкала влаштована як акції — рядком із привʼязкою до товару АБО
             * розділу АБО ні до чого (тоді вона загальна). Але вирішується
             * НЕ як акції: там перемагає найбільший відсоток серед усіх, хто
             * підійшов, а тут — найближчий заповнений ярус, цілком. Інакше
             * шкала розділу назавжди перебивала б шкалу товару, і сказати
             * «на цей сорт опту менше» стало б неможливо.
             *
             * wholesale — вимикач. «Опт на цей товар не діє» треба вміти
             * сказати окремо від «шкала не заповнена»: друге означає
             * «беремо з розділу».
             *
             * qty_scope — що саме рахувати. Пʼять свічок різного кольору —
             * це пʼять свічок, і опт на них має спрацювати; пʼять шапок
             * різного розміру — пʼять різних речей, кожна своїм рахунком.
             * З даних ця різниця не виводиться, її знає лише той, хто завів
             * товар, — тому це поле картки, а не здогад коду.
             *
             * max_discount — стеля сумарної знижки на позицію. Знижки тепер
             * складаються (акція + опт + код), а складені доходять до цін,
             * яких ніхто не планував: кожен ярус окремо здається невеликим.
             * Стеля обовʼязкова й має значення за замовчуванням — порожнє
             * поле означає «як ярусом вище», а не «без обмежень».
             */
            self::createAll();                                  // qty_discounts
            self::addColumn('products', 'wholesale', 'bool default 1');
            self::addColumn('products', 'qty_scope', "str default 'product'");
            self::addColumn('products', 'max_discount', 'num null');
            self::addColumn('product_variants', 'max_discount', 'num null');
            if (Settings::get('max_discount_default', '') === '') {
                Settings::set('max_discount_default', (string)Catalog::DEFAULT_MAX_DISCOUNT);
            }
        }
        if ($ver < 42) {
            /*
             * Набори: разом дешевше.
             *
             * Опт відповідає на «скільки взяли одного», набір — на «що взяли
             * разом». Це різні продажі: мед ящиком купує перекупник, а мед із
             * прополісом і свічкою — той, хто шукає подарунок і не знає, з
             * чого його скласти. Другому потрібна не знижка, а готова
             * пропозиція; знижка лише прибирає сумнів.
             *
             * Знижка набору рахується по позиціях, а не від суми кошика: лише
             * так вона впирається в ту саму стелю, що акція, опт і промокод, і
             * лише так фіскальний чек сходиться по рядках. Але покупцеві
             * показується одним рядком — «Набір "Подарунковий" −108 грн», — бо
             * набір є фактом про кошик, а не про товар: тиха зміна ціни меду
             * на 162 грн не пояснює себе нічим.
             *
             * kind розводить два способи назвати ту саму знижку. Відсоток
             * зручний, коли ціни змінюються; фіксована ціна — коли набір
             * продають як окрему річ («подарунковий за 500»), і рівне число на
             * ціннику важливіше за рівний відсоток.
             *
             * variant_id порожній означає «будь-яка фасовка»: набір «мед +
             * прополіс» не мусить наполягати саме на банці 350 г, поки покупцю
             * все одно.
             */
            self::createAll();   // bundles + bundle_items
        }
        if ($ver < 43) {
            /*
             * Фото варіанта.
             *
             * Досі галерея належала товару цілком, і на сторінці мед у банці
             * 0,5 виглядав так само, як ящик: перемикач міняв ціну й вагу, а
             * картинка лишалась та сама. Для фасовок це терпимо, для кольору —
             * ні: «оберіть колір» без кадру самого кольору не вибір, а здогад.
             *
             * Мітка на фото, а не поле в варіанті: у варіанта тоді була б рівно
             * одна картинка, а колір показують кількома — загальний план,
             * фактура, деталь. Порожній variant_id лишає кадр спільним, тому
             * всі наявні фото поводяться далі так, як поводились.
             */
            self::addColumn('product_images', 'variant_id', 'int null');
            self::createAll();   // індекс product_images.variant_id
        }
        if ($ver < 44) {
            /*
             * Торг: «запропоную свою ціну за стільки-то штук».
             *
             * Усі знижки досі призначав магазин: акція, промокод, опт, набір.
             * Питання покупця — «а за стільки віддасте?» — не мало де
             * прозвучати, і його ставили в дірект. Продавець рахував у голові,
             * ціну правили руками в замовленні, а через місяць ніхто не міг
             * пояснити, чому саме цей мед пішов по 480.
             *
             * Тепер розмова записується: пропозиція → зустрічна → погодження
             * або відмова. Домовлена ціна стає справжньою — з нею оформлюється
             * замовлення, і вона не складається ні з чим іншим (див. Offers).
             *
             * bargain — вимикач у картці, як wholesale: є позиції, де торг
             * недоречний, і сказати це треба окремо від «торг вимкнено скрізь».
             * За замовчуванням увімкнено: власник вирішив, що торг є, а
             * прапорець на кожному товарі руками ніхто ставити не буде.
             */
            self::createAll();                                // offers + offer_rounds
            self::addColumn('products', 'bargain', 'bool default 1');
            foreach (['offer_new' => 'sellers', 'offer_reply' => 'customer'] as $event => $to) {
                foreach (array_keys(Notify::CHANNELS) as $channel) {
                    if (DB::row('SELECT id FROM notification_rules WHERE event = ? AND channel = ?', [$event, $channel])) continue;
                    DB::insert('notification_rules', [
                        'event' => $event, 'channel' => $channel,
                        // viber вимкнений так само, як його заводили попередні
                        // міграції: канал є не в кожного магазину
                        'enabled' => $channel === 'viber' ? 0 : 1,
                        'recipients' => $to, 'template' => Notify::DEFAULT_TEMPLATES[$event] ?? '',
                    ]);
                }
            }
        }
        if ($ver < 45) {
            /*
             * Торг доходив не до всіх, кому потрібен.
             *
             * Подію завели з адресатом 'sellers', за зразком черги очікувань.
             * Але 'sellers' означає буквально людей із роллю «продавець», а в
             * магазині, де власник один і він адміністратор, таких немає
             * жодного: пропозиція мовчки лежала б у черзі, поки хтось не зайде
             * в адмінку. Для попиту на відсутній товар це терпимо, для угоди,
             * яка діє дві доби, — ні. Правильний зразок тут — нове замовлення.
             *
             * Чіпаємо лише рядки, яких ніхто не редагував: власник, що вже
             * перевів подію на себе чи на когось іще, не має отримати назад
             * наш вибір.
             */
            DB::query("UPDATE notification_rules SET recipients = ?
                        WHERE event = ? AND recipients = ?",
                ['admins_sellers', 'offer_new', 'sellers']);
        }
        if ($ver < 46) {
            /*
             * Мовчанка — найгірше, що може статись із торгом.
             *
             * Людина запропонувала ціну, продавець не зайшов в адмінку, через
             * два тижні розмова тихо протермінувалась. Покупець не отримав
             * нічого. Це гірше, ніж якби торгу не було зовсім: ми запросили до
             * розмови й не прийшли.
             *
             * Дві мітки закривають три різні мовчанки: пропозиція, що чекає
             * довше за обіцяний строк; погоджена ціна, якій лишились години; і
             * розмова, що вже закрилась сама. Проходить по них команда
             * offers:remind із cron — там, де вже живуть np:track і vchasno:z.
             *
             * offers_reply_hours — те саме число, що показане покупцю у формі
             * («відповідаємо протягом доби») і за яким б'є нагадування. Обіцянка
             * й будильник мають бути одним значенням: інакше одне з них рано чи
             * пізно почне брехати.
             */
            self::addColumn('offers', 'reminded_at', 'ts null');
            self::addColumn('offers', 'notified_at', 'ts null');
            if (Settings::get('offers_reply_hours', '') === '') {
                Settings::set('offers_reply_hours', (string)Offers::DEFAULT_REPLY_HOURS);
            }
        }
        if ($ver < 47) {
            /*
             * Оплата карткою на сайті — інтернет-еквайринг Raiffeisen Bank.
             *
             * Досі гроші й товар зустрічались або у відділенні перевізника
             * (післяплата), або біля каси. Обидва способи мають одну спільну
             * рису: покупець платить ПІСЛЯ того, як побачив товар, і до того
             * моменту може передумати мовчки. Для магазину це не дрібниця —
             * непроплачена посилка їде туди й назад за його рахунок.
             *
             * Онлайн-оплата змінює порядок: гроші приходять до відправлення.
             * Через це з'являється те, чого раніше не було, — стан «замовлення
             * створене, але ще не оплачене», і його треба десь тримати. Тримає
             * таблиця payments (див. коментар до неї), а не пара стовпців у
             * замовленні: спроб оплати буває кілька.
             *
             * Вимкнено за замовчуванням: без реквізитів від банку вмикати
             * нічого. Автоматичний чек, навпаки, увімкнений — оплата карткою на
             * сайті це розрахункова операція, і чек за нею обов'язковий рівно
             * так само, як за карткою біля каси.
             */
            self::createAll();                       // payments + індекси
            self::seedRules();                       // нова подія «оплату отримано»
            if (Settings::get('acq_env', '') === '') Settings::set('acq_env', 'test');
            if (Settings::get('acq_auto_fiscal', '') === '') Settings::set('acq_auto_fiscal', '1');
        }
        if ($ver < 48) {
            /*
             * Журнал входів і змін доступів.
             *
             * Стрічка подій у замовлення була завжди — видно, хто що зробив із
             * конкретним замовленням. А от на питання «хто заходив у кабінет»,
             * «хто видав людині права» і «хто змінив платіжні реквізити»
             * відповісти було нічим. Саме ці три ставлять першими, коли
             * розбираються, як сталося те, що сталося.
             *
             * Заднім числом журнал не заповнюється: те, що не записали, не
             * відновити. Тому таблиця починається з дня оновлення, і це
             * нормально — важливо, щоб вона почалась.
             */
            self::createAll();
        }
        if ($ver < 58) {
            // ПРРО ДПС: підпис у браузері (DpsFlow) — крок документа, звірка стану
            // каси й «пульс» вкладки, яка тримає ключ
            self::addColumn('fiscal_receipts', 'step', 'text null');
            self::addColumn('stores', 'dps_synced_at', 'str null');
            self::addColumn('stores', 'dps_testing', 'bool null');
            self::addColumn('users', 'dps_signer_seen_at', 'str null');
        }
        if ($ver < 57) {
            /*
             * bargain і access_days додавала лише стара міграція, а в описі
             * таблиці їх не було. На чистому встановленні (версія одразу
             * остання) вони так і не зʼявлялись — і збереження товару в адмінці
             * падало. Тепер вони є в описі, а тут — для баз, створених раніше.
             */
            self::addColumn('products', 'bargain', 'bool default 1');
            self::addColumn('products', 'access_days', 'int null');
        }
        if ($ver < 56) {
            // Точність відсотка опту — див. тип 'pct' у colSql. SQLite зберігає
            // числа як є, тож міняти там нічого.
            if (DB::driver() !== 'sqlite') {
                try { DB::pdo()->exec('ALTER TABLE qty_discounts MODIFY percent DECIMAL(9,4) NOT NULL'); } catch (Throwable $e) {}
            }
        }
        if ($ver < 55) {
            /*
             * Магазин іграшок: вік дитини в товарі, відгуки й ПРРО податкової.
             * Нові таблиці створює createAll (IF NOT EXISTS), колонки — тут.
             */
            self::createAll();
            self::addColumn('products', 'age_min', 'int null');
            self::addColumn('products', 'age_max', 'int null');
            self::addColumn('products', 'ext_id', 'str null');
            self::addColumn('products', 'seo_title', 'str null');
            foreach (['dps_fiscal_num' => 'str null', 'dps_local_num' => 'int null',
                      'dps_point_name' => 'str null', 'dps_point_addr' => 'str null',
                      'dps_next_num' => 'int null', 'dps_shift_open' => 'bool default 0',
                      'dps_shift_at' => 'str null'] as $col => $type) {
                self::addColumn('stores', $col, $type);
            }
            foreach (array_keys(Notify::CHANNELS) as $channel) {
                if (DB::row('SELECT id FROM notification_rules WHERE event = ? AND channel = ?', ['review_new', $channel])) continue;
                [$to, $on] = Notify::DEFAULT_RULES['review_new'];
                DB::insert('notification_rules', ['event' => 'review_new', 'channel' => $channel, 'enabled' => $on ? 1 : 0,
                    'recipients' => $to, 'template' => Notify::DEFAULT_TEMPLATES['review_new']]);
            }
        }
        if ($ver < 54) {
            /*
             * Типів «Послуга» й «Відео» більше немає (див. Catalog::TYPES).
             *
             * Вони не робили нічого: товар із типом «Відео» так само возився
             * Новою Поштою, рахувався на складі й питав вагу. Обравши тип,
             * людина нічого не вмикала — лише вірила, що ввімкнула.
             *
             * Наявні записи стають звичайними товарами, і це нічого не змінює
             * в їхній поведінці: вони й так поводились саме так. Зате картка
             * такого товару знову зберігається — інакше правило «тип має
             * збігатися з типом категорії» вимагало б від них типу, якого в
             * списку вже немає.
             */
            try {
                DB::query("UPDATE products SET type = 'product' WHERE type IN ('service','video')");
                DB::query("UPDATE categories SET type = 'product' WHERE type IN ('service','video')");
            } catch (Throwable $e) { /* порожня база */ }
        }
        if ($ver < 53) {
            /*
             * Черга розподілу онлайн-замовлень (Roles::DISPATCHER).
             *
             * Наявні замовлення позначаємо розподіленими заднім числом: вони
             * вже виконуються або виконані, і скидати їх у чергу означало б
             * попросити людину переглянути те, що давно поїхало. Черга має
             * починатись із наступного замовлення, а не з історії магазину.
             */
            self::addColumn('orders', 'routed_at', 'ts null');
            self::addColumn('orders', 'routed_by', 'int null');
            try {
                DB::query('UPDATE orders SET routed_at = created_at WHERE routed_at IS NULL AND parent_id IS NULL');
            } catch (Throwable $e) { /* порожня база — нічого позначати */ }
        }
        if ($ver < 52) {
            /*
             * Два поля сторінки курсу: «чого навчитесь» і програма.
             *
             * Обидва — простий текст, по пункту в рядку. Не JSON і не окрема
             * таблиця «модулів»: пунктів десяток, редагує їх людина, а не
             * інтеграція, і кожна зайва сутність тут — це ще одна форма, у якій
             * доведеться клацати замість того, щоб просто написати список.
             *
             * Порожні поля означають, що блок на сторінці не показується
             * взагалі, — курс без програми виглядає скромніше, ніж курс із
             * заголовком «Програма» й порожнечею під ним.
             */
            self::addColumn('products', 'learn_outcomes', 'text null');
            self::addColumn('products', 'program', 'text null');
        }
        if ($ver < 51) {
            /*
             * Кабінет студента: куплені курси й отримані сертифікати.
             *
             * Дипломам додаються два звʼязки — з акаунтом і з курсом-товаром.
             * Заднім числом не проставляємо нічого: збіг за іменем звʼязком не
             * є (тезки, зміна прізвища), і приписати людині чужий диплом гірше,
             * ніж не показати їй власний. Наявні дипломи звʼязує адмін руками
             * у формі видачі — там тепер вибір акаунта, а не самий лише текст.
             *
             * access_days — на скільки днів курс відкривається після оплати.
             * Порожньо означає «назавжди»: це найчастіший випадок, і саме він
             * має працювати без заповнення зайвого поля.
             */
            self::addColumn('diplomas', 'user_id', 'int null');
            self::addColumn('diplomas', 'product_id', 'int null');
            self::addColumn('products', 'access_days', 'int null');
            self::createAll();
        }
        if ($ver < 50) {
            /*
             * Вибір способів входу (див. LoginMethods).
             *
             * Таблиця створюється порожньою, і це не недогляд: порожньо означає
             * «вибору не робили», тобто дозволені всі налаштовані способи.
             * Заповнити її заднім числом було б найгіршим варіантом оновлення —
             * частина людей увійшла б наступного дня в закриті двері, не
             * розуміючи чому.
             */
            self::createAll();
        }
        if ($ver < 49) {
            /*
             * Підтверджені контакти: пошта (вхід за кодом) і номер (Telegram).
             *
             * Заднім числом не проставляємо НІЧОГО, і це навмисно.
             *
             * Спокуса є: позначити доведеними номери всіх, у кого привʼязаний
             * Telegram, — контакт же приходив із месенджера. Але звірка
             * contact.user_id проти from.id зʼявилась лише в 0722edf, а до неї
             * переслати чужий контакт було можна. Тобто серед старих записів
             * теоретично є рівно ті, заради яких усе це й робиться. Позначити
             * їх доведеними означало б закрити двері й лишити ключ у замку.
             *
             * Втрати від суворості немає: бот просить контакт при КОЖНОМУ вході
             * (див. README, «Вхід через месенджер»), тож номер стає доведеним
             * сам собою, першим же входом. Пошта — так само, першим входом за
             * кодом. Вхід через Google теж проставляє пошту: Google віддає
             * лише підтверджені адреси.
             *
             * Замовлення, оформлені до підтвердження, не губляться —
             * Customers::claimOrders() чіпляє їх у ту ж мить, коли номер стає
             * доведеним.
             */
            self::addColumn('users', 'email_verified_at', 'ts null');
            self::addColumn('users', 'phone_verified_at', 'ts null');
            self::addColumn('auth_tokens', 'email', 'str null');
        }
        Settings::set('schema_version', (string)self::VERSION);
    }

    /**
     * Дописати правила нотифікацій для подій, яких у базі ще немає.
     *
     * Наявних не чіпає: адмін міг вимкнути подію чи переписати шаблон, і
     * міграція, що це затирає, гірша за відсутню. Значення беремо з
     * Notify::DEFAULT_RULES — з того самого місця, що й Seeder, інакше нова
     * подія працювала б або лише на нових базах, або лише на оновлених.
     */
    private static function seedRules(): void
    {
        foreach (Notify::EVENTS as $event => $label) {
            [$to, $on] = Notify::DEFAULT_RULES[$event] ?? ['admins_sellers', false];
            foreach (array_keys(Notify::CHANNELS) as $channel) {
                if (DB::row('SELECT id FROM notification_rules WHERE event = ? AND channel = ?',
                    [$event, $channel])) continue;
                DB::insert('notification_rules', [
                    'event' => $event, 'channel' => $channel,
                    'enabled' => $on ? 1 : 0,
                    'recipients' => $to,
                    'template' => Notify::DEFAULT_TEMPLATES[$event] ?? '',
                ]);
            }
        }
    }

    /**
     * Роль зі стовпця users.role стає рядком у user_roles. Стовпець лишаємо на місці,
     * але більше не читаємо — приберемо окремо, коли переконаємось, що на нього
     * ніхто не посилається. Покупця не переносимо: це базовий стан, а не право.
     */
    private static function moveRolesToUserRoles(): void
    {
        foreach (DB::all('SELECT id, role FROM users') as $u) {
            $role = (string)($u['role'] ?? '');
            if ($role === '' || $role === Roles::CUSTOMER || !Roles::exists($role)) continue;
            $has = DB::row('SELECT id FROM user_roles WHERE user_id = ? AND role = ?', [$u['id'], $role]);
            if (!$has) DB::insert('user_roles', ['user_id' => (int)$u['id'], 'role' => $role, 'created_at' => now()]);
        }
    }

    /**
     * Старі замовлення переводимо в нову модель: кожне дістає одне підзамовлення,
     * куди переїжджають його позиції. Магазин — той, що вже вказаний у замовленні
     * (самовивіз), інакше магазин за замовчуванням. Так у коді лишається один шлях:
     * позиції завжди лежать у підзамовленні.
     */
    private static function splitLegacyOrders(): void
    {
        // позиції, що лежать просто в замовленні, — ознака старої моделі (і захист від повторного запуску)
        $rows = DB::all(
            'SELECT * FROM orders o WHERE o.parent_id IS NULL
             AND EXISTS (SELECT 1 FROM order_items i WHERE i.order_id = o.id)');
        foreach ($rows as $o) {
            $parentId = (int)$o['id'];
            $storeId = $o['store_id'] ? (int)$o['store_id'] : OrderFlow::defaultStoreId();
            DB::tx(function () use ($o, $parentId, $storeId) {
                $data = ['parent_id' => $parentId, 'seq' => 1,
                    'number' => $o['number'] . '/1', 'token' => null,
                    'store_id' => $storeId ?: null, 'status' => $o['status'],
                    'subtotal' => $o['subtotal'], 'discount' => $o['discount'], 'total' => $o['total'],
                    'created_at' => $o['created_at'] ?: now()];
                foreach (['user_id', 'name', 'phone', 'email', 'delivery', 'city', 'np_office',
                          'address', 'comment', 'promo_code'] as $f) $data[$f] = $o[$f] ?? null;
                $childId = DB::insert('orders', $data);
                DB::update('order_items', ['order_id' => $childId], 'order_id = ?', [$parentId]);
                OrderFlow::log($parentId, $childId, 'created',
                    'Замовлення переведено в модель підзамовлень при оновленні бази.');
            });
        }
    }

    /**
     * Залишки товарів, що мають варіанти, переносимо з рядка «без варіанта»
     * на перший активний варіант того самого магазину — щоб не втратити кількість.
     */
    private static function moveStockToVariants(): void
    {
        $rows = DB::all(
            'SELECT ss.* FROM store_stock ss
             WHERE ss.variant_id IS NULL
               AND EXISTS (SELECT 1 FROM product_variants v WHERE v.product_id = ss.product_id AND v.active = 1)');
        foreach ($rows as $r) {
            $pid = (int)$r['product_id']; $sid = (int)$r['store_id']; $qty = (int)$r['qty'];
            $vid = (int)(DB::val('SELECT id FROM product_variants WHERE product_id = ? AND active = 1 ORDER BY sort, id LIMIT 1', [$pid]) ?? 0);
            if ($vid && $qty > 0) {
                $exists = DB::row('SELECT id, qty FROM store_stock WHERE product_id = ? AND store_id = ? AND variant_id = ?', [$pid, $sid, $vid]);
                if ($exists) DB::update('store_stock', ['qty' => (int)$exists['qty'] + $qty], 'id = ?', [$exists['id']]);
                else DB::insert('store_stock', ['product_id' => $pid, 'store_id' => $sid, 'variant_id' => $vid, 'qty' => $qty]);
            }
            DB::delete('store_stock', 'id = ?', [(int)$r['id']]);
        }
    }

    /**
     * Один номер — один акаунт. Де номер повторюється, лишаємо його найстаршому
     * запису (він майже завжди й є справжнім акаунтом людини), а решті обнуляємо.
     */
    private static function dedupePhones(): void
    {
        $dupes = DB::all("SELECT phone FROM users
                          WHERE phone IS NOT NULL AND phone != ''
                          GROUP BY phone HAVING COUNT(*) > 1");
        foreach ($dupes as $d) {
            $keep = (int)DB::val('SELECT MIN(id) FROM users WHERE phone = ?', [$d['phone']]);
            DB::query('UPDATE users SET phone = NULL WHERE phone = ? AND id <> ?', [$d['phone'], $keep]);
        }
    }

    /** Телефони, які не проходять нормалізацію, робимо порожніми — краще перепитати, ніж мати сміття */
    private static function fixBrokenPhones(): void
    {
        foreach (DB::all("SELECT id, phone FROM users WHERE phone IS NOT NULL AND phone != ''") as $u) {
            $norm = AuthTokens::normPhoneAny((string)$u['phone']);
            if ($norm === null) DB::update('users', ['phone' => null], 'id = ?', [$u['id']]);
            elseif ($norm !== $u['phone']) DB::update('users', ['phone' => $norm], 'id = ?', [$u['id']]);
        }
    }

    private static function addColumn(string $table, string $col, string $spec): void
    {
        try { DB::val("SELECT $col FROM $table LIMIT 1"); return; } catch (Throwable $e) {}
        $driver = DB::driver();
        $sql = 'ALTER TABLE ' . $table . ' ADD COLUMN ' . self::colSql($col, $spec, $driver);
        try { DB::pdo()->exec($sql); } catch (Throwable $e) { /* лог */ }
    }

    public static function tables(): array
    {
        return [
            'users' => [
                'id' => 'id', 'google_id' => 'str null unique', 'email' => 'str unique',
                'name' => 'str', 'avatar' => 'str null',
                'role' => "str default 'customer'", // admin|seller|editor|customer
                'active' => 'bool default 1', 'tg_chat_id' => 'str null',
                'viber_id' => 'str null', 'phone' => 'str null',
                /*
                 * Чим доведено, що контакт належить власнику акаунта.
                 *
                 * Пошта доводиться одноразовим кодом у листі (EmailAuth), номер —
                 * контактом із Telegram (там є contact.user_id, яким це можна
                 * звірити). Вписаний руками номер не доводить нічого: він лишається
                 * контактом для дзвінка, але не робить акаунт власником номера.
                 *
                 * Різниця не теоретична. Замовлення, які продавець оформлює по
                 * телефону, знаходять покупця саме за номером (Customers::resolve).
                 * Доки «номер у профілі» = «мій номер», будь-хто, хто увійшов,
                 * міг вписати чужий номер і почати збирати чужі замовлення разом
                 * з адресою й сумами. Саме через цю дірку прибрано вхід через
                 * Viber (66a8175) — але через профіль вона лишалась відкритою.
                 */
                'email_verified_at' => 'ts null', 'phone_verified_at' => 'ts null',
                // Власна каса продавця: Device Manager на його ПК чи телефоні
                // зі своїм ключем. Порожньо — працює касою своєї точки.
                'fiscal_route' => 'str null',
                'dm_url' => 'str null', 'dm_device' => 'str null',
                // Коли вкладка-підписувач ПРРО ДПС цієї людини востаннє виходила на
                // звʼязок: так картка замовлення знає, чи є кому підписати чек
                'dps_signer_seen_at' => 'str null',
                'created_at' => 'ts',
            ],
            // Чий товар. own = 1 стоїть у бренда самого магазину — саме з нього
            // сайт бере право сказати «ми виробник». Прапорець, а не порівняння
            // назв: назву магазину міняють, і твердження про походження товару
            // не має залежати від такої правки.
            /*
             * Відгуки про магазин. Перенесені з Prom (source = 'prom') і нові,
             * залишені на сайті (source = 'site'). Нові спершу чекають на
             * модерацію: відгук — це слова покупця, але на нашій сторінці, і
             * спам чи чужі персональні дані тут опублікувати означало б
             * опублікувати їх від свого імені.
             */
            'reviews' => [
                'id' => 'id', 'author' => 'str', 'rating' => 'int default 5',
                'text' => 'text null', 'reply' => 'text null',
                'tags' => 'text null',           // JSON-масив: «Швидко відправили», …
                'product_ids' => 'text null',    // JSON-масив id товарів, про які відгук
                'user_id' => 'int null',
                'source' => "str default 'site'", 'ext_id' => 'str null',
                'approved' => 'bool default 0',
                'created_at' => 'ts',
            ],
            'brands' => [
                'id' => 'id', 'name' => 'str', 'slug' => 'str unique',
                'logo' => 'str null', 'description' => 'text null',
                'own' => 'bool default 0', 'active' => 'bool default 1', 'sort' => 'int default 0',
            ],
            // Брендів у товару може бути кілька: спільне виробництво — це не
            // окремий бренд «A & B», а той самий товар під обома. Тоді пошук
            // по будь-якому з них його знаходить, а не половина покупців.
            'product_brands' => [
                'id' => 'id', 'product_id' => 'int', 'brand_id' => 'int',
            ],
            /**
             * Власник точки — ФОП або юрособа.
             *
             * Це не «зайва сутність»: два магазини одного ФОПа і третій магазин
             * іншого — це два ПЛАТНИКИ ПОДАТКІВ, а не три точки. У кожного свій
             * ПРРО, свій ключ, своя ставка, своя декларація і свій ліміт доходу,
             * який перевищується окремо. Поки власника не було, все це доводилось
             * повторювати в кожній точці, і ніщо не заважало випадково поставити
             * одному магазину ставку сусіда.
             *
             * ep_group і vat зберігаємо не заради краси: саме на їх поєднанні
             * ловиться найдорожча помилка — друга група єдиного податку платником
             * ПДВ бути не може, тож ПДВ-ставка в її чеках означає, що хтось
             * переплутав «групу ЄП» із «податковою групою чека».
             */
            'owners' => [
                'id' => 'id',
                'name' => 'str',                    // як у документах: «ФОП Прізвище І. Б.»
                'tax_id' => 'str null',             // ІПН або ЄДРПОУ
                'ep_group' => 'int null',           // група єдиного податку: 1|2|3, порожньо — загальна система
                'vat' => 'bool default 0',          // платник ПДВ
                // Типова податкова група ЧЕКА (коди ДПС 1–9, див. Vchasno::TAX_GROUPS).
                // З групою ЄП не має нічого спільного, і плутають їх постійно.
                'taxgrp' => 'int null',
                'cashier' => 'str null',            // підпис під автоматичними операціями
                // Реквізити для рахунків і накладних. Повна назва окремо від
                // name: у списку зручне «ФОП Іваненко», а в документі має стояти
                // «Фізична особа-підприємець Іваненко Іван Іванович».
                'full_name' => 'str null',
                'iban' => 'str null',
                'bank' => 'str null',
                'address' => 'str null',
                'signer' => 'str null',             // хто підписує документи
                'note' => 'text null',
                'active' => 'bool default 1', 'sort' => 'int default 0',
                'created_at' => 'ts',
            ],
            'stores' => [
                'id' => 'id', 'name' => 'str', 'slug' => 'str unique', 'city' => 'str null',
                // Чия це точка. Порожньо — власника ще не вказали; тоді все
                // працює як раніше, з налаштувань самої точки.
                'owner_id' => 'int null',
                'address' => 'str null', 'phone' => 'str null',
                // Коли точка відчинена — рядком, як його читає людина:
                // «Пн–Пт 9:00–18:00, Сб 10:00–15:00»
                'hours' => 'str null',
                // мітка на карті; без них точка лишається в списку, але не на карті
                'lat' => 'geo null', 'lng' => 'geo null',
                // Звідки ця точка відправляє посилки. Порожньо — з відділення,
                // вказаного в загальних налаштуваннях (див. Shipments::sender).
                'np_sender_phone' => 'str null',
                'np_city' => 'str null', 'np_city_ref' => 'str null',
                'np_warehouse' => 'str null', 'np_warehouse_ref' => 'str null',
                // Каса цієї точки. ПРРО належить точці: чек мусить пробитись
                // саме там, де стоїть покупець. Порожньо — точка працює на
                // загальній касі з налаштувань.
                //
                // fiscal_route — як ми доходимо до каси: cloud | agent | device.
                // dm_url і dm_device потрібні двом останнім: адреса Device
                // Manager і назва ПРРО всередині нього.
                'fiscal_route' => 'str null',
                'dm_url' => 'str null', 'dm_device' => 'str null',
                // ПРРО в ДПС (постачальник 'dps', див. DpsDoc). Фіскальний номер
                // видає податкова при реєстрації ПРРО, локальний — номер каси
                // всередині точки. Лічильник документів і прапорець зміни — наша
                // копія стану ПРРО: фіскальний сервер вимагає наскрізної нумерації,
                // і пропуск номера він не пробачає.
                'dps_fiscal_num' => 'str null', 'dps_local_num' => 'int null',
                'dps_point_name' => 'str null', 'dps_point_addr' => 'str null',
                'dps_next_num' => 'int null', 'dps_shift_open' => 'bool default 0',
                'dps_shift_at' => 'str null',
                // Коли стан ПРРО востаннє звіряли з фіскальним сервером і чи
                // відкрита зараз зміна тестова (в одній зміні не можна змішувати
                // тестові й фіскальні документи)
                'dps_synced_at' => 'str null', 'dps_testing' => 'bool null',
                // Агент доводить, що він із цієї точки, своїм токеном. У базі
                // лежить лише хеш: перелік токенів усіх кас мережі — надто
                // дорога річ, щоб зберігати її у відкритому вигляді.
                'agent_hash' => 'str null', 'agent_seen_at' => 'str null',
                'vchasno_token' => 'str null',
                // Типова податкова група точки: у мережі одна точка може бути
                // платником ПДВ, а сусідня — ні (різні ФОПи).
                'vchasno_taxgrp' => 'int null',
                // Підпис під автоматичними операціями (нічний Z-звіт). Порожньо —
                // береться загальний із налаштувань; заповнюють лише там, де
                // точка належить іншому ФОПу й веде її інший бухгалтер.
                // Чеків продажу не стосується: там завжди імʼя того, хто продав.
                'vchasno_cashier' => 'str null',
                'active' => 'bool default 1', 'sort' => 'int default 0',
            ],
            // Партнери — не бренди. Бренд відповідає на питання «чий це товар»
            // і живе в картці товару; партнер нічого не виробляє для нас — це
            // господарство, школа чи крамниця, з якою ми працюємо. Спільна
            // таблиця змусила б кожен список фільтрувати прапорцем, а перший же
            // забутий фільтр показав би партнера у виборі виробника.
            'partners' => [
                'id' => 'id', 'name' => 'str', 'slug' => 'str unique',
                'logo' => 'str null', 'url' => 'str null', 'description' => 'text null',
                'active' => 'bool default 1', 'sort' => 'int default 0',
            ],
            'seller_stores' => [ 'user_id' => 'int', 'store_id' => 'int' ],
            // Ролі окремо від users: одна людина може мати кілька. Призначення магазинів
            // (seller_stores) з роллю не повʼязане — це різні речі.
            'user_roles' => [
                'id' => 'id', 'user_id' => 'int', 'role' => 'str', 'created_at' => 'ts',
            ],
            // parent_id — розділ, у якому лежить цей: «Мед» → «Липовий».
            // Порожньо = верхній рівень. Глибше за один крок не пускає адмінка
            // (Controllers\Admin\Categories): панель каталогу розгортається рівно раз.
            'categories' => [
                'id' => 'id', 'name' => 'str', 'slug' => 'str unique',
                'parent_id' => 'int null',
                // Тип розділу мусить збігатися з типом товарів у ньому
                // (Products::categoryTypeError). Перелік — Catalog::TYPES.
                'type' => "str default 'product'", // product|course
                'sort' => 'int default 0', 'active' => 'bool default 1',
            ],
            'products' => [
                'id' => 'id', 'category_id' => 'int', 'name' => 'str', 'slug' => 'str unique',
                // sku — наш код для обліку, barcode — те, що надруковано на
                // етикетці й прилітає зі сканера. Обидва необовʼязкові.
                'sku' => 'str null', 'barcode' => 'str null',
                // застарілі поля бренду: до міграції 28 бренд був один, до 27 — текстом.
                // Не читаються; чий товар — тепер product_brands.
                'brand_id' => 'int null',
                'brand' => 'str null',
                'short_desc' => 'text null', 'description' => 'text null',
                'base_price' => 'num null', // null => "За запитом"
                'old_price' => 'num null',
                // Перелік і значення — Catalog::TYPES. Типи service й video
                // прибрано в міграції 54: вони не робили нічого.
                'type' => "str default 'product'", // product|course
                // Сторінка курсу: результати навчання й програма, по пункту в
                // рядку. Читаються лише для type = course (див. home/course).
                'learn_outcomes' => 'text null', 'program' => 'text null',
                'unit' => 'str null',
                'active' => 'bool default 1', 'featured' => 'bool default 0',
                'made_to_order' => 'bool default 1', // виробник: можна замовити без наявності
                'low_stock_threshold' => 'int null', // ≤ цього — показуємо "закінчується" замість числа
                // Вага однієї штуки, кг — щоб форма накладної не питала те, що
                // ми вже знаємо. Порожньо — береться типова з налаштувань.
                'weight' => 'num null',
                // Податкова група для фіскального чека. Порожньо — береться
                // типова магазину: більшість товарів оподатковується однаково,
                // і проставляти те саме число в кожній картці ніхто не стане.
                // Заповнюють її там, де товар відрізняється від решти полиці.
                'taxgrp' => 'int null',
                'uktzed' => 'str null',   // код УКТЗЕД, якщо він потрібен у чеку
                // Опт. wholesale — чи діє він на цей товар узагалі; порожня
                // шкала при ввімкненому опті означає «як у розділі», а не
                // «знижки немає» (див. Catalog::qtyTiers).
                'wholesale' => 'bool default 1',
                // Що рахувати для порогу: 'product' — усі фасовки разом
                // (пʼять свічок різного кольору — пʼять свічок), 'variant' —
                // кожна окремо (пʼять шапок різного розміру — різні речі).
                'qty_scope' => "str default 'product'",
                // Стеля сумарної знижки на позицію, %. Порожньо — загальна
                // з налаштувань; у варіації може бути своя, ще жорсткіша.
                'max_discount' => 'num null',
                'image' => 'str null',
                // Вік дитини в місяцях: з якого підходить і до якого (порожньо —
                // без верхньої межі). Головний фільтр магазину іграшок — див. Ages.
                'age_min' => 'int null', 'age_max' => 'int null',
                // Звідки товар прийшов під час переносу: id на Prom. За ним
                // повторний імпорт оновлює той самий товар, а не заводить копію.
                'ext_id' => 'str null',
                // Довгий заголовок для пошуковиків («Йо-йо м'яч з підсвічуванням
                // для новачків…»). На вітрині — коротка назва, у <title> — цей.
                'seo_title' => 'str null',
                // Торг на цей товар (див. Offers) і строк доступу до курсу, днів
                'bargain' => 'bool default 1', 'access_days' => 'int null',
                'created_at' => 'ts', 'updated_at' => 'ts',
            ],
            'product_variants' => [
                'id' => 'id', 'product_id' => 'int', 'name' => 'str',
                // Коди належать фасовці, а не товару: етикетку клеять на банку
                'price' => 'num null', 'sku' => 'str null', 'barcode' => 'str null',
                // Вага теж належить фасовці: «мед узагалі» не важить нічого,
                // важить банка на 0.5 чи на 1.5 кг
                'weight' => 'num null',
                // Своя стеля знижки на цю фасовку. Порожньо — товару, далі
                // загальна: дрібна банка витримує менший відсоток, ніж ящик.
                'max_discount' => 'num null',
                'sort' => 'int default 0', 'active' => 'bool default 1',
            ],
            'product_images' => [
                'id' => 'id', 'product_id' => 'int', 'path' => 'str',
                // Фото належить товару, а варіанту лише позначається. Порожньо
                // — кадр спільний: макро, упаковка, розріз однакові для всіх
                // фасовок, і змушувати заводити їх у кожну означало б тримати
                // пʼять копій одного файлу заради одного «червоного».
                'variant_id' => 'int null',
                'width' => 'int default 0', 'height' => 'int default 0', 'bytes' => 'int default 0', 'sort' => 'int default 0',
            ],
            'product_attrs' => [
                'id' => 'id', 'product_id' => 'int', 'name' => 'str', 'value' => 'str', 'filterable' => 'bool default 0', 'sort' => 'int default 0',
                'attribute_id' => 'int null', 'value_id' => 'int null',
            ],
            // Словник характеристик: спільний для всіх товарів
            'attributes' => [
                'id' => 'id', 'name' => 'str', 'slug' => 'str unique', 'unit' => 'str null',
                'type' => "str default 'select'", // select|text|number|color
                'filterable' => 'bool default 1', 'sort' => 'int default 0', 'active' => 'bool default 1',
            ],
            'attribute_values' => [
                'id' => 'id', 'attribute_id' => 'int', 'value' => 'str', 'color' => 'str null', 'sort' => 'int default 0',
            ],
            // Для яких категорій пропонувати характеристику (немає рядків = для всіх)
            'attribute_categories' => [
                'id' => 'id', 'attribute_id' => 'int', 'category_id' => 'int',
            ],
            // Варіант = набір значень характеристик (Розмір: M + Колір: Червоний)
            'variant_options' => [
                'id' => 'id', 'variant_id' => 'int', 'attribute_id' => 'int', 'value_id' => 'int null', 'value' => 'str',
            ],
            'store_prices' => [
                'id' => 'id', 'product_id' => 'int', 'variant_id' => 'int null', 'store_id' => 'int', 'price' => 'num',
            ],
            'store_stock' => [
                'id' => 'id', 'product_id' => 'int', 'variant_id' => 'int null', 'store_id' => 'int', 'qty' => 'int default 0',
            ],
            'promotions' => [
                'id' => 'id', 'title' => 'str', 'percent' => 'num',
                'store_id' => 'int null', 'category_id' => 'int null', 'product_id' => 'int null',
                'starts_at' => 'str null', 'ends_at' => 'str null', 'active' => 'bool default 1',
            ],
            /*
             * Оптова шкала: від min_qty штук — знижка percent відсотків.
             *
             * Привʼязка та сама, що в акцій: товар АБО розділ АБО ні до чого
             * (загальна шкала магазину). Розвʼязується інакше — не найбільшим
             * відсотком, а найближчим заповненим ярусом цілком, див.
             * Catalog::qtyTiers. Дат тут немає: опт — це умова продажу, а не
             * подія з початком і кінцем; тимчасова знижка на кількість — то
             * акція, і для неї є promotions.
             */
            'qty_discounts' => [
                'id' => 'id', 'product_id' => 'int null', 'category_id' => 'int null',
                'min_qty' => 'int', 'percent' => 'pct', 'active' => 'bool default 1',
            ],
            /*
             * Набір: перелік того, що має зустрітись у кошику разом, і
             * наскільки це разом дешевше.
             *
             * kind = 'percent' — value відсотків з позицій набору;
             * kind = 'fixed'   — увесь набір коштує value гривень, а знижкою
             *                    стає різниця з його звичайною ціною.
             *
             * Дат немає з тієї ж причини, що і в оптової шкали: набір — це
             * пропозиція, а не подія. Тимчасовий набір вимикають прапорцем.
             */
            'bundles' => [
                'id' => 'id', 'title' => 'str', 'kind' => "str default 'percent'",
                'value' => 'num', 'active' => 'bool default 1', 'sort' => 'int default 0',
            ],
            // Позиція набору. variant_id порожній — підійде будь-яка активна
            // фасовка: набір «мед + прополіс» не мусить наполягати на банці
            // 350 г, поки покупцю все одно, а наполягав би — не збирався б
            // із того, що вже лежить у кошику.
            'bundle_items' => [
                'id' => 'id', 'bundle_id' => 'int', 'product_id' => 'int',
                'variant_id' => 'int null', 'qty' => 'int default 1',
            ],
            // Два незалежні ліміти. NULL — без обмежень; це різні питання:
            // max_uses — скільки разів кодом скористаються всі разом (1 = код
            // для однієї людини), per_user_limit — скільки разів одна й та сама
            // людина (1 = кожному по разу, NULL = хоч при кожній покупці).
            // stackable — чи діє код на товар, який уже продається зі знижкою
            // (акція магазину або стара ціна). max_total_percent — стеля сумарної
            // знижки на позицію: акція 20% + код 15% зі стелею 25% дадуть 25%,
            // а не 35%. Без стелі знижки складаються повністю.
            'promo_codes' => [
                'id' => 'id', 'code' => 'str unique', 'percent' => 'num', 'active' => 'bool default 1',
                'expires_at' => 'str null', 'max_uses' => 'int null', 'per_user_limit' => 'int null',
                'stackable' => 'bool default 1', 'max_total_percent' => 'num null',
            ],
            // Використання промокоду. Рядок зʼявляється лише разом із замовленням,
            // тож ліміти рахуються по фактах, а не по лічильнику, який може
            // розʼїхатися з дійсністю. Людину впізнаємо за акаунтом, а гостя —
            // за нормалізованим номером: іншого сталого імені в нас немає.
            'promo_uses' => [
                'id' => 'id', 'promo_id' => 'int', 'code' => 'str', 'order_id' => 'int null',
                'user_id' => 'int null', 'phone' => 'str null', 'created_at' => 'ts',
            ],
            // Головне замовлення: parent_id IS NULL. Підзамовлення магазину: parent_id = головне,
            // store_id = магазин-виконавець, seq = його порядковий номер у замовленні.
            // Позиції (order_items) лежать лише в підзамовленнях — див. OrderFlow.
            'orders' => [
                'id' => 'id', 'number' => 'str unique', 'token' => 'str null', 'user_id' => 'int null',
                'parent_id' => 'int null', 'seq' => 'int default 0',
                /*
                 * Розподіл підтверджено людиною: хто і коли (див. Roles::DISPATCHER).
                 *
                 * Порожньо означає «ще ніхто не дивився». Магазин при цьому вже
                 * призначений — його обрала система при оформленні, і саме це
                 * тримає резерв складу. Тобто це не «замовлення без магазину»,
                 * а «вибір ще не переглянуто живою людиною».
                 *
                 * Тільки в головному замовленні: розподіляють замовлення
                 * цілком, а не кожну його частину окремо.
                 */
                'routed_at' => 'ts null', 'routed_by' => 'int null',
                'name' => 'str', 'phone' => 'str', 'email' => 'str null',
                'delivery' => 'str', 'city' => 'str null', 'np_office' => 'str null',
                'address' => 'str null', 'comment' => 'text null',
                // Адреса Нової Пошти двома шарами: назви для людини й Ref-и для
                // API. Без Ref-ів накладну не створити — «Відділення №5» у місті
                // буває не одне, і вгадувати, яке з них мали на увазі, ніхто не
                // стане. np_type розводить два різні маршрути: у відділення
                // (тоді працює np_office_ref) чи курʼєром (тоді вулиця з будинком).
                'city_ref' => 'str null', 'np_office_ref' => 'str null',
                'np_type' => "str default 'warehouse'", // warehouse|courier
                'np_street' => 'str null', 'np_street_ref' => 'str null',
                'np_house' => 'str null', 'np_flat' => 'str null',
                'store_id' => 'int null',
                // Звідки замовлення: сайт, дзвінок продавцю чи продаж у точці.
                // created_by_user_id — продавець, який його завів (у сайтових порожньо).
                'source' => "str default 'site'", // site|phone|offline
                'created_by_user_id' => 'int null',
                'status' => "str default 'new'", // new|processing|shipped|done|canceled
                // хто взяв підзамовлення в роботу (мітка, не блокування)
                'assigned_user_id' => 'int null', 'assigned_at' => 'str null',
                'promo_code' => 'str null',
                'subtotal' => 'num default 0', 'discount' => 'num default 0', 'total' => 'num default 0',
                /*
                 * Оплата.
                 *
                 * Досі станів оплати не було взагалі: у роздрібі гроші й товар
                 * зустрічаються в одну мить, і питання «чи заплачено» не
                 * виникало. З розрахунком за рахунком воно виникає одразу:
                 * рахунок виставили сьогодні, гроші прийшли за три дні, товар
                 * поїхав на четвертий.
                 *
                 * paid_at — коли гроші фактично отримані. Саме цей момент, а не
                 * виставлення рахунку, вирішує, коли пробивати чек (якщо він
                 * узагалі потрібен) і коли можна відвантажувати.
                 */
                'payment_kind' => 'str null',   // cash|card|invoice|cod — чим розраховуються
                'paid_at' => 'ts null',
                // Реквізити покупця для рахунку. Живуть у замовленні, а не в
                // акаунті: сьогодні людина купує собі, завтра — на свій ФОП, і
                // документи в цих двох випадках різні.
                'buyer_type' => 'str null',     // person|ep|general — див. Invoice::BUYER_TYPES
                'buyer_name' => 'str null',     // назва як у документах
                'buyer_tax_id' => 'str null',   // ІПН або ЄДРПОУ
                'created_at' => 'ts',
            ],
            // Адреси доставки, збережені покупцем. Отримувача не зберігаємо навмисно —
            // його вказують у кожному замовленні окремо (див. Addresses).
            'user_addresses' => [
                'id' => 'id', 'user_id' => 'int',
                'label' => 'str null',              // «Дім», «Робота» — необовʼязкова мітка
                'delivery' => "str default 'np'",   // np|other; самовивіз адреси не потребує
                'city' => 'str null', 'city_ref' => 'str null', 'np_office' => 'str null',
                // Ті самі поля, що й у замовленні: збережена адреса має бути
                // придатною до накладної, інакше швидкий вибір лише економить
                // друкування, а відділення все одно доводиться обирати заново
                'np_office_ref' => 'str null', 'np_type' => "str default 'warehouse'",
                'np_street' => 'str null', 'np_street_ref' => 'str null',
                'np_house' => 'str null', 'np_flat' => 'str null',
                'address' => 'str null',
                'is_default' => 'bool default 0',
                'used_at' => 'str null', 'created_at' => 'ts',
            ],
            'order_items' => [
                'id' => 'id', 'order_id' => 'int', 'product_id' => 'int null', 'variant_id' => 'int null',
                'title' => 'str', 'variant_name' => 'str null', 'price' => 'num', 'qty' => 'int', 'sum' => 'num',
                // Скільки з qty вдалося зняти зі складу магазину. Менше за qty —
                // позицію продали понад залишок, і продавцю є що робити: передати
                // її іншій точці або довиробити. NULL — замовлення старіше за цей
                // облік, тоді поводимось як раніше й вважаємо, що взяли все.
                'stock_taken' => 'int null',
            ],
            /**
             * Спроба оплати карткою на сайті (інтернет-еквайринг).
             *
             * Окрема таблиця, а не стовпці в замовленні, — і це не про
             * акуратність, а про те, як поводиться шлюз. Він відхиляє повторний
             * OrderID (код 412), а покупець, у якого не пройшла картка, майже
             * завжди пробує другу. Тому спроб на одне замовлення буває кілька,
             * у кожної свій номер, свій код відмови й своя доля.
             *
             * Платіж належить ГОЛОВНОМУ замовленню, а не підзамовленню: покупець
             * платить одну суму за весь кошик однією операцією, і розділити її
             * між двома продавцями на боці банку неможливо. Розподіл виручки
             * між власниками — питання розрахунків, а не картки.
             *
             * merchant_id, terminal_id, env і provider дублюють налаштування
             * НАВМИСНО: реквізити міняються (тестовий термінал → робочий, зміна
             * банку), і старі платежі мають лишатись придатними до повернення.
             * Повернення робиться тим самим терміналом, що й списання.
             *
             * purchase_time — рядок, а не дата: він бере участь у підписі
             * КОЖНОГО подальшого запиту, і відтворити його з created_at не
             * вийде — там інша точність.
             */
            'payments' => [
                'id' => 'id',
                'parent_id' => 'int',                   // головне замовлення
                'order_ref' => 'str unique',            // OrderID для шлюзу
                'attempt' => 'int default 1',
                'provider' => "str default 'raiffeisen'",
                'env' => "str default 'test'",          // test|prod
                'merchant_id' => 'str null', 'terminal_id' => 'str null',
                'amount' => 'num', 'currency' => "str default '980'",
                'purchase_time' => 'str',               // yyMMddHHmmss, як пішло в підпис
                'hold' => 'bool default 0',             // преавторизація (Delay=1)
                'status' => "str default 'new'",        // new|sent|held|paid|failed|refunded
                // Відповідь шлюзу. tran_code — код завершення ('000' = успіх),
                // approval_code і rrn потрібні для повернення через API, без них
                // гроші повертають лише руками в кабінеті банку.
                'tran_code' => 'str null', 'approval_code' => 'str null', 'rrn' => 'str null',
                'xid' => 'str null', 'proxy_pan' => 'str null',
                'wallet' => 'str null',                 // APPLE_PAY|GOOGLE_PAY
                'card_scheme' => 'str null',
                'error' => 'str null',
                'refunded' => 'num default 0',          // скільки вже повернено
                'paid_at' => 'ts null', 'notified_at' => 'ts null',
                'raw' => 'text null',                   // відповідь шлюзу без підпису
                'created_at' => 'ts', 'updated_at' => 'ts null',
            ],
            /**
             * Накладна Нової Пошти.
             *
             * Одна на підзамовлення, а не на замовлення: кожен магазин відправляє
             * свою частину зі свого відділення, і фізично це різні посилки з
             * різними номерами. Одного номера на дві коробки з різних міст не
             * буває, тож і в базі його немає.
             *
             * Рядок лишається й після доставки — це історія відправлення, а не
             * стан. Видаляється лише разом зі скасуванням накладної в НП.
             */
            'shipments' => [
                'id' => 'id',
                'order_id' => 'int',                    // підзамовлення (parent_id IS NOT NULL)
                'parent_id' => 'int',                   // головне — щоб кабінет читав одним запитом
                // Перевізник поки один, але поле є: додати «Укрпошту» дешевше,
                // ніж потім розводити дві таблиці з однаковим змістом.
                'carrier' => "str default 'np'",
                'number' => 'str',                      // ТТН — те, що покупець вводить у трекінг
                'doc_ref' => 'str null',                // Ref накладної в НП; у вписаних руками порожній
                'source' => "str default 'api'",        // api|manual — звідки номер
                'service' => "str default 'warehouse'", // warehouse|courier
                'payer' => "str default 'Recipient'",   // хто платить за доставку
                'payment' => "str default 'Cash'",      // Cash|NonCash
                'cod' => 'num default 0',               // післяплата: скільки грошей везуть назад
                'weight' => 'num default 0', 'seats' => 'int default 1',
                'description' => 'str null',            // опис вантажу для накладної
                'cost' => 'num default 0',              // оголошена вартість
                'delivery_cost' => 'num default 0',     // скільки НП порахувала за доставку
                'estimated_at' => 'str null',           // орієнтовна дата доставки
                // Останнє, що сказала НП. phase — той самий статус, зведений до
                // шести станів, якими користується решта коду (див. NovaPoshta).
                'status_code' => 'int null', 'status_text' => 'str null',
                'phase' => "str default 'new'",
                'tracked_at' => 'str null', 'delivered_at' => 'str null',
                // Про що покупцю вже сказали: без цього кожен прохід трекінгу
                // слав би «посилка у відділенні» повторно, поки її не заберуть.
                'notified_phase' => 'str null',
                'created_by_user_id' => 'int null',
                'created_at' => 'ts', 'updated_at' => 'str null',
            ],
            /**
             * Фіскальний чек.
             *
             * Теж на підзамовлення, і з тієї самої причини, що й накладна:
             * гроші отримує конкретна точка своєю касою, тож замовлення на два
             * магазини — це два продажі й два чеки.
             *
             * tag — наш незмінний ідентифікатор запиту, і саме він робить
             * повтор безпечним: ПРРО впізнає за ним ту саму спробу й віддає
             * той самий чек замість другого. Тому рядок створюється ДО запиту,
             * а payload зберігає рівно те, що ми надіслали, — інакше повтор
             * після обриву зв’язку відправляв би вже інший чек.
             *
             * provider і route пишемо в самому чеку, а не читаємо з налаштувань
             * при потребі. Постачальника ПРРО міняють — і це нормально, — але
             * чек, пробитий у Вчасно, повертається теж у Вчасно: повернення
             * робить той самий ПРРО. Запис у рядку робить перехід безболісним:
             * старі чеки далі знають свого постачальника, нові йдуть до нового.
             *
             * status:
             *   queued  — чекає, поки його забере агент точки або браузер
             *             продавця (у маршрутах agent/device наш сервер до каси
             *             не ходить взагалі);
             *   pending — надіслали, відповіді не було. Чек МІГ пробитись,
             *             тому повторюємо тим самим tag;
             *   done    — є фіскальний номер;
             *   error   — ПРРО відмовив по суті, без людини не обійтись.
             */
            'fiscal_receipts' => [
                'id' => 'id',
                'order_id' => 'int',                 // підзамовлення (parent_id IS NOT NULL)
                'parent_id' => 'int',                // головне — щоб картка читала одним запитом
                'store_id' => 'int null',            // чия каса пробила
                'provider' => "str default 'vchasno'",
                'route' => "str default 'cloud'",    // cloud|agent|device
                'type' => "str default 'sell'",      // sell|return|service
                /*
                 * Що саме робимо. Документи (sell/return) належать замовленню,
                 * службові завдання — касі: відкрити зміну, зняти Z-звіт,
                 * внести чи видати готівку.
                 *
                 * Вони живуть в одній таблиці навмисно. Черга до каси, мітка
                 * проти дублів, повтор після обриву, стани — усе це в них
                 * спільне до останнього рядка, а друга така сама таблиця
                 * означала б дві копії найтоншого коду в системі. Ціна —
                 * order_id = 0 у службових рядків; зовнішніх ключів тут немає,
                 * тож це нічого не ламає.
                 */
                'task' => "str default 'sell'",      // sell|return|shift_open|shift_close|x_report|cash_in|cash_out
                'of_receipt_id' => 'int null',       // для повернення — чек продажу, який повертаємо
                'tag' => 'str',                      // мітка запиту: за нею ПРРО впізнає повтор
                'payload' => 'text null',            // що саме надіслали — тіло для повтору
                'doc' => 'text null',                // нейтральний чек: із нього будується тіло під будь-якого постачальника
                'status' => "str default 'queued'",  // queued|pending|done|error
                // Текстом, а не рядком: ПРРО пояснює відмову разом із деталями
                // валідації («сума 10050, рядки 10000»), і обрізати саме те,
                // заради чого це повідомлення читають, було б знущанням.
                'error' => 'text null',
                'attempts' => 'int default 0',
                // Те, що повернув ПРРО. fiscal_number друкується в чеку й за ним
                // чек шукають у ДПС; rro_number — сама каса; shift_link — зміна,
                // у яку чек потрапив (за нею він і закривається Z-звітом).
                'fiscal_number' => 'str null', 'rro_number' => 'str null',
                'shift_link' => 'int null', 'doc_no' => 'int null',
                'receipt_dt' => 'str null',          // час чека за ПРРО, YYYYMMDDHHMMSS
                // Посилання на електронний чек для покупця. Теж текстом: у ньому
                // сама адреса, номер, дата, сума й 64-символьний підпис — у 255
                // символів воно впирається впритул.
                'qr' => 'text null',
                'cancel_id' => 'str null',           // ідентифікатор для скасування операції
                // Тестова каса пробиває чеки без юридичної сили, офлайн-чек іде
                // в ДПС пізніше. Обидва треба бачити очима, а не здогадуватись.
                'is_offline' => 'bool default 0', 'is_test' => 'bool default 0',
                'sum' => 'num default 0', 'pay_type' => 'int default 0', 'change' => 'num default 0',
                // Відповідь каси на службове завдання: сума готівки після
                // внесення, номер зміни після Z-звіту. Для чеків усе потрібне
                // вже розкладено по стовпцях вище.
                'result' => 'text null',
                // ПРРО ДПС: поточний крок документа (що підписати далі, з яким
                // локальним номером і часом) — див. DpsFlow
                'step' => 'text null',
                'created_by_user_id' => 'int null',
                'created_at' => 'ts', 'updated_at' => 'str null',
            ],
            // Історія замовлення: розділення, зміни статусів, передачі позицій між магазинами.
            // parent_id — завжди головне замовлення, щоб уся стрічка читалась одним запитом.
            'order_events' => [
                'id' => 'id', 'parent_id' => 'int', 'order_id' => 'int null', 'user_id' => 'int null',
                'type' => 'str', // created|status|transfer|note|shipment|fiscal
                'role' => 'str null', // роль, у якій діяли
                'message' => 'text null', 'created_at' => 'ts',
            ],
            /**
             * «Повідомте, коли зʼявиться». Запит завжди належить акаунту: канали
             * сповіщень людина обирає в кабінеті, а в гостя кабінету немає.
             *
             * store_id — не адреса доставки, а побажання: звідки людині зручно
             * забрати. Повідомляємо однаково про будь-яку точку, бо чекати
             * конкретну — майже завжди довше, ніж треба.
             */
            'stock_requests' => [
                'id' => 'id', 'product_id' => 'int', 'variant_id' => 'int null',
                'store_id' => 'int null', 'user_id' => 'int',
                'created_at' => 'ts', 'notified_at' => 'ts null',
            ],
            /**
             * Торг: одна розмова про одну позицію.
             *
             * Умови зберігаються поточні — qty й price після останнього ходу, —
             * а не історія: історія лежить у offer_rounds, і рахувати за нею
             * «чия зараз черга» на кожному показі картки товару означало б
             * читати всю розмову заради двох чисел.
             *
             * turn відповідає на єдине питання, яке має і покупець, і продавець:
             * від кого зараз чекають ходу. Статус відповідає на інше — чи
             * розмова ще жива. Жоден із них не виводиться з іншого: 'open' із
             * turn = 'buyer' означає «магазин уже відповів», а 'open' із
             * turn = 'seller' — «лежить у черзі й чекає».
             *
             * list_price — вітринна ціна на момент ходу. Без неї за місяць не
             * сказати, чи 480 було поступкою: ціна товару відтоді могла
             * змінитись двічі.
             *
             * expires_at працює двічі й означає різне: доки розмова чекає
             * відповіді (Offers::OPEN_DAYS) і доки діє вже погоджена ціна
             * (offers_hold_hours). Це навмисно одне поле — питання в обох
             * випадках те саме: коли цей рядок перестає щось значити.
             */
            'offers' => [
                'id' => 'id', 'product_id' => 'int', 'variant_id' => 'int null', 'user_id' => 'int',
                'qty' => 'int', 'price' => 'num', 'list_price' => 'num null',
                'turn' => "str default 'seller'", 'status' => "str default 'open'",
                'rounds' => 'int default 0', 'answered_by' => 'int null', 'order_id' => 'int null',
                // Два сліди того, що ми вже написали про цей рядок, і вони про
                // різне. reminded_at — «нагадали, поки розмова жива» (продавцю,
                // що чекають на нього; покупцю, що ціна спливає). notified_at —
                // «сказали, що все скінчилось». Перший обнуляється кожним ходом:
                // після відповіді нагадувати треба заново й з нуля. Другий не
                // обнуляється ніколи — закриття буває один раз.
                'reminded_at' => 'ts null', 'notified_at' => 'ts null',
                'expires_at' => 'ts null', 'created_at' => 'ts', 'updated_at' => 'ts',
            ],
            // Хід розмови. Зберігаємо кожен, разом із коментарем: цифри кажуть,
            // на чому зійшлись, а коментар — чому («беру на подарунки всьому
            // відділу»), і саме він вирішує наступного разу.
            'offer_rounds' => [
                'id' => 'id', 'offer_id' => 'int', 'side' => 'str', 'action' => 'str',
                'qty' => 'int null', 'price' => 'num null', 'note' => 'text null',
                'user_id' => 'int null', 'created_at' => 'ts',
            ],
            /*
             * Диплом випускника — і два звʼязки, без яких він лишається папірцем.
             *
             * student і course — вільний текст, і це навмисно лишається: диплом
             * видають і тим, хто акаунта не заводив, а назва курсу на бланку
             * має пережити перейменування товару в каталозі. Але самого лише
             * тексту замало: за іменем кабінет не відповість на питання «які
             * дипломи мої» — тезки існують, а прізвища міняються.
             *
             * Тому user_id (чий це диплом) і product_id (за який курс). Обидва
             * необовʼязкові: диплом без акаунта — звичайна річ, він просто не
             * зʼявиться в жодному кабінеті, доки його не звʼяжуть.
             */
            'diplomas' => [
                'id' => 'id', 'number' => 'str unique', 'student' => 'str', 'course' => 'str null',
                'user_id' => 'int null', 'product_id' => 'int null',
                'issued_at' => 'str null', 'active' => 'bool default 1',
            ],
            /*
             * Доступ до купленого курсу.
             *
             * Окрема таблиця, а не запит «курси в оплачених замовленнях», бо
             * доступ БУВАЄ СТРОКОВИЙ: курс відкривається на кілька місяців, і
             * дату кінця треба десь тримати. Похідний запит її не виразить, а
             * ще й не переживе повернення грошей — рядок доступу можна закрити,
             * замовлення ж переписувати заднім числом не можна.
             *
             * expires_at порожнє означає «безстроково»: так продаються курси,
             * доступ до яких лишається назавжди.
             */
            'course_access' => [
                'id' => 'id', 'user_id' => 'int', 'product_id' => 'int', 'order_id' => 'int null',
                'granted_at' => 'ts', 'expires_at' => 'str null',
            ],
            'posts' => [
                'id' => 'id', 'user_id' => 'int null', 'title' => 'str', 'slug' => 'str unique',
                'excerpt' => 'text null', 'body' => 'text null', 'image' => 'str null',
                'published' => 'bool default 0', 'created_at' => 'ts',
            ],
            'settings' => [ 'key' => 'str primary', 'value' => 'text null' ],
            'content_blocks' => [
                'key' => 'str primary', 'title' => 'str null', 'body' => 'text null', 'image' => 'str null',
            ],
            // Особистий вибір користувача: які події й якими каналами він хоче отримувати.
            // Рядок зʼявляється лише коли людина щось ВИМКНУЛА — відсутність рядка означає
            // згоду. Так налаштування адміна лишається стелею, а це — фільтром під нею.
            'user_notify_prefs' => [
                'id' => 'id', 'user_id' => 'int', 'event' => 'str', 'channel' => 'str',
                'enabled' => 'bool default 1',
            ],
            /*
             * Способи, якими дозволено входити в акаунт (див. LoginMethods).
             *
             * Присутність рядка = дозволено. Жодного рядка = вибору не робили,
             * і тоді дозволені всі налаштовані способи — інакше оновлення схеми
             * зачинило б двері всім одразу.
             *
             * Окрема таблиця, а не стовпець-перелік у users: способів кілька,
             * вони додаються, і питання «хто дозволив собі вхід поштою» має
             * лишатись звичайним запитом, а не розбором рядка.
             */
            'user_login_methods' => [
                'id' => 'id', 'user_id' => 'int', 'method' => 'str', 'created_at' => 'ts',
            ],
            'notification_rules' => [
                'id' => 'id', 'event' => 'str', 'channel' => 'str', // telegram|push|email
                'enabled' => 'bool default 1',
                'recipients' => "str default 'admins'", // admins|sellers|admins_sellers
                'template' => 'text null',
            ],
            'push_subscriptions' => [
                'id' => 'id', 'user_id' => 'int', 'endpoint' => 'text', 'p256dh' => 'str', 'auth' => 'str', 'created_at' => 'ts',
            ],
            'auth_tokens' => [
                'id' => 'id', 'user_id' => 'int null',
                'purpose' => 'str', // tg_link|tg_login|viber_link|phone_code|email_code
                'token' => 'str unique', 'code' => 'str null', 'phone' => 'str null',
                'email' => 'str null',                      // адреса, на яку пішов код входу
                'chat_id' => 'str null', 'confirmed_user_id' => 'int null',
                'ip' => 'str null', 'agent' => 'str null',  // звідки почався вхід — показуємо в боті
                'expires_at' => 'str', 'used' => 'bool default 0', 'created_at' => 'ts',
            ],
            // Підписники розсилки: email гостя або зареєстрованого користувача.
            // token — для відписки одним кліком з листа, без входу на сайт.
            'subscribers' => [
                'id' => 'id', 'email' => 'str unique', 'name' => 'str null', 'user_id' => 'int null',
                'source' => "str default 'checkout'", // checkout|profile
                'active' => 'bool default 1', 'token' => 'str unique',
                'created_at' => 'ts', 'unsubscribed_at' => 'str null',
            ],
            // Лічильник звернень для обмеження частоти (боти, перебір, спам замовленнями)
            'rate_hits' => [
                'id' => 'id', 'action' => 'str', 'ident' => 'str', 'created_at' => 'ts',
            ],
            /*
             * Журнал входів і змін доступів (див. AuthLog).
             *
             * user_id — кого стосується, actor_id — хто зробив. Вони різні рівно
             * тоді, коли це найважливіше: адміністратор видав комусь права або
             * вимкнув чужий акаунт. Один стовпець замість двох перетворив би
             * «Петро зняв права Олені» на «щось сталося з Оленою».
             *
             * Секретів тут немає за побудовою: у detail лежить опис словами
             * («змінено ключ Нової Пошти»), а не значення. Журнал читає людина,
             * і він потрапляє в кожен дамп бази.
             */
            'auth_log' => [
                'id' => 'id', 'user_id' => 'int null', 'actor_id' => 'int null',
                'event' => 'str', 'detail' => 'text null',
                'ip' => 'str null', 'agent' => 'str null', 'created_at' => 'ts',
            ],
            'migrations_log' => [ 'id' => 'id', 'name' => 'str', 'ran_at' => 'ts' ],
        ];
    }

    public static function createAll(): void
    {
        $driver = DB::driver();
        foreach (self::tables() as $table => $cols) {
            $sql = self::createSql($table, $cols, $driver);
            DB::pdo()->exec($sql);
        }
        // індекси
        $idx = [
            // parent_id — за ним збирається гілка розділу на кожному відкритті каталогу
            'categories' => ['parent_id'],
            // sku/barcode — те, за чим шукає каса: точний збіг на кожен скан
            'products' => ['category_id', 'active', 'featured', 'sku', 'barcode', 'age_min', 'ext_id'],
            'reviews' => ['approved', 'source'],
            'product_variants' => ['product_id', 'sku', 'barcode'],
            'product_images' => ['product_id', 'variant_id'],
            'product_attrs' => ['product_id', 'attribute_id'],
            'product_brands' => ['product_id', 'brand_id'],
            'attribute_values' => ['attribute_id'],
            'attribute_categories' => ['attribute_id', 'category_id'],
            'variant_options' => ['variant_id', 'attribute_id'],
            'store_prices' => ['product_id', 'store_id', 'variant_id'],
            'store_stock' => ['product_id', 'store_id', 'variant_id'],
            'orders' => ['status', 'store_id', 'user_id', 'token', 'parent_id'],
            'order_events' => ['parent_id'],
            // number — за ним шукає трекінг, phase — за нею відбираються ті
            // накладні, які ще має сенс перепитувати
            'shipments' => ['order_id', 'parent_id', 'number', 'phase'],
            // tag — за ним впізнаємо повтор, status — за ним cron відбирає
            // чеки, які лишились без відповіді
            'fiscal_receipts' => ['order_id', 'parent_id', 'tag', 'status', 'of_receipt_id'],
            'rate_hits' => ['action', 'ident', 'created_at'],
            // user_id — «покажи все про цю людину», created_at — «покажи, що
            // було того дня». Саме ці два питання й ставлять до журналу.
            'auth_log' => ['user_id', 'actor_id', 'event', 'created_at'],
            'subscribers' => ['token'],
            // «Які курси мої» і «кому відкритий цей курс» — обидва питання
            // ставить кабінет, і обидва мають відповідатись без перебору
            'course_access' => ['user_id', 'product_id'],
            // «Мої сертифікати» шукає за акаунтом; product_id — щоб побачити,
            // скільки дипломів видано за конкретним курсом
            'diplomas' => ['user_id', 'product_id'],
            'order_items' => ['order_id'],
            // order_ref — за ним шлюз упізнає платіж у NOTIFY; status — за ним
            // cron відбирає спроби, які лишились без відповіді
            'payments' => ['parent_id', 'order_ref', 'status'],
            'seller_stores' => ['user_id', 'store_id'],
            'user_roles' => ['user_id', 'role'],
            'user_notify_prefs' => ['user_id'],
            'user_login_methods' => ['user_id'],
            'user_addresses' => ['user_id'],
            'promo_uses' => ['promo_id', 'user_id', 'phone'],
            'qty_discounts' => ['product_id', 'category_id'],
            'bundle_items' => ['bundle_id', 'product_id'],
            'stock_requests' => ['product_id', 'user_id', 'notified_at'],
            // status і turn — те, за чим черга продавця відбирає свої рядки;
            // user_id — за ним кабінет покупця збирає його розмови
            'offers' => ['product_id', 'user_id', 'status', 'turn'],
            'offer_rounds' => ['offer_id'],
        ];
        foreach ($idx as $table => $columns) {
            foreach ($columns as $col) {
                $name = "idx_{$table}_{$col}";
                try { DB::pdo()->exec("CREATE INDEX $name ON $table ($col)"); }
                catch (Throwable $e) { /* вже існує */ }
            }
        }
        // Телефон — це логін (вхід за номером, склеювання акаунтів у BotAuth),
        // тож двох власників у нього бути не може. Перевірки в коді вже є, але
        // код можна обійти новим шляхом, а індекс — ні. NULL тут не заважає:
        // і MySQL, і SQLite дозволяють у UNIQUE скільки завгодно NULL.
        try { DB::pdo()->exec('CREATE UNIQUE INDEX uniq_users_phone ON users (phone)'); }
        catch (Throwable $e) { /* вже існує або є дублікати — розбирається міграція 17 */ }
    }

    private static function createSql(string $table, array $cols, string $driver): string
    {
        $defs = [];
        foreach ($cols as $name => $spec) {
            $defs[] = self::colSql($name, $spec, $driver);
        }
        $q = $driver === 'sqlite' ? '"' : '`';
        $sql = "CREATE TABLE IF NOT EXISTS {$q}{$table}{$q} (" . implode(', ', $defs) . ")";
        if ($driver === 'mysql') $sql .= " ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        return $sql;
    }

    private static function colSql(string $name, string $spec, string $driver): string
    {
        $q = $driver === 'sqlite' ? '"' : '`';
        $parts = preg_split('/\s+/', trim($spec));
        $type = array_shift($parts);
        $rest = implode(' ', $parts);
        $null = str_contains($rest, 'null') && !str_contains($rest, 'not null');
        $unique = str_contains($rest, 'unique');
        $primary = str_contains($rest, 'primary');
        $default = null;
        if (preg_match("/default\s+('([^']*)'|\S+)/", $rest, $m)) $default = $m[1];

        if ($type === 'id') {
            return $driver === 'sqlite'
                ? "{$q}{$name}{$q} INTEGER PRIMARY KEY AUTOINCREMENT"
                : "{$q}{$name}{$q} BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY";
        }
        $sqlType = match (true) {
            $type === 'int' => $driver === 'sqlite' ? 'INTEGER' : 'BIGINT',
            $type === 'num' => $driver === 'sqlite' ? 'NUMERIC' : 'DECIMAL(12,2)',
            // Координата. Окремо від num, бо там два знаки після коми — гроші.
            // Для широти й довготи два знаки це похибка близько кілометра, тобто
            // мітка стане не на магазин, а на сусідній квартал. Сім знаків —
            // сантиметри, з запасом на будь-яку адресу.
            $type === 'geo' => $driver === 'sqlite' ? 'REAL' : 'DECIMAL(10,7)',
            // Відсоток знижки. Два знаки не дають ціни до копійки: оптова ціна
            // 945 ₴ від 1120 ₴ — це 15.625 %, а 15.63 % дає 944.94 ₴. Чотири
            // знаки відтворюють будь-яку ціну в гривнях точно.
            $type === 'pct' => $driver === 'sqlite' ? 'NUMERIC' : 'DECIMAL(9,4)',
            $type === 'str' => $driver === 'sqlite' ? 'TEXT' : 'VARCHAR(255)',
            $type === 'text' => $driver === 'sqlite' ? 'TEXT' : 'MEDIUMTEXT',
            $type === 'bool' => $driver === 'sqlite' ? 'INTEGER' : 'TINYINT(1)',
            $type === 'ts' => $driver === 'sqlite' ? 'TEXT' : 'DATETIME',
            default => 'TEXT',
        };
        $sql = "{$q}{$name}{$q} $sqlType";
        if ($primary) $sql .= ' PRIMARY KEY';
        elseif (!$null) $sql .= ' NOT NULL';
        if ($default !== null) $sql .= " DEFAULT $default";
        if ($unique) $sql .= ' UNIQUE';
        return $sql;
    }
}
