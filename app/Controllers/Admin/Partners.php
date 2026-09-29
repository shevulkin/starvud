<?php
declare(strict_types=1);

namespace Controllers\Admin;

// Media НЕ імпортуємо: він у цьому ж неймспейсі, а `use Media` вказав би на
// глобальний клас, якого немає, — і виклик падав би вже після запису в базу
use DB, View, Auth, Images;

/**
 * Наші партнери: господарства, школи, крамниці, з якими ми працюємо.
 *
 * Не бренди. Бренд відповідає на питання «чий це товар» і живе в картці товару;
 * партнер нам нічого не виробляє — він поруч. Тому окремий довідник, і жодних
 * звʼязків із каталогом: партнера можна видалити будь-коли, за ним не лишається
 * товарів, які раптом стали нічиїми.
 */
class Partners
{
    public static function index(): never
    {
        Auth::requireCap('content.manage');
        if (is_post()) {
            $action = $_POST['_action'] ?? '';
            if ($action === 'add') self::add();
            if ($action === 'save') self::save((int)($_POST['id'] ?? 0));
            if ($action === 'logo_remove') self::removeLogo((int)($_POST['id'] ?? 0));
            if ($action === 'delete') self::delete((int)($_POST['id'] ?? 0));
            redirect('/admin/partners');
        }
        View::show('admin/partners', [
            'partners' => DB::all('SELECT * FROM partners ORDER BY sort, name'),
            'page_title' => 'Партнери — адмінка',
        ], 'layouts/admin');
    }

    private static function add(): void
    {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') { flash('error', 'Вкажіть назву партнера'); return; }
        if (DB::row('SELECT id FROM partners WHERE name = ?', [$name])) {
            flash('error', 'Такий партнер уже є'); return;
        }
        DB::insert('partners', [
            'name' => $name,
            'slug' => self::freeSlug($name),
            'active' => 1,
            'sort' => (int)DB::val('SELECT COALESCE(MAX(sort),0)+1 FROM partners'),
        ]);
        flash('success', 'Партнера додано — тепер завантажте лого й напишіть, хто це');
    }

    /** Один партнер — одна форма: інакше файл лого не вкласти без вкладених форм */
    private static function save(int $id): void
    {
        if (!$id || !DB::row('SELECT id FROM partners WHERE id = ?', [$id])) return;
        $name = trim($_POST['name'] ?? '');
        if ($name === '') { flash('error', 'Назва не може бути порожньою'); return; }
        if (DB::row('SELECT id FROM partners WHERE name = ? AND id <> ?', [$name, $id])) {
            flash('error', 'Партнер «' . $name . '» уже є — назви не повторюються'); return;
        }

        $note = '';
        $url = self::url($_POST['url'] ?? '', $bad);
        if ($bad) $note = ' Посилання не збережено: потрібна адреса сайту, як-от medok.ua.';

        $data = [
            'name' => $name,
            // slug перераховуємо з назви, як у брендах: перейменований партнер
            // не має лишатися під чужою адресою
            'slug' => self::freeSlug($name, $id),
            'url' => $url,
            'description' => trim($_POST['description'] ?? '') ?: null,
            'active' => !empty($_POST['active']) ? 1 : 0,
            'sort' => (int)($_POST['sort'] ?? 0),
        ];
        if (($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $res = Images::saveUpload($_FILES['logo'], 'partner');
            if ($res) { self::dropLogoFile($id, $res[0]); $data['logo'] = $res[0]; }
            else $note .= ' Лого не завантажилось — потрібен JPEG, PNG, GIF або WebP до 15 МБ.';
        }
        DB::update('partners', $data, 'id = ?', [$id]);
        flash($note ? 'error' : 'success', 'Збережено.' . $note);
    }

    /**
     * Адреса сайту партнера.
     *
     * Схему дописуємо самі: люди пишуть «medok.ua», а посилання без схеми
     * браузер прочитає як шлях усередині нашого сайту й приведе в нікуди.
     * Дозволяємо лише http(s) — javascript: у цьому полі означав би, що чужий
     * рядок виконується на сторінці в покупця.
     */
    private static function url(string $raw, ?bool &$bad = null): ?string
    {
        $raw = trim($raw);
        $bad = false;
        if ($raw === '') return null;
        if (!preg_match('~^https?://~i', $raw)) $raw = 'https://' . $raw;
        $host = parse_url($raw, PHP_URL_HOST);
        if (!$host || !str_contains($host, '.')) { $bad = true; return null; }
        return $raw;
    }

    private static function removeLogo(int $id): void
    {
        if (!$id) return;
        self::dropLogoFile($id, null);
        DB::update('partners', ['logo' => null], 'id = ?', [$id]);
        flash('success', 'Лого прибрано');
    }

    /**
     * Прибирає старий файл лого, якщо він більше ніде не використовується:
     * те саме фото могли обрати й для товару, і для бренду.
     */
    private static function dropLogoFile(int $id, ?string $keep): void
    {
        $old = (string)(DB::val('SELECT logo FROM partners WHERE id = ?', [$id]) ?? '');
        if ($old === '' || $old === $keep) return;
        DB::update('partners', ['logo' => null], 'id = ?', [$id]);   // щоб Media::usage не рахував нас самих
        if (!Media::usage($old)) Images::delete($old);
    }

    /** На партнері нічого не висить, тож видалення без умов — на відміну від брендів */
    private static function delete(int $id): void
    {
        if (!$id) return;
        self::dropLogoFile($id, null);
        $name = (string)(DB::val('SELECT name FROM partners WHERE id = ?', [$id]) ?? '');
        DB::delete('partners', 'id = ?', [$id]);
        flash('success', 'Партнера «' . $name . '» видалено');
    }

    /** $exceptId — власний рядок при перейменуванні, інакше він займе слаг сам у себе */
    private static function freeSlug(string $name, int $exceptId = 0): string
    {
        $base = slugify($name) ?: 'partner';
        $slug = $base; $i = 1;
        while (DB::row('SELECT id FROM partners WHERE slug = ? AND id <> ?', [$slug, $exceptId])) {
            $slug = $base . '-' . (++$i);
        }
        return $slug;
    }
}
