<?php

namespace App\Console\Commands;

use App\Domains\Billing\Chapa\Exceptions\ChapaRequestFailed;
use App\Domains\Billing\Models\ChapaAccount;
use App\Domains\Billing\Models\PaymentLink;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CheckPaymentLinks extends Command
{
    /**
     * Open links older than this are no longer checked on a schedule; staff
     * can still check them by hand.
     */
    private const DAYS_OF_LINKS_TO_CHECK = 7;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'billing:check-payment-links';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Ask Chapa about the open payment links of the last 7 days, and record the ones that were paid';

    /**
     * Execute the console command. Chapa's own call after a payment may not
     * arrive, so the open links are checked one by one, each acting as its
     * gym and asking with that gym's own key. See ADR 0009.
     */
    public function handle(CurrentOrganization $currentOrganization): int
    {
        $paymentLinks = PaymentLink::query()
            ->acrossOrganizations()
            ->pending()
            ->where('created_at', '>=', CarbonImmutable::now()->subDays(self::DAYS_OF_LINKS_TO_CHECK))
            ->with('organization')
            ->orderBy('id')
            ->get();

        $checked = 0;

        foreach ($paymentLinks as $paymentLink) {
            if ($currentOrganization->run($paymentLink->organization, fn (): bool => $this->checkWithChapa($paymentLink))) {
                $checked++;
            }
        }

        $this->info("Checked {$checked} open payment ".Str::plural('link', $checked).' with Chapa.');

        return self::SUCCESS;
    }

    /**
     * Ask Chapa about the link with its gym's own key, and give whether that
     * worked. A gym without a Chapa account connected can't be asked, and a
     * link Chapa didn't answer about is tried again on the next run.
     */
    private function checkWithChapa(PaymentLink $paymentLink): bool
    {
        $chapaAccount = ChapaAccount::query()->first();

        if ($chapaAccount === null) {
            return false;
        }

        try {
            $paymentLink->check($chapaAccount);
        } catch (ChapaRequestFailed $exception) {
            $this->warn("Payment link {$paymentLink->id}: {$exception->getMessage()}");

            return false;
        }

        return true;
    }
}
