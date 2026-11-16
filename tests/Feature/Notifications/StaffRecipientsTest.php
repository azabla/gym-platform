<?php

declare(strict_types=1);

use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;
use App\Notifications\StaffRecipients;

beforeEach(function () {
    $gym = actingAsOrganization(Organization::factory()->create());
    $this->main = Branch::factory()->for($gym)->create();
    $this->bole = Branch::factory()->for($gym)->create();

    $this->owner = Staff::factory()->for($gym)->owner()->create();
    $this->accountant = Staff::factory()->for($gym)->create(['role' => StaffRole::Accountant]);
    $this->mainReceptionist = Staff::factory()->for($gym)->create(['role' => StaffRole::Receptionist]);
    $this->mainReceptionist->assignToBranches($this->main);
    $this->boleManager = Staff::factory()->for($gym)->create(['role' => StaffRole::Manager]);
    $this->boleManager->assignToBranches($this->bole);
    Staff::factory()->for($gym)->create(['role' => StaffRole::Trainer]);
    $archived = Staff::factory()->for($gym)->create(['role' => StaffRole::Manager]);
    $archived->archive();
});

it('sends money news to owners, managers and accountants at the branch', function () {
    expect(StaffRecipients::forMoneyAt($this->main)->modelKeys())->toBe([$this->owner->id, $this->accountant->id])
        ->and(StaffRecipients::forMoneyAt($this->bole)->modelKeys())->toBe([$this->owner->id, $this->accountant->id, $this->boleManager->id]);
});

it('sends member news to owners, managers and receptionists at the member\'s branch', function () {
    expect(StaffRecipients::forMembersAt($this->main)->modelKeys())->toBe([$this->owner->id, $this->mainReceptionist->id])
        ->and(StaffRecipients::forMembersAnywhere()->modelKeys())->toBe([$this->owner->id, $this->mainReceptionist->id, $this->boleManager->id]);
});
