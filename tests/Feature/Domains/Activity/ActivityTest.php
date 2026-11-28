<?php

declare(strict_types=1);

use App\Domains\Activity\Activity;
use App\Domains\Activity\Enums\ActivityAction;
use App\Domains\Activity\Models\ActivityRecord;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

beforeEach(function () {
    $this->gym = actingAsOrganization(Organization::factory()->create());
    $this->staff = Staff::factory()->for($this->gym)->create();
    $this->plan = Plan::factory()->for($this->gym)->create();
});

it('records who did what to which record, keeping money and dates as written', function () {
    $branch = Branch::factory()->for($this->gym)->create();

    Activity::record(ActivityAction::PlanPriced, $this->plan, $this->staff, $branch->id, [
        'from' => Money::of('2,000'),
        'to' => Money::of('2,500'),
        'on' => CarbonImmutable::parse('2026-11-28'),
    ]);

    $record = ActivityRecord::query()->about($this->plan)->whereNotNull('staff_id')->sole();

    expect($record->subject_type)->toBe('plan')
        ->and($record->staff?->is($this->staff))->toBeTrue()
        ->and($record->branch_id)->toBe($branch->id)
        ->and($record->details)->toEqualCanonicalizing(['from' => '2,000.00 ETB', 'to' => '2,500.00 ETB', 'on' => '2026-11-28'])
        ->and($record->flagged)->toBeFalse();
});

it('always flags money given back or taken off', function () {
    $record = Activity::record(ActivityAction::PaymentVoided, $this->plan, $this->staff, null);

    expect($record->flagged)->toBeTrue();
});

it('never changes or deletes a record, even past the model', function () {
    $record = Activity::record(ActivityAction::PlanPriced, $this->plan, $this->staff, null);

    expect(fn () => $record->forceFill(['flagged' => true])->save())->toThrow(LogicException::class)
        ->and(fn () => $record->delete())->toThrow(LogicException::class)
        ->and(fn () => ActivityRecord::query()->whereKey($record->id)->update(['flagged' => true]))->toThrow(QueryException::class, 'never changed or deleted');
});

it('shows a staff member limited to some branches their branches\' records and the gym-wide ones', function () {
    [$main, $bole] = Branch::factory()->for($this->gym)->count(2)->create()->all();
    Activity::record(ActivityAction::PaymentReceived, $this->plan, $this->staff, $main->id);
    Activity::record(ActivityAction::PaymentReceived, $this->plan, $this->staff, $bole->id);
    Activity::record(ActivityAction::PlanPriced, $this->plan, $this->staff, null);

    $byTheStaff = fn () => ActivityRecord::query()->where('staff_id', $this->staff->id);

    expect($byTheStaff()->seenAtBranches([$main->id])->count())->toBe(2)
        ->and($byTheStaff()->seenAtBranches(null)->count())->toBe(3);
});
