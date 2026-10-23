<?php

namespace Database\Seeders;

use App\Domains\Identity\Models\User;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class OrganizationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(CurrentOrganization $currentOrganization): void
    {
        DB::transaction(function () use ($currentOrganization): void {
            $organization = Organization::query()->firstOrCreate(
                ['slug' => 'demo-gym'],
                ['name' => 'Demo Gym'],
            );

            $currentOrganization->run($organization, function () use ($organization): void {
                $organization->branches()->firstOrCreate(
                    ['code' => 'MAIN'],
                    ['name' => 'Main Branch'],
                );

                $owner = User::query()->firstOrCreate(
                    ['email' => 'owner@demo-gym.test'],
                    ['name' => 'Demo Owner', 'password' => 'password'],
                );

                $organization->staff()->firstOrCreate(
                    ['user_id' => $owner->id],
                    ['role' => StaffRole::Owner],
                );
            });
        });
    }
}
