<?php

declare(strict_types=1);

namespace App\Domains\Announcements\Models;

use App\Domains\Announcements\Enums\AnnouncementStatus;
use App\Domains\Announcements\Policies\AnnouncementPolicy;
use App\Domains\Members\Models\Member;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Organizations\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Database\Factories\AnnouncementFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A message from a gym to its members, in the gym's own words: to members
 * with a membership valid today, at every branch or one, and optionally to
 * those whose membership ended in the last 30 days. It goes now or at
 * send_at, and can be edited or cancelled until it goes. Those changes go by
 * its own key, so they work with or without a current gym. See ADR 0015.
 */
#[UseFactory(AnnouncementFactory::class)]
#[UsePolicy(AnnouncementPolicy::class)]
class Announcement extends Model
{
    /** @use HasFactory<AnnouncementFactory> */
    use BelongsToOrganization, HasFactory;

    /**
     * How long after their membership ended members still hear announcements
     * that include the recently expired.
     */
    public const RECENTLY_EXPIRED_DAYS = 30;

    /**
     * The same default as the database column, so a new announcement knows it before it is saved.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'include_recently_expired' => false,
    ];

    /**
     * Post an announcement at the staff member's gym, to go out at the given
     * time, or now when none is given.
     */
    public static function post(
        Staff $postedBy,
        string $title,
        string $body,
        ?Branch $branch = null,
        bool $includeRecentlyExpired = false,
        ?CarbonImmutable $sendAt = null,
    ): self {
        $announcement = new self;
        $announcement->forceFill([
            'organization_id' => $postedBy->organization_id,
            'branch_id' => $branch?->id,
            'posted_by_staff_id' => $postedBy->id,
            'title' => $title,
            'body' => $body,
            'include_recently_expired' => $includeRecentlyExpired,
            'send_at' => ($sendAt ?? CarbonImmutable::now())->utc(),
        ])->save();

        return $announcement;
    }

    /**
     * Change what a scheduled announcement says, who it goes to or when.
     * Returns false, changing nothing, if it has gone out or been cancelled
     * in the meantime. Times are kept in UTC, whatever offset they come with.
     *
     * @param  array{title?: string, body?: string, branch_id?: int|null, include_recently_expired?: bool, send_at?: CarbonImmutable}  $changes
     */
    public function edit(array $changes): bool
    {
        if (isset($changes['send_at'])) {
            $changes['send_at'] = $changes['send_at']->utc();
        }

        $edited = $this->newQueryWithoutScopes()
            ->whereKey($this->id)
            ->whereNull('sent_at')
            ->whereNull('cancelled_at')
            ->update($changes) === 1;

        $this->refresh();

        return $edited;
    }

    /**
     * Cancel a scheduled announcement. Returns false if it has gone out or
     * been cancelled already.
     */
    public function cancel(): bool
    {
        $cancelled = $this->newQueryWithoutScopes()
            ->whereKey($this->id)
            ->whereNull('sent_at')
            ->whereNull('cancelled_at')
            ->update(['cancelled_at' => CarbonImmutable::now()]) === 1;

        $this->refresh();

        return $cancelled;
    }

    /**
     * Mark the announcement sent, if it is due and nothing else sent or
     * cancelled it first. Only the caller that gets true sends it, so it goes
     * out once however many times sending is attempted.
     */
    public function claimForSending(): bool
    {
        $claimed = $this->newQueryWithoutScopes()
            ->whereKey($this->id)
            ->due()
            ->update(['sent_at' => CarbonImmutable::now()]) === 1;

        $this->refresh();

        return $claimed;
    }

    public function recordRecipients(int $count): void
    {
        $this->forceFill(['recipients_count' => $count])->save();
    }

    /**
     * The members it goes to, worked out today at its gym: members still with
     * the gym (of its branch, when it has one) with a membership valid today,
     * or one that ended in the last 30 days when it includes them.
     *
     * @return Builder<Member>
     */
    public function audience(): Builder
    {
        $today = $this->organization->today();
        $endedNoEarlierThan = $this->include_recently_expired ? $today->subDays(self::RECENTLY_EXPIRED_DAYS) : $today;

        return Member::query()
            ->when($this->branch_id !== null, fn ($query) => $query->where('home_branch_id', $this->branch_id))
            ->whereExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('memberships')
                ->whereColumn('memberships.member_id', 'members.id')
                ->whereColumn('memberships.organization_id', 'members.organization_id')
                ->whereNull('memberships.cancelled_at')
                ->where('memberships.starts_on', '<=', $today->toDateString())
                ->where('memberships.ends_on', '>=', $endedNoEarlierThan->toDateString()));
    }

    public function status(): AnnouncementStatus
    {
        return match (true) {
            $this->cancelled_at !== null => AnnouncementStatus::Cancelled,
            $this->sent_at !== null => AnnouncementStatus::Sent,
            default => AnnouncementStatus::Scheduled,
        };
    }

    public function isScheduled(): bool
    {
        return $this->status() === AnnouncementStatus::Scheduled;
    }

    /**
     * The branch whose members it goes to, or null for every branch.
     *
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<Staff, $this> */
    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'posted_by_staff_id');
    }

    /**
     * Announcements whose time has come that haven't gone out or been cancelled.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function due(Builder $query): void
    {
        $query->whereNull('sent_at')
            ->whereNull('cancelled_at')
            ->where('send_at', '<=', CarbonImmutable::now());
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'include_recently_expired' => 'boolean',
            'send_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'recipients_count' => 'integer',
        ];
    }
}
