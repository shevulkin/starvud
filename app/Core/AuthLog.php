<?php
declare(strict_types=1);

/**
 * Журнал входів і дій, які змінюють права чи доступи.
 *
 * Замовлення вже мають свою стрічку подій (OrderFlow::log) — там видно, хто
 * що зробив із конкретним замовленням. Але на питання «хто взагалі заходив у
 * кабінет і коли», «хто видав людині права адміністратора» й «хто змінив
 * платіжні реквізити» відповісти було нічим.
 *
 * Це не бюрократія: саме ці три питання ставлять першими, коли з'ясовують, як
 * саме сталося те, що сталося. Без журналу відповідь звучить як «не знаємо», і
 * далі розмова йде вже не про магазин.
 *
 * ЩО СЮДИ НЕ ПИШЕТЬСЯ:
 *
 *   • Паролі, токени, ключі — журнал читає людина, і він осідає в дампах бази.
 *     Замість значення пишеться сам факт: «змінено ключ Нової Пошти».
 *   • Кожен перегляд сторінки. Журнал, у якому мільйон рядків за тиждень, не
 *     читає ніхто — а отже, його немає.
 *
 * Записи не видаляються з часом навмисно: рядків тут одиниці на день, а
 * питання до них виникають через місяці.
 */
class AuthLog
{
    /** Що саме сталося — переклад для сторінки журналу */
    public const EVENTS = [
        'login'            => 'вхід',
        'logout'           => 'вихід',
        'session_expired'  => 'сесію закрито за строком',
        'login_failed'     => 'невдала спроба входу',
        'roles_changed'    => 'змінено ролі',
        'stores_changed'   => 'змінено доступ до точок',
        'user_disabled'    => 'акаунт вимкнено',
        'user_enabled'     => 'акаунт увімкнено',
        'settings_changed' => 'змінено налаштування',
        'secret_changed'   => 'змінено ключ інтеграції',
        'messenger_linked' => 'підключено месенджер',
        'login_methods_changed' => 'змінено дозволені способи входу',
        'login_blocked'    => 'вхід відхилено — спосіб вимкнений',
        'login_fallback'   => 'дозволені способи не працюють — відкрито всі налаштовані',
    ];

    /**
     * Записати подію.
     *
     * Ніколи не кидає винятків: журнал не має права зламати дію, яку він
     * описує. Невдалий запис у журнал — це втрачений рядок, а невдалий вхід
     * через невдалий запис — це людина, яка не може працювати.
     *
     * @param ?int   $userId кого стосується (null — невідомо, напр. невдалий вхід)
     * @param string $event  ключ із EVENTS
     * @param string $detail людський опис БЕЗ секретів
     * @param ?int   $actorId хто зробив, якщо це не сама людина
     */
    public static function write(?int $userId, string $event, string $detail = '', ?int $actorId = null): void
    {
        try {
            DB::insert('auth_log', [
                'user_id'  => $userId,
                'actor_id' => $actorId ?? $userId,
                'event'    => $event,
                'detail'   => $detail !== '' ? mb_substr($detail, 0, 500) : null,
                'ip'       => RateLimit::ip(),
                // Сирий User-Agent тут ні до чого: рядок на двісті символів
                // нічого не пояснює, а «Chrome на Windows» — пояснює
                'agent'    => AuthTokens::device($_SERVER['HTTP_USER_AGENT'] ?? ''),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            // Таблиці ще немає (перший запуск до міграції) або база недоступна.
            // У журнал помилок це пишемо, а дію не чіпаємо.
            @file_put_contents(BOFU_ROOT . '/storage/logs/app-error.log',
                '[' . date('Y-m-d H:i:s') . '] auth_log: ' . $e->getMessage() . "\n", FILE_APPEND);
        }
    }

    /** Останні події — для сторінки журналу в кабінеті */
    public static function recent(int $limit = 200, ?int $userId = null): array
    {
        $limit = max(1, min(1000, $limit));
        $where = $userId ? 'WHERE l.user_id = ?' : '';
        $args = $userId ? [$userId] : [];
        return DB::all(
            "SELECT l.*, u.name AS user_name, a.name AS actor_name
               FROM auth_log l
          LEFT JOIN users u ON u.id = l.user_id
          LEFT JOIN users a ON a.id = l.actor_id
               $where
           ORDER BY l.id DESC
              LIMIT " . $limit, $args);
    }

    public static function label(?string $event): string
    {
        return self::EVENTS[(string)$event] ?? ('подія «' . (string)$event . '»');
    }
}
