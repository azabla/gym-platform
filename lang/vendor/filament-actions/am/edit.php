<?php

declare(strict_types=1);

// Amharic that follows docs/amharic-glossary.md, over Filament's own (ADR 0041).

return [
    'single' => [
        'label' => 'አስተካክል',
        'modal' => [
            'heading' => ':label አስተካክል',
            'actions' => ['save' => ['label' => 'አስቀምጥ']],
        ],
        'notifications' => ['saved' => ['title' => 'ተቀምጧል']],
    ],
];
