<?php

namespace App\Http\Resources\V1;

use App\Domains\Organizations\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The gym's settings: its name, slug and time zone, and the language its
 * members get messages in unless they chose their own (ADR 0034).
 *
 * @mixin Organization
 */
class OrganizationSettingsResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->name,
            'slug' => $this->slug,
            'timezone' => $this->timezone,
            'message_language' => $this->message_language,
        ];
    }
}
