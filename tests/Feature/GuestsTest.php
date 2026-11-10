<?php

declare(strict_types=1);

use Beziworld\Proxmox\Exceptions\GuestNotFound;
use Beziworld\Proxmox\Exceptions\InvalidConfiguration;
use Beziworld\Proxmox\Facades\Proxmox;
use Beziworld\Proxmox\Resources\Guest;
use Beziworld\Proxmox\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('locates the node of a vm in the cluster resources', function (): void {
    Http::fake([
        TestCase::API . '/cluster/resources*' => Http::response(jsonFixture('cluster-resources')),
        TestCase::API . '/nodes/pve2/lxc/200/status/start' => Http::response(TestCase::data(TestCase::UPID)),
    ]);

    $task = Proxmox::container(200)->start();

    expect((string) $task)->toBe(TestCase::UPID)->and($task->node())->toBe('pve1');
    Http::assertSent(fn(Request $request): bool => $request->url() === TestCase::API . '/cluster/resources?type=vm');
    Http::assertSent(fn(Request $request): bool => $request->method() === 'POST' && $request->url() === TestCase::API . '/nodes/pve2/lxc/200/status/start');
});

it('detects the guest type with guest()', function (): void {
    Http::fake(['*' => Http::response(jsonFixture('cluster-resources'))]);

    $guest = Proxmox::guest(9000);

    expect($guest->type)->toBe(Guest::QEMU)->and($guest->node)->toBe('pve2');
});

it('reports unknown or mistyped vmids', function (): void {
    Http::fake(['*' => Http::response(jsonFixture('cluster-resources'))]);

    expect(fn() => Proxmox::vm(555))->toThrow(GuestNotFound::class, 'VMID 555')
        ->and(fn() => Proxmox::vm(200))->toThrow(GuestNotFound::class);
});

it('reads the runtime status', function (): void {
    Http::fake(['*' => Http::response(jsonFixture('qemu-status'))]);

    $status = Proxmox::vm(100, 'pve1')->status();

    expect($status->isRunning())->toBeTrue()
        ->and($status->tags)->toBe(['prod', 'web'])
        ->and($status->cpus)->toBe(4.0);
    Http::assertSent(fn(Request $request): bool => $request->url() === TestCase::API . '/nodes/pve1/qemu/100/status/current');
});

it('runs every lifecycle action', function (string $method, string $path): void {
    Http::fake(['*' => Http::response(TestCase::data(TestCase::UPID))]);

    Proxmox::vm(100, 'pve1')->{$method}();

    Http::assertSent(fn(Request $request): bool => $request->method() === 'POST' && $request->url() === TestCase::API . '/nodes/pve1/qemu/100/status/' . $path);
})->with([
    ['start', 'start'],
    ['stop', 'stop'],
    ['shutdown', 'shutdown'],
    ['reboot', 'reboot'],
    ['reset', 'reset'],
    ['suspend', 'suspend'],
    ['resume', 'resume'],
]);

it('refuses reset for containers', function (): void {
    expect(fn() => Proxmox::container(200, 'pve2')->reset())->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
});

it('updates qemu configuration asynchronously and container configuration synchronously', function (): void {
    Http::fake([
        '*/qemu/100/config' => Http::response(TestCase::data(TestCase::UPID)),
        '*/lxc/200/config' => Http::response(TestCase::data(null)),
    ]);

    $task = Proxmox::vm(100, 'pve1')->updateConfig(['memory' => 8192, 'cores' => 4, 'delete' => 'net1', 'onboot' => true]);
    $none = Proxmox::container(200, 'pve2')->updateConfig(['memory' => 1024], digest: 'abc');

    expect($task)->not->toBeNull()->and($none)->toBeNull();
    Http::assertSent(fn(Request $request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/qemu/100/config')
        && $request->data() === ['memory' => '8192', 'cores' => '4', 'delete' => 'net1', 'onboot' => '1']);
    Http::assertSent(fn(Request $request): bool => $request->method() === 'PUT'
        && str_ends_with($request->url(), '/lxc/200/config')
        && $request->data() === ['memory' => '1024', 'digest' => 'abc']);
});

it('reads the configuration', function (): void {
    Http::fake(['*' => Http::response(TestCase::data(['name' => 'web1', 'memory' => '4096', 'net0' => 'virtio=BC:24:11:00:00:01,bridge=vmbr0', 'digest' => 'abc']))]);

    expect(Proxmox::vm(100, 'pve1')->config(current: true)['net0'])->toStartWith('virtio=');
    Http::assertSent(fn(Request $request): bool => $request->url() === TestCase::API . '/nodes/pve1/qemu/100/config?current=1');
});

it('clones templates and maps the container hostname', function (): void {
    Http::fake(['*' => Http::response(TestCase::data(TestCase::UPID))]);

    Proxmox::vm(9000, 'pve2')->clone(123, ['name' => 'web2', 'full' => true, 'storage' => 'local-lvm', 'target' => 'pve1']);
    Proxmox::container(200, 'pve2')->clone(201, ['name' => 'ct-db2']);

    Http::assertSent(fn(Request $request): bool => str_ends_with($request->url(), '/qemu/9000/clone')
        && $request->data() === ['newid' => '123', 'name' => 'web2', 'full' => '1', 'storage' => 'local-lvm', 'target' => 'pve1']);
    Http::assertSent(fn(Request $request): bool => str_ends_with($request->url(), '/lxc/200/clone')
        && $request->data() === ['newid' => '201', 'hostname' => 'ct-db2']);
});

it('creates guests with the next free vmid', function (): void {
    Http::fake([
        TestCase::API . '/cluster/nextid' => Http::response(TestCase::data('105')),
        TestCase::API . '/nodes/pve1/qemu' => Http::response(TestCase::data(TestCase::UPID)),
    ]);

    Proxmox::qemu()->create(['name' => 'api1', 'memory' => 2048, 'scsi0' => 'local-lvm:32', 'net0' => 'virtio,bridge=vmbr0']);

    Http::assertSent(fn(Request $request): bool => $request->method() === 'POST'
        && $request->url() === TestCase::API . '/nodes/pve1/qemu'
        && ($request->data()['vmid'] ?? null) === '105'
        && ($request->data()['scsi0'] ?? null) === 'local-lvm:32');
});

it('lists guests of a node', function (): void {
    Http::fake(['*' => Http::response(TestCase::data([jsonFixture('qemu-status')['data']]))]);

    $vms = Proxmox::qemu('pve1')->list();

    expect($vms[0]->vmid)->toBe(100)->and($vms[0]->type)->toBe('qemu');
    Http::assertSent(fn(Request $request): bool => $request->url() === TestCase::API . '/nodes/pve1/qemu?full=1');
});

it('requires a node for node-scoped calls', function (): void {
    config()->set('proxmox.connections.default.node', null);

    expect(fn() => Proxmox::lxc()->list())->toThrow(InvalidConfiguration::class, 'PROXMOX_NODE');
});

it('manages snapshots', function (): void {
    Http::fake([
        '*/snapshot' => fn(Request $request) => $request->method() === 'GET' ? Http::response(jsonFixture('snapshots')) : Http::response(TestCase::data(TestCase::UPID)),
        '*' => Http::response(TestCase::data(TestCase::UPID)),
    ]);

    $vm = Proxmox::vm(100, 'pve1');
    $snapshots = $vm->snapshots();
    $vm->snapshot('pre-deploy', 'Before release 2.3', withRam: true);
    $vm->rollback('before-upgrade', start: true);
    $vm->deleteSnapshot('before-upgrade');

    expect($snapshots)->toHaveCount(1)
        ->and($snapshots[0]->name)->toBe('before-upgrade')
        ->and($snapshots[0]->createdAt?->toIso8601ZuluString())->toBe('2026-11-10T14:00:00Z');

    Http::assertSent(fn(Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/qemu/100/snapshot')
        && $request->data() === ['snapname' => 'pre-deploy', 'description' => 'Before release 2.3', 'vmstate' => '1']);
    Http::assertSent(fn(Request $request): bool => str_ends_with($request->url(), '/snapshot/before-upgrade/rollback') && $request->data() === ['start' => '1']);
    Http::assertSent(fn(Request $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/qemu/100/snapshot/before-upgrade'));
});

it('validates snapshot names before sending', function (): void {
    expect(fn() => Proxmox::vm(100, 'pve1')->snapshot('1 bad name'))->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
});

it('starts backups and lists them newest first', function (): void {
    Http::fake([
        TestCase::API . '/nodes/pve1/vzdump' => Http::response(TestCase::data(TestCase::UPID)),
        TestCase::API . '/nodes/pve1/storage/pbs/content*' => Http::response(jsonFixture('backups')),
    ]);

    $vm = Proxmox::vm(100, 'pve1');
    $vm->backup('pbs', 'snapshot', 'zstd', ['notes-template' => '{{guestname}}', 'protected' => true]);
    $backups = $vm->backups('pbs');

    expect($backups[0]->volid)->toBe('pbs:backup/vm/100/2026-11-08T22:00:03Z');
    Http::assertSent(fn(Request $request): bool => $request->url() === TestCase::API . '/nodes/pve1/vzdump'
        && $request->data() === ['vmid' => '100', 'storage' => 'pbs', 'mode' => 'snapshot', 'compress' => 'zstd', 'notes-template' => '{{guestname}}', 'protected' => '1']);
    Http::assertSent(fn(Request $request): bool => $request->url() === TestCase::API . '/nodes/pve1/storage/pbs/content?content=backup&vmid=100');
    expect(Proxmox::storage()->backups('pbs', 100)[0]->createdAt?->getTimestamp())->toBe(1794261602);
});

it('migrates, resizes and destroys guests', function (): void {
    Http::fake(['*' => Http::response(TestCase::data(TestCase::UPID))]);

    $vm = Proxmox::vm(100, 'pve1');
    $vm->migrate('pve2', online: true, options: ['with-local-disks' => true]);
    $vm->resize('scsi0', '+10G');
    $vm->destroy(purge: true, destroyUnreferencedDisks: true);
    Proxmox::container(200, 'pve2')->migrate('pve1', online: true);

    Http::assertSent(fn(Request $request): bool => str_ends_with($request->url(), '/qemu/100/migrate') && $request->data() === ['target' => 'pve2', 'with-local-disks' => '1', 'online' => '1']);
    Http::assertSent(fn(Request $request): bool => $request->method() === 'PUT' && str_ends_with($request->url(), '/qemu/100/resize') && $request->data() === ['disk' => 'scsi0', 'size' => '+10G']);
    Http::assertSent(fn(Request $request): bool => $request->method() === 'DELETE' && $request->url() === TestCase::API . '/nodes/pve1/qemu/100?purge=1&destroy-unreferenced-disks=1');
    Http::assertSent(fn(Request $request): bool => str_ends_with($request->url(), '/lxc/200/migrate') && $request->data() === ['target' => 'pve1', 'restart' => '1']);
});

it('reads guest agent interfaces', function (): void {
    Http::fake(['*' => Http::response(TestCase::data(['result' => [['name' => 'eth0', 'ip-addresses' => [['ip-address' => '10.0.0.10', 'ip-address-type' => 'ipv4']]]]]))]);

    expect(Proxmox::vm(100, 'pve1')->interfaces()[0]['name'])->toBe('eth0');
    Http::assertSent(fn(Request $request): bool => str_ends_with($request->url(), '/qemu/100/agent/network-get-interfaces'));
});
