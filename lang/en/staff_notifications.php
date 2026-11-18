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
    ],

    'staff_refund_needed' => [
        'title' => 'Refund needed',
        'body' => 'Chapa took :amount from :member that isn\'t due. Refund it from the gym\'s Chapa dashboard.',
    ],

    'staff_member_froze' => [
        'title' => 'Frozen in the app',
        'body' => ':member froze their :plan membership in the app from :starts_on to :ends_on. It now ends on :membership_ends_on.',
    ],

    'staff_member_ended_freeze' => [
        'title' => 'Back early',
        'body' => ':member ended their freeze of :plan early in the app. It now ends on :membership_ends_on.',
    ],

    'staff_members_to_call' => [
        'title' => 'Members to call',
        'body' => '{1} 1 member to call: a membership ends in the next 7 days with nothing sold after it.|[2,*] :count members to call: memberships ending in the next 7 days with nothing sold after them.',
    ],

];
