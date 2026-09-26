<?php
/**
 * Storage Tanks Migration Script
 * Migrates storage_tanks table from MariaDB (agro) to PostgreSQL (agro-odoo)
 * 
 * Usage: php migrate_storage_tanks.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "=================================================\n";
echo "Storage Tanks Migration: MariaDB to PostgreSQL\n";
echo "=================================================\n\n";

// Source Database (MariaDB)
$source_config = [
    'host' => 'localhost',
    'user' => 'root',
    'pass' => '',
    'name' => 'plantation',
    'charset' => 'utf8mb4'
];

// Target Database (PostgreSQL)
$target_config = [
    'host' => 'localhost',
    'port' => '5432',
    'user' => 'odoo',
    'pass' => 'odoopwd',
    'name' => 'plantation'
];

try {
    // Connect to source MariaDB
    echo "Step 1: Connecting to source MariaDB database...\n";
    $source_dsn = "mysql:host={$source_config['host']};dbname={$source_config['name']};charset={$source_config['charset']}";
    $source_db = new PDO($source_dsn, $source_config['user'], $source_config['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    echo "✓ Connected to MariaDB (plantation)\n\n";
    
    // Connect to target PostgreSQL
    echo "Step 2: Connecting to target PostgreSQL database...\n";
    $target_dsn = "pgsql:host={$target_config['host']};port={$target_config['port']};dbname={$target_config['name']}";
    $target_db = new PDO($target_dsn, $target_config['user'], $target_config['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    echo "✓ Connected to PostgreSQL (plantation)\n\n";
    
    // Check if source table exists
    echo "Step 3: Checking source table...\n";
    $check_source = $source_db->query("SHOW TABLES LIKE 'storage_tanks'")->fetchAll();
    if (empty($check_source)) {
        throw new Exception("Source table 'storage_tanks' does not exist in MariaDB!");
    }
    echo "✓ Source table 'storage_tanks' found\n\n";
    
    // Get record count from source
    $source_count = $source_db->query("SELECT COUNT(*) as count FROM storage_tanks")->fetch()['count'];
    echo "Source table has {$source_count} records\n\n";
    
    if ($source_count == 0) {
        echo "⚠ Warning: Source table is empty. No data to migrate.\n";
        echo "Migration completed (no data).\n";
        exit(0);
    }
    
    // Fetch all data from source
    echo "Step 4: Fetching data from MariaDB...\n";
    $stmt = $source_db->query("
        SELECT 
            tank_id,
            tank_code,
            tank_name,
            tank_type,
            capacity_kg,
            location,
            status,
            remarks,
            created_at,
            updated_at,
            created_by,
            updated_by
        FROM storage_tanks
        ORDER BY tank_id
    ");
    $records = $stmt->fetchAll();
    echo "✓ Fetched {$source_count} records\n\n";
    
    // Check if target table exists
    echo "Step 5: Checking target table...\n";
    $check_target = $target_db->query("
        SELECT EXISTS (
            SELECT FROM information_schema.tables 
            WHERE table_schema = 'public' 
            AND table_name = 'storage_tanks'
        )
    ")->fetchColumn();
    
    if (!$check_target) {
        echo "⚠ Target table 'storage_tanks' does not exist!\n";
        echo "Please run the schema creation script first:\n";
        echo "psql -U odoo -d plantation -f database/create_storage_tanks_postgresql.sql\n";
        exit(1);
    }
    echo "✓ Target table 'storage_tanks' exists\n\n";
    
    // Check if target table has data
    $target_count = $target_db->query("SELECT COUNT(*) as count FROM storage_tanks")->fetch()['count'];
    if ($target_count > 0) {
        echo "⚠ Warning: Target table already has {$target_count} records.\n";
        echo "Do you want to:\n";
        echo "  1. Skip migration (keep existing data)\n";
        echo "  2. Clear and reimport (delete existing data)\n";
        echo "  3. Append new records (may cause duplicates)\n";
        echo "Enter choice (1-3): ";
        
        $handle = fopen("php://stdin", "r");
        $choice = trim(fgets($handle));
        fclose($handle);
        
        if ($choice == '1') {
            echo "Migration skipped. Existing data preserved.\n";
            exit(0);
        } elseif ($choice == '2') {
            echo "\nClearing existing data...\n";
            $target_db->exec("TRUNCATE TABLE storage_tanks RESTART IDENTITY CASCADE");
            echo "✓ Existing data cleared\n\n";
        } elseif ($choice == '3') {
            echo "\nAppending to existing data...\n\n";
        } else {
            echo "Invalid choice. Migration cancelled.\n";
            exit(1);
        }
    }
    
    // Begin transaction
    echo "Step 6: Importing data to PostgreSQL...\n";
    $target_db->beginTransaction();
    
    // Prepare insert statement
    $insert_stmt = $target_db->prepare("
        INSERT INTO storage_tanks (
            tank_id, tank_code, tank_name, tank_type, capacity_kg,
            location, status, remarks, created_at, updated_at,
            created_by, updated_by
        ) VALUES (
            :tank_id, :tank_code, :tank_name, :tank_type, :capacity_kg,
            :location, :status, :remarks, :created_at, :updated_at,
            :created_by, :updated_by
        )
    ");
    
    $imported = 0;
    $errors = 0;
    
    foreach ($records as $record) {
        try {
            $insert_stmt->execute([
                ':tank_id' => $record['tank_id'],
                ':tank_code' => $record['tank_code'],
                ':tank_name' => $record['tank_name'],
                ':tank_type' => $record['tank_type'],
                ':capacity_kg' => $record['capacity_kg'],
                ':location' => $record['location'],
                ':status' => $record['status'],
                ':remarks' => $record['remarks'],
                ':created_at' => $record['created_at'],
                ':updated_at' => $record['updated_at'],
                ':created_by' => $record['created_by'],
                ':updated_by' => $record['updated_by']
            ]);
            $imported++;
            echo "  ✓ Imported: {$record['tank_code']} - {$record['tank_name']}\n";
        } catch (PDOException $e) {
            $errors++;
            echo "  ✗ Error importing {$record['tank_code']}: {$e->getMessage()}\n";
        }
    }
    
    // Update sequence to match the highest tank_id
    if ($imported > 0) {
        $max_id = $target_db->query("SELECT MAX(tank_id) FROM storage_tanks")->fetchColumn();
        $target_db->exec("SELECT setval('storage_tanks_tank_id_seq', {$max_id}, true)");
    }
    
    // Commit transaction
    $target_db->commit();
    
    echo "\n";
    echo "=================================================\n";
    echo "Migration Summary\n";
    echo "=================================================\n";
    echo "Source records:    {$source_count}\n";
    echo "Imported records:  {$imported}\n";
    echo "Errors:            {$errors}\n";
    echo "=================================================\n\n";
    
    // Verify migration
    echo "Step 7: Verifying migration...\n";
    $final_count = $target_db->query("SELECT COUNT(*) FROM storage_tanks")->fetchColumn();
    echo "Target table now has {$final_count} records\n";
    
    // Show sample data
    echo "\nSample records in PostgreSQL:\n";
    $samples = $target_db->query("
        SELECT tank_code, tank_name, tank_type, capacity_kg, status
        FROM storage_tanks
        ORDER BY tank_id
        LIMIT 5
    ")->fetchAll();
    
    foreach ($samples as $sample) {
        echo "  - {$sample['tank_code']}: {$sample['tank_name']} ({$sample['tank_type']}, {$sample['capacity_kg']} kg, {$sample['status']})\n";
    }
    
    echo "\n✓ Migration completed successfully!\n\n";
    
} catch (PDOException $e) {
    if (isset($target_db) && $target_db->inTransaction()) {
        $target_db->rollBack();
    }
    echo "\n✗ Database Error: " . $e->getMessage() . "\n";
    exit(1);
} catch (Exception $e) {
    echo "\n✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>

// Made with Bob
