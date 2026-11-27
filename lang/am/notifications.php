<?php

/*
| Amharic messages to members, with the same keys and placeholders as
| lang/en/notifications.php; a key missing here falls back to English. Like
| the English, they name the gym and the plan, never a person. Words follow
| docs/amharic-glossary.md; dates come written out, the Ethiopian date with
| the Gregorian beside it, or the Ethiopian date alone in an SMS (ADR 0034,
| ADR 0041). For a fluent speaker to review.
*/

return [

    'membership_expiring' => [
        'title' => 'አባልነትዎ በቅርቡ ያበቃል',
        'body' => '{1} በ:gym ያለዎት የ:plan አባልነት ነገ፣ :ends_on፣ ያበቃል።|[2,*] በ:gym ያለዎት የ:plan አባልነት በ:count ቀን ውስጥ፣ :ends_on፣ ያበቃል።',
        'sms' => ':gym፦ የ:plan አባልነትዎ :ends_on ያበቃል። ስፖርትዎን ለመቀጠል በመስተንግዶ ያድሱ።',
    ],

    'payment_link_sent' => [
        'sms' => ':gym፦ ለ:plan :amount እዚህ ይክፈሉ፦ :url',
    ],

    'membership_expired' => [
        'title' => 'አባልነትዎ አብቅቷል',
        'body' => 'በ:gym ያለዎት የ:plan አባልነት :ends_on አብቅቷል። ስፖርትዎን ለመቀጠል ያድሱት።',
        'sms' => ':gym፦ የ:plan አባልነትዎ :ends_on አብቅቷል። ስፖርትዎን ለመቀጠል በመስተንግዶ ያድሱ።',
    ],

    'membership_frozen' => [
        'title' => 'አባልነትዎ ለጊዜው ቆሟል',
        'body' => 'በ:gym ያለዎት የ:plan አባልነት ከ:starts_on እስከ :ends_on ለጊዜው ቆሟል። አባልነትዎ አሁን :membership_ends_on ያበቃል።',
    ],

    'payment_succeeded' => [
        'title' => 'ክፍያዎ ደርሷል',
        'body' => 'በ:gym ለ:plan የከፈሉት :amount ተመዝግቧል።',
    ],

    'visit_denied' => [
        'title' => 'መግባት አልተፈቀደም',
        'body' => 'በ:branch መግባት አልቻሉም፦ :reason',
    ],

    'visits_running_low' => [
        'title' => 'አንድ ጉብኝት ብቻ ቀርቶዎታል',
        'body' => 'በ:gym ባለዎት የ:plan አባልነት አንድ ጉብኝት ብቻ ቀርቶዎታል። ስፖርትዎን ለመቀጠል ያድሱት።',
    ],

    'branch_closed' => [
        'title' => ':branch ዝግ ይሆናል',
        'body' => '{1} በ:gym የሚገኘው :branch :starts_on ዝግ ይሆናል፦ :reason።|[2,*] በ:gym የሚገኘው :branch ከ:starts_on እስከ :ends_on ዝግ ይሆናል፦ :reason።',
    ],

    'branch_hours_changed' => [
        'title' => ':branch በልዩ ሰዓት ይከፈታል',
        'body' => '{1} በ:gym የሚገኘው :branch :starts_on ከ:opens_at እስከ :closes_at ብቻ ክፍት ይሆናል፦ :reason።|[2,*] በ:gym የሚገኘው :branch ከ:starts_on እስከ :ends_on ከ:opens_at እስከ :closes_at ብቻ ክፍት ይሆናል፦ :reason።',
    ],

    'branch_reopened' => [
        'title' => ':branch እንደወትሮው ክፍት ነው',
        'body' => '{1} በ:gym የሚገኘው :branch :starts_on እንደወትሮው ክፍት ይሆናል።|[2,*] በ:gym የሚገኘው :branch ከ:starts_on እስከ :ends_on እንደወትሮው ክፍት ይሆናል።',
    ],

    'announcement' => [
        'title' => ':title',
        'body' => ':body',
    ],

    'reasons' => [
        'CREDENTIAL_INVALID' => 'QR ኮዱ አልታወቀም።',
        'CREDENTIAL_REVOKED' => 'የዚህ ስልክ QR ኮድ ተተክቷል። አዲስ ለማግኘት መተግበሪያውን ይክፈቱ።',
        'CODE_EXPIRED' => 'QR ኮዱ ጊዜው አልፎበታል። የስልክዎን ሰዓት ያረጋግጡ።',
        'CODE_ALREADY_USED' => 'ይህ QR ኮድ አስቀድሞ ተጠቅመውበታል።',
        'OUTSIDE_HOURS' => 'ቅርንጫፉ በዚህ ሰዓት ዝግ ነው።',
        'MEMBERSHIP_NONE' => 'ለዛሬ የሚሰራ አባልነት የለዎትም።',
        'MEMBERSHIP_EXPIRED' => 'አባልነትዎ አብቅቷል።',
        'MEMBERSHIP_FROZEN' => 'አባልነትዎ ዛሬ ለጊዜው ቆሟል።',
        'BRANCH_NOT_ALLOWED' => 'አባልነትዎ በዚህ ቅርንጫፍ አይሰራም።',
        'VISITS_USED_UP' => 'የአባልነትዎን ጉብኝቶች በሙሉ ተጠቅመዋል።',
        'ALREADY_CHECKED_IN' => 'ከ5 ደቂቃ በፊት ገብተዋል።',
    ],

];
