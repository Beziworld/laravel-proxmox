<?php

declare(strict_types=1);

namespace Beziworld\Proxmox\Resources;

use Beziworld\Proxmox\Data\ClusterResource;
use Beziworld\Proxmox\Exceptions\ApiException;
use Beziworld\Proxmox\Http\ProxmoxApi;
use Beziworld\Proxmox\Support\Values;
use InvalidArgumentException;

/**
 * Cluster-wide views: resources, next free VMID and cluster status.
 */
final class Cluster
{
    public function __construct(
        private readonly ProxmoxApi $api,
    ) {}

    /**
     * Every node, guest, storage and SDN object of the cluster.
     *
     * @param string|null $type vm, storage, node or sdn.
     * @return list<ClusterResource>
     */
    public function resources(?string $type = null): array
    {
        if ($type !== null && ! in_array($type, ['vm', 'storage', 'node', 'sdn'], true)) {
            throw new InvalidArgumentException('The resource type must be "vm", "storage", "node" or "sdn".');
        }

        return array_map(ClusterResource::fromArray(...), Values::rows($this->api->get('/cluster/resources', ['type' => $type])));
    }

    /**
     * QEMU VMs and LXC containers of the cluster, templates included.
     *
     * @return list<ClusterResource>
     */
    public function guests(): array
    {
        return array_values(array_filter($this->resources('vm'), static fn(ClusterResource $resource): bool => $resource->isGuest()));
    }

    public function findGuest(int $vmid): ?ClusterResource
    {
        foreach ($this->guests() as $guest) {
            if ($guest->vmid === $vmid) {
                return $guest;
            }
        }

        return null;
    }

    /**
     * Next free VMID.
     */
    public function nextId(): int
    {
        return Values::int(['id' => $this->api->get('/cluster/nextid')], 'id');
    }

    /**
     * Whether the VMID is free at the time of the check.
     */
    public function isIdFree(int $vmid): bool
    {
        try {
            $this->api->get('/cluster/nextid', ['vmid' => $vmid]);

            return true;
        } catch (ApiException $exception) {
            if ($exception->httpStatus() === 400) {
                return false;
            }

            throw $exception;
        }
    }

    /**
     * Cluster and quorum status entries (type "cluster" and "node").
     *
     * @return list<array<array-key, mixed>>
     */
    public function status(): array
    {
        return Values::rows($this->api->get('/cluster/status'));
    }
}
