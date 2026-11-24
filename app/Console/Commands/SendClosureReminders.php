<?php

namespace App\Console\Commands;

use App\Domains\Organizations\Models\BranchClosure;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Notifications\BranchClosureMessages;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class SendClosureReminders extends Command
{
    /** The hour, in each gym's time zone, from which the reminders go out. */
    private const SEND_FROM_HOUR = 9;

    /** Members told about a closure this recently aren't reminded of it. */
    private const TOLD_RECENTLY_DAYS = 2;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notifications:send-closure-reminders';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remind members the day before their branch is closed or keeps other hours';

    /**
     * Run every hour; from 09:00 where each gym is, the members of a branch
     * closed tomorrow, or open other hours, get a push and an inbox message,
     * once, unless they were told in the last 2 days (when it was added). See
     * ADR 0039.
     */
    public function handle(CurrentOrganization $currentOrganization): int
    {
        $reminded = 0;

        foreach (Organization::query()->orderBy('id')->get() as $organization) {
            if (CarbonImmutable::now($organization->timezone)->hour < self::SEND_FROM_HOUR) {
                continue;
            }

            $reminded += (int) $currentOrganization->run($organization, fn (): int => $this->remindAt($organization));
        }

        $this->info("Reminded members of {$reminded} ".Str::plural('closure', $reminded).'.');

        return self::SUCCESS;
    }

    private function remindAt(Organization $organization): int
    {
        $closures = BranchClosure::query()
            ->with(['branch', 'organization'])
            ->whereDate('starts_on', $organization->today()->addDay()->toDateString())
            ->whereNull('reminded_at')
            ->get();

        $reminded = 0;
        $toldRecently = CarbonImmutable::now()->subDays(self::TOLD_RECENTLY_DAYS);

        foreach ($closures as $closure) {
            if ($closure->members_told_at === null || $closure->members_told_at->lt($toldRecently)) {
                BranchClosureMessages::tellMembersOf($closure);
                $reminded++;
            }

            $closure->forceFill(['reminded_at' => CarbonImmutable::now()])->save();
        }

        return $reminded;
    }
}
