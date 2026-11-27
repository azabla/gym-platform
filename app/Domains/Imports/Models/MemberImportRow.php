<?php

declare(strict_types=1);

namespace App\Domains\Imports\Models;

use App\Domains\Imports\Enums\MemberImportRowOutcome;
use App\Domains\Imports\Exceptions\RowRefused;
use App\Domains\Members\Models\Member;
use App\Domains\Organizations\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of a member import file: its cells as they were, what checking or
 * importing it gave, and the member it made. Pruned 30 days after its import
 * finished, as it holds names and phone numbers. See ADR 0044.
 *
 * @property int $line_number
 * @property array<string, string> $cells
 * @property MemberImportRowOutcome $outcome
 * @property string|null $reason_text
 * @property array<string, string>|null $reason_values
 */
class MemberImportRow extends Model
{
    use BelongsToOrganization, MassPrunable;

    public const KEPT_FOR_DAYS = 30;

    /** @return BelongsTo<MemberImport, $this> */
    public function import(): BelongsTo
    {
        return $this->belongsTo(MemberImport::class, 'member_import_id');
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class)->withTrashed();
    }

    /**
     * Record what became of the row, and why when it isn't ready or added.
     */
    public function settle(MemberImportRowOutcome $outcome, ?RowRefused $reason = null, ?Member $member = null): void
    {
        $this->outcome = $outcome;
        $this->reason_text = $reason?->text;
        $this->reason_values = $reason?->values;
        $this->member()->associate($member);
        $this->save();
    }

    /**
     * Why the row wasn't imported, in the reader's language.
     */
    public function reason(): ?string
    {
        return $this->reason_text === null ? null : __($this->reason_text, $this->reason_values ?? []);
    }

    /**
     * Rows of imports that finished more than 30 days ago.
     *
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return self::query()->acrossOrganizations()->whereIn(
            'member_import_id',
            MemberImport::query()->acrossOrganizations()->where('finished_at', '<', now()->subDays(self::KEPT_FOR_DAYS))->select('id'),
        );
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cells' => 'array',
            'outcome' => MemberImportRowOutcome::class,
            'reason_values' => 'array',
        ];
    }
}
