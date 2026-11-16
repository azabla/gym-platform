<?php

declare(strict_types=1);

namespace App\Filament\Staff\Resources\PaymentLinks\Pages;

use App\Filament\Staff\Resources\PaymentLinks\PaymentLinkResource;
use Filament\Resources\Pages\ListRecords;

class ListPaymentLinks extends ListRecords
{
    protected static string $resource = PaymentLinkResource::class;
}
