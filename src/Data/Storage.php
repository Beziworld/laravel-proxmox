<?php

declare(strict_types=1);

namespace Beziworld\Proxmox\Data;

use Beziworld\Proxmox\Support\Values;

/**
 * Storage as seen by a node (GET /nodes/{node}/storage). Sizes in bytes.
 */
final readonly class Storage
{
    /**
     * @param list<string> $content e.g. images, rootdir, iso, vztmpl, backup, snippets
     * @param array<array-key, mixed> $raw
     */
    public function __construct(
        public string $storage,
        public string $type,
        public array $content,
        public bool $active,
        public bool $enabled,
        public bool $shared,
        public int $total,
        public int $used,
        public int $available,
        public array $raw = [],
    ) {}

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $content = Values::string($data, 'content');

        return new self(
            storage: Values::string($data, 'storage'),
            type: Values::string($data, 'type'),
            content: $content === '' ? [] : array_values(array_filter(array_map(trim(...), explode(',', $content)), static fn(string $item): bool => $item !== '')),
            active: Values::bool($data, 'active'),
            enabled: ! array_key_exists('enabled', $data) || Values::bool($data, 'enabled'),
            shared: Values::bool($data, 'shared'),
            total: Values::int($data, 'total'),
            used: Values::int($data, 'used'),
            available: Values::int($data, 'avail'),
            raw: $data,
        );
    }

    public function supports(string $content): bool
    {
        return in_array($content, $this->content, true);
    }

    public function usage(): float
    {
        return $this->total > 0 ? $this->used / $this->total : 0.0;
    }
}
