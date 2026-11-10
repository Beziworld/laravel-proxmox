<?php

declare(strict_types=1);

namespace Beziworld\Proxmox\Exceptions;

use Beziworld\Proxmox\Data\TaskStatus;

/**
 * Raised by the task waiter when a task finished with an exit status other than "OK".
 */
final class TaskFailed extends ProxmoxException
{
    private ?TaskStatus $status = null;

    /**
     * @param list<string> $log Last lines of the task log, when they could be read.
     */
    public static function withStatus(TaskStatus $status, array $log = []): self
    {
        $message = sprintf('Proxmox task %s (%s) failed: %s', $status->type, $status->upid, $status->exitStatus ?? 'unknown error');

        if ($log !== []) {
            $message .= "\n" . implode("\n", $log);
        }

        $exception = new self($message);
        $exception->status = $status;

        return $exception;
    }

    public function status(): ?TaskStatus
    {
        return $this->status;
    }
}
