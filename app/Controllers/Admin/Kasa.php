<?php
declare(strict_types=1);

namespace Controllers\Admin;

use DB, View, Auth, Settings, Catalog, RateLimit, Fiscal, FiscalProvider, Sheet, VchasnoGoods;

/**
 * Каса: те, що стосується самої каси, а не окремого замовлення.
 *
 * Зміна, звіти й готівка в скриньці — це стан торгової точки; чеки живуть у
 * картках замовлень, і дублювати їх тут ні до чого. Виняток один: чеки, які не
 * пройшли, — їх шукають не по замовленнях, а «покажіть усе, що впало».
 *
 * Каса = торгова точка. Навіть коли всі точки працюють на одному ПРРО, питання
 * «чи відкрита зміна» ставлять про конкретний магазин, а не про мережу.
 *
 * Важливе: у маршрутах, де ключ лежить у магазині, наш сервер до каси не
 * ходить — тому кнопки не «роблять», а СТАВЛЯТЬ У ЧЕРГУ. Різницю видно на
 * екрані: інакше людина тисне «Z-звіт», нічого не відбувається, і вона тисне
 * ще пʼять разів.
 */
class Kasa
{
    /** Куди складаємо вивантаження з кабінету, поки з ним працюють */
    private const UPLOAD_DIR = BOFU_ROOT . '/storage/vchasno';

    /**
     * Каси, до яких у нас є доступ: по одній на активну точку.
     *
     * @return array<int, array{id:string,store_id:int,name:string,route:array,gaps:array,agent:array}>
     */
    private static function cases(): array
    {
        $out = [];
        foreach (DB::all('SELECT * FROM stores WHERE active = 1 ORDER BY sort, id') as $s) {
            $route = FiscalProvider::route((int)$s['id']);
            $gaps = FiscalProvider::missing($route);
            // Точка без налаштованої каси в перелік не потрапляє: показувати
            // екран, який уміє лише сказати «токена немає», сенсу немає.
            if ($gaps && $route['source'] === 'global' && $route['route'] === 'cloud') continue;
            // ПРРО ДПС показуємо й ненастроєним: саме тут видно, чого бракує
            $seen = trim((string)($s['agent_seen_at'] ?? ''));
            $out[] = [
                'id' => (string)(int)$s['id'],
                'store_id' => (int)$s['id'],
                'name' => (string)$s['name'],
                'route' => $route,
                'gaps' => $gaps,
                'store' => $s,
                'agent' => [
                    'seen' => $seen,
                    'alive' => $seen !== '' && strtotime($seen) > time() - 300,
                    'ready' => trim((string)($s['agent_hash'] ?? '')) !== '',
                ],
            ];
        }
        return $out;
    }

    private static function currentCase(array $cases): ?array
    {
        $want = (string)($_POST['case'] ?? $_GET['case'] ?? '');
        foreach ($cases as $c) if ($c['id'] === $want) return $c;
        return $cases[0] ?? null;
    }

    public static function index(): never
    {
        Auth::requireCap('fiscal.manage');
        $cases = self::cases();
        $case = self::currentCase($cases);

        if (is_post() && $case) self::action($case);

        // Стан питаємо лише там, де до каси ходить наш сервер. У решті
        // маршрутів синхронної відповіді немає й бути не може — там про стан
        // говорять журнал службових завдань і те, чи виходив на звʼязок агент.
        $status = null;
        if ($case && $case['route']['route'] === 'cloud' && !$case['gaps']) {
            $status = \Vchasno::status($case['store_id']);
        }

        View::show('admin/vchasno/index', [
            'cases' => $cases,
            'case' => $case,
            'status' => $status,
            'info' => $status && $status['ok'] ? \VchasnoDoc::status($status['data']) : [],
            'service' => $case ? Fiscal::serviceLog($case['store_id']) : [],
            /*
             * Завдання, які має виконати браузер цієї людини.
             *
             * Без цього рядка маршрут «каса на пристрої» мав діру: нічний
             * Z-звіт ставав у чергу з cron, а виконати його не було кому —
             * бігунок жив лише в картці замовлення й забирав завдання лише
             * того замовлення. Каса, у якої зміна не закривається, за добу
             * просто перестає приймати чеки.
             *
             * Тут він забирає ВСЕ, що чекає на цю людину, — і чеки, і службові
             * завдання. Тобто досить зайти на сторінку каси.
             */
            'fiscal_jobs' => Auth::can('orders.fiscal')
                ? count(Fiscal::queuedForUser((int)Auth::id())) : 0,
            // Чеки — лише обраної точки. У мережі точки можуть належати різним
            // ФОПам, і зводити їхні чеки в один список означало б показувати
            // бухгалтеру однієї людини виторг іншої.
            'broken' => $case ? DB::all("SELECT f.*, o.number FROM fiscal_receipts f
                                 LEFT JOIN orders o ON o.id = f.order_id
                                 WHERE f.status NOT IN ('done') AND f.type <> 'service' AND f.store_id = ?
                                 ORDER BY f.id DESC LIMIT 30", [$case['store_id']]) : [],
            'recent' => $case ? DB::all("SELECT f.*, o.number FROM fiscal_receipts f
                                 LEFT JOIN orders o ON o.id = f.order_id
                                 WHERE f.status = 'done' AND f.type <> 'service' AND f.store_id = ?
                                 ORDER BY f.id DESC LIMIT 20", [$case['store_id']]) : [],
            'page_title' => 'Каса — адмінка',
        ], 'layouts/admin');
    }

    /**
     * Дії над касою.
     *
     * Кожна з них — справжня фіскальна операція, тому ліміт спільний і тісний:
     * він тут не від ботів, а від подвійного натискання. Z-звіт двічі поспіль
     * не страшний (друга спроба нічого не закриє), а от два службові внесення
     * розійдуться з готівкою у скриньці.
     */
    private static function action(array $case): void
    {
        $action = (string)($_POST['_action'] ?? '');
        $cashier = (string)(Auth::user()['name'] ?? '');
        $back = '/admin/vchasno?case=' . rawurlencode($case['id']);
        RateLimit::guard('vchasno_kasa', 60, 3600);

        $tasks = [
            'shift_open' => 'Відкриття зміни',
            'x_report' => 'X-звіт',
            'shift_close' => 'Z-звіт',
            'cash_in' => 'Внесення',
            'cash_out' => 'Видача',
        ];

        if (isset($tasks[$action])) {
            $extra = ['cashier' => $cashier];
            if ($action === 'cash_in' || $action === 'cash_out') {
                $extra['sum'] = (float)str_replace(',', '.', (string)($_POST['sum'] ?? ''));
                $extra['comment'] = (string)($_POST['comment'] ?? '');
            }
            $r = Fiscal::service($action, $case['store_id'], Auth::id(), $extra);
            // Три різні відповіді, і плутати їх не можна: «зроблено» каже лише
            // хмара, решта маршрутів чесно каже «поставили в чергу».
            if ($r['state'] === 'queued') {
                flash('success', $tasks[$action] . ': завдання пішло на касу — результат зʼявиться нижче.');
            } elseif ($r['ok']) {
                flash('success', $tasks[$action] . ': виконано.');
            } else {
                flash('error', $tasks[$action] . ' не виконано. ' . $r['error']);
            }
            redirect($back);
        }

        if ($action === 'retry_all') {
            $done = 0; $left = 0;
            foreach (Fiscal::due(20) as $r) {
                $res = Fiscal::retry($r, Auth::id());
                $res['ok'] ? $done++ : $left++;
            }
            $back2 = Fiscal::requeueStale();
            flash($left ? 'error' : 'success',
                ($done || $left)
                    ? "Перепитано: пройшло — $done, досі без відповіді — $left."
                      . ($back2 ? " Повернуто в чергу: $back2." : '')
                    : ($back2 ? "Повернуто в чергу: $back2." : 'Непевних чеків немає — перепитувати нічого.'));
            redirect($back);
        }
    }

    // ──────────────────────────────────────────────────────────────── товари

    public static function goods(): never
    {
        Auth::requireCap('fiscal.manage');
        $storeId = (int)($_POST['store_id'] ?? $_GET['store'] ?? 0) ?: null;

        if (is_post()) {
            $action = (string)($_POST['_action'] ?? '');
            if ($action === 'export') self::exportGoods($storeId);
            if ($action === 'upload') self::uploadGoods($storeId);
            if ($action === 'apply') self::applyGoods($storeId);
            if ($action === 'forget') {
                self::forgetUpload();
                flash('success', 'Файл прибрано.');
                redirect('/admin/vchasno/goods');
            }
        }

        // Звірку рахуємо з файлу щоразу, а не тримаємо в сесії: каталог у нас
        // міняється між відкриттями сторінки, і показувати вчорашню звірку як
        // сьогоднішню — це рівно той випадок, коли краще не показувати нічого.
        $report = null; $parsed = null;
        $file = self::uploadPath();
        if ($file !== null) {
            $parsed = VchasnoGoods::parse($file);
            if ($parsed['error'] === '') $report = VchasnoGoods::compare($parsed['goods'], $storeId);
        }

        View::show('admin/vchasno/goods', [
            'stores' => Catalog::stores(),
            'store_id' => $storeId,
            'has_file' => $file !== null,
            'file_name' => (string)($_SESSION['vchasno_goods_name'] ?? ''),
            'parsed' => $parsed,
            'report' => $report,
            'tax_groups' => \Vchasno::TAX_GROUPS,
            'page_title' => 'Каса: товари — адмінка',
        ], 'layouts/admin');
    }

    /** Наш каталог у файл, який приймає імпорт кабінету */
    private static function exportGoods(?int $storeId): never
    {
        $rows = VchasnoGoods::export($storeId);
        $csv = (string)($_POST['format'] ?? '') === 'csv';
        $body = $csv ? Sheet::writeCsv($rows) : Sheet::writeXlsx($rows);
        $name = 'starvud-tovary-' . date('Y-m-d') . ($csv ? '.csv' : '.xlsx');

        header('Content-Type: ' . ($csv ? 'text/csv; charset=utf-8'
            : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'));
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . strlen($body));
        echo $body;
        exit;
    }

    /**
     * Прийняти вивантаження з кабінету.
     *
     * Файл кладемо в storage і памʼятаємо в сесії, а не тримаємо в ній самі
     * товари: каталог на кілька тисяч позицій роздув би сесію, яку читає кожен
     * запит сайту. Живе він до кінця роботи з ним — кнопка «прибрати» поруч.
     */
    private static function uploadGoods(?int $storeId): never
    {
        $f = $_FILES['file'] ?? null;
        $back = '/admin/vchasno/goods' . ($storeId ? '?store=' . $storeId : '');
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            flash('error', 'Файл не обрано або не завантажився. Потрібне вивантаження товарів із кабінету ПРРО у форматі CSV.');
            redirect($back);
        }
        if ((int)$f['size'] > 8 * 1024 * 1024) {
            flash('error', 'Файл завеликий — більше 8 МБ. Це точно вивантаження товарів?');
            redirect($back);
        }
        if (!is_dir(self::UPLOAD_DIR)) @mkdir(self::UPLOAD_DIR, 0775, true);
        self::forgetUpload();

        $path = self::UPLOAD_DIR . '/goods-' . bin2hex(random_bytes(8));
        if (!@move_uploaded_file($f['tmp_name'], $path)) {
            flash('error', 'Не вдалося зберегти файл — перевірте права на теку storage.');
            redirect($back);
        }
        $_SESSION['vchasno_goods_file'] = $path;
        $_SESSION['vchasno_goods_name'] = mb_substr((string)($f['name'] ?? 'файл'), 0, 100);

        $parsed = VchasnoGoods::parse($path);
        if ($parsed['error'] !== '') {
            self::forgetUpload();
            flash('error', $parsed['error']);
        } else {
            flash('success', 'Прочитано позицій: ' . count($parsed['goods']) . '.');
        }
        redirect($back);
    }

    /** Перенести до себе те, чого в нас немає */
    private static function applyGoods(?int $storeId): never
    {
        $back = '/admin/vchasno/goods' . ($storeId ? '?store=' . $storeId : '');
        $file = self::uploadPath();
        if ($file === null) { flash('error', 'Файл загубився — завантажте його ще раз.'); redirect($back); }

        $parsed = VchasnoGoods::parse($file);
        if ($parsed['error'] !== '') { flash('error', $parsed['error']); redirect($back); }

        $report = VchasnoGoods::compare($parsed['goods'], $storeId);
        $r = VchasnoGoods::apply($report['rows']);
        flash($r['filled'] ? 'success' : 'error',
            $r['filled']
                ? 'Заповнено порожніх полів у позиціях: ' . $r['filled'] . '.'
                  . ($r['notes'] ? ' Не чіпали: ' . implode('; ', array_slice($r['notes'], 0, 5))
                     . (count($r['notes']) > 5 ? ' та інші' : '') . '.' : '')
                : 'Нічого переносити: усе, що збіглося, у нас уже заповнене.');
        redirect($back);
    }

    private static function uploadPath(): ?string
    {
        $p = (string)($_SESSION['vchasno_goods_file'] ?? '');
        return $p !== '' && is_file($p) ? $p : null;
    }

    private static function forgetUpload(): void
    {
        $p = (string)($_SESSION['vchasno_goods_file'] ?? '');
        if ($p !== '' && is_file($p)) @unlink($p);
        unset($_SESSION['vchasno_goods_file'], $_SESSION['vchasno_goods_name']);
    }
}
