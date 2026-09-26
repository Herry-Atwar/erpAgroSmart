<?php
require_once 'config/database.php';
$db = getDB();
header('Content-Type: text/plain');

// Buat tabel block_plant_varieties untuk PostgreSQL
echo "=== Creating table block_plant_varieties ===\n";
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS block_plant_varieties (
            id              SERIAL PRIMARY KEY,
            block_id        INTEGER NOT NULL,
            variety_id      INTEGER NOT NULL,
            plant_count     INTEGER DEFAULT 0,
            percentage      NUMERIC(5,2) DEFAULT 0.00,
            notes           TEXT,
            created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_bpv_block   FOREIGN KEY (block_id)   REFERENCES blocks(block_id)           ON DELETE CASCADE,
            CONSTRAINT fk_bpv_variety FOREIGN KEY (variety_id) REFERENCES plant_varieties(variety_id) ON DELETE CASCADE,
            CONSTRAINT unique_block_variety UNIQUE (block_id, variety_id)
        )
    ");
    echo "OK - table created\n";
} catch (PDOException $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
}

// Buat index
echo "\n=== Creating indexes ===\n";
try {
    $db->exec("CREATE INDEX IF NOT EXISTS idx_bpv_block_id   ON block_plant_varieties(block_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_bpv_variety_id ON block_plant_varieties(variety_id)");
    echo "OK\n";
} catch (PDOException $e) {
    echo "Index warning: " . $e->getMessage() . "\n";
}

// Verifikasi
echo "\n=== Verify table ===\n";
try {
    $r = $db->query("SELECT COUNT(*) FROM block_plant_varieties");
    echo "Table ready - rows: " . $r->fetchColumn() . "\n";
} catch (PDOException $e) {
    echo "Still failed: " . $e->getMessage() . "\n";
}

echo "\nDone! Tabel berhasil dibuat. Silakan hapus file ini setelah selesai.\n";
