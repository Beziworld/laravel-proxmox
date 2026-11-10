<?php

declare(strict_types=1);

namespace Beziworld\Proxmox\Data;

use Beziworld\Proxmox\Support\Values;
use Carbon\CarbonImmutable;

/**
 * User of the Proxmox VE access control (GET /access/users).
 */
final readonly class User
{
    /**
     * @param list<string> $groups
     * @param array<array-key, mixed> $raw
     */
    public function __construct(
        public string $userId,
        public bool $enabled,
        public ?CarbonImmutable $expiresAt,
        public ?string $firstName,
        public ?string $lastName,
        public ?string $email,
        public ?string $comment,
        public array $groups = [],
        public array $raw = [],
    ) {}

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $groups = $data['groups'] ?? null;

        return new self(
            userId: Values::string($data, 'userid'),
            enabled: ! array_key_exists('enable', $data) || Values::bool($data, 'enable'),
            expiresAt: Values::timestamp($data, 'expire'),
            firstName: Values::nullableString($data, 'firstname'),
            lastName: Values::nullableString($data, 'lastname'),
            email: Values::nullableString($data, 'email'),
            comment: Values::nullableString($data, 'comment'),
            groups: is_array($groups) ? Values::tags(['g' => implode(',', array_filter($groups, is_string(...)))], 'g') : Values::tags($data, 'groups'),
            raw: $data,
        );
    }

    public function realm(): string
    {
        $position = strrpos($this->userId, '@');

        return $position === false ? '' : substr($this->userId, $position + 1);
    }
}
