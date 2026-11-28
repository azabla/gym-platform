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
    protected $description = 'Issue the platform\'s invoices to gyms as they come due, and pause gyms that haven\'t paid';

    /**
     * Run every hour, over every billed gym, paused ones included: this is
     * the one scheduled job that must still see them. In each gym's own
     * today it issues each invoice 7 days before its period starts, reminds
     * the owners of an unpaid one, and pauses a gym whose invoice is still
     * unpaid on its pause day. A missed
     * run is caught up on the next. See ADR 0045.
     */
    public function handle(CurrentOrganization $currentOrganization): int
    {
        $issued = 0;
        $paused = 0;
        $billedGymIds = GymSubscription::query()->acrossOrganizations()->pluck('organization_id')->map(fn (mixed $id): int => (int) $id)->all();

        foreach (Organization::query()->whereKey($billedGymIds)->orderBy('id')->get() as $gym) {
            $issued += (int) $currentOrganization->run($gym, fn (): int => count(GymInvoice::issueDue($gym)));
            $currentOrganization->run($gym, fn (): int => GymInvoice::remindOwnersOf($gym));
            $paused += (int) $currentOrganization->run($gym, fn (): bool => GymInvoice::pauseGymIfUnpaid($gym) !== null);
        }

        $this->info("Issued {$issued} ".Str::plural('invoice', $issued).", paused {$paused} ".Str::plural('gym', $paused).' for unpaid bills.');

        return self::SUCCESS;
    }
}
