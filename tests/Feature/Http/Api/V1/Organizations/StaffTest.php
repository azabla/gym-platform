<?php

declare(strict_types=1);

use App\Domains\Billing\Models\Payment;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Notifications\PasswordLinkNotification;
use App\Domains\Identity\Notifications\StaffAddedNotification;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;
use App\Filament\Staff\PanelUser;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->gym = Organization::factory()->create(['slug' => 'demo-gym']);
    $this->mainBranch = Branch::factory()->for($this->gym)->create(['name' => 'Main Branch']);
    $this->boleBranch = Branch::factory()->for($this->gym)->create(['name' => 'Bole Branch']);
});

it('locks archived staff out of the gym at once, in the API and the panel, but not out of their other gym', function () {
    $hana = Staff::factory()->for($this->gym)->create(['role' => StaffRole::Receptionist]);
    Staff::factory()->for($hana->user)->for(Organization::factory()->create(['slug' => 'other-gym']))->create();
    Sanctum::actingAs($hana->user);

    $this->getJson(route('api.v1.organizations.branches.index', ['organization' => 'demo-gym']))->assertOk();

    actingAsOrganization($this->gym);
    $hana->archive();

    $this->getJson(route('api.v1.organizations.branches.index', ['organization' => 'demo-gym']))->assertNotFound();
    $this->getJson(route('api.v1.organizations.branches.index', ['organization' => 'other-gym']))->assertOk();

    $this->actingAs(PanelUser::query()->findOrFail($hana->user_id), 'staff_panel')
        ->get(route('filament.staff.pages.dashboard', ['tenant' => 'demo-gym']))
        ->assertNotFound();
});

it('still names archived staff on the payments they took', function () {
    actingAsOrganization($this->gym);
    $hana = Staff::factory()->for($this->gym)->create(['role' => StaffRole::Receptionist]);
    $hana->user->update(['name' => 'Hana Tesfaye']);
    $member = Member::factory()->for($this->mainBranch, 'homeBranch')->create();
    $membership = Membership::factory()->for($member)->for(Plan::factory()->for($this->gym))->create();
    $payment = Payment::factory()->for($membership)->create(['received_by_staff_id' => $hana->id, 'branch_id' => $this->mainBranch->id]);
    $hana->archive();

    actingAsStaff($this->gym, StaffRole::Owner);

    $this->getJson(route('api.v1.organizations.payments.show', ['organization' => 'demo-gym', 'payment' => $payment]))
        ->assertOk()
        ->assertJsonPath('data.received_by', ['id' => $hana->id, 'name' => 'Hana Tesfaye']);
});

/**
 * Add someone to the demo gym's staff, as the signed-in staff member.
 *
 * @param  array<string, mixed>  $details
 */
function addStaff(array $details): TestResponse
{
    return test()->postJson(route('api.v1.organizations.staff.store', ['organization' => 'demo-gym']), [
        'name' => 'Selam Tadesse',
        'phone' => '0944 55 66 77',
        ...$details,
    ]);
}

function staffUrl(Staff $staff, string $action = 'show'): string
{
    return route("api.v1.organizations.staff.{$action}", ['organization' => 'demo-gym', 'staff' => $staff]);
}

it('lets an owner add a manager by phone, who gets an invite by SMS naming the gym', function () {
    Notification::fake();
    $this->gym->update(['name' => 'Demo Gym']);
    actingAsStaff($this->gym, StaffRole::Owner);

    addStaff(['role' => 'manager'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Selam Tadesse')
        ->assertJsonPath('data.phone', '+251944556677')
        ->assertJsonPath('data.role', 'manager')
        ->assertJsonPath('data.works_at_all_branches', true)
        ->assertJsonPath('data.invite_pending', true);

    $selam = User::query()->where('phone', '+251944556677')->sole();
    Notification::assertSentTo($selam, PasswordLinkNotification::class, fn (PasswordLinkNotification $notification): bool => str_contains($notification->toSms($selam), 'Demo Gym added you'));
});

it('keeps someone who is already a member as the same user, with their own name', function () {
    Notification::fake();
    $member = User::factory()->withPhone('0944 55 66 77')->create(['name' => 'Selam T.']);
    actingAsStaff($this->gym, StaffRole::Owner);

    addStaff(['role' => 'trainer'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Selam T.');

    expect(User::query()->where('phone', '+251944556677')->count())->toBe(1);
    actingAsOrganization($this->gym);
    expect(Staff::query()->where('role', StaffRole::Trainer)->sole()->user_id)->toBe($member->id);
});

it('lets a manager add receptionists, trainers and accountants at their own branches only', function () {
    Notification::fake();
    actingAsStaff($this->gym, StaffRole::Manager, $this->mainBranch);

    addStaff(['role' => 'receptionist', 'branch_ids' => [$this->mainBranch->id]])
        ->assertCreated()
        ->assertJsonPath('data.branches.0.name', 'Main Branch');

    addStaff(['phone' => '0955 00 00 01', 'role' => 'manager', 'branch_ids' => [$this->mainBranch->id]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['role' => 'You can\'t give someone this role.']);
    addStaff(['phone' => '0955 00 00 02', 'role' => 'owner'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('role');
    addStaff(['phone' => '0955 00 00 03', 'role' => 'trainer', 'branch_ids' => [$this->boleBranch->id]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['branch_ids' => 'Choose only branches you work at.']);
    addStaff(['phone' => '0955 00 00 04', 'role' => 'trainer'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('branch_ids');
});

it('refuses another gym\'s branch, and someone who already works at the gym', function () {
    Notification::fake();
    $otherGymsBranch = Branch::factory()->create();
    $staff = Staff::factory()->for($this->gym)->create();
    $staff->user->update(['phone' => '0944 55 66 77']);
    actingAsStaff($this->gym, StaffRole::Owner);

    addStaff(['role' => 'trainer'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['phone' => 'This person already works at the gym.']);
    addStaff(['phone' => '0955 00 00 01', 'role' => 'trainer', 'branch_ids' => [$otherGymsBranch->id]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['branch_ids' => 'Choose branches of this gym.']);
});

it('keeps staff lists to owners and managers, and a limited manager sees only their branches', function () {
    actingAsOrganization($this->gym);
    $atBole = Staff::factory()->for($this->gym)->create();
    $atBole->assignToBranches($this->boleBranch);

    $receptionist = actingAsStaff($this->gym, StaffRole::Receptionist);
    $this->getJson(route('api.v1.organizations.staff.index', ['organization' => 'demo-gym']))->assertForbidden();
    $this->getJson(staffUrl($atBole))->assertForbidden();
    addStaff(['role' => 'trainer'])->assertForbidden();

    $manager = actingAsStaff($this->gym, StaffRole::Manager, $this->mainBranch);
    $this->getJson(route('api.v1.organizations.staff.index', ['organization' => 'demo-gym']))
        ->assertOk()
        ->assertJsonPath('data.*.id', [$receptionist->id, $manager->id]);
    $this->getJson(staffUrl($atBole))->assertForbidden();

    $owner = actingAsStaff($this->gym, StaffRole::Owner);
    $this->getJson(route('api.v1.organizations.staff.index', ['organization' => 'demo-gym']))
        ->assertJsonPath('data.*.id', [$atBole->id, $receptionist->id, $manager->id, $owner->id]);
});

it('changes a role or branches under the same rules', function () {
    actingAsOrganization($this->gym);
    $receptionist = Staff::factory()->for($this->gym)->create();
    $receptionist->assignToBranches($this->mainBranch);
    $otherManager = Staff::factory()->for($this->gym)->create(['role' => StaffRole::Manager]);
    $me = actingAsStaff($this->gym, StaffRole::Manager, $this->mainBranch);

    $this->patchJson(staffUrl($receptionist, 'update'), ['role' => 'accountant'])
        ->assertOk()
        ->assertJsonPath('data.role', 'accountant')
        ->assertJsonPath('data.branches.0.name', 'Main Branch');
    $this->patchJson(staffUrl($receptionist, 'update'), ['branch_ids' => [$this->boleBranch->id]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('branch_ids');
    $this->patchJson(staffUrl($receptionist, 'update'), ['role' => 'manager'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('role');
    $this->patchJson(staffUrl($otherManager, 'update'), ['role' => 'trainer'])->assertForbidden();
    $this->patchJson(staffUrl($me, 'update'), ['role' => 'trainer'])->assertForbidden();

    actingAsStaff($this->gym, StaffRole::Owner);
    $this->patchJson(staffUrl($receptionist, 'update'), ['role' => 'manager', 'branch_ids' => []])
        ->assertOk()
        ->assertJsonPath('data.role', 'manager')
        ->assertJsonPath('data.works_at_all_branches', true);
});

it('never lets the last owner be demoted or archived, nor anyone change themselves', function () {
    actingAsOrganization($this->gym);
    $owner = Staff::factory()->for($this->gym)->owner()->create();
    $manager = actingAsStaff($this->gym, StaffRole::Manager);

    $this->deleteJson(staffUrl($owner, 'destroy'))->assertForbidden();

    $secondOwner = actingAsStaff($this->gym, StaffRole::Owner);
    $this->deleteJson(staffUrl($secondOwner, 'destroy'))->assertForbidden();
    $this->deleteJson(staffUrl($owner, 'destroy'))->assertNoContent();

    actingAsOrganization($this->gym);
    $thirdOwner = Staff::factory()->for($this->gym)->owner()->create();
    $this->patchJson(staffUrl($thirdOwner, 'update'), ['role' => 'manager'])->assertOk();

    $this->patchJson(staffUrl($secondOwner, 'update'), ['role' => 'manager'])->assertForbidden();
    $this->deleteJson(staffUrl($secondOwner, 'destroy'))->assertForbidden();

    actingAsOrganization($this->gym);
    expect(Staff::query()->where('role', StaffRole::Owner)->pluck('id')->all())->toBe([$secondOwner->id])
        ->and($manager->fresh()->role)->toBe(StaffRole::Manager);
});

it('archives and restores staff, and lists the archived ones apart', function () {
    actingAsOrganization($this->gym);
    $trainer = Staff::factory()->for($this->gym)->create(['role' => StaffRole::Trainer]);
    $owner = actingAsStaff($this->gym, StaffRole::Owner);

    $this->deleteJson(staffUrl($trainer, 'destroy'))->assertNoContent();
    $this->getJson(staffUrl($trainer))->assertNotFound();
    $this->getJson(route('api.v1.organizations.staff.index', ['organization' => 'demo-gym']))
        ->assertJsonPath('data.*.id', [$owner->id]);
    $this->getJson(route('api.v1.organizations.staff.index', ['organization' => 'demo-gym', 'archived' => 1]))
        ->assertJsonPath('data.*.id', [$trainer->id])
        ->assertJsonPath('data.0.archived_at', fn (?string $archivedAt): bool => $archivedAt !== null);

    $this->postJson(route('api.v1.organizations.staff.restore', ['organization' => 'demo-gym', 'staff' => $trainer]))
        ->assertOk()
        ->assertJsonPath('data.archived_at', null);
    $this->getJson(staffUrl($trainer))->assertOk();
});

it('restores someone archived when they are added again', function () {
    Notification::fake();
    actingAsOrganization($this->gym);
    $trainer = Staff::factory()->for($this->gym)->create(['role' => StaffRole::Trainer]);
    $trainer->user->update(['phone' => '0944 55 66 77']);
    $trainer->delete();
    actingAsStaff($this->gym, StaffRole::Owner);

    addStaff(['role' => 'receptionist'])
        ->assertCreated()
        ->assertJsonPath('data.id', $trainer->id)
        ->assertJsonPath('data.role', 'receptionist');
});

it('sends the invite again with a new link, and only tells staff who have a password that they were added', function () {
    Notification::fake();
    actingAsOrganization($this->gym);
    $invited = Staff::factory()->for($this->gym)->create(['user_id' => User::factory()->withPhone('0944 55 66 77')]);
    $signedUp = Staff::factory()->for($this->gym)->create(['user_id' => User::factory()->create(['phone' => '0955 00 00 01'])]);
    actingAsStaff($this->gym, StaffRole::Owner);

    $this->postJson(staffUrl($invited, 'invite'))->assertAccepted();
    $this->postJson(staffUrl($signedUp, 'invite'))->assertAccepted();

    Notification::assertSentTo($invited->user, PasswordLinkNotification::class);
    Notification::assertSentTo($signedUp->user, StaffAddedNotification::class);
    $this->getJson(staffUrl($invited))->assertJsonPath('data.invite_pending', true);
    $this->getJson(staffUrl($signedUp))->assertJsonPath('data.invite_pending', false);
});
