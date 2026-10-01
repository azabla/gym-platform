<?php

namespace Database\Seeders;

use App\Domains\Organizations\Models\Organization;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class OrganizationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::transaction(function (): void {
            $organization = Organization::query()->firstOrCreate(
                ['slug' => 'demo-gym'],
                ['name' => 'Demo Gym'],
            );

            $organization->branches()->firstOrCreate(
                ['code' => 'MAIN'],
                ['name' => 'Main Branch'],
            );
        });
    }
}
