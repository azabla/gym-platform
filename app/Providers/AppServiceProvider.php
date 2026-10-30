<?php

namespace App\Providers;

use App\Domains\Identity\Models\User;
use App\Domains\Members\Models\Member;
use App\Domains\Notifications\Push\FcmPushSender;
use App\Domains\Notifications\Push\LogPushSender;
use App\Domains\Notifications\Push\PushSender;
use App\Domains\Notifications\Sms\AfroMessageSmsSender;
use App\Domains\Notifications\Sms\LogSmsSender;
use App\Domains\Notifications\Sms\SmsSender;
use App\Domains\Shared\PhoneNumber\Exceptions\InvalidPhoneNumber;
use App\Domains\Shared\PhoneNumber\PhoneNumber;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->sendTextMessagesWithTheConfiguredDriver();
        $this->sendPushesWithTheConfiguredDriver();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->storeAliasesInPolymorphicColumns();
        $this->trustTheHostsProxies();
        $this->limitSignInAttempts();
        $this->limitSmsSignIn();
    }

    /**
     * Text messages go through the driver SMS_DRIVER names: "log" writes them to
     * the log, and "afromessage" sends them. See ADR 0010.
     */
    private function sendTextMessagesWithTheConfiguredDriver(): void
    {
        $this->app->bind(SmsSender::class, fn (): SmsSender => match (config('services.sms.driver')) {
            'log' => new LogSmsSender,
            'afromessage' => new AfroMessageSmsSender,
            default => throw new InvalidArgumentException('SMS_DRIVER must be "log" or "afromessage".'),
        });
    }

    /**
     * Pushes go through the driver PUSH_DRIVER names: "log" writes them to the
     * log, and "fcm" sends them through Firebase. See ADR 0012.
     */
    private function sendPushesWithTheConfiguredDriver(): void
    {
        $this->app->bind(PushSender::class, fn (): PushSender => match (config('services.push.driver')) {
            'log' => new LogPushSender,
            'fcm' => new FcmPushSender,
            default => throw new InvalidArgumentException('PUSH_DRIVER must be "log" or "fcm".'),
        });
    }

    /**
     * Polymorphic columns, such as personal_access_tokens.tokenable_type, store
     * these aliases instead of class names, so moving a class never breaks
     * stored rows. Every model used in a polymorphic relation must be listed.
     */
    private function storeAliasesInPolymorphicColumns(): void
    {
        Relation::enforceMorphMap([
            'user' => User::class,
            'member' => Member::class,
        ]);
    }

    /**
     * Trust the host's load balancer, when TRUSTED_PROXIES names it, so
     * links use https and rate limits see the client's IP (ADR 0020).
     */
    private function trustTheHostsProxies(): void
    {
        $proxies = config('app.trusted_proxies');

        if (! is_string($proxies) || $proxies === '') {
            return;
        }

        TrustProxies::at($proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
    }

    /**
     * Allow five sign-in attempts a minute for each phone number (or email
     * address) and IP.
     */
    private function limitSignInAttempts(): void
    {
        RateLimiter::for('login', function (Request $request): Limit {
            $signsInWith = $request->filled('phone')
                ? $this->phoneNumberIn($request)
                : Str::lower($request->string('email')->toString());

            return Limit::perMinute(5)->by($signsInWith.'|'.$request->ip());
        });
    }

    /**
     * Members' sign-in by SMS: a code a minute and five an hour for each phone
     * number, 20 codes an hour from each IP, and five tries a minute at signing
     * in. Each limit has its own key, so no two share a counter. See ADR 0010.
     */
    private function limitSmsSignIn(): void
    {
        RateLimiter::for('sms-codes', fn (Request $request): array => [
            Limit::perMinute(1)->by('minute:'.$this->phoneNumberIn($request)),
            Limit::perHour(5)->by('hour:'.$this->phoneNumberIn($request)),
            Limit::perHour(20)->by('ip:'.$request->ip()),
        ]);

        // Password links for staff who forgot theirs, counted the same way (ADR 0019).
        RateLimiter::for('password-links', fn (Request $request): array => [
            Limit::perMinute(1)->by('minute:'.$this->phoneNumberIn($request)),
            Limit::perHour(5)->by('hour:'.$this->phoneNumberIn($request)),
            Limit::perHour(20)->by('ip:'.$request->ip()),
        ]);

        RateLimiter::for('sms-sign-in', fn (Request $request): Limit => Limit::perMinute(5)
            ->by($this->phoneNumberIn($request).'|'.$request->ip()));
    }

    /**
     * The request's phone number in E.164 form, so it counts the same however
     * it is typed, or as sent when it isn't a phone number.
     */
    private function phoneNumberIn(Request $request): string
    {
        $phone = $request->string('phone')->toString();

        try {
            return PhoneNumber::of($phone)->e164;
        } catch (InvalidPhoneNumber) {
            return $phone;
        }
    }
}
