<?php
declare(strict_types=1);

class Settings
{
    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = [];
            foreach (DB::all('SELECT * FROM settings') as $r) self::$cache[$r['key']] = $r['value'];
        }
        return self::$cache;
    }

    public static function get(string $key, $default = null)
    {
        $all = self::all();
        return array_key_exists($key, $all) && $all[$key] !== null && $all[$key] !== '' ? $all[$key] : $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::get($key);
        if ($v === null) return $default;
        return in_array(strtolower((string)$v), ['1', 'true', 'on', 'yes'], true);
    }

    public static function set(string $key, $value): void
    {
        $exists = DB::val('SELECT COUNT(*) FROM settings WHERE `key` = ?', [$key]);
        if (DB::driver() === 'sqlite') $exists = DB::val('SELECT COUNT(*) FROM settings WHERE "key" = ?', [$key]);
        if ($exists) {
            if (DB::driver() === 'sqlite') DB::query('UPDATE settings SET value = ? WHERE "key" = ?', [$value, $key]);
            else DB::query('UPDATE settings SET value = ? WHERE `key` = ?', [$value, $key]);
        } else {
            DB::insert('settings', ['key' => $key, 'value' => $value]);
        }
        self::$cache = null;
    }
}
