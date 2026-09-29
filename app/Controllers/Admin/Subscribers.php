<?php
declare(strict_types=1);

namespace Controllers\Admin;

use DB, View, Auth, Newsletter;

class Subscribers
{
    public static function index(): never
    {
        Auth::requireCap('subscribers.manage');

        if (is_post()) {
            $action = $_POST['_action'] ?? '';
            $id = (int)($_POST['id'] ?? 0);
            // Адмін може лише відписати або видалити. Підписати вручну не можна:
            // згоду дає сама людина, інакше це розсилка без згоди.
            if ($action === 'unsubscribe' && $id) {
                $row = DB::row('SELECT email FROM subscribers WHERE id = ?', [$id]);
                if ($row) { Newsletter::unsubscribe($row['email']); flash('success', 'Адресу відписано'); }
            }
            if ($action === 'delete' && $id) { DB::delete('subscribers', 'id = ?', [$id]); flash('success', 'Запис видалено'); }
            redirect('/admin/subscribers');
        }

        if (($_GET['export'] ?? '') === 'csv') { self::exportCsv(); }

        View::show('admin/subscribers', [
            'rows' => DB::all('SELECT * FROM subscribers ORDER BY active DESC, id DESC'),
            'active_count' => (int)DB::val('SELECT COUNT(*) FROM subscribers WHERE active = 1'),
            'page_title' => 'Розсилка — адмінка',
        ], 'layouts/admin');
    }

    /** Вивантаження активних підписників для сервісу розсилки */
    private static function exportCsv(): never
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="subscribers-' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // BOM, щоб Excel не ламав кирилицю
        fputcsv($out, ['email', 'name', 'source', 'created_at', 'unsubscribe_url']);
        foreach (DB::all('SELECT * FROM subscribers WHERE active = 1 ORDER BY id') as $r) {
            fputcsv($out, array_map([self::class, 'csvCell'],
                [$r['email'], $r['name'], $r['source'], $r['created_at'], url('/unsubscribe/' . $r['token'])]));
        }
        fclose($out);
        exit;
    }

    /**
     * Імʼя підписника приходить із форми замовлення, тож у CSV воно може початися з "="
     * і Excel сприйме його як формулу. Апостроф спереду робить клітинку текстом.
     */
    private static function csvCell(?string $value): string
    {
        $value = (string)$value;
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
    }
}
