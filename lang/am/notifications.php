<?php

/*
| Amharic messages to members, with the same keys and placeholders as
| lang/en/notifications.php; a key missing here falls back to English. Like
| the English, they name the gym and the plan, never a person. Drafted for a
| fluent speaker to review (ADR 0034).
*/

return [

    'membership_expiring' => [
        'title' => 'አባልነትዎ በቅርቡ ያበቃል',
        'body' => '{1} በ:gym ያለዎት የ:plan አባልነት ነገ፣ :ends_on፣ ያበቃል።|[2,*] በ:gym ያለዎት የ:plan አባልነት በ:count ቀናት ውስጥ፣ :ends_on ላይ ያበቃል።',
        'sms' => ':gym፦ የ:plan አባልነትዎ :ends_on ላይ ያበቃል። ስፖርትዎን ለመቀጠል በእንግዳ መቀበያ ያድሱ።',
    ],

    'payment_link_sent' => [
        'sms' => ':gym፦ ለ:plan :amount እዚህ ይክፈሉ፦ :url',
    ],

    'membership_expired' => [
        'title' => 'አባልነትዎ አብቅቷል',
        'body' => 'በ:gym ያለዎት የ:plan አባልነት :ends_on ላይ አብቅቷል። ስፖርትዎን ለመቀጠል ያድሱት።',
        'sms' => ':gym፦ የ:plan አባልነትዎ :ends_on ላይ አብቅቷል። ስፖርትዎን ለመቀጠል በእንግዳ መቀበያ ያድሱ።',
    ],

    'membership_frozen' => [
        'title' => 'አባልነትዎ ለጊዜው ቆሟል',
        'body' => 'በ:gym ያለዎት የ:plan አባልነት ከ:starts_on እስከ :ends_on ለጊዜው ቆሟል። አሁን :membership_ends_on ላይ ያበቃል።',
    ],

    'payment_succeeded' => [
        'title' => 'ክፍያ ደርሷል',
        'body' => 'በ:gym ለ:plan የከፈሉት :amount ተመዝግቧል።',
    ],

    'visit_denied' => [
        'title' => 'መግባት አልተፈቀደም',
        'body' => ':branch ላይ መግባት አልቻሉም፦ :reason',
    ],

    'visits_running_low' => [
        'title' => 'አንድ ጉብኝት ቀርቶዎታል',
        'body' => 'በ:gym ባለዎት የ:plan አባልነት አንድ ጉብኝት ብቻ ቀርቶዎታል። ስፖርትዎን ለመቀጠል ያድሱት።',
    ],

    'branch_closed' => [
        'title' => ':branch ዝግ ይሆናል',
        'body' => '{1} በ:gym የሚገኘው :branch :starts_on ላይ ዝግ ይሆናል፦ :reason።|[2,*] በ:gym የሚገኘው :branch ከ:starts_on እስከ :ends_on ዝግ ይሆናል፦ :reason።',
    ],

    'branch_hours_changed' => [
        'title' => ':branch በተለየ ሰዓት ይከፈታል',
        'body' => '{1} በ:gym የሚገኘው :branch :starts_on ላይ ከ:opens_at እስከ :closes_at ብቻ ክፍት ይሆናል፦ :reason።|[2,*] በ:gym የሚገኘው :branch ከ:starts_on እስከ :ends_on ከ:opens_at እስከ :closes_at ብቻ ክፍት ይሆናል፦ :reason።',
    ],

    // The gym's own words, the same in every language (ADR 0015).
    'announcement' => [
        'title' => ':title',
        'body' => ':body',
    ],

    'reasons' => [
        'CREDENTIAL_INVALID' => 'QR ኮዱ አልታወቀም።',
        'CREDENTIAL_REVOKED' => 'የዚህ ስልክ QR ኮድ ተተክቷል። አዲስ ለማግኘት መተግበሪያውን ይክፈቱ።',
        'CODE_EXPIRED' => 'QR ኮዱ ጊዜው አልፎበታል። የስልክዎን ሰዓት ያረጋግጡ።',
        'CODE_ALREADY_USED' => 'ያ QR ኮድ ቀድሞ ጥቅም ላይ ውሏል።',
        'OUTSIDE_HOURS' => 'ቅርንጫፉ በዚህ ሰዓት ዝግ ነው።',
        'MEMBERSHIP_NONE' => 'ለዛሬ አባልነት የለዎትም።',
        'MEMBERSHIP_EXPIRED' => 'አባልነትዎ አብቅቷል።',
        'MEMBERSHIP_FROZEN' => 'አባልነትዎ ዛሬ ለጊዜው ቆሟል።',
        'BRANCH_NOT_ALLOWED' => 'አባልነትዎ በዚህ ቅርንጫፍ አያገለግልም።',
        'VISITS_USED_UP' => 'የአባልነትዎን ጉብኝቶች በሙሉ ተጠቅመዋል።',
        'ALREADY_CHECKED_IN' => 'ከ5 ደቂቃ ባነሰ ጊዜ በፊት ገብተዋል።',
    ],

];
