<?php

declare(strict_types=1);

// Amharic that follows docs/amharic-glossary.md, over Filament's own (ADR 0041).

return [
    'actions' => [
        'filter' => ['label' => 'አጣራ'],
    ],
    'filter' => ['label' => 'የገበታውን መረጃ አጣራ'],
    'filters' => [
        'actions' => [
            'apply' => ['label' => 'አጣራ'],
            'reset' => ['label' => 'እንደነበረ መልስ'],
        ],
    ],
    'empty' => ['heading' => 'የሚታይ መረጃ የለም'],
];
