<?php

declare(strict_types=1);

use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Models\Payment;
use App\Domains\Imports\Enums\MemberImportRowOutcome;
use App\Domains\Imports\Enums\MemberImportStatus;
use App\Domains\Imports\MemberImporter;
use App\Domains\Imports\Models\MemberImport;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Enums\DurationUnit;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Domains\Shared\Money\Money;
use App\Jobs\ImportMembers;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-27 10:00', 'Africa/Addis_Ababa'));
    $this->gym = actingAsOrganization(Organization::factory()->create(['timezone' => 'Africa/Addis_Ababa']));
    $this->main = Branch::factory()->for($this->gym)->create(['name' => 'Main Branch']);
    Plan::factory()->for($this->gym)->create(['name' => 'Monthly', 'price' => Money::of('2,500'), 'duration_count' => 1, 'duration_unit' => DurationUnit::Month]);
    $this->owner = Staff::factory()->for($this->gym)->owner()->create();
});

/**
 * Check the given rows under the template's header.
 *
 * @param  list<string>  $lines
 */
function importFile(array $lines): MemberImport
{
    $csv = "full_name,phone,gender,date_of_birth,home_branch,plan,starts_on,ends_on,amount_paid\n".implode("\n", $lines)."\n";

    return MemberImport::check(test()->owner, 'members.csv', $csv, CarbonImmutable::parse('2026-11-27'));
}

it('brings in each ready row: the member, their membership and what they paid, telling nobody', function () {
    $import = importFile([
        'Hana Girma,0911223344,female,1995-04-12,Main Branch,Monthly,2026-11-01,,1500',
        'Selam Tadesse,0911223355,,,Main Branch,,,,',
        'Abebe,12,,,Main Branch,,,,',
    ]);

    ImportMembers::dispatch($import);

    $hana = Member::query()->where('phone', '+251911223344')->sole();
    $membership = Membership::query()->whereBelongsTo($hana)->sole();
    $payment = Payment::query()->sole();

    expect($import->fresh()?->only(['status', 'added_count', 'error_count', 'ready_count']))->toBe(['status' => MemberImportStatus::Imported, 'added_count' => 2, 'error_count' => 1, 'ready_count' => 0])
        ->and($hana->full_name)->toBe('Hana Girma')
        ->and($hana->date_of_birth?->toDateString())->toBe('1995-04-12')
        ->and($membership->only(['starts_on', 'ends_on']))->toEqual(['starts_on' => CarbonImmutable::parse('2026-11-01'), 'ends_on' => CarbonImmutable::parse('2026-11-30')])
        ->and($payment->method)->toBe(PaymentMethod::BroughtIn)
        ->and($payment->paid_on->toDateString())->toBe('2026-11-01')
        ->and($payment->received_by_staff_id)->toBe($this->owner->id)
        ->and(Payment::balanceOf($membership)->balanceDue->format())->toBe('1,000.00 ETB')
        ->and(Member::query()->where('phone', '+251911223355')->exists())->toBeTrue()
        ->and($import->rows()->where('outcome', MemberImportRowOutcome::Added)->whereNotNull('member_id')->count())->toBe(2);
    Notification::assertNothingSent();
});

it('changes nothing when the same file is imported again', function () {
    ImportMembers::dispatch(importFile(['Hana Girma,0911223344,,,Main Branch,Monthly,2026-11-01,,1500']));
    $again = importFile(['Hana Girma,0911223344,,,Main Branch,Monthly,2026-11-01,,1500']);

    ImportMembers::dispatch($again);

    expect($again->fresh()?->duplicate_count)->toBe(1)
        ->and(Member::query()->count())->toBe(1)
        ->and(Payment::query()->count())->toBe(1);
});

it('marks a row failed when the gym changed since the check, and goes on with the others', function () {
    $import = importFile([
        'Hana Girma,0911223344,,,Main Branch,Monthly,2026-11-01,,',
        'Selam Tadesse,0911223355,,,Main Branch,Monthly,2026-11-01,,',
    ]);
    Plan::query()->where('name', 'Monthly')->sole()->delete();
    Member::factory()->for($this->main, 'homeBranch')->create(['phone' => '+251911223355']);

    ImportMembers::dispatch($import);

    $rows = $import->rows()->orderBy('line_number')->get();

    expect($rows->pluck('outcome')->all())->toBe([MemberImportRowOutcome::Failed, MemberImportRowOutcome::Duplicate])
        ->and($rows[0]->reason())->toBe('There is no plan Monthly on sale.')
        ->and($import->fresh()?->status)->toBe(MemberImportStatus::Imported);
});

it('keeps receipt numbers continuous with the desk\'s', function () {
    ImportMembers::dispatch(importFile([
        'Hana Girma,0911223344,,,Main Branch,Monthly,2026-11-01,,1000',
        'Selam Tadesse,0911223355,,,Main Branch,Monthly,2026-11-01,,500',
    ]));

    expect(Payment::query()->orderBy('receipt_number')->pluck('receipt_number')->all())->toBe([1, 2]);
});

it('imports 100 rows a run, queueing the next run while rows are left', function () {
    Queue::fake();
    $lines = array_map(fn (int $n): string => sprintf('Member %d,09%08d,,,Main Branch,,,,', $n, 11000000 + $n), range(1, ImportMembers::ROWS_PER_RUN + 5));
    $import = importFile($lines);

    (new ImportMembers($import))->handle(app(CurrentOrganization::class), app(MemberImporter::class));

    expect($import->fresh()?->only(['status', 'added_count', 'ready_count']))->toBe(['status' => MemberImportStatus::Importing, 'added_count' => 100, 'ready_count' => 5]);
    Queue::assertPushed(ImportMembers::class, 1);
});

it('never imports a discarded file', function () {
    $import = importFile(['Hana Girma,0911223344,,,Main Branch,,,,']);
    $import->discard();

    ImportMembers::dispatch($import);

    expect(Member::query()->count())->toBe(0)
        ->and($import->fresh()?->status)->toBe(MemberImportStatus::Discarded);
});
