<?php

declare(strict_types=1);

namespace Beziworld\Proxmox\Tests;

use Beziworld\Proxmox\Facades\Proxmox;
use Beziworld\Proxmox\ProxmoxManager;
use Beziworld\Proxmox\ProxmoxServiceProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    public const string API = 'https://pve1.example.com:8006/api2/json';

    public const string UPID = 'UPID:pve1:000A1B2C:0123ABCD:6730A1B2:qmstart:100:automation@pve!laravel:';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Sleep::fake();
        app()->forgetInstance(ProxmoxManager::class);
        Proxmox::clearResolvedInstances();
    }

    protected function getPackageProviders($app): array
    {
        return [ProxmoxServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('proxmox.connections.default', [
            'url' => 'pve1.example.com',
            'token_id' => 'automation@pve!laravel',
            'token_secret' => 'b3c6a8e2-1f4d-4a7b-9c0e-123456789abc',
            'node' => 'pve1',
            'retry_delay' => 0,
        ]);
        $app['config']->set('proxmox.connections.lab', [
            'url' => 'https://10.0.0.5:8006/',
            'username' => 'root',
            'password' => 'secret',
            'verify_ssl' => false,
            'retry_delay' => 0,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function data(mixed $data): array
    {
        return ['data' => $data];
    }
}
