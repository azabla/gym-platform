<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domains\Attendance\Enums\CredentialRevocation;
use App\Domains\Attendance\Models\AccessCredential;
use App\Domains\Identity\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IssueTokenRequest;
use App\Http\Resources\V1\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class TokenController extends Controller
{
    /**
     * Sign in: exchange a phone (or email) and password for an API token for
     * one device.
     *
     * The web guard checks the password inside a Timebox, so an unknown phone
     * and a wrong password take the same time and get the same answer.
     */
    public function store(IssueTokenRequest $request): JsonResponse
    {
        [$field, $value] = $request->identifier();

        if (! Auth::guard('web')->validate([$field => $value, 'password' => $request->string('password')->toString()])) {
            throw ValidationException::withMessages([
                $field => __('auth.failed'),
            ]);
        }

        $user = User::query()->where($field, $value)->sole();

        $token = $user->createToken($request->string('device_name')->toString());

        return response()->json([
            'data' => [
                'token' => $token->plainTextToken,
                'user' => new UserResource($user),
            ],
        ], Response::HTTP_CREATED);
    }

    /**
     * Sign out: revoke the token this request was made with, and the QR
     * credentials issued to the phone under it (ADR 0011).
     */
    public function destroy(Request $request): Response
    {
        $token = $request->user()->currentAccessToken();

        AccessCredential::query()
            ->acrossOrganizations()
            ->where('personal_access_token_id', $token->getKey())
            ->active()
            ->get()
            ->each(fn (AccessCredential $credential) => $credential->revoke(CredentialRevocation::SignedOut));

        $token->delete();

        return response()->noContent();
    }
}
