<?php

declare(strict_types=1);

namespace App\Domains\Organizations\Exceptions;

use DomainException;

/**
 * A change to a gym's staff that would leave it without an owner, or add
 * someone who already works there. The field names the request field the
 * message belongs to. See ADR 0019.
 */
final class StaffRefused extends DomainException
{
    private function __construct(string $message, public readonly string $field)
    {
        parent::__construct($message);
    }

    public static function lastOwner(): self
    {
        return new self('The gym must keep at least one owner.', 'role');
    }

    public static function alreadyWorksHere(): self
    {
        return new self('This person already works at the gym.', 'phone');
    }
}
