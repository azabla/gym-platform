<?php

/*
| Amharic validation messages for the staff panel (ADR 0041), for the rules
| it uses. A rule missing here falls back to English. :attribute is the
| field's label, already in Amharic on the panel. Drafted for a fluent
| speaker to review.
*/

return [

    'accepted' => ':attribute መቀበል አለበት።',
    'after' => ':attribute ከ:date በኋላ መሆን አለበት።',
    'after_or_equal' => ':attribute ከ:date ጋር እኩል ወይም በኋላ መሆን አለበት።',
    'array' => ':attribute ዝርዝር መሆን አለበት።',
    'before' => ':attribute ከ:date በፊት መሆን አለበት።',
    'before_or_equal' => ':attribute ከ:date ጋር እኩል ወይም በፊት መሆን አለበት።',
    'between' => [
        'numeric' => ':attribute በ:min እና በ:max መካከል መሆን አለበት።',
        'string' => ':attribute ከ:min እስከ :max ፊደላት መሆን አለበት።',
        'array' => ':attribute ከ:min እስከ :max ነገሮች ሊኖሩት ይገባል።',
        'file' => ':attribute ከ:min እስከ :max ኪሎባይት መሆን አለበት።',
    ],
    'boolean' => ':attribute አዎ ወይም አይደለም መሆን አለበት።',
    'confirmed' => ':attribute ማረጋገጫው አይዛመድም።',
    'date' => ':attribute ትክክለኛ ቀን መሆን አለበት።',
    'date_format' => ':attribute ከ:format ቅርጽ ጋር መዛመድ አለበት።',
    'different' => ':attribute እና :other የተለያዩ መሆን አለባቸው።',
    'digits' => ':attribute :digits አሃዝ መሆን አለበት።',
    'distinct' => ':attribute የተደገመ ዋጋ አለው።',
    'email' => ':attribute ትክክለኛ የኢሜይል አድራሻ መሆን አለበት።',
    'enum' => 'የተመረጠው :attribute ትክክል አይደለም።',
    'exists' => 'የተመረጠው :attribute ትክክል አይደለም።',
    'file' => ':attribute ፋይል መሆን አለበት።',
    'filled' => ':attribute ዋጋ ሊኖረው ይገባል።',
    'gt' => [
        'numeric' => ':attribute ከ:value በላይ መሆን አለበት።',
    ],
    'gte' => [
        'numeric' => ':attribute ከ:value ጋር እኩል ወይም በላይ መሆን አለበት።',
    ],
    'image' => ':attribute ምስል መሆን አለበት።',
    'in' => 'የተመረጠው :attribute ትክክል አይደለም።',
    'integer' => ':attribute ሙሉ ቁጥር መሆን አለበት።',
    'lt' => [
        'numeric' => ':attribute ከ:value በታች መሆን አለበት።',
    ],
    'lte' => [
        'numeric' => ':attribute ከ:value ጋር እኩል ወይም በታች መሆን አለበት።',
    ],
    'max' => [
        'numeric' => ':attribute ከ:max መብለጥ የለበትም።',
        'string' => ':attribute ከ:max ፊደላት መብለጥ የለበትም።',
        'array' => ':attribute ከ:max ነገሮች በላይ ሊኖሩት አይገባም።',
        'file' => ':attribute ከ:max ኪሎባይት መብለጥ የለበትም።',
    ],
    'mimes' => ':attribute ከእነዚህ የፋይል አይነቶች አንዱ መሆን አለበት፦ :values።',
    'min' => [
        'numeric' => ':attribute ቢያንስ :min መሆን አለበት።',
        'string' => ':attribute ቢያንስ :min ፊደላት መሆን አለበት።',
        'array' => ':attribute ቢያንስ :min ነገሮች ሊኖሩት ይገባል።',
        'file' => ':attribute ቢያንስ :min ኪሎባይት መሆን አለበት።',
    ],
    'numeric' => ':attribute ቁጥር መሆን አለበት።',
    'present' => ':attribute መኖር አለበት።',
    'prohibited' => ':attribute አይፈቀድም።',
    'prohibited_if' => ':other :value ሲሆን :attribute አይፈቀድም።',
    'regex' => ':attribute ቅርጹ ትክክል አይደለም።',
    'required' => ':attribute ያስፈልጋል።',
    'required_if' => ':other :value ሲሆን :attribute ያስፈልጋል።',
    'required_with' => ':values ሲኖር :attribute ያስፈልጋል።',
    'required_without' => ':values ከሌለ :attribute ያስፈልጋል።',
    'same' => ':attribute እና :other መዛመድ አለባቸው።',
    'size' => [
        'numeric' => ':attribute :size መሆን አለበት።',
        'string' => ':attribute :size ፊደላት መሆን አለበት።',
    ],
    'string' => ':attribute ጽሑፍ መሆን አለበት።',
    'unique' => ':attribute አስቀድሞ ተይዟል።',
    'uploaded' => ':attribute መጫን አልተቻለም።',
    'url' => ':attribute ትክክለኛ አድራሻ መሆን አለበት።',

    'attributes' => [],

];
