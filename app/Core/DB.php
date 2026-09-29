<?php
declare(strict_types=1);

class DB
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $c = cfg('db');
            if (($c['driver'] ?? 'mysql') === 'sqlite') {
                $dir = dirname($c['sqlite_path']);
                if (!is_dir($dir)) mkdir($dir, 0775, true);
                self::$pdo = new PDO('sqlite:' . $c['sqlite_path']);
                self::$pdo->exec('PRAGMA foreign_keys = ON');
                self::$pdo->exec('PRAGMA journal_mode = WAL');
            } else {
                $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
                    $c['host'], (int)$c['port'], $c['database'], $c['charset'] ?? 'utf8mb4');
                self::$pdo = new PDO($dsn, $c['username'], $c['password'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    /*
                     * Справжні підготовлені запити, а не підстановка рядком.
                     *
                     * За замовчуванням PDO для MySQL підставляє значення сам, у
                     * своєму коді, і лише потім шле готовий SQL. Екранує він
                     * правильно, тож дірки тут не було, — але захист від
                     * впровадження тримається на цьому екрануванні, а не на
                     * тому, що значення й запит їдуть різними шляхами.
                     * З false вони справді їдуть різними: сервер отримує запит
                     * із «?» і значення окремо, і зробити з даних команду
                     * неможливо в принципі.
                     *
                     * Побічно це ще й прибирає клас помилок із типами: у
                     * реальних підготовлених запитах число лишається числом, а
                     * не перетворюється на рядок дорогою.
                     */
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
                ]);
            }
            self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        }
        return self::$pdo;
    }

    public static function driver(): string { return cfg('db.driver', 'mysql'); }

    public static function query(string $sql, array $params = []): PDOStatement
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st;
    }

    public static function all(string $sql, array $params = []): array
    { return self::query($sql, $params)->fetchAll(); }

    public static function row(string $sql, array $params = []): ?array
    { $r = self::query($sql, $params)->fetch(); return $r === false ? null : $r; }

    public static function val(string $sql, array $params = [])
    { $r = self::query($sql, $params)->fetchColumn(); return $r === false ? null : $r; }

    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = "INSERT INTO `$table` (`" . implode('`,`', $cols) . "`) VALUES (" .
               implode(',', array_fill(0, count($cols), '?')) . ")";
        if (self::driver() === 'sqlite') $sql = str_replace('`', '"', $sql);
        self::query($sql, array_values($data));
        return (int)self::pdo()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $sets = implode(',', array_map(fn($c) => "`$c` = ?", array_keys($data)));
        $sql = "UPDATE `$table` SET $sets WHERE $where";
        if (self::driver() === 'sqlite') $sql = str_replace('`', '"', $sql);
        $st = self::query($sql, array_merge(array_values($data), $whereParams));
        return $st->rowCount();
    }

    public static function delete(string $table, string $where, array $params = []): int
    {
        $sql = "DELETE FROM `$table` WHERE $where";
        if (self::driver() === 'sqlite') $sql = str_replace('`', '"', $sql);
        return self::query($sql, $params)->rowCount();
    }

    public static function tx(callable $fn)
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try { $res = $fn(); $pdo->commit(); return $res; }
        catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    }
}
