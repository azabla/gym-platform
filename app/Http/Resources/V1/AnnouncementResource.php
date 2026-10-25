<?php

namespace App\Http\Resources\V1;

use App\Domains\Announcements\Models\Announcement;
use App\Domains\Organizations\Models\Staff;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An announcement, as staff see it: what it says, who it goes to, when, and
 * where it stands. A sent one shows how many members it reached.
 *
 * @mixin Announcement
 */
class AnnouncementResource extends JsonResource
{
    /**
     * The relations an announcement response shows.
     */
    public const RELATIONS = ['branch', 'postedBy.user'];

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status(),
            'title' => $this->title,
            'body' => $this->body,
            'branch' => new BranchResource($this->whenLoaded('branch')),
            'include_recently_expired' => $this->include_recently_expired,
            'send_at' => $this->send_at->toIso8601String(),
            'sent_at' => $this->sent_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'recipients_count' => $this->recipients_count,
            'posted_by' => $this->whenLoaded('postedBy', fn (Staff $staff) => [
                'id' => $staff->id,
                'name' => $staff->user->name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
