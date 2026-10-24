<?php

namespace App\Http\Resources\V1\Me;

use App\Domains\Announcements\Models\Announcement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An announcement from the gym, as the member sees it: what it says and when
 * it went out, but not who posted it or who else it went to.
 *
 * @mixin Announcement
 */
class MyAnnouncementResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'sent_at' => $this->sent_at?->toIso8601String(),
        ];
    }
}
