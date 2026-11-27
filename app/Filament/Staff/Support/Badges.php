<?php

declare(strict_types=1);

namespace App\Filament\Staff\Support;

use App\Domains\Announcements\Enums\AnnouncementStatus;
use App\Domains\Attendance\Enums\CheckInMethod;
use App\Domains\Attendance\Enums\CheckInResult;
use App\Domains\Billing\Enums\ChapaMode;
use App\Domains\Billing\Enums\PaymentLinkStatus;
use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Enums\PaymentType;
use App\Domains\Memberships\Enums\MembershipStatus;
use App\Domains\Organizations\Enums\StaffRole;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;

/**
 * How the panel shows the domains' statuses, methods and roles: one label,
 * colour and icon each, used by every badge column, entry and filter, so a
 * status looks the same everywhere (ADR 0033). It lives in the panel because
 * the domains never depend on Filament.
 */
final class Badges
{
    public static function label(?BackedEnum $value): string
    {
        return $value === null ? '' : __(self::englishLabel($value));
    }

    /**
     * The label in English, the key of its translation in lang/am.json.
     */
    private static function englishLabel(BackedEnum $value): string
    {
        return match (true) {
            $value instanceof PaymentLinkStatus => match ($value) {
                PaymentLinkStatus::Pending => 'Waiting',
                PaymentLinkStatus::Paid => 'Paid',
                PaymentLinkStatus::Cancelled => 'Cancelled',
                PaymentLinkStatus::RefundNeeded => 'Refund needed',
                PaymentLinkStatus::Refunded => 'Refunded',
            },
            $value instanceof CheckInResult => $value === CheckInResult::Allowed ? 'Let in' : 'Turned away',
            $value instanceof PaymentMethod => $value->label(),
            $value instanceof CheckInMethod => $value->label(),
            $value instanceof StaffRole => Str::headline($value->value),
            default => Str::ucfirst(str_replace('_', ' ', (string) $value->value)),
        };
    }

    /**
     * A Filament colour: success, warning, danger, info, primary or gray.
     */
    public static function color(?BackedEnum $value): string
    {
        return match (true) {
            $value instanceof MembershipStatus => match ($value) {
                MembershipStatus::Active => 'success',
                MembershipStatus::Frozen => 'info',
                MembershipStatus::Upcoming => 'warning',
                MembershipStatus::Expired => 'gray',
                MembershipStatus::Cancelled => 'danger',
            },
            $value instanceof PaymentLinkStatus => match ($value) {
                PaymentLinkStatus::Pending => 'warning',
                PaymentLinkStatus::Paid => 'success',
                PaymentLinkStatus::Cancelled, PaymentLinkStatus::Refunded => 'gray',
                PaymentLinkStatus::RefundNeeded => 'danger',
            },
            $value instanceof CheckInResult => $value === CheckInResult::Allowed ? 'success' : 'danger',
            $value instanceof PaymentType => $value === PaymentType::Payment ? 'success' : 'danger',
            $value instanceof PaymentMethod => match ($value) {
                PaymentMethod::Cash => 'success',
                PaymentMethod::Telebirr, PaymentMethod::CbeBirr => 'info',
                PaymentMethod::BankTransfer => 'gray',
                PaymentMethod::Chapa => 'primary',
            },
            $value instanceof StaffRole => match ($value) {
                StaffRole::Owner => 'primary',
                StaffRole::Manager => 'info',
                StaffRole::Accountant => 'success',
                StaffRole::Receptionist => 'warning',
                StaffRole::Trainer => 'gray',
            },
            $value instanceof AnnouncementStatus => match ($value) {
                AnnouncementStatus::Scheduled => 'warning',
                AnnouncementStatus::Sent => 'success',
                AnnouncementStatus::Cancelled => 'gray',
            },
            $value instanceof ChapaMode => $value === ChapaMode::Live ? 'success' : 'warning',
            $value instanceof CheckInMethod => $value === CheckInMethod::Qr ? 'info' : 'gray',
            default => 'gray',
        };
    }

    public static function icon(?BackedEnum $value): ?Heroicon
    {
        return match (true) {
            $value instanceof MembershipStatus => match ($value) {
                MembershipStatus::Active => Heroicon::OutlinedCheckCircle,
                MembershipStatus::Frozen => Heroicon::OutlinedPauseCircle,
                MembershipStatus::Upcoming => Heroicon::OutlinedClock,
                MembershipStatus::Expired => Heroicon::OutlinedMinusCircle,
                MembershipStatus::Cancelled => Heroicon::OutlinedXCircle,
            },
            $value instanceof PaymentLinkStatus => match ($value) {
                PaymentLinkStatus::Pending => Heroicon::OutlinedClock,
                PaymentLinkStatus::Paid => Heroicon::OutlinedCheckCircle,
                PaymentLinkStatus::Cancelled => Heroicon::OutlinedXCircle,
                PaymentLinkStatus::RefundNeeded => Heroicon::OutlinedExclamationTriangle,
                PaymentLinkStatus::Refunded => Heroicon::OutlinedArrowUturnLeft,
            },
            $value instanceof CheckInResult => $value === CheckInResult::Allowed ? Heroicon::OutlinedCheckCircle : Heroicon::OutlinedNoSymbol,
            $value instanceof PaymentType => $value === PaymentType::Payment ? Heroicon::OutlinedArrowDownLeft : Heroicon::OutlinedArrowUpRight,
            $value instanceof AnnouncementStatus => match ($value) {
                AnnouncementStatus::Scheduled => Heroicon::OutlinedClock,
                AnnouncementStatus::Sent => Heroicon::OutlinedPaperAirplane,
                AnnouncementStatus::Cancelled => Heroicon::OutlinedXCircle,
            },
            $value instanceof CheckInMethod => $value === CheckInMethod::Qr ? Heroicon::OutlinedQrCode : Heroicon::OutlinedComputerDesktop,
            default => null,
        };
    }

    /**
     * Every case of the enum as filter or select options, value => label.
     *
     * @param  class-string<BackedEnum>  $enum
     * @return array<string, string>
     */
    public static function options(string $enum): array
    {
        return collect($enum::cases())
            ->mapWithKeys(fn (BackedEnum $case): array => [(string) $case->value => self::label($case)])
            ->all();
    }

    /**
     * The colour of a promo code's status, as PromoCodeResource words it.
     */
    public static function promoCodeColor(string $status): string
    {
        return match ($status) {
            'Active' => 'success',
            'Upcoming' => 'warning',
            'Used up' => 'danger',
            default => 'gray',
        };
    }
}
