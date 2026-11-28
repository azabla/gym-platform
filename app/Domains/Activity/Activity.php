<?php

declare(strict_types=1);

namespace App\Domains\Activity;

use App\Domains\Activity\Enums\ActivityAction;
use App\Domains\Activity\Models\ActivityRecord;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Keeps the log of who did what with a gym's money (ADR 0046). The domains
 * call it inside the transaction of each money action, so an action never
 * lands without its record; it depends on no domain but Organizations and
 * Identity, like a kernel.
 */
final class Activity
{
    /**
     * Record an action on a gym-owned record.
     *
     * @param  array<string, scalar|Money|CarbonImmutable|null>  $details  what to show: amounts, dates, reasons, before and after
     */
    public static function record(ActivityAction $action, Model $subject, ?Staff $by, ?int $branchId, array $details = [], bool $flagged = false): ActivityRecord
    {
        $organizationId = $subject->getAttribute('organization_id');

        if (! is_int($organizationId)) {
            throw new InvalidArgumentException('Only a gym\'s own records are logged.');
        }

        $record = new ActivityRecord;
        $record->forceFill([
            'organization_id' => $organizationId,
            'branch_id' => $branchId,
            'staff_id' => $by?->getKey(),
            'action' => $action,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'details' => array_map(self::stored(...), $details),
            'flagged' => $flagged || $action->isAlwaysFlagged(),
            'occurred_at' => CarbonImmutable::now(),
        ])->save();

        return $record;
    }

    /**
     * Money is kept as its written amount ("2,500.00 ETB") and dates as
     * "2026-11-28", so the record reads the same however prices change.
     */
    private static function stored(mixed $value): string|int|float|bool|null
    {
        return match (true) {
            $value instanceof Money => $value->format(),
            $value instanceof CarbonImmutable => $value->toDateString(),
            default => $value,
        };
    }
}
