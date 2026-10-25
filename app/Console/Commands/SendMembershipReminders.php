<?php

namespace App\Console\Commands;

use App\Domains\Memberships\Models\Membership;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Notifications\MemberNotification;
use App\Notifications\MembershipExpired;
use App\Notifications\MembershipExpiring;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SendMembershipReminders extends Command
{
    /**
     * The days before a membership ends when its member is reminded.
     */
    private const DAYS_BEFORE_END = [5, 3, 1];

    /**
     * The hour, in each gym's time zone, from which the day's reminders go out.
     */
    private const SEND_FROM_HOUR = 9;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notifications:send-membership-reminders';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remind members that their membership ends soon, or ended yesterday';

    /**
     * Execute the console command. It runs every hour; each gym's reminders
     * go out from 09:00 where the gym is, and each reminder is sent once,
     * however often the command runs (sent_reminders). Members who already
     * have a later membership aren't reminded. See ADR 0012.
     */
    public function handle(CurrentOrganization $currentOrganization): int
    {
        $sent = 0;

        foreach (Organization::query()->orderBy('id')->get() as $organization) {
            if (CarbonImmutable::now($organization->timezone)->hour < self::SEND_FROM_HOUR) {
                continue;
            }

            $sent += (int) $currentOrganization->run($organization, fn (): int => $this->remindAt($organization));
        }

        $this->info("Sent {$sent} membership ".Str::plural('reminder', $sent).'.');

        return self::SUCCESS;
    }

    private function remindAt(Organization $organization): int
    {
        $today = $organization->today();
        $sent = 0;

        foreach (self::DAYS_BEFORE_END as $daysLeft) {
            foreach ($this->endingOn($today->addDays($daysLeft)) as $membership) {
                $sent += $this->sendOnce($membership, 'membership_expiring', $daysLeft, MembershipExpiring::for($membership, $daysLeft));
            }
        }

        foreach ($this->endingOn($today->subDay()) as $membership) {
            $sent += $this->sendOnce($membership, 'membership_expired', -1, MembershipExpired::for($membership));
        }

        return $sent;
    }

    /**
     * The gym's memberships ending on the day, of members still with the
     * gym, with nothing sold to follow them.
     *
     * @return iterable<Membership>
     */
    private function endingOn(CarbonImmutable $day): iterable
    {
        return Membership::query()
            ->notCancelled()
            ->whereDate('ends_on', $day->toDateString())
            ->whereHas('member', fn ($query) => $query->whereNull('deleted_at'))
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('memberships as later')
                ->whereColumn('later.member_id', 'memberships.member_id')
                ->whereColumn('later.starts_on', '>', 'memberships.ends_on')
                ->whereNull('later.cancelled_at'))
            ->with(['plan', 'organization', 'member'])
            ->lazyById();
    }

    /**
     * Send the reminder unless it was sent before. Returns 1 if it was sent now.
     */
    private function sendOnce(Membership $membership, string $type, int $daysBeforeEnd, MemberNotification $reminder): int
    {
        $isNew = DB::table('sent_reminders')->insertOrIgnore([
            'organization_id' => $membership->organization_id,
            'membership_id' => $membership->id,
            'type' => $type,
            'days_before_end' => $daysBeforeEnd,
            'sent_at' => CarbonImmutable::now(),
        ]) === 1;

        if ($isNew) {
            $membership->member->notify($reminder);
        }

        return $isNew ? 1 : 0;
    }
}
