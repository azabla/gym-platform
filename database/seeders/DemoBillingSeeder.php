<?php

namespace Database\Seeders;

use App\Domains\Identity\Models\User;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Domains\PlatformBilling\Enums\GymPaymentMethod;
use App\Domains\PlatformBilling\Models\GymInvoice;
use App\Domains\PlatformBilling\Models\GymSubscription;
use App\Domains\PlatformBilling\Models\PlatformPlan;
use App\Domains\PlatformBilling\SubscriptionTerms;
use App\Domains\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * The platform's bill to a few gyms, one in each state, to look around the
 * admin panel's gyms, invoices and figures and the owner's Platform bill
 * page (ADR 0045):
 *
 * - Demo Gym on Standard, its first invoice paid by bank transfer and the
 *   next one due today, so its owner sees the banner;
 * - Bole Fitness on its free trial;
 * - Piassa Fitness overdue past its grace, so paused for an unpaid bill;
 * - Hawassa Fitness not billed yet.
 *
 * It replays the past by moving the clock, issuing invoices as the hourly
 * run would have. Owners aren't messaged. `make demo` runs it after the
 * demo activity; it seeds the plans first, and adds nothing the second time.
 */
class DemoBillingSeeder extends Seeder
{
    public function run(CurrentOrganization $currentOrganization): void
    {
        $this->call(PlatformPlanSeeder::class);
        $demo = Organization::query()->where('slug', 'demo-gym')->firstOrFail();

        if (GymSubscription::of($demo) !== null) {
            $this->command?->info('The demo billing is already there.');

            return;
        }

        $admin = User::query()->firstOrCreate(
            ['email' => 'billing@platform.test'],
            ['name' => 'Platform Billing', 'phone' => '+251911000090', 'password' => 'password'],
        );
        $now = CarbonImmutable::now();

        Event::fakeFor(fn () => DB::transaction(function () use ($currentOrganization, $demo, $admin, $now): void {
            $piassa = $this->gym($currentOrganization, 'Piassa Fitness', 'piassa-fitness', '+251911000020');
            $bole = $this->gym($currentOrganization, 'Bole Fitness', 'bole-fitness', '+251911000010');
            $this->gym($currentOrganization, 'Hawassa Fitness', 'hawassa-fitness', '+251911000030');

            // Demo Gym joined 40 days ago, paid its first month, and its second is due today.
            $first = $this->at($now->subDays(40), function () use ($demo): GymInvoice {
                GymSubscription::start($demo, new SubscriptionTerms($this->plan('Standard'), tin: '0012345678'), firstBillingDay: $demo->today()->addDays(7));

                return GymInvoice::issueDue($demo)[0];
            });
            $this->at($now->subDays(35), fn () => $first->recordPayment($first->total, GymPaymentMethod::BankTransfer, 'FT26DEMO0001', $demo->today(), $admin));
            $this->at($now, fn (): array => GymInvoice::issueDue($demo));

            // Bole Fitness opened 10 days ago with the usual 30-day trial.
            $this->at($now->subDays(10), fn (): GymSubscription => GymSubscription::start($bole, new SubscriptionTerms($this->plan('Starter')), trialDays: GymSubscription::DEFAULT_TRIAL_DAYS));

            // Piassa Fitness was due 25 days ago, paid only part, and is paused for the rest.
            $unpaid = $this->at($now->subDays(30), function () use ($piassa): GymInvoice {
                GymSubscription::start($piassa, new SubscriptionTerms($this->plan('Premium')), firstBillingDay: $piassa->today()->addDays(5));

                return GymInvoice::issueDue($piassa)[0];
            });
            $this->at($now->subDays(20), fn () => $unpaid->recordPayment(Money::of('1,000'), GymPaymentMethod::Telebirr, 'TBDEMO0002', $piassa->today(), $admin));
            $this->at($now, fn (): ?GymInvoice => GymInvoice::pauseGymIfUnpaid($piassa));
        }));

        $this->command?->info('Billed Demo Gym (due today), Bole Fitness (trial) and Piassa Fitness (paused, unpaid); Hawassa Fitness isn\'t billed.');
    }

    /**
     * A small gym with one branch and an owner, who signs in with "password".
     */
    private function gym(CurrentOrganization $currentOrganization, string $name, string $slug, string $ownerPhone): Organization
    {
        $gym = Organization::query()->firstOrCreate(['slug' => $slug], ['name' => $name]);

        $currentOrganization->run($gym, function () use ($gym, $name, $slug, $ownerPhone): void {
            $gym->branches()->firstOrCreate(['code' => 'MAIN'], ['name' => 'Main Branch']);
            $owner = User::query()->firstOrCreate(
                ['email' => "owner@{$slug}.test"],
                ['name' => "{$name} Owner", 'phone' => $ownerPhone, 'password' => 'password'],
            );
            $gym->staff()->firstOrCreate(['user_id' => $owner->id], ['role' => StaffRole::Owner]);
        });

        return $gym;
    }

    /**
     * Do something as if it were then.
     *
     * @template T
     *
     * @param  callable(): T  $what
     * @return T
     */
    private function at(CarbonImmutable $moment, callable $what): mixed
    {
        CarbonImmutable::setTestNow($moment);

        try {
            return $what();
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    private function plan(string $name): PlatformPlan
    {
        return PlatformPlan::query()->where('name', $name)->firstOrFail();
    }
}
