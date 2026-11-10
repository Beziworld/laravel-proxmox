<?php

declare(strict_types=1);

namespace Beziworld\Proxmox\Exceptions;

/**
 * Raised when the user or API token lacks a privilege for the request (HTTP 403).
 *
 * API tokens with privilege separation have only the permissions granted
 * to the token itself, not those of the owning user.
 */
final class PermissionDenied extends ApiException {}
