<?php
declare(strict_types=1);

namespace Controllers;

use Auth, Csrf, Roles;

/**
 * Перемикання робочої ролі: діяти в системі як інша роль.
 *
 * Маршрути свідомо лежать поза адмінкою: у ролі покупця людина втрачає
 * стафні права, і якби перемикач жив під гейтом адмінки — вийти з неї
 * було б нічим.
 */
class RoleController
{
    public static function change(): never
    {
        Csrf::verify();
        $back = safe_back($_POST['back'] ?? null, '/');
        if (!Auth::check()) redirect($back);

        $role = (string)($_POST['role'] ?? '');
        $storeId = null;
        if ($role === Roles::SELLER) {
            $storeId = (int)($_POST['store_id'] ?? 0) ?: null;
            // без точки кабінет продавця показав би порожньо й це виглядало б як поломка
            if ($storeId === null) $storeId = Auth::authorityStoreIds()[0] ?? null;
        }

        if (!Auth::simulate($role, $storeId)) {
            flash('error', 'Ця роль вам недоступна.');
            redirect($back);
        }
        flash('success', 'Працюєте як ' . Roles::label($role) . '. Права звужено до цієї ролі.');
        // у ролі покупця в адмінці робити нічого — ведемо на вітрину
        redirect($role === Roles::CUSTOMER ? '/' : $back);
    }

    /** Вибір робочої точки: продавець із кількома магазинами звужує кабінет до однієї */
    public static function store(): never
    {
        Csrf::verify();
        $back = safe_back($_POST['back'] ?? null, '/admin');
        if (!Auth::check()) redirect($back);
        $raw = trim((string)($_POST['store_id'] ?? ''));
        $storeId = $raw === '' ? null : (int)$raw;
        if (!Auth::setWorkStore($storeId)) {
            flash('error', 'Ця точка вам недоступна.');
        } else {
            flash('success', $storeId === null
                ? 'Працюєте по всіх своїх точках.'
                : 'Робоча точка змінена.');
        }
        redirect($back);
    }

    public static function reset(): never
    {
        Csrf::verify();
        $back = safe_back($_POST['back'] ?? null, '/');
        Auth::stopSimulating();
        flash('success', 'Повернуто ваші звичайні права.');
        redirect($back);
    }
}
