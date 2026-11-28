<?php

namespace App\Notifications;

use App\Domains\Notifications\Enums\StaffNotificationTopic;
use App\Domains\Notifications\Push\PushChannel;
use App\Domains\Notifications\Sms\SmsChannel;
use App\Domains\Organizations\Enums\StaffRole;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Models\Staff;
use App\Domains\PlatformBilling\Models\GymInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

/**
 * A message to a gym's owners about the platform's bill (ADR 0045). Only
 * owners get it, and they can't turn it off: it always comes to the inbox,
 * by push and by SMS. It waits for daytime where the gym is, and a pause
 * never holds it back, as the gym needs it most when it is paused.
 */
abstract class GymBillNotification extends StaffNotification
{
    /**
     * Send it to each owner of the invoice's gym, from 07:00 their time.
     */
    public function toOwnersOf(GymInvoice $invoice): void
    {
        $gym = Organization::query()->findOrFail($invoice->organization_id);
        $owners = Staff::query()->acrossOrganizations()
            ->where('organization_id', $gym->getKey())
            ->where('role', StaffRole::Owner)
            ->with(['user', 'organization'])
            ->get();

        Notification::send($owners, $this->delay(self::nextDaytimeAt($gym)));
    }

    /**
     * What every bill message is written from.
     *
     * @return array<string, mixed>
     */
    protected static function detailsOf(GymInvoice $invoice): array
    {
        return [
            'invoice' => $invoice->reference(),
            'amount' => $invoice->balance()->format(),
            'starts_on' => $invoice->period_starts_on->toDateString(),
            'ends_on' => $invoice->period_ends_on->toDateString(),
            'due_on' => $invoice->due_on->toDateString(),
            'pauses_on' => $invoice->pauses_on->toDateString(),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected static function dataOf(GymInvoice $invoice): array
    {
        return ['gym_invoice_id' => (string) $invoice->getKey()];
    }

    public function topic(): StaffNotificationTopic
    {
        return StaffNotificationTopic::PlatformBill;
    }

    /**
     * @return list<string|class-string>
     */
    public function via(Staff $staff): array
    {
        return ['database', PushChannel::class, SmsChannel::class];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        return true;
    }

    /**
     * Now, in daytime where the gym is; otherwise 07:00 there.
     */
    private static function nextDaytimeAt(Organization $gym): ?CarbonImmutable
    {
        $now = CarbonImmutable::now($gym->timezone);

        if ($now->hour >= self::TEXTS_FROM_HOUR && $now->hour < self::TEXTS_UNTIL_HOUR) {
            return null;
        }

        $morning = $now->setTime(self::TEXTS_FROM_HOUR, 0);

        return ($now->hour >= self::TEXTS_UNTIL_HOUR ? $morning->addDay() : $morning)->utc();
    }
}
