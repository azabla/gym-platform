<?php

namespace App\Console\Commands;

use App\Dashboards\StaffDashboard;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Notifications\MembersToCall;
use App\Notifications\StaffRecipients;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class SendStaffMorningList extends Command
{
    /**
     * The hour, in each gym's time zone, from which the day's list goes out.
     */
    private const SEND_FROM_HOUR = 8;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notifications:send-staff-morning-list';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Tell owners, managers and receptionists how many members to call today';

    /**
     * Execute the console command. It runs every hour; from 08:00 where each
     * gym is, its owners, managers and receptionists get one message a day
     * with the number of memberships at their branches ending in the next 7
     * days with nothing sold after them (the dashboard's members to call).
     * Nobody gets it when there are none, or twice in a day, however often
     * it runs. See ADR 0027.
     */
    public function handle(CurrentOrganization $currentOrganization, StaffDashboard $dashboard): int
    {
        $sent = 0;

        foreach (Organization::query()->active()->orderBy('id')->get() as $organization) {
            if (CarbonImmutable::now($organization->timezone)->hour < self::SEND_FROM_HOUR) {
                continue;
            }

            $sent += (int) $currentOrganization->run($organization, fn (): int => $this->sendAt($organization, $dashboard));
        }

        $this->info("Sent {$sent} morning ".Str::plural('list', $sent).'.');

        return self::SUCCESS;
    }

    private function sendAt(Organization $organization, StaffDashboard $dashboard): int
    {
        $startOfToday = CarbonImmutable::parse($organization->today()->toDateString(), $organization->timezone)->utc();
        $sent = 0;

        foreach (StaffRecipients::forMembersAnywhere() as $staff) {
            if ($this->hadTodaysListAlready($staff, $startOfToday)) {
                continue;
            }

            $count = count($dashboard->expiring($organization, $staff->limitedToBranchIds(), false));

            if ($count > 0) {
                $staff->notify(MembersToCall::count($count));
                $sent++;
            }
        }

        return $sent;
    }

    private function hadTodaysListAlready(Staff $staff, CarbonImmutable $startOfToday): bool
    {
        return $staff->notifications()
            ->where('type', 'staff_members_to_call')
            ->where('created_at', '>=', $startOfToday)
            ->exists();
    }
}
