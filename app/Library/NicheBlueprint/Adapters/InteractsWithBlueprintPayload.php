<?php

namespace App\Library\NicheBlueprint\Adapters;

use InvalidArgumentException;

/**
 * Shared payload parsing for the V2 adapters. Every failure is an
 * InvalidArgumentException with an operator-readable message: the publisher
 * wraps it as InvalidComponentDescriptorException, the Workspace shows it.
 */
trait InteractsWithBlueprintPayload
{
    /** @param array<string, mixed> $payload */
    protected function requireString(array $payload, string $key, int $max = 191): string
    {
        $value = $payload[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("\"{$key}\" is required.");
        }

        $value = trim($value);

        if (mb_strlen($value) > $max) {
            throw new InvalidArgumentException("\"{$key}\" must be at most {$max} characters.");
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    protected function optionalString(array $payload, string $key, int $max = 5000): ?string
    {
        $value = $payload[$key] ?? null;

        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        if (! is_string($value) || mb_strlen(trim($value)) > $max) {
            throw new InvalidArgumentException("\"{$key}\" must be text of at most {$max} characters.");
        }

        return trim($value);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    protected function stringList(array $payload, string $key, int $maxItems = 100, int $maxLength = 191, bool $required = true): array
    {
        $value = $payload[$key] ?? [];

        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException("\"{$key}\" must be a list.");
        }

        $out = [];

        foreach ($value as $item) {
            if (! is_string($item) || trim($item) === '' || mb_strlen(trim($item)) > $maxLength) {
                throw new InvalidArgumentException("Every entry in \"{$key}\" must be text of 1-{$maxLength} characters.");
            }

            $out[] = trim($item);
        }

        if ($required && $out === []) {
            throw new InvalidArgumentException("\"{$key}\" needs at least one entry.");
        }

        if (count($out) > $maxItems) {
            throw new InvalidArgumentException("\"{$key}\" may hold at most {$maxItems} entries.");
        }

        return $out;
    }

    /** @return list<string> non-empty trimmed lines */
    protected function linesOf(mixed $text): array
    {
        if (is_array($text)) {
            $text = implode("\n", array_map('strval', $text));
        }

        $lines = preg_split('/\r\n|\r|\n/', (string) $text) ?: [];

        return array_values(array_filter(array_map('trim', $lines), static fn (string $l): bool => $l !== ''));
    }

    /**
     * Splits "a | b | c" into at most $parts trimmed segments (the last keeps
     * any further pipes), padded with null.
     *
     * @return list<?string>
     */
    protected function pipeParts(string $line, int $parts): array
    {
        $segments = array_map('trim', explode('|', $line, $parts));

        return array_pad($segments, $parts, null);
    }

    protected function intInRange(mixed $value, string $key, int $min, int $max, ?int $default = null): ?int
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (! is_numeric($value) || (int) $value != $value || (int) $value < $min || (int) $value > $max) {
            throw new InvalidArgumentException("\"{$key}\" must be a whole number from {$min} to {$max}.");
        }

        return (int) $value;
    }
}
