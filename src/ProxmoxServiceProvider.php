<?php

declare(strict_types=1);

namespace Beziworld\Proxmox;

use Beziworld\Proxmox\Console\CheckConnectionCommand;
use Beziworld\Proxmox\Console\WaitForTaskCommand;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the Proxmox VE connection manager and the console commands.
 */
final class ProxmoxServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/proxmox.php', 'proxmox');

        $this->app->singleton(ProxmoxManager::class, static fn(Application $app): ProxmoxManager => new ProxmoxManager(
            $app->make(Config::class),
            $app->make(HttpFactory::class),
            $app->make(CacheFactory::class),
        ));

        $this->app->bind(ProxmoxClient::class, static fn(Application $app): ProxmoxClient => $app->make(ProxmoxManager::class)->connection());
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/proxmox.php' => $this->app->configPath('proxmox.php'),
            ], 'proxmox-config');

            $this->commands([CheckConnectionCommand::class, WaitForTaskCommand::class]);
        }
    }
}
