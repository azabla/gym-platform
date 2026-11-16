<?php

declare(strict_types=1);

namespace App\Reports;

use App\Domains\Memberships\Models\Membership;
use App\Domains\Organizations\Models\Organization;
use Illuminate\Database\Eloquent\Builder;

/**
 * The memberships sold below their list price in the period, at the desk or
 * in the app, of members whose home branch is one of the branches: what each
 * discount took off, and where it came from, a discount typed at the desk, a
 * promo code (ADR 0030) or a discount group (ADR 0032).
 */
final class DiscountsReport extends Report
{
    public function __construct(private readonly ReportPeriod $period) {}

    public function name(): string
    {
        return 'discounts';
    }

    public function headings(): array
    {
        return [
            'Sold on', 'Member', 'Phone', 'Home branch', 'Plan', 'List price', 'Price', 'Amount off', 'Currency',
            'Source', 'Code, group or reason', 'Sold by', 'Sold in the app',
        ];
    }

    public function rows(): iterable
    {
        foreach ($this->memberships()->lazy(self::CHUNK) as $membership) {
            yield [
                $this->day($membership->created_at?->setTimezone($this->period->gym->timezone)),
                (string) $membership->member?->full_name,
                (string) $membership->member?->phone,
                (string) $membership->member?->homeBranch?->name,
                (string) $membership->plan?->name,
                $membership->list_price->toDecimal(),
                $membership->price->toDecimal(),
                $membership->list_price->subtract($membership->price)->toDecimal(),
                $membership->price->currency->value,
                ...$this->sourceOf($membership),
                (string) $membership->soldBy?->user?->name,
                $membership->sold_in_app ? 'Yes' : 'No',
            ];
        }
    }

    protected function gym(): Organization
    {
        return $this->period->gym;
    }

    protected function describeDays(): string
    {
        return $this->period->describe();
    }

    /**
     * Where the discount came from, and its code, group or typed reason.
     *
     * @return array{0: string, 1: string}
     */
    private function sourceOf(Membership $membership): array
    {
        return match (true) {
            $membership->promoCode !== null => ['Promo code', $membership->promoCode->code],
            $membership->discountGroup !== null => ['Discount group', $membership->discountGroup->name],
            default => ['Typed', (string) $membership->discount_reason],
        };
    }

    /**
     * @return Builder<Membership>
     */
    private function memberships(): Builder
    {
        return Membership::query()
            ->where('created_at', '>=', $this->period->startsAt())
            ->where('created_at', '<', $this->period->endsBefore())
            ->whereColumn('price_minor', '<', 'list_price_minor')
            ->ofMembersAtAnyOf($this->period->branchIds)
            ->with(['member.homeBranch', 'plan', 'soldBy.user', 'promoCode', 'discountGroup'])
            ->orderBy('created_at')
            ->orderBy('id');
    }
}
