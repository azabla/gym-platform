<?php

declare(strict_types=1);

use App\Domains\Activity\Models\ActivityRecord;
use App\Domains\Billing\Models\CashCount;
use App\Domains\Organizations\Models\Organization;
use Database\Seeders\DemoActivitySeeder;
use Database\Seeders\DemoCashCountSeeder;
use Illuminate\Support\Facades\Notification;

it('closes the receptionist\'s cash 50 short for the owner to check, with the money log filled, and adds nothing when run again', function () {
    Notification::fake();
    $this->seed();
    $this->seed(DemoActivitySeeder::class);
    $this->seed(DemoCashCountSeeder::class);
    $this->seed(DemoCashCountSeeder::class);

    actingAsOrganization(Organization::query()->where('slug', 'demo-gym')->sole());
    $count = CashCount::query()->sole();

    expect($count->difference()->toDecimal())->toBe('-50.00')
        ->and($count->checked_at)->toBeNull()
        ->and(ActivityRecord::query()->where('action', 'payment_received')->count())->toBeGreaterThan(10)
        ->and(ActivityRecord::query()->where('flagged', true)->count())->toBeGreaterThan(0);
});
