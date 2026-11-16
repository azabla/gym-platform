<?php

declare(strict_types=1);

use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Models\Payment;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Shared\Money\Money;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-13 10:00:00', 'Africa/Addis_Ababa'));

    $this->gym = Organization::factory()->create(['slug' => 'demo-gym']);
    $this->main = Branch::factory()->for($this->gym)->create(['name' => 'Main Branch']);
    $this->bole = Branch::factory()->for($this->gym)->create(['name' => 'Bole Branch']);
    $monthly = Plan::factory()->for($this->gym)->create(['name' => 'Monthly', 'price' => Money::of('2,500')]);

    $hana = Member::factory()->for($this->main, 'homeBranch')->create(['full_name' => 'ሐና ግርማ', 'phone' => '0911223344']);
    $hanas = $this->hanas = Membership::factory()->for($hana)->for($monthly)->create();
    $this->cash = Payment::factory()->for($hanas)->create([
        'amount' => Money::of('1,000'), 'method' => PaymentMethod::Cash, 'branch_id' => $this->main->id, 'paid_on' => '2026-11-02',
    ]);
    $this->voided = Payment::factory()->for($hanas)->voided()->create([
        'amount' => Money::of('300'), 'method' => PaymentMethod::Cash, 'branch_id' => $this->main->id, 'paid_on' => '2026-11-03',
    ]);

    $abebe = Member::factory()->for($this->bole, 'homeBranch')->create(['full_name' => 'Abebe Bekele']);
    $abebes = Membership::factory()->for($abebe)->for($monthly)->create();
    $this->telebirr = Payment::factory()->for($abebes)->create([
        'amount' => Money::of('2,500'), 'method' => PaymentMethod::Telebirr, 'reference' => 'TB123', 'branch_id' => $this->bole->id, 'paid_on' => '2026-11-05',
    ]);
    Payment::factory()->for($abebes)->create(['amount' => Money::of('100'), 'branch_id' => $this->bole->id, 'paid_on' => '2026-10-20']);
});

function paymentsReport(array $query = []): string
{
    return route('api.v1.organizations.reports.payments', ['organization' => 'demo-gym', ...$query]);
}

it('downloads the payments of a period as a CSV file, oldest first', function () {
    actingAsStaff($this->gym, StaffRole::Accountant);

    $response = $this->get(paymentsReport(['from' => '2026-11-01', 'to' => '2026-11-30']))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->assertDownload('demo-gym-payments-2026-11-01-to-2026-11-30.csv');

    $rows = csvRowsOf($response);

    expect($rows[0])->toBe([
        'Receipt', 'Paid on', 'Type', 'Member', 'Phone', 'Plan', 'Amount', 'Currency',
        'Method', 'Reference', 'Branch', 'Received by', 'Voided at', 'Void reason',
    ])
        ->and(array_column(array_slice($rows, 1), 1))->toBe(['2026-11-02', '2026-11-03', '2026-11-05'])
        ->and(array_slice($rows[1], 2, 10))->toBe(['Payment', 'ሐና ግርማ', '+251911223344', 'Monthly', '1000.00', 'ETB', 'Cash', '', 'Main Branch', $this->cash->receivedBy->user->name])
        ->and($rows[2][12])->not->toBe('')
        ->and(array_slice($rows[3], 8, 2))->toBe(['telebirr', 'TB123']);
});

it('writes refunds as negative amounts', function () {
    actingAsStaff($this->gym, StaffRole::Owner);
    Payment::factory()->for($this->hanas)->refund()->create(['amount' => Money::of('400'), 'branch_id' => $this->main->id, 'paid_on' => '2026-11-13']);

    $rows = csvRowsOf($this->get(paymentsReport())->assertOk());

    expect($rows[1][2])->toBe('Refund')
        ->and($rows[1][6])->toBe('-400.00');
});

it('narrows to a branch or a method', function () {
    actingAsStaff($this->gym, StaffRole::Owner);

    $atBole = csvRowsOf($this->get(paymentsReport(['from' => '2026-11-01', 'to' => '2026-11-30', 'branch_id' => $this->bole->id])));
    $cash = csvRowsOf($this->get(paymentsReport(['from' => '2026-11-01', 'to' => '2026-11-30', 'method' => 'cash'])));

    expect(array_column(array_slice($atBole, 1), 0))->toBe([(string) $this->telebirr->receipt_number])
        ->and(array_column(array_slice($cash, 1), 0))->toBe([(string) $this->cash->receipt_number, (string) $this->voided->receipt_number]);
});

it('covers only the branches an accountant limited to some works at', function () {
    actingAsStaff($this->gym, StaffRole::Accountant, $this->bole);

    $rows = csvRowsOf($this->get(paymentsReport(['from' => '2026-11-01', 'to' => '2026-11-30'])));

    expect(array_column(array_slice($rows, 1), 10))->toBe(['Bole Branch']);

    $this->getJson(paymentsReport(['branch_id' => $this->main->id]))->assertUnprocessable()->assertJsonValidationErrors(['branch_id']);
});

it('keeps the report to the roles that see money', function (StaffRole $role) {
    actingAsStaff($this->gym, $role);

    $this->getJson(paymentsReport())->assertForbidden();
})->with([StaffRole::Receptionist, StaffRole::Trainer]);

it('refuses a period longer than a year', function () {
    actingAsStaff($this->gym, StaffRole::Owner);

    $this->getJson(paymentsReport(['from' => '2025-11-01', 'to' => '2026-11-13']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['to' => 'The period can be at most a year long.']);
});
