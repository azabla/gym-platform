<?php

declare(strict_types=1);

namespace App\Domains\Attendance\DoorList\Exceptions;

use RuntimeException;

/**
 * Door lists can't be signed: DOOR_LIST_SIGNING_KEY is missing or isn't a
 * key. See ADR 0038.
 */
final class DoorListNotSetUp extends RuntimeException
{
    public static function withoutAKey(): self
    {
        return new self('Offline scanning is not set up: DOOR_LIST_SIGNING_KEY must be a base64 Ed25519 secret key (php artisan door-list:generate-key).');
    }
}
