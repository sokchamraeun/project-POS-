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
        // Default local configuration (fallback to config.php or environment)
        $host    = getenv('DB_HOST') ?: '127.0.0.1';
        $port    = getenv('DB_PORT') ?: '3306';
        $dbname  = getenv('DB_NAME') ?: 'db_coffeeshop_final--';
        $user    = getenv('DB_USER') ?: 'root';
        $pass    = getenv('DB_PASS') ?: '';
        $charset = 'utf8mb4';

        // Check if local config file exists for custom credentials
        if (is_file(__DIR__ . '/db_config.local.php')) {
            require __DIR__ . '/db_config.local.php';
            if (isset($servername)) $host = $servername;
            if (isset($username))   $user = $username;
            if (isset($password))   $pass = $password;
            if (isset($dbname_custom)) $dbname = $dbname_custom;
        }

        date_default_timezone_set('Asia/Phnom_Penh');

        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_PERSISTENT         => false,
        ];

        try {
            $this->pdo = new PDO($dsn, $user, $pass, $options);
            $this->pdo->exec("SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'");
            $this->pdo->exec("SET time_zone = '+07:00'");
        } catch (PDOException $e) {
            error_log("[Database Connection Error] " . $e->getMessage());
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
