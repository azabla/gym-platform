<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domains\Identity\Models\SignInCode;
use App\Domains\Identity\Notifications\SignInCodeNotification;
use App\Domains\Members\Models\Member;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RequestSmsCodeRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Timebox;

class SmsCodeController extends Controller
{
    /**
     * How long every answer takes, in microseconds, so a registered number and
     * an unregistered one can't be told apart by timing.
     */
    private const ANSWER_MICROSECONDS = 200_000;

    /**
     * Send a sign-in code by SMS to a phone number registered as a member at
     * any gym. Every number gets the same answer, in the same time, so nobody
     * can use this to find out who is a member, and unregistered numbers
     * can't run up the SMS bill. See ADR 0010.
     */
    public function store(RequestSmsCodeRequest $request, Timebox $timebox): JsonResponse
    {
        $phone = $request->phone();

        $timebox->call(function () use ($phone): void {
            if (! Member::query()->acrossOrganizations()->where('phone', $phone->e164)->exists()) {
                return;
            }

            Notification::route('sms', $phone)->notify(new SignInCodeNotification(SignInCode::issue($phone)));
        }, self::ANSWER_MICROSECONDS);

        return response()->json([
            'message' => 'If this number is registered at a gym, a code is on its way by SMS.',
        ], Response::HTTP_ACCEPTED);
    }
}
