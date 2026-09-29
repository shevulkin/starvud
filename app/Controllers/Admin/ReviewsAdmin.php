<?php
declare(strict_types=1);

namespace Controllers\Admin;

use DB, View, Auth, Reviews;

/**
 * Відгуки: нові з сайту чекають тут, поки продавець їх не погляне.
 * Відповідь магазину показується під відгуком на сторінці «Відгуки».
 */
class ReviewsAdmin
{
    public static function index(): never
    {
        Auth::requireCap('content.manage');
        if (is_post()) {
            $id = (int)($_POST['id'] ?? 0);
            $action = $_POST['_action'] ?? '';
            if ($id && DB::row('SELECT id FROM reviews WHERE id = ?', [$id])) {
                if ($action === 'approve') {
                    DB::update('reviews', ['approved' => 1], 'id = ?', [$id]);
                    flash('success', 'Відгук опубліковано');
                }
                if ($action === 'hide') {
                    DB::update('reviews', ['approved' => 0], 'id = ?', [$id]);
                    flash('success', 'Відгук приховано з сайту');
                }
                if ($action === 'reply') {
                    $reply = trim((string)($_POST['reply'] ?? ''));
                    DB::update('reviews', ['reply' => $reply !== '' ? mb_substr($reply, 0, 2000) : null], 'id = ?', [$id]);
                    flash('success', $reply !== '' ? 'Відповідь збережено' : 'Відповідь прибрано');
                }
                if ($action === 'delete') {
                    DB::delete('reviews', 'id = ?', [$id]);
                    flash('success', 'Відгук видалено');
                }
            }
            $tab = ($_POST['tab'] ?? '') === 'all' ? '?tab=all' : '';
            redirect('/admin/reviews' . $tab);
        }

        $tab = ($_GET['tab'] ?? '') === 'all' ? 'all' : 'new';
        $sql = 'SELECT * FROM reviews' . ($tab === 'new' ? ' WHERE approved = 0' : '')
             . ' ORDER BY created_at DESC, id DESC LIMIT 300';
        View::show('admin/reviews', [
            'tab' => $tab,
            'rows' => DB::all($sql),
            'pending' => Reviews::pendingCount(),
            'page_title' => 'Відгуки — адмінка',
        ], 'layouts/admin');
    }
}
