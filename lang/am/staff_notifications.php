<?php

/*
| Amharic messages to staff, with the same keys and placeholders as
| lang/en/staff_notifications.php, for staff who chose Amharic. Words follow
| docs/amharic-glossary.md; dates come written out, the Ethiopian date with
| the Gregorian beside it, or the Ethiopian date alone in an SMS (ADR 0034,
| ADR 0041). For a fluent speaker to review.
*/

return [

    'staff_online_payment' => [
        'title' => 'በኦንላይን ተከፍሏል',
        'body' => ':member ለ:plan :amount በኦንላይን ከፍለዋል (ደረሰኝ :receipt)።',
        'sms' => ':gym፦ :member ለ:plan :amount በኦንላይን ከፍለዋል (ደረሰኝ :receipt)።',
    ],

    'staff_refund_needed' => [
        'title' => 'ገንዘብ መመለስ ያስፈልጋል',
        'body' => 'ቻፓ ከ:member ያልተገባ :amount ተቀብሏል። ከጂሙ የቻፓ ዳሽቦርድ ገንዘቡን ይመልሱ።',
        'sms' => ':gym፦ ገንዘብ መመለስ ያስፈልጋል። ቻፓ ከ:member ያልተገባ :amount ተቀብሏል። ከጂሙ የቻፓ ዳሽቦርድ ገንዘቡን ይመልሱ።',
    ],

    'staff_member_froze' => [
        'title' => 'በመተግበሪያው ለጊዜው ቆሟል',
        'body' => ':member የ:plan አባልነታቸውን በመተግበሪያው ከ:starts_on እስከ :ends_on ለጊዜው አቁመዋል። አባልነቱ አሁን :membership_ends_on ያበቃል።',
        'sms' => ':gym፦ :member የ:plan አባልነታቸውን በመተግበሪያው ከ:starts_on እስከ :ends_on አቁመዋል። አሁን :membership_ends_on ያበቃል።',
    ],

    'staff_member_ended_freeze' => [
        'title' => 'ቀድመው ተመልሰዋል',
        'body' => ':member የ:plan አባልነታቸውን ማቆም በመተግበሪያው ቀድመው አንስተዋል። አባልነቱ አሁን :membership_ends_on ያበቃል።',
        'sms' => ':gym፦ :member የ:plan አባልነታቸውን ማቆም ቀድመው አንስተዋል። አሁን :membership_ends_on ያበቃል።',
    ],

    'staff_members_to_call' => [
        'title' => 'የሚደወልላቸው አባላት',
        'body' => '{1} የሚደወልለት 1 አባል አለ፦ አባልነቱ በሚቀጥሉት 7 ቀናት ያበቃል፣ ከዚያ በኋላ ምንም አልተሸጠለትም።|[2,*] የሚደወልላቸው :count አባላት አሉ፦ አባልነታቸው በሚቀጥሉት 7 ቀናት ያበቃል፣ ከዚያ በኋላ ምንም አልተሸጠላቸውም።',
        'sms' => '{1} :gym፦ ዛሬ የሚደወልለት 1 አባል አለ፤ አባልነቱ በ7 ቀን ውስጥ ያበቃል።|[2,*] :gym፦ ዛሬ የሚደወልላቸው :count አባላት አሉ፤ አባልነታቸው በ7 ቀን ውስጥ ያበቃል።',
    ],

];
