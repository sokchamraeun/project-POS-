<?php
declare(strict_types=1);

/**
 * db.php — Production-Ready PDO Database Connection Singleton
 * Architecture: Singleton Pattern with Strict Exception Handling & UTF-8mb4
 */

final class Database
{
    private static ?Database $instance = null;
    private ?PDO $pdo = null;

    /**
     * Private constructor to prevent direct instantiation
     */
    private function __construct()
    {
        date_default_timezone_set('Asia/Phnom_Penh');

        $candidates = [];

        // 1. Local override if present
        if (is_file(__DIR__ . '/db_config.local.php')) {
            require __DIR__ . '/db_config.local.php';
            if (isset($servername, $username, $password, $dbname)) {
                $candidates[] = [
                    'host' => (string)$servername,
                    'port' => (string)($port ?? '3306'),
                    'name' => (string)$dbname,
                    'user' => (string)$username,
                    'pass' => (string)$password
                ];
            }
        }

        // 2. Environment variables
        if (getenv('DB_NAME') || getenv('DB_USER')) {
            $candidates[] = [
                'host' => getenv('DB_HOST') ?: '127.0.0.1',
                'port' => getenv('DB_PORT') ?: '3306',
                'name' => getenv('DB_NAME') ?: 'db_coffeeshop_final--',
                'user' => getenv('DB_USER') ?: 'root',
                'pass' => getenv('DB_PASS') !== false ? getenv('DB_PASS') : ''
            ];
        }

        // 3. Environment detection
        $is_local = (
            (!empty($_SERVER['SERVER_NAME']) && in_array($_SERVER['SERVER_NAME'], ['localhost', '127.0.0.1', '::1'], true)) ||
            (!empty($_SERVER['HTTP_HOST']) && (str_contains($_SERVER['HTTP_HOST'], 'localhost') || str_contains($_SERVER['HTTP_HOST'], '127.0.0.1'))) ||
            (php_sapi_name() === 'cli' && stripos(__DIR__, 'xampp') !== false)
        );

        if ($is_local) {
            $candidates[] = ['host' => '127.0.0.1', 'port' => '3306', 'name' => 'db_coffeeshop_final--', 'user' => 'root', 'pass' => ''];
            $candidates[] = ['host' => 'localhost', 'port' => '3306', 'name' => 'db_coffeeshop_final--', 'user' => 'root', 'pass' => ''];
            $candidates[] = ['host' => 'localhost', 'port' => '3306', 'name' => 'db_coffee', 'user' => 'root', 'pass' => ''];
            $candidates[] = ['host' => 'localhost', 'port' => '3306', 'name' => 'dpdc690_pos', 'user' => 'dpdc690_pos', 'pass' => 'Coffee@_1234'];
        } else {
            $candidates[] = ['host' => 'localhost', 'port' => '3306', 'name' => 'dpdc690_pos', 'user' => 'dpdc690_pos', 'pass' => 'Coffee@_1234'];
            $candidates[] = ['host' => 'localhost', 'port' => '3306', 'name' => 'dpdc690_dbcoffee', 'user' => 'dpdc690_pos', 'pass' => 'Coffee@_1234'];
            $candidates[] = ['host' => '127.0.0.1', 'port' => '3306', 'name' => 'db_coffeeshop_final--', 'user' => 'root', 'pass' => ''];
        }

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_PERSISTENT         => false,
        ];

        $last_ex = null;
        foreach ($candidates as $c) {
            try {
                $dsn = "mysql:host={$c['host']};port={$c['port']};dbname={$c['name']};charset=utf8mb4";
                $this->pdo = new PDO($dsn, $c['user'], $c['pass'], $options);
                $this->pdo->exec("SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'");
                $this->pdo->exec("SET time_zone = '+07:00'");
                break;
            } catch (Throwable $e) {
                $last_ex = $e;
            }
        }

        if (!$this->pdo) {
            error_log("[Database Connection Error] " . ($last_ex ? $last_ex->getMessage() : 'No candidate matched'));
            throw new RuntimeException("Database connection failed. Please contact administrator.");
        }
    }

    /**
     * Prevent object cloning
     */
    private function __clone() {}

    /**
     * Prevent unserialization
     */
    public function __wakeup(): void
    {
        throw new RuntimeException("Cannot unserialize singleton.");
    }

    /**
     * Get Database singleton instance
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get active PDO connection
     */
    public static function getPdo(): PDO
    {
        return self::getInstance()->pdo;
    }
}
