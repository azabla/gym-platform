<?php

namespace App\Providers;

use App\Domains\Identity\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->storeAliasesInPolymorphicColumns();
        $this->limitSignInAttempts();
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
        ]);
    }

    /**
     * Allow five sign-in attempts a minute for each email address and IP.
     */
    private function limitSignInAttempts(): void
    {
        RateLimiter::for('login', function (Request $request): Limit {
            return Limit::perMinute(5)->by(Str::lower($request->string('email')->toString()).'|'.$request->ip());
        });
    }
}
