<?php

declare(strict_types=1);

namespace Beziworld\Proxmox\Data;

use Beziworld\Proxmox\Support\Values;

/**
 * Entry of GET /cluster/resources: a node, a guest (qemu, lxc), a storage, a pool or an SDN object.
 */
final readonly class ClusterResource
{
    /**
     * @param list<string> $tags
     * @param array<array-key, mixed> $raw
     */
    public function __construct(
        public string $id,
        public string $type,
        public ?string $node,
        public ?int $vmid,
        public ?string $name,
        public ?string $status,
        public ?string $storage,
        public ?string $pool,
        public bool $template,
        public float $cpu,
        public int $maxCpu,
        public int $memory,
        public int $maxMemory,
        public int $disk,
        public int $maxDisk,
        public int $uptime,
        public array $tags = [],
        public array $raw = [],
    ) {}

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Values::string($data, 'id'),
            type: Values::string($data, 'type'),
            node: Values::nullableString($data, 'node'),
            vmid: Values::nullableInt($data, 'vmid'),
            name: Values::nullableString($data, 'name'),
            status: Values::nullableString($data, 'status'),
            storage: Values::nullableString($data, 'storage'),
            pool: Values::nullableString($data, 'pool'),
            template: Values::bool($data, 'template'),
            cpu: Values::float($data, 'cpu'),
            maxCpu: Values::int($data, 'maxcpu'),
            memory: Values::int($data, 'mem'),
            maxMemory: Values::int($data, 'maxmem'),
            disk: Values::int($data, 'disk'),
            maxDisk: Values::int($data, 'maxdisk'),
            uptime: Values::int($data, 'uptime'),
            tags: Values::tags($data),
            raw: $data,
        );
    }

    public function isGuest(): bool
    {
        return $this->type === 'qemu' || $this->type === 'lxc';
    }

    public function isRunning(): bool
    {
        return $this->status === 'running';
    }
}
