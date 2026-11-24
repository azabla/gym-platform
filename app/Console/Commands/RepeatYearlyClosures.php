<?php

namespace App\Console\Commands;

use App\Domains\Organizations\Models\BranchClosure;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class RepeatYearlyClosures extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'branch-closures:repeat-yearly';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Add next year\'s closure for each yearly closure that has ended';

    /**
     * Run every hour; once a closure that comes back every year has ended
     * (by the gym's date), next year's is added on the same Ethiopian date,
     * once. See ADR 0039.
     */
    public function handle(CurrentOrganization $currentOrganization): int
    {
        $added = 0;

        foreach (Organization::query()->orderBy('id')->get() as $organization) {
            $added += (int) $currentOrganization->run($organization, function () use ($organization): int {
                $ended = BranchClosure::query()
                    ->where('repeats_yearly', true)
                    ->whereNull('repeated_at')
                    ->whereDate('ends_on', '<', $organization->today()->toDateString())
                    ->get();

                return $ended->filter(fn (BranchClosure $closure): bool => $closure->repeatNextYear() !== null)->count();
            });
        }

        $this->info("Added {$added} ".Str::plural('closure', $added).' for next year.');

        return self::SUCCESS;
    }
}
