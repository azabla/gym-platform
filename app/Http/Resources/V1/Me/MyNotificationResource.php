<?php

namespace App\Http\Resources\V1\Me;

use App\Notifications\MessageText;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/**
 * A message in the member's inbox at a gym, for the members app. It keeps
 * which message it is and its details, so the title and body are written
 * here (MessageText), in the language the app asks for (Accept-Language), falling back to
 * English. The data matches the push's, so a tap opens the same screen.
 *
 * @mixin DatabaseNotification
 */
class MyNotificationResource extends JsonResource
{
    /**
     * The languages messages can be written in. A language without its file
     * yet falls back to English, key by key.
     */
    public const LANGUAGES = ['en', 'am'];

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $language = $request->getPreferredLanguage(self::LANGUAGES) ?? 'en';
        $details = (array) ($this->data['details'] ?? []);

        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => MessageText::title($this->type, $details, $language),
            'body' => MessageText::body($this->type, $details, $language),
            'data' => (object) ($this->data['data'] ?? []),
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
