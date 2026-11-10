<?php

declare(strict_types=1);

namespace Beziworld\Proxmox\Data;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Parsed unique process identifier of a task.
 *
 * Format: UPID:{node}:{pid hex}:{pstart hex}:{starttime hex}:{type}:{id}:{user}:
 */
final readonly class Upid
{
    public function __construct(
        public string $value,
        public string $node,
        public int $pid,
        public CarbonImmutable $startedAt,
        public string $type,
        public ?string $id,
        public string $user,
    ) {}

    /**
     * @throws InvalidArgumentException When the value is not a UPID.
     */
    public static function parse(string $value): self
    {
        $parts = explode(':', trim($value));

        if (count($parts) < 8 || $parts[0] !== 'UPID' || $parts[1] === '' || ! ctype_xdigit($parts[2]) || ! ctype_xdigit($parts[4])) {
            throw new InvalidArgumentException(sprintf('"%s" is not a Proxmox task UPID.', $value));
        }

        return new self(
            value: trim($value),
            node: $parts[1],
            pid: (int) hexdec($parts[2]),
            startedAt: CarbonImmutable::createFromTimestampUTC((int) hexdec($parts[4])),
            type: $parts[5],
            id: $parts[6] === '' ? null : $parts[6],
            user: $parts[7],
        );
    }

    public static function isValid(mixed $value): bool
    {
        return is_string($value) && str_starts_with($value, 'UPID:') && substr_count($value, ':') >= 8;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
