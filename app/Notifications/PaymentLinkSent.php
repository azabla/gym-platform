<?php

namespace App\Notifications;

use App\Domains\Billing\Models\PaymentLink;
use App\Domains\Notifications\Sms\SendableBySms;
use App\Domains\Notifications\Sms\SmsChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * An open payment link, texted to the member at the desk's request, for
 * members without the app (ADR 0031). It goes by SMS only: the app already
 * shows a membership's open link. Its details are worked out when it is
 * created, at the gym, so the queue worker queries nothing scoped to a gym.
 */
final class PaymentLinkSent extends Notification implements SendableBySms, ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, string>  $details
     */
    private function __construct(private readonly array $details)
    {
        $this->afterCommit();
    }

    public static function for(PaymentLink $paymentLink): self
    {
        return new self([
            'gym' => (string) $paymentLink->organization->name,
            'amount' => $paymentLink->amount->format(),
            'plan' => $paymentLink->planName(),
            'url' => (string) $paymentLink->checkout_url,
        ]);
    }

    /**
     * @return list<class-string>
     */
    public function via(object $notifiable): array
    {
        return [SmsChannel::class];
    }

    public function toSms(object $notifiable): string
    {
        return MessageText::smsTo($notifiable, 'payment_link_sent', $this->details);
    }
}
