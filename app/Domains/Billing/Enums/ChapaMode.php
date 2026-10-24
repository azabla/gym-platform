<?php

declare(strict_types=1);

namespace App\Domains\Billing\Enums;

/**
 * Whether a Chapa key takes real money (live) or only Chapa's test payments.
 * Test keys contain "_TEST", such as CHASECK_TEST-…. See ADR 0009.
 */
enum ChapaMode: string
{
    case Test = 'test';
    case Live = 'live';

    public static function ofKey(string $secretKey): self
    {
        return str_contains($secretKey, '_TEST') ? self::Test : self::Live;
    }
}
