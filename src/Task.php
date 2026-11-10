<?php

declare(strict_types=1);

namespace Beziworld\Proxmox;

use Beziworld\Proxmox\Data\TaskStatus;
use Beziworld\Proxmox\Data\Upid;
use Beziworld\Proxmox\Resources\Tasks;
use Closure;
use Stringable;

/**
 * Handle of an asynchronous Proxmox task returned by state-changing calls.
 *
 * The task runs on the node regardless of the handle; wait() blocks until
 * it finishes, status() and log() inspect it without blocking.
 */
final class Task implements Stringable
{
    public function __construct(
        public readonly Upid $upid,
        private readonly Tasks $tasks,
    ) {}

    public function status(): TaskStatus
    {
        return $this->tasks->status($this->upid->value);
    }

    /**
     * @param Closure(TaskStatus): void|null $onPoll
     */
    public function wait(?int $timeout = null, ?int $interval = null, bool $throw = true, ?Closure $onPoll = null): TaskStatus
    {
        return $this->tasks->wait($this->upid->value, $timeout, $interval, $throw, $onPoll);
    }

    /**
     * @return list<string>
     */
    public function log(int $start = 0, int $limit = 500): array
    {
        return $this->tasks->log($this->upid->value, $start, $limit);
    }

    public function stop(): void
    {
        $this->tasks->stop($this->upid->value);
    }

    public function node(): string
    {
        return $this->upid->node;
    }

    public function __toString(): string
    {
        return $this->upid->value;
    }
}
