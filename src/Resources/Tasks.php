<?php

declare(strict_types=1);

namespace Beziworld\Proxmox\Resources;

use Beziworld\Proxmox\Data\TaskStatus;
use Beziworld\Proxmox\Data\Upid;
use Beziworld\Proxmox\Exceptions\TaskFailed;
use Beziworld\Proxmox\Exceptions\TaskTimeout;
use Beziworld\Proxmox\Http\ProxmoxApi;
use Beziworld\Proxmox\Support\Values;
use Beziworld\Proxmox\Task;
use Closure;
use Illuminate\Support\Sleep;

/**
 * Asynchronous tasks (UPIDs): status, log, listing, stopping and waiting.
 */
final class Tasks
{
    public function __construct(
        private readonly ProxmoxApi $api,
    ) {}

    /**
     * Wraps a UPID returned by another call into a task handle.
     */
    public function task(string $upid): Task
    {
        return new Task(Upid::parse($upid), $this);
    }

    public function status(string $upid): TaskStatus
    {
        $parsed = Upid::parse($upid);
        $data = $this->api->get(self::path($parsed) . '/status');

        return TaskStatus::fromArray((is_array($data) ? $data : []) + ['upid' => $parsed->value, 'node' => $parsed->node]);
    }

    /**
     * Lines of the task log.
     *
     * @return list<string>
     */
    public function log(string $upid, int $start = 0, int $limit = 500): array
    {
        $lines = [];

        foreach (Values::rows($this->api->get(self::path(Upid::parse($upid)) . '/log', ['start' => $start, 'limit' => $limit])) as $line) {
            $lines[] = Values::string($line, 't');
        }

        return $lines;
    }

    /**
     * Stops a running task.
     */
    public function stop(string $upid): void
    {
        $this->api->delete(self::path(Upid::parse($upid)));
    }

    /**
     * Recent tasks of a node.
     *
     * @param array{vmid?: int, typefilter?: string, statusfilter?: string, userfilter?: string, errors?: bool, since?: int, until?: int, source?: string, start?: int, limit?: int} $filters
     * @return list<TaskStatus>
     */
    public function forNode(string $node, array $filters = []): array
    {
        return array_map(TaskStatus::fromArray(...), Values::rows($this->api->get('/nodes/' . rawurlencode($node) . '/tasks', $filters)));
    }

    /**
     * Recent tasks of the whole cluster.
     *
     * @return list<TaskStatus>
     */
    public function recent(): array
    {
        return array_map(TaskStatus::fromArray(...), Values::rows($this->api->get('/cluster/tasks')));
    }

    /**
     * Polls the task status until the task finishes.
     *
     * @param int|null $timeout Seconds; the configured default applies when null.
     * @param int|null $interval Seconds between polls; the configured default applies when null.
     * @param Closure(TaskStatus): void|null $onPoll Called after every poll, e.g. to report progress.
     *
     * @throws TaskFailed When the task finished with an error exit status (and $throw is true).
     * @throws TaskTimeout When the task is still running after the timeout.
     */
    public function wait(string $upid, ?int $timeout = null, ?int $interval = null, bool $throw = true, ?Closure $onPoll = null): TaskStatus
    {
        $timeout ??= $this->api->config()->taskTimeout;
        $interval = max(1, $interval ?? $this->api->config()->pollInterval);
        $waited = 0;

        while (true) {
            $status = $this->status($upid);

            if ($onPoll !== null) {
                $onPoll($status);
            }

            if ($status->isFinished()) {
                if ($throw && $status->failed()) {
                    throw TaskFailed::withStatus($status, $this->tail($upid));
                }

                return $status;
            }

            if ($waited >= $timeout) {
                throw TaskTimeout::after($upid, $timeout);
            }

            $step = min($interval, $timeout - $waited);
            Sleep::for(max(1, $step))->seconds();
            $waited += max(1, $step);
        }
    }

    /**
     * Last log lines of a failed task; an unreadable log does not hide the failure.
     *
     * @return list<string>
     */
    private function tail(string $upid): array
    {
        try {
            return array_slice($this->log($upid, 0, 1000), -10);
        } catch (\Throwable) {
            return [];
        }
    }

    private static function path(Upid $upid): string
    {
        return '/nodes/' . rawurlencode($upid->node) . '/tasks/' . rawurlencode($upid->value);
    }
}
