<?php

namespace Database\Seeders;

use App\Domains\PlatformBilling\Models\PlatformPlan;
use App\Domains\Shared\Money\Money;
use Illuminate\Database\Seeder;

/**
 * The plans the platform charges gyms on (ADR 0045): three on offer and an
 * old one retired, so the admin panel's Plans list has something in each
 * state. Running it again changes nothing.
 */
class PlatformPlanSeeder extends Seeder
{
    /**
     * [name, a month, each extra branch, % off 3 / 6 / 12 months, retired]
     *
     * @var list<array{0: string, 1: string, 2: string, 3: int, 4: int, 5: int, 6: bool}>
     */
    private const PLANS = [
        ['Starter', '1,500', '0', 0, 0, 10, false],
        ['Standard', '2,000', '500', 5, 10, 15, false],
        ['Premium', '3,500', '750', 5, 10, 20, false],
        ['Founding partner', '1,000', '0', 0, 0, 0, true],
    ];

    public function run(): void
    {
        foreach (self::PLANS as [$name, $monthly, $extraBranch, $three, $six, $twelve, $retired]) {
            $plan = PlatformPlan::query()->firstOrCreate(['name' => $name], [
                'monthly_price' => Money::of($monthly),
                'extra_branch_price' => Money::of($extraBranch),
                'discount_3_months' => $three,
                'discount_6_months' => $six,
                'discount_12_months' => $twelve,
            ]);

            if ($retired && ! $plan->isRetired()) {
                $plan->retire();
            }
        }
    }
}
