<?php
declare(strict_types=1);

namespace Controllers\Admin;

use DB, View, Auth, Courses;

class Diplomas
{
    public static function index(): never
    {
        Auth::requireCap('diplomas.manage');
        if (is_post()) {
            $action = $_POST['_action'] ?? '';
            if ($action === 'add' && trim($_POST['number'] ?? '') !== '') {
                /*
                 * Два звʼязки, і обидва перевіряються, а не приймаються на віру
                 * з POST: id акаунта й курсу приходять із форми, а звідти в них
                 * може лежати що завгодно. Неіснуючий id тихо стає порожнім —
                 * диплом лишиться без звʼязку, і це чесніше, ніж посилання в
                 * нікуди, яке зламає кабінет уже в студента.
                 */
                $uid = self::resolveStudent($_POST['contact'] ?? '');
                $pid = (int)($_POST['product_id'] ?? 0);
                if ($pid && !DB::val('SELECT id FROM products WHERE id = ? AND type = ?', [$pid, Courses::TYPE])) $pid = 0;
                try {
                    DB::insert('diplomas', [
                        'number' => strtoupper(trim($_POST['number'])), 'student' => trim($_POST['student'] ?? ''),
                        'course' => trim($_POST['course'] ?? '') ?: null,
                        'user_id' => $uid ?: null, 'product_id' => $pid ?: null,
                        'issued_at' => trim($_POST['issued_at'] ?? '') ?: null, 'active' => 1,
                    ]);
                    flash('success', $uid
                        ? 'Диплом додано — він зʼявиться в кабінеті випускника'
                        : 'Диплом додано. Акаунт не вказано, тож у кабінеті він не покажеться: '
                          . 'знайти випускника можна за номером телефону або поштою.');
                } catch (\Throwable $e) { flash('error', 'Такий номер вже існує'); }
            }
            // Привʼязати вже виданий диплом до акаунта: старі записи заводились
            // без цього поля, і зводити їх доводиться руками
            if ($action === 'link') {
                $raw = trim((string)($_POST['contact'] ?? ''));
                $uid = self::resolveStudent($raw);
                if ($raw !== '' && !$uid) {
                    // Не мовчимо: адмін гадав би, чому диплом так і не зʼявився
                    // в кабінеті, а причина проста — такого акаунта ще немає
                    flash('error', 'Акаунта з таким номером чи поштою немає. Спершу випускник має увійти на сайт.');
                } else {
                    DB::update('diplomas', ['user_id' => $uid ?: null], 'id = ?', [(int)$_POST['id']]);
                    flash('success', $uid ? 'Диплом привʼязано до акаунта' : 'Звʼязок з акаунтом знято');
                }
            }
            if ($action === 'toggle') DB::query('UPDATE diplomas SET active = 1 - active WHERE id = ?', [(int)$_POST['id']]);
            if ($action === 'delete') DB::delete('diplomas', 'id = ?', [(int)$_POST['id']]);
            redirect('/admin/diplomas');
        }
        View::show('admin/diplomas', [
            'diplomas' => DB::all(
                'SELECT d.*, u.name AS user_name, u.phone AS user_phone, u.email AS user_email
                 FROM diplomas d LEFT JOIN users u ON u.id = d.user_id ORDER BY d.id DESC'),
            'courses' => Courses::all(),
            'page_title' => 'Дипломи — адмінка',
        ], 'layouts/admin');
    }

    /**
     * Знайти акаунт випускника за тим, що адмін і так знає, — номером або поштою.
     *
     * Списком тут не обійтись: користувачів тисячі, а select із тисячею рядків —
     * це не вибір, а пошук очима. Номер же лежить у тій самій відомості, з якої
     * вносять диплом.
     *
     * Номер нормалізуємо тим самим правилом, що й скрізь (AuthTokens), інакше
     * «067…» з відомості не знайшов би «+38067…» у базі.
     *
     * @return int id акаунта або 0
     */
    private static function resolveStudent(string $raw): int
    {
        $raw = trim($raw);
        if ($raw === '') return 0;
        if (str_contains($raw, '@')) {
            $email = \Newsletter::normEmail($raw);
            return $email ? (int)(DB::val('SELECT id FROM users WHERE email = ?', [$email]) ?? 0) : 0;
        }
        $phone = \AuthTokens::normPhoneAny($raw);
        return $phone ? (int)(DB::val('SELECT id FROM users WHERE phone = ?', [$phone]) ?? 0) : 0;
    }
}
