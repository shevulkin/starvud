<?php
declare(strict_types=1);

/**
 * Режим редагування сайту.
 *
 * Список полів в адмінці показує ключі, а не сайт: людина бачить `why_2` і не
 * знає, який це абзац і на якій сторінці. Тому редагування переїхало туди, де
 * блоки видно, — на сам сайт. Увімкнувши режим, адмін ходить вітриною як
 * завжди, але кожен редагований блок обведений і відкривається по кліку.
 *
 * Режим живе в сесії, а не в адресі: інакше він губився б на кожному переході
 * сторінкою й на кожній формі. Права перевіряються при кожному зверненні
 * (`active()`), тож знята роль вимикає режим сама, без окремого прибирання.
 */
class EditMode
{
    private const KEY = 'content_edit';

    /** Чи людина взагалі може вмикати режим */
    public static function available(): bool { return Auth::can('content.manage'); }

    /** Чи можна міняти фото — медіа-бібліотека закрита окремим правом */
    public static function canEditImages(): bool { return Auth::can('media.manage'); }

    /**
     * Чи режим увімкнено зараз. Права перевіряємо тут, а не лише при вмиканні:
     * позначка в сесії пережила б і зміну ролі, і режим перегляду «як покупець».
     */
    public static function active(): bool
    {
        return !empty($_SESSION[self::KEY]) && self::available();
    }

    public static function enable(): void { $_SESSION[self::KEY] = true; }

    public static function disable(): void { unset($_SESSION[self::KEY]); }

    /** Сторінки вітрини людськими назвами — для смужки режиму */
    private const PAGES = [
        '/' => 'Головна', '/about' => 'Про нас', '/testimonials' => 'Відгуки',
        '/contacts' => 'Контакти', '/stores' => 'Магазини',
        '/shop' => 'Магазин', '/cart' => 'Кошик', '/checkout' => 'Оформлення',
        '/orders' => 'Мої замовлення', '/profile' => 'Кабінет',
    ];

    /**
     * Назва сторінки, на якій зараз стоїть редактор.
     *
     * Беремо з маршруту, а не із заголовка вкладки: той закінчується назвою
     * сайту й у смужці читався б як «Про мене — Beekeeper of Ukraine», де
     * половина рядка — шум. Незнайомий шлях показуємо як є: краще технічна
     * адреса, ніж упевнене «Сторінка», яке нічого не розрізняє.
     */
    public static function pageName(): string
    {
        $path = self::path();
        if (isset(self::PAGES[$path])) return self::PAGES[$path];
        if (str_starts_with($path, '/product/')) return 'Сторінка товару';
        return $path;
    }

    /** Шлях поточної сторінки без кінцевої скісної */
    public static function path(): string
    {
        return rtrim(request_path(), '/') ?: '/';
    }

    /**
     * Позначка редагованої зони для шаблону. Поза режимом повертає порожній
     * рядок — сторінка покупця не несе про це жодного байта.
     *
     * $field — колонка, яку показує саме цей елемент (`title`, `body`, `image`).
     * Вона потрібна, щоб після збереження оновити текст на місці, не
     * перезавантажуючи сторінку. Там, де елемент показує не одне поле
     * (список, лічильник з анімацією, посилання в атрибуті), поле не вказують —
     * такі блоки просто перемальовуються перезавантаженням.
     */
    public static function mark(string $key, ?string $field = null): string
    {
        if (!self::active() || !ContentSchema::has($key)) return '';
        if ($field !== null && ContentSchema::field($key, $field) === null) $field = null;
        return ' data-ce="' . e($key) . '"' . ($field !== null ? ' data-cef="' . e($field) . '"' : '');
    }
}
