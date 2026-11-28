<?php

declare(strict_types=1);

namespace App\Filament\Staff\Resources\ExpenseCategories\Pages;

use App\Filament\Staff\Resources\ExpenseCategories\ExpenseCategoryResource;
use Filament\Resources\Pages\ListRecords;

class ListExpenseCategories extends ListRecords
{
    protected static string $resource = ExpenseCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ExpenseCategoryResource::createAction(),
        ];
    }
}
