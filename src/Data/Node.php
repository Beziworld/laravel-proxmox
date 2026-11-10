<?php

declare(strict_types=1);

namespace Beziworld\Proxmox\Data;

use Beziworld\Proxmox\Support\Values;

/**
 * Cluster node (GET /nodes). Memory in bytes, CPU as a fraction (0.25 = 25%).
 */
final readonly class Node
{
    /**
     * @param array<array-key, mixed> $raw
     */
    public function __construct(
        public string $node,
        public string $status,
        public float $cpu,
        public int $maxCpu,
        public int $memory,
        public int $maxMemory,
        public int $uptime,
        public ?string $supportLevel,
        public array $raw = [],
    ) {}

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            node: Values::string($data, 'node'),
            status: Values::string($data, 'status', 'unknown'),
            cpu: Values::float($data, 'cpu'),
            maxCpu: Values::int($data, 'maxcpu'),
            memory: Values::int($data, 'mem'),
            maxMemory: Values::int($data, 'maxmem'),
            uptime: Values::int($data, 'uptime'),
            supportLevel: Values::nullableString($data, 'level'),
            raw: $data,
        );
    }

    public function isOnline(): bool
    {
        return $this->status === 'online';
    }

    public function memoryUsage(): float
    {
        return $this->maxMemory > 0 ? $this->memory / $this->maxMemory : 0.0;
    }
}
