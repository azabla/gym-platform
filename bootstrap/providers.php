<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\StaffPanelProvider;

return [
    AppServiceProvider::class,
    StaffPanelProvider::class,
    AdminPanelProvider::class,
];
