<?php

declare(strict_types=1);

use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Models\Payment;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Shared\Money\Money;
use Database\Seeders\MemberSeeder;
use Database\Seeders\MembershipSeeder;
use Database\Seeders\OrganizationSeeder;
use Database\Seeders\PaymentSeeder;
use Database\Seeders\PlanSeeder;

it('creates the demo payments', function () {
    $this->seed([OrganizationSeeder::class, MemberSeeder::class, PlanSeeder::class, MembershipSeeder::class, PaymentSeeder::class]);

    actingAsOrganization(Organization::query()->where('slug', 'demo-gym')->sole());

    $payments = Payment::query()->with('membership.member', 'branch')->orderBy('receipt_number')->get();
    $balancesDue = Payment::balancesDueOf(Member::query()->pluck('id')->all());
    $tigist = Member::query()->where('full_name', 'Tigist Haile')->sole();
    $abebe = Member::query()->where('full_name', 'Abebe Kebede')->sole();

    expect($payments->map(fn (Payment $payment) => [
        $payment->receipt_number,
        $payment->membership->member->full_name,
        $payment->method,
        $payment->reference,
        $payment->branch->code,
    ])->all())->toBe([
        [1, 'Tigist Haile', PaymentMethod::Telebirr, 'CJ12ABC34D', 'MAIN'],
        [2, 'Abebe Kebede', PaymentMethod::Cash, null, 'MAIN'],
    ])
        ->and($payments->first()->amount)->toEqual(Money::of('1,500'))
        ->and($payments->first()->paid_on)->toEqual(Membership::query()->whereBelongsTo($tigist)->sole()->starts_on)
        ->and($balancesDue[$tigist->id])->toEqual(Money::of('1,000'))
        ->and($balancesDue[$abebe->id])->toEqual(Money::zero());
});

it('can run more than once without creating duplicates', function () {
    $this->seed([OrganizationSeeder::class, MemberSeeder::class, PlanSeeder::class, MembershipSeeder::class, PaymentSeeder::class]);
    $this->seed(PaymentSeeder::class);

    expect(Payment::query()->acrossOrganizations()->count())->toBe(2);
});

it('runs as part of the database seeder', function () {
    $this->seed();

    expect(Payment::query()->acrossOrganizations()->count())->toBe(2);
});
