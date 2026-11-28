<?php

declare(strict_types=1);

namespace App\Domains\Activity\Enums;

/**
 * What was done with a gym's money (ADR 0046).
 */
enum ActivityAction: string
{
    case PaymentReceived = 'payment_received';
    case PaymentRefunded = 'payment_refunded';
    case PaymentVoided = 'payment_voided';
    case MembershipSold = 'membership_sold';
    case MembershipCancelled = 'membership_cancelled';
    case MembershipFrozen = 'membership_frozen';
    case DiscountGiven = 'discount_given';
    case DiscountRemoved = 'discount_removed';
    case PaymentLinkCancelled = 'payment_link_cancelled';
    case PaymentLinkRefunded = 'payment_link_refunded';
    case PlanPriced = 'plan_priced';
    case PromoCodeSaved = 'promo_code_saved';
    case DiscountGroupSaved = 'discount_group_saved';
    case CashCounted = 'cash_counted';
    case CashCountChecked = 'cash_count_checked';
    case ExpenseRecorded = 'expense_recorded';
    case ExpenseVoided = 'expense_voided';

    public function label(): string
    {
        return match ($this) {
            self::PaymentReceived => 'Payment received',
            self::PaymentRefunded => 'Refund given',
            self::PaymentVoided => 'Payment voided',
            self::MembershipSold => 'Membership sold',
            self::MembershipCancelled => 'Membership cancelled',
            self::MembershipFrozen => 'Membership frozen',
            self::DiscountGiven => 'Discount given',
            self::DiscountRemoved => 'Discount removed',
            self::PaymentLinkCancelled => 'Payment link cancelled',
            self::PaymentLinkRefunded => 'Payment link refunded',
            self::PlanPriced => 'Plan price set',
            self::PromoCodeSaved => 'Promo code saved',
            self::DiscountGroupSaved => 'Discount group saved',
            self::CashCounted => 'Cash counted',
            self::CashCountChecked => 'Cash count checked',
            self::ExpenseRecorded => 'Expense recorded',
            self::ExpenseVoided => 'Expense voided',
        };
    }

    /**
     * Actions an owner always wants to look at: money given back or taken off.
     */
    public function isAlwaysFlagged(): bool
    {
        return in_array($this, [self::PaymentRefunded, self::PaymentVoided, self::MembershipCancelled, self::PaymentLinkRefunded, self::ExpenseVoided], true);
    }
}
