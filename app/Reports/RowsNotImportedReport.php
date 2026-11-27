<?php

declare(strict_types=1);

namespace App\Reports;

use App\Domains\Imports\Csv\MemberCsv;
use App\Domains\Imports\Enums\MemberImportRowOutcome;
use App\Domains\Imports\Models\MemberImport;
use App\Domains\Imports\Models\MemberImportRow;
use App\Domains\Organizations\Models\Organization;

/**
 * The rows of a member import that weren't imported, as they were in the
 * file with why not in a last column, so staff fix them and upload the file
 * again: the rows already added are left out, and duplicates are skipped
 * again. See ADR 0044.
 */
final class RowsNotImportedReport extends Report
{
    public function __construct(private readonly MemberImport $import) {}

    public function name(): string
    {
        return 'members-not-imported';
    }

    public function headings(): array
    {
        return [...MemberCsv::COLUMNS, 'reason'];
    }

    public function rows(): iterable
    {
        $rows = $this->import->rows()
            ->whereIn('outcome', [MemberImportRowOutcome::Error, MemberImportRowOutcome::Duplicate, MemberImportRowOutcome::Failed])
            ->orderBy('line_number')
            ->lazy(self::CHUNK);

        foreach ($rows as $row) {
            /** @var MemberImportRow $row */
            yield [...array_map(fn (string $column): string => $row->cells[$column] ?? '', MemberCsv::COLUMNS), (string) $row->reason()];
        }
    }

    protected function gym(): Organization
    {
        return Organization::query()->findOrFail($this->import->organization_id);
    }

    protected function describeDays(): string
    {
        return $this->day($this->import->created_at);
    }
}
