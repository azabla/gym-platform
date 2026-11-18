<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Domains\Members\Models\Member;
use App\Domains\Shared\Language\Language;
use Laravel\Sanctum\Sanctum;

it('writes my messages at every gym, and as staff, in the language I choose', function () {
    $atDemoGym = Member::factory()->create(['phone' => '0922 34 56 78']);
    $atOtherGym = Member::factory()->create(['phone' => '0922 34 56 78', 'message_language' => 'en']);
    $someoneElse = Member::factory()->create(['phone' => '0911 22 33 44']);
    $user = actingAsMember($atDemoGym);

    $this->putJson(route('api.v1.me.language.update'), ['language' => 'am'])->assertNoContent();

    expect($atDemoGym->refresh()->message_language)->toBe(Language::Amharic)
        ->and($atOtherGym->refresh()->message_language)->toBe(Language::Amharic)
        ->and($someoneElse->refresh()->message_language)->toBeNull()
        ->and($user->refresh()->messageLanguage())->toBe(Language::Amharic);
});

it('refuses a language messages are not written in', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->putJson(route('api.v1.me.language.update'), ['language' => 'fr'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['language']);
});
