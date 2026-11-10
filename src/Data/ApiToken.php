<?php

declare(strict_types=1);

namespace Beziworld\Proxmox\Data;

use Beziworld\Proxmox\Support\Values;
use Carbon\CarbonImmutable;

/**
 * API token of a user. The secret value is only present right after creation.
 */
final readonly class ApiToken
{
    /**
     * @param array<array-key, mixed> $raw
     */
    public function __construct(
        public string $fullTokenId,
        #[\SensitiveParameter]
        public ?string $value,
        public bool $privilegeSeparation,
        public ?CarbonImmutable $expiresAt,
        public ?string $comment,
        public array $raw = [],
    ) {}

    /**
     * @param array<array-key, mixed> $data Response of the token creation, or an entry of a token list.
     */
    public static function fromArray(array $data, ?string $fullTokenId = null): self
    {
        $info = Values::array($data, 'info') !== [] ? Values::array($data, 'info') : $data;

        return new self(
            fullTokenId: Values::nullableString($data, 'full-tokenid') ?? $fullTokenId ?? Values::string($data, 'tokenid'),
            value: Values::nullableString($data, 'value'),
            privilegeSeparation: ! array_key_exists('privsep', $info) || Values::bool($info, 'privsep'),
            expiresAt: Values::timestamp($info, 'expire'),
            comment: Values::nullableString($info, 'comment'),
            raw: $data,
        );
    }

    /**
     * Authorization header value for the new token ("PVEAPIToken=user@realm!id=secret").
     */
    public function authorizationHeader(): ?string
    {
        return $this->value === null ? null : sprintf('PVEAPIToken=%s=%s', $this->fullTokenId, $this->value);
    }
}
