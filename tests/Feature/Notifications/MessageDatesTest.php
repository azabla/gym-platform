<?php

declare(strict_types=1);

use App\Notifications\MessageText;

it('writes dates out for the reader: both calendars in Amharic, the Ethiopian alone in an Amharic SMS', function () {
    $details = ['gym' => 'Demo Gym', 'plan' => 'Monthly', 'ends_on' => '2026-11-27'];

    expect(MessageText::body('membership_expired', $details, 'am'))
        ->toBe('በDemo Gym ያለዎት የMonthly አባልነት ኅዳር 18 ቀን 2019 ዓ.ም. (ኖቬምበር 27, 2026) አብቅቷል። ስፖርትዎን ለመቀጠል ያድሱት።')
        ->and(MessageText::sms('membership_expired', $details, 'am'))
        ->toBe('Demo Gym፦ የMonthly አባልነትዎ ኅዳር 18 ቀን 2019 ዓ.ም. አብቅቷል። ስፖርትዎን ለመቀጠል በመስተንግዶ ያድሱ።')
        ->and(MessageText::sms('membership_expired', $details, 'en'))
        ->toBe('Demo Gym: your Monthly membership ended on 27 November 2026. Renew at the desk to keep training.');
});

it('writes dates out in messages to staff too', function () {
    $details = ['gym' => 'Demo Gym', 'member' => 'Hana Girma', 'plan' => 'Monthly', 'membership_ends_on' => '2026-12-26'];

    expect(MessageText::forStaff('staff_member_ended_freeze', 'body', $details, 'am'))
        ->toContain('ታኅሣሥ 17 ቀን 2019 ዓ.ም. (ዲሴምበር 26, 2026)')
        ->and(MessageText::forStaff('staff_member_ended_freeze', 'sms', $details, 'am'))
        ->toContain('ታኅሣሥ 17 ቀን 2019 ዓ.ም. ያበቃል')
        ->not->toContain('ዲሴምበር');
});
