<?php

declare(strict_types=1);

namespace Beziworld\Proxmox\Data;

use Beziworld\Proxmox\Support\Values;

/**
 * Runtime status of a QEMU VM or LXC container (status/current, or an entry of the guest index).
 */
final readonly class GuestStatus
{
    /**
     * @param string $type "qemu" or "lxc".
     * @param list<string> $tags
     * @param array<array-key, mixed> $raw
     */
    public function __construct(
        public int $vmid,
        public string $type,
        public ?string $name,
        public string $status,
        public ?string $qmpStatus,
        public ?string $lock,
        public bool $template,
        public float $cpu,
        public float $cpus,
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
    public static function fromArray(array $data, string $type = 'qemu'): self
    {
        return new self(
            vmid: Values::int($data, 'vmid'),
            type: Values::string($data, 'type', $type),
            name: Values::nullableString($data, 'name'),
            status: Values::string($data, 'status', 'unknown'),
            qmpStatus: Values::nullableString($data, 'qmpstatus'),
            lock: Values::nullableString($data, 'lock'),
            template: Values::bool($data, 'template'),
            cpu: Values::float($data, 'cpu'),
            cpus: Values::float($data, 'cpus'),
            memory: Values::int($data, 'mem'),
            maxMemory: Values::int($data, 'maxmem'),
            disk: Values::int($data, 'disk'),
            maxDisk: Values::int($data, 'maxdisk'),
            uptime: Values::int($data, 'uptime'),
            tags: Values::tags($data),
            raw: $data,
        );
    }

    public function isRunning(): bool
    {
        return $this->status === 'running';
    }

    /**
     * Whether a running VM is paused or suspended (QMP status other than "running").
     */
    public function isPaused(): bool
    {
        return $this->isRunning() && $this->qmpStatus !== null && $this->qmpStatus !== 'running';
    }

    public function isLocked(): bool
    {
        return $this->lock !== null;
    }
}
