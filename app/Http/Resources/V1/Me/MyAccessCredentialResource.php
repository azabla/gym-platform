<?php

namespace App\Http\Resources\V1\Me;

use App\Domains\Attendance\Models\AccessCredential;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A QR credential of the signed-in member, for the members app, without its
 * secret: the phone gets the secret only once, when it is issued.
 *
 * @mixin AccessCredential
 */
class MyAccessCredentialResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'credential_id' => $this->publicId(),
            'algorithm' => $this->algorithm,
            'status' => $this->revoked_at === null ? 'active' : 'revoked',
            'revoked_reason' => $this->revoked_reason,
            'issued_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
