<?php

declare(strict_types=1);

use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\DiscountGroup;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Memberships\Models\PromoCode;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Shared\Money\Money;
use App\Filament\Staff\Resources\Memberships\Pages\ListMemberships;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-20 10:00:00', 'Africa/Addis_Ababa'));

    $this->gym = Organization::factory()->create(['slug' => 'demo-gym', 'timezone' => 'Africa/Addis_Ababa']);
    $this->main = Branch::factory()->for($this->gym)->create(['name' => 'Main Branch']);
    $this->bole = Branch::factory()->for($this->gym)->create(['name' => 'Bole Branch']);
    $monthly = Plan::factory()->for($this->gym)->create(['name' => 'Monthly', 'price' => Money::of('2,500')]);
    $newYear = PromoCode::factory()->for($this->gym)->create(['code' => 'NEWYEAR', 'discount' => '20%']);
    $student = DiscountGroup::factory()->for($this->gym)->create(['name' => 'Student', 'discount' => '10%']);
    $soldOn = CarbonImmutable::parse('2026-12-10 09:00', 'Africa/Addis_Ababa');

    $sell = function (string $name, Branch $branch, string $price, array $source) use ($monthly, $soldOn): void {
        Membership::factory()
            ->for(Member::factory()->for($branch, 'homeBranch')->create(['full_name' => $name]))
            ->for($monthly)
            ->create([
                'list_price' => Money::of('2,500'),
                'price' => Money::of($price),
                'discount_reason' => $price === '2,500' ? null : ($source['reason'] ?? 'Discount'),
                'promo_code_id' => $source['promo_code_id'] ?? null,
                'discount_group_id' => $source['discount_group_id'] ?? null,
                'created_at' => $soldOn,
            ]);
    };

    $sell('Hana Girma', $this->main, '2,000', ['promo_code_id' => $newYear->id, 'reason' => 'Promo code NEWYEAR']);
    $sell('Tigist Haile', $this->main, '2,250', ['discount_group_id' => $student->id, 'reason' => 'Discount group Student']);
    $sell('Abebe Kebede', $this->bole, '2,000', ['reason' => 'Loyal member']);
    $sell('Dawit Tesfaye', $this->main, '2,500', []);
});

function discountsReportUrl(array $query = []): string
{
    return route('api.v1.organizations.reports.discounts', ['organization' => 'demo-gym', ...$query]);
}

it('lists every membership sold below list price, with what came off and where it came from', function () {
    actingAsStaff($this->gym, StaffRole::Accountant);

    $rows = csvRowsOf($this->get(discountsReportUrl(['from' => '2026-12-01', 'to' => '2026-12-31']))
        ->assertOk()
        ->assertDownload('demo-gym-discounts-2026-12-01-to-2026-12-31.csv'));

    expect($rows)->toHaveCount(4)
        ->and($rows[0][9])->toBe('Source')
        ->and(array_map(fn (array $row): array => [$row[1], $row[7], $row[9], $row[10]], array_slice($rows, 1)))->toEqualCanonicalizing([
            ['Hana Girma', '500.00', 'Promo code', 'NEWYEAR'],
            ['Tigist Haile', '250.00', 'Discount group', 'Student'],
            ['Abebe Kebede', '500.00', 'Typed', 'Loyal member'],
        ]);
});

it('keeps it to the staff member\'s branches and to the roles that see money', function () {
    actingAsStaff($this->gym, StaffRole::Manager, $this->bole);

    $rows = csvRowsOf($this->get(discountsReportUrl(['from' => '2026-12-01', 'to' => '2026-12-31']))->assertOk());

    expect(array_column(array_slice($rows, 1), 1))->toBe(['Abebe Kebede']);

    actingAsStaff($this->gym, StaffRole::Receptionist);

    $this->getJson(discountsReportUrl())->assertForbidden();
});

it('offers the discounts download on the memberships list', function () {
    actingAsStaffInPanel($this->gym, StaffRole::Owner);

    Livewire::test(ListMemberships::class)
        ->assertActionVisible('downloadDiscounts');

    actingAsStaffInPanel($this->gym, StaffRole::Receptionist);

    Livewire::test(ListMemberships::class)
        ->assertActionHidden('downloadDiscounts');
});
