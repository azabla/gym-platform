<?php

declare(strict_types=1);

namespace App\Domains\Announcements\Exceptions;

use DomainException;

/**
 * An announcement the gym's rules don't allow: Announcement::refusalToPost()
 * decides, so the API and the staff panel refuse the same things. The field
 * names the request field the message belongs to. See ADR 0023.
 */
final class AnnouncementRefused extends DomainException
{
    private function __construct(string $message, public readonly string $field)
    {
        parent::__construct($message);
    }

    public static function notInTheFuture(): self
    {
        return new self('Choose a time in the future, or leave it out to send it now.', 'send_at');
    }

    public static function notToABranchTheStaffMemberWorksAt(): self
    {
        return new self('Choose a branch of this gym that you work at.', 'branch_id');
    }

    public static function alreadyGoneOut(): self
    {
        return new self('Only an announcement that hasn\'t gone out can be changed.', 'announcement');
    }
}
