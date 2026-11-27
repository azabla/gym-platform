<?php

declare(strict_types=1);

// Amharic that follows docs/amharic-glossary.md, over Filament's own (ADR 0041).

return [
    'modal' => [
        'heading' => 'ማሳወቂያዎች',
        'unread_label' => 'ያልተነበበ ማሳወቂያ',
        'actions' => [
            'clear' => ['label' => 'ሁሉንም አጽዳ'],
            'mark_all_as_read' => ['label' => 'ሁሉንም እንደተነበበ ምልክት አድርግ'],
        ],
        'empty' => [
            'heading' => 'አዲስ ማሳወቂያ የለም',
            'description' => 'ቆይተው ይመልከቱ።',
        ],
    ],
];
