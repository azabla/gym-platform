<?php

declare(strict_types=1);

use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Filament\Staff\Resources\CheckIns\Pages\ListCheckIns;
use App\Filament\Staff\Resources\Members\Pages\ListMembers;
use App\Filament\Staff\Resources\Members\Pages\ViewMember;
use App\Filament\Staff\Support\Photos;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    config(['filesystems.photos_disk' => 'local']);

    $this->gym = Organization::factory()->create();
    $this->branch = Branch::factory()->for($this->gym)->create();
    $this->member = Member::factory()->for($this->branch, 'homeBranch')->create(['full_name' => 'Hana Girma']);
});

it('lets the desk add a member\'s photo, and remove it', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Receptionist);

    Livewire::test(ViewMember::class, ['record' => $this->member->id])
        ->assertActionHasLabel('photo', 'Add photo')
        ->assertActionHidden('removePhoto')
        ->callAction('photo', ['photo' => UploadedFile::fake()->createWithContent('hana.jpg', photoBytes(600, 600))])
        ->assertHasNoActionErrors()
        ->assertNotified('Photo saved.');

    expect($this->member->refresh()->photo_path)->not->toBeNull();

    Livewire::test(ViewMember::class, ['record' => $this->member->id])
        ->assertActionHasLabel('photo', 'Change photo')
        ->callAction('removePhoto')
        ->assertNotified('Photo removed.');

    expect($this->member->refresh()->photo_path)->toBeNull()
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('refuses an image too small to show the person', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Owner);

    Livewire::test(ViewMember::class, ['record' => $this->member->id])
        ->callAction(TestAction::make('photo'), ['photo' => UploadedFile::fake()->createWithContent('tiny.jpg', photoBytes(120, 120))])
        ->assertNotified('The photo must be at least 200 pixels wide and high.');

    expect($this->member->refresh()->photo_path)->toBeNull();
});

it('keeps trainers from changing photos', function () {
    $this->member->forceFill(['photo_path' => 'member-photos/1/hana.jpg'])->save();
    actingAsStaffInPanel($this->gym, StaffRole::Trainer);

    Livewire::test(ViewMember::class, ['record' => $this->member->id])
        ->assertActionHidden('photo')
        ->assertActionHidden('removePhoto');
});

it('shows the photo, or the initials, in the members and check-ins lists', function () {
    $this->member->forceFill(['photo_path' => 'member-photos/1/hana.jpg'])->save();
    Member::factory()->for($this->branch, 'homeBranch')->create(['full_name' => 'Abebe Kebede']);
    actingAsStaffInPanel($this->gym, StaffRole::Receptionist);

    Livewire::test(ListMembers::class)
        ->assertOk()
        ->assertSee('hana.jpg', escape: false)
        ->assertSee(Photos::initials('Abebe Kebede'), escape: false);

    Livewire::test(ListCheckIns::class)->assertOk();
});

it('draws two initials from a name', function () {
    $svg = base64_decode(substr(Photos::initials('Hana Tesfaye Girma'), strlen('data:image/svg+xml;base64,')));

    expect($svg)->toContain('>HG</text>');
});

it('shows the member\'s photo when the desk lets them in', function () {
    $this->freezeTime();
    $this->member->forceFill(['photo_path' => 'member-photos/1/hana.jpg'])->save();
    Membership::factory()->for($this->member)->create(['starts_on' => now()->subDays(3)->toDateString(), 'ends_on' => now()->addDays(20)->toDateString()]);
    actingAsStaffInPanel($this->gym, StaffRole::Receptionist, $this->branch);

    Livewire::test(ViewMember::class, ['record' => $this->member->id])
        ->callAction('checkIn', ['branch_id' => $this->branch->id])
        ->assertNotified(
            Notification::make()
                ->success()
                ->title('Hana Girma is let in.')
                ->body(sprintf('<img src="%s" alt="Hana Girma" width="96" height="96">', e(Photos::urlFor($this->member->refresh())))),
        );
});
