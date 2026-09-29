<?php
declare(strict_types=1);

namespace Controllers\Admin;

use DB, View, Auth, Geo;

class Stores
{
    public static function index(): never
    {
        Auth::requireCap('stores.manage');
        if (is_post()) {
            $action = $_POST['_action'] ?? '';
            if ($action === 'add' && trim($_POST['name'] ?? '') !== '') {
                $name = trim($_POST['name']);
                DB::insert('stores', array_merge([
                    'name' => $name, 'slug' => slugify($name) . '-' . random_int(10, 99),
                    'city' => trim($_POST['city'] ?? '') ?: null, 'address' => trim($_POST['address'] ?? '') ?: null,
                    'phone' => trim($_POST['phone'] ?? '') ?: null,
                    'hours' => trim($_POST['hours'] ?? '') ?: null, 'active' => 1,
                    'sort' => (int)DB::val('SELECT COALESCE(MAX(sort),0)+1 FROM stores'),
                ], self::coords($_POST['coords'] ?? '', $bad)));
                flash('success', $bad ? 'Магазин додано, але координати не розібрались — впишіть їх у рядку нижче'
                                      : 'Магазин додано');
            }
            if ($action === 'save') {
                $badNames = [];
                foreach ((array)($_POST['store'] ?? []) as $id => $s) {
                    $name = trim($s['name'] ?? '');
                    DB::update('stores', array_merge([
                        'name' => $name, 'city' => trim($s['city'] ?? '') ?: null,
                        'address' => trim($s['address'] ?? '') ?: null, 'phone' => trim($s['phone'] ?? '') ?: null,
                        'hours' => trim($s['hours'] ?? '') ?: null,
                        'active' => !empty($s['active']) ? 1 : 0,
                    ], self::coords($s['coords'] ?? '', $bad), self::npSender($s), self::kasa($s), self::dps((int)$id, $s)), 'id = ?', [(int)$id]);
                    if ($bad) $badNames[] = $name;
                }
                // Про нерозібрані координати кажемо поіменно: збереглося все інше,
                // і мовчазне «Збережено» лишило б людину в упевненості, що мітка є
                flash($badNames ? 'error' : 'success', $badNames
                    ? 'Збережено, але координати не розібрались: ' . implode(', ', $badNames)
                      . '. Потрібна пара чисел «50.4501, 30.5234» або посилання з Google Maps.'
                    : 'Збережено');
            }
            if ($action === 'agent_token') {
                // Токен показуємо РІВНО ОДИН РАЗ — у базі лишається хеш.
                // Тому не «згенерувати й показати колись», а «ось він, копіюйте
                // зараз»: другого разу не буде, буде лише новий токен, від
                // якого старий агент перестане працювати.
                $id = (int)($_POST['store_id'] ?? 0);
                $store = $id ? DB::row('SELECT id, name FROM stores WHERE id = ?', [$id]) : null;
                if (!$store) { flash('error', 'Такої точки немає.'); redirect('/admin/stores'); }
                $token = \FiscalProvider::newAgentToken($id);
                flash('success', 'Токен агента для «' . $store['name'] . '»: ' . $token
                    . ' — скопіюйте його зараз, удруге він не покажеться. '
                    . 'Старий токен, якщо був, більше не діє.');
                redirect('/admin/stores');
            }
            redirect('/admin/stores');
        }
        $stores = DB::all('SELECT * FROM stores ORDER BY sort, id');
        View::show('admin/stores', [
            'stores' => $stores,
            // Карти-вибиралки тут більше немає: заради неї сторінка вантажила
            // чужий скрипт, а координати й так вставляють копіюванням із Google
            // Maps — Geo::parse() приймає і пару чисел, і саме посилання.
            'page_title' => 'Магазини — Адміністрування',
        ], 'layouts/admin');
    }

    /**
     * Звідки точка відправляє посилки Новою Поштою.
     *
     * Місто й відділення приймаються лише разом і лише з довідника: відділення
     * без міста (чи навпаки) — це накладна в нікуди, а назва без Ref нічого не
     * варта для API. Половинчастий набір мовчки обнуляємо — тоді працює спільне
     * відділення з налаштувань, і це чесніше за «збережено», після якого
     * накладна не створюється.
     */
    private static function npSender(array $s): array
    {
        $cut = static fn($v, int $max) => mb_substr(trim((string)($v ?? '')), 0, $max);
        $cityRef = $cut($s['np_city_ref'] ?? '', 60);
        $whRef = $cut($s['np_warehouse_ref'] ?? '', 60);
        $full = $cityRef !== '' && $whRef !== '';
        return [
            'np_city' => $full ? ($cut($s['np_city'] ?? '', 160) ?: null) : null,
            'np_city_ref' => $full ? $cityRef : null,
            'np_warehouse' => $full ? ($cut($s['np_warehouse'] ?? '', 200) ?: null) : null,
            'np_warehouse_ref' => $full ? $whRef : null,
            'np_sender_phone' => $cut($s['np_sender_phone'] ?? '', 30) ?: null,
        ];
    }

    /**
     * Власна каса точки.
     *
     * Токен належить касі, а каса — торговій точці: чек мусить пробитись саме
     * на тому ПРРО, де стоїть покупець, бо йому належать і фіскальний номер, і
     * зміна, і Z-звіт. Порожній токен означає «працює на загальній касі» — і це
     * нормальний стан для одного ФОПа з кількома точками.
     *
     * Податкова група тут з тієї ж причини: точки можуть належати різним ФОПам,
     * і платник ПДВ поруч із неплатником — звичайна для мережі річ.
     */
    /**
     * ПРРО ДПС точки: фіскальний номер (видає податкова при реєстрації), локальний
     * номер каси й назва/адреса господарської одиниці — як у формі 1-ПРРО.
     *
     * Поле є лише у формі, коли обрано постачальника «ПРРО ДПС»; якщо його не
     * надіслали — нічого не чіпаємо. Змінився фіскальний номер — це вже інша
     * каса: лічильник документів і стан зміни звіримо з ДПС заново.
     */
    private static function dps(int $id, array $s): array
    {
        if (!array_key_exists('dps_fiscal_num', $s)) return [];
        $num = preg_replace('/\D/', '', (string)$s['dps_fiscal_num']);
        $local = (int)($s['dps_local_num'] ?? 0);
        $out = [
            'dps_fiscal_num' => $num !== '' ? substr($num, 0, 20) : null,
            'dps_local_num' => $local > 0 ? $local : null,
            'dps_point_name' => \DpsDoc::clean((string)($s['dps_point_name'] ?? ''), 256) ?: null,
            'dps_point_addr' => \DpsDoc::clean((string)($s['dps_point_addr'] ?? ''), 256) ?: null,
        ];
        $was = (string)(DB::val('SELECT dps_fiscal_num FROM stores WHERE id = ?', [$id]) ?? '');
        if ($was !== (string)$out['dps_fiscal_num']) {
            $out += ['dps_next_num' => null, 'dps_synced_at' => null, 'dps_shift_open' => 0, 'dps_testing' => null];
        }
        return $out;
    }

    private static function kasa(array $s): array
    {
        $token = trim((string)($s['vchasno_token'] ?? ''));
        $tax = (int)($s['vchasno_taxgrp'] ?? 0);
        $route = trim((string)($s['fiscal_route'] ?? ''));
        $url = trim((string)($s['dm_url'] ?? ''));
        return [
            // Порожній маршрут означає «як у загальних налаштуваннях», а не
            // «ніяк»: інакше кожну нову точку довелося б налаштовувати цілком,
            // аби вона просто працювала як усі.
            'fiscal_route' => isset(\FiscalProvider::ROUTES[$route]) ? $route : null,
            // Лише localhost: Device Manager стоїть на комп'ютері точки й
            // слухає тільки його (див. FiscalProvider::normalizeDmUrl)
            'dm_url' => \FiscalProvider::normalizeDmUrl($url),
            'dm_device' => mb_substr(trim((string)($s['dm_device'] ?? '')), 0, 100) ?: null,
            // Підпис під автоматичними операціями цієї точки. Чистимо тим самим
            // фільтром, що й чеки: ПРРО має вузьку абетку, і зайвий символ
            // завалив би нічний Z-звіт, про який ніхто б не дізнався до ранку.
            'vchasno_cashier' => \Vchasno::clean((string)($s['vchasno_cashier'] ?? ''), 100) ?: null,
            'vchasno_token' => $token !== '' ? mb_substr($token, 0, 250) : null,
            // Нуль — це «як у загальних налаштуваннях», а не група №0: такої
            // немає. Чуже число теж не приймаємо — ПРРО відхилив би його вже
            // на живому чеку, посеред черги.
            'vchasno_taxgrp' => isset(\Vchasno::TAX_GROUPS[$tax]) ? $tax : null,
        ];
    }

    /**
     * Поле координат у стовпці бази.
     *
     * Порожнє поле — це «мітки немає», і його треба вміти зняти, тому порожнеча
     * пише null, а не мовчки лишає старе. А от нерозбірливий текст не чіпає
     * нічого: людина описалась, і стерти через це правильні координати було б
     * найгіршою з можливих відповідей.
     */
    private static function coords(string $raw, ?bool &$bad = null): array
    {
        $raw = trim($raw);
        $bad = false;
        if ($raw === '') return ['lat' => null, 'lng' => null];
        $p = Geo::parse($raw);
        if ($p === null) { $bad = true; return []; }
        return ['lat' => $p['lat'], 'lng' => $p['lng']];
    }
}
