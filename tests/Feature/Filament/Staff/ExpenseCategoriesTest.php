<?php

declare(strict_types=1);

use App\Domains\Billing\Enums\DefaultExpenseCategory;
use App\Domains\Billing\Models\ExpenseCategory;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Onboarding\NewGym;
use App\Domains\Shared\Language\Language;
use App\Domains\Shared\PhoneNumber\PhoneNumber;
use App\Filament\Staff\Resources\ExpenseCategories\ExpenseCategoryResource;
use App\Filament\Staff\Resources\ExpenseCategories\Pages\ListExpenseCategories;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    Notification::fake();
});

it('starts a newly opened gym with the default categories', function () {
    $owner = Organization::open(new NewGym('Bole Fitness', 'bole-fitness', 'Africa/Addis_Ababa', Language::English, 'Main Branch', 'Meron', PhoneNumber::of('0911223344')));

    $keys = ExpenseCategory::query()->acrossOrganizations()->where('organization_id', $owner->organization_id)->pluck('default_key')->all();

    expect($keys)->toEqualCanonicalizing(DefaultExpenseCategory::cases());
});

it('lets owners add, rename and archive categories, showing defaults in the reader\'s language', function () {
    $gym = Organization::factory()->create();
    ExpenseCategory::startListFor($gym);
    actingAsStaffInPanel($gym, StaffRole::Owner);
    $rent = ExpenseCategory::query()->where('default_key', DefaultExpenseCategory::Rent)->sole();

    Livewire::test(ListExpenseCategories::class)
        ->assertSee(['Rent', 'Electricity, water and internet', 'Starting list'])
        ->callAction('create', ['name' => 'rent'])
        ->assertHasActionErrors(['name']);

    Livewire::test(ListExpenseCategories::class)
        ->callAction('create', ['name' => 'Water for members'])
        ->assertHasNoActionErrors()
        ->callTableAction('rename', $rent, ['name' => 'Rent for the hall'])
        ->assertNotified('Category renamed.')
        ->callTableAction('archive', $rent)
        ->assertNotified('Category archived.');

    expect($rent->refresh()->name)->toBe('Rent for the hall')
        ->and($rent->trashed())->toBeTrue()
        ->and(ExpenseCategory::query()->where('name', 'Water for members')->exists())->toBeTrue();

    app()->setLocale('am');
    expect(ExpenseCategory::query()->where('default_key', DefaultExpenseCategory::Salaries)->sole()->displayName())->toBe('ደመወዝ');
    app()->setLocale('en');
});

it('lets managers see the categories but not change them, and keeps them from receptionists', function () {
    $gym = Organization::factory()->create();
    ExpenseCategory::startListFor($gym);

    actingAsStaffInPanel($gym, StaffRole::Manager);
    Livewire::test(ListExpenseCategories::class)
        ->assertActionHidden('create')
        ->assertTableActionHidden('rename', ExpenseCategory::query()->first());

    actingAsStaffInPanel($gym, StaffRole::Receptionist);
    $this->get(ExpenseCategoryResource::getUrl('index', tenant: $gym))->assertForbidden();
});
