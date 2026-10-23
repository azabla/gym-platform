<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domains\Identity\Models\SignInCode;
use App\Domains\Identity\Models\User;
use App\Domains\Members\Models\Member;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IssueSmsTokenRequest;
use App\Http\Resources\V1\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class SmsTokenController extends Controller
{
    /**
     * Sign in with a code sent by SMS: exchange the phone number and its code
     * for an API token for one device. The first sign-in creates the user,
     * named after the member. See ADR 0010.
     */
    public function store(IssueSmsTokenRequest $request): JsonResponse
    {
        $phone = $request->phone();
        $member = Member::query()->acrossOrganizations()->where('phone', $phone->e164)->latest('id')->first();

        if ($member === null || ! SignInCode::redeem($phone, $request->string('code')->toString())) {
            throw ValidationException::withMessages([
                'code' => 'This code is wrong or has expired. Ask for a new one.',
            ]);
        }

        $user = User::forPhone($phone, $member->full_name);
        $token = $user->createToken($request->string('device_name')->toString());

        return response()->json([
            'data' => [
                'token' => $token->plainTextToken,
                'user' => new UserResource($user),
            ],
        ], Response::HTTP_CREATED);
    }
}
