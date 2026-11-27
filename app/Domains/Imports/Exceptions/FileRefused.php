<?php

declare(strict_types=1);

namespace App\Domains\Imports\Exceptions;

use DomainException;

/**
 * A file that can't be read as a member import at all: not CSV UTF-8, too
 * big, without the columns it needs, or with no rows. Shown to the staff
 * member who uploaded it, in their language. See ADR 0044.
 */
final class FileRefused extends DomainException
{
    public static function notUtf8(): self
    {
        return new self(__('Save the file as "CSV UTF-8" and upload it again.'));
    }

    public static function tooBig(int $maxRows): self
    {
        return new self(__('A file can have at most :count rows. Split it and upload each part.', ['count' => $maxRows]));
    }

    public static function empty(): self
    {
        return new self(__('The file has no rows to import.'));
    }

    /**
     * @param  list<string>  $columns
     */
    public static function missingColumns(array $columns): self
    {
        return new self(__('The file needs the columns :columns, as in the template.', ['columns' => implode(', ', $columns)]));
    }
}
