<?php

declare(strict_types=1);

namespace App\Domains\Attendance\Credentials;

/**
 * A scanned GQ1 QR code, read but not yet checked: whose credential it
 * claims to be (its ULID in uppercase), for which step, and the code.
 */
final readonly class Gq1Payload
{
    public function __construct(
        public string $credentialId,
        public int $step,
        public string $code,
    ) {}
}
