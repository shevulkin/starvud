<?php
declare(strict_types=1);

namespace Controllers\Admin;

use View, Auth, Content, ContentSave, ContentSchema, Images;

/**
 * Список усіх блоків сайту.
 *
 * Головний спосіб правити контент — режим редагування на самій вітрині
 * (EditMode): там видно, який блок де стоїть. Ця сторінка лишається другим
 * шляхом — коли треба пройтись по всьому одразу або знайти блок, якого зараз
 * немає на екрані. Підписи, підказки й склад полів обидва шляхи беруть
 * з одного реєстру ContentSchema.
 */
class ContentAdmin
{
    public static function index(): never
    {
        Auth::requireCap('content.manage');
        if (is_post()) {
            $action = $_POST['_action'] ?? 'save';
            if ($action === 'save') {
                foreach ((array)($_POST['block'] ?? []) as $key => $fields) {
                    if (!ContentSchema::has((string)$key) || !is_array($fields)) continue;
                    ContentSave::text((string)$key, $fields);
                }
                // FAQ приходить двома паралельними масивами — форма зручніша,
                // ніж вкладені імена полів; складаємо їх назад у пари
                if (isset($_POST['faq_q'])) {
                    $pairs = [];
                    foreach ((array)$_POST['faq_q'] as $i => $q) {
                        $pairs[] = [(string)$q, (string)($_POST['faq_a'][$i] ?? '')];
                    }
                    ContentSave::text('faq', ['body' => $pairs]);
                }
                flash('success', 'Контент збережено');
            }
            if ($action === 'set_image') {
                $key = (string)($_POST['key'] ?? '');
                if (ContentSchema::type($key, 'image') === 'image'
                    && ContentSave::image($key, (string)($_POST['media_path'] ?? ''))) {
                    flash('success', 'Фото оновлено');
                } else {
                    flash('error', 'Не вдалося змінити фото');
                }
            }
            if ($action === 'upload') {
                $key = (string)($_POST['key'] ?? '');
                if (ContentSchema::type($key, 'image') === 'image') {
                    $res = Images::saveUpload($_FILES['image'] ?? [], 'content-' . $key);
                    if ($res && ContentSave::image($key, $res[0])) {
                        [, $w, $h, $bytes] = $res;
                        flash('success', "Фото оновлено ({$w}×{$h}, " . round($bytes / 1024) . ' КБ)');
                    } else flash('error', 'Не вдалося завантажити фото');
                }
            }
            if ($action === 'gallery_pick' || $action === 'gallery_add') {
                $path = $action === 'gallery_add'
                    ? (($res = Images::saveUpload($_FILES['image'] ?? [], 'gallery')) ? $res[0] : '')
                    : (string)($_POST['media_path'] ?? '');
                $title = trim((string)($_POST['title'] ?? ''));
                if (Content::isSafeImagePath($path)) {
                    $g = ContentSave::currentList('gallery');
                    $g[] = [$title ?: 'Фото', $path];
                    ContentSave::text('gallery', ['body' => $g]);
                    flash('success', 'Фото додано до галереї');
                } else flash('error', 'Не вдалося додати фото');
            }
            if ($action === 'gallery_del') {
                $idx = (int)($_POST['index'] ?? -1);
                $g = ContentSave::currentList('gallery');
                if (isset($g[$idx])) {
                    $path = (string)($g[$idx][1] ?? '');
                    array_splice($g, $idx, 1);
                    ContentSave::text('gallery', ['body' => $g]);
                    ContentSave::forgetImage($path);
                    flash('success', 'Фото прибрано з галереї');
                }
            }
            redirect('/admin/content');
        }

        View::show('admin/content', [
            'groups' => ContentSchema::groups(),
            'blocks' => Content::all(),
            'faq' => ContentSave::currentList('faq'),
            'gallery' => ContentSave::currentList('gallery'),
            'page_title' => 'Контент сайту — адмінка',
        ], 'layouts/admin');
    }
}
