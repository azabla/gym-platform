<?php

declare(strict_types=1);

namespace App\Domains\Imports\Enums;

/**
 * What became of one row of a member import. Checking gives ready, error
 * (the row can't be imported as it is) or duplicate (the member is already
 * at the gym, or earlier in the file); importing turns ready into added, or
 * failed when something changed since the check. See ADR 0044.
 */
enum MemberImportRowOutcome: string
{
    case Ready = 'ready';
    case Error = 'error';
    case Duplicate = 'duplicate';
    case Added = 'added';
    case Failed = 'failed';
}
