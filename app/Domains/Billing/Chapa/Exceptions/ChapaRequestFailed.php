<?php

declare(strict_types=1);

namespace App\Domains\Billing\Chapa\Exceptions;

use RuntimeException;

/**
 * A request to Chapa that didn't succeed: either Chapa refused it (the key,
 * or the details sent) or Chapa didn't answer. The API answers 502 unless a
 * request turns a refusal into a validation error.
 */
final class ChapaRequestFailed extends RuntimeException
{
    private function __construct(string $message, public readonly bool $refusedByChapa)
    {
        parent::__construct($message);
    }

    public static function refused(string $reason): self
    {
        return new self("Chapa refused the request: {$reason}", refusedByChapa: true);
    }

    public static function unavailable(): self
    {
        return new self('Chapa didn\'t answer. Try again in a moment.', refusedByChapa: false);
    }
}
