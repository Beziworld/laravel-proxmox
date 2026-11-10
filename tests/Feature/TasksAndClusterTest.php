<?php

declare(strict_types=1);

use Beziworld\Proxmox\Data\TaskStatus;
use Beziworld\Proxmox\Exceptions\TaskFailed;
use Beziworld\Proxmox\Exceptions\TaskTimeout;
use Beziworld\Proxmox\Facades\Proxmox;
use Beziworld\Proxmox\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

$statusUrl = TestCase::API . '/nodes/pve1/tasks/' . rawurlencode(TestCase::UPID) . '/status';

it('waits for a task until it finishes', function () use ($statusUrl): void {
    Http::fake([
        TestCase::API . '/nodes/pve1/qemu/100/status/start' => Http::response(TestCase::data(TestCase::UPID)),
        $statusUrl => Http::sequence()->push(jsonFixture('task-running'))->push(jsonFixture('task-running'))->push(jsonFixture('task-ok')),
    ]);

    $polls = 0;
    $status = Proxmox::vm(100, 'pve1')->start()->wait(timeout: 60, interval: 5, onPoll: function (TaskStatus $status) use (&$polls): void {
        $polls++;
    });

    expect($status->succeeded())->toBeTrue()
        ->and($status->exitStatus)->toBe('OK')
        ->and($polls)->toBe(3);
    Sleep::assertSleptTimes(2);
    Sleep::assertSequence([Sleep::for(5)->seconds(), Sleep::for(5)->seconds()]);
});

it('throws with the log tail when a task fails', function () use ($statusUrl): void {
    Http::fake([
        $statusUrl => Http::response(jsonFixture('task-failed')),
        TestCase::API . '/nodes/pve1/tasks/' . rawurlencode(TestCase::UPID) . '/log*' => Http::response(jsonFixture('task-log')),
    ]);

    try {
        Proxmox::task(TestCase::UPID)->wait();
        test()->fail('Expected exception was not thrown.');
    } catch (TaskFailed $exception) {
        expect($exception->getMessage())->toContain('start failed: QEMU exited with code 1')
            ->and($exception->getMessage())->toContain('No such file or directory')
            ->and($exception->status()?->type)->toBe('qmstart');
    }
});

it('returns the failed status when throwing is disabled', function () use ($statusUrl): void {
    Http::fake([$statusUrl => Http::response(jsonFixture('task-failed'))]);

    expect(Proxmox::tasks()->wait(TestCase::UPID, throw: false)->failed())->toBeTrue();
});

it('times out while a task keeps running', function () use ($statusUrl): void {
    Http::fake([$statusUrl => Http::response(jsonFixture('task-running'))]);

    expect(fn() => Proxmox::task(TestCase::UPID)->wait(timeout: 6, interval: 2))->toThrow(TaskTimeout::class, 'after 6 seconds');
    Sleep::assertSleptTimes(3);
});

it('reads task logs, lists and stops tasks', function (): void {
    Http::fake([
        '*/log*' => Http::response(jsonFixture('task-log')),
        TestCase::API . '/nodes/pve1/tasks?*' => Http::response(TestCase::data([['upid' => TestCase::UPID, 'type' => 'vzdump', 'status' => 'OK', 'starttime' => 1794319200, 'endtime' => 1794319500, 'node' => 'pve1', 'user' => 'root@pam']])),
        TestCase::API . '/nodes/pve1/tasks/*' => Http::response(TestCase::data(null)),
    ]);

    $log = Proxmox::task(TestCase::UPID)->log();
    $tasks = Proxmox::tasks()->forNode('pve1', ['vmid' => 100, 'typefilter' => 'vzdump', 'errors' => false]);
    Proxmox::task(TestCase::UPID)->stop();

    expect($log[1])->toBe('TASK ERROR: start failed: QEMU exited with code 1')
        ->and($tasks[0]->succeeded())->toBeTrue()
        ->and($tasks[0]->endedAt?->getTimestamp())->toBe(1794319500);
    Http::assertSent(fn(Request $request): bool => $request->url() === TestCase::API . '/nodes/pve1/tasks?vmid=100&typefilter=vzdump&errors=0');
    Http::assertSent(fn(Request $request): bool => $request->method() === 'DELETE' && $request->url() === TestCase::API . '/nodes/pve1/tasks/' . rawurlencode(TestCase::UPID));
});

it('lists cluster resources, guests and the next id', function (): void {
    Http::fake([
        TestCase::API . '/cluster/resources*' => Http::response(jsonFixture('cluster-resources')),
        TestCase::API . '/cluster/nextid' => Http::response(TestCase::data('106')),
    ]);

    expect(Proxmox::cluster()->resources())->toHaveCount(5)
        ->and(Proxmox::cluster()->guests())->toHaveCount(3)
        ->and(Proxmox::cluster()->findGuest(200)?->node)->toBe('pve2')
        ->and(Proxmox::cluster()->nextId())->toBe(106)
        ->and(fn() => Proxmox::cluster()->resources('qemu'))->toThrow(InvalidArgumentException::class);
});

it('checks whether a vmid is free', function (): void {
    Http::fake([
        TestCase::API . '/cluster/nextid?vmid=100' => Http::response(['data' => null, 'errors' => ['vmid' => 'VM 100 already exists']], 400),
        TestCase::API . '/cluster/nextid?vmid=150' => Http::response(TestCase::data('150')),
    ]);

    expect(Proxmox::cluster()->isIdFree(100))->toBeFalse()->and(Proxmox::cluster()->isIdFree(150))->toBeTrue();
});

it('lists nodes and storages', function (): void {
    Http::fake([
        TestCase::API . '/nodes' => Http::response(jsonFixture('nodes')),
        TestCase::API . '/nodes/pve1/storage*' => Http::response(jsonFixture('storage')),
    ]);

    expect(Proxmox::nodes()->online())->toHaveCount(1)
        ->and(Proxmox::nodes()->list()[0]->memoryUsage())->toBe(0.25)
        ->and(Proxmox::storage()->list('backup')[1]->shared)->toBeTrue();
    Http::assertSent(fn(Request $request): bool => $request->url() === TestCase::API . '/nodes/pve1/storage?content=backup');
});

it('deletes storage volumes', function (): void {
    Http::fake(['*' => Http::response(TestCase::data(TestCase::UPID))]);

    $task = Proxmox::onNode('pve2')->storage()->deleteVolume('pbs', 'pbs:backup/vm/100/2026-11-08T22:00:03Z');

    expect($task)->not->toBeNull();
    Http::assertSent(fn(Request $request): bool => $request->method() === 'DELETE'
        && $request->url() === TestCase::API . '/nodes/pve2/storage/pbs/content/' . rawurlencode('pbs:backup/vm/100/2026-11-08T22:00:03Z'));
});

it('creates api tokens and manages acls', function (): void {
    Http::fake([
        TestCase::API . '/access/users/ci%40pve/token/deploy' => Http::response(TestCase::data(['full-tokenid' => 'ci@pve!deploy', 'info' => ['privsep' => 1, 'expire' => 1825855200, 'comment' => 'CI'], 'value' => '0f6b5d7e-uuid'])),
        TestCase::API . '/access/acl' => Http::response(TestCase::data(null)),
        TestCase::API . '/access/permissions*' => Http::response(TestCase::data(['/vms/100' => ['VM.PowerMgmt' => 1, 'VM.Audit' => 1]])),
    ]);

    $token = Proxmox::access()->createToken('ci@pve', 'deploy', 'CI', now()->setTimestamp(1825855200));
    Proxmox::access()->updateAcl('/vms/100', ['PVEVMUser'], tokens: ['ci@pve!deploy']);

    expect($token->authorizationHeader())->toBe('PVEAPIToken=ci@pve!deploy=0f6b5d7e-uuid')
        ->and($token->expiresAt?->getTimestamp())->toBe(1825855200)
        ->and(Proxmox::access()->can('/vms/100', 'VM.PowerMgmt'))->toBeTrue()
        ->and(Proxmox::access()->can('/vms/100', 'VM.Allocate'))->toBeFalse();

    Http::assertSent(fn(Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/token/deploy')
        && $request->data() === ['comment' => 'CI', 'expire' => '1825855200', 'privsep' => '1']);
    Http::assertSent(fn(Request $request): bool => $request->method() === 'PUT' && str_ends_with($request->url(), '/access/acl')
        && $request->data() === ['path' => '/vms/100', 'roles' => 'PVEVMUser', 'tokens' => 'ci@pve!deploy', 'propagate' => '1']);
});

it('lists users', function (): void {
    Http::fake(['*' => Http::response(TestCase::data([['userid' => 'root@pam', 'enable' => 1, 'expire' => 0], ['userid' => 'ops@pve', 'enable' => 0, 'groups' => 'admins,ops']]))]);

    $users = Proxmox::access()->users();

    expect($users[0]->enabled)->toBeTrue()->and($users[1]->enabled)->toBeFalse()->and($users[1]->groups)->toBe(['admins', 'ops']);
});

it('checks a connection from the console', function (): void {
    Http::fake([
        TestCase::API . '/version' => Http::response(TestCase::data(['version' => '9.0.11', 'release' => '9.0'])),
        TestCase::API . '/nodes' => Http::response(jsonFixture('nodes')),
    ]);

    $this->artisan('proxmox:check')
        ->expectsOutputToContain('Proxmox VE 9.0.11')
        ->expectsOutputToContain('pve1')
        ->assertSuccessful();
});

it('waits for a task from the console', function () use ($statusUrl): void {
    Http::fake([$statusUrl => Http::response(jsonFixture('task-failed'))]);

    $this->artisan('proxmox:task', ['upid' => TestCase::UPID])->expectsOutputToContain('QEMU exited with code 1')->assertFailed();
    $this->artisan('proxmox:task', ['upid' => 'not-a-upid'])->assertExitCode(2);
});
