<?php

declare(strict_types=1);

namespace Beziworld\Proxmox;

use Beziworld\Proxmox\Data\Version;
use Beziworld\Proxmox\Exceptions\GuestNotFound;
use Beziworld\Proxmox\Http\ProxmoxApi;
use Beziworld\Proxmox\Resources\Access;
use Beziworld\Proxmox\Resources\Cluster;
use Beziworld\Proxmox\Resources\Guest;
use Beziworld\Proxmox\Resources\Guests;
use Beziworld\Proxmox\Resources\Nodes;
use Beziworld\Proxmox\Resources\Storages;
use Beziworld\Proxmox\Resources\Tasks;

/**
 * Entry point to one Proxmox VE cluster, grouping the API by topic.
 *
 * Guests are addressed by VMID: vm(100) and container(200) look up the node
 * in the cluster resources unless it is passed explicitly.
 */
class ProxmoxClient
{
    public function __construct(
        private readonly ProxmoxApi $api,
    ) {}

    public function config(): ProxmoxConfig
    {
        return $this->api->config();
    }

    /**
     * Client whose node-scoped calls (qemu(), lxc(), storage()) use the given node.
     */
    public function onNode(string $node): self
    {
        return new self($this->api->withConfig($this->config()->withNode($node)));
    }

    public function version(): Version
    {
        $data = $this->api->get('/version');

        return Version::fromArray(is_array($data) ? $data : []);
    }

    public function nodes(): Nodes
    {
        return new Nodes($this->api);
    }

    public function cluster(): Cluster
    {
        return new Cluster($this->api);
    }

    public function tasks(): Tasks
    {
        return new Tasks($this->api);
    }

    public function access(): Access
    {
        return new Access($this->api);
    }

    /**
     * QEMU virtual machines of a node (the configured node by default).
     */
    public function qemu(?string $node = null): Guests
    {
        return new Guests($this->api, $this->tasks(), Guest::QEMU, $node ?? $this->config()->node);
    }

    /**
     * LXC containers of a node (the configured node by default).
     */
    public function lxc(?string $node = null): Guests
    {
        return new Guests($this->api, $this->tasks(), Guest::LXC, $node ?? $this->config()->node);
    }

    public function storage(?string $node = null): Storages
    {
        return new Storages($this->api, $this->tasks(), $node ?? $this->config()->node);
    }

    /**
     * A QEMU virtual machine.
     *
     * @throws GuestNotFound When the node is not given and the VMID is not in the cluster resources.
     */
    public function vm(int $vmid, ?string $node = null): Guest
    {
        return new Guest($this->api, $this->tasks(), Guest::QEMU, $node ?? $this->locate($vmid, Guest::QEMU), $vmid);
    }

    /**
     * An LXC container.
     *
     * @throws GuestNotFound When the node is not given and the VMID is not in the cluster resources.
     */
    public function container(int $vmid, ?string $node = null): Guest
    {
        return new Guest($this->api, $this->tasks(), Guest::LXC, $node ?? $this->locate($vmid, Guest::LXC), $vmid);
    }

    /**
     * A guest of either type, with type and node read from the cluster resources.
     */
    public function guest(int $vmid): Guest
    {
        $resource = $this->cluster()->findGuest($vmid) ?? throw GuestNotFound::forVmid($vmid, $this->config()->name);

        return new Guest($this->api, $this->tasks(), $resource->type, (string) $resource->node, $vmid);
    }

    /**
     * Wraps a UPID into a task handle, e.g. a UPID stored in a queued job.
     */
    public function task(string $upid): Task
    {
        return $this->tasks()->task($upid);
    }

    /**
     * Sends a request to any endpoint; returns the "data" member.
     *
     * @param array<string, scalar|null> $parameters
     */
    public function request(string $method, string $path, array $parameters = []): mixed
    {
        return $this->api->request($method, $path, $parameters);
    }

    public function api(): ProxmoxApi
    {
        return $this->api;
    }

    private function locate(int $vmid, string $type): string
    {
        $resource = $this->cluster()->findGuest($vmid);

        if ($resource === null || $resource->type !== $type || $resource->node === null) {
            throw GuestNotFound::forVmid($vmid, $this->config()->name);
        }

        return $resource->node;
    }
}
