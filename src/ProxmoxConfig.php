<?php

declare(strict_types=1);

namespace Beziworld\Proxmox;

use Beziworld\Proxmox\Exceptions\InvalidConfiguration;

/**
 * Validated, immutable settings of a single Proxmox VE cluster connection.
 */
final readonly class ProxmoxConfig
{
    public function __construct(
        public string $name,
        public string $url,
        public ?string $tokenId = null,
        #[\SensitiveParameter]
        public ?string $tokenSecret = null,
        public ?string $username = null,
        #[\SensitiveParameter]
        public ?string $password = null,
        public ?string $node = null,
        public bool|string $verifySsl = true,
        public int $timeout = 30,
        public int $connectTimeout = 10,
        public int $retries = 2,
        public int $retryDelay = 300,
        public int $pollInterval = 2,
        public int $taskTimeout = 900,
    ) {}

    /**
     * @param array<string, mixed> $config
     *
     * @throws InvalidConfiguration When the URL or the credentials are missing or malformed.
     */
    public static function fromArray(array $config, string $name = 'default'): self
    {
        $url = self::string($config['url'] ?? null) ?? throw InvalidConfiguration::missing('url', 'PROXMOX_URL', $name);

        if (preg_match('#^https?://#i', $url) !== 1) {
            $url = 'https://' . $url;
        }

        $url = rtrim((string) preg_replace('#/api2/json/?$#', '', rtrim($url, '/')), '/');

        if (parse_url($url, PHP_URL_PORT) === null) {
            $url .= ':8006';
        }

        $tokenId = self::string($config['token_id'] ?? null);
        $tokenSecret = self::string($config['token_secret'] ?? null);
        $username = self::string($config['username'] ?? null);
        $password = is_string($config['password'] ?? null) && $config['password'] !== '' ? $config['password'] : null;

        if ($tokenId !== null) {
            if (preg_match('/^[^@!\s]+@[^@!\s]+![A-Za-z][A-Za-z0-9._-]*$/', $tokenId) !== 1) {
                throw InvalidConfiguration::invalid('token_id', $tokenId . ' (expected user@realm!tokenid)', $name);
            }

            if ($tokenSecret === null) {
                throw InvalidConfiguration::missing('token_secret', 'PROXMOX_TOKEN_SECRET', $name);
            }
        } elseif ($username === null || $password === null) {
            throw InvalidConfiguration::missing('token_id', 'PROXMOX_TOKEN_ID and PROXMOX_TOKEN_SECRET (or PROXMOX_USERNAME and PROXMOX_PASSWORD)', $name);
        }

        if ($username !== null && ! str_contains($username, '@')) {
            $username .= '@pam';
        }

        return new self(
            name: $name,
            url: $url,
            tokenId: $tokenId,
            tokenSecret: $tokenId === null ? null : $tokenSecret,
            username: $username,
            password: $password,
            node: self::string($config['node'] ?? null),
            verifySsl: self::verify($config['verify_ssl'] ?? true),
            timeout: self::positiveInt($config['timeout'] ?? null, 30),
            connectTimeout: self::positiveInt($config['connect_timeout'] ?? null, 10),
            retries: self::naturalInt($config['retries'] ?? null, 2),
            retryDelay: self::naturalInt($config['retry_delay'] ?? null, 300),
            pollInterval: self::positiveInt($config['poll_interval'] ?? null, 2),
            taskTimeout: self::positiveInt($config['task_timeout'] ?? null, 900),
        );
    }

    public function usesToken(): bool
    {
        return $this->tokenId !== null;
    }

    /**
     * Value of the Authorization header for API token authentication.
     */
    public function tokenHeader(): ?string
    {
        return $this->tokenId === null ? null : sprintf('PVEAPIToken=%s=%s', $this->tokenId, $this->tokenSecret);
    }

    public function apiUrl(): string
    {
        return $this->url . '/api2/json';
    }

    /**
     * Copy with another default node.
     */
    public function withNode(?string $node): self
    {
        return new self(
            $this->name,
            $this->url,
            $this->tokenId,
            $this->tokenSecret,
            $this->username,
            $this->password,
            $node,
            $this->verifySsl,
            $this->timeout,
            $this->connectTimeout,
            $this->retries,
            $this->retryDelay,
            $this->pollInterval,
            $this->taskTimeout,
        );
    }

    private static function string(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    private static function verify(mixed $value): bool|string
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        if (is_string($value)) {
            return match (strtolower(trim($value))) {
                '', '1', 'true', 'yes', 'on' => true,
                '0', 'false', 'no', 'off' => false,
                default => trim($value),
            };
        }

        return true;
    }

    private static function positiveInt(mixed $value, int $default): int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : $default;
    }

    private static function naturalInt(mixed $value, int $default): int
    {
        return is_numeric($value) && (int) $value >= 0 ? (int) $value : $default;
    }
}
