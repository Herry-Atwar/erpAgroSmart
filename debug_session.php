<?php
/**
 * Debug Session - Check if user company is set
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h2>Session Debug Information</h2>";
echo "<pre>";

echo "=== Session Status ===\n";
echo "Session ID: " . session_id() . "\n";
echo "Session Status: " . (session_status() === PHP_SESSION_ACTIVE ? 'Active' : 'Inactive') . "\n\n";

echo "=== User Company ===\n";
$user_company_id = get_user_company_id();
echo "User Company ID: " . ($user_company_id ? $user_company_id : 'NOT SET') . "\n";
echo "Has User Company: " . (has_user_company() ? 'YES' : 'NO') . "\n\n";

echo "=== All Session Data ===\n";
print_r($_SESSION);

echo "\n=== Test Setting Company ===\n";
echo "To set a company, go to: <a href='set_user_company.php'>set_user_company.php</a>\n";

echo "</pre>";

// Show link to journal entries
echo "<p><a href='journal_entries.php'>Go to Journal Entries</a></p>";
?>

// Made with Bob
