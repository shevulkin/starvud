<?php
declare(strict_types=1);

namespace Controllers\Admin;

// Media НЕ імпортуємо: він у цьому ж неймспейсі, а `use Media` вказав би на
// глобальний клас, якого немає, — і виклик падав би вже після запису в базу
use DB, View, Auth, Catalog, Images;

/**
 * Довідник брендів: чий товар. Один бренд позначений «наш» — саме він дає
 * право написати покупцю «ми виробник», решта отримує нейтральний текст.
 */
class Brands
{
    public static function index(): never
    {
        Auth::requireCap('catalog.manage');
        if (is_post()) {
            $action = $_POST['_action'] ?? '';
            if ($action === 'add') self::add();
            if ($action === 'save') self::save((int)($_POST['id'] ?? 0));
            if ($action === 'own') self::makeOwn((int)($_POST['id'] ?? 0));
            if ($action === 'logo_remove') self::removeLogo((int)($_POST['id'] ?? 0));
            if ($action === 'delete') self::delete((int)($_POST['id'] ?? 0));
            redirect('/admin/brands');
        }
        View::show('admin/brands', [
            'brands' => Catalog::brands(),
            // скільки товарів на кожному бренді: без цього не видно, що можна прибрати
            'counts' => self::counts(),
            'own_default' => Catalog::ownBrandName(),
            'page_title' => 'Бренди — адмінка',
        ], 'layouts/admin');
    }

    /** @return array<int,int> [brand_id => скільки товарів] */
    private static function counts(): array
    {
        $out = [];
        foreach (DB::all('SELECT brand_id, COUNT(*) AS n FROM product_brands GROUP BY brand_id') as $r) {
            $out[(int)$r['brand_id']] = (int)$r['n'];
        }
        return $out;
    }

    private static function add(): void
    {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') { flash('error', 'Вкажіть назву бренду'); return; }
        if (DB::row('SELECT id FROM brands WHERE name = ?', [$name])) {
            flash('error', 'Такий бренд уже є'); return;
        }
        // «наш» тут не питаємо: це рішення про все виробництво, а не поле
        // нового рядка. Для нього є окрема дія «Зробити нашим».
        DB::insert('brands', [
            'name' => $name,
            'slug' => self::freeSlug($name),
            'own' => 0,
            'active' => 1,
            'sort' => (int)DB::val('SELECT COALESCE(MAX(sort),0)+1 FROM brands'),
        ]);
        flash('success', 'Бренд додано');
    }

    /** Один бренд — одна форма: інакше файл лого не вкласти без вкладених форм */
    private static function save(int $id): void
    {
        if (!$id || !DB::row('SELECT id FROM brands WHERE id = ?', [$id])) return;
        $name = trim($_POST['name'] ?? '');
        if ($name === '') { flash('error', 'Назва не може бути порожньою'); return; }
        if (DB::row('SELECT id FROM brands WHERE name = ? AND id <> ?', [$name, $id])) {
            flash('error', 'Бренд «' . $name . '» уже є — назви не повторюються'); return;
        }
        $data = [
            'name' => $name,
            // slug завжди перераховуємо з назви: інакше перейменований бренд
            // лишається під чужою адресою, а звільнена назва дістає при
            // створенні slug із хвостиком. Для незмінної назви freeSlug()
            // повертає той самий рядок, тож зайвих правок не буде.
            'slug' => self::freeSlug($name, $id),
            'description' => trim($_POST['description'] ?? '') ?: null,
            // own тут навмисно немає: його переносять окремою дією, і запис
            // нуля стирав би позначку при кожному збереженні назви
            'active' => !empty($_POST['active']) ? 1 : 0,
        ];
        $note = '';
        if (($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $res = Images::saveUpload($_FILES['logo'], 'brand');
            if ($res) { self::dropLogoFile($id, $res[0]); $data['logo'] = $res[0]; }
            else $note = ' Лого не завантажилось — потрібен JPEG, PNG, GIF або WebP до 15 МБ.';
        }
        DB::update('brands', $data, 'id = ?', [$id]);
        flash($note ? 'error' : 'success', 'Збережено.' . $note);
    }

    private static function removeLogo(int $id): void
    {
        if (!$id) return;
        self::dropLogoFile($id, null);
        DB::update('brands', ['logo' => null], 'id = ?', [$id]);
        flash('success', 'Лого прибрано');
    }

    /**
     * Прибирає старий файл лого, якщо він більше ніде не використовується.
     * $keep — новий шлях: коли міняють лого на те саме фото з медіатеки,
     * видаляти нічого не можна.
     */
    private static function dropLogoFile(int $id, ?string $keep): void
    {
        $old = (string)(DB::val('SELECT logo FROM brands WHERE id = ?', [$id]) ?? '');
        if ($old === '' || $old === $keep) return;
        DB::update('brands', ['logo' => null], 'id = ?', [$id]);   // щоб Media::usage не рахував нас самих
        if (!Media::usage($old)) Images::delete($old);
    }

    /** Перенести позначку «наш» — вона одна на весь каталог */
    private static function makeOwn(int $id): void
    {
        if (!$id || !DB::row('SELECT id FROM brands WHERE id = ?', [$id])) return;
        DB::update('brands', ['own' => 1], 'id = ?', [$id]);
        self::keepSingleOwn($id);
        flash('success', 'Тепер «наш» — це ' . DB::val('SELECT name FROM brands WHERE id = ?', [$id]));
    }

    /**
     * Видаляємо лише те, на чому не висять товари: інакше позиції мовчки
     * лишились би без бренду, а сайт — без відповіді, чий це товар.
     */
    private static function delete(int $id): void
    {
        if (!$id) return;
        $used = (int)DB::val('SELECT COUNT(*) FROM product_brands WHERE brand_id = ?', [$id]);
        if ($used) {
            flash('error', 'Бренд не видалено: на ньому ' . $used . ' товар(ів). '
                . 'Спершу перепідпишіть їх або зніміть галку «Активний».');
            return;
        }
        DB::delete('brands', 'id = ?', [$id]);
        flash('success', 'Бренд видалено');
    }

    /** «Наш» може бути лише один — інакше незрозуміло, від чийого імені казати «ми виробник» */
    private static function keepSingleOwn(int $keepId): void
    {
        DB::query('UPDATE brands SET own = 0 WHERE id <> ?', [$keepId]);
    }

    /** $exceptId — власний рядок при перейменуванні, інакше він займе слаг сам у себе */
    private static function freeSlug(string $name, int $exceptId = 0): string
    {
        $base = slugify($name) ?: 'brand';
        $slug = $base; $i = 1;
        while (DB::row('SELECT id FROM brands WHERE slug = ? AND id <> ?', [$slug, $exceptId])) {
            $slug = $base . '-' . (++$i);
        }
        return $slug;
    }
}
