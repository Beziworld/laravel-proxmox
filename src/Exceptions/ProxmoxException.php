<?php

declare(strict_types=1);

namespace Beziworld\Proxmox\Exceptions;

use RuntimeException;

/**
 * Base exception for every error raised by the Proxmox VE integration.
 */
class ProxmoxException extends RuntimeException {}
