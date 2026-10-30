<?php

namespace App\Http\Controllers\Staff;

use App\Domains\Identity\Models\PasswordLink;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\SetStaffPasswordRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

/**
 * The page a password link sent by SMS opens: staff choose their password
 * there, then sign in to the panel with their phone. See ADR 0019.
 */
class StaffPasswordController extends Controller
{
    public function edit(string $token): View|Response
    {
        $link = PasswordLink::findUsable($token);

        if ($link === null) {
            return response()->view('staff-password.link-expired', status: Response::HTTP_GONE);
        }

        return view('staff-password.edit', ['token' => $token, 'name' => $link->user->name]);
    }

    public function update(SetStaffPasswordRequest $request, string $token): RedirectResponse|Response
    {
        $link = PasswordLink::findUsable($token);

        if ($link === null || ! $link->setPassword($request->string('password')->toString())) {
            return response()->view('staff-password.link-expired', status: Response::HTTP_GONE);
        }

        return redirect()->route('staff.password.done');
    }

    public function done(): View
    {
        return view('staff-password.done');
    }
}
