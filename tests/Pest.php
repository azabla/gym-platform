<?php

use App\Domains\Billing\Models\PaymentLink;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/**
 * Act as the given organization (gym) for the rest of the test.
 */
function actingAsOrganization(Organization $organization): Organization
{
    app(CurrentOrganization::class)->set($organization);

    return $organization;
}

/**
 * Sign in to the API as a new staff member of the gym, limited to the given
 * branches if any are passed.
 */
function actingAsStaff(Organization $organization, StaffRole $role, Branch ...$onlyAtBranches): Staff
{
    $staff = Staff::factory()->for($organization)->create(['role' => $role]);

    if ($onlyAtBranches !== []) {
        $staff->assignToBranches(...$onlyAtBranches);
    }

    Sanctum::actingAs($staff->user);

    return $staff;
}

/**
 * What Chapa answers, when asked how the payment link's transaction stands,
 * once the member has paid it in full: with Chapa's reference APfqvRZJ0bEn,
 * and the amount as a number, as in Chapa's docs. The data given replaces
 * parts of the answer.
 *
 * @param  array<string, mixed>  $data
 * @return array<string, mixed>
 */
function chapaPaidAnswer(PaymentLink $paymentLink, array $data = []): array
{
    return [
        'message' => 'Payment details',
        'status' => 'success',
        'data' => [
            'currency' => $paymentLink->amount->currency->value,
            'amount' => (float) $paymentLink->amount->toDecimal(),
            'charge' => 87.5,
            'mode' => 'test',
            'method' => 'telebirr',
            'type' => 'API',
            'status' => 'success',
            'reference' => 'APfqvRZJ0bEn',
            'tx_ref' => $paymentLink->tx_ref,
            ...$data,
        ],
    ];
}

/**
 * Fake Chapa saying the given links were paid in full, and that it knows of
 * no payment for any other transaction.
 */
function fakeChapaPaid(PaymentLink ...$paidLinks): void
{
    Http::fake(['api.chapa.co/v1/transaction/verify/*' => function (Request $request) use ($paidLinks) {
        foreach ($paidLinks as $paidLink) {
            if (str_ends_with($request->url(), "/{$paidLink->tx_ref}")) {
                return Http::response(chapaPaidAnswer($paidLink));
            }
        }

        return Http::response(['message' => 'Invalid transaction or Transaction not found', 'status' => 'failed', 'data' => null], 404);
    }]);
}
