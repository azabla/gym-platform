<?php

declare(strict_types=1);

use App\Domains\Notifications\Models\PushToken;
use App\Domains\Notifications\Push\Exceptions\PushNotSent;
use App\Domains\Notifications\Push\PushChannel;
use App\Domains\Notifications\Push\PushMessage;
use App\Domains\Notifications\Push\PushResult;
use App\Domains\Notifications\Push\PushSender;
use App\Domains\Notifications\Push\SendableByPush;
use Illuminate\Notifications\Notification;

/**
 * A push sender that records what it was asked to send, and answers for each
 * phone as given: a result, or an exception to throw.
 */
function recordingPushSender(array $answers = []): PushSender
{
    return new class($answers) implements PushSender
    {
        /** @var list<array{string, PushMessage}> */
        public array $sent = [];

        public function __construct(private array $answers) {}

        public function send(string $deviceToken, PushMessage $message): PushResult
        {
            $this->sent[] = [$deviceToken, $message];
            $answer = $this->answers[$deviceToken] ?? PushResult::Sent;

            if ($answer instanceof Throwable) {
                throw $answer;
            }

            return $answer;
        }
    };
}

/**
 * Someone signed in on the given phones, and a notification greeting them in each phone's language.
 *
 * @return array{object, Notification&SendableByPush}
 */
function signedInOn(PushToken ...$pushTokens): array
{
    $notifiable = new class($pushTokens)
    {
        public function __construct(private array $pushTokens) {}

        public function routeNotificationFor(string $channel): array
        {
            return $channel === 'push' ? $this->pushTokens : [];
        }
    };

    $notification = new class extends Notification implements SendableByPush
    {
        public function toPush(object $notifiable, string $locale): PushMessage
        {
            return new PushMessage("Hello in {$locale}", 'Body');
        }
    };

    return [$notifiable, $notification];
}

it('pushes to every phone, each in its own language, English by default', function () {
    $amharic = PushToken::factory()->create(['locale' => 'am']);
    $noLanguage = PushToken::factory()->create();
    $sender = recordingPushSender();

    (new PushChannel($sender))->send(...signedInOn($amharic, $noLanguage));

    expect($sender->sent)->toHaveCount(2)
        ->and($sender->sent[0][0])->toBe($amharic->token)
        ->and($sender->sent[0][1]->title)->toBe('Hello in am')
        ->and($sender->sent[1][1]->title)->toBe('Hello in en');
});

it('forgets a token FCM no longer knows, and keeps going when one phone fails', function () {
    [$gone, $failing, $fine] = PushToken::factory()->count(3)->create()->all();
    $sender = recordingPushSender([
        $gone->token => PushResult::TokenNoLongerValid,
        $failing->token => PushNotSent::unavailable(),
    ]);

    (new PushChannel($sender))->send(...signedInOn($gone, $failing, $fine));

    expect($sender->sent)->toHaveCount(3)
        ->and(PushToken::query()->pluck('id')->all())->toEqualCanonicalizing([$failing->id, $fine->id]);
});
