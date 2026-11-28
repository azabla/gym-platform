<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Organization;
use App\Domains\PlatformBilling\Models\PlatformPlan;
use App\Filament\Admin\AdminUser;
use App\Filament\Admin\Resources\PlatformPlans\Pages\CreatePlatformPlan;
use App\Filament\Admin\Resources\PlatformPlans\Pages\EditPlatformPlan;
use App\Filament\Admin\Resources\PlatformPlans\Pages\ListPlatformPlans;
use App\Filament\Admin\Resources\PlatformPlans\PlatformPlanResource;
use Filament\Facades\Filament;
use Livewire\Livewire;

function actingAsPlatformAdminInPanel(): User
{
    $admin = User::factory()->create(['name' => 'Selam Tadesse']);
    $admin->makePlatformAdmin();
    test()->actingAs(AdminUser::query()->findOrFail($admin->id), 'admin_panel');
    Filament::setCurrentPanel('admin');

    return $admin;
}

it('adds a plan, showing what a gym pays as it is filled in', function () {
    actingAsPlatformAdminInPanel();

    Livewire::test(CreatePlatformPlan::class)
        ->fillForm([
            'name' => 'Standard',
            'monthly_price' => '2,000',
            'extra_branch_price' => '500',
            'discount_3_months' => 0,
            'discount_6_months' => 5,
            'discount_12_months' => 10,
        ])
        ->assertSee('1 branch, 1 month: 2,000.00 ETB')
        ->assertSee('3 branches, 12 months: 32,400.00 ETB')
        ->call('create')
        ->assertHasNoFormErrors();

    $plan = PlatformPlan::query()->sole();

    expect($plan->name)->toBe('Standard')
        ->and($plan->monthly_price->toDecimal())->toBe('2000.00')
        ->and($plan->extra_branch_price->toDecimal())->toBe('500.00')
        ->and($plan->discountFor(12))->toBe(10)
        ->and($plan->discountFor(1))->toBe(0);
});

it('refuses a name another plan has, a discount over 50% and a price that isn\'t money', function () {
    actingAsPlatformAdminInPanel();
    PlatformPlan::factory()->create(['name' => 'Standard']);

    Livewire::test(CreatePlatformPlan::class)
        ->fillForm(['name' => 'standard', 'monthly_price' => 'two thousand', 'extra_branch_price' => '0', 'discount_12_months' => 60])
        ->call('create')
        ->assertHasFormErrors(['name', 'monthly_price', 'discount_12_months']);

    expect(PlatformPlan::query()->count())->toBe(1);
});

it('changes a plan\'s prices', function () {
    actingAsPlatformAdminInPanel();
    $plan = PlatformPlan::factory()->create(['name' => 'Standard']);

    Livewire::test(EditPlatformPlan::class, ['record' => $plan->getRouteKey()])
        ->assertSchemaStateSet(['monthly_price' => '2000.00'])
        ->fillForm(['monthly_price' => '2,500'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($plan->refresh()->monthly_price->toDecimal())->toBe('2500.00');
});

it('retires a plan and offers it again, and filters by status', function () {
    actingAsPlatformAdminInPanel();
    $standard = PlatformPlan::factory()->create(['name' => 'Standard']);
    $old = PlatformPlan::factory()->retired()->create(['name' => 'Old']);

    Livewire::test(ListPlatformPlans::class)
        ->callTableAction('retire', $standard)
        ->assertNotified('Plan retired.')
        ->callTableAction('offerAgain', $old)
        ->assertNotified('Plan offered again.')
        ->filterTable('status', 'retired')
        ->assertCanSeeTableRecords([$standard])
        ->assertCanNotSeeTableRecords([$old]);

    expect(PlatformPlan::query()->offered()->pluck('name')->all())->toBe(['Old']);
});

it('is for platform admins only', function () {
    $gym = Organization::factory()->create();
    actingAsStaffInPanel($gym, StaffRole::Owner);

    $this->get(PlatformPlanResource::getUrl('index', panel: 'admin'))->assertRedirect();
});
