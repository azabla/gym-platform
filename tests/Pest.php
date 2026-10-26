<?php

use App\Domains\Billing\Models\PaymentLink;
use App\Domains\Identity\Models\User;
use App\Domains\Members\Models\Member;
use App\Domains\Notifications\Push\PushMessage;
use App\Domains\Notifications\Push\PushResult;
use App\Domains\Notifications\Push\PushSender;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Filament\Staff\PanelUser;
use Filament\Facades\Filament;
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
 * Sign in to the staff panel as a new staff member of the gym, limited to the
 * given branches if any are passed, working in that gym. Livewire::test() skips
 * the panel's middleware, so this sets the current gym and staff as it would.
 */
function actingAsStaffInPanel(Organization $organization, StaffRole $role, Branch ...$onlyAtBranches): Staff
{
    $staff = Staff::factory()->for($organization)->create(['role' => $role]);

    if ($onlyAtBranches !== []) {
        $staff->assignToBranches(...$onlyAtBranches);
    }

    test()->actingAs(PanelUser::query()->findOrFail($staff->user_id), 'staff_panel');
    Filament::setCurrentPanel('staff');
    Filament::setTenant($organization);
    app(CurrentOrganization::class)->set($organization);
    app(CurrentStaff::class)->set($staff);

    return $staff;
}

/**
 * Make a request from one of a user's phones: each phone signs in with its
 * own token, as a real one does, not with Sanctum::actingAs().
 */
function fromPhone(string $plainTextToken): TestCase
{
    app('auth')->forgetGuards();

    return test()->withToken($plainTextToken);
}

/**
 * Sign in to the API as the member, as the members app does after an SMS
 * code: as the user with the member's phone number.
 */
function actingAsMember(Member $member): User
{
    $user = User::query()->where('phone', $member->phone)->first()
        ?? User::factory()->withPhone($member->phone)->create();

    Sanctum::actingAs($user);

    return $user;
}

/**
 * Fake Chapa answering a new checkout with the address of its payment page.
 */
function fakeChapaCheckout(string $checkoutUrl = 'https://checkout.chapa.co/checkout/payment/hana123'): void
{
    Http::fake(['api.chapa.co/v1/transaction/initialize' => Http::response([
        'message' => 'Hosted Link',
        'status' => 'success',
        'data' => ['checkout_url' => $checkoutUrl],
    ])]);
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

/**
 * A push sender that records what it was asked to send, and answers for each
 * phone as given: a result, or an exception to throw.
 */
function recordingPushSender(array $answers = []): PushSender
{
    return new class($answers) implements PushSender
    {
        /** @var list<array{string, PushMessage}> */
        public array $sent = [];

        public function __construct(private array $answers) {}

        public function send(string $deviceToken, PushMessage $message): PushResult
        {
            $this->sent[] = [$deviceToken, $message];
            $answer = $this->answers[$deviceToken] ?? PushResult::Sent;

            if ($answer instanceof Throwable) {
                throw $answer;
            }

            return $answer;
        }
    };
}

/**
 * Record pushes instead of writing them to the log, and give the recorder,
 * whose $sent lists each push as [phone token, message].
 */
function fakePushes(): PushSender
{
    $pushSender = recordingPushSender();
    app()->instance(PushSender::class, $pushSender);

    return $pushSender;
}
