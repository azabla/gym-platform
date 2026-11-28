<?php

declare(strict_types=1);

namespace App\Filament\Staff\Support;

use App\Domains\Activity\Enums\ActivityAction;
use App\Domains\Activity\Models\ActivityRecord;
use App\Domains\Billing\Enums\PaymentMethod;
use App\Filament\Staff\Resources\DiscountGroups\DiscountGroupResource;
use App\Filament\Staff\Resources\Members\MemberResource;
use App\Filament\Staff\Resources\Memberships\MembershipResource;
use App\Filament\Staff\Resources\PaymentLinks\PaymentLinkResource;
use App\Filament\Staff\Resources\Payments\PaymentResource;
use App\Filament\Staff\Resources\Plans\PlanResource;
use App\Filament\Staff\Resources\PromoCodes\PromoCodeResource;

/**
 * A money log record as a line in the staff member's language, and the page
 * of what it was about (ADR 0046). Records keep what they showed when written:
 * amounts as written, dates as "2026-11-28".
 */
final class ActivityText
{
    public static function of(ActivityRecord $record): string
    {
        $details = $record->details;
        $text = fn (string $key): string => isset($details[$key]) ? (string) $details[$key] : '';
        $date = fn (string $key): string => $text($key) === '' ? '' : Dates::short($text($key));
        $method = fn (): string => Badges::label(PaymentMethod::tryFrom($text('method')));

        $line = match ($record->action) {
            ActivityAction::PaymentReceived => __('Receipt :receipt: :amount by :method, paid on :paid_on', ['receipt' => $text('receipt'), 'amount' => $text('amount'), 'method' => $method(), 'paid_on' => $date('paid_on')]),
            ActivityAction::PaymentRefunded => __('Refund, receipt :receipt: :amount by :method', ['receipt' => $text('receipt'), 'amount' => $text('amount'), 'method' => $method()]),
            ActivityAction::PaymentVoided => __('Receipt :receipt of :amount voided: :reason', ['receipt' => $text('receipt'), 'amount' => $text('amount'), 'reason' => $text('reason')]),
            ActivityAction::MembershipSold => __(':plan from :starts_on at :price', ['plan' => $text('plan'), 'starts_on' => $date('starts_on'), 'price' => $text('price')])
                .(($details['discounted'] ?? false) === true ? ' '.__('(plan price :plan_price; :reason)', ['plan_price' => $text('plan_price'), 'reason' => $text('discount_reason') === '' ? __('no reason given') : $text('discount_reason')]) : ''),
            ActivityAction::MembershipCancelled => __(':plan at :price cancelled: :reason', ['plan' => $text('plan'), 'price' => $text('price'), 'reason' => $text('reason')]),
            ActivityAction::MembershipFrozen => trans_choice('Frozen :count day from :starts_on: :reason|Frozen :count days from :starts_on: :reason', (int) $text('days'), ['starts_on' => $date('starts_on'), 'reason' => $text('reason')]),
            ActivityAction::DiscountGiven => __('Put in the :group discount group (:discount)', ['group' => $text('group'), 'discount' => $text('discount')]),
            ActivityAction::DiscountRemoved => __('Taken out of the :group discount group', ['group' => $text('group')]),
            ActivityAction::PaymentLinkCancelled => __('Payment link for :amount cancelled', ['amount' => $text('amount')]),
            ActivityAction::PaymentLinkRefunded => __('Payment link of :amount refunded from Chapa', ['amount' => $text('amount')]),
            ActivityAction::PlanPriced => ($details['created'] ?? false) === true
                ? __(':plan added at :to', ['plan' => $text('plan'), 'to' => $text('to')])
                : __(':plan price changed from :from to :to', ['plan' => $text('plan'), 'from' => $text('from'), 'to' => $text('to')]),
            ActivityAction::PromoCodeSaved => self::changed($details, __('Promo code :code', ['code' => $text('code')]), $text('discount'), $text('was_discount')),
            ActivityAction::DiscountGroupSaved => self::changed($details, __('Discount group :group', ['group' => $text('group')]), $text('discount'), $text('was_discount')),
            ActivityAction::CashCounted => __('Counted :counted, should have had :expected', ['counted' => $text('counted'), 'expected' => $text('expected')])
                .($text('note') === '' ? '' : ': '.$text('note')),
            ActivityAction::CashCountChecked => __('Checked a count of :counted', ['counted' => $text('counted')])
                .($text('note') === '' ? '' : ': '.$text('note')),
        };

        return ($details['backdated'] ?? false) === true ? $line.' · '.__('backdated') : $line;
    }

    /**
     * The page of what the record was about, if staff can open it.
     */
    public static function urlOf(ActivityRecord $record): ?string
    {
        return match ($record->subject_type) {
            'payment' => PaymentResource::getUrl('view', ['record' => $record->subject_id]),
            'payment_link' => PaymentLinkResource::getUrl('view', ['record' => $record->subject_id]),
            'membership' => MembershipResource::getUrl('view', ['record' => $record->subject_id]),
            'member' => MemberResource::getUrl('view', ['record' => $record->subject_id]),
            'plan' => PlanResource::getUrl('edit', ['record' => $record->subject_id]),
            'promo_code' => PromoCodeResource::getUrl('edit', ['record' => $record->subject_id]),
            'discount_group' => DiscountGroupResource::getUrl('edit', ['record' => $record->subject_id]),
            default => null,
        };
    }

    /**
     * "Promo code NEWYEAR added: 10%", or "… changed from 10% to 25%".
     *
     * @param  array<string, scalar|null>  $details
     */
    private static function changed(array $details, string $what, string $discount, string $was): string
    {
        return match (true) {
            ($details['created'] ?? false) === true => __(':what added: :discount', ['what' => $what, 'discount' => $discount]),
            $was !== '' => __(':what changed from :was to :discount', ['what' => $what, 'was' => $was, 'discount' => $discount]),
            default => __(':what changed: :discount', ['what' => $what, 'discount' => $discount]),
        };
    }
}
