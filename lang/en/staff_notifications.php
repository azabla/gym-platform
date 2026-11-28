<?php

/*
| Messages to staff, in the staff panel's inbox and the API's (ADR 0027).
| They don't show on a lock screen, so they name the member. Each staff
| member reads them in their own message language; Amharic is in
| lang/am/staff_notifications.php with the same keys (ADR 0034).
*/

return [

    'staff_online_payment' => [
        'title' => 'Paid online',
        'body' => ':member paid :amount online for :plan (receipt :receipt).',
        'sms' => ':gym: :member paid :amount online for :plan (receipt :receipt).',
    ],

    'staff_refund_needed' => [
        'title' => 'Refund needed',
        'body' => 'Chapa took :amount from :member that isn\'t due. Refund it from the gym\'s Chapa dashboard.',
        'sms' => ':gym: refund needed. Chapa took :amount from :member that isn\'t due. Refund it from the gym\'s Chapa dashboard.',
    ],

    'staff_member_froze' => [
        'title' => 'Frozen in the app',
        'body' => ':member froze their :plan membership in the app from :starts_on to :ends_on. It now ends on :membership_ends_on.',
        'sms' => ':gym: :member froze :plan in the app from :starts_on to :ends_on. It now ends on :membership_ends_on.',
    ],

    'staff_member_ended_freeze' => [
        'title' => 'Back early',
        'body' => ':member ended their freeze of :plan early in the app. It now ends on :membership_ends_on.',
        'sms' => ':gym: :member ended their freeze of :plan early. It now ends on :membership_ends_on.',
    ],

    'staff_members_to_call' => [
        'title' => 'Members to call',
        'body' => '{1} 1 member to call: a membership ends in the next 7 days with nothing sold after it.|[2,*] :count members to call: memberships ending in the next 7 days with nothing sold after them.',
        'sms' => '{1} :gym: 1 member to call today, a membership ending within 7 days.|[2,*] :gym: :count members to call today, memberships ending within 7 days.',
    ],

    // The platform's bill to the gym, for its owners only (ADR 0045).

    'staff_bill_issued' => [
        'title' => 'New platform invoice',
        'body' => 'Invoice :invoice for :amount, for :starts_on to :ends_on, is due on :due_on. If it isn\'t paid by :pauses_on, the gym is paused. See Platform bill for how to pay.',
        'sms' => ':gym: platform invoice :invoice for :amount is due on :due_on. Unpaid by :pauses_on, the gym is paused. Pay with :invoice as the reason.',
    ],

    'staff_bill_due_today' => [
        'title' => 'Platform invoice due today',
        'body' => 'Invoice :invoice is due today: :amount still to pay. If it isn\'t paid by :pauses_on, the gym is paused.',
        'sms' => ':gym: platform invoice :invoice is due today, :amount to pay. Unpaid by :pauses_on, the gym is paused.',
    ],

    'staff_bill_overdue' => [
        'title' => 'Platform invoice overdue',
        'body' => 'Invoice :invoice was due on :due_on: :amount still to pay. If it isn\'t paid by :pauses_on, the gym is paused.',
        'sms' => ':gym: platform invoice :invoice is overdue, :amount to pay. Unpaid by :pauses_on, the gym is paused.',
    ],

    'staff_bill_pauses_tomorrow' => [
        'title' => 'The gym pauses tomorrow',
        'body' => 'Invoice :invoice is still unpaid: :amount. Tomorrow the gym is paused: staff can\'t sign in, the door and the members\' app stop. Pay today to keep it open.',
        'sms' => ':gym: platform invoice :invoice is unpaid, :amount. The gym is paused tomorrow unless it is paid today.',
    ],

    'staff_bill_paused' => [
        'title' => 'Gym paused for an unpaid invoice',
        'body' => 'Invoice :invoice wasn\'t paid, so the gym is paused: staff can\'t sign in, the door and the members\' app are stopped. Nothing is deleted. Pay :amount and it reopens by itself.',
        'sms' => ':gym is paused: platform invoice :invoice is unpaid. Pay :amount with :invoice as the reason and it reopens by itself.',
    ],

    'staff_bill_paid' => [
        'title' => 'Platform invoice paid',
        'body' => 'Thank you: invoice :invoice for :amount is paid.',
        'sms' => ':gym: thank you, platform invoice :invoice for :amount is paid.',
    ],

    'staff_bill_paid_reopened' => [
        'title' => 'Paid: the gym is open again',
        'body' => 'Thank you: invoice :invoice for :amount is paid, and the gym is open again. Staff can sign in, and the door and the members\' app work.',
        'sms' => ':gym: thank you, platform invoice :invoice is paid. The gym is open again.',
    ],

];
