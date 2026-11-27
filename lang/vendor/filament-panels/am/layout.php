<?php

declare(strict_types=1);

// Amharic that follows docs/amharic-glossary.md, over Filament's own (ADR 0041).

return [
    'actions' => [
        'logout' => ['label' => 'ውጣ'],
        'open_database_notifications' => [
            'label' => 'ማሳወቂያዎችን ክፈት',
            'label_with_unread_count' => '{1} ማሳወቂያዎች፣ 1 ያልተነበበ|[2,*] ማሳወቂያዎች፣ :count ያልተነበቡ',
        ],
        'open_user_menu' => ['label' => 'የተጠቃሚ ምናሌ'],
        'sidebar' => [
            'collapse' => ['label' => 'የጎን ምናሌውን ሰብስብ'],
            'expand' => ['label' => 'የጎን ምናሌውን ዘርጋ'],
        ],
        'theme_switcher' => [
            'label' => 'ገጽታ',
            'dark' => ['label' => 'ጨለማ ገጽታ'],
            'light' => ['label' => 'ብሩህ ገጽታ'],
            'system' => ['label' => 'እንደ መሳሪያው'],
        ],
    ],
    'skip_to_content' => ['label' => 'ወደ ዋናው ይዘት ዝለል'],
    'navigation' => ['label' => 'የጎን ምናሌ'],
    'topbar' => ['label' => 'የላይኛው ምናሌ'],
    'tenant_menu' => [
        'search_field' => ['label' => 'ጂም ፈልግ', 'placeholder' => 'ፈልግ'],
    ],
];
