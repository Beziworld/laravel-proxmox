<?php

declare(strict_types=1);

namespace Beziworld\Proxmox\Resources;

use Beziworld\Proxmox\Data\Storage;
use Beziworld\Proxmox\Data\StorageVolume;
use Beziworld\Proxmox\Data\Upid;
use Beziworld\Proxmox\Exceptions\InvalidConfiguration;
use Beziworld\Proxmox\Http\ProxmoxApi;
use Beziworld\Proxmox\Support\Values;
use Beziworld\Proxmox\Task;

/**
 * Storages of a node and their content (images, ISOs, templates, backups).
 */
final class Storages
{
    public function __construct(
        private readonly ProxmoxApi $api,
        private readonly Tasks $tasks,
        private readonly ?string $node,
    ) {}

    /**
     * @param string|null $content Only storages supporting this content type, e.g. "backup" or "images".
     * @return list<Storage>
     */
    public function list(?string $content = null, bool $enabledOnly = false): array
    {
        return array_map(Storage::fromArray(...), Values::rows($this->api->get($this->path(), [
            'content' => $content,
            'enabled' => $enabledOnly ? true : null,
        ])));
    }

    public function get(string $storage): Storage
    {
        $data = $this->api->get($this->path('/' . rawurlencode($storage) . '/status'));

        return Storage::fromArray((is_array($data) ? $data : []) + ['storage' => $storage]);
    }

    /**
     * @param string|null $content images, rootdir, iso, vztmpl, backup or snippets.
     * @return list<StorageVolume>
     */
    public function content(string $storage, ?string $content = null, ?int $vmid = null): array
    {
        return array_map(StorageVolume::fromArray(...), Values::rows($this->api->get($this->path('/' . rawurlencode($storage) . '/content'), [
            'content' => $content,
            'vmid' => $vmid,
        ])));
    }

    /**
     * Backup archives, newest first.
     *
     * @return list<StorageVolume>
     */
    public function backups(string $storage, ?int $vmid = null): array
    {
        $backups = $this->content($storage, 'backup', $vmid);
        usort($backups, static fn(StorageVolume $a, StorageVolume $b): int => ($b->createdAt?->getTimestamp() ?? 0) <=> ($a->createdAt?->getTimestamp() ?? 0));

        return $backups;
    }

    /**
     * Deletes a volume, e.g. an old backup archive ("backup-storage:backup/vzdump-qemu-100-....vma.zst").
     */
    public function deleteVolume(string $storage, string $volid): ?Task
    {
        $result = $this->api->delete($this->path('/' . rawurlencode($storage) . '/content/' . rawurlencode($volid)));

        return Upid::isValid($result) ? $this->tasks->task((string) $result) : null;
    }

    private function path(string $suffix = ''): string
    {
        $node = $this->node ?? throw InvalidConfiguration::missing('node', 'PROXMOX_NODE', $this->api->config()->name);

        return '/nodes/' . rawurlencode($node) . '/storage' . $suffix;
    }
}
