<?php

declare(strict_types=1);

use App\Domains\Billing\Models\Payment;
use App\Domains\Members\Models\Member;
use App\Domains\Memberships\Models\Membership;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Branch;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;
use App\Filament\Staff\Pages\Dashboard;
use App\Filament\Staff\Pages\Inbox;
use App\Filament\Staff\Resources\Payments\PaymentResource;
use App\Notifications\MembersToCall;
use App\Notifications\OnlinePaymentReceived;
use Livewire\Livewire;

beforeEach(function () {
    $this->gym = Organization::factory()->create();
    $this->branch = Branch::factory()->for($this->gym)->create();
});

it('lists the staff member\'s messages, with the unread count in the menu', function () {
    $staff = actingAsStaffInPanel($this->gym, StaffRole::Manager);
    $staff->notify(MembersToCall::count(4));
    Staff::factory()->for($this->gym)->create()->notify(MembersToCall::count(9));

    expect(Inbox::getNavigationBadge())->toBe('1');

    Livewire::test(Inbox::class)
        ->assertSee('Members to call')
        ->assertSee('4 members to call')
        ->assertDontSee('9 members to call');
});

it('opens a message: marks it read and goes to what it is about', function () {
    $staff = actingAsStaffInPanel($this->gym, StaffRole::Owner);
    $membership = Membership::factory()->for(Member::factory()->for($this->branch, 'homeBranch'))->create();
    $payment = Payment::factory()->for($membership)->create(['branch_id' => $this->branch->id]);
    $staff->notify(OnlinePaymentReceived::for($payment));
    $message = $staff->notifications()->sole();

    Livewire::test(Inbox::class)
        ->callTableAction('open', $message)
        ->assertRedirect(PaymentResource::getUrl('view', ['record' => $payment->id]));

    expect($message->refresh()->read_at)->not->toBeNull()
        ->and(Inbox::getNavigationBadge())->toBeNull();
});

it('opens the morning list on the dashboard, and marks everything read', function () {
    $staff = actingAsStaffInPanel($this->gym, StaffRole::Receptionist);
    $staff->notify(MembersToCall::count(2));
    $staff->notify(MembersToCall::count(3));

    Livewire::test(Inbox::class)
        ->callTableAction('open', $staff->notifications()->firstOrFail())
        ->assertRedirect(Dashboard::getUrl());

    Livewire::test(Inbox::class)->callAction('markAllRead');

    expect($staff->unreadNotifications()->count())->toBe(0);
});

it('shows only unread messages when asked', function () {
    $staff = actingAsStaffInPanel($this->gym, StaffRole::Manager);
    $staff->notify(MembersToCall::count(4));
    $staff->notify(MembersToCall::count(2));
    $read = $staff->notifications()->oldest()->firstOrFail();
    $read->markAsRead();
    $unread = $staff->unreadNotifications()->sole();

    Livewire::test(Inbox::class)
        ->filterTable('unread')
        ->assertCanSeeTableRecords([$unread])
        ->assertCanNotSeeTableRecords([$read]);
});
