<?php
declare(strict_types=1);

/**
 * Запис блоків контенту: перевірка значень за реєстром і прибирання фото,
 * що лишились без вжитку.
 *
 * Живе окремо від контролерів, бо тепер писати контент уміють двоє — список в
 * адмінці та режим редагування на сайті. Правило «що можна зберегти» має бути
 * одне: інакше екранування, білий список ключів і перевірка посилань розʼїдуться,
 * і дірка зʼявиться в тому шляху, який забули.
 */
class ContentSave
{
    /**
     * Зберегти текстові поля блоку. Приймає лише поля, оголошені в ContentSchema;
     * усе інше мовчки відкидається. Повертає збережені значення (уже нормалізовані).
     */
    public static function text(string $key, array $values): array
    {
        $write = [];
        foreach (ContentSchema::fields($key) as $name => $def) {
            if (!array_key_exists($name, $values)) continue;
            $type = (string)($def['type'] ?? 'text');
            if ($type === 'image') continue;                       // фото — окремим шляхом
            $write[$name] = self::normalize($type, $values[$name]);
        }
        if ($write) Content::set($key, $write);
        return $write;
    }

    /** Привести значення поля до вигляду, у якому воно лягає в базу */
    public static function normalize(string $type, $value): string
    {
        if (in_array($type, ContentSchema::JSON_TYPES, true)) {
            return json_encode(self::list($type, is_array($value) ? $value : []), JSON_UNESCAPED_UNICODE) ?: '[]';
        }
        $s = str_replace("\r\n", "\n", (string)$value);
        if ($type === 'text' || $type === 'url') {
            // однорядкові поля: перенос усередині заголовка ламає верстку мовчки
            $s = trim(str_replace(["\n", "\r"], ' ', $s));
            if ($type === 'url') $s = self::url($s);
            return $s;
        }
        if ($type === 'lines') {
            $lines = array_values(array_filter(array_map('trim', explode("\n", $s)), fn($l) => $l !== ''));
            return implode("\n", $lines);
        }
        return trim($s);
    }

    /**
     * Посилання від адміна. Схему приймаємо лише http(s): `javascript:` у href
     * кнопки «Записатись» перетворив би поле контенту на спосіб виконати
     * скрипт у браузері кожного відвідувача.
     */
    public static function url(string $s): string
    {
        if ($s === '') return '';
        if (str_starts_with($s, '/')) return $s;                   // своя сторінка
        if (preg_match('~^https?://~i', $s)) return $s;
        // людина написала «instagram.com/...» — дописуємо схему, а не мовчки ламаємо посилання
        if (!preg_match('~^[a-z][a-z0-9+.-]*:~i', $s)) return 'https://' . $s;
        return '';                                                  // будь-яка інша схема
    }

    /** Списки (питання/відповіді, галерея) — пари рядків без порожніх */
    public static function list(string $type, array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $a = trim((string)($row[0] ?? ''));
            $b = trim((string)($row[1] ?? ''));
            if ($type === 'gallery') {
                if (!Content::isSafeImagePath($b)) continue;
                $out[] = [$a !== '' ? $a : 'Фото', $b];
            } else {
                if ($a === '' || $b === '') continue;
                $out[] = [$a, $b];
            }
        }
        return $out;
    }

    /** Поточний список блоку у вигляді масиву пар */
    public static function currentList(string $key): array
    {
        $rows = json_decode(Content::get($key, 'body', '[]'), true);
        return is_array($rows) ? $rows : [];
    }

    /**
     * Поставити блоку нове фото й прибрати попереднє, якщо воно більше ніде
     * не використовується. Повертає false, якщо шлях не схожий на фото сайту.
     */
    public static function image(string $key, string $path): bool
    {
        if (!Content::isSafeImagePath($path)) return false;
        $old = Content::get($key, 'image', '');
        Content::set($key, ['image' => $path]);
        if ($old !== $path) self::forgetImage($old);
        return true;
    }

    /**
     * Файл більше не потрібен цьому блоку. Видаляємо лише завантажені фото і
     * лише тоді, коли їх ніде не лишилось: те саме фото могло стояти і в товарі,
     * і в галереї, а видалене з-під них виглядало б як зіпсована сторінка.
     */
    public static function forgetImage(string $path): void
    {
        if ($path === '' || !str_starts_with($path, 'uploads/')) return;
        if (!\Controllers\Admin\Media::usage($path)) Images::delete($path);
    }
}
