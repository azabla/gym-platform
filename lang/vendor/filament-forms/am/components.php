<?php

declare(strict_types=1);

// Amharic that follows docs/amharic-glossary.md, over Filament's own (ADR 0041).

return [
    'date_time_picker' => [
        'month_select' => ['label' => 'ወር'],
        'year_input' => ['label' => 'ዓመት'],
        'hour_input' => ['label' => 'ሰዓት'],
        'minute_input' => ['label' => 'ደቂቃ'],
        'second_input' => ['label' => 'ሰከንድ'],
    ],
    'js_field' => ['failed_to_load' => 'ይህ መስክ ሊጫን አልቻለም። እባክዎ ገጹን እንደገና ይጫኑ።'],
    'toggle_buttons' => [
        'boolean' => ['true' => 'አዎ', 'false' => 'አይ'],
        'required_description' => 'ቢያንስ አንዱን ይምረጡ።',
    ],
    'checkbox_list' => [
        'required_description' => 'ቢያንስ አንዱን ይምረጡ።',
        'actions' => [
            'deselect_all' => ['label' => 'ምርጫውን ተው'],
            'select_all' => ['label' => 'ሁሉንም ምረጥ'],
        ],
    ],
    'file_upload' => [
        'placeholder' => 'ምንም ፋይል አልተጫነም',
        'actions' => [
            'download' => ['label' => 'አውርድ'],
            'open' => ['label' => 'በአዲስ ገጽ ክፈት'],
        ],
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
        'columns' => [
            'actions' => ['label' => 'ተግባራት'],
            'reorder' => ['label' => 'አንቀሳቅስ'],
        ],
        'reordering' => [
            'item' => 'ንጥል',
            'position' => ':label፣ ቦታ :position ከ:count',
            'moved' => ':label ወደ ቦታ :position ከ:count ተዛውሯል',
        ],
    ],
    'select' => [
        'actions' => [
            'clear' => ['label' => 'ምርጫውን አጽዳ'],
            'create_option' => ['label' => 'አክል'],
            'edit_option' => ['label' => 'አስተካክል'],
            'remove_option' => ['label' => ':label አስወግድ'],
        ],
        'error_message' => 'አማራጮቹ ሊጫኑ አልቻሉም። እባክዎ እንደገና ይሞክሩ።',
        'no_options_message' => 'ምንም አማራጭ የለም።',
        'required_description' => 'አንዱን ይምረጡ።',
        'search_label' => 'ፈልግ',
        'boolean' => ['true' => 'አዎ', 'false' => 'አይ'],
        'loading_message' => 'እየተጫነ ነው…',
        'max_items_message' => 'መምረጥ የሚችሉት :count ብቻ ነው።',
        'no_search_results_message' => 'የሚመሳሰል አልተገኘም።',
        'placeholder' => 'ይምረጡ',
        'searching_message' => 'እየፈለገ ነው…',
        'search_prompt' => 'ለመፈለግ መጻፍ ይጀምሩ…',
    ],
];
