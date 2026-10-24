<?php

declare(strict_types=1);

use App\Domains\Billing\Enums\PaymentLinkStatus;
use App\Domains\Billing\Models\PaymentLink;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Shared\Money\Money;
use Illuminate\Database\QueryException;

it('refuses a second open link for a membership, but keeps cancelled ones', function () {
    $membership = Membership::factory()->create();
    PaymentLink::factory()->for($membership)->cancelled()->create();
    PaymentLink::factory()->for($membership)->create();

    PaymentLink::factory()->for($membership)->create();
})->throws(QueryException::class, 'violates unique constraint');

it('refuses a zero amount, half a cancellation, or a paid link without its payment', function (array $attributes) {
    PaymentLink::factory()->create($attributes);
})->with([
    'a zero amount' => [['amount' => Money::zero()]],
    'a cancellation without who cancelled it' => [['cancelled_at' => '2026-10-23 10:00:00']],
    'a paid link without its payment' => [['status' => PaymentLinkStatus::Paid]],
])->throws(QueryException::class, 'violates check constraint');

it('refuses a membership of another gym', function () {
    $otherGymsMembership = Membership::factory()->create();

    PaymentLink::factory()->create([
        'organization_id' => Membership::factory()->create()->organization_id,
        'membership_id' => $otherGymsMembership->id,
    ]);
})->throws(QueryException::class, 'violates foreign key constraint');
