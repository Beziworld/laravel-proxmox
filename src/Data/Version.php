<?php

declare(strict_types=1);

namespace Beziworld\Proxmox\Data;

use Beziworld\Proxmox\Support\Values;

/**
 * Proxmox VE version (GET /version).
 */
final readonly class Version
{
    /**
     * @param array<array-key, mixed> $raw
     */
    public function __construct(
        public string $version,
        public string $release,
        public string $repoId,
        public array $raw = [],
    ) {}

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(Values::string($data, 'version'), Values::string($data, 'release'), Values::string($data, 'repoid'), $data);
    }

    public function atLeast(string $release): bool
    {
        return version_compare($this->release !== '' ? $this->release : $this->version, $release, '>=');
    }
}
