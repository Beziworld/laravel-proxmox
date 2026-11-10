<?php

declare(strict_types=1);

use Beziworld\Proxmox\Tests\TestCase;

uses(TestCase::class)->in('Unit', 'Feature');

/**
 * Decodes a JSON fixture from tests/Fixtures.
 *
 * @return array<string, mixed>
 */
function jsonFixture(string $name): array
{
    $decoded = json_decode((string) file_get_contents(__DIR__ . '/Fixtures/' . $name . '.json'), true, 512, JSON_THROW_ON_ERROR);

    return is_array($decoded) ? $decoded : [];
}
