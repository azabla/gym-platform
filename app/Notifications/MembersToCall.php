<?php

namespace App\Notifications;

/**
 * The morning list: how many memberships at the staff member's branches end
 * in the next 7 days with nothing sold after them, the members to call. It
 * opens the dashboard's list (ADR 0014 and ADR 0027).
 */
final class MembersToCall extends StaffNotification
{
    public static function count(int $count): self
    {
        return new self(details: ['count' => $count], data: []);
    }

    public function databaseType(object $notifiable): string
    {
        return 'staff_members_to_call';
    }
}
