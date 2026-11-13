<?php

declare(strict_types=1);

use App\Domains\Attendance\Models\CheckIn;
use App\Domains\Billing\Models\Payment;
use App\Domains\Billing\Models\PaymentLink;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;

beforeEach(function () {
    $organization = actingAsOrganization(Organization::factory()->create());
    $this->main = Branch::factory()->for($organization)->create();
    $this->bole = Branch::factory()->for($organization)->create();

    $this->hana = Member::factory()->for($this->main, 'homeBranch')->create();
    $this->abebe = Member::factory()->for($this->bole, 'homeBranch')->create();
    $this->hanas = Membership::factory()->for($this->hana)->create();
    $this->abebes = Membership::factory()->for($this->abebe)->create();
});

it('limits members by home branch, archived ones included in memberships', function () {
    $this->abebe->delete();

    expect(Member::query()->homeAtAnyOf([$this->main->id])->pluck('id')->all())->toBe([$this->hana->id])
        ->and(Member::query()->homeAtAnyOf(null)->count())->toBe(1)
        ->and(Membership::query()->ofMembersAtAnyOf([$this->bole->id])->pluck('id')->all())->toBe([$this->abebes->id])
        ->and(Membership::query()->ofMembersAtAnyOf(null)->count())->toBe(2);
});

it('limits payments, links and check-ins by the branch where they happened', function () {
    // Hana, of Main, pays and comes in at Bole: those records are Bole's.
    $atBole = Payment::factory()->for($this->hanas)->create(['branch_id' => $this->bole->id]);
    Payment::factory()->for($this->abebes)->create(['branch_id' => $this->main->id]);
    $boleLink = PaymentLink::factory()->for($this->hanas)->create(['branch_id' => $this->bole->id]);
    PaymentLink::factory()->for($this->abebes)->create(['branch_id' => $this->main->id]);
    $boleVisit = CheckIn::factory()->for($this->hana)->for($this->bole)->create();
    CheckIn::factory()->for($this->abebe)->for($this->main)->create();

    expect(Payment::query()->takenAtAnyOf([$this->bole->id])->pluck('id')->all())->toBe([$atBole->id])
        ->and(PaymentLink::query()->forAnyOf([$this->bole->id])->pluck('id')->all())->toBe([$boleLink->id])
        ->and(CheckIn::query()->madeAtAnyOf([$this->bole->id])->pluck('id')->all())->toBe([$boleVisit->id])
        ->and(Payment::query()->takenAtAnyOf(null)->count())->toBe(2)
        ->and(CheckIn::query()->madeAtAnyOf([])->count())->toBe(0);
});
