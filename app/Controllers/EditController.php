<?php
declare(strict_types=1);

namespace Controllers;

use Auth, Csrf, Content, ContentSave, ContentSchema, EditMode, Images;

/**
 * Режим редагування сайту: вмикання, вимикання й збереження одного блоку.
 *
 * Маршрути свідомо лежать поза `/admin` — режим працює саме на вітрині, і
 * гейт адмінки закрив би доступ до збереження рівно там, де воно потрібне.
 * Права перевіряються в кожній дії окремо: приховати кнопку — не перевірка.
 *
 * Читання й запис відповідають JSON: панель редагування — це та сама сторінка
 * сайту, перезавантажувати її на кожне поле не можна, бо тоді зникає весь сенс
 * «бачу блок — правлю блок».
 */
class EditController
{
    public static function on(): never
    {
        Csrf::verify();
        $back = safe_back($_POST['back'] ?? null, '/');
        if (!EditMode::available()) {
            flash('error', 'Редагування сайту вам недоступне.');
            redirect($back);
        }
        EditMode::enable();
        flash('success', 'Режим редагування увімкнено. Натисніть на будь-який обведений блок, щоб змінити його.');
        redirect($back);
    }

    public static function off(): never
    {
        Csrf::verify();
        EditMode::disable();
        flash('success', 'Режим редагування вимкнено — ви бачите сайт очима покупця.');
        redirect(safe_back($_POST['back'] ?? null, '/'));
    }

    /**
     * Назви всіх блоків одним запитом: смужка режиму підписує ними зони на
     * сторінці та список «Блоки на сторінці». Окремо від block() навмисно —
     * інакше та сама сторінка тягла б з десяток запитів заради самих підписів.
     */
    public static function blocks(): never
    {
        if (!EditMode::available()) json_response(['ok' => false, 'error' => 'Немає прав'], 403);
        $labels = [];
        foreach (ContentSchema::all() as $key => $def) $labels[$key] = $def['label'];
        json_response(['ok' => true, 'labels' => $labels]);
    }

    /** Опис блоку та його поточні значення — для панелі редагування */
    public static function block(): never
    {
        if (!EditMode::available()) json_response(['ok' => false, 'error' => 'Немає прав'], 403);
        $key = (string)($_GET['key'] ?? '');
        if (!ContentSchema::has($key)) json_response(['ok' => false, 'error' => 'Невідомий блок'], 404);
        json_response(['ok' => true] + self::describe($key));
    }

    /** Зберегти один блок */
    public static function save(): never
    {
        Csrf::verify();
        if (!EditMode::available()) json_response(['ok' => false, 'error' => 'Немає прав'], 403);

        $payload = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($payload)) json_response(['ok' => false, 'error' => 'Некоректний запит'], 400);
        $key = (string)($payload['key'] ?? '');
        if (!ContentSchema::has($key)) json_response(['ok' => false, 'error' => 'Невідомий блок'], 404);
        $values = is_array($payload['values'] ?? null) ? $payload['values'] : [];

        // фото: своє право й свій прибиральник старих файлів
        if (array_key_exists('image', $values) && ContentSchema::type($key, 'image') === 'image') {
            if (!EditMode::canEditImages()) {
                json_response(['ok' => false, 'error' => 'Змінювати фото може лише той, хто має доступ до медіа-бібліотеки'], 403);
            }
            $path = (string)$values['image'];
            if ($path !== '' && !ContentSave::image($key, $path)) {
                json_response(['ok' => false, 'error' => 'Це не схоже на фото сайту'], 400);
            }
            unset($values['image']);
        }

        // фото, що зникли з галереї, більше нікому не потрібні
        $droppedImages = [];
        if (ContentSchema::type($key, 'body') === 'gallery' && isset($values['body'])) {
            $before = array_column(ContentSave::currentList($key), 1);
            $after = array_column(ContentSave::list('gallery', (array)$values['body']), 1);
            $droppedImages = array_diff($before, $after);
        }

        ContentSave::text($key, $values);
        foreach ($droppedImages as $path) ContentSave::forgetImage((string)$path);

        json_response(['ok' => true] + self::describe($key));
    }

    /**
     * Блок для панелі: опис із реєстру плюс поточні значення.
     * `display` — те, що має стояти на сторінці замість старого тексту, щоб не
     * перезавантажувати її після кожного збереження. Для фото це вже готова
     * адреса файлу, для списків — нічого: такі блоки перемальовує сервер.
     */
    private static function describe(string $key): array
    {
        $fields = [];
        $display = [];
        foreach (ContentSchema::fields($key) as $name => $def) {
            $type = (string)($def['type'] ?? 'text');
            $isList = in_array($type, ContentSchema::JSON_TYPES, true);
            $raw = Content::get($key, $name, '');
            $fields[] = [
                'name' => $name,
                'type' => $type,
                'label' => (string)($def['label'] ?? $name),
                'hint' => (string)($def['hint'] ?? ''),
                'value' => $isList ? ContentSave::currentList($key) : $raw,
                'readonly' => $type === 'image' && !EditMode::canEditImages(),
            ];
            if ($isList) continue;
            $display[$name] = $type === 'image'
                ? ($raw !== '' ? asset($raw) : '')
                : $raw;
        }
        return [
            'key' => $key,
            'label' => ContentSchema::label($key),
            'where' => ContentSchema::where($key),
            'fields' => $fields,
            'display' => $display,
            'gallery_thumbs' => self::galleryThumbs($key),
        ];
    }

    /** Адреси превʼю для галереї — щоб панель не збирала шляхи руками */
    private static function galleryThumbs(string $key): array
    {
        if (ContentSchema::type($key, 'body') !== 'gallery') return [];
        $out = [];
        foreach (ContentSave::currentList($key) as $row) {
            $path = (string)($row[1] ?? '');
            if ($path !== '') $out[$path] = asset(Images::displayThumb($path));
        }
        return $out;
    }
}
