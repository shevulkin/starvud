<?php
declare(strict_types=1);

/**
 * Курс — це товар, а не окрема сутність.
 *
 * Рішення не з економії: у курсу рівно ті самі питання, що й у банки меду, —
 * назва, опис, фото, ціна, знижка, промокод, кошик, оплата, чек, історія
 * покупок. Усе це в системі вже написане й перевірене. Окремий «модуль курсів»
 * означав би другий кошик, другий чекаут і другу історію замовлень, які
 * розходяться з першими на першій же правці.
 *
 * Тому курс — рядок у products із type = 'course'. Схема це передбачала від
 * початку (див. Schema: product|service|video|course), але жодна логіка на тип
 * не спиралась — він був лише підписом у формі. Тут той підпис стає поведінкою.
 *
 * Чим курс ВІДРІЗНЯЄТЬСЯ від банки меду — і що з цього випливає:
 *
 *  1. Його не буває «мало». Залишків у курсу немає й бути не може: продати
 *     доступ до відео можна скільки завгодно разів. Тому Cart::limit для нього
 *     не питає складу (див. там же).
 *  2. Його нікуди не везти. Нова Пошта, вага, накладна — усе це до курсу не
 *     стосується, і питати адресу в того, хто купує відео, означає питати
 *     нізащо. Звідси спосіб доставки 'digital' (OrderFlow::DELIVERY).
 *  3. Після оплати він має ВІДКРИТИСЬ. Цього ще немає — відео знімається, —
 *     але місце під це вже позначене: grantFor() кличеться там, де гроші
 *     підтверджені, і поки що лише пише в журнал.
 */
class Courses
{
    /** Значення products.type, яким курс і відрізняється від решти полиці */
    public const TYPE = 'course';

    public static function isCourse(?array $product): bool
    {
        return $product !== null && ($product['type'] ?? '') === self::TYPE;
    }

    /**
     * Курси для вітрини — ті, що активні.
     *
     * Порядок: спершу позначені «хіт», далі за назвою. Курсів одиниці, тож
     * окреме поле сортування тут було б формою, яку заповнюють раз і назавжди
     * забувають.
     */
    public static function all(): array
    {
        return DB::all(
            "SELECT * FROM products WHERE type = ? AND active = 1 ORDER BY featured DESC, name",
            [self::TYPE]);
    }

    public static function bySlug(string $slug): ?array
    {
        return DB::row('SELECT * FROM products WHERE slug = ? AND type = ? AND active = 1',
            [$slug, self::TYPE]);
    }

    /**
     * Чи в кошику самі лише курси.
     *
     * Саме «лише»: якщо поруч лежить банка меду, замовлення все одно треба
     * везти, і доставку питати доведеться. Питання не в тому, чи є курс, а в
     * тому, чи лишилось хоч щось фізичне.
     *
     * Порожній кошик цифровим не рахуємо: інакше чекаут порожнього кошика
     * вирішив би, що доставка не потрібна, і сховав би її ще до того, як
     * покупець щось поклав.
     */
    public static function cartIsDigital(?array $rows = null): bool
    {
        $rows ??= Cart::detailed();
        if (!$rows) return false;
        foreach ($rows as $r) {
            if (!self::isCourse($r['product'] ?? null)) return false;
        }
        return true;
    }

    /**
     * Курси в замовленні — тим і вирішується, чи є що відкривати.
     *
     * Шукаємо по всьому дереву, а не в одному рядку: замовлення ділиться між
     * магазинами, і позиції лежать у ПІДзамовленнях, а не в головному. Запит
     * лише по переданому id повертав нуль курсів для щойно оформленого
     * замовлення — тобто доступ не відкрився б жодного разу.
     *
     * Приймає і головне замовлення, і будь-яку його частину: викликають звідси
     * й звідти (оплата знає про частину, лист покупцю — про ціле).
     */
    public static function inOrder(int $orderId): array
    {
        $root = (int)(DB::val('SELECT COALESCE(parent_id, id) FROM orders WHERE id = ?', [$orderId]) ?: $orderId);
        return DB::all(
            "SELECT DISTINCT p.* FROM order_items i
                JOIN orders o ON o.id = i.order_id
                JOIN products p ON p.id = i.product_id
             WHERE (o.id = ? OR o.parent_id = ?) AND p.type = ?",
            [$root, $root, self::TYPE]);
    }

    /**
     * Відкрити доступ до курсів цього замовлення.
     *
     * Кличеться там, де гроші підтверджені, — не за статусом «Доставлено», який
     * ставить продавець рукою і який до цифрового замовлення й не застосовний.
     *
     * Гість без акаунта доступу не отримує, і вдавати протилежне нема сенсу:
     * показувати курс нема кому й ніде. Купівля не пропадає — замовлення лежить
     * із його поштою, і щойно він увійде тією ж адресою, Customers::
     * claimOrdersByEmail() віддасть замовлення йому; лишиться повторний виклик
     * звідси (див. claimAfterLogin).
     */
    public static function grantFor(int $orderId, ?int $userId): void
    {
        if (!$userId) return;
        foreach (self::inOrder($orderId) as $course) {
            self::grant($userId, (int)$course['id'], $orderId, $course['access_days'] ?? null);
        }
    }

    /**
     * Один доступ. Повторний виклик не плодить рядків і не вкорочує строк.
     *
     * Той самий курс купують удруге — наприклад, коли строк вийшов. Тоді
     * доступ ПОДОВЖУЄТЬСЯ, а не заводиться поруч другим рядком: інакше кабінет
     * показував би той самий курс двічі, а «до якої дати» стало б питанням із
     * двома відповідями.
     */
    public static function grant(int $userId, int $productId, ?int $orderId, $accessDays): void
    {
        $days = ($accessDays === null || $accessDays === '') ? null : max(1, (int)$accessDays);
        $until = $days === null ? null : date('Y-m-d H:i:s', time() + $days * 86400);

        $has = DB::row('SELECT * FROM course_access WHERE user_id = ? AND product_id = ?', [$userId, $productId]);
        if ($has) {
            // Безстроковий доступ строковим уже не робимо: відібрати те, що
            // людина купила назавжди, не може ані нова покупка, ані правка
            // строку в картці курсу.
            if ($has['expires_at'] === null || $until === null) {
                DB::update('course_access', ['expires_at' => null], 'id = ?', [(int)$has['id']]);
                return;
            }
            // Продовжуємо від пізнішої з двох дат, а не від «сьогодні»: інакше
            // друга покупка, зроблена завчасно, вкорочувала б доступ
            $base = max(strtotime((string)$has['expires_at']), time());
            DB::update('course_access', ['expires_at' => date('Y-m-d H:i:s', $base + $days * 86400)],
                'id = ?', [(int)$has['id']]);
            return;
        }
        DB::insert('course_access', [
            'user_id' => $userId, 'product_id' => $productId, 'order_id' => $orderId,
            'granted_at' => now(), 'expires_at' => $until,
        ]);
    }

    /**
     * Курси, відкриті цій людині: сам курс плюс строк.
     *
     * Протухлі не ховаємо — показуємо з позначкою. «Курс зник із кабінету» —
     * найгірше пояснення того, що строк вийшов: людина вирішує, що її обікрали,
     * а не що доступ скінчився.
     *
     * @return array<array{product:array,expires_at:?string,expired:bool}>
     */
    public static function forUser(int $userId): array
    {
        $rows = DB::all(
            "SELECT a.expires_at, p.* FROM course_access a
                JOIN products p ON p.id = a.product_id
             WHERE a.user_id = ? ORDER BY a.granted_at DESC", [$userId]);
        $out = [];
        foreach ($rows as $r) {
            $exp = $r['expires_at'];
            unset($r['expires_at']);
            $out[] = ['product' => $r, 'expires_at' => $exp,
                      'expired' => $exp !== null && strtotime((string)$exp) < time()];
        }
        return $out;
    }

    /**
     * Чи цей курс уже куплений — саме куплений, а не «відкритий зараз».
     *
     * Різниця істотна для того, що бачить людина. Кнопка «До кошика» на
     * курсі, за який уже заплачено, — найгірше, що може показати сторінка:
     * вона пропонує купити вдруге те, що вже твоє, і жодного натяку, куди йти
     * дивитись. Тому питання «чи купував» окреме від «чи не вийшов строк»:
     * протухлий курс купують ще раз свідомо, і кнопка тоді інша.
     */
    public static function owned(?int $userId, int $productId): bool
    {
        if (!$userId) return false;
        return DB::row('SELECT id FROM course_access WHERE user_id = ? AND product_id = ?',
            [$userId, $productId]) !== null;
    }

    /** Чи відкритий курс саме зараз — питання доступу до відео, а не до списку */
    public static function isOpen(int $userId, int $productId): bool
    {
        $r = DB::row('SELECT expires_at FROM course_access WHERE user_id = ? AND product_id = ?',
            [$userId, $productId]);
        if (!$r) return false;
        return $r['expires_at'] === null || strtotime((string)$r['expires_at']) >= time();
    }

    /**
     * Видати доступ за вже оплаченими замовленнями цієї людини.
     *
     * Потрібно там, де замовлення знайшло господаря пізніше за оплату: гість
     * купив курс, а кабінет завів згодом тією ж поштою (Customers::
     * claimOrdersByEmail). На момент оплати давати доступ не було кому.
     */
    public static function claimAfterLogin(int $userId): void
    {
        $orders = DB::all(
            "SELECT id FROM orders WHERE user_id = ? AND parent_id IS NULL AND paid_at IS NOT NULL",
            [$userId]);
        foreach ($orders as $o) self::grantFor((int)$o['id'], $userId);
    }
}
