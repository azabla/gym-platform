<?php

declare(strict_types=1);

use App\Domains\Billing\Models\Payment;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;
use App\Filament\Staff\PanelUser;
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
