<?php

declare(strict_types=1);

// Amharic that follows docs/amharic-glossary.md, over Filament's own (ADR 0041).

return [
    'entries' => [
        'icon' => ['true' => 'አዎ', 'false' => 'አይ'],
        'text' => [
            'actions' => [
                'collapse_list' => ':count ያነሰ አሳይ',
                'expand_list' => ':count ተጨማሪ አሳይ',
            ],
            'more_list_items' => 'እና :count ተጨማሪ',
        ],
    ],
];
