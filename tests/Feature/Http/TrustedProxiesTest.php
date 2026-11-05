<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/**
 * Boot the application again with TRUSTED_PROXIES set as the host would set it.
 */
function bootWithTrustedProxies(?string $proxies): void
{
    if ($proxies === null) {
        putenv('TRUSTED_PROXIES');
        unset($_ENV['TRUSTED_PROXIES'], $_SERVER['TRUSTED_PROXIES']);
    } else {
        putenv("TRUSTED_PROXIES={$proxies}");
        $_ENV['TRUSTED_PROXIES'] = $_SERVER['TRUSTED_PROXIES'] = $proxies;
    }

    test()->refreshApplication();

    Route::get('/proxy-check', fn () => [
        'secure' => request()->isSecure(),
        'url' => url('/panel'),
    ]);
}

afterEach(function () {
    putenv('TRUSTED_PROXIES');
    unset($_ENV['TRUSTED_PROXIES'], $_SERVER['TRUSTED_PROXIES']);
});

test('requests through a trusted proxy keep the https and host the visitor used', function () {
    bootWithTrustedProxies('*');

    $this->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'gym.onrender.com'])
        ->get('/proxy-check')
        ->assertExactJson(['secure' => true, 'url' => 'https://gym.onrender.com/panel']);
});

test('forwarded headers are ignored when TRUSTED_PROXIES is not set', function () {
    bootWithTrustedProxies(null);

    $response = $this->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'evil.example'])
        ->get('/proxy-check');

    $response->assertJsonPath('secure', false);
    expect($response->json('url'))->not->toContain('evil.example');
});
