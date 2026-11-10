<?php

declare(strict_types=1);

namespace Beziworld\Proxmox\Resources;

use Beziworld\Proxmox\Data\ApiToken;
use Beziworld\Proxmox\Data\User;
use Beziworld\Proxmox\Http\ProxmoxApi;
use Beziworld\Proxmox\Support\Values;
use DateTimeInterface;

/**
 * Access control: users, API tokens, permissions, roles, groups and ACLs.
 */
final class Access
{
    public function __construct(
        private readonly ProxmoxApi $api,
    ) {}

    /**
     * @return list<User>
     */
    public function users(): array
    {
        return array_map(User::fromArray(...), Values::rows($this->api->get('/access/users')));
    }

    public function user(string $userId): User
    {
        $data = $this->api->get('/access/users/' . rawurlencode($userId));

        return User::fromArray((is_array($data) ? $data : []) + ['userid' => $userId]);
    }

    /**
     * @return list<ApiToken>
     */
    public function tokens(string $userId): array
    {
        return array_map(
            static fn(array $row): ApiToken => ApiToken::fromArray($row, $userId . '!' . Values::string($row, 'tokenid')),
            Values::rows($this->api->get('/access/users/' . rawurlencode($userId) . '/token')),
        );
    }

    /**
     * Creates an API token; the returned secret is shown only once and must be stored.
     *
     * @param bool $privilegeSeparation Restrict the token to permissions granted to the token itself.
     */
    public function createToken(string $userId, string $tokenId, ?string $comment = null, ?DateTimeInterface $expiresAt = null, bool $privilegeSeparation = true): ApiToken
    {
        $data = $this->api->post($this->tokenPath($userId, $tokenId), [
            'comment' => $comment,
            'expire' => $expiresAt?->getTimestamp(),
            'privsep' => $privilegeSeparation,
        ]);

        return ApiToken::fromArray(is_array($data) ? $data : [], $userId . '!' . $tokenId);
    }

    public function deleteToken(string $userId, string $tokenId): void
    {
        $this->api->delete($this->tokenPath($userId, $tokenId));
    }

    /**
     * Effective permissions, keyed by path, of the current credentials or of the given user or token.
     *
     * @return array<string, array<string, int>>
     */
    public function permissions(?string $path = null, ?string $userId = null): array
    {
        $data = $this->api->get('/access/permissions', ['path' => $path, 'userid' => $userId]);
        $result = [];

        foreach (is_array($data) ? $data : [] as $aclPath => $privileges) {
            if (! is_array($privileges)) {
                continue;
            }

            foreach ($privileges as $privilege => $propagate) {
                $result[(string) $aclPath][(string) $privilege] = is_numeric($propagate) ? (int) $propagate : 0;
            }
        }

        return $result;
    }

    /**
     * Whether the credentials hold a privilege on a path, e.g. can('/vms/100', 'VM.PowerMgmt').
     */
    public function can(string $path, string $privilege): bool
    {
        return isset($this->permissions($path)[$path][$privilege]);
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    public function roles(): array
    {
        return Values::rows($this->api->get('/access/roles'));
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    public function groups(): array
    {
        return Values::rows($this->api->get('/access/groups'));
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    public function acl(): array
    {
        return Values::rows($this->api->get('/access/acl'));
    }

    /**
     * Grants (or with $delete revokes) roles on a path.
     *
     * @param list<string> $roles e.g. ["PVEVMUser"]
     * @param list<string> $users
     * @param list<string> $groups
     * @param list<string> $tokens Full token ids (user@realm!tokenid).
     */
    public function updateAcl(string $path, array $roles, array $users = [], array $groups = [], array $tokens = [], bool $propagate = true, bool $delete = false): void
    {
        $this->api->put('/access/acl', [
            'path' => $path,
            'roles' => implode(',', $roles),
            'users' => $users === [] ? null : implode(',', $users),
            'groups' => $groups === [] ? null : implode(',', $groups),
            'tokens' => $tokens === [] ? null : implode(',', $tokens),
            'propagate' => $propagate,
            'delete' => $delete ? true : null,
        ]);
    }

    private function tokenPath(string $userId, string $tokenId): string
    {
        return '/access/users/' . rawurlencode($userId) . '/token/' . rawurlencode($tokenId);
    }
}
