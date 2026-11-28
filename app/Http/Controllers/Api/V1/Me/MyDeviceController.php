<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Domains\Identity\Enums\SignInApp;
use App\Domains\Identity\SignIns;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The phones signed in as the user, in either app (ADR 0046): to see them
 * and sign out one that was lost. A sign-in unused for 30 days (staff app)
 * or 90 (members app) ends by itself.
 */
class MyDeviceController extends Controller
{
    /**
     * The user's sign-ins, the most recently used first; current is the one
     * this request was made with.
     */
    public function index(Request $request): JsonResponse
    {
        $currentId = $request->user()->currentAccessToken()->getKey();

        $devices = SignIns::of($request->user())->map(fn (PersonalAccessToken $token): array => [
            'id' => $token->getKey(),
            'name' => $token->name,
            'app' => (SignInApp::tryFrom((string) $token->getAttribute('app')) ?? SignInApp::Staff)->value,
            'last_used_at' => $token->last_used_at?->toIso8601String(),
            'signed_in_at' => $token->created_at?->toIso8601String(),
            'current' => $token->getKey() === $currentId,
        ])->values();

        return response()->json(['data' => $devices]);
    }

    /**
     * Sign one of the user's phones out. Another person's sign-in is a 404.
     */
    public function destroy(Request $request, int $device): Response
    {
        $token = $request->user()->tokens()->whereKey($device)->first();

        abort_if($token === null, Response::HTTP_NOT_FOUND);

        SignIns::end($token);

        return response()->noContent();
    }
}
