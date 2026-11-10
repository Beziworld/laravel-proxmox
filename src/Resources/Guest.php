<?php

declare(strict_types=1);

namespace Beziworld\Proxmox\Resources;

use Beziworld\Proxmox\Data\GuestStatus;
use Beziworld\Proxmox\Data\Snapshot;
use Beziworld\Proxmox\Data\StorageVolume;
use Beziworld\Proxmox\Data\Upid;
use Beziworld\Proxmox\Exceptions\ApiException;
use Beziworld\Proxmox\Http\ProxmoxApi;
use Beziworld\Proxmox\Support\Values;
use Beziworld\Proxmox\Task;
use InvalidArgumentException;

/**
 * One QEMU virtual machine or LXC container: lifecycle, configuration,
 * cloning, snapshots, backups, migration and removal.
 *
 * Every state-changing call returns a Task handle (UPID); call wait() on it
 * to block until Proxmox finished the operation.
 */
final class Guest
{
    public const string QEMU = 'qemu';

    public const string LXC = 'lxc';

    public function __construct(
        private readonly ProxmoxApi $api,
        private readonly Tasks $tasks,
        public readonly string $type,
        public readonly string $node,
        public readonly int $vmid,
    ) {
        if ($type !== self::QEMU && $type !== self::LXC) {
            throw new InvalidArgumentException(sprintf('Unknown guest type "%s"; expected "qemu" or "lxc".', $type));
        }
    }

    public function isContainer(): bool
    {
        return $this->type === self::LXC;
    }

    public function status(): GuestStatus
    {
        $data = $this->api->get($this->path('/status/current'));

        return GuestStatus::fromArray((is_array($data) ? $data : []) + ['vmid' => $this->vmid], $this->type);
    }

    public function start(): Task
    {
        return $this->taskFrom($this->api->post($this->path('/status/start')));
    }

    /**
     * Hard stop, comparable to pulling the power cord.
     */
    public function stop(?int $timeout = null): Task
    {
        // Containers accept no timeout for a hard stop.
        return $this->taskFrom($this->api->post($this->path('/status/stop'), ['timeout' => $this->isContainer() ? null : $timeout]));
    }

    /**
     * Clean shutdown through ACPI (VMs) or the init system (containers).
     *
     * @param bool $forceStop Stop the guest when it does not shut down within the timeout.
     */
    public function shutdown(?int $timeout = null, bool $forceStop = false): Task
    {
        return $this->taskFrom($this->api->post($this->path('/status/shutdown'), [
            'timeout' => $timeout,
            'forceStop' => $forceStop ? true : null,
        ]));
    }

    public function reboot(?int $timeout = null): Task
    {
        return $this->taskFrom($this->api->post($this->path('/status/reboot'), ['timeout' => $timeout]));
    }

    /**
     * Hard reset (QEMU only).
     */
    public function reset(): Task
    {
        $this->requireQemu('reset');

        return $this->taskFrom($this->api->post($this->path('/status/reset')));
    }

    public function suspend(): Task
    {
        return $this->taskFrom($this->api->post($this->path('/status/suspend')));
    }

    public function resume(): Task
    {
        return $this->taskFrom($this->api->post($this->path('/status/resume')));
    }

    /**
     * Configuration with pending changes applied; $current returns the active configuration instead.
     *
     * @return array<array-key, mixed>
     */
    public function config(bool $current = false): array
    {
        $data = $this->api->get($this->path('/config'), $current ? ['current' => true] : []);

        return is_array($data) ? $data : [];
    }

    /**
     * Changes configuration options, e.g. ["memory" => 4096, "cores" => 4] or ["delete" => "net1"].
     *
     * QEMU changes are applied asynchronously (POST) and return a task when
     * Proxmox started one (hotplug, disk allocation); container changes are
     * synchronous (PUT) and return null.
     *
     * @param array<string, scalar|null> $options
     */
    public function updateConfig(array $options, ?string $digest = null): ?Task
    {
        $options = $options + ['digest' => $digest];

        if ($this->isContainer()) {
            $this->api->put($this->path('/config'), $options);

            return null;
        }

        $result = $this->api->post($this->path('/config'), $options);

        return Upid::isValid($result) ? $this->tasks->task((string) $result) : null;
    }

    /**
     * Grows a disk, e.g. resize("scsi0", "+10G") or resize("rootfs", "32G").
     */
    public function resize(string $disk, string $size): ?Task
    {
        $result = $this->api->put($this->path('/resize'), ['disk' => $disk, 'size' => $size]);

        return Upid::isValid($result) ? $this->tasks->task((string) $result) : null;
    }

    /**
     * Clones the guest (or template) into a new VMID.
     *
     * @param array{name?: string, hostname?: string, description?: string, full?: bool, pool?: string, snapname?: string, storage?: string, format?: string, target?: string, bwlimit?: int} $options
     */
    public function clone(int $newId, array $options = []): Task
    {
        if (isset($options['name']) && $this->isContainer() && ! isset($options['hostname'])) {
            $options['hostname'] = $options['name'];
            unset($options['name']);
        }

        return $this->taskFrom($this->api->post($this->path('/clone'), ['newid' => $newId] + $options));
    }

    /**
     * Converts the guest into a template.
     */
    public function convertToTemplate(): ?Task
    {
        $result = $this->api->post($this->path('/template'));

        return Upid::isValid($result) ? $this->tasks->task((string) $result) : null;
    }

    /**
     * Moves the guest to another node.
     *
     * @param bool $online Live migration of a running VM; for a running container a restart migration.
     * @param array<string, scalar|null> $options e.g. ["with-local-disks" => true, "targetstorage" => "local-lvm"]
     */
    public function migrate(string $target, bool $online = false, array $options = []): Task
    {
        $parameters = ['target' => $target] + $options;
        $parameters[$this->isContainer() ? 'restart' : 'online'] = $online ? true : null;

        return $this->taskFrom($this->api->post($this->path('/migrate'), $parameters));
    }

    /**
     * Destroys the guest and its disks.
     *
     * @param bool $purge Also remove the guest from backup jobs, replication and HA.
     */
    public function destroy(bool $purge = false, bool $destroyUnreferencedDisks = false, bool $force = false): Task
    {
        return $this->taskFrom($this->api->delete($this->path(), [
            'purge' => $purge ? true : null,
            'destroy-unreferenced-disks' => $destroyUnreferencedDisks ? true : null,
            'force' => $force && $this->isContainer() ? true : null,
        ]));
    }

    /**
     * Snapshots of the guest, without the "current" pseudo entry.
     *
     * @return list<Snapshot>
     */
    public function snapshots(): array
    {
        return array_values(array_filter(
            array_map(Snapshot::fromArray(...), Values::rows($this->api->get($this->path('/snapshot')))),
            static fn(Snapshot $snapshot): bool => ! $snapshot->isCurrent(),
        ));
    }

    /**
     * @param bool $withRam Include the RAM state (QEMU only).
     */
    public function snapshot(string $name, ?string $description = null, bool $withRam = false): Task
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_-]{1,39}$/', $name) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid snapshot name "%s": use 2 to 40 letters, digits, "-" or "_", starting with a letter.', $name));
        }

        return $this->taskFrom($this->api->post($this->path('/snapshot'), [
            'snapname' => $name,
            'description' => $description,
            'vmstate' => $withRam && ! $this->isContainer() ? true : null,
        ]));
    }

    /**
     * @param bool $start Start the guest after the rollback.
     */
    public function rollback(string $snapshot, bool $start = false): Task
    {
        return $this->taskFrom($this->api->post($this->path('/snapshot/' . rawurlencode($snapshot) . '/rollback'), ['start' => $start ? true : null]));
    }

    public function deleteSnapshot(string $snapshot, bool $force = false): Task
    {
        return $this->taskFrom($this->api->delete($this->path('/snapshot/' . rawurlencode($snapshot)), ['force' => $force ? true : null]));
    }

    /**
     * Starts a backup (vzdump) of the guest.
     *
     * @param string $mode snapshot, suspend or stop.
     * @param string|null $compress 0, 1, gzip, lzo or zstd.
     * @param array<string, scalar|null> $options e.g. ["notes-template" => "{{guestname}}", "protected" => true, "remove" => true]
     */
    public function backup(?string $storage = null, string $mode = 'snapshot', ?string $compress = 'zstd', array $options = []): Task
    {
        if (! in_array($mode, ['snapshot', 'suspend', 'stop'], true)) {
            throw new InvalidArgumentException('The backup mode must be "snapshot", "suspend" or "stop".');
        }

        return $this->taskFrom($this->api->post('/nodes/' . rawurlencode($this->node) . '/vzdump', [
            'vmid' => $this->vmid,
            'storage' => $storage,
            'mode' => $mode,
            'compress' => $compress,
        ] + $options));
    }

    /**
     * Backup archives of the guest on a storage.
     *
     * @return list<StorageVolume>
     */
    public function backups(string $storage): array
    {
        return array_map(StorageVolume::fromArray(...), Values::rows($this->api->get(
            '/nodes/' . rawurlencode($this->node) . '/storage/' . rawurlencode($storage) . '/content',
            ['content' => 'backup', 'vmid' => $this->vmid],
        )));
    }

    /**
     * Network interfaces reported by the QEMU guest agent (VMs) or by the container.
     *
     * @return list<array<array-key, mixed>>
     */
    public function interfaces(): array
    {
        $data = $this->isContainer()
            ? $this->api->get($this->path('/interfaces'))
            : $this->api->get($this->path('/agent/network-get-interfaces'));

        return Values::rows(is_array($data) && array_key_exists('result', $data) ? $data['result'] : $data);
    }

    private function path(string $suffix = ''): string
    {
        return sprintf('/nodes/%s/%s/%d%s', rawurlencode($this->node), $this->type, $this->vmid, $suffix);
    }

    private function taskFrom(mixed $result): Task
    {
        if (! Upid::isValid($result)) {
            throw new ApiException(sprintf('Proxmox did not return a task id for %s %d.', $this->type, $this->vmid));
        }

        return $this->tasks->task((string) $result);
    }

    private function requireQemu(string $operation): void
    {
        if ($this->isContainer()) {
            throw new InvalidArgumentException(sprintf('The "%s" operation is only available for QEMU virtual machines.', $operation));
        }
    }
}
