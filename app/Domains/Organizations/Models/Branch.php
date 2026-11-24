<?php

declare(strict_types=1);

namespace App\Domains\Organizations\Models;

use App\Domains\Organizations\Hours\OpeningWindow;
use App\Domains\Organizations\Policies\BranchPolicy;
use App\Domains\Organizations\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Closure;
use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

#[Fillable(['name', 'code', 'address'])]
#[UseFactory(BranchFactory::class)]
#[UsePolicy(BranchPolicy::class)]
class Branch extends Model
{
    /** @use HasFactory<BranchFactory> */
    use BelongsToOrganization, HasFactory;

    /**
     * A branch code as it is stored: 2 to 20 capital letters, digits or
     * hyphens, starting with a letter or digit, such as MAIN or BOLE-2.
     */
    public const CODE_PATTERN = '/^[A-Z0-9][A-Z0-9-]{1,19}$/';

    /**
     * A code as it is stored: without surrounding spaces, in capitals, so
     * "bole" and "BOLE" are the same code.
     */
    public static function tidyCode(string $code): string
    {
        return strtoupper(trim($code));
    }

    /**
     * The gym's branch that already has the code, however it is typed, if any.
     */
    public static function codeTakenBy(string $code, ?self $ignoring = null): ?self
    {
        return static::query()
            ->where('code', static::tidyCode($code))
            ->when($ignoring !== null, fn (Builder $query) => $query->whereKeyNot($ignoring?->getKey()))
            ->first();
    }

    /**
     * The code is always stored tidied.
     *
     * @return Attribute<string, string>
     */
    protected function code(): Attribute
    {
        return Attribute::make(
            get: fn (string $value): string => $value,
            set: fn (string $value): string => static::tidyCode($value),
        );
    }

    /**
     * When the branch is open, by weekday, Monday first. None means always open.
     *
     * @return HasMany<BranchOpeningHour, $this>
     */
    public function openingHours(): HasMany
    {
        return $this->hasMany(BranchOpeningHour::class)->orderBy('weekday');
    }

    /**
     * Days the branch is closed or keeps other hours, such as holidays
     * (ADR 0037).
     *
     * @return HasMany<BranchClosure, $this>
     */
    public function closures(): HasMany
    {
        return $this->hasMany(BranchClosure::class)->orderBy('starts_on');
    }

    /**
     * What the apps load with a branch: its weekly hours, and its closures
     * from the gym's today to 60 days ahead.
     *
     * @return array<int|string, string|Closure>
     */
    public static function withHoursAndClosuresFrom(CarbonImmutable $today): array
    {
        return [
            'openingHours',
            'closures' => fn ($query) => $query->upcomingFrom($today),
        ];
    }

    /**
     * Whether the branch is open at the moment, in the gym's time zone (ADR
     * 0016). Hours that run past midnight, such as Monday 18:00–02:00, keep
     * it open into Tuesday morning, and a closure overrides the weekly hours
     * on its days (ADR 0037).
     */
    public function isOpenAt(CarbonImmutable $moment, string $timezone): bool
    {
        $local = $moment->setTimezone($timezone);
        $timeOfDay = $local->format('H:i:s');
        $date = $local->startOfDay();
        $closures = $this->closures()->overlapping($date->subDay(), $date)->get();

        $today = $this->windowOn($date, $closures);
        $yesterday = $this->windowOn($date->subDay(), $closures);

        return ($today !== null && $today->covers($timeOfDay))
            || ($yesterday !== null && $yesterday->coversTheNextMorning($timeOfDay));
    }

    /**
     * The branch's hours on a date, or null when it is closed all day: a
     * closure on the date wins; otherwise the weekday's hours. A branch
     * without weekly hours is open all day; once it has some, a weekday
     * without hours is closed.
     *
     * @param  Collection<int, BranchClosure>|null  $closures  the branch's closures, if already loaded
     */
    public function windowOn(CarbonImmutable $date, ?Collection $closures = null): ?OpeningWindow
    {
        $closures ??= $this->closures()->overlapping($date, $date)->get();
        $closure = $closures->first(fn (BranchClosure $closure): bool => $closure->covers($date));

        if ($closure !== null) {
            return $closure->window();
        }

        if ($this->openingHours->isEmpty()) {
            return OpeningWindow::allDay();
        }

        return $this->openingHours->firstWhere('weekday', $date->isoWeekday())?->window();
    }

    /**
     * Replace the branch's whole week of opening hours. An empty week makes
     * it always open again.
     *
     * @param  list<array{weekday: int, opens_at: string, closes_at: string}>  $week
     */
    public function replaceOpeningHours(array $week): void
    {
        DB::transaction(function () use ($week): void {
            $this->openingHours()->delete();

            foreach ($week as $day) {
                $hours = new BranchOpeningHour;
                $hours->forceFill([...$day, 'organization_id' => $this->organization_id]);
                $this->openingHours()->save($hours);
            }
        });

        $this->unsetRelation('openingHours');
    }
}
