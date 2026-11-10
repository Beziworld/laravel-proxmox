<?php

declare(strict_types=1);

namespace Beziworld\Proxmox\Exceptions;

/**
 * Raised when Proxmox rejects the API token, the ticket or the login (HTTP 401).
 *
 * A rejected login is never repeated automatically.
 */
final class AuthenticationException extends ApiException {}
