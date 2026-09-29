<?php
declare(strict_types=1);

namespace Controllers\Admin;

use DB, View, Auth, Catalog, Settings;

class Promos
{
    public static function index(): never
    {
        Auth::requireCap('promos.manage');
        if (is_post()) {
            $action = $_POST['_action'] ?? '';
            if ($action === 'add_code' && trim($_POST['code'] ?? '') !== '') {
                // порожнє поле ліміту = без обмежень, тому саме null, а не 0
                $limit = static fn(string $k): ?int => (int)($_POST[$k] ?? 0) > 0 ? (int)$_POST[$k] : null;
                $cap = trim((string)($_POST['max_total_percent'] ?? ''));
                DB::insert('promo_codes', [
                    'code' => mb_strtoupper(trim($_POST['code'])), 'percent' => (float)($_POST['percent'] ?? 0),
                    'active' => 1, 'expires_at' => trim($_POST['expires_at'] ?? '') ?: null,
                    'max_uses' => $limit('max_uses'), 'per_user_limit' => $limit('per_user_limit'),
                    'stackable' => isset($_POST['stackable']) ? 1 : 0,
                    'max_total_percent' => $cap !== '' ? (float)$cap : null,
                ]);
                flash('success', 'Промокод додано');
            }
            if ($action === 'del_code') { DB::delete('promo_codes', 'id = ?', [(int)$_POST['id']]); flash('success', 'Промокод видалено'); }
            if ($action === 'add_promo' && trim($_POST['title'] ?? '') !== '') {
                DB::insert('promotions', [
                    'title' => trim($_POST['title']), 'percent' => (float)($_POST['percent'] ?? 0),
                    'store_id' => (int)($_POST['store_id'] ?? 0) ?: null,
                    'category_id' => (int)($_POST['category_id'] ?? 0) ?: null,
                    'product_id' => (int)($_POST['product_id'] ?? 0) ?: null,
                    'starts_at' => trim($_POST['starts_at'] ?? '') ?: null,
                    'ends_at' => trim($_POST['ends_at'] ?? '') ?: null,
                    'active' => 1,
                ]);
                flash('success', 'Акцію створено');
            }
            if ($action === 'toggle_promo') {
                DB::query('UPDATE promotions SET active = 1 - active WHERE id = ?', [(int)$_POST['id']]);
            }
            if ($action === 'del_promo') { DB::delete('promotions', 'id = ?', [(int)$_POST['id']]); flash('success', 'Акцію видалено'); }
            if ($action === 'sale_banner') {
                Settings::set('sale_banner_active', isset($_POST['sale_active']) ? '1' : '0');
                Settings::set('sale_banner_text', trim($_POST['sale_text'] ?? ''));
                Settings::set('sale_banner_percent', trim($_POST['sale_percent'] ?? ''));
                flash('success', 'Банер оновлено');
            }
            redirect('/admin/promos');
        }
        View::show('admin/promos', [
            // разом із фактичною кількістю використань — без неї ліміт у списку
            // нічого не означає: незрозуміло, скільки від нього лишилось
            'codes' => DB::all('SELECT c.*, (SELECT COUNT(*) FROM promo_uses u WHERE u.promo_id = c.id) used
                                FROM promo_codes c ORDER BY c.id DESC'),
            'promos' => DB::all('SELECT pr.*, s.name AS store_name, c.name AS cat_name, p.name AS product_name
                                 FROM promotions pr
                                 LEFT JOIN stores s ON s.id = pr.store_id
                                 LEFT JOIN categories c ON c.id = pr.category_id
                                 LEFT JOIN products p ON p.id = pr.product_id ORDER BY pr.id DESC'),
            'stores' => Catalog::stores(), 'categories' => Catalog::categories(),
            'products' => DB::all('SELECT id, name FROM products WHERE active = 1 ORDER BY name'),
            'page_title' => 'Акції та промокоди — адмінка',
        ], 'layouts/admin');
    }
}
