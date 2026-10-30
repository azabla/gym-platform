<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use Illuminate\Http\Middleware\TrustProxies;

afterEach(function () {
    TrustProxies::flushState();
});

/**
 * Boot the app's provider again with the given TRUSTED_PROXIES, as the app
 * does when it starts.
 */
function bootWithTrustedProxies(?string $proxies): void
{
    config(['app.trusted_proxies' => $proxies]);
    (new AppServiceProvider(app()))->boot();
}

it('builds https links behind a trusted load balancer', function () {
    bootWithTrustedProxies('*');

    $this->get(route('staff.password.edit', ['token' => 'x'], absolute: false), ['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'gym.example.com'])
        ->assertSee('https://gym.example.com/password/forgot', escape: false);
});

it('trusts no forwarded headers when no proxy is named', function () {
    bootWithTrustedProxies(null);

    $this->get(route('staff.password.edit', ['token' => 'x'], absolute: false), ['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'gym.example.com'])
        ->assertDontSee('https://gym.example.com', escape: false);
});
