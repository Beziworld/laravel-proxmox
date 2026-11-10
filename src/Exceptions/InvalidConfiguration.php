<?php

declare(strict_types=1);

namespace Beziworld\Proxmox\Exceptions;

/**
 * Raised when a cluster connection is not configured or incomplete.
 */
final class InvalidConfiguration extends ProxmoxException
{
    public static function missing(string $key, string $env, string $connection = 'default'): self
    {
        return new self(sprintf('Proxmox connection "%s" has no "%s" configured. Set %s in the environment or in the connection array.', $connection, $key, $env));
    }

    public static function unknownConnection(string $connection): self
    {
        return new self(sprintf('Proxmox connection "%s" is not defined in config/proxmox.php.', $connection));
    }

    public static function invalid(string $key, string $value, string $connection = 'default'): self
    {
        return new self(sprintf('Proxmox connection "%s" has an invalid "%s" value "%s".', $connection, $key, $value));
    }
}
