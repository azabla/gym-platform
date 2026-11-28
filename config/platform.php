<?php

return [

    /*
    |--------------------------------------------------------------------------
    | The platform, as it appears on its invoices to gyms
    |--------------------------------------------------------------------------
    |
    | Who bills the gyms (ADR 0045): the name, TIN, address and phone printed
    | on each invoice, and the accounts gyms pay into, shown on the invoice
    | and on the owner's bill page. Accounts are separated by "|", each as
    | "Bank or wallet: account number (name)".
    |
    */

    'name' => env('PLATFORM_NAME', 'Gym Platform'),

    'tin' => env('PLATFORM_TIN'),

    'address' => env('PLATFORM_ADDRESS', 'Addis Ababa, Ethiopia'),

    'phone' => env('PLATFORM_PHONE'),

    'pay_to' => array_values(array_filter(array_map('trim', explode('|', (string) env('PLATFORM_PAY_TO', ''))))),

];
