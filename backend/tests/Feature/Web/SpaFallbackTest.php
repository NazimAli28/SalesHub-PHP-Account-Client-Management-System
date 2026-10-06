<?php

use App\Http\Middleware\SecurityHeaders;

beforeEach(function () {
    $this->index = tempnam(sys_get_temp_dir(), 'spa');
    file_put_contents($this->index, '<!doctype html><title>SalesHub SPA</title><div id="root"></div>');
    config(['saleshub.spa_index' => $this->index]);
});

afterEach(function () {
    @unlink($this->index);
});

it('returns the SPA shell with the HTML security policy for client routes', function (string $uri) {
    $response = $this->get($uri)->assertOk();

    expect($response->getContent())->toContain('SalesHub SPA')
        ->and($response->headers->get('Content-Type'))->toStartWith('text/html')
        ->and($response->headers->get('Cache-Control'))->toContain('no-cache')
        ->and($response->headers->get('Content-Security-Policy'))->toBe(SecurityHeaders::APP_CSP)
        ->and($response->headers->get('X-Frame-Options'))->toBe('DENY')
        ->and($response->headers->has('Set-Cookie'))->toBeFalse();
})->with(['/', '/leads/123', '/clients/5/notes', '/login']);

it('keeps unknown API routes as JSON 404s', function () {
    $this->getJson('/api/nope')->assertNotFound()->assertJsonStructure(['message']);
    $this->get('/api/nope', ['Accept' => 'text/html'])->assertNotFound()->assertHeader('Content-Type', 'application/json');
});

it('does not turn missing files into HTML', function () {
    $this->get('/assets/missing.js')->assertNotFound();
    $this->get('/sourcemap.js.map')->assertNotFound();
});

it('leaves the health check, the CSRF cookie and the API reference alone', function () {
    $this->get('/up')->assertOk();
    $this->get('/sanctum/csrf-cookie')->assertNoContent();
    $this->get('/docs/does-not-exist')->assertNotFound();
});

it('shows a placeholder when the SPA has not been built', function () {
    config(['saleshub.spa_index' => '/nonexistent/index.html']);

    $this->get('/leads')->assertOk()->assertSee('has not been built')
        ->assertHeader('Content-Security-Policy', SecurityHeaders::APP_CSP);
});

it('only answers GET and HEAD with the shell', function () {
    $this->post('/leads')->assertStatus(405);
});
