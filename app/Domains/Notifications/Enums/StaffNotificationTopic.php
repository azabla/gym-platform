<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Enums;

/**
 * What a message to staff is about (ADR 0027). Each staff member chooses, at
 * each gym, whether each topic also comes by push (on until turned off) and
 * by SMS (off until turned on); the inbox gets every message. See ADR 0035.
 */
enum StaffNotificationTopic: string
{
    case OnlinePayments = 'online_payments';
    case Refunds = 'refunds';
    case AppFreezes = 'app_freezes';
    case MembersToCall = 'members_to_call';
}
