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

    // የሲስተሙ አገልግሎት ክፍያ፣ ለጂሙ ባለቤቶች ብቻ (ADR 0045)።

    'staff_bill_issued' => [
        'title' => 'አዲስ የአገልግሎት ክፍያ መጠየቂያ',
        'body' => 'ከ:starts_on እስከ :ends_on ያለው የ:amount የክፍያ መጠየቂያ :invoice የመክፈያ ቀኑ :due_on ነው። እስከ :pauses_on ካልተከፈለ ጂሙ ለጊዜው ይቋረጣል። እንዴት እንደሚከፈል በ«የአገልግሎት ክፍያ» ገጽ ይመልከቱ።',
        'sms' => ':gym፦ የ:amount የአገልግሎት ክፍያ መጠየቂያ :invoice የመክፈያ ቀኑ :due_on ነው። እስከ :pauses_on ካልተከፈለ ጂሙ ለጊዜው ይቋረጣል። ሲከፍሉ :invoice ብለው ይጻፉ።',
    ],

    'staff_bill_due_today' => [
        'title' => 'የአገልግሎት ክፍያ ዛሬ ይከፈላል',
        'body' => 'የክፍያ መጠየቂያ :invoice የመክፈያ ቀኑ ዛሬ ነው፦ ቀሪ ዕዳ :amount። እስከ :pauses_on ካልተከፈለ ጂሙ ለጊዜው ይቋረጣል።',
        'sms' => ':gym፦ የአገልግሎት ክፍያ መጠየቂያ :invoice የመክፈያ ቀኑ ዛሬ ነው፤ ቀሪ ዕዳ :amount። እስከ :pauses_on ካልተከፈለ ጂሙ ለጊዜው ይቋረጣል።',
    ],

    'staff_bill_overdue' => [
        'title' => 'የአገልግሎት ክፍያ የመክፈያ ጊዜው አልፏል',
        'body' => 'የክፍያ መጠየቂያ :invoice የመክፈያ ቀኑ :due_on ነበር፦ ቀሪ ዕዳ :amount። እስከ :pauses_on ካልተከፈለ ጂሙ ለጊዜው ይቋረጣል።',
        'sms' => ':gym፦ የአገልግሎት ክፍያ መጠየቂያ :invoice የመክፈያ ጊዜው አልፏል፤ ቀሪ ዕዳ :amount። እስከ :pauses_on ካልተከፈለ ጂሙ ለጊዜው ይቋረጣል።',
    ],

    'staff_bill_pauses_tomorrow' => [
        'title' => 'ጂሙ ነገ ለጊዜው ይቋረጣል',
        'body' => 'የክፍያ መጠየቂያ :invoice እስካሁን አልተከፈለም፦ :amount። ነገ ጂሙ ለጊዜው ይቋረጣል፦ ሰራተኞች መግባት አይችሉም፣ በሩና የአባላት መተግበሪያው ይቆማሉ። ጂሙ ክፍት እንዲቆይ ዛሬ ይክፈሉ።',
        'sms' => ':gym፦ የአገልግሎት ክፍያ መጠየቂያ :invoice አልተከፈለም፤ :amount። ዛሬ ካልተከፈለ ጂሙ ነገ ለጊዜው ይቋረጣል።',
    ],

    'staff_bill_paused' => [
        'title' => 'ጂሙ ባልተከፈለ ክፍያ ምክንያት ለጊዜው ተቋርጧል',
        'body' => 'የክፍያ መጠየቂያ :invoice ስላልተከፈለ ጂሙ ለጊዜው ተቋርጧል፦ ሰራተኞች መግባት አይችሉም፣ በሩና የአባላት መተግበሪያው ቆመዋል። ምንም አልተሰረዘም። :amount ሲከፍሉ ጂሙ በራሱ ይከፈታል።',
        'sms' => ':gym ለጊዜው ተቋርጧል፦ የአገልግሎት ክፍያ መጠየቂያ :invoice አልተከፈለም። :invoice ብለው :amount ሲከፍሉ በራሱ ይከፈታል።',
    ],

    'staff_bill_paid' => [
        'title' => 'የአገልግሎት ክፍያ ተከፍሏል',
        'body' => 'እናመሰግናለን፦ የ:amount የክፍያ መጠየቂያ :invoice ተከፍሏል።',
        'sms' => ':gym፦ እናመሰግናለን፤ የ:amount የአገልግሎት ክፍያ መጠየቂያ :invoice ተከፍሏል።',
    ],

    'staff_bill_paid_reopened' => [
        'title' => 'ተከፍሏል፦ ጂሙ እንደገና ተከፍቷል',
        'body' => 'እናመሰግናለን፦ የ:amount የክፍያ መጠየቂያ :invoice ተከፍሏል፣ ጂሙም እንደገና ተከፍቷል። ሰራተኞች መግባት ይችላሉ፣ በሩና የአባላት መተግበሪያው ይሰራሉ።',
        'sms' => ':gym፦ እናመሰግናለን፤ የአገልግሎት ክፍያ መጠየቂያ :invoice ተከፍሏል። ጂሙ እንደገና ተከፍቷል።',
    ],

];
