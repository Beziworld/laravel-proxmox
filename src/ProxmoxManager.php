<?php

declare(strict_types=1);

namespace Beziworld\Proxmox;

use BadMethodCallException;
use Beziworld\Proxmox\Exceptions\InvalidConfiguration;
use Beziworld\Proxmox\Http\ProxmoxApi;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * Resolves clients of the Proxmox VE clusters defined in config/proxmox.php.
 *
 * Named connections work like database connections; clusters stored at
 * runtime are reached with using(). Calls not defined on the manager are
 * forwarded to the default connection, so Proxmox::vm(100) and
 * Proxmox::connection('dc2')->vm(100) are both valid.
 *
 * @mixin ProxmoxClient
 */
class ProxmoxManager
{
    /** @var array<string, ProxmoxClient> */
    private array $clients = [];

    public function __construct(
        private readonly Config $config,
        private readonly HttpFactory $http,
        private readonly CacheFactory $cache,
    ) {}

    /**
     * @throws InvalidConfiguration When the connection is not defined or incomplete.
     */
    public function connection(?string $name = null): ProxmoxClient
    {
        $name ??= $this->getDefaultConnection();

        return $this->clients[$name] ??= $this->resolve($name);
    }

    /**
     * Client for a cluster that is not stored in the configuration file.
     *
     * @param ProxmoxConfig|array<string, mixed> $config Keys as in a connection: url, token_id, token_secret, node, ...
     */
    public function using(ProxmoxConfig|array $config, string $name = 'runtime'): ProxmoxClient
    {
        return $this->make($config instanceof ProxmoxConfig ? $config : ProxmoxConfig::fromArray($this->withTaskDefaults($config), $name));
    }

    public function getDefaultConnection(): string
    {
        $default = $this->config->get('proxmox.default');

        return is_string($default) && $default !== '' ? $default : 'default';
    }

    /**
     * @return list<string>
     */
    public function connectionNames(): array
    {
        $connections = $this->config->get('proxmox.connections');

        return is_array($connections) ? array_map(strval(...), array_keys($connections)) : [];
    }

    public function purge(?string $name = null): void
    {
        if ($name === null) {
            $this->clients = [];

            return;
        }

        unset($this->clients[$name]);
    }

    /**
     * @param array<array-key, mixed> $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        $callable = [$this->connection(), $method];

        if (! is_callable($callable)) {
            throw new BadMethodCallException(sprintf('Method %s::%s does not exist.', ProxmoxClient::class, $method));
        }

        return $callable(...$arguments);
    }

    private function resolve(string $name): ProxmoxClient
    {
        $config = $this->config->get('proxmox.connections.' . $name);

        if (! is_array($config)) {
            throw InvalidConfiguration::unknownConnection($name);
        }

        /** @var array<string, mixed> $config */
        return $this->make(ProxmoxConfig::fromArray($this->withTaskDefaults($config), $name));
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function withTaskDefaults(array $config): array
    {
        return $config + [
            'poll_interval' => $this->config->get('proxmox.tasks.poll_interval'),
            'task_timeout' => $this->config->get('proxmox.tasks.timeout'),
        ];
    }

    private function make(ProxmoxConfig $config): ProxmoxClient
    {
        $store = $this->config->get('proxmox.ticket.store');
        $prefix = $this->config->get('proxmox.ticket.prefix');
        $ttl = $this->config->get('proxmox.ticket.ttl');

        return new ProxmoxClient(new ProxmoxApi(
            $this->http,
            $config,
            $this->cache->store(is_string($store) && $store !== '' ? $store : null),
            is_string($prefix) && $prefix !== '' ? $prefix : 'proxmox:ticket:',
            is_numeric($ttl) && (int) $ttl > 0 ? min((int) $ttl, 7200) : 6600,
        ));
    }
}
