<?php

declare(strict_types=1);

use App\Domains\Imports\Csv\MemberCsv;
use App\Domains\Imports\Enums\MemberImportRowOutcome;
use App\Domains\Imports\Enums\MemberImportStatus;
use App\Domains\Imports\Exceptions\FileRefused;
use App\Domains\Imports\Models\MemberImport;
use App\Domains\Imports\Models\MemberImportRow;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Shared\Money\Money;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->gym = actingAsOrganization(Organization::factory()->create());
    $this->main = Branch::factory()->for($this->gym)->create(['name' => 'Main Branch', 'code' => 'MAIN']);
    $this->bole = Branch::factory()->for($this->gym)->create(['name' => 'Bole Branch', 'code' => 'BOLE']);
    Plan::factory()->for($this->gym)->create(['name' => 'Monthly', 'price' => Money::of('2,500')]);
    $boleOnly = Plan::factory()->for($this->gym)->create(['name' => 'Bole Pass', 'price' => Money::of('1,000')]);
    $boleOnly->makeAvailableOnlyAt($this->bole);
    Member::factory()->for($this->main, 'homeBranch')->create(['full_name' => 'Abebe Kebede', 'phone' => '+251911000001']);
    Member::factory()->for($this->main, 'homeBranch')->create(['full_name' => 'Dawit Bekele', 'phone' => '+251911000002'])->delete();
    $this->owner = Staff::factory()->for($this->gym)->owner()->create();
});

/**
 * Check the given rows, under the template's header.
 *
 * @param  list<string>  $lines
 */
function checkRows(array $lines): MemberImport
{
    $csv = "full_name,phone,gender,date_of_birth,home_branch,plan,starts_on,ends_on,amount_paid\n".implode("\n", $lines)."\n";

    return MemberImport::check(test()->owner, 'members.csv', $csv, CarbonImmutable::parse('2026-11-27'));
}

it('checks each row, so staff see what it will give before importing', function () {
    $import = checkRows([
        'Hana Girma,0911 22 33 44,female,1995-04-12,Main Branch,Monthly,2026-11-01,,1500',
        'Selam Tadesse,911223355,F,,bole,,,,',
        'Meron Assefa,0911 22 33 66,ሴት,12/04/1995,MAIN,monthly,01/11/2026,30/11/2026,',
    ]);

    expect($import->status)->toBe(MemberImportStatus::Checked)
        ->and($import->only(['rows_total', 'ready_count', 'error_count', 'duplicate_count']))->toBe(['rows_total' => 3, 'ready_count' => 3, 'error_count' => 0, 'duplicate_count' => 0])
        ->and($import->rows()->orderBy('line_number')->pluck('line_number')->all())->toBe([2, 3, 4]);
});

it('says why a row can\'t be imported', function (string $line, string $reason, MemberImportRowOutcome $outcome) {
    $row = checkRows([$line])->rows()->sole();

    expect($row->outcome)->toBe($outcome)
        ->and($row->reason())->toBe($reason);
})->with([
    'no name' => [',0911223344,,,Main Branch,,,,', 'The full name is missing.', MemberImportRowOutcome::Error],
    'a bad phone' => ['Hana,12,,,Main Branch,,,,', '12 isn\'t a phone number like 0911 234 567.', MemberImportRowOutcome::Error],
    'a phone the spreadsheet turned into a number' => ['Hana,2.51911E+11,,,Main Branch,,,,', '2.51911E+11 isn\'t a phone number: the spreadsheet turned it into a number. Format the column as text and type it again.', MemberImportRowOutcome::Error],
    'an unknown branch' => ['Hana,0911223344,,,Piassa,,,,', 'There is no branch Piassa. Use a branch\'s name or code.', MemberImportRowOutcome::Error],
    'a bad gender' => ['Hana,0911223344,x,,Main Branch,,,,', 'The gender x isn\'t female or male.', MemberImportRowOutcome::Error],
    'a bad date' => ['Hana,0911223344,,31/02/1995,Main Branch,,,,', 'The date of birth 31/02/1995 isn\'t a date like 2026-11-27 or 27/11/2026.', MemberImportRowOutcome::Error],
    'an unknown plan' => ['Hana,0911223344,,,Main Branch,Yearly,2026-11-01,,', 'There is no plan Yearly on sale.', MemberImportRowOutcome::Error],
    'a plan not sold at the branch' => ['Hana,0911223344,,,Main Branch,Bole Pass,2026-11-01,,', 'The plan Bole Pass isn\'t sold at Main Branch.', MemberImportRowOutcome::Error],
    'a membership without its plan' => ['Hana,0911223344,,,Main Branch,,2026-11-01,,', 'A membership needs its plan.', MemberImportRowOutcome::Error],
    'a membership without its start' => ['Hana,0911223344,,,Main Branch,Monthly,,,', 'A membership needs its first day (starts on).', MemberImportRowOutcome::Error],
    'an end before the start' => ['Hana,0911223344,,,Main Branch,Monthly,2026-11-10,2026-11-01,', 'A membership can\'t end before its first day.', MemberImportRowOutcome::Error],
    'more paid than the price' => ['Hana,0911223344,,,Main Branch,Monthly,2026-11-01,,3000', 'The amount paid 3,000.00 ETB is more than the plan\'s price, 2,500.00 ETB.', MemberImportRowOutcome::Error],
    'a member already at the gym' => ['Abebe,0911000001,,,Main Branch,,,,', 'Already a member: Abebe Kebede has this phone.', MemberImportRowOutcome::Duplicate],
    'an archived member' => ['Dawit,0911000002,,,Main Branch,,,,', 'Dawit Bekele has this phone but is archived: restore them instead.', MemberImportRowOutcome::Duplicate],
]);

it('marks a phone that comes twice in the file as a duplicate the second time', function () {
    $rows = checkRows([
        'Hana Girma,0911223344,,,Main Branch,,,,',
        'Hana G.,+251911223344,,,Main Branch,,,,',
    ])->rows()->orderBy('line_number')->get();

    expect($rows->pluck('outcome')->all())->toBe([MemberImportRowOutcome::Ready, MemberImportRowOutcome::Duplicate])
        ->and($rows[1]->reason())->toBe('The same phone as line 2.');
});

it('reads files saved with a byte-order mark, semicolons or other spellings of the columns', function () {
    $csv = "\xEF\xBB\xBFFull Name;Phone;Home-Branch\nHana Girma;0911223344;Main Branch\n\n";

    expect(MemberImport::check($this->owner, 'members.csv', $csv, CarbonImmutable::parse('2026-11-27'))->ready_count)->toBe(1);
});

it('refuses a file it can\'t read as a member import', function (string $csv, string $message) {
    expect(fn () => MemberImport::check($this->owner, 'members.csv', $csv, CarbonImmutable::parse('2026-11-27')))
        ->toThrow(FileRefused::class, $message);

    expect(MemberImport::query()->count())->toBe(0);
})->with([
    'not UTF-8' => ["full_name,phone,home_branch\nH\xE4na,0911223344,Main Branch\n", 'Save the file as "CSV UTF-8" and upload it again.'],
    'missing columns' => ["name,phone\nHana,0911223344\n", 'The file needs the columns full_name, home_branch, as in the template.'],
    'no rows' => ["full_name,phone,home_branch\n,,\n", 'The file has no rows to import.'],
]);

it('refuses more rows than it can take at once', function () {
    $csv = "full_name,phone,home_branch\n".str_repeat("Hana,0911223344,Main Branch\n", MemberCsv::MAX_ROWS + 1);

    expect(fn () => MemberImport::check($this->owner, 'members.csv', $csv, CarbonImmutable::parse('2026-11-27')))->toThrow(FileRefused::class);
});

it('prunes the rows of imports finished more than 30 days ago, keeping the summary', function () {
    $import = checkRows(['Hana Girma,0911223344,,,Main Branch,,,,']);
    $import->discard();
    $this->travel(31)->days();

    $this->artisan('model:prune', ['--model' => [MemberImportRow::class]])->assertSuccessful();

    expect(MemberImportRow::query()->count())->toBe(0)
        ->and($import->fresh()?->rows_total)->toBe(1);
});
