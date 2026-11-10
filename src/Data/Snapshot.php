<?php

declare(strict_types=1);

namespace Beziworld\Proxmox\Data;

use Beziworld\Proxmox\Support\Values;
use Carbon\CarbonImmutable;

/**
 * Snapshot of a guest; the entry named "current" represents the live state.
 */
final readonly class Snapshot
{
    /**
     * @param array<array-key, mixed> $raw
     */
    public function __construct(
        public string $name,
        public ?string $description,
        public ?string $parent,
        public bool $vmState,
        public ?CarbonImmutable $createdAt,
        public array $raw = [],
    ) {}

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: Values::string($data, 'name'),
            description: Values::nullableString($data, 'description'),
            parent: Values::nullableString($data, 'parent'),
            vmState: Values::bool($data, 'vmstate'),
            createdAt: Values::timestamp($data, 'snaptime'),
            raw: $data,
        );
    }

    public function isCurrent(): bool
    {
        return $this->name === 'current';
    }
}
