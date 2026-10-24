<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Domains\Attendance\Models\AccessCredential;
use App\Domains\Members\CurrentMember;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Me\MyAccessCredentialResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;

class MyAccessCredentialController extends Controller
{
    /**
     * Issue this phone's QR credential at the gym, and revoke the member's
     * previous one: one phone per member. The secret is in this answer only;
     * the phone keeps it and makes the codes. See ADR 0011.
     */
    public function store(Request $request, CurrentMember $currentMember): JsonResponse
    {
        [$credential, $secret] = AccessCredential::issue($currentMember->get(), $request->user(), $this->tokenId($request));

        return response()->json([
            'data' => [
                ...(new MyAccessCredentialResource($credential))->resolve($request),
                'secret' => base64_encode($secret),
            ],
        ], Response::HTTP_CREATED);
    }

    /**
     * This phone's credential at the gym, active or revoked, without its
     * secret. The app asks for a new one only when there is none, so two
     * phones don't keep revoking each other.
     */
    public function show(Request $request, CurrentMember $currentMember): MyAccessCredentialResource
    {
        $credential = AccessCredential::query()
            ->whereBelongsTo($currentMember->get())
            ->where('user_id', $request->user()?->id)
            ->where('personal_access_token_id', $this->tokenId($request))
            ->latest()
            ->firstOrFail();

        return new MyAccessCredentialResource($credential);
    }

    /**
     * The sign-in token of this phone, which its credential is tied to.
     */
    private function tokenId(Request $request): ?int
    {
        $token = $request->user()?->currentAccessToken();

        return $token instanceof PersonalAccessToken ? $token->id : null;
    }
}
