<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Domains\Notifications\Models\PushToken;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Me\StorePushTokenRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;

class MyPushTokenController extends Controller
{
    /**
     * Keep this phone's push token from Firebase, and the language it wants
     * messages in. The app sends it after signing in, whenever Firebase
     * replaces it, and when the member changes language. See ADR 0012.
     */
    public function update(StorePushTokenRequest $request): Response
    {
        PushToken::register(
            (int) $request->user()?->id,
            $this->tokenId($request),
            $request->string('token')->toString(),
            $request->language(),
        );

        return response()->noContent();
    }

    /**
     * Forget this phone's push token, so it gets no more pushes.
     */
    public function destroy(Request $request): Response
    {
        PushToken::query()->where('personal_access_token_id', $this->tokenId($request))->delete();

        return response()->noContent();
    }

    /**
     * The sign-in token of this phone, which its push token is tied to.
     */
    private function tokenId(Request $request): int
    {
        $token = $request->user()?->currentAccessToken();

        abort_unless($token instanceof PersonalAccessToken, Response::HTTP_FORBIDDEN, 'Push tokens are for signed-in phones.');

        return (int) $token->getKey();
    }
}
