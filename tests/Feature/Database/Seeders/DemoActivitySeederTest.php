<?php

declare(strict_types=1);

use App\Domains\Attendance\Models\CheckIn;
use App\Domains\Billing\Enums\PaymentLinkStatus;
use App\Domains\Billing\Models\PaymentLink;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;
use Database\Seeders\DemoActivitySeeder;
use Illuminate\Support\Facades\Notification;

it('adds two months of activity to Demo Gym, without telling any member', function () {
    Notification::fake();
    $this->seed();
    $this->seed(DemoActivitySeeder::class);

    actingAsOrganization(Organization::query()->where('slug', 'demo-gym')->sole());

    expect(Member::query()->count())->toBe(43)
        ->and(Membership::query()->whereNotNull('cancelled_at')->count())->toBe(2)
        ->and(CheckIn::query()->count())->toBeGreaterThan(50)
        ->and(PaymentLink::query()->pluck('status')->unique()->sort()->values()->all())
        ->toEqualCanonicalizing([PaymentLinkStatus::Pending, PaymentLinkStatus::Paid, PaymentLinkStatus::RefundNeeded, PaymentLinkStatus::Refunded])
        ->and(Staff::query()->where('role', StaffRole::Trainer)->count())->toBe(1);

    Notification::assertNothingSentTo(Member::query()->where('phone', 'like', '+25194400%')->get());
});

it('fills the staff inbox, and adds nothing when run again', function () {
    $this->seed();
    $this->seed(DemoActivitySeeder::class);
    $this->seed(DemoActivitySeeder::class);

    actingAsOrganization(Organization::query()->where('slug', 'demo-gym')->sole());

    expect(Member::query()->count())->toBe(43)
        ->and(Staff::query()->where('role', StaffRole::Owner)->sole()->notifications()->count())->toBeGreaterThan(2);
});
