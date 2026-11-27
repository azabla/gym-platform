<?php

declare(strict_types=1);

use App\Domains\Announcements\Enums\AnnouncementStatus;
use App\Domains\Attendance\Enums\CheckInResult;
use App\Domains\Billing\Enums\ChapaMode;
use App\Domains\Billing\Enums\PaymentLinkStatus;
use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Enums\PaymentType;
use App\Domains\Memberships\Enums\MembershipStatus;
use App\Domains\Organizations\Enums\StaffRole;
use App\Filament\Staff\Support\Badges;
use Tests\TestCase;

// Labels go through __() (ADR 0041), so the app's translator must be running.
uses(TestCase::class);

it('labels and colours every status the panel shows', function (BackedEnum $value, string $label, string $color) {
    expect(Badges::label($value))->toBe($label)
        ->and(Badges::color($value))->toBe($color);
})->with([
    'active membership' => [MembershipStatus::Active, 'Active', 'success'],
    'frozen membership' => [MembershipStatus::Frozen, 'Frozen', 'info'],
    'cancelled membership' => [MembershipStatus::Cancelled, 'Cancelled', 'danger'],
    'waiting link' => [PaymentLinkStatus::Pending, 'Waiting', 'warning'],
    'link needing a refund' => [PaymentLinkStatus::RefundNeeded, 'Refund needed', 'danger'],
    'turned away' => [CheckInResult::Denied, 'Turned away', 'danger'],
    'refund' => [PaymentType::Refund, 'Refund', 'danger'],
    'telebirr, as it writes itself' => [PaymentMethod::Telebirr, 'telebirr', 'info'],
    'owner' => [StaffRole::Owner, 'Owner', 'primary'],
    'scheduled announcement' => [AnnouncementStatus::Scheduled, 'Scheduled', 'warning'],
    'live Chapa key' => [ChapaMode::Live, 'Live', 'success'],
]);

it('gives every case of every enum a colour and filter options', function (string $enum) {
    foreach ($enum::cases() as $case) {
        expect(Badges::label($case))->not->toBe('')
            ->and(Badges::color($case))->toBeIn(['success', 'warning', 'danger', 'info', 'primary', 'gray']);
    }

    expect(Badges::options($enum))->toHaveCount(count($enum::cases()));
})->with([
    MembershipStatus::class, PaymentLinkStatus::class, CheckInResult::class, PaymentType::class,
    PaymentMethod::class, StaffRole::class, AnnouncementStatus::class, ChapaMode::class,
]);
