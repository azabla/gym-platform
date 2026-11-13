<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/**
 * Read config/trustedproxy.php with TRUSTED_PROXIES set as the host would set
 * it, or unset, and use the result as the application's config.
 */
function configureTrustedProxiesFromEnvironment(?string $proxies): void
{
    if ($proxies === null) {
        putenv('TRUSTED_PROXIES');
        unset($_ENV['TRUSTED_PROXIES'], $_SERVER['TRUSTED_PROXIES']);
    } else {
        putenv("TRUSTED_PROXIES={$proxies}");
        $_ENV['TRUSTED_PROXIES'] = $_SERVER['TRUSTED_PROXIES'] = $proxies;
    }

    config(['trustedproxy' => require config_path('trustedproxy.php')]);
}

beforeEach(function () {
    Route::get('/proxy-check', fn () => [
        'secure' => request()->isSecure(),
        'url' => url('/panel'),
    ]);
});

afterEach(function () {
    putenv('TRUSTED_PROXIES');
    unset($_ENV['TRUSTED_PROXIES'], $_SERVER['TRUSTED_PROXIES']);
});

test('requests through a trusted proxy keep the https and host the visitor used', function () {
    configureTrustedProxiesFromEnvironment('*');

    $this->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'gym.onrender.com'])
        ->get('/proxy-check')
        ->assertExactJson(['secure' => true, 'url' => 'https://gym.onrender.com/panel']);
});

test('forwarded headers are ignored when TRUSTED_PROXIES is not set', function () {
    configureTrustedProxiesFromEnvironment(null);

    $response = $this->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'evil.example'])
        ->get('/proxy-check');

    $response->assertJsonPath('secure', false);
    expect($response->json('url'))->not->toContain('evil.example');
});
