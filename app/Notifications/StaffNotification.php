<?php

namespace App\Notifications;

use App\Domains\Organizations\Models\Staff;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * A message to a staff member at one gym, in their inbox: the staff panel's
 * and the API's (ADR 0027). Like a message to a member, it keeps what its text
 * is written from, not the text, and what to open. Staff messages don't show
 * on a lock screen, so they may name the member.
 */
abstract class StaffNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $details  what the message's text is filled in with
     * @param  array<string, string>  $data  what to open: a payment_id, membership_id or member_id
     */
    public function __construct(
        protected readonly array $details,
        protected readonly array $data,
    ) {
        $this->afterCommit();
    }

    /**
     * The message's type, such as "staff_online_payment".
     */
    abstract public function databaseType(object $notifiable): string;

    /**
     * @return list<string>
     */
    public function via(Staff $staff): array
    {
        return ['database'];
    }

    /**
     * @return array{details: array<string, mixed>, data: array<string, string>}
     */
    public function toArray(Staff $staff): array
    {
        return ['details' => $this->details, 'data' => $this->data];
    }
}
