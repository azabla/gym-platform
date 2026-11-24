<?php

declare(strict_types=1);

use App\Domains\Members\Models\Member;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    config(['filesystems.photos_disk' => 'local']);

    $demoGym = Organization::factory()->create(['slug' => 'demo-gym']);
    $this->atDemoGym = Member::factory()->for(Branch::factory()->for($demoGym), 'homeBranch')->create(['phone' => '0922 34 56 78']);
    $this->atOtherGym = Member::factory()->create(['phone' => '0922 34 56 78']);
    $this->someoneElse = Member::factory()->create(['phone' => '0911 22 33 44']);
});

it('sets my photo at every gym where my phone is registered, and shows it with my gyms', function () {
    actingAsMember($this->atDemoGym);

    $this->put(route('api.v1.me.photo.update'), ['photo' => UploadedFile::fake()->createWithContent('me.jpg', photoBytes(800, 600))], ['Accept' => 'application/json'])
        ->assertNoContent();

    expect($this->atDemoGym->refresh()->photo_path)->not->toBeNull()
        ->and($this->atOtherGym->refresh()->photo_path)->not->toBeNull()
        ->and($this->atOtherGym->photo_path)->not->toBe($this->atDemoGym->photo_path)
        ->and($this->someoneElse->refresh()->photo_path)->toBeNull();

    $this->getJson(route('api.v1.me.gyms.index'))
        ->assertOk()
        ->assertJsonPath('data.0.member.photo_url', fn (?string $url) => is_string($url) && str_contains($url, 'member-photos'))
        ->assertJsonPath('data.1.member.photo_url', fn (?string $url) => is_string($url));
});

it('takes my photo away at every gym', function () {
    actingAsMember($this->atDemoGym);
    $this->put(route('api.v1.me.photo.update'), ['photo' => UploadedFile::fake()->createWithContent('me.jpg', photoBytes(400, 400))], ['Accept' => 'application/json']);

    $this->deleteJson(route('api.v1.me.photo.destroy'))->assertNoContent();

    expect($this->atDemoGym->refresh()->photo_path)->toBeNull()
        ->and($this->atOtherGym->refresh()->photo_path)->toBeNull()
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('refuses a file that isn\'t a photo', function () {
    actingAsMember($this->atDemoGym);

    $this->put(route('api.v1.me.photo.update'), ['photo' => UploadedFile::fake()->create('cv.pdf', 50, 'application/pdf')], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['photo']);
});
