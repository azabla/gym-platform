<?php

declare(strict_types=1);

// Amharic that follows docs/amharic-glossary.md, over Filament's own (ADR 0041).

return [
    'title' => ':label አስተካክል',
    'breadcrumb' => 'አስተካክል',
    'navigation_label' => 'አስተካክል',
    'form' => [
        'actions' => [
            'cancel' => ['label' => 'ተው'],
            'save' => ['label' => 'አስቀምጥ'],
        ],
    ],
    'content' => ['tab' => ['label' => 'አስተካክል']],
    'notifications' => ['saved' => ['title' => 'ተቀምጧል']],
];
