<?php
/**
 * Database Configuration for AgroSmart - Agribusiness Intelligence
 * PostgreSQL / Supabase
 *
 * Credentials are loaded from a .env file in the project root.
 * NEVER hardcode passwords in this file.
 * NEVER commit .env to Git.
 *
 * .env format (one KEY=VALUE per line):
 *   DB_HOST=db.xxxxxxxxxxxxxxxx.supabase.co
 *   DB_PORT=5432
 *   DB_USER=postgres
 *   DB_PASS=your_password_here
 *   DB_NAME=postgres
 */

// ── Load .env from the project root ──────────────────────────────────────────
$_env_file = __DIR__ . '/../.env';

if (file_exists($_env_file)) {
    foreach (file($_env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $_line) {
        $_line = trim($_line);
        // Skip comments and blank lines
        if ($_line === '' || $_line[0] === '#' || !str_contains($_line, '=')) {
            continue;
        }
        [$_key, $_val] = explode('=', $_line, 2);
        $_key = trim($_key);
        $_val = trim($_val);
        // Strip surrounding quotes if present
        if (strlen($_val) >= 2 && (
            ($val[0] ?? '') === '"'  && ($_val[-1] ?? '') === '"'  ||
            ($val[0] ?? '') === "'"  && ($_val[-1] ?? '') === "'"
        )) {
            $_val = substr($_val, 1, -1);
        }
        if (!isset($_ENV[$_key])) {
            $_ENV[$_key]    = $_val;
            putenv("$_key=$_val");
        }
    }
}

// ── Helper: read from $_ENV, then getenv(), then fall back to default ─────────
function _env(string $key, string $default = ''): string {
    return $_ENV[$key] ?? (getenv($key) ?: $default);
}

// ── Database constants ────────────────────────────────────────────────────────
define('DB_DRIVER', 'pgsql');
define('DB_HOST',   _env('DB_HOST', 'localhost'));
define('DB_PORT',   _env('DB_PORT', '5432'));
define('DB_USER',   _env('DB_USER', ''));
define('DB_PASS',   _env('DB_PASS', '######'));
define('DB_NAME',   _env('DB_NAME', 'postgres'));

// ── Database connection class ─────────────────────────────────────────────────
class Database {
    private static $instance = null;
    private $connection = null;

    private function __construct() {
        try {
            $dsn = "pgsql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME;

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];

            $this->connection = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            error_log("Database Connection Failed: " . $e->getMessage());
            throw $e;
        }
    }

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection(): PDO {
        return $this->connection;
    }

    private function __clone() {}

    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }
}

// ── Helper function to get database connection ────────────────────────────────
function getDB(): PDO {
    return Database::getInstance()->getConnection();
}
