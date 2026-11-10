<?php

declare(strict_types=1);

use Beziworld\Proxmox\Exceptions\ApiException;
use Beziworld\Proxmox\Exceptions\AuthenticationException;
use Beziworld\Proxmox\Exceptions\ConnectionFailed;
use Beziworld\Proxmox\Exceptions\InvalidConfiguration;
use Beziworld\Proxmox\Exceptions\PermissionDenied;
use Beziworld\Proxmox\Exceptions\RateLimitException;
use Beziworld\Proxmox\Facades\Proxmox;
use Beziworld\Proxmox\Tests\TestCase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

it('authenticates with an api token header', function (): void {
    Http::fake([TestCase::API . '/version' => Http::response(TestCase::data(['version' => '9.0.11', 'release' => '9.0', 'repoid' => 'abcd1234']))]);

    $version = Proxmox::version();

    expect($version->version)->toBe('9.0.11')->and($version->atLeast('8.2'))->toBeTrue();

    Http::assertSent(fn(Request $request): bool => $request->url() === TestCase::API . '/version'
        && $request->hasHeader('Authorization', 'PVEAPIToken=automation@pve!laravel=b3c6a8e2-1f4d-4a7b-9c0e-123456789abc')
        && ! $request->hasHeader('CSRFPreventionToken'));
});

it('sends write parameters form-encoded with booleans as integers', function (): void {
    Http::fake(['*' => Http::response(TestCase::data(TestCase::UPID))]);

    Proxmox::vm(100, 'pve1')->shutdown(timeout: 120, forceStop: true);

    Http::assertSent(fn(Request $request): bool => $request->method() === 'POST'
        && $request->url() === TestCase::API . '/nodes/pve1/qemu/100/status/shutdown'
        && $request->isForm()
        && $request->data() === ['timeout' => '120', 'forceStop' => '1']);
});

it('logs in with a ticket and sends the csrf token only on writes', function (): void {
    Http::fake([
        'https://10.0.0.5:8006/api2/json/access/ticket' => Http::response(TestCase::data(['username' => 'root@pam', 'ticket' => 'PVE:root@pam:6730A1B2::sig', 'CSRFPreventionToken' => '6730A1B2:csrf'])),
        'https://10.0.0.5:8006/api2/json/nodes' => Http::response(jsonFixture('nodes')),
        'https://10.0.0.5:8006/api2/json/nodes/pve1/qemu/100/status/start' => Http::response(TestCase::data(TestCase::UPID)),
    ]);

    $lab = Proxmox::connection('lab');
    $lab->nodes()->list();
    $lab->vm(100, 'pve1')->start();

    Http::assertSent(fn(Request $request): bool => str_ends_with($request->url(), '/access/ticket')
        && $request->data() === ['username' => 'root@pam', 'password' => 'secret']);
    Http::assertSent(fn(Request $request): bool => str_ends_with($request->url(), '/nodes')
        && $request->hasHeader('Cookie', 'PVEAuthCookie=' . rawurlencode('PVE:root@pam:6730A1B2::sig'))
        && ! $request->hasHeader('CSRFPreventionToken'));
    Http::assertSent(fn(Request $request): bool => str_ends_with($request->url(), '/status/start')
        && $request->hasHeader('CSRFPreventionToken', '6730A1B2:csrf'));
    Http::assertSentCount(3);
});

it('renews an expired ticket once', function (): void {
    Cache::put('proxmox:ticket:' . hash('sha256', 'https://10.0.0.5:8006|root@pam'), ['ticket' => 'old', 'csrf' => 'old'], 60);
    $calls = 0;

    Http::fake([
        '*/access/ticket' => Http::response(TestCase::data(['ticket' => 'new', 'CSRFPreventionToken' => 'csrf'])),
        '*/nodes' => function () use (&$calls) {
            return ++$calls === 1 ? Http::response(TestCase::data(null), 401) : Http::response(jsonFixture('nodes'));
        },
    ]);

    expect(Proxmox::connection('lab')->nodes()->list())->toHaveCount(2);
    Http::assertSentCount(3);
});

it('does not retry a rejected login', function (): void {
    Http::fake(['*' => Http::response(TestCase::data(null), 401)]);

    expect(fn() => Proxmox::connection('lab')->nodes()->list())->toThrow(AuthenticationException::class, 'root@pam');
    Http::assertSentCount(1);
});

it('raises authentication errors for rejected tokens without retrying', function (): void {
    Http::fake(['*' => Http::response(TestCase::data(null), 401)]);

    expect(fn() => Proxmox::nodes()->list())->toThrow(AuthenticationException::class, 'HTTP 401');
    Http::assertSentCount(1);
});

it('reports missing privileges', function (): void {
    Http::fake(['*' => Http::response(TestCase::data(null), 403)]);

    expect(fn() => Proxmox::vm(100, 'pve1')->start())->toThrow(PermissionDenied::class);
});

it('exposes parameter validation errors', function (): void {
    Http::fake(['*' => Http::response(['data' => null, 'message' => "Parameter verification failed.\n", 'errors' => ['newid' => 'VM 101 already exists']], 400)]);

    try {
        Proxmox::vm(100, 'pve1')->clone(101);
        test()->fail('Expected exception was not thrown.');
    } catch (ApiException $exception) {
        expect($exception->errors())->toBe(['newid' => 'VM 101 already exists'])
            ->and($exception->httpStatus())->toBe(400)
            ->and($exception->getMessage())->toContain('Parameter verification failed.')
            ->and($exception->getMessage())->toContain('newid: VM 101 already exists');
    }
});

it('reports rate limits from a reverse proxy', function (): void {
    Http::fake(['*' => Http::response('', 429, ['Retry-After' => '15'])]);

    try {
        Proxmox::nodes()->list();
        test()->fail('Expected exception was not thrown.');
    } catch (RateLimitException $exception) {
        expect($exception->retryAfter())->toBe(15);
    }
});

it('retries reads on server errors and unreachable nodes', function (): void {
    Http::fakeSequence()->push('', 596)->push(jsonFixture('nodes'));

    expect(Proxmox::nodes()->list())->toHaveCount(2);
    Http::assertSentCount(2);
});

it('never retries writes', function (): void {
    Http::fake(['*' => Http::response(TestCase::data(null), 500)]);

    expect(fn() => Proxmox::vm(100, 'pve1')->stop())->toThrow(ApiException::class, 'HTTP 500');
    Http::assertSentCount(1);
});

it('wraps connection errors', function (): void {
    Http::fake(fn() => throw new ConnectionException('cURL error 60: SSL certificate problem'));

    expect(fn() => Proxmox::version())->toThrow(ConnectionFailed::class, 'SSL certificate problem');
});

it('creates clients for runtime credentials', function (): void {
    Http::fake(['*' => Http::response(TestCase::data(['version' => '8.4.1', 'release' => '8.4']))]);

    Proxmox::using(['url' => 'https://dc2.example.com:8006', 'token_id' => 'tenant@pve!api', 'token_secret' => 'x'])->version();

    Http::assertSent(fn(Request $request): bool => $request->url() === 'https://dc2.example.com:8006/api2/json/version'
        && $request->hasHeader('Authorization', 'PVEAPIToken=tenant@pve!api=x'));
});

it('rejects unknown connections', function (): void {
    expect(fn() => Proxmox::connection('nope'))->toThrow(InvalidConfiguration::class, '"nope"');
});

it('passes any endpoint through', function (): void {
    Http::fake(['*' => Http::response(TestCase::data([['id' => 'local', 'type' => 'dir']]))]);

    expect(Proxmox::request('GET', '/storage', ['type' => 'dir']))->toBe([['id' => 'local', 'type' => 'dir']]);
    Http::assertSent(fn(Request $request): bool => $request->url() === TestCase::API . '/storage?type=dir');
});
