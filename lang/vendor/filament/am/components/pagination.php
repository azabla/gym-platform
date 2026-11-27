<?php

declare(strict_types=1);

// Amharic that follows docs/amharic-glossary.md, over Filament's own (ADR 0041).

return [
    'label' => 'ገጾች',
    'overview' => '{1} 1 ረድፍ|[2,*] ከ:total ረድፎች ከ:first እስከ :last',
    'fields' => [
        'records_per_page' => [
            'label' => 'በአንድ ገጽ',
            'options' => ['all' => 'ሁሉም'],
        ],
    ],
    'actions' => [
        'first' => ['label' => 'የመጀመሪያው'],
        'go_to_page' => ['label' => 'ወደ ገጽ :page'],
        'last' => ['label' => 'የመጨረሻው'],
        'next' => ['label' => 'ቀጣይ'],
        'previous' => ['label' => 'ያለፈው'],
    ],
];
