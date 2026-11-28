<?php

declare(strict_types=1);

use App\Domains\Announcements\Models\Announcement;
use App\Domains\Attendance\Models\CheckIn;
use App\Domains\Billing\Models\ChapaAccount;
use App\Domains\Billing\Models\Payment;
use App\Domains\Billing\Models\PaymentLink;
use App\Domains\Identity\Models\User;
use App\Domains\Imports\Models\MemberImport;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\DiscountGroup;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Memberships\Models\PromoCode;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\BranchClosure;
use App\Domains\Organizations\Models\Organization;
use App\Domains\PlatformBilling\Models\GymInvoice;
use App\Domains\PlatformBilling\Models\GymSubscription;
use App\Domains\PlatformBilling\Models\PlatformPlan;
use App\Domains\PlatformBilling\SubscriptionTerms;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Livewire\Livewire;

/*
| Renders every page of the staff panel for a staff member who reads
| Amharic, and fails on any word in Latin letters that isn't the gym's own
| data or one of the few names kept as they are (docs/amharic-glossary.md,
| ADR 0041).
*/

/** Words kept in Latin letters on purpose. */
const KEPT_IN_LATIN = ['CSV', 'QR', 'JPEG', 'PNG', 'WebP', 'Excel', 'Google', 'Sheets', 'NEWYEAR', 'BOLE', 'GYM', 'CJ12ABC34D', 'CHASECK_TEST', 'CHASECK', 'Settings', 'API', 'px', 'INV'];

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-24 10:00', 'Africa/Addis_Ababa'));
    $this->gym = Organization::factory()->create(['slug' => 'demo-gym', 'timezone' => 'Africa/Addis_Ababa']);
    $this->branch = Branch::factory()->for($this->gym)->create();
    $this->staff = actingAsStaffInPanel($this->gym, StaffRole::Owner);
    $plan = Plan::factory()->for($this->gym)->create();
    $member = Member::factory()->for($this->branch, 'homeBranch')->create();
    $membership = Membership::factory()->for($member)->for($plan)->create(['starts_on' => '2026-11-01', 'ends_on' => '2026-11-30']);
    Payment::factory()->for($membership)->create(['paid_on' => '2026-11-01', 'branch_id' => $this->branch->id]);
    CheckIn::factory()->for($member)->for($this->branch)->create(['checked_in_at' => CarbonImmutable::parse('2026-11-23 06:30', 'UTC')]);
    Announcement::factory()->for($this->gym)->create(['title' => 'የበዓል ማስታወቂያ', 'body' => 'ሰኞ ሁለቱም ቅርንጫፎች ዝግ ናቸው።']);
    PromoCode::factory()->for($this->gym)->create();
    DiscountGroup::factory()->for($this->gym)->create();
    BranchClosure::factory()->for($this->branch)->create(['starts_on' => '2026-12-01', 'ends_on' => '2026-12-01']);
    ChapaAccount::factory()->for($this->gym)->create();
    PaymentLink::factory()->for($membership)->create();
    MemberImport::check($this->staff, 'members.csv', "full_name,phone,home_branch
ሀና ግርማ,0911223399,{$this->branch->name}
ዳዊት,12,{$this->branch->name}
", CarbonImmutable::parse('2026-11-24'));
    // An owner sees the platform's bill: its page, a banner while an invoice is due, and its message in their inbox, read in Amharic (ADR 0045).
    Filament::auth()->user()?->forceFill(['message_language' => 'am'])->save();
    GymSubscription::start($this->gym, new SubscriptionTerms(PlatformPlan::factory()->create()), firstBillingDay: CarbonImmutable::parse('2026-11-30'));
    GymInvoice::issueDue($this->gym);

    app()->setLocale('am');
    CarbonImmutable::setLocale('am');
});

afterEach(function () {
    app()->setLocale('en');
    CarbonImmutable::setLocale('en');
});

/**
 * The words in Latin letters a page shows, leaving out the gym's own data.
 *
 * @return list<string>
 */
function latinWordsShownIn(string $html): array
{
    $text = preg_replace('#<(script|style|template)\b.*?</\1>#si', ' ', $html) ?? '';
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5);
    // The English sample beside the English choice on My notifications, and the panel's address.
    $text = str_replace(['Members to call: 3 members to call…', '/staff/'], ' ', $text);

    foreach (dataOfTheGym() as $value) {
        $text = str_replace($value, ' ', $text);
    }

    preg_match_all('/\b[A-Za-z][A-Za-z_]+\b/', $text, $words);

    return array_values(array_unique(array_diff($words[0], KEPT_IN_LATIN)));
}

/**
 * Every string the test's records hold: names, codes, phones, notes.
 *
 * @return list<string>
 */
function dataOfTheGym(): array
{
    $values = [];

    foreach ([Organization::class, Branch::class, Plan::class, Member::class, Announcement::class, PromoCode::class, DiscountGroup::class, BranchClosure::class, Payment::class, PaymentLink::class, ChapaAccount::class, MemberImport::class, PlatformPlan::class, GymInvoice::class, User::class] as $model) {
        $records = method_exists($model, 'acrossOrganizations') ? $model::acrossOrganizations()->get() : $model::query()->get();

        foreach ($records as $record) {
            foreach ($record->getAttributes() as $value) {
                if (is_string($value) && strlen($value) > 1) {
                    $values[] = $value;
                }
            }
        }
    }

    usort($values, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

    return $values;
}

it('shows every resource page in Amharic', function () {
    $found = [];

    foreach (Filament::getPanel('staff')->getResources() as $resource) {
        /** @var class-string<Model> $model */
        $model = $resource::getModel();
        // The newest record: the first staff member is the owner signed in, who may not edit themselves.
        $record = $model::query()->latest((new $model)->getKeyName())->first();

        foreach ($resource::getPages() as $name => $registration) {
            $parameters = in_array($name, ['view', 'edit'], true) ? ['record' => $record?->getRouteKey()] : [];

            if ($parameters !== [] && $record === null) {
                continue;
            }

            $words = latinWordsShownIn(Livewire::test($registration->getPage(), $parameters)->html());

            if ($words !== []) {
                $found[class_basename($resource).'::'.$name] = $words;
            }
        }
    }

    expect($found)->toBe([]);
});

it('shows every custom page and widget in Amharic', function () {
    $found = [];
    $panel = Filament::getPanel('staff');

    foreach ([...$panel->getPages(), ...$panel->getWidgets()] as $component) {
        $class = is_string($component) ? $component : $component->widget;
        $words = latinWordsShownIn(Livewire::test($class)->html());

        if ($words !== []) {
            $found[class_basename($class)] = $words;
        }
    }

    expect($found)->toBe([]);
});
