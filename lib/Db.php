<?php
declare(strict_types=1);
require_once __DIR__.'/../config.php';

final class Db {
    private static ?PDO $pdo = null;

    public static function pdo(): PDO {
        if (self::$pdo) return self::$pdo;
        $driver = env('DB_DRIVER', 'mysql');
        $host = env('DB_HOST', '127.0.0.1');
        $port = env('DB_PORT', $driver === 'pgsql' ? '5432' : '3306');
        $db   = env('DB_NAME', 'kaspi_amocrm');
        $user = env('DB_USER', 'root');
        $pass = env('DB_PASS', '');

        if ($driver === 'pgsql') {
            $dsn = "pgsql:host={$host};port={$port};dbname={$db}";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ];
        } else {
            $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
        }
        self::$pdo = new PDO($dsn, $user, $pass, $options);
        return self::$pdo;
    }

    public static function getSetting(string $key, ?string $default=null): ?string {
        $driver = env('DB_DRIVER', 'mysql');
        $column = $driver === 'pgsql' ? '"key"' : '`key`';
        $stmt = self::pdo()->prepare("SELECT value FROM settings WHERE {$column} = :key");
        $stmt->execute([':key' => $key]);
        $row = $stmt->fetch();
        return $row['value'] ?? $default;
    }

    public static function setSetting(string $key, string $value): void {
        $driver = env('DB_DRIVER', 'mysql');
        if ($driver === 'pgsql') {
            $sql = "INSERT INTO settings(key, value) VALUES(:key,:value)
                    ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value";
        } else {
            $sql = "INSERT INTO settings(`key`, `value`) VALUES(:key,:value)
                    ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)";
        }
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute([':key'=>$key, ':value'=>$value]);
    }
}
