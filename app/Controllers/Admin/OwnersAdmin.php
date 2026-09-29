<?php
declare(strict_types=1);

namespace Controllers\Admin;

use DB, View, Auth, Owners, Vchasno;

/**
 * Власники точок: хто продає й перед ким звітує.
 *
 * Сторінка потрібна рівно тоді, коли мережа — це більше ніж один платник
 * податків: два ваші магазини й третій дружини. Для покупця це один сайт, для
 * ДПС — дві окремі історії, і плутати їх не можна ні в чеку, ні в звіті.
 *
 * Видалення тут немає навмисно, як і в магазинів: на власника посилаються
 * точки, а через них — чеки, які вже в ДПС. Того, хто більше не працює,
 * знімають з активних, і він лишається в історії.
 */
class OwnersAdmin
{
    public static function index(): never
    {
        Auth::requireCap('stores.manage');

        if (is_post()) {
            $action = (string)($_POST['_action'] ?? '');
            if ($action === 'add') self::add();
            if ($action === 'save') self::save();
            redirect('/admin/owners');
        }

        $owners = Owners::all();
        $rows = [];
        foreach ($owners as $o) {
            $id = (int)$o['id'];
            $rows[] = $o + [
                'stores' => Owners::stores($id),
                'kassy' => Owners::cashRegisters($id),
                'problems' => Owners::problems($o),
                'income' => Owners::income($id),
                'income_prev' => Owners::income($id, (int)date('Y') - 1),
            ];
        }

        View::show('admin/owners', [
            'owners' => $rows,
            // Точки без власника — саме з них починають після оновлення
            'orphans' => DB::all('SELECT id, name FROM stores WHERE owner_id IS NULL ORDER BY sort, id'),
            'ep_groups' => Owners::EP_GROUPS,
            'tax_groups' => Vchasno::TAX_GROUPS,
            'year' => (int)date('Y'),
            'page_title' => 'Власники — адмінка',
        ], 'layouts/admin');
    }

    private static function add(): void
    {
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') { flash('error', 'Вкажіть назву — як у документах.'); return; }
        DB::insert('owners', [
            'name' => mb_substr($name, 0, 200),
            'tax_id' => mb_substr(trim((string)($_POST['tax_id'] ?? '')), 0, 20) ?: null,
            'active' => 1,
            'sort' => (int)DB::val('SELECT COALESCE(MAX(sort),0)+1 FROM owners'),
            'created_at' => now(),
        ]);
        flash('success', 'Власника додано — тепер призначте йому точки й ставку.');
    }

    private static function save(): void
    {
        foreach ((array)($_POST['owner'] ?? []) as $id => $o) {
            $id = (int)$id;
            if (!$id || !Owners::byId($id)) continue;

            $ep = (int)($o['ep_group'] ?? 0);
            $tax = (int)($o['taxgrp'] ?? 0);
            DB::update('owners', [
                'name' => mb_substr(trim((string)($o['name'] ?? '')), 0, 200) ?: 'Без назви',
                'tax_id' => mb_substr(trim((string)($o['tax_id'] ?? '')), 0, 20) ?: null,
                'ep_group' => isset(Owners::EP_GROUPS[$ep]) ? $ep : null,
                'vat' => !empty($o['vat']) ? 1 : 0,
                // Ставку приймаємо лише з переліку ДПС: підставлене число ПРРО
                // відхилив би вже на живому чеку, посеред черги
                'taxgrp' => isset(Vchasno::TAX_GROUPS[$tax]) ? $tax : null,
                'cashier' => Vchasno::clean((string)($o['cashier'] ?? ''), 100) ?: null,
                'note' => mb_substr(trim((string)($o['note'] ?? '')), 0, 500) ?: null,
                // Реквізити для рахунків. IBAN лишаємо як ввели, лише без
                // пробілів: у банківських виписках його друкують і так, і так,
                // а звіряти рядок із пробілами посеред ночі — задоволення нижче
                // середнього.
                'full_name' => mb_substr(trim((string)($o['full_name'] ?? '')), 0, 200) ?: null,
                'iban' => mb_substr(preg_replace('/\s+/', '', (string)($o['iban'] ?? '')) ?? '', 0, 40) ?: null,
                'bank' => mb_substr(trim((string)($o['bank'] ?? '')), 0, 150) ?: null,
                'address' => mb_substr(trim((string)($o['address'] ?? '')), 0, 250) ?: null,
                'signer' => mb_substr(trim((string)($o['signer'] ?? '')), 0, 150) ?: null,
                'active' => !empty($o['active']) ? 1 : 0,
            ], 'id = ?', [$id]);
        }

        // Привʼязка точок. Робимо тут, а не в картці магазину, бо саме тут
        // видно картину цілком: чия точка, скільки їх у кожного і чи не
        // лишилось безхазяйних.
        foreach ((array)($_POST['store_owner'] ?? []) as $storeId => $ownerId) {
            $storeId = (int)$storeId;
            $ownerId = (int)$ownerId;
            if (!$storeId) continue;
            DB::update('stores', ['owner_id' => $ownerId && Owners::byId($ownerId) ? $ownerId : null],
                'id = ?', [$storeId]);
        }
        flash('success', 'Збережено.');
    }
}
