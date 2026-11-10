<?php

declare(strict_types=1);

namespace Beziworld\Proxmox\Exceptions;

use Throwable;

/**
 * Raised when the cluster cannot be reached (DNS, TCP, TLS or timeout errors).
 */
final class ConnectionFailed extends ProxmoxException
{
    public static function during(string $operation, string $url, Throwable $previous): self
    {
        return new self(sprintf('Could not reach Proxmox VE at %s for %s: %s', $url, $operation, $previous->getMessage()), 0, $previous);
    }
}
