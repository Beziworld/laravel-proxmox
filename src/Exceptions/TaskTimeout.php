<?php

declare(strict_types=1);

namespace Beziworld\Proxmox\Exceptions;

/**
 * Raised by the task waiter when a task is still running after the timeout.
 *
 * The task itself keeps running on the node; it can be awaited again or
 * stopped with tasks()->stop().
 */
final class TaskTimeout extends ProxmoxException
{
    private string $upid = '';

    public static function after(string $upid, int $seconds): self
    {
        $exception = new self(sprintf('Proxmox task %s is still running after %d seconds.', $upid, $seconds));
        $exception->upid = $upid;

        return $exception;
    }

    public function upid(): string
    {
        return $this->upid;
    }
}
