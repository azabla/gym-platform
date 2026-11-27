<?php

declare(strict_types=1);

// Amharic that follows docs/amharic-glossary.md, over Filament's own (ADR 0041).

return [
    'single' => [
        'label' => 'አጥፋ',
        'modal' => [
            'heading' => ':label ይጥፋ?',
            'actions' => ['delete' => ['label' => 'አጥፋ']],
        ],
        'notifications' => ['deleted' => ['title' => 'ጠፍቷል']],
    ],
    'multiple' => [
        'label' => 'የተመረጡትን አጥፋ',
        'modal' => [
            'heading' => 'የተመረጡት :label ይጥፉ?',
            'actions' => ['delete' => ['label' => 'አጥፋ']],
        ],
        'notifications' => ['deleted' => ['title' => 'ጠፍተዋል']],
    ],
];
