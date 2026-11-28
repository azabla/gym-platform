<?php

namespace App\Console\Commands;

use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Domains\PlatformBilling\Models\GymInvoice;
use App\Domains\PlatformBilling\Models\GymSubscription;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class RunPlatformBilling extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'platform-billing:run';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Issue the platform\'s invoices to gyms as they come due';

    /**
     * Run every hour, over every billed gym, paused ones included: this is
     * the one scheduled job that must still see them. In each gym's own
     * today it issues each invoice 7 days before its period starts. A missed
     * run is caught up on the next. See ADR 0045.
     */
    public function handle(CurrentOrganization $currentOrganization): int
    {
        $issued = 0;
        $billedGymIds = GymSubscription::query()->acrossOrganizations()->pluck('organization_id')->map(fn (mixed $id): int => (int) $id)->all();

        foreach (Organization::query()->whereKey($billedGymIds)->orderBy('id')->get() as $gym) {
            $issued += (int) $currentOrganization->run($gym, fn (): int => count(GymInvoice::issueDue($gym)));
        }

        $this->info("Issued {$issued} ".Str::plural('invoice', $issued).'.');

        return self::SUCCESS;
    }
}
