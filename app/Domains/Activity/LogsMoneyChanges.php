<?php

declare(strict_types=1);

namespace App\Domains\Activity;

use App\Domains\Activity\Enums\ActivityAction;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Organizations\Tenancy\CurrentStaff;
use App\Domains\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * For records staff create and change in forms, such as plans and promo codes,
 * whose money matters to the log (ADR 0046): when one is created, or saved
 * with a change to what it charges, the change is logged with the staff
 * member signed in (none, for a seed or a command).
 *
 * @phpstan-require-extends Model
 */
trait LogsMoneyChanges
{
    /**
     * The action to log for this record.
     */
    abstract protected function moneyChangeAction(): ActivityAction;

    /**
     * What to log about the save, or null when nothing it charges changed.
     *
     * @return array<string, scalar|Money|CarbonImmutable|null>|null
     */
    abstract protected function moneyChangeDetails(bool $created): ?array;

    public static function bootLogsMoneyChanges(): void
    {
        static::created(fn (self $record) => $record->logMoneyChange(created: true));
        static::updated(fn (self $record) => $record->logMoneyChange(created: false));
    }

    protected function logMoneyChange(bool $created): void
    {
        $details = $this->moneyChangeDetails($created);

        if ($details === null) {
            return;
        }

        Activity::record($this->moneyChangeAction(), $this, self::staffSignedIn(), null, ['created' => $created, ...$details]);
    }

    private static function staffSignedIn(): ?Staff
    {
        $currentStaff = app(CurrentStaff::class);

        return $currentStaff->has() ? $currentStaff->get() : null;
    }
}
