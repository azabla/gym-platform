<?php

declare(strict_types=1);

// Amharic that follows docs/amharic-glossary.md, over Filament's own (ADR 0041).

return [
    'single' => [
        'label' => 'ከማህደር መልስ',
        'modal' => [
            'heading' => ':label ከማህደር ይመለስ?',
            'actions' => ['restore' => ['label' => 'ከማህደር መልስ']],
        ],
        'notifications' => ['restored' => ['title' => 'ከማህደር ተመልሷል']],
    ],
    'multiple' => [
        'label' => 'የተመረጡትን ከማህደር መልስ',
        'modal' => [
            'heading' => 'የተመረጡት :label ከማህደር ይመለሱ?',
            'actions' => ['restore' => ['label' => 'ከማህደር መልስ']],
        ],
        'notifications' => ['restored' => ['title' => 'ከማህደር ተመልሰዋል']],
    ],
];
