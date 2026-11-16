<?php

declare(strict_types=1);

namespace App\Domains\Organizations\Models;

use App\Domains\Organizations\Policies\BranchPolicy;
use App\Domains\Organizations\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
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
     * Whether the branch is open at the moment, in the gym's time zone. A
     * branch without opening hours is always open; once it has some, a
     * weekday without hours is closed. See ADR 0016.
     */
    public function isOpenAt(CarbonImmutable $moment, string $timezone): bool
    {
        if ($this->openingHours->isEmpty()) {
            return true;
        }

        $local = $moment->setTimezone($timezone);
        $hours = $this->openingHours->firstWhere('weekday', $local->isoWeekday());

        return $hours !== null && $hours->covers($local->format('H:i:s'));
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
