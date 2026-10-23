<?php

namespace Database\Seeders;

use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Domains\Shared\PhoneNumber\PhoneNumber;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MembershipSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Abebe gets a Monthly membership that is active today and Tigist one that
     * has expired. Hana has none, so the members list shows every case.
     * Members who already have a membership are skipped.
     */
    public function run(CurrentOrganization $currentOrganization): void
    {
        $organization = Organization::query()->where('slug', 'demo-gym')->firstOrFail();

        DB::transaction(function () use ($currentOrganization, $organization): void {
            $currentOrganization->run($organization, function () use ($organization): void {
                $today = $organization->today();
                $monthly = Plan::query()->where('name', 'Monthly')->firstOrFail();
                $owner = $organization->staff()->whereRelation('user', 'email', 'owner@demo-gym.test')->firstOrFail();

                $demoMemberships = [
                    ['0911 23 45 67', $today->subDays(10)],
                    ['0922 34 56 78', $today->subMonths(2)],
                ];

                foreach ($demoMemberships as [$phone, $startsOn]) {
                    $member = Member::query()->where('phone', PhoneNumber::of($phone)->e164)->firstOrFail();

                    if (Membership::query()->whereBelongsTo($member)->exists()) {
                        continue;
                    }

                    Membership::sell($member, $monthly, $startsOn, $owner);
                }
            });
        });
    }
}
