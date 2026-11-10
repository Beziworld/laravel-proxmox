<?php

declare(strict_types=1);

namespace Beziworld\Proxmox\Facades;

use Beziworld\Proxmox\ProxmoxManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Beziworld\Proxmox\ProxmoxClient connection(?string $name = null)
 * @method static \Beziworld\Proxmox\ProxmoxClient using(\Beziworld\Proxmox\ProxmoxConfig|array<string, mixed> $config, string $name = 'runtime')
 * @method static string getDefaultConnection()
 * @method static list<string> connectionNames()
 * @method static void purge(?string $name = null)
 * @method static \Beziworld\Proxmox\ProxmoxConfig config()
 * @method static \Beziworld\Proxmox\ProxmoxClient onNode(string $node)
 * @method static \Beziworld\Proxmox\Data\Version version()
 * @method static \Beziworld\Proxmox\Resources\Nodes nodes()
 * @method static \Beziworld\Proxmox\Resources\Cluster cluster()
 * @method static \Beziworld\Proxmox\Resources\Tasks tasks()
 * @method static \Beziworld\Proxmox\Resources\Access access()
 * @method static \Beziworld\Proxmox\Resources\Guests qemu(?string $node = null)
 * @method static \Beziworld\Proxmox\Resources\Guests lxc(?string $node = null)
 * @method static \Beziworld\Proxmox\Resources\Storages storage(?string $node = null)
 * @method static \Beziworld\Proxmox\Resources\Guest vm(int $vmid, ?string $node = null)
 * @method static \Beziworld\Proxmox\Resources\Guest container(int $vmid, ?string $node = null)
 * @method static \Beziworld\Proxmox\Resources\Guest guest(int $vmid)
 * @method static \Beziworld\Proxmox\Task task(string $upid)
 * @method static mixed request(string $method, string $path, array<string, scalar|null> $parameters = [])
 * @method static \Beziworld\Proxmox\Http\ProxmoxApi api()
 *
 * @see ProxmoxManager
 * @see \Beziworld\Proxmox\ProxmoxClient
 */
final class Proxmox extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ProxmoxManager::class;
    }
}
