<?php

declare(strict_types=1);

use App\Domains\Members\Models\Member;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;

beforeEach(function () {
    $this->gym = Organization::factory()->create(['slug' => 'demo-gym']);
    $this->mainBranch = Branch::factory()->for($this->gym)->create(['name' => 'Main Branch']);
    $this->boleBranch = Branch::factory()->for($this->gym)->create(['name' => 'Bole Branch']);
});

it('lists the members of the gym in alphabetical order', function () {
    Member::factory()->for($this->mainBranch, 'homeBranch')->create(['full_name' => 'Tigist Haile']);
    Member::factory()->for($this->boleBranch, 'homeBranch')->create(['full_name' => 'Abebe Kebede']);
    Member::factory()->create(['full_name' => 'Member Of Another Gym']);
    actingAsStaff($this->gym, StaffRole::Trainer);

    $this->getJson(route('api.v1.organizations.members.index', ['organization' => 'demo-gym']))
        ->assertOk()
        ->assertJsonPath('data.*.full_name', ['Abebe Kebede', 'Tigist Haile']);
});

it('shows members of every branch to staff limited to one branch', function () {
    Member::factory()->for($this->mainBranch, 'homeBranch')->create(['full_name' => 'Tigist Haile']);
    actingAsStaff($this->gym, StaffRole::Receptionist, $this->boleBranch);

    $this->getJson(route('api.v1.organizations.members.index', ['organization' => 'demo-gym']))
        ->assertOk()
        ->assertJsonPath('data.*.full_name', ['Tigist Haile']);
});

it('lists 25 members per page', function () {
    Member::factory()->count(26)->for($this->mainBranch, 'homeBranch')->create();
    actingAsStaff($this->gym, StaffRole::Receptionist);

    $this->getJson(route('api.v1.organizations.members.index', ['organization' => 'demo-gym']))
        ->assertOk()
        ->assertJsonCount(25, 'data')
        ->assertJsonPath('meta.total', 26);
});

it('finds members by part of their name or phone number', function () {
    Member::factory()->for($this->mainBranch, 'homeBranch')->create(['full_name' => 'Abebe Kebede', 'phone' => '0911234567']);
    Member::factory()->for($this->mainBranch, 'homeBranch')->create(['full_name' => 'Tigist Haile', 'phone' => '0922345678']);
    actingAsStaff($this->gym, StaffRole::Receptionist);

    $this->getJson(route('api.v1.organizations.members.index', ['organization' => 'demo-gym', 'search' => 'kebede']))
        ->assertJsonPath('data.*.full_name', ['Abebe Kebede']);

    $this->getJson(route('api.v1.organizations.members.index', ['organization' => 'demo-gym', 'search' => '0922 345']))
        ->assertJsonPath('data.*.full_name', ['Tigist Haile']);
});

it('treats % and _ in a search as plain characters', function () {
    Member::factory()->for($this->mainBranch, 'homeBranch')->create(['full_name' => 'Abebe Kebede']);
    actingAsStaff($this->gym, StaffRole::Receptionist);

    $this->getJson(route('api.v1.organizations.members.index', ['organization' => 'demo-gym', 'search' => '%']))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('registers a member', function () {
    actingAsStaff($this->gym, StaffRole::Receptionist);

    $this->postJson(route('api.v1.organizations.members.store', ['organization' => 'demo-gym']), [
        'full_name' => 'Abebe Kebede',
        'phone' => '0911 23 45 67',
        'email' => 'abebe@example.com',
        'gender' => 'male',
        'date_of_birth' => '1995-04-12',
        'emergency_contact_name' => 'Almaz Kebede',
        'emergency_contact_phone' => '0922 34 56 78',
        'home_branch_id' => $this->mainBranch->id,
    ])
        ->assertCreated()
        ->assertJsonPath('data.full_name', 'Abebe Kebede')
        ->assertJsonPath('data.phone', '+251911234567')
        ->assertJsonPath('data.gender', 'male')
        ->assertJsonPath('data.date_of_birth', '1995-04-12')
        ->assertJsonPath('data.emergency_contact.phone', '+251922345678')
        ->assertJsonPath('data.home_branch.name', 'Main Branch');

    expect(Member::query()->acrossOrganizations()->sole()->organization_id)->toBe($this->gym->id);
});

it('does not let trainers register, edit or archive members', function () {
    $member = Member::factory()->for($this->mainBranch, 'homeBranch')->create();
    actingAsStaff($this->gym, StaffRole::Trainer);

    $this->postJson(route('api.v1.organizations.members.store', ['organization' => 'demo-gym']), [
        'full_name' => 'Abebe Kebede',
        'phone' => '0911234567',
        'home_branch_id' => $this->mainBranch->id,
    ])->assertForbidden();

    $this->patchJson(route('api.v1.organizations.members.update', ['organization' => 'demo-gym', 'member' => $member]), [
        'full_name' => 'New Name',
    ])->assertForbidden();

    $this->deleteJson(route('api.v1.organizations.members.destroy', ['organization' => 'demo-gym', 'member' => $member]))
        ->assertForbidden();
});

it('refuses to register a member at a branch the staff member does not work at', function () {
    actingAsStaff($this->gym, StaffRole::Receptionist, $this->boleBranch);

    $this->postJson(route('api.v1.organizations.members.store', ['organization' => 'demo-gym']), [
        'full_name' => 'Abebe Kebede',
        'phone' => '0911234567',
        'home_branch_id' => $this->mainBranch->id,
    ])->assertUnprocessable()->assertJsonValidationErrors(['home_branch_id']);
});

it('refuses a phone number another member of the gym already has', function () {
    Member::factory()->for($this->mainBranch, 'homeBranch')->create(['phone' => '0911234567']);
    actingAsStaff($this->gym, StaffRole::Receptionist);

    $this->postJson(route('api.v1.organizations.members.store', ['organization' => 'demo-gym']), [
        'full_name' => 'Abebe Kebede',
        'phone' => '+251 911 234 567',
        'home_branch_id' => $this->mainBranch->id,
    ])->assertUnprocessable()->assertJsonValidationErrors(['phone']);
});

it('accepts the phone number of an archived member', function () {
    Member::factory()->for($this->mainBranch, 'homeBranch')->create(['phone' => '0911234567'])->delete();
    actingAsStaff($this->gym, StaffRole::Receptionist);

    $this->postJson(route('api.v1.organizations.members.store', ['organization' => 'demo-gym']), [
        'full_name' => 'Abebe Kebede',
        'phone' => '0911234567',
        'home_branch_id' => $this->mainBranch->id,
    ])->assertCreated();
});

it('reports missing and invalid details', function () {
    actingAsStaff($this->gym, StaffRole::Receptionist);

    $this->postJson(route('api.v1.organizations.members.store', ['organization' => 'demo-gym']), [
        'phone' => '12345',
        'email' => 'not-an-email',
        'gender' => 'unknown',
        'date_of_birth' => 'not-a-date',
        'emergency_contact_name' => 'Almaz Kebede',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'full_name',
            'phone',
            'email',
            'gender',
            'date_of_birth',
            'emergency_contact_phone',
            'home_branch_id',
        ]);
});

it('shows a member of the gym', function () {
    $member = Member::factory()->for($this->mainBranch, 'homeBranch')->create(['full_name' => 'Abebe Kebede']);
    actingAsStaff($this->gym, StaffRole::Trainer);

    $this->getJson(route('api.v1.organizations.members.show', ['organization' => 'demo-gym', 'member' => $member]))
        ->assertOk()
        ->assertJsonPath('data.full_name', 'Abebe Kebede')
        ->assertJsonPath('data.home_branch.name', 'Main Branch');
});

it('cannot reach a member of another gym by their id', function () {
    $otherGymsMember = Member::factory()->create();
    actingAsStaff($this->gym, StaffRole::Owner);

    $this->getJson(route('api.v1.organizations.members.show', ['organization' => 'demo-gym', 'member' => $otherGymsMember]))
        ->assertNotFound();
});

it('edits only the details that are sent', function () {
    $member = Member::factory()->for($this->mainBranch, 'homeBranch')->create([
        'full_name' => 'Abebe Kebede',
        'email' => 'abebe@example.com',
    ]);
    actingAsStaff($this->gym, StaffRole::Manager);

    $this->patchJson(route('api.v1.organizations.members.update', ['organization' => 'demo-gym', 'member' => $member]), [
        'phone' => '0933 45 67 89',
        'home_branch_id' => $this->boleBranch->id,
    ])
        ->assertOk()
        ->assertJsonPath('data.full_name', 'Abebe Kebede')
        ->assertJsonPath('data.email', 'abebe@example.com')
        ->assertJsonPath('data.phone', '+251933456789')
        ->assertJsonPath('data.home_branch.name', 'Bole Branch');
});

it('changes the emergency contact only as a whole', function () {
    $member = Member::factory()->for($this->mainBranch, 'homeBranch')->create([
        'emergency_contact_name' => 'Almaz Kebede',
        'emergency_contact_phone' => '0922345678',
    ]);
    actingAsStaff($this->gym, StaffRole::Receptionist);

    $this->patchJson(route('api.v1.organizations.members.update', ['organization' => 'demo-gym', 'member' => $member]), [
        'emergency_contact_name' => null,
    ])->assertUnprocessable()->assertJsonValidationErrors(['emergency_contact_phone']);

    $this->patchJson(route('api.v1.organizations.members.update', ['organization' => 'demo-gym', 'member' => $member]), [
        'emergency_contact_name' => null,
        'emergency_contact_phone' => null,
    ])->assertOk();

    $member->refresh();

    expect($member->emergency_contact_name)->toBeNull()
        ->and($member->emergency_contact_phone)->toBeNull();
});

it('archives a member, who then leaves the list', function () {
    $member = Member::factory()->for($this->mainBranch, 'homeBranch')->create();
    actingAsStaff($this->gym, StaffRole::Receptionist);

    $this->deleteJson(route('api.v1.organizations.members.destroy', ['organization' => 'demo-gym', 'member' => $member]))
        ->assertNoContent();

    $this->getJson(route('api.v1.organizations.members.index', ['organization' => 'demo-gym']))
        ->assertJsonCount(0, 'data');

    expect(Member::query()->acrossOrganizations()->withTrashed()->sole()->trashed())->toBeTrue();
});
