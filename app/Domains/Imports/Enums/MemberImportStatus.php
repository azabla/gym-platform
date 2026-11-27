<?php

declare(strict_types=1);

namespace App\Domains\Imports\Enums;

/**
 * Where a member import stands: checked and waiting for the staff member to
 * confirm, being imported, imported, or discarded without importing. See
 * ADR 0044.
 */
enum MemberImportStatus: string
{
    case Checked = 'checked';
    case Importing = 'importing';
    case Imported = 'imported';
    case Discarded = 'discarded';
}
