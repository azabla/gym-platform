<?php

declare(strict_types=1);

// Amharic that follows docs/amharic-glossary.md, over Filament's own (ADR 0041).

return [
    'single' => [
        'label' => 'አዲስ :label',
        'modal' => [
            'heading' => 'አዲስ :label',
            'actions' => [
                'create' => ['label' => 'አስቀምጥ'],
                'create_another' => ['label' => 'አስቀምጥና ሌላ አክል'],
            ],
        ],
        'notifications' => ['created' => ['title' => 'ተጨምሯል']],
    ],
];
