<?php

namespace Database\Seeders;

use App\Domains\Announcements\Models\Announcement;
use App\Domains\Attendance\Enums\CheckInDenial;
use App\Domains\Attendance\Models\CheckIn;
use App\Domains\Billing\Enums\PaymentLinkStatus;
use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Models\Payment;
use App\Domains\Billing\Models\PaymentLink;
use App\Domains\Identity\Models\User;
use App\Domains\Members\Enums\Gender;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Discount\Discount;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Memberships\Models\MembershipFreeze;
use App\Domains\Memberships\Models\Plan;
use App\Domains\Memberships\Models\PromoCode;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;
use App\Domains\Organizations\Tenancy\CurrentOrganization;
use App\Domains\Shared\Money\Money;
use App\Domains\Shared\PhoneNumber\PhoneNumber;
use App\Notifications\MemberFroze;
use App\Notifications\MembersToCall;
use App\Notifications\OnlinePaymentReceived;
use App\Notifications\RefundNeeded;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Two months of life at Demo Gym, to look around the panel and the API:
 * about 40 members in every membership state, payments and check-ins over
 * the last weeks, payment links in every status, announcements and staff
 * messages. Run it after the base seed with `make demo`; it adds nothing the
 * second time. Member messages are held back while it runs, so no one is
 * texted or pushed. The base seed's ids (used in docs/development-setup.md)
 * don't change, because this runs after it.
 */
class DemoActivitySeeder extends Seeder
{
    private const FIRST_DEMO_PHONE = '0944 00 00 01';

    /**
     * [name, gender, at Bole, what to sell]
     *
     * @var list<array{0: string, 1: Gender, 2: bool, 3: string}>
     */
    private const MEMBERS = [
        ['Dawit Tesfaye', Gender::Male, false, 'active'],
        ['Selam Bekele', Gender::Female, false, 'active'],
        ['Yonas Alemu', Gender::Male, true, 'active'],
        ['Meron Tadesse', Gender::Female, false, 'active-code'],
        ['Kalkidan Assefa', Gender::Female, false, 'active'],
        ['Biruk Mengistu', Gender::Male, true, 'active-typed'],
        ['Liya Worku', Gender::Female, false, 'active-3m'],
        ['Natnael Girma', Gender::Male, false, 'active-code'],
        ['Hiwot Desta', Gender::Female, true, 'active-bole'],
        ['Samuel Fikru', Gender::Male, false, 'active-yearly'],
        ['Rahel Mulugeta', Gender::Female, false, 'active-owing'],
        ['Abel Tilahun', Gender::Male, true, 'active-owing'],
        ['Bethlehem Haile', Gender::Female, false, 'active'],
        ['Ermias Wolde', Gender::Male, false, 'active-3m'],
        ['Mahlet Abebe', Gender::Female, true, 'active-bole'],
        ['Henok Lemma', Gender::Male, false, 'active-owing'],
        ['Tsion Kassa', Gender::Female, false, 'active'],
        ['Mikiyas Getachew', Gender::Male, true, 'active'],
        ['Eden Solomon', Gender::Female, false, 'ending'],
        ['Robel Ayele', Gender::Male, false, 'ending'],
        ['Saron Teshome', Gender::Female, true, 'ending'],
        ['Kirubel Dereje', Gender::Male, false, 'ending'],
        ['Feven Yohannes', Gender::Female, false, 'ending-owing'],
        ['Nahom Berhanu', Gender::Male, true, 'ending'],
        ['Lidya Negash', Gender::Female, false, 'expired'],
        ['Fitsum Asfaw', Gender::Male, false, 'expired'],
        ['Blen Tefera', Gender::Female, true, 'expired'],
        ['Surafel Kebede', Gender::Male, false, 'expired'],
        ['Hanna Wondimu', Gender::Female, false, 'day-pass'],
        ['Yared Mamo', Gender::Male, false, 'frozen-app'],
        ['Ruth Gebre', Gender::Female, true, 'frozen-desk'],
        ['Tewodros Abate', Gender::Male, false, 'cancelled-refunded'],
        ['Mekdes Ali', Gender::Female, false, 'cancelled-refund-needed'],
        ['Amanuel Shiferaw', Gender::Male, false, 'upcoming'],
        ['Kidist Mekonnen', Gender::Female, true, 'upcoming'],
        ['Eyob Tsegaye', Gender::Male, false, 'visits'],
        ['Sara Hailu', Gender::Female, false, 'visits'],
        ['Mulugeta Bayu', Gender::Male, true, 'visits'],
        ['Senait Alemayehu', Gender::Female, false, 'link-waiting'],
        ['Gelila Tamrat', Gender::Female, false, 'link-waiting-texted'],
    ];

    private CarbonImmutable $today;

    private Branch $main;

    private Branch $bole;

    private Staff $owner;

    private Staff $receptionist;

    private Staff $manager;

    public function run(CurrentOrganization $currentOrganization): void
    {
        $organization = Organization::query()->where('slug', 'demo-gym')->firstOrFail();

        $alreadySeeded = Member::query()->acrossOrganizations()
            ->where('organization_id', $organization->id)
            ->where('phone', PhoneNumber::of(self::FIRST_DEMO_PHONE)->e164)
            ->exists();

        if ($alreadySeeded) {
            $this->command?->info('The demo activity is already there.');

            return;
        }

        mt_srand(2026);

        Event::fakeFor(function () use ($currentOrganization, $organization): void {
            DB::transaction(fn () => $currentOrganization->run($organization, function () use ($organization): void {
                $this->today = $organization->today();
                $this->main = $organization->branches()->where('code', 'MAIN')->firstOrFail();
                $this->bole = $organization->branches()->where('code', 'BOLE')->firstOrFail();
                $this->owner = $organization->staff()->where('role', StaffRole::Owner)->firstOrFail();
                $this->receptionist = $organization->staff()->where('role', StaffRole::Receptionist)->firstOrFail();
                $this->manager = $this->addStaff($organization, 'Demo Manager', 'manager@demo-gym.test', '+251911000004', StaffRole::Manager, $this->bole);
                $this->addStaff($organization, 'Demo Trainer', 'trainer@demo-gym.test', '+251911000005', StaffRole::Trainer, null);

                $freezes = [];
                $paidOnline = null;
                $refundNeeded = null;

                foreach (self::MEMBERS as $index => [$name, $gender, $atBole, $story]) {
                    $member = $this->addMember($organization, $index, $name, $gender, $atBole ? $this->bole : $this->main);
                    $result = $this->tell($member, $story);
                    $freezes = [...$freezes, ...($result['freeze'] === null ? [] : [$result['freeze']])];
                    $paidOnline ??= $result['paid_online'];
                    $refundNeeded ??= $result['refund_needed'];
                }

                $this->checkIns();
                $this->announcements($organization);
                $this->staffMessages($freezes, $paidOnline, $refundNeeded);
            }));
        });

        $this->command?->info('Demo activity added: 40 members, two months of payments and check-ins, payment links, announcements and staff messages.');
    }

    private function addStaff(Organization $organization, string $name, string $email, string $phone, StaffRole $role, ?Branch $onlyAt): Staff
    {
        $user = User::query()->firstOrCreate(['email' => $email], ['name' => $name, 'phone' => $phone, 'password' => 'password']);
        $staff = $organization->staff()->firstOrCreate(['user_id' => $user->id], ['role' => $role]);

        if ($onlyAt !== null) {
            $staff->assignToBranches($onlyAt);
        }

        return $staff;
    }

    private function addMember(Organization $organization, int $index, string $name, Gender $gender, Branch $homeBranch): Member
    {
        $member = new Member;
        $member->fill([
            'full_name' => $name,
            'gender' => $gender,
            'phone' => PhoneNumber::of(sprintf('0944 00 %02d %02d', intdiv($index + 1, 100), ($index + 1) % 100))->e164,
        ]);
        $member->organization()->associate($organization);
        $member->homeBranch()->associate($homeBranch);
        $member->save();

        return $member;
    }

    /**
     * Sell, pay, freeze or cancel as the story says.
     *
     * @return array{freeze: ?MembershipFreeze, paid_online: ?Payment, refund_needed: ?PaymentLink}
     */
    private function tell(Member $member, string $story): array
    {
        $result = ['freeze' => null, 'paid_online' => null, 'refund_needed' => null];
        $monthly = $this->plan('Monthly');

        switch ($story) {
            case 'active':
                $this->sellAndPay($member, $monthly, $this->today->subDays(mt_rand(0, 24)));
                break;
            case 'active-code':
                $membership = Membership::sell($member, $monthly, $this->today->subDays(mt_rand(2, 15)), $this->seller($member), promoCode: PromoCode::findByCode('NEWYEAR'));
                $result['paid_online'] = $this->payOnline($membership);
                break;
            case 'active-typed':
                $this->sellAndPay($member, $monthly, $this->today->subDays(mt_rand(3, 20)), Discount::parse('10%', 'Student'));
                break;
            case 'active-3m':
                $this->sellAndPay($member, $this->plan('3 Months'), $this->today->subDays(mt_rand(20, 70)));
                break;
            case 'active-yearly':
                $this->sellAndPay($member, $this->plan('Yearly'), $this->today->subDays(mt_rand(40, 58)));
                break;
            case 'active-bole':
                $this->sellAndPay($member, $this->plan('Bole Monthly'), $this->today->subDays(mt_rand(0, 20)));
                break;
            case 'active-owing':
                $this->sellAndPay($member, $monthly, $this->today->subDays(mt_rand(3, 18)), part: Money::of('1,000'));
                break;
            case 'ending':
                $this->sellAndPay($member, $monthly, $this->today->subMonthNoOverflow()->addDays(mt_rand(2, 7)));
                break;
            case 'ending-owing':
                $this->sellAndPay($member, $monthly, $this->today->subMonthNoOverflow()->addDays(4), part: Money::of('1,500'));
                break;
            case 'expired':
                $this->sellAndPay($member, $monthly, $this->today->subMonthsNoOverflow(2)->subDays(mt_rand(0, 10)));
                break;
            case 'day-pass':
                $this->sellAndPay($member, $this->plan('Day Pass'), $this->today->subDays(12));
                break;
            case 'frozen-app':
            case 'frozen-desk':
                $membership = $this->sellAndPay($member, $monthly, $this->today->subDays(10));
                $result['freeze'] = $membership->freeze($this->today->subDay(), $this->today->addDays(4), 'Travelling', $story === 'frozen-app' ? null : $this->seller($member));
                break;
            case 'cancelled-refunded':
                $membership = $this->sellAndPay($member, $monthly, $this->today->subDays(20));
                $membership->cancel($this->owner, 'Moved to another city');
                Payment::refund($membership, Money::of('1,000'), PaymentMethod::Cash, null, $member->homeBranch, $this->today->subDays(5), $this->owner);
                break;
            case 'cancelled-refund-needed':
                $membership = $this->sellAndPay($member, $monthly, $this->today->subDays(8));
                $membership->cancel($this->owner, 'Changed their mind');
                $refundNeeded = $this->link($membership, Money::of('2,500'), PaymentLinkStatus::RefundNeeded, $this->today->subDays(2));
                $refunded = $this->link($membership, Money::of('500'), PaymentLinkStatus::RefundNeeded, $this->today->subDays(6));
                $refunded->markRefunded($this->owner, 'CHAPA-RF-0001');
                $result['refund_needed'] = $refundNeeded;
                break;
            case 'upcoming':
                Membership::sell($member, $monthly, $this->today->addDays(3), $this->seller($member));
                break;
            case 'visits':
                $this->sellAndPay($member, $this->plan('10 Visits'), $this->today->subDays(15));
                break;
            case 'link-waiting':
            case 'link-waiting-texted':
                $membership = Membership::sell($member, $monthly, $this->today->subDays(1), $this->seller($member));
                $link = $this->link($membership, $membership->price, PaymentLinkStatus::Pending, $this->today);

                if ($story === 'link-waiting-texted') {
                    $link->forceFill(['sms_sent_at' => CarbonImmutable::now()->subHours(2)])->save();
                }
                break;
        }

        return $result;
    }

    private function sellAndPay(Member $member, Plan $plan, CarbonImmutable $startsOn, ?Discount $discount = null, ?Money $part = null): Membership
    {
        $membership = Membership::sell($member, $plan, $startsOn, $this->seller($member), $discount);
        $methods = [PaymentMethod::Cash, PaymentMethod::Cash, PaymentMethod::Telebirr, PaymentMethod::Telebirr, PaymentMethod::CbeBirr, PaymentMethod::BankTransfer];
        $method = $methods[mt_rand(0, count($methods) - 1)];
        $paidOn = $startsOn->greaterThan($this->today) ? $this->today : $startsOn;

        Payment::receive(
            membership: $membership,
            amount: $part ?? $membership->price,
            method: $method,
            reference: $method->hasReference() ? strtoupper(substr(md5((string) $membership->id), 0, 10)) : null,
            branch: $member->homeBranch,
            paidOn: $paidOn,
            receivedBy: $this->seller($member),
        );

        return $membership;
    }

    /**
     * A membership paid in full through a Chapa link.
     */
    private function payOnline(Membership $membership): Payment
    {
        $reference = 'AP'.strtoupper(substr(md5('chapa'.$membership->id), 0, 10));
        $paidOn = $membership->starts_on->greaterThan($this->today) ? $this->today : CarbonImmutable::parse($membership->starts_on->toDateString());
        $payment = Payment::receive($membership, $membership->price, PaymentMethod::Chapa, $reference, $membership->member->homeBranch, $paidOn, null);

        PaymentLink::factory()->for($membership)->create([
            'organization_id' => $membership->organization_id,
            'member_id' => $membership->member_id,
            'branch_id' => $membership->member->home_branch_id,
            'amount' => $membership->price,
            'status' => PaymentLinkStatus::Paid,
            'payment_id' => $payment->id,
            'chapa_reference' => $reference,
            'settled_at' => $paidOn->setTime(10, 0),
            'created_by_staff_id' => $this->seller($membership->member)->id,
        ]);

        return $payment;
    }

    private function link(Membership $membership, Money $amount, PaymentLinkStatus $status, CarbonImmutable $on): PaymentLink
    {
        return PaymentLink::factory()->for($membership)->create([
            'organization_id' => $membership->organization_id,
            'member_id' => $membership->member_id,
            'branch_id' => $membership->member->home_branch_id,
            'amount' => $amount,
            'status' => $status,
            'chapa_reference' => $status === PaymentLinkStatus::Pending ? null : 'AP'.strtoupper(substr(md5('link'.$membership->id.$amount->minor), 0, 10)),
            'settled_at' => $status === PaymentLinkStatus::Pending ? null : $on->setTime(11, 0),
            'created_by_staff_id' => $this->seller($membership->member)->id,
            'created_at' => $on->setTime(9, 30),
        ]);
    }

    /**
     * The last 30 days of visits: members whose membership ran that day come
     * in at morning and evening peaks; members whose membership had ended are
     * sometimes turned away.
     */
    private function checkIns(): void
    {
        $timezone = $this->main->organization->timezone;
        $now = CarbonImmutable::now();
        $memberships = Membership::query()->with('member')->notCancelled()->get();
        $hours = [6, 6, 7, 7, 7, 8, 9, 12, 13, 17, 17, 18, 18, 18, 19, 19, 20];

        for ($daysAgo = 29; $daysAgo >= 0; $daysAgo--) {
            $day = $this->today->subDays($daysAgo);

            foreach ($memberships as $membership) {
                $runs = $membership->starts_on->lessThanOrEqualTo($day) && $membership->ends_on->greaterThanOrEqualTo($day);
                $comesIn = mt_rand(1, 100) <= ($membership->visit_limit === null ? 45 : 25);

                if (! $comesIn || $day->dayOfWeek === 0) {
                    continue;
                }

                $at = CarbonImmutable::parse($day->toDateString().' '.sprintf('%02d:%02d', $hours[mt_rand(0, count($hours) - 1)], mt_rand(0, 59)), $timezone);

                if ($at->greaterThan($now)) {
                    continue;
                }

                if ($runs && $membership->freezes()->where('starts_on', '<=', $day)->where('ends_on', '>=', $day)->doesntExist()) {
                    $this->checkIn($membership->member, $membership, $at, null);
                } elseif (! $runs && $membership->ends_on->lessThan($day) && mt_rand(1, 100) <= 10) {
                    $this->checkIn($membership->member, null, $at, CheckInDenial::MembershipExpired);
                }
            }
        }
    }

    private function checkIn(Member $member, ?Membership $membership, CarbonImmutable $at, ?CheckInDenial $denial): void
    {
        $factory = CheckIn::factory()->state([
            'organization_id' => $member->organization_id,
            'member_id' => $member->id,
            'branch_id' => $member->home_branch_id,
            'membership_id' => $membership?->id,
            'checked_in_by_staff_id' => $this->seller($member)->id,
            'checked_in_at' => $at->utc(),
        ]);

        ($denial === null ? $factory : $factory->denied($denial))->create();
    }

    private function announcements(Organization $organization): void
    {
        Announcement::factory()->sent(38)->create([
            'organization_id' => $organization->id,
            'posted_by_staff_id' => $this->owner->id,
            'title' => 'New spin bikes',
            'body' => 'Ten new spin bikes are ready at Main Branch. Come and try a class this week.',
        ]);

        Announcement::factory()->create([
            'organization_id' => $organization->id,
            'posted_by_staff_id' => $this->owner->id,
            'title' => 'Closed for the holiday',
            'body' => 'Both branches are closed tomorrow for the holiday. See you the day after.',
            'send_at' => $this->today->addDay()->setTime(7, 0),
        ]);
    }

    /**
     * What the owner and the desk would have heard (ADR 0027), stored now
     * rather than queued.
     *
     * @param  list<MembershipFreeze>  $freezes
     */
    private function staffMessages(array $freezes, ?Payment $paidOnline, ?PaymentLink $refundNeeded): void
    {
        $this->owner->notifyNow(MembersToCall::count(6));
        $this->receptionist->notifyNow(MembersToCall::count(4));

        if ($paidOnline !== null) {
            $this->owner->notifyNow(OnlinePaymentReceived::for($paidOnline));
        }

        if ($refundNeeded !== null) {
            $this->owner->notifyNow(RefundNeeded::for($refundNeeded));
        }

        foreach ($freezes as $freeze) {
            if ($freeze->isFromTheApp()) {
                $this->owner->notifyNow(MemberFroze::for($freeze));
                $this->receptionist->notifyNow(MemberFroze::for($freeze));
            }
        }
    }

    private function plan(string $name): Plan
    {
        return Plan::query()->where('name', $name)->firstOrFail();
    }

    /**
     * Who works the desk at the member's branch.
     */
    private function seller(Member $member): Staff
    {
        return $member->home_branch_id === $this->bole->id ? $this->manager : $this->receptionist;
    }
}
