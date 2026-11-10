<?php

declare(strict_types=1);

namespace Beziworld\Proxmox\Exceptions;

use Throwable;

/**
 * Raised when the Proxmox VE API answers with an error status.
 *
 * Proxmox reports the error message in the HTTP reason phrase (and in a
 * "message" member in newer releases); parameter validation failures carry
 * an "errors" object keyed by parameter name.
 */
class ApiException extends ProxmoxException
{
    /**
     * @param array<string, string> $errors
     * @param array<array-key, mixed> $payload
     */
    final public function __construct(
        string $message,
        private readonly ?int $httpStatus = null,
        private readonly array $errors = [],
        private readonly array $payload = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus ?? 0, $previous);
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    public static function fromResponse(string $operation, int $status, string $reason, array $payload): static
    {
        $errors = [];

        foreach (is_array($payload['errors'] ?? null) ? $payload['errors'] : [] as $parameter => $error) {
            if (is_scalar($error)) {
                $errors[(string) $parameter] = trim((string) $error);
            }
        }

        $message = is_string($payload['message'] ?? null) && trim($payload['message']) !== '' ? trim($payload['message']) : trim($reason);
        $message = $message === '' ? 'HTTP ' . $status : $message;
        $text = sprintf('Proxmox %s failed: %s (HTTP %d)', $operation, $message, $status);

        if ($errors !== []) {
            $text .= ': ' . implode('; ', array_map(static fn(string $key, string $value): string => $key . ': ' . $value, array_keys($errors), $errors));
        }

        return new static($text, $status, $errors, $payload);
    }

    public static function unexpected(string $operation, int $status): static
    {
        return new static(sprintf('Proxmox %s returned an unexpected response (HTTP %d).', $operation, $status), $status);
    }

    public function httpStatus(): ?int
    {
        return $this->httpStatus;
    }

    /**
     * Validation messages keyed by parameter name.
     *
     * @return array<string, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }
}
