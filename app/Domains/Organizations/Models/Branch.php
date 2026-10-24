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
