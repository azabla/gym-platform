<?php

declare(strict_types=1);

use App\Domains\Organizations\Models\Organization;
use App\Domains\PlatformBilling\Enums\GymBillStatus;
use App\Domains\PlatformBilling\GymBills;
use App\Domains\PlatformBilling\Models\GymInvoice;
use App\Domains\PlatformBilling\Models\PlatformPlan;
use Database\Seeders\DemoBillingSeeder;
use Illuminate\Support\Facades\Notification;

it('bills a gym in each state, without messaging any owner, and adds nothing when run again', function () {
    $this->seed();
    Notification::fake();
    $this->seed(DemoBillingSeeder::class);
    $this->seed(DemoBillingSeeder::class);

    $statuses = [];

    foreach (Organization::query()->orderBy('slug')->get() as $gym) {
        $statuses[$gym->slug] = app(GymBills::class)->statusOf($gym);
    }

    expect($statuses)->toBe([
        'bole-fitness' => GymBillStatus::Trial,
        'demo-gym' => GymBillStatus::Due,
        'hawassa-fitness' => GymBillStatus::NotBilled,
        'piassa-fitness' => GymBillStatus::PausedUnpaid,
    ])
        ->and(GymInvoice::query()->acrossOrganizations()->pluck('number')->sort()->values()->all())->toBe([1, 2, 3])
        ->and(PlatformPlan::query()->offered()->count())->toBe(3);

    Notification::assertNothingSent();
});
