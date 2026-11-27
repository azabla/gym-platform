<?php

declare(strict_types=1);

// Amharic that follows docs/amharic-glossary.md, over Filament's own (ADR 0041).

return [
    'checkbox_list' => [
        'actions' => [
            'deselect_all' => ['label' => 'ምርጫውን ተው'],
            'select_all' => ['label' => 'ሁሉንም ምረጥ'],
        ],
    ],
    'file_upload' => [
        'editor' => [
            'actions' => [
                'cancel' => ['label' => 'ተው'],
                'drag_crop' => ['label' => 'ለመቁረጥ ጎትት'],
                'drag_move' => ['label' => 'ለማንቀሳቀስ ጎትት'],
                'flip_horizontal' => ['label' => 'በአግድም ገልብጥ'],
                'flip_vertical' => ['label' => 'በቁመት ገልብጥ'],
                'move_down' => ['label' => 'ወደ ታች አንቀሳቅስ'],
                'zoom_in' => ['label' => 'አቅርብ'],
                'zoom_out' => ['label' => 'አርቅ'],
                'zoom_100' => ['label' => 'ሙሉ መጠን'],
                'save' => ['label' => 'አስቀምጥ'],
                'reset' => ['label' => 'እንደነበረ መልስ'],
            ],
            'fields' => [
                'rotation' => ['label' => 'አዙር', 'unit' => 'ዲግሪ'],
            ],
            'aspect_ratios' => [
                'label' => 'የጎን ምጣኔ',
                'no_fixed' => ['label' => 'ነጻ'],
            ],
        ],
    ],
    'radio' => [
        'boolean' => ['true' => 'አዎ', 'false' => 'አይ'],
    ],
    'repeater' => [
        'actions' => [
            'add' => ['label' => 'አክል'],
            'add_between' => ['label' => 'በመካከል አክል'],
            'delete' => ['label' => 'አስወግድ'],
            'clone' => ['label' => 'ቅዳ'],
            'reorder' => ['label' => 'አንቀሳቅስ'],
        ],
    ],
    'select' => [
        'boolean' => ['true' => 'አዎ', 'false' => 'አይ'],
        'loading_message' => 'እየተጫነ ነው…',
        'max_items_message' => 'መምረጥ የሚችሉት :count ብቻ ነው።',
        'no_search_results_message' => 'የሚመሳሰል አልተገኘም።',
        'placeholder' => 'ይምረጡ',
        'searching_message' => 'እየፈለገ ነው…',
        'search_prompt' => 'ለመፈለግ መጻፍ ይጀምሩ…',
    ],
    'toggle_buttons' => [
        'boolean' => ['true' => 'አዎ', 'false' => 'አይ'],
    ],
];
