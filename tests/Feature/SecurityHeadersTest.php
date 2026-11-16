<?php

use Inertia\DevTools\DevTools;

test('web responses carry the baseline security headers', function () {
    $response = $this->get('/');

    $response->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()')
        ->assertHeaderMissing('Strict-Transport-Security');

    $this->get('https://localhost/')
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});

test('API and API docs responses carry the security headers too', function () {
    $this->getJson(route('marketing.api.me'))->assertUnauthorized()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN');

    $this->get(route('marketing.api.docs.spec'))->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN');
});

test('inertia devtools is off unless explicitly enabled', function () {
    app()->detectEnvironment(fn () => 'local');

    expect(app()->environment('local'))->toBeTrue()
        ->and(DevTools::enabled())->toBeFalse();

    config(['inertia.devtools.enabled' => true]);

    expect(DevTools::enabled())->toBeTrue();
});
