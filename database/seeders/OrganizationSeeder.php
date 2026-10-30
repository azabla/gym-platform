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
                $mainBranch = $organization->branches()->firstOrCreate(
                    ['code' => 'MAIN'],
                    ['name' => 'Main Branch'],
                );
                $mainBranch->replaceOpeningHours(array_map(
                    fn (int $weekday): array => ['weekday' => $weekday, 'opens_at' => '06:00:00', 'closes_at' => '21:00:00'],
                    range(1, 6),
                ));

                $organization->branches()->firstOrCreate(
                    ['code' => 'BOLE'],
                    ['name' => 'Bole Branch'],
                );

                $owner = User::query()->firstOrCreate(
                    ['email' => 'owner@demo-gym.test'],
                    ['name' => 'Demo Owner', 'phone' => '+251911000001', 'password' => 'password'],
                );

                $organization->staff()->firstOrCreate(
                    ['user_id' => $owner->id],
                    ['role' => StaffRole::Owner],
                );

                $receptionist = User::query()->firstOrCreate(
                    ['email' => 'frontdesk@demo-gym.test'],
                    ['name' => 'Demo Receptionist', 'phone' => '+251911000002', 'password' => 'password'],
                );

                $organization->staff()
                    ->firstOrCreate(['user_id' => $receptionist->id], ['role' => StaffRole::Receptionist])
                    ->assignToBranches($mainBranch);

                $accountant = User::query()->firstOrCreate(
                    ['email' => 'accounts@demo-gym.test'],
                    ['name' => 'Demo Accountant', 'phone' => '+251911000003', 'password' => 'password'],
                );

                $organization->staff()->firstOrCreate(
                    ['user_id' => $accountant->id],
                    ['role' => StaffRole::Accountant],
                );
            });
        });
    }
}
