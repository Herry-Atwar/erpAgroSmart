<?php
// TEMPORARY diagnostic — delete after use
// Access: https://inodesain.com/erpagrosmart/phpcheck.php
echo '<pre>';
echo 'PHP Version: ' . PHP_VERSION . "\n";
echo 'PHP Major:   ' . PHP_MAJOR_VERSION . "\n";
echo "\n";
echo 'str_contains exists: ' . (function_exists('str_contains') ? 'YES (PHP 8+)' : 'NO (PHP 7)') . "\n";
echo 'match() test: ';
// Can't test match() here — parse error in PHP 7 would break this file too.
// Instead check version directly:
if (PHP_MAJOR_VERSION >= 8) {
    echo "OK (PHP 8+)\n";
} else {
    echo "FAIL — PHP " . PHP_VERSION . " does not support match(). Upgrade to PHP 8.0+ in Hostinger hPanel.\n";
}
echo "\n";
echo 'Loaded ini: ' . php_ini_loaded_file() . "\n";
echo 'Additional ini: ' . php_ini_scanned_files() . "\n";
echo "\n";
echo '--- Session ---' . "\n";
session_start();
echo 'Session save path: ' . session_save_path() . "\n";
echo 'Session writable:  ' . (is_writable(session_save_path() ?: sys_get_temp_dir()) ? 'YES' : 'NO') . "\n";
echo "\n";
echo '--- DB Connection ---' . "\n";
$env = __DIR__ . '/.env';
echo '.env exists: ' . (file_exists($env) ? 'YES' : 'NO — DB will fail') . "\n";
echo '</pre>';
