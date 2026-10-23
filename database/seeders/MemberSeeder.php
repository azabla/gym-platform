<?php

namespace Database\Seeders;

use App\Domains\Members\Enums\Gender;
use App\Domains\Members\Models\Member;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Domains\Shared\PhoneNumber\PhoneNumber;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MemberSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(CurrentOrganization $currentOrganization): void
    {
        $organization = Organization::query()->where('slug', 'demo-gym')->firstOrFail();

        DB::transaction(function () use ($currentOrganization, $organization): void {
            $currentOrganization->run($organization, function () use ($organization): void {
                $mainBranch = $organization->branches()->where('code', 'MAIN')->firstOrFail();
                $boleBranch = $organization->branches()->where('code', 'BOLE')->firstOrFail();

                $demoMembers = [
                    ['Abebe Kebede', '0911 23 45 67', Gender::Male, $mainBranch],
                    ['Tigist Haile', '0922 34 56 78', Gender::Female, $mainBranch],
                    ['Hana Girma', '0933 45 67 89', Gender::Female, $boleBranch],
                ];

                foreach ($demoMembers as [$fullName, $phone, $gender, $homeBranch]) {
                    $member = Member::query()->firstOrNew(['phone' => PhoneNumber::of($phone)->e164]);
                    $member->fill(['full_name' => $fullName, 'gender' => $gender]);
                    $member->organization()->associate($organization);
                    $member->homeBranch()->associate($homeBranch);
                    $member->save();
                }
            });
        });
    }
}
