# Laravel Proxmox VE

[![tests](https://github.com/beziworld/laravel-proxmox/actions/workflows/tests.yml/badge.svg)](https://github.com/beziworld/laravel-proxmox/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/beziworld/laravel-proxmox.svg)](https://packagist.org/packages/beziworld/laravel-proxmox)
[![License](https://img.shields.io/packagist/l/beziworld/laravel-proxmox.svg)](LICENSE.md)

Laravel client for the [Proxmox VE](https://www.proxmox.com/en/proxmox-virtual-environment) API: nodes, QEMU
virtual machines and LXC containers (lifecycle, configuration, cloning, snapshots, backups, migration), storage,
asynchronous tasks with a UPID waiter, cluster resources and access control, for one or many clusters.

[Polska wersja dokumentacji](README.pl.md)

## Features

- API tokens (`PVEAPIToken=user@realm!id=secret`) or username/password tickets with the
  `CSRFPreventionToken`, cached and renewed automatically
- Multiple named clusters plus `using([...])` for clusters stored in your database
- TLS verification on by default, per connection `verify_ssl` true, false or a CA bundle path
  (`/etc/pve/pve-root-ca.pem`)
- Guests addressed by VMID: `Proxmox::vm(100)` finds the node in the cluster resources
- Every asynchronous call returns a `Task` (UPID) with `wait()`, `status()`, `log()` and `stop()`; failures raise
  `TaskFailed` with the last log lines, long tasks `TaskTimeout`
- Typed DTOs (`Node`, `ClusterResource`, `GuestStatus`, `Snapshot`, `TaskStatus`, `Storage`, `StorageVolume`,
  `ApiToken`, `User`) that keep the raw payload
- Reads retried on connection errors, HTTP 5xx and 595/596, writes never retried; validation errors exposed per
  parameter
- `php artisan proxmox:check` and `php artisan proxmox:task {upid}`
- PHP 8.3+, Laravel 12 and 13, PHPStan level 8, works with `Http::fake()` and `Sleep::fake()`

## Why this package

On Packagist (November 2026) `corsinvest/cv4pve-api-php` has about 1.37M downloads: a complete generated client,
framework-agnostic and curl-based. Laravel packages are rare: `irabbi360/laravel-php-proxmox` (about 4.6k downloads)
always disables TLS verification, supports only password tickets for a single server and has no task handling;
`h1g/proxmox` (160 downloads) is a 2016 Laravel 5 binding. This package adds:

- API token authentication and ticket caching shared by queue workers
- several clusters, each with its own credentials, node and TLS settings
- a UPID task waiter, so provisioning flows (clone, configure, start) can be written top to bottom
- Laravel's HTTP client (testable with `Http::fake()`), typed exceptions and DTOs

## Installation

```bash
composer require beziworld/laravel-proxmox
php artisan vendor:publish --tag=proxmox-config   # optional
```

```dotenv
PROXMOX_URL=https://pve1.example.com:8006
PROXMOX_TOKEN_ID=automation@pve!laravel          # Datacenter > Permissions > API Tokens
PROXMOX_TOKEN_SECRET=b3c6a8e2-....
PROXMOX_NODE=pve1                                # default node for create/list calls
PROXMOX_VERIFY_SSL=/etc/ssl/pve-root-ca.pem      # true, false or a CA bundle path

# or a ticket login instead of the token:
# PROXMOX_USERNAME=root@pam
# PROXMOX_PASSWORD=
```

```bash
php artisan proxmox:check
```

API tokens with privilege separation only hold the permissions granted to the token itself: grant a role (for
example `PVEVMAdmin` on `/vms` and `PVEDatastoreUser` on `/storage`) to the token, not only to its user.

More clusters go into `config/proxmox.php`:

```php
'connections' => [
    'default' => [...],
    'dc2' => [
        'url' => 'https://dc2-pve1.example.com:8006',
        'token_id' => env('PROXMOX_DC2_TOKEN_ID'),
        'token_secret' => env('PROXMOX_DC2_TOKEN_SECRET'),
        'node' => 'dc2-pve1',
        'verify_ssl' => storage_path('certs/dc2-pve-root-ca.pem'),
    ],
],
```

## Usage

### Provision a VM from a template

```php
use Beziworld\Proxmox\Facades\Proxmox;

$vmid = Proxmox::cluster()->nextId();

Proxmox::vm(9000)->clone($vmid, ['name' => 'shop-42', 'full' => true, 'storage' => 'local-lvm'])->wait();

$vm = Proxmox::vm($vmid);
$vm->updateConfig(['memory' => 4096, 'cores' => 2, 'ipconfig0' => 'ip=10.0.0.42/24,gw=10.0.0.1'])?->wait();
$vm->resize('scsi0', '+20G')?->wait();
$vm->start()->wait();
```

### Lifecycle

```php
$vm = Proxmox::vm(100);                      // node looked up in /cluster/resources
$vm = Proxmox::vm(100, 'pve1');              // or given explicitly
$ct = Proxmox::container(200);
$guest = Proxmox::guest(100);                // type and node detected

$vm->status()->isRunning();
$vm->start(); $vm->shutdown(timeout: 120, forceStop: true); $vm->stop();
$vm->reboot(); $vm->reset(); $vm->suspend(); $vm->resume();
$vm->config(); $vm->config(current: true);
$vm->migrate('pve2', online: true)->wait(timeout: 3600);
$vm->convertToTemplate();
$vm->destroy(purge: true);
```

### Snapshots and backups

```php
$vm->snapshot('before-upgrade', 'PHP 8.4 upgrade', withRam: false)->wait();
$vm->snapshots();                              // list<Snapshot>
$vm->rollback('before-upgrade', start: true)->wait();
$vm->deleteSnapshot('before-upgrade');

$vm->backup('pbs', mode: 'snapshot', compress: 'zstd', options: ['notes-template' => '{{guestname}}'])->wait();
$vm->backups('pbs');                           // list<StorageVolume>
Proxmox::storage('pve1')->deleteVolume('pbs', $backup->volid);
```

### Tasks (UPID)

```php
$task = $vm->start();                          // Task
$upid = (string) $task;                        // store it, e.g. in a queued job

$status = Proxmox::task($upid)->wait(timeout: 600, interval: 2, onPoll: fn($s) => logger()->info($s->status));
$status->succeeded(); $status->exitStatus;     // "OK", "WARNINGS: 1" or the error message

Proxmox::task($upid)->log();                   // list<string>
Proxmox::tasks()->forNode('pve1', ['vmid' => 100, 'errors' => true]);
Proxmox::tasks()->recent();                    // cluster tasks
```

```bash
php artisan proxmox:task "UPID:pve1:000A1B2C:0123ABCD:6730A1B2:qmstart:100:root@pam:" --timeout=300
```

### Nodes, cluster and storage

```php
Proxmox::nodes()->list();                      // list<Node>
Proxmox::nodes()->status('pve1');
Proxmox::cluster()->resources('vm');           // list<ClusterResource>
Proxmox::cluster()->guests();
Proxmox::cluster()->isIdFree(150);
Proxmox::qemu('pve1')->list();                 // list<GuestStatus>
Proxmox::lxc('pve1')->create([
    'hostname' => 'ct1',
    'ostemplate' => 'local:vztmpl/debian-12-standard_12.7-1_amd64.tar.zst',
    'rootfs' => 'local-lvm:8',
    'net0' => 'name=eth0,bridge=vmbr0,ip=dhcp',
])->wait();
Proxmox::storage('pve1')->list('backup');      // list<Storage>
Proxmox::storage('pve1')->content('local', 'iso');
```

### Access control

```php
$token = Proxmox::access()->createToken('ci@pve', 'deploy', comment: 'CI', expiresAt: now()->addYear());
$token->value;                                 // shown only once, store it
Proxmox::access()->updateAcl('/vms/100', ['PVEVMUser'], tokens: [$token->fullTokenId]);
Proxmox::access()->can('/vms/100', 'VM.PowerMgmt');
Proxmox::access()->users();
```

### Runtime clusters and anything else

```php
Proxmox::using([
    'url' => $cluster->url,
    'token_id' => $cluster->token_id,
    'token_secret' => decrypt($cluster->token_secret),
])->vm(100)->status();

Proxmox::request('GET', '/cluster/ha/resources');
Proxmox::request('POST', '/nodes/pve1/qemu/100/agent/ping');
```

## Exceptions

All exceptions extend `Beziworld\Proxmox\Exceptions\ProxmoxException`:

| Exception | When |
|---|---|
| `AuthenticationException` | Token, ticket or login rejected (HTTP 401). A rejected login is never repeated. |
| `PermissionDenied` | Missing privilege (HTTP 403) |
| `ApiException` | Other error status; `httpStatus()`, `errors()` (per parameter), `payload()` |
| `RateLimitException` | HTTP 429 from a proxy in front of the cluster; `retryAfter()` |
| `ConnectionFailed` | DNS, TCP, TLS or timeout error (after read retries) |
| `TaskFailed` | `wait()` saw an exit status other than OK; `status()` and the last log lines |
| `TaskTimeout` | `wait()` gave up; the task keeps running (`upid()`) |
| `GuestNotFound` | VMID not in the cluster resources (or not visible to the token) |
| `InvalidConfiguration` | Unknown connection, missing URL, credentials or node |

## Notes

- Calls are synchronous and tasks such as clones, backups and migrations take minutes: run them in queued jobs and
  keep the UPID, or use `wait()` with a timeout that fits the job.
- `updateConfig()` uses the asynchronous POST endpoint for VMs (needed for hotplug and disk allocation) and
  returns a task only when Proxmox started one.
- Two-factor authentication is not supported for ticket logins: use an API token.

## Development

```bash
composer install
composer ci   # pint --test, phpstan (level 8), pest
```

## Author

Created and maintained by **Kamil Ćwiertnia**, [beziworld.eu](https://beziworld.eu).

- E-mail: [biuro@beziworld.eu](mailto:biuro@beziworld.eu)
- Issues and feature requests: [GitHub issues](https://github.com/beziworld/laravel-proxmox/issues)
- Security vulnerabilities: see [SECURITY.md](SECURITY.md)

Need an integration? Get in touch through [beziworld.eu](https://beziworld.eu).

### Other packages by the author

- [beziworld/laravel-directadmin](https://github.com/beziworld/laravel-directadmin): DirectAdmin hosting panel
- [beziworld/laravel-plesk](https://github.com/beziworld/laravel-plesk): Plesk hosting panel
- [beziworld/laravel-unifi](https://github.com/beziworld/laravel-unifi): UniFi Network

## License

MIT. See [LICENSE.md](LICENSE.md). This package is not affiliated with, endorsed by or sponsored by Proxmox Server
Solutions GmbH. Proxmox is a registered trademark of Proxmox Server Solutions GmbH.
