<?php

namespace Database\Seeders;

use App\Domains\Billing\Models\CashCount;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Domains\Shared\Money\Money;
use Illuminate\Database\Seeder;

/**
 * Cash counts at Demo Gym (ADR 0046): the receptionist closes their cash at
 * Main 50 ETB short, with a note, for the owner to check; the owner's own
 * cash is left to count, so the dashboard reminds them. The demo activity
 * already filled the money log. Run it after DemoActivitySeeder, as
 * `make demo` does; it adds nothing the second time.
 */
class DemoCashCountSeeder extends Seeder
{
    public function run(CurrentOrganization $currentOrganization): void
    {
        $gym = Organization::query()->where('slug', 'demo-gym')->firstOrFail();

        $currentOrganization->run($gym, function () use ($gym): void {
            if (CashCount::query()->exists()) {
                $this->command?->info('The demo cash counts are already there.');

                return;
            }

            $receptionist = $gym->staff()->where('role', StaffRole::Receptionist)->firstOrFail();
            $main = $gym->branches()->where('code', 'MAIN')->firstOrFail();
            $expected = CashCount::expectedFor($receptionist, $main);

            if (! $expected->isPositive()) {
                $this->command?->info('The receptionist has no cash to count; run DemoActivitySeeder first.');

                return;
            }

            CashCount::close($receptionist, $main, $expected->subtract(Money::of('50')), 'Gave 50 ETB change twice to one member.');
            $this->command?->info("Closed the receptionist's cash at Main, 50 ETB short of {$expected->format()}.");
        });
    }
}
