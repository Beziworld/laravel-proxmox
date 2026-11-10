<?php

declare(strict_types=1);

use Beziworld\Proxmox\Data\ApiToken;
use Beziworld\Proxmox\Data\ClusterResource;
use Beziworld\Proxmox\Data\GuestStatus;
use Beziworld\Proxmox\Data\Storage;
use Beziworld\Proxmox\Data\TaskStatus;
use Beziworld\Proxmox\Data\Upid;
use Beziworld\Proxmox\Data\User;
use Beziworld\Proxmox\Exceptions\InvalidConfiguration;
use Beziworld\Proxmox\ProxmoxConfig;
use Beziworld\Proxmox\Tests\TestCase;

it('normalizes the cluster url', function (string $url, string $expected): void {
    expect(ProxmoxConfig::fromArray(['url' => $url, 'token_id' => 'a@pve!t', 'token_secret' => 's'])->url)->toBe($expected);
})->with([
    ['pve1.example.com', 'https://pve1.example.com:8006'],
    ['https://pve1.example.com:8006/', 'https://pve1.example.com:8006'],
    ['https://pve1.example.com/api2/json', 'https://pve1.example.com:8006'],
    ['https://proxy.example.com:443', 'https://proxy.example.com:443'],
]);

it('builds the api token header', function (): void {
    $config = ProxmoxConfig::fromArray(['url' => 'h', 'token_id' => 'automation@pve!laravel', 'token_secret' => 'uuid']);

    expect($config->tokenHeader())->toBe('PVEAPIToken=automation@pve!laravel=uuid')
        ->and($config->usesToken())->toBeTrue()
        ->and($config->apiUrl())->toBe('https://h:8006/api2/json');
});

it('validates credentials', function (array $config, string $message): void {
    expect(fn() => ProxmoxConfig::fromArray($config, 'dc1'))->toThrow(InvalidConfiguration::class, $message);
})->with([
    'no url' => [['token_id' => 'a@pve!t', 'token_secret' => 's'], '"url"'],
    'malformed token id' => [['url' => 'h', 'token_id' => 'laravel', 'token_secret' => 's'], 'user@realm!tokenid'],
    'token without secret' => [['url' => 'h', 'token_id' => 'a@pve!t'], '"token_secret"'],
    'nothing' => [['url' => 'h'], '"token_id"'],
    'username without password' => [['url' => 'h', 'username' => 'root@pam'], '"token_id"'],
]);

it('defaults the realm of ticket logins to pam', function (): void {
    expect(ProxmoxConfig::fromArray(['url' => 'h', 'username' => 'root', 'password' => 'p'])->username)->toBe('root@pam')
        ->and(ProxmoxConfig::fromArray(['url' => 'h', 'username' => 'ops@pve', 'password' => 'p'])->username)->toBe('ops@pve');
});

it('parses the tls verification setting', function (mixed $value, bool|string $expected): void {
    expect(ProxmoxConfig::fromArray(['url' => 'h', 'token_id' => 'a@pve!t', 'token_secret' => 's', 'verify_ssl' => $value])->verifySsl)->toBe($expected);
})->with([
    ['false', false],
    [true, true],
    ['/etc/pve/pve-root-ca.pem', '/etc/pve/pve-root-ca.pem'],
]);

it('parses task upids', function (): void {
    $upid = Upid::parse(TestCase::UPID);

    expect($upid->node)->toBe('pve1')
        ->and($upid->type)->toBe('qmstart')
        ->and($upid->id)->toBe('100')
        ->and($upid->user)->toBe('automation@pve!laravel')
        ->and($upid->pid)->toBe(0x000A1B2C)
        ->and($upid->startedAt->getTimestamp())->toBe(0x6730A1B2)
        ->and(Upid::isValid(TestCase::UPID))->toBeTrue()
        ->and(Upid::isValid('OK'))->toBeFalse()
        ->and(fn() => Upid::parse('UPID:broken'))->toThrow(InvalidArgumentException::class);
});

it('interprets task exit statuses', function (): void {
    expect(TaskStatus::fromArray(jsonFixture('task-ok')['data'])->succeeded())->toBeTrue()
        ->and(TaskStatus::fromArray(jsonFixture('task-running')['data'])->isRunning())->toBeTrue()
        ->and(TaskStatus::fromArray(jsonFixture('task-failed')['data'])->failed())->toBeTrue()
        ->and(TaskStatus::fromArray(['status' => 'stopped', 'exitstatus' => 'WARNINGS: 2'])->succeeded())->toBeTrue()
        ->and(TaskStatus::fromArray(['status' => 'OK', 'endtime' => 1794319200])->succeeded())->toBeTrue()
        ->and(TaskStatus::fromArray(['status' => 'unexpected status'])->failed())->toBeTrue();
});

it('maps cluster resources', function (): void {
    $resources = array_map(ClusterResource::fromArray(...), jsonFixture('cluster-resources')['data']);

    expect($resources[1]->isGuest())->toBeTrue()
        ->and($resources[1]->tags)->toBe(['prod', 'web'])
        ->and($resources[1]->isRunning())->toBeTrue()
        ->and($resources[3]->template)->toBeTrue()
        ->and($resources[4]->storage)->toBe('local-lvm')
        ->and($resources[0]->isGuest())->toBeFalse();
});

it('maps guest status, storages, tokens and users', function (): void {
    $status = GuestStatus::fromArray(jsonFixture('qemu-status')['data']);
    $storage = Storage::fromArray(jsonFixture('storage')['data'][0]);
    $token = ApiToken::fromArray(['full-tokenid' => 'ops@pve!ci', 'info' => ['privsep' => 0, 'expire' => 0], 'value' => 'secret-uuid']);
    $user = User::fromArray(['userid' => 'ops@pve', 'enable' => 1, 'groups' => ['admins', 'ops'], 'expire' => 0]);

    expect($status->isRunning())->toBeTrue()
        ->and($status->isPaused())->toBeFalse()
        ->and($status->maxMemory)->toBe(4294967296)
        ->and($storage->content)->toBe(['iso', 'vztmpl', 'backup'])
        ->and($storage->supports('backup'))->toBeTrue()
        ->and($storage->usage())->toBe(0.25)
        ->and($token->authorizationHeader())->toBe('PVEAPIToken=ops@pve!ci=secret-uuid')
        ->and($token->privilegeSeparation)->toBeFalse()
        ->and($token->expiresAt)->toBeNull()
        ->and($user->groups)->toBe(['admins', 'ops'])
        ->and($user->realm())->toBe('pve');
});
