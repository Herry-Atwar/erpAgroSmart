<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\StorageTank;

class StorageTankSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $tanks = [
            [
                'tank_id' => 1,
                'tank_code' => 'TANK-001',
                'tank_name' => 'Main Storage Tank 1',
                'tank_type' => 'vertical',
                'capacity_kg' => 500000,
                'location' => 'Mill Area A - Section 1',
                'status' => 'active',
                'created_by' => 'system',
            ],
            [
                'tank_id' => 2,
                'tank_code' => 'TANK-002',
                'tank_name' => 'Main Storage Tank 2',
                'tank_type' => 'vertical',
                'capacity_kg' => 500000,
                'location' => 'Mill Area A - Section 2',
                'status' => 'active',
                'created_by' => 'system',
            ],
            [
                'tank_id' => 3,
                'tank_code' => 'TANK-003',
                'tank_name' => 'Main Storage Tank 3',
                'tank_type' => 'vertical',
                'capacity_kg' => 500000,
                'location' => 'Mill Area A - Section 3',
                'status' => 'active',
                'created_by' => 'system',
            ],
            [
                'tank_id' => 4,
                'tank_code' => 'TANK-004',
                'tank_name' => 'Reserve Tank 1',
                'tank_type' => 'vertical',
                'capacity_kg' => 300000,
                'location' => 'Mill Area B - Section 1',
                'status' => 'active',
                'created_by' => 'system',
            ],
            [
                'tank_id' => 5,
                'tank_code' => 'TANK-005',
                'tank_name' => 'Reserve Tank 2',
                'tank_type' => 'vertical',
                'capacity_kg' => 300000,
                'location' => 'Mill Area B - Section 2',
                'status' => 'active',
                'created_by' => 'system',
            ],
            [
                'tank_id' => 6,
                'tank_code' => 'TANK-006',
                'tank_name' => 'Export Holding Tank',
                'tank_type' => 'horizontal',
                'capacity_kg' => 200000,
                'location' => 'Export Terminal',
                'status' => 'active',
                'created_by' => 'system',
            ],
            [
                'tank_id' => 7,
                'tank_code' => 'TANK-007',
                'tank_name' => 'Quality Control Tank',
                'tank_type' => 'vertical',
                'capacity_kg' => 100000,
                'location' => 'QC Laboratory Area',
                'status' => 'active',
                'created_by' => 'system',
            ],
            [
                'tank_id' => 8,
                'tank_code' => 'TANK-008',
                'tank_name' => 'Emergency Tank',
                'tank_type' => 'underground',
                'capacity_kg' => 150000,
                'location' => 'Underground Storage',
                'status' => 'maintenance',
                'created_by' => 'system',
            ],
        ];

        foreach ($tanks as $tank) {
            StorageTank::create($tank);
        }

        $this->command->info('Storage tanks seeded successfully!');
    }
}

// Made with Bob
