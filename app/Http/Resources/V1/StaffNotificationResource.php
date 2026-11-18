<?php

namespace App\Http\Resources\V1;

use App\Domains\Identity\Models\User;
use App\Notifications\MessageText;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/**
 * A message in a staff member's inbox at the gym (ADR 0027): its type, its
 * text in the signed-in user's message language (ADR 0034), what it is about
 * (payment_id, membership_id or member_id), and whether it was read.
 *
 * @mixin DatabaseNotification
 */
class StaffNotificationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $details = (array) ($this->data['details'] ?? []);
        $user = $request->user();
        $language = $user instanceof User ? $user->messageLanguage()->value : 'en';

        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => MessageText::forStaff($this->type, 'title', $details, $language),
            'body' => MessageText::forStaff($this->type, 'body', $details, $language),
            'data' => (object) ($this->data['data'] ?? []),
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
