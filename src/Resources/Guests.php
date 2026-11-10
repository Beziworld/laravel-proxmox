<?php

declare(strict_types=1);

namespace Beziworld\Proxmox\Resources;

use Beziworld\Proxmox\Data\GuestStatus;
use Beziworld\Proxmox\Data\Upid;
use Beziworld\Proxmox\Exceptions\ApiException;
use Beziworld\Proxmox\Exceptions\InvalidConfiguration;
use Beziworld\Proxmox\Http\ProxmoxApi;
use Beziworld\Proxmox\Support\Values;
use Beziworld\Proxmox\Task;

/**
 * QEMU virtual machines or LXC containers of one node: listing and creation.
 */
final class Guests
{
    public function __construct(
        private readonly ProxmoxApi $api,
        private readonly Tasks $tasks,
        private readonly string $type,
        private readonly ?string $node,
    ) {}

    /**
     * @return list<GuestStatus>
     */
    public function list(): array
    {
        return array_map(
            fn(array $row): GuestStatus => GuestStatus::fromArray($row, $this->type),
            Values::rows($this->api->get($this->path(), $this->type === Guest::QEMU ? ['full' => true] : [])),
        );
    }

    public function get(int $vmid): Guest
    {
        return new Guest($this->api, $this->tasks, $this->type, $this->node(), $vmid);
    }

    /**
     * Creates a guest; $vmid null takes the next free id from the cluster.
     *
     * QEMU example: ["name" => "web1", "memory" => 2048, "cores" => 2, "scsi0" => "local-lvm:32", "net0" => "virtio,bridge=vmbr0"].
     * LXC example: ["hostname" => "ct1", "ostemplate" => "local:vztmpl/debian-12-standard_12.7-1_amd64.tar.zst", "rootfs" => "local-lvm:8", "net0" => "name=eth0,bridge=vmbr0,ip=dhcp"].
     *
     * @param array<string, scalar|null> $options
     */
    public function create(array $options, ?int $vmid = null): Task
    {
        $vmid ??= Values::int(['id' => $this->api->get('/cluster/nextid')], 'id');
        $result = $this->api->post($this->path(), ['vmid' => $vmid] + $options);

        if (! Upid::isValid($result)) {
            throw new ApiException(sprintf('Proxmox did not return a task id for the new %s %d.', $this->type, $vmid));
        }

        return $this->tasks->task((string) $result);
    }

    private function node(): string
    {
        return $this->node ?? throw InvalidConfiguration::missing('node', 'PROXMOX_NODE', $this->api->config()->name);
    }

    private function path(): string
    {
        return '/nodes/' . rawurlencode($this->node()) . '/' . $this->type;
    }
}
