<?php
/**
 * Main Configuration File
 * Includes database configuration and provides PDO connection
 */

// Include database configuration
require_once __DIR__ . '/config/database.php';

// Get PDO connection instance
try {
    $pdo = getDB();
} catch (Exception $e) {
    die("Failed to connect to database: " . $e->getMessage());
}

// Application settings
define('APP_NAME', 'AgroSmart - Agribusiness Intelligence');
define('APP_VERSION', '1.0.0');
define('APP_TIMEZONE', 'Asia/Jakarta');

// Set timezone
date_default_timezone_set(APP_TIMEZONE);

// Error reporting (adjust for production)
error_reporting(E_ALL);
ini_set('display_errors', 1);
?>

// Made with Bob
