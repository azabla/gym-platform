<?php

declare(strict_types=1);

namespace App\Domains\Imports\Models;

use App\Domains\Imports\Csv\MemberCsv;
use App\Domains\Imports\Enums\MemberImportRowOutcome;
use App\Domains\Imports\Enums\MemberImportStatus;
use App\Domains\Imports\Exceptions\FileRefused;
use App\Domains\Imports\Policies\MemberImportPolicy;
use App\Domains\Imports\RowCheck;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Organizations\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * A file of members a gym had before joining, being brought in (ADR 0044):
 * checked first, so staff see what each row will give, then imported once
 * they confirm, or discarded.
 *
 * @property string $file_name
 * @property MemberImportStatus $status
 * @property int $rows_total
 * @property int $ready_count
 * @property int $error_count
 * @property int $duplicate_count
 * @property int $added_count
 * @property int $failed_count
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 */
#[UsePolicy(MemberImportPolicy::class)]
class MemberImport extends Model
{
    use BelongsToOrganization;

    /**
     * Read and check the file, keeping each row and what it will give, for
     * the staff member to look over before importing.
     *
     * @throws FileRefused when the file can't be read as a member import
     */
    public static function check(Staff $uploadedBy, string $fileName, string $contents, CarbonImmutable $today): self
    {
        $rows = MemberCsv::rows($contents);
        $check = new RowCheck($today);

        return DB::transaction(function () use ($uploadedBy, $fileName, $rows, $check): self {
            $import = new self;
            $import->organization()->associate($uploadedBy->organization_id);
            $import->uploadedBy()->associate($uploadedBy);
            $import->file_name = mb_substr($fileName, 0, 255);
            $import->status = MemberImportStatus::Checked;
            $import->save();

            foreach ($rows as $line => $cells) {
                $checked = $check->check($line, $cells);

                $row = new MemberImportRow;
                $row->import()->associate($import);
                $row->line_number = $line;
                $row->cells = $cells;
                $row->outcome = $checked->outcome;
                $row->reason_text = $checked->reason?->text;
                $row->reason_values = $checked->reason?->values;
                $row->save();
            }

            $import->recount();

            return $import;
        });
    }

    /**
     * Drop a checked file without importing it.
     */
    public function discard(): void
    {
        if ($this->status !== MemberImportStatus::Checked) {
            return;
        }

        $this->status = MemberImportStatus::Discarded;
        $this->finished_at = CarbonImmutable::now();
        $this->save();
    }

    /**
     * Count the rows by what became of them.
     */
    public function recount(): void
    {
        $counts = $this->rows()->toBase()->groupBy('outcome')->selectRaw('outcome, COUNT(*) AS rows')->pluck('rows', 'outcome');
        $count = fn (MemberImportRowOutcome $outcome): int => (int) ($counts[$outcome->value] ?? 0);

        $this->rows_total = (int) $counts->sum();
        $this->ready_count = $count(MemberImportRowOutcome::Ready);
        $this->error_count = $count(MemberImportRowOutcome::Error);
        $this->duplicate_count = $count(MemberImportRowOutcome::Duplicate);
        $this->added_count = $count(MemberImportRowOutcome::Added);
        $this->failed_count = $count(MemberImportRowOutcome::Failed);
        $this->save();
    }

    /** @return BelongsTo<Staff, $this> */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'uploaded_by_staff_id')->withTrashed();
    }

    /** @return HasMany<MemberImportRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(MemberImportRow::class, 'member_import_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => MemberImportStatus::class,
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }
}
