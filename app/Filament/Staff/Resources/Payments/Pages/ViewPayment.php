<?php

declare(strict_types=1);

namespace App\Filament\Staff\Resources\Payments\Pages;

use App\Filament\Staff\Resources\Payments\PaymentResource;
use Filament\Resources\Pages\ViewRecord;

class ViewPayment extends ViewRecord
{
    protected static string $resource = PaymentResource::class;
}
