<?php

namespace App\Jobs;

use App\Domains\Imports\MemberImporter;
use App\Domains\Imports\Models\MemberImport;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Imports a confirmed member import at its gym, 100 rows at a time: each run
 * stays well inside the worker's time limit and lets other jobs, such as
 * SMS, run in between, then queues the next run while rows are left. Safe to
 * run again: only rows still ready are imported. See ADR 0044.
 */
class ImportMembers implements ShouldQueueAfterCommit
{
    use Queueable;

    /** How many rows each run imports. */
    public const ROWS_PER_RUN = 100;

    public function __construct(public MemberImport $import) {}

    public function handle(CurrentOrganization $currentOrganization, MemberImporter $importer): void
    {
        $gym = Organization::query()->findOrFail($this->import->organization_id);

        $left = $currentOrganization->run($gym, fn (): bool => $importer->importNext($this->import, self::ROWS_PER_RUN));

        if ($left) {
            self::dispatch($this->import);
        }
    }
}
