<?php

namespace App\Http\Controllers\Staff;

use App\Domains\Identity\Models\PasswordLink;
use App\Domains\Identity\Models\User;
use App\Domains\Organizations\Models\Staff;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RequestSmsCodeRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Timebox;

/**
 * Staff who forgot their password ask for a link by SMS. Every number gets
 * the same answer, in the same time, so nobody can use this to find out who
 * works at a gym, and only staff numbers run up the SMS bill. See ADR 0019.
 */
class ForgotStaffPasswordController extends Controller
{
    /**
     * How long every answer takes, in microseconds.
     */
    private const ANSWER_MICROSECONDS = 200_000;

    public function create(): View
    {
        return view('staff-password.forgot');
    }

    public function store(RequestSmsCodeRequest $request, Timebox $timebox): RedirectResponse
    {
        $phone = $request->phone();

        $timebox->call(function () use ($phone): void {
            $user = User::query()->where('phone', $phone->e164)->first();

            if ($user === null || ! Staff::query()->acrossOrganizations()->whereBelongsTo($user)->exists()) {
                return;
            }

            PasswordLink::sendReset($user);
        }, self::ANSWER_MICROSECONDS);

        return redirect()->route('staff.password.forgot')
            ->with('status', 'If this number belongs to a staff member, a link to reset the password is on its way by SMS.');
    }
}
