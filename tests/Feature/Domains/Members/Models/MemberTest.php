<?php

declare(strict_types=1);

use App\Domains\Identity\Models\User;
use App\Domains\Members\Models\Member;
use App\Domains\Notifications\Enums\PushApp;
use App\Domains\Notifications\Models\PushToken;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Shared\Language\Language;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

it('stores phone numbers in international form', function () {
    $member = Member::factory()->create([
        'phone' => '0911 23 45 67',
        'emergency_contact_name' => 'Almaz Kebede',
        'emergency_contact_phone' => '0922-345-678',
    ]);

    expect($member->refresh()->phone)->toBe('+251911234567')
        ->and($member->emergency_contact_phone)->toBe('+251922345678');
});

it('allows only one active member per phone number in a gym', function () {
    $branch = Branch::factory()->create();
    Member::factory()->for($branch, 'homeBranch')->create(['phone' => '0911234567']);

    Member::factory()->for($branch, 'homeBranch')->create(['phone' => '+251 911 234 567']);
})->throws(UniqueConstraintViolationException::class);

it('frees the phone number of an archived member', function () {
    $branch = Branch::factory()->create();
    Member::factory()->for($branch, 'homeBranch')->create(['phone' => '0911234567'])->delete();

    $newcomer = Member::factory()->for($branch, 'homeBranch')->create(['phone' => '0911234567']);

    expect($newcomer->exists)->toBeTrue();
});

it('lets different gyms register the same phone number', function () {
    Member::factory()->create(['phone' => '0911234567']);
    Member::factory()->create(['phone' => '0911234567']);

    expect(Member::query()->acrossOrganizations()->count())->toBe(2);
});

it('refuses a home branch from another gym', function () {
    $organization = Organization::factory()->create();
    $otherGymsBranch = Branch::factory()->create();

    Member::factory()->for($organization)->for($otherGymsBranch, 'homeBranch')->create();
})->throws(QueryException::class, 'violates foreign key constraint');

it('keeps the emergency contact name and phone together', function () {
    Member::factory()->create(['emergency_contact_name' => 'Almaz Kebede']);
})->throws(QueryException::class, 'violates check constraint');

it('writes to a member in their own language, else their gym\'s', function () {
    $organization = Organization::factory()->create(['message_language' => 'am']);
    $branch = Branch::factory()->for($organization)->create();

    $followsTheGym = Member::factory()->for($organization)->for($branch, 'homeBranch')->create();
    $choseEnglish = Member::factory()->for($organization)->for($branch, 'homeBranch')->create(['message_language' => 'en']);

    expect($followsTheGym->messageLanguage())->toBe(Language::Amharic)
        ->and($choseEnglish->messageLanguage())->toBe(Language::English);
});

it('refuses a message language it cannot write in', function () {
    $member = Member::factory()->create();

    DB::table('members')->where('id', $member->id)->update(['message_language' => 'fr']);
})->throws(QueryException::class, 'violates check constraint');

it('pushes to the members app only', function () {
    $member = Member::factory()->create(['phone' => '0922 34 56 78']);
    $user = User::factory()->withPhone('0922 34 56 78')->create();
    PushToken::register($user->id, $user->createToken('members app')->accessToken->id, 'members-app-token', null);
    PushToken::register($user->id, $user->createToken('staff app')->accessToken->id, 'staff-app-token', null, PushApp::Staff);

    expect($member->routeNotificationForPush()->pluck('token')->all())->toBe(['members-app-token']);
});
