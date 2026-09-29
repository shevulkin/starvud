<?php
declare(strict_types=1);

/**
 * Реєстр ролей і прав.
 *
 * Права користувача — обʼєднання прав усіх його ролей. Роль «покупець» навмисно
 * порожня: це не право, а базовий стан. Каталог, кошик і оформлення замовлення
 * доступні навіть гостю без акаунта, тож «бути покупцем» нічого не додає —
 * воно лише ховає стафний інтерфейс.
 */
class Roles
{
    public const ADMIN    = 'admin';
    public const SELLER   = 'seller';
    /** Вирішує, з якої точки поїде онлайн-замовлення (див. MAP і Controllers\Admin\Dispatch) */
    public const DISPATCHER = 'dispatcher';
    public const EDITOR   = 'editor';
    public const CUSTOMER = 'customer';

    /** Усі права системи — джерело правди для карти маршрутів і перевірок у діях */
    public const CAPS = [
        'orders.view'         => 'Бачити замовлення',
        'orders.view_all'     => 'Бачити замовлення всіх магазинів (без права правити чужі)',
        'orders.status'       => 'Змінювати статус замовлення',
        'orders.assign'       => 'Брати замовлення в роботу',
        'orders.note'         => 'Додавати нотатки до замовлення',
        'orders.manage'       => 'Керувати замовленням цілком (передача між магазинами)',
        'orders.create'       => 'Оформлювати замовлення за покупця (дзвінок, продаж у точці)',
        // Накладна коштує грошей і створюється від імені магазину, тож це
        // окреме право, а не додаток до «змінювати статус»: подивитись і
        // повести замовлення можна й без нього
        'orders.ship'         => 'Створювати накладні Нової Пошти',
        // Чек — не «ще одна кнопка в замовленні»: він іде в ДПС, і повернення
        // теж. Тому окреме право, як і в накладної. Продавцю воно потрібне —
        // без нього він не зможе пробити чек за власним же продажем.
        'orders.fiscal'       => 'Пробивати фіскальні чеки (ПРРО)',
        // Онлайн-оплата: списати заблоковані кошти або повернути гроші на
        // картку. Окреме право й навмисно НЕ в наборі продавця: помилка тут
        // рухає чужі гроші в обидва боки й виправляється лише в банку, а не
        // натисканням «скасувати». Продавець при цьому й далі бачить, чи
        // оплачено замовлення, — на це права не треба.
        'orders.refund'       => 'Онлайн-оплата: списувати заблоковане й повертати кошти',
        // А це — сама каса: відкрити зміну, зняти Z-звіт, звірити товари.
        // Z-звіт закриває день усій точці, тож це вже не робота продавця.
        'fiscal.manage'       => 'Каса (ПРРО): зміна, звіти, звірка товарів',
        // Торг — це не «правити ціну магазину», а рішення про одну угоду з
        // однією людиною: погодитись, дати зустрічні умови, відмовити. Тому
        // окреме право: продавцю воно потрібне (він і веде розмову), а той,
        // хто лише виставляє ціни, у чужі переговори не втручається.
        'offers.manage'       => 'Торг: розглядати пропозиції ціни від покупців',
        'products.view'       => 'Бачити товари',
        'products.stock'      => 'Правити залишки',
        'products.price'      => 'Правити ціни магазину',
        'products.manage'     => 'Створювати й редагувати товари',
        'catalog.manage'      => 'Категорії та характеристики',
        'stores.all'          => 'Бачити всі магазини, а не лише призначені',
        'stores.manage'       => 'Керувати магазинами',
        'promos.manage'       => 'Акції та промокоди',
        'diplomas.manage'     => 'Дипломи',
        'users.manage'        => 'Користувачі та ролі',
        'subscribers.manage'  => 'Розсилка',
        'content.manage'      => 'Контент сайту',
        'media.manage'        => 'Медіа-бібліотека',
        'notifications.manage'=> 'Правила сповіщень',
        'settings.manage'     => 'Налаштування',
        'posts.manage'        => 'Пости блогу',
    ];

    /**
     * '*' — усі права. Порядок важливий: за ним обирається роль для показу в інтерфейсі.
     * `assignable` = чи пропонувати роль в /admin/users.
     */
    private const MAP = [
        self::ADMIN => [
            'label' => 'Адміністратор',
            'caps' => ['*'],
            'assignable' => true,
        ],
        self::SELLER => [
            'label' => 'Продавець',
            // orders.view_all — лише читання чужих точок: правити можна те, що у своїх
            // (це вирішує canManage по seller_stores, а не право)
            'caps' => ['orders.view', 'orders.view_all', 'orders.status', 'orders.assign',
                       'orders.note', 'orders.create', 'orders.ship', 'orders.fiscal',
                       'offers.manage',
                       'products.view', 'products.stock', 'products.price'],
            'assignable' => true,
        ],
        /*
         * Розподільник — той, хто вирішує, з якого магазину поїде онлайн-замовлення.
         *
         * Роль зʼявилась не заради нового права: orders.manage («передача між
         * магазинами») існувало від початку, але його мав лише адміністратор.
         * Тобто робота була, права були, а людини, якій це доручити, — не було.
         *
         * Чому не продавець. Продавець дивиться зі своєї точки: він бачить свої
         * залишки й свої замовлення, і правити чужі частини йому не можна
         * навмисно. Розподіл же вимагає протилежного погляду — згори на всю
         * мережу: де скільки лежить, кому ближче везти, яка точка взагалі
         * відправляє. Це не «продавець із ширшими правами», це інша робота.
         *
         * Чого тут немає: створювати замовлення (це каса), пробивати чеки й
         * створювати накладні. Розподільник вирішує, ХТО виконує, а виконує —
         * магазин. Дати йому ще й накладні означало б, що він може відправити
         * замість точки, не спитавши її.
         */
        self::DISPATCHER => [
            'label' => 'Розподільник замовлень',
            'caps' => ['orders.view', 'orders.view_all', 'orders.status',
                       'orders.assign', 'orders.note', 'orders.manage',
                       // Бачити залишки по мережі — те, на чому й тримається
                       // рішення; правити їх не треба
                       'products.view'],
            'assignable' => true,
        ],
        self::EDITOR => [
            // Роль зарезервована під контент сайту, пости й фото-рекламу. Поки прав немає,
            // не даємо її призначати: інакше вона мовчки забирає доступ, нічого не даючи.
            // Вмикається додаванням content.manage / media.manage / posts.manage + assignable.
            'label' => 'Автор постів',
            'caps' => [],
            'assignable' => false,
        ],
        self::CUSTOMER => [
            'label' => 'Покупець',
            'caps' => [],
            'assignable' => false,
        ],
    ];

    /** Усі ролі в порядку старшинства */
    public static function all(): array { return array_keys(self::MAP); }

    /** Ролі, які можна призначати в адмінці */
    public static function assignable(): array
    {
        return array_keys(array_filter(self::MAP, fn($r) => $r['assignable']));
    }

    public static function exists(string $role): bool { return isset(self::MAP[$role]); }

    public static function label(string $role): string { return self::MAP[$role]['label'] ?? $role; }

    public static function caps(string $role): array { return self::MAP[$role]['caps'] ?? []; }

    /** Обʼєднання прав кількох ролей */
    public static function capsOf(array $roles): array
    {
        $out = [];
        foreach ($roles as $r) {
            foreach (self::caps($r) as $c) {
                if ($c === '*') return ['*'];
                $out[$c] = true;
            }
        }
        return array_keys($out);
    }

    /** Чи дає набір прав конкретну можливість. Підтримує '*' і префікси на кшталт 'content.*' */
    public static function allows(array $caps, string $cap): bool
    {
        foreach ($caps as $granted) {
            if ($granted === '*' || $granted === $cap) return true;
            if (str_ends_with($granted, '.*') && str_starts_with($cap, substr($granted, 0, -1))) return true;
        }
        return false;
    }

    /**
     * Звуження набору прав до ролі, яку вдають: $target ∩ $own.
     * Симуляція ніколи не додає прав — лише відбирає, тому результат
     * не може вийти за межі $own навіть якщо сесію підробили.
     */
    public static function narrow(array $own, array $target): array
    {
        if ($target === ['*']) return $own;
        $out = [];
        foreach ($target as $c) if (self::allows($own, $c)) $out[] = $c;
        return $out;
    }

    /** Чи покриває набір прав усі права ролі — умова, за якою дозволяємо її вдавати */
    public static function covers(array $own, string $role): bool
    {
        $target = self::caps($role);
        if ($target === ['*']) return in_array('*', $own, true);
        foreach ($target as $c) if (!self::allows($own, $c)) return false;
        return true;
    }
}
