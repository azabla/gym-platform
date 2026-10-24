<?php

namespace App\Http\Resources\V1;

use App\Domains\Billing\Models\ChapaAccount;
use App\Domains\Organizations\Models\Staff;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Whether the gym's Chapa account is connected, with which kind of key and by
 * whom. Never the key itself. The resource is null when nothing is connected.
 *
 * @property ChapaAccount|null $resource
 */
class ChapaAccountResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $account = $this->resource;

        if ($account === null) {
            return [
                'connected' => false,
                'mode' => null,
                'key_hint' => null,
                'connected_by' => null,
                'connected_at' => null,
            ];
        }

        return [
            'connected' => true,
            'mode' => $account->mode,
            'key_hint' => $account->key_hint,
            'connected_by' => $this->whenLoaded('connectedBy', fn (Staff $staff) => [
                'id' => $staff->id,
                'name' => $staff->user->name,
            ]),
            'connected_at' => $account->updated_at?->toIso8601String(),
        ];
    }
}
