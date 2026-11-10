<?php

declare(strict_types=1);

namespace Beziworld\Proxmox\Exceptions;

/**
 * Raised when a VMID does not exist in the cluster resources.
 */
final class GuestNotFound extends ProxmoxException
{
    public static function forVmid(int $vmid, string $connection): self
    {
        return new self(sprintf('Proxmox connection "%s" has no guest with VMID %d (or the token cannot see it).', $connection, $vmid));
    }
}
