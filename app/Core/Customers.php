<?php
declare(strict_types=1);

/**
 * Покупець за номером телефону.
 *
 * Потрібно там, де замовлення оформлює не сам покупець, а продавець: по
 * телефону або на місці в точці. Питання щоразу одне — чий це запис.
 *
 * Номер — єдине стале імʼя покупця в цій системі: за ним він входить, за ним
 * рахуються ліміти промокодів, за ним склеюються записи з месенджерів (див.
 * BotAuth::resolveUser). Тому правило тут те саме: є номер — акаунт знайдеться
 * або зʼявиться, і замовлення ляже в історію тієї самої людини, а не в нікуди.
 *
 * Номера немає — замовлення лишається анонімним (user_id = NULL). Це не збій,
 * а нормальний продаж у точці: людина зайшла, взяла банку меду, розрахувалась
 * і пішла. Вигадувати їй акаунт нема з чого, а порожній акаунт «Покупець №17»
 * лише засмічує список користувачів і чіпляє чужі замовлення до одного запису.
 */
class Customers
{
    /**
     * Пошук покупця за номером — без жодних створень.
     *
     * Потрібен там, де продавець ще тільки питає «а ви в нас були?»: спершу
     * показуємо, кого знайшли, і лише за його підтвердженням щось робимо.
     *
     * @param ?string $phone нормалізований номер (AuthTokens::normPhoneAny)
     */
    public static function find(?string $phone): ?array
    {
        if ($phone === null || $phone === '') return null;
        return DB::row('SELECT * FROM users WHERE phone = ? ORDER BY id LIMIT 1', [$phone]);
    }

    /**
     * Чи має цей запис право отримувати замовлення, оформлені на його номер.
     *
     * Два випадки, і обидва — про те, що номером не можна заволодіти з
     * клавіатури:
     *
     *  1. Номер підтверджений (phone_verified_at) — контактом із Telegram, який
     *     месенджер засвідчив сам.
     *  2. Запис завів продавець у точці: технічна адреса @offline.local і
     *     жодного каналу входу. У такий акаунт НІХТО не може увійти — код
     *     поштою на .local не піде (EmailAuth::normEmail), месенджера немає,
     *     Google немає. Це записник продавця, а не чийсь кабінет, і чіпляти до
     *     нього продажі безпечно: побачить їх лише той, хто доведе номер і тим
     *     самим цей запис успадкує (BotAuth::resolveUser склеює за номером).
     *
     * Усе інше — акаунт, у який хтось входить, із номером, вписаним руками.
     * Такому номеру віри немає: вписати можна чужий, і саме так витікали б
     * замовлення разом з адресою й сумами.
     */
    public static function ownsPhone(array $user): bool
    {
        if (!empty($user['phone_verified_at'])) return true;
        return empty($user['google_id'])
            && empty($user['tg_chat_id'])
            && empty($user['viber_id'])
            && empty($user['email_verified_at'])
            && str_ends_with(mb_strtolower((string)$user['email']), '@offline.local');
    }

    /**
     * Забрати собі власні замовлення, оформлені до підтвердження номера.
     *
     * Гість оформлює замовлення без входу — воно лишається з user_id = NULL і
     * доступне лише за посиланням із токеном. Коли та сама людина згодом
     * доводить свій номер, ці замовлення мають опинитись у її кабінеті: інакше
     * «історія замовлень» починається з нуля саме в постійного покупця.
     *
     * Робиться це ЛИШЕ за доведеним номером і лише над анонімними записами:
     * чуже замовлення, яке вже комусь належить, не перечіпляється ніколи.
     *
     * @param string $phone нормалізований номер (як його зберігає Checkout)
     * @return int скільки замовлень причепилось
     */
    public static function claimOrders(int $userId, string $phone): int
    {
        if ($phone === '') return 0;
        $rows = DB::all('SELECT id FROM orders WHERE user_id IS NULL AND phone = ?', [$phone]);
        if (!$rows) return 0;
        DB::query('UPDATE orders SET user_id = ? WHERE user_id IS NULL AND phone = ?', [$userId, $phone]);
        return count($rows);
    }

    /**
     * Те саме, але за доведеною скринькою.
     *
     * Половина покупців приходить не через месенджер, а через пошту — і для них
     * claimOrders() не спрацьовував ніколи, бо шукає лише за номером. Виходило,
     * що людина замовила гостем, зареєструвалась тією самою адресою й побачила
     * порожню історію, хоча її замовлення лежать поруч із її ж поштою в
     * orders.email.
     *
     * Умови ті самі, що й для номера, і послаблювати їх не можна:
     *
     *  — адреса має бути ДОВЕДЕНОЮ. Викликати це можна лише там, де скринька
     *    щойно себе підтвердила: код із листа (EmailAuth) або Google, який
     *    віддає тільки підтверджені адреси. Вписана в профілі адреса не
     *    доводить нічого — рівно як і вписаний руками номер.
     *  — чіпляємо лише анонімні записи. Замовлення, яке вже комусь належить,
     *    не перечіпляється ніколи.
     *
     * Адресу порівнюємо як є: і Checkout, і EmailAuth женуть її через
     * Newsletter::normEmail, тож у базі вона в одному вигляді.
     *
     * @param string $email нормалізована адреса
     * @return int скільки замовлень причепилось
     */
    public static function claimOrdersByEmail(int $userId, string $email): int
    {
        $email = Newsletter::normEmail($email) ?? '';
        if ($email === '') return 0;
        $rows = DB::all('SELECT id FROM orders WHERE user_id IS NULL AND email = ?', [$email]);
        if (!$rows) return 0;
        DB::query('UPDATE orders SET user_id = ? WHERE user_id IS NULL AND email = ?', [$userId, $email]);
        return count($rows);
    }

    /**
     * Скільки замовлень у цієї людини — щоб продавець упізнав постійного покупця
     * ще до того, як почне продаж.
     */
    public static function orderCount(int $userId): int
    {
        return (int)DB::val('SELECT COUNT(*) FROM orders WHERE user_id = ? AND parent_id IS NULL', [$userId]);
    }

    /**
     * Акаунт покупця за номером: знайти, а як немає — створити.
     *
     * @param ?string $phone вже нормалізований номер (AuthTokens::normPhoneAny) або null
     * @param string  $name  імʼя зі слів продавця; порожнє — не біда
     * @return ?int id акаунта; null — покупець анонімний (номера не було)
     */
    public static function resolve(?string $phone, string $name = ''): ?int
    {
        if ($phone === null || $phone === '') return null;
        $name = trim($name);

        $user = DB::row('SELECT * FROM users WHERE phone = ? ORDER BY id LIMIT 1', [$phone]);
        if ($user) {
            if (!self::ownsPhone($user)) return null;
            // Імʼя з трубки не затирає введене самим покупцем: він міг назватись
            // у профілі як йому треба, а продавець записав зі слуху.
            if (trim((string)$user['name']) === '' && $name !== '') {
                DB::update('users', ['name' => $name], 'id = ?', [(int)$user['id']]);
            }
            return (int)$user['id'];
        }

        // Пошта потрібна схемі (email unique), а не людині: справжньої ми не
        // знаємо й вигадувати не маємо права — лист на неї не піде. Домен
        // .local відсіює Newsletter::normEmail, тож у розсилку така адреса не
        // потрапить, а Checkout не покаже її як «свою» в формі.
        $uid = DB::insert('users', [
            'email' => substr(md5($phone . '|' . random_bytes(8)), 0, 12) . '@offline.local',
            'name' => $name !== '' ? $name : 'Покупець',
            'role' => 'customer', 'active' => 1,
            'phone' => $phone, 'created_at' => now(),
        ]);
        Notify::fire('user_new', ['name' => $name !== '' ? $name : 'Покупець', 'email' => $phone]);
        return $uid;
    }
}
