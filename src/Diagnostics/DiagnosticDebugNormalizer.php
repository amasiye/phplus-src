<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Diagnostics;

final class DiagnosticDebugNormalizer
{
    /**
     * @param array<string, mixed> $debug
     * @return array<string, mixed>
     */
    public function normalize(array $debug): array
    {
        $normalized = [];

        foreach ($debug as $key => $value) {
            $normalized[$key] = $this->normalizeValue($value, 1);
        }

        return $normalized;
    }

    private function normalizeValue(mixed $value, int $depth): mixed
    {
        if ($depth >= 8) {
            return '[maximum depth reached]';
        }

        if ($value === null || is_bool($value) || is_int($value) || is_float($value) || is_string($value)) {
            return $value;
        }

        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if ($value instanceof \UnitEnum) {
            return $value->name;
        }

        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        if (is_array($value)) {
            $normalized = [];

            foreach (array_slice($value, 0, 100, true) as $key => $item) {
                $normalized[is_int($key) ? $key : (string) $key] = $this->normalizeValue($item, $depth + 1);
            }

            if (count($value) > 100) {
                $normalized['__truncated__'] = count($value) - 100;
            }

            return $normalized;
        }

        if (is_object($value)) {
            return sprintf('[object %s]', $value::class);
        }

        if (is_resource($value)) {
            return sprintf('[resource %s]', get_resource_type($value));
        }

        return sprintf('[%s]', get_debug_type($value));
    }
}
