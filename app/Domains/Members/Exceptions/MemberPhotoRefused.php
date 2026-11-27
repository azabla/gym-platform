<?php

declare(strict_types=1);

namespace App\Domains\Members\Exceptions;

use DomainException;

/**
 * A photo that can't be a member's photo: not an image that can be read, or
 * too small to show the person. The field names the request field the
 * message belongs to. See ADR 0036.
 */
final class MemberPhotoRefused extends DomainException
{
    private function __construct(string $message, public readonly string $field = 'photo')
    {
        parent::__construct($message);
    }

    public static function notAnImage(): self
    {
        return new self(__('The photo must be a JPEG, PNG or WebP image.'));
    }

    public static function tooSmall(int $minimumPixels): self
    {
        return new self(__('The photo must be at least :pixels pixels wide and high.', ['pixels' => $minimumPixels]));
    }
}
