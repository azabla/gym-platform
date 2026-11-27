<?php

declare(strict_types=1);

use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Models\Payment;
use App\Domains\Imports\Enums\MemberImportStatus;
use App\Domains\Imports\Models\MemberImport;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Shared\Money\Money;
use App\Filament\Staff\Resources\MemberImports\MemberImportResource;
use App\Filament\Staff\Resources\MemberImports\Pages\ListMemberImports;
use App\Filament\Staff\Resources\MemberImports\Pages\ViewMemberImport;
use App\Filament\Staff\Resources\Members\Pages\ListMembers;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-27 10:00', 'Africa/Addis_Ababa'));
    $this->gym = Organization::factory()->create(['slug' => 'demo-gym', 'timezone' => 'Africa/Addis_Ababa']);
    $this->main = Branch::factory()->for($this->gym)->create(['name' => 'Main Branch']);
    Plan::factory()->for($this->gym)->create(['name' => 'Monthly', 'price' => Money::of('2,500')]);
});

/**
 * A CSV file of members under the template's header.
 *
 * @param  list<string>  $lines
 */
function membersFile(array $lines): UploadedFile
{
    return UploadedFile::fake()->createWithContent('members.csv', "full_name,phone,gender,date_of_birth,home_branch,plan,starts_on,ends_on,amount_paid\n".implode("\n", $lines)."\n");
}

it('lets owners and managers import members, and nobody else', function (StaffRole $role, bool $imports) {
    actingAsStaffInPanel($this->gym, $role);

    Livewire::test(ListMembers::class)->{$imports ? 'assertActionVisible' : 'assertActionHidden'}('import');
    $this->get(MemberImportResource::getUrl('index', tenant: $this->gym))->assertStatus($imports ? 200 : 403);
})->with([
    'owner' => [StaffRole::Owner, true],
    'manager' => [StaffRole::Manager, true],
    'receptionist' => [StaffRole::Receptionist, false],
    'accountant' => [StaffRole::Accountant, false],
]);

it('checks an uploaded file, shows each row, then imports the ready ones once confirmed', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Owner);

    Livewire::test(ListMemberImports::class)
        ->callAction('upload', ['file' => membersFile([
            'Hana Girma,0911223344,female,,Main Branch,Monthly,2026-11-01,,1500',
            'Abebe,12,,,Main Branch,,,,',
        ])])
        ->assertHasNoActionErrors()
        ->assertRedirect();

    $import = MemberImport::query()->sole();

    Livewire::test(ViewMemberImport::class, ['record' => $import->getRouteKey()])
        ->assertSee('members.csv')
        ->assertActionVisible('import')
        ->callAction('import')
        ->assertNotified('Importing. The rows below fill in as it goes.');

    $hana = Member::query()->where('phone', '+251911223344')->sole();

    expect($import->fresh()?->status)->toBe(MemberImportStatus::Imported)
        ->and($import->fresh()?->added_count)->toBe(1)
        ->and(Payment::query()->sole()->method)->toBe(PaymentMethod::BroughtIn)
        ->and($hana->full_name)->toBe('Hana Girma');

    Livewire::test(ViewMemberImport::class, ['record' => $import->getRouteKey()])
        ->assertActionHidden('import')
        ->callAction('notImported')
        ->assertFileDownloaded('demo-gym-members-not-imported-2026-11-27.csv');
});

it('says why a file can\'t be read, and keeps nothing', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Owner);

    Livewire::test(ListMemberImports::class)
        ->callAction('upload', ['file' => UploadedFile::fake()->createWithContent('members.csv', "name,phone\nHana,0911223344\n")])
        ->assertNotified('The file needs the columns full_name, home_branch, as in the template.');

    expect(MemberImport::query()->count())->toBe(0);
});

it('discards a checked file without importing it', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Owner);
    Livewire::test(ListMemberImports::class)->callAction('upload', ['file' => membersFile(['Hana Girma,0911223344,,,Main Branch,,,,'])]);
    $import = MemberImport::query()->sole();

    Livewire::test(ViewMemberImport::class, ['record' => $import->getRouteKey()])
        ->callAction('discard')
        ->assertNotified('File discarded.');

    expect($import->fresh()?->status)->toBe(MemberImportStatus::Discarded)
        ->and(Member::query()->count())->toBe(0);
});

it('gives the template to fill in', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Manager);

    Livewire::test(ListMemberImports::class)
        ->callAction('template')
        ->assertFileDownloaded('members-template.csv');
});
