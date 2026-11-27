<?php

declare(strict_types=1);

// Amharic that follows docs/amharic-glossary.md, over Filament's own (ADR 0041).

return [
    'js_component' => ['failed_to_load' => 'ይህ ክፍል ሊጫን አልቻለም። እባክዎ ገጹን እንደገና ይጫኑ።'],
    'callout' => [
        'statuses' => [
            'danger' => 'ስህተት፦',
            'info' => 'ማስታወሻ፦',
            'success' => 'ተሳክቷል፦',
            'warning' => 'ማሳሰቢያ፦',
        ],
    ],
    'section' => [
        'actions' => [
            'collapse' => ['label' => 'ክፍሉን ሰብስብ'],
            'expand' => ['label' => 'ክፍሉን ዘርጋ'],
        ],
    ],
    'tabs' => [
        'actions' => ['more' => ['label' => 'ተጨማሪ']],
    ],
    'wizard' => [
        'actions' => [
            'previous_step' => ['label' => 'ወደ ኋላ'],
            'next_step' => ['label' => 'ቀጣይ'],
        ],
        'header' => [
            'step' => ['statuses' => ['completed' => 'ተጠናቋል', 'upcoming' => 'አልተጠናቀቀም']],
        ],
    ],
];
