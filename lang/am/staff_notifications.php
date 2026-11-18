<?php

/*
| Amharic messages to staff, with the same keys and placeholders as
| lang/en/staff_notifications.php, for staff who chose Amharic. Drafted for a
| fluent speaker to review (ADR 0034).
*/

return [

    'staff_online_payment' => [
        'title' => 'በኦንላይን ተከፍሏል',
        'body' => ':member ለ:plan :amount በኦንላይን ከፍለዋል (ደረሰኝ :receipt)።',
        'sms' => ':gym፦ :member ለ:plan :amount በኦንላይን ከፍለዋል (ደረሰኝ :receipt)።',
    ],

    'staff_refund_needed' => [
        'title' => 'ገንዘብ መመለስ ያስፈልጋል',
        'body' => 'Chapa ከ:member ያልተገባ :amount ወስዷል። ከጂሙ የChapa ዳሽቦርድ ይመልሱት።',
        'sms' => ':gym፦ ገንዘብ መመለስ ያስፈልጋል። Chapa ከ:member ያልተገባ :amount ወስዷል። ከጂሙ የChapa ዳሽቦርድ ይመልሱት።',
    ],

    'staff_member_froze' => [
        'title' => 'በመተግበሪያው ቆሟል',
        'body' => ':member የ:plan አባልነታቸውን በመተግበሪያው ከ:starts_on እስከ :ends_on አቁመዋል። አሁን :membership_ends_on ላይ ያበቃል።',
        'sms' => ':gym፦ :member የ:plan አባልነታቸውን በመተግበሪያው ከ:starts_on እስከ :ends_on አቁመዋል። አሁን :membership_ends_on ላይ ያበቃል።',
    ],

    'staff_member_ended_freeze' => [
        'title' => 'ቀድመው ተመልሰዋል',
        'body' => ':member የ:plan አባልነታቸውን ማቆም በመተግበሪያው ቀድመው አብቅተዋል። አሁን :membership_ends_on ላይ ያበቃል።',
        'sms' => ':gym፦ :member የ:plan አባልነታቸውን ማቆም ቀድመው አብቅተዋል። አሁን :membership_ends_on ላይ ያበቃል።',
    ],

    'staff_members_to_call' => [
        'title' => 'የሚደወልላቸው አባላት',
        'body' => '{1} የሚደወልለት 1 አባል አለ፦ በሚቀጥሉት 7 ቀናት የሚያበቃ አባልነት አለ፣ ከዚያ በኋላ ምንም አልተሸጠም።|[2,*] የሚደወልላቸው :count አባላት አሉ፦ በሚቀጥሉት 7 ቀናት የሚያበቁ አባልነቶች አሉ፣ ከዚያ በኋላ ምንም አልተሸጠም።',
        'sms' => '{1} :gym፦ ዛሬ የሚደወልለት 1 አባል አለ፤ አባልነቱ በ7 ቀናት ውስጥ ያበቃል።|[2,*] :gym፦ ዛሬ የሚደወልላቸው :count አባላት አሉ፤ አባልነታቸው በ7 ቀናት ውስጥ ያበቃል።',
    ],

];
