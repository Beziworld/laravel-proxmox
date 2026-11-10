<?php

declare(strict_types=1);

namespace Beziworld\Proxmox\Data;

use Beziworld\Proxmox\Support\Values;
use Carbon\CarbonImmutable;

/**
 * Status of an asynchronous task (GET /nodes/{node}/tasks/{upid}/status, or an entry of a task list).
 *
 * A finished task has status "stopped" and an exit status: "OK" on success,
 * "WARNINGS: n" when it succeeded with warnings, the error message otherwise.
 */
final readonly class TaskStatus
{
    /**
     * @param array<array-key, mixed> $raw
     */
    public function __construct(
        public string $upid,
        public string $node,
        public string $type,
        public ?string $id,
        public string $user,
        public string $status,
        public ?string $exitStatus,
        public ?CarbonImmutable $startedAt,
        public ?CarbonImmutable $endedAt,
        public array $raw = [],
    ) {}

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $status = Values::string($data, 'status', 'unknown');
        $exit = Values::nullableString($data, 'exitstatus');

        // Task lists report the exit status in "status" for finished tasks.
        if ($exit === null && $status !== 'running' && $status !== 'stopped' && $status !== 'unknown') {
            $exit = $status;
            $status = 'stopped';
        }

        return new self(
            upid: Values::string($data, 'upid'),
            node: Values::string($data, 'node'),
            type: Values::string($data, 'type'),
            id: Values::nullableString($data, 'id'),
            user: Values::string($data, 'user'),
            status: $status,
            exitStatus: $exit,
            startedAt: Values::timestamp($data, 'starttime'),
            endedAt: Values::timestamp($data, 'endtime'),
            raw: $data,
        );
    }

    public function isRunning(): bool
    {
        return $this->status === 'running';
    }

    public function isFinished(): bool
    {
        return $this->status === 'stopped';
    }

    public function succeeded(): bool
    {
        return $this->isFinished() && ($this->exitStatus === 'OK' || $this->hasWarnings());
    }

    public function failed(): bool
    {
        return $this->isFinished() && ! $this->succeeded();
    }

    public function hasWarnings(): bool
    {
        return $this->exitStatus !== null && str_starts_with($this->exitStatus, 'WARNINGS');
    }
}
