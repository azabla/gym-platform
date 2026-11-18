<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Domains\Identity\Models\User;
use App\Domains\Members\Models\Member;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Me\UpdateMyLanguageRequest;
use Illuminate\Http\Response;

class MyLanguageController extends Controller
{
    /**
     * Choose the language messages are written in: the SMS of every gym
     * where the signed-in phone is a member, and, for staff, their own inbox
     * and texts. The app calls it when the member changes language, beside
     * updating its push token's locale. See ADR 0034.
     */
    public function update(UpdateMyLanguageRequest $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $language = $request->language();

        $user->update(['message_language' => $language]);

        if ($user->phone !== null) {
            Member::query()->acrossOrganizations()->where('phone', $user->phone)->update(['message_language' => $language->value]);
        }

        return response()->noContent();
    }
}
