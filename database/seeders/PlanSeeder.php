<?php

namespace Database\Seeders;

use App\Domains\Memberships\Enums\DurationUnit;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Domains\Shared\Money\Money;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Four plans valid at every branch, and Bole Monthly, valid only at Bole Branch.
     */
    public function run(CurrentOrganization $currentOrganization): void
    {
        $organization = Organization::query()->where('slug', 'demo-gym')->firstOrFail();

        DB::transaction(function () use ($currentOrganization, $organization): void {
            $currentOrganization->run($organization, function () use ($organization): void {
                $boleBranch = $organization->branches()->where('code', 'BOLE')->firstOrFail();

                $demoPlans = [
                    ['Day Pass', '200', 1, DurationUnit::Day, null],
                    ['Monthly', '2,500', 1, DurationUnit::Month, null],
                    ['3 Months', '6,500', 3, DurationUnit::Month, null],
                    ['Yearly', '24,000', 12, DurationUnit::Month, null],
                    ['Bole Monthly', '2,000', 1, DurationUnit::Month, $boleBranch],
                ];

                foreach ($demoPlans as [$name, $price, $durationCount, $durationUnit, $onlyAtBranch]) {
                    $plan = Plan::query()->firstOrNew(['name' => $name]);
                    $plan->fill([
                        'price' => Money::of($price),
                        'duration_count' => $durationCount,
                        'duration_unit' => $durationUnit,
                    ]);
                    $plan->organization()->associate($organization);
                    $plan->save();

                    if ($onlyAtBranch !== null) {
                        $plan->makeAvailableOnlyAt($onlyAtBranch);
                    }
                }
            });
        });
    }
}
