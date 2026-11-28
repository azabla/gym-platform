<?php

declare(strict_types=1);

use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\PlatformBilling\Models\GymSubscription;
use App\Domains\PlatformBilling\Models\PlatformPlan;
use App\Domains\PlatformBilling\SubscriptionTerms;
use App\Filament\Admin\Resources\Gyms\Pages\CreateGym;
use App\Filament\Admin\Resources\Gyms\Pages\ListGyms;
use App\Filament\Admin\Resources\Gyms\Pages\ViewGym;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-28 10:00', 'Africa/Addis_Ababa'));
    actingAsPlatformAdminInPanel();
    $this->gym = Organization::factory()->create(['name' => 'Demo Gym', 'timezone' => 'Africa/Addis_Ababa']);
    Branch::factory()->for($this->gym)->count(2)->create();
    $this->plan = PlatformPlan::factory()->create(['name' => 'Standard']);
});

it('sets up billing for an existing gym from a chosen day, showing its next invoice', function () {
    Livewire::test(ViewGym::class, ['record' => $this->gym->getRouteKey()])
        ->assertSee('Not billed')
        ->assertActionHidden('changeBilling')
        ->callAction('setUpBilling', [
            'plan' => $this->plan->getKey(),
            'months_per_invoice' => 1,
            'grace_days' => 14,
            'start' => 'date',
            'first_billing_day' => '2026-12-01',
        ])
        ->assertHasNoActionErrors()
        ->assertNotified('Billing set up.');

    $subscription = GymSubscription::of($this->gym);

    expect($subscription?->next_period_starts_on->toDateString())->toBe('2026-12-01')
        ->and($subscription?->trial_ends_on)->toBeNull();

    Livewire::test(ViewGym::class, ['record' => $this->gym->getRouteKey()])
        ->assertSee('Paid up')
        ->assertSee('For the period from 1 Dec 2026: 2,500.00 ETB for 2 branches, 1 month')
        ->assertActionHidden('setUpBilling')
        ->assertActionVisible('changeBilling');
});

it('changes the plan with negotiated prices and a TIN, refusing one that isn\'t 10 digits', function () {
    GymSubscription::start($this->gym, new SubscriptionTerms($this->plan));

    Livewire::test(ViewGym::class, ['record' => $this->gym->getRouteKey()])
        ->callAction('changeBilling', ['tin' => '123'])
        ->assertHasActionErrors(['tin' => 'regex']);

    Livewire::test(ViewGym::class, ['record' => $this->gym->getRouteKey()])
        ->callAction('changeBilling', ['months_per_invoice' => 12, 'monthly_price_override' => '1,800', 'tin' => '0012345678'])
        ->assertHasNoActionErrors()
        ->assertNotified('Billing changed. It applies from the next invoice.');

    $subscription = GymSubscription::of($this->gym);

    expect($subscription?->months_per_invoice)->toBe(12)
        ->and($subscription?->monthlyPrice()->toDecimal())->toBe('1800.00')
        ->and($subscription?->tin)->toBe('0012345678');
});

it('extends a trial', function () {
    GymSubscription::start($this->gym, new SubscriptionTerms($this->plan), trialDays: 30);

    Livewire::test(ViewGym::class, ['record' => $this->gym->getRouteKey()])
        ->assertSee('Trial')
        ->callAction('extendTrial', ['until' => '2027-01-31'])
        ->assertNotified('Trial extended.');

    expect(GymSubscription::of($this->gym)?->next_period_starts_on->toDateString())->toBe('2027-02-01');
});

it('opens a gym on a plan with the 30-day trial by default, or unbilled', function () {
    $form = [
        'timezone' => 'Africa/Addis_Ababa',
        'message_language' => 'en',
        'branch' => 'Main Branch',
        'owner_name' => 'Meron Assefa',
    ];

    Livewire::test(CreateGym::class)
        ->fillForm([...$form, 'name' => 'Bole Fitness', 'slug' => 'bole-fitness', 'owner_phone' => '0911223344', 'billing_plan' => $this->plan->getKey()])
        ->assertSchemaStateSet(['trial_days' => 30, 'months_per_invoice' => 1])
        ->call('create')
        ->assertHasNoFormErrors();

    Livewire::test(CreateGym::class)
        ->fillForm([...$form, 'name' => 'Piassa Gym', 'slug' => 'piassa-gym', 'owner_phone' => '0911556677'])
        ->call('create')
        ->assertHasNoFormErrors();

    $bole = Organization::query()->where('slug', 'bole-fitness')->sole();
    $piassa = Organization::query()->where('slug', 'piassa-gym')->sole();

    expect(GymSubscription::of($bole)?->trial_ends_on?->toDateString())->toBe('2026-12-27')
        ->and(GymSubscription::of($piassa))->toBeNull();
});

it('shows and filters each gym\'s bill in the list', function () {
    $onTrial = Organization::factory()->create(['name' => 'Trial Gym']);
    GymSubscription::start($onTrial, new SubscriptionTerms($this->plan), trialDays: 30);
    $billed = Organization::factory()->create(['name' => 'Billed Gym']);
    GymSubscription::start($billed, new SubscriptionTerms($this->plan));

    Livewire::test(ListGyms::class)
        ->assertSee(['Not billed', 'Trial', 'Paid up'])
        ->filterTable('bill', 'trial')
        ->assertCanSeeTableRecords([$onTrial])
        ->assertCanNotSeeTableRecords([$this->gym, $billed])
        ->filterTable('bill', 'not_billed')
        ->assertCanSeeTableRecords([$this->gym])
        ->assertCanNotSeeTableRecords([$onTrial, $billed])
        ->filterTable('bill', 'paid_up')
        ->assertCanSeeTableRecords([$billed])
        ->assertCanNotSeeTableRecords([$this->gym, $onTrial]);
});
