<?php

declare(strict_types=1);

namespace Beziworld\Proxmox\Resources;

use Beziworld\Proxmox\Data\Node;
use Beziworld\Proxmox\Data\Version;
use Beziworld\Proxmox\Http\ProxmoxApi;
use Beziworld\Proxmox\Support\Values;

/**
 * Cluster nodes.
 */
final class Nodes
{
    public function __construct(
        private readonly ProxmoxApi $api,
    ) {}

    /**
     * @return list<Node>
     */
    public function list(): array
    {
        return array_map(Node::fromArray(...), Values::rows($this->api->get('/nodes')));
    }

    /**
     * @return list<Node>
     */
    public function online(): array
    {
        return array_values(array_filter($this->list(), static fn(Node $node): bool => $node->isOnline()));
    }

    /**
     * Detailed node status: cpu, memory, swap, rootfs, loadavg, kversion, pveversion, boot-info, ...
     *
     * @return array<array-key, mixed>
     */
    public function status(string $node): array
    {
        $data = $this->api->get('/nodes/' . rawurlencode($node) . '/status');

        return is_array($data) ? $data : [];
    }

    public function version(string $node): Version
    {
        $data = $this->api->get('/nodes/' . rawurlencode($node) . '/version');

        return Version::fromArray(is_array($data) ? $data : []);
    }
}
