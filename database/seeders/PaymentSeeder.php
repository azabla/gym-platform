<?php

namespace Database\Seeders;

use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Models\Payment;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Domains\Shared\Money\Money;
use App\Domains\Shared\PhoneNumber\PhoneNumber;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PaymentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Tigist paid 1,500 of her membership by Telebirr, so she still owes 1,000,
     * and Abebe paid for his in full, in cash. Both paid at Main Branch on the
     * day their membership started, so the receipt numbers follow the dates.
     * Memberships that already have a payment are skipped.
     */
    public function run(CurrentOrganization $currentOrganization): void
    {
        $organization = Organization::query()->where('slug', 'demo-gym')->firstOrFail();

        DB::transaction(function () use ($currentOrganization, $organization): void {
            $currentOrganization->run($organization, function () use ($organization): void {
                $mainBranch = $organization->branches()->where('code', 'MAIN')->firstOrFail();
                $receptionist = $organization->staff()->whereRelation('user', 'email', 'frontdesk@demo-gym.test')->firstOrFail();

                $demoPayments = [
                    ['0922 34 56 78', Money::of('1,500'), PaymentMethod::Telebirr, 'CJ12ABC34D'],
                    ['0911 23 45 67', null, PaymentMethod::Cash, null],
                ];

                foreach ($demoPayments as [$phone, $amount, $method, $reference]) {
                    $member = Member::query()->where('phone', PhoneNumber::of($phone)->e164)->firstOrFail();
                    $membership = Membership::query()->whereBelongsTo($member)->orderBy('starts_on')->firstOrFail();

                    if (Payment::query()->whereBelongsTo($membership)->exists()) {
                        continue;
                    }

                    Payment::receive(
                        membership: $membership,
                        amount: $amount ?? $membership->price,
                        method: $method,
                        reference: $reference,
                        branch: $mainBranch,
                        paidOn: $membership->starts_on,
                        receivedBy: $receptionist,
                    );
                }
            });
        });
    }
}
