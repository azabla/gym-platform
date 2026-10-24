<?php

/*
| Messages to members (ADR 0012), in the inbox, by push and by SMS. They show
| on a lock screen, so they name the gym and the plan, never a person's name,
| phone number or payment reference. Amharic goes in lang/am/notifications.php,
| with the same keys; a key missing there falls back to this file.
*/

return [

    'membership_expiring' => [
        'title' => 'Membership ending soon',
        'body' => '{1} Your :plan membership at :gym ends tomorrow, :ends_on.|[2,*] Your :plan membership at :gym ends in :count days, on :ends_on.',
        'sms' => ':gym: your :plan membership ends on :ends_on. Renew at the desk to keep training.',
    ],

    'membership_expired' => [
        'title' => 'Membership ended',
        'body' => 'Your :plan membership at :gym ended on :ends_on. Renew it to keep training.',
        'sms' => ':gym: your :plan membership ended on :ends_on. Renew at the desk to keep training.',
    ],

    'membership_frozen' => [
        'title' => 'Membership frozen',
        'body' => 'Your :plan membership at :gym is frozen from :starts_on to :ends_on. It now ends on :membership_ends_on.',
    ],

    'payment_succeeded' => [
        'title' => 'Payment received',
        'body' => 'Your payment of :amount for :plan at :gym was recorded.',
    ],

    'visit_denied' => [
        'title' => 'Check-in refused',
        'body' => 'You couldn\'t check in at :branch: :reason',
    ],

    // The gym's own words, the same in every language (ADR 0015).
    'announcement' => [
        'title' => ':title',
        'body' => ':body',
    ],

    // Why a check-in was refused, by Volt's reason code (ADR 0011).
    'reasons' => [
        'CREDENTIAL_INVALID' => 'the QR code wasn\'t recognised.',
        'CREDENTIAL_REVOKED' => 'this phone\'s QR code was replaced. Open the app to get a new one.',
        'CODE_EXPIRED' => 'the QR code had expired. Check your phone\'s clock.',
        'CODE_ALREADY_USED' => 'that QR code was already used.',
        'MEMBERSHIP_NONE' => 'you have no membership for today.',
        'MEMBERSHIP_EXPIRED' => 'your membership has ended.',
        'MEMBERSHIP_FROZEN' => 'your membership is frozen today.',
        'BRANCH_NOT_ALLOWED' => 'your membership isn\'t valid at this branch.',
        'VISITS_USED_UP' => 'you have used every visit of your membership.',
        'ALREADY_CHECKED_IN' => 'you checked in less than 5 minutes ago.',
    ],

];
