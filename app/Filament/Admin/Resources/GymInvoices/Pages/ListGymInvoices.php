<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\GymInvoices\Pages;

use App\Filament\Admin\Resources\GymInvoices\GymInvoiceResource;
use Filament\Resources\Pages\ListRecords;

class ListGymInvoices extends ListRecords
{
    protected static string $resource = GymInvoiceResource::class;
}
