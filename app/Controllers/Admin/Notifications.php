<?php
declare(strict_types=1);

namespace Controllers\Admin;

use DB, View, Auth, Notify;

class Notifications
{
    public static function index(): never
    {
        Auth::requireCap('notifications.manage');
        if (is_post()) {
            foreach ((array)($_POST['rule'] ?? []) as $id => $r) {
                $rule = DB::row('SELECT event, recipients FROM notification_rules WHERE id = ?', [(int)$id]);
                if (!$rule) continue;
                // Подія, адресована покупцю, одержувача не міняє: «всі покупці» —
                // це вже не сповіщення, а розсилка по базі. Раніше форма не знала
                // такого значення й мовчки переписувала його на «адмінів»,
                // після чого людина переставала отримувати те, що для неї.
                $to = Notify::isCustomerEvent((string)$rule['event'])
                    ? 'customer'
                    : (in_array($r['recipients'] ?? '', ['admins', 'sellers', 'admins_sellers'], true)
                        ? $r['recipients'] : 'admins');
                DB::update('notification_rules', [
                    'enabled' => !empty($r['enabled']) ? 1 : 0,
                    'recipients' => $to,
                    'template' => $r['template'] ?? '',
                ], 'id = ?', [(int)$id]);
            }
            flash('success', 'Правила сповіщень збережено');
            redirect('/admin/notifications');
        }
        $rules = DB::all('SELECT * FROM notification_rules ORDER BY event, channel');
        $byEvent = [];
        foreach ($rules as $r) $byEvent[$r['event']][] = $r;
        View::show('admin/notifications', [
            'by_event' => $byEvent, 'event_labels' => Notify::EVENTS,
            'channel_labels' => ['telegram' => 'Telegram', 'viber' => 'Viber', 'email' => 'Email', 'push' => 'Push'],
            'vars_hint' => [
                'order_new' => '{number} {name} {phone} {delivery} {address} {items} {shortage} {total} {store}',
                'order_status' => '{number} {status}',
                'order_customer' => '{number} {status} {part} {items} {total}',
                'user_new' => '{name} {email}',
                'stock_low' => '{product} {qty} {store}',
                'stock_wanted' => '{product} {waiting} {store}',
                'stock_back' => '{product} {where} {url}',
            ],
            'page_title' => 'Сповіщення — адмінка',
        ], 'layouts/admin');
    }
}
