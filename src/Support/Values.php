<?php

declare(strict_types=1);

namespace Beziworld\Proxmox\Support;

use Carbon\CarbonImmutable;

/**
 * Conversions of loosely typed API values into strict PHP types.
 *
 * @internal
 */
final class Values
{
    /**
     * @param array<array-key, mixed> $data
     */
    public static function string(array $data, string $key, string $default = ''): string
    {
        $value = $data[$key] ?? null;

        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function nullableString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function int(array $data, string $key, int $default = 0): int
    {
        $value = $data[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function nullableInt(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function float(array $data, string $key): float
    {
        $value = $data[$key] ?? null;

        return is_numeric($value) ? (float) $value : 0.0;
    }

    /**
     * Interprets the 1/0 integers used by Proxmox for booleans.
     *
     * @param array<array-key, mixed> $data
     */
    public static function bool(array $data, string $key): bool
    {
        $value = $data[$key] ?? null;

        if (is_bool($value)) {
            return $value;
        }

        return is_scalar($value) && in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Splits the semicolon or comma separated tag list of a guest.
     *
     * @param array<array-key, mixed> $data
     * @return list<string>
     */
    public static function tags(array $data, string $key = 'tags'): array
    {
        $value = self::string($data, $key);

        $parts = preg_split('/[;,\s]+/', $value);

        if ($value === '' || $parts === false) {
            return [];
        }

        return array_values(array_filter(array_map(trim(...), $parts), static fn(string $tag): bool => $tag !== ''));
    }

    /**
     * Parses a Unix timestamp (seconds) into UTC.
     *
     * @param array<array-key, mixed> $data
     */
    public static function timestamp(array $data, string $key): ?CarbonImmutable
    {
        $value = $data[$key] ?? null;

        return is_numeric($value) && (int) $value > 0 ? CarbonImmutable::createFromTimestampUTC((int) $value) : null;
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    public static function array(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        return is_array($value) ? $value : [];
    }

    /**
     * Returns the list items of a value that are arrays.
     *
     * @return list<array<array-key, mixed>>
     */
    public static function rows(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, is_array(...)));
    }
}
