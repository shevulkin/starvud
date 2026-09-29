<?php
declare(strict_types=1);

namespace Controllers\Admin;

use DB, View, Auth, Images;

class Media
{
    /** Де використовується фото: товари, банери, галерея */
    public static function usage(string $path): array
    {
        $uses = [];
        // фото товару: будь-яке з галереї або призначене головним
        foreach (DB::all(
            'SELECT DISTINCT p.id, p.name FROM products p
             LEFT JOIN product_images pi ON pi.product_id = p.id AND pi.path = ?
             WHERE pi.id IS NOT NULL OR p.image = ?',
            [$path, $path]
        ) as $p) {
            $uses[] = ['label' => 'Товар: ' . $p['name'], 'url' => url('/admin/products/' . $p['id'])];
        }
        foreach (DB::all('SELECT id, name FROM brands WHERE logo = ?', [$path]) as $b) {
            $uses[] = ['label' => 'Лого бренду: ' . $b['name'], 'url' => url('/admin/brands')];
        }
        foreach (DB::all('SELECT id, name FROM partners WHERE logo = ?', [$path]) as $p) {
            $uses[] = ['label' => 'Лого партнера: ' . $p['name'], 'url' => url('/admin/partners')];
        }
        foreach (DB::all('SELECT `key` FROM content_blocks WHERE image = ?', [$path]) as $c) {
            $uses[] = ['label' => 'Банер/фото сайту: ' . $c['key'], 'url' => url('/admin/content')];
        }
        $gallery = json_decode(\Content::get('gallery', 'body', '[]'), true) ?: [];
        foreach ($gallery as $g) {
            if (($g[1] ?? '') === $path) { $uses[] = ['label' => 'Галерея: ' . ($g[0] ?: 'фото'), 'url' => url('/admin/content')]; break; }
        }
        return $uses;
    }

    /** Список усіх фото сайту (для сторінки і для вікна вибору) */
    public static function listAll(): array
    {
        $dir = cfg('uploads_dir');
        $items = [];
        // завантажені фото
        foreach (glob($dir . '/*') ?: [] as $f) {
            $name = basename($f);
            if (str_contains($name, '-thumb.')) continue;
            $size = @getimagesize($f);
            $path = 'uploads/' . $name;
            $items[] = [
                'path' => $path, 'thumb' => 'uploads/' . preg_replace('/\.(\w+)$/', '-thumb.$1', $name),
                'width' => $size[0] ?? 0, 'height' => $size[1] ?? 0,
                'bytes' => filesize($f) ?: 0, 'mtime' => filemtime($f) ?: 0, 'builtin' => false,
                'usage' => self::usage($path),
            ];
        }
        usort($items, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
        // вбудовані фото дизайну
        foreach (glob(BOFU_ROOT . '/assets/img/*.png') ?: [] as $f) {
            $size = @getimagesize($f);
            $items[] = [
                'path' => 'img/' . basename($f), 'thumb' => 'img/' . basename($f),
                'width' => $size[0] ?? 0, 'height' => $size[1] ?? 0,
                'bytes' => filesize($f) ?: 0, 'mtime' => 0, 'builtin' => true,
            ];
        }
        return $items;
    }

    public static function index(): never
    {
        // Медіа-бібліотека спільна для всього сайту (банери, галерея, фото товарів),
        // тому нею керує адмін — інакше продавець одного магазину міняє картинки всім.
        Auth::requireCap('media.manage');
        if (($_GET['format'] ?? '') === 'json') {
            json_response(['items' => self::listAll()]);
        }
        if (is_post()) {
            $action = $_POST['_action'] ?? '';
            if ($action === 'upload') {
                $res = Images::saveUpload($_FILES['image'] ?? [], 'media');
                if ($res) {
                    [$path, $w, $h, $bytes] = $res;
                    if (($_POST['format'] ?? '') === 'json') json_response(['ok' => true, 'path' => $path, 'width' => $w, 'height' => $h, 'bytes' => $bytes]);
                    flash('success', "Фото додано ({$w}×{$h}, " . round($bytes/1024) . ' КБ)');
                } else {
                    if (($_POST['format'] ?? '') === 'json') json_response(['ok' => false], 422);
                    flash('error', 'Не вдалося завантажити фото');
                }
            }
            if ($action === 'delete') {
                $path = (string)($_POST['path'] ?? '');
                if (str_starts_with($path, 'uploads/') && !str_contains($path, '..')) {
                    $uses = self::usage($path);
                    if ($uses) {
                        $msg = 'Фото використовується (' . implode(', ', array_column($uses, 'label')) . ') — спочатку приберіть або замініть його там.';
                        if (($_POST['format'] ?? '') === 'json') json_response(['ok' => false, 'error' => $msg, 'usage' => $uses], 409);
                        flash('error', $msg);
                    } else {
                        Images::delete($path);
                        if (($_POST['format'] ?? '') === 'json') json_response(['ok' => true]);
                        flash('success', 'Фото видалено із сайту');
                    }
                } elseif (($_POST['format'] ?? '') === 'json') json_response(['ok' => false], 422);
            }
            redirect('/admin/media');
        }
        View::show('admin/media', [
            'items' => self::listAll(),
            'page_title' => 'Медіа-бібліотека — адмінка',
        ], 'layouts/admin');
    }
}
