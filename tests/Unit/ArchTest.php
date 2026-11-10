<?php

declare(strict_types=1);

arch('every source file declares strict types')
    ->expect('Beziworld\Proxmox')
    ->toUseStrictTypes();

arch('no debugging statements are left behind')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'die'])
    ->not->toBeUsed();

arch('data objects are immutable')
    ->expect('Beziworld\Proxmox\Data')
    ->toBeReadonly();

arch('exceptions share the package base exception')
    ->expect('Beziworld\Proxmox\Exceptions')
    ->toExtend('Beziworld\Proxmox\Exceptions\ProxmoxException')
    ->ignoring('Beziworld\Proxmox\Exceptions\ProxmoxException');
