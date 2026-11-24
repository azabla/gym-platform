<?php

declare(strict_types=1);

use App\Domains\Members\Models\Member;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    config(['filesystems.photos_disk' => 'local']);

    $this->gym = Organization::factory()->create(['slug' => 'demo-gym']);
    $this->branch = Branch::factory()->for($this->gym)->create();
    $this->member = Member::factory()->for($this->branch, 'homeBranch')->create();
});

/**
 * A photo as the staff app or the members app would send it.
 */
function uploadedPhoto(int $width = 600, int $height = 800): UploadedFile
{
    return UploadedFile::fake()->createWithContent('photo.jpg', photoBytes($width, $height));
}

it('lets the desk take a member\'s photo, and gives a short link to it', function () {
    actingAsStaff($this->gym, StaffRole::Receptionist);

    $this->put(route('api.v1.organizations.members.photo.update', ['organization' => 'demo-gym', 'member' => $this->member]), ['photo' => uploadedPhoto()], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('data.id', $this->member->id)
        ->assertJsonPath('data.photo_url', fn (?string $url) => is_string($url) && str_contains($url, 'member-photos'));

    expect(Storage::disk('local')->exists((string) $this->member->refresh()->photo_path))->toBeTrue();

    $this->getJson(route('api.v1.organizations.members.show', ['organization' => 'demo-gym', 'member' => $this->member]))
        ->assertJsonPath('data.photo_url', fn (?string $url) => is_string($url));

    $this->deleteJson(route('api.v1.organizations.members.photo.destroy', ['organization' => 'demo-gym', 'member' => $this->member]))
        ->assertNoContent();

    expect($this->member->refresh()->photo_path)->toBeNull()
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('keeps trainers from changing photos', function () {
    actingAsStaff($this->gym, StaffRole::Trainer);

    $this->put(route('api.v1.organizations.members.photo.update', ['organization' => 'demo-gym', 'member' => $this->member]), ['photo' => uploadedPhoto()], ['Accept' => 'application/json'])
        ->assertForbidden();
    $this->deleteJson(route('api.v1.organizations.members.photo.destroy', ['organization' => 'demo-gym', 'member' => $this->member]))
        ->assertForbidden();
});

it('refuses a file that isn\'t a photo, one too large, and one too small', function (UploadedFile $file) {
    actingAsStaff($this->gym, StaffRole::Owner);

    $this->put(route('api.v1.organizations.members.photo.update', ['organization' => 'demo-gym', 'member' => $this->member]), ['photo' => $file], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['photo']);

    expect($this->member->refresh()->photo_path)->toBeNull();
})->with([
    'a PDF' => fn () => UploadedFile::fake()->create('contract.pdf', 100, 'application/pdf'),
    'over 5 MB' => fn () => UploadedFile::fake()->image('huge.jpg', 600, 600)->size(6000),
    'too small' => fn () => uploadedPhoto(120, 120),
]);

it('shows the member\'s photo with a check-in at the desk', function () {
    actingAsStaff($this->gym, StaffRole::Receptionist);
    $this->member->forceFill(['photo_path' => 'member-photos/1/hana.jpg'])->save();

    $this->postJson(route('api.v1.organizations.members.check-ins.store', ['organization' => 'demo-gym', 'member' => $this->member]), ['branch_id' => $this->branch->id])
        ->assertOk()
        ->assertJsonPath('data.member.photo_url', fn (?string $url) => is_string($url) && str_contains($url, 'hana.jpg'));
});
