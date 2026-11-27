<?php

declare(strict_types=1);

// Amharic that follows docs/amharic-glossary.md, over Filament's own (ADR 0041).

return [
    'column_manager' => ['heading' => 'ዓምዶች'],
    'columns' => [
        'text' => [
            'actions' => [
                'collapse_list' => ':count ያነሰ አሳይ',
                'expand_list' => ':count ተጨማሪ አሳይ',
            ],
            'more_list_items' => 'እና :count ተጨማሪ',
        ],
    ],
    'fields' => [
        'bulk_select_page' => ['label' => 'ሁሉንም ምረጥ ወይም ተው'],
        'bulk_select_record' => ['label' => ':key ምረጥ ወይም ተው'],
        'search' => ['label' => 'ፈልግ', 'placeholder' => 'ፈልግ', 'indicator' => 'ፍለጋ'],
    ],
    'actions' => [
        'filter' => ['label' => 'አጣራ'],
        'open_bulk_actions' => ['label' => 'ለተመረጡት'],
        'column_manager' => ['label' => 'ዓምዶችን አሳይ ወይም ደብቅ'],
    ],
    'empty' => [
        'heading' => ':model የሉም',
        'description' => 'ለመጀመር አዲስ :model ያክሉ።',
    ],
    'filters' => [
        'actions' => [
            'apply' => ['label' => 'አጣራ'],
            'remove' => ['label' => 'ማጣሪያውን አስወግድ'],
            'remove_all' => ['label' => 'ሁሉንም ማጣሪያዎች አስወግድ', 'tooltip' => 'ሁሉንም ማጣሪያዎች አስወግድ'],
            'reset' => ['label' => 'እንደነበረ መልስ'],
        ],
        'heading' => 'ማጣሪያዎች',
        'indicator' => 'የተመረጡ ማጣሪያዎች',
        'multi_select' => ['placeholder' => 'ሁሉም'],
        'select' => ['placeholder' => 'ሁሉም'],
        'trashed' => [
            'label' => 'በማህደር ያሉ',
            'only_trashed' => 'በማህደር ያሉ ብቻ',
            'with_trashed' => 'በማህደር ያሉትንም ጨምሮ',
            'without_trashed' => 'በማህደር ያሉትን ሳይጨምር',
        ],
    ],
    'reorder_indicator' => 'ረድፎቹን ጎትተው በሚፈልጉት ቅደም ተከተል ያስቀምጡ።',
    'selection_indicator' => [
        'selected_count' => '{1} 1 ረድፍ ተመርጧል|[2,*] :count ረድፎች ተመርጠዋል',
        'actions' => [
            'select_all' => ['label' => 'ሁሉንም :count ምረጥ'],
            'deselect_all' => ['label' => 'ምርጫውን ተው'],
        ],
    ],
    'sorting' => [
        'fields' => [
            'column' => ['label' => 'መደርደሪያ'],
            'direction' => [
                'label' => 'አቅጣጫ',
                'options' => ['asc' => 'ከትንሽ ወደ ትልቅ', 'desc' => 'ከትልቅ ወደ ትንሽ'],
            ],
        ],
    ],
    'default_model_label' => 'መዝገብ',
];
