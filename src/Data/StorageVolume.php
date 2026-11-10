<?php

declare(strict_types=1);

namespace Beziworld\Proxmox\Data;

use Beziworld\Proxmox\Support\Values;
use Carbon\CarbonImmutable;

/**
 * Volume of a storage (disk image, ISO, template or backup archive). Sizes in bytes.
 */
final readonly class StorageVolume
{
    /**
     * @param array<array-key, mixed> $raw
     */
    public function __construct(
        public string $volid,
        public string $content,
        public ?string $format,
        public int $size,
        public ?int $vmid,
        public ?string $notes,
        public bool $protected,
        public ?CarbonImmutable $createdAt,
        public array $raw = [],
    ) {}

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            volid: Values::string($data, 'volid'),
            content: Values::string($data, 'content'),
            format: Values::nullableString($data, 'format'),
            size: Values::int($data, 'size', Values::int($data, 'approximate-size')),
            vmid: Values::nullableInt($data, 'vmid'),
            notes: Values::nullableString($data, 'notes'),
            protected: Values::bool($data, 'protected'),
            createdAt: Values::timestamp($data, 'ctime'),
            raw: $data,
        );
    }

    public function isBackup(): bool
    {
        return $this->content === 'backup';
    }
}
