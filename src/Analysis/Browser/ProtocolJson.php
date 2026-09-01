<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Analysis\Browser;

final class ProtocolJson
{
    /** @param array<string, mixed> $value */
    public static function encodeCanonical(array $value): string
    {
        return json_encode(
            self::sort($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    public static function hash(string $contents): string
    {
        return 'sha256:' . hash('sha256', $contents);
    }

    /**
     * @param array<mixed> $value
     * @return array<mixed>
     */
    private static function sort(array $value): array
    {
        if (array_is_list($value)) {
            return array_map(
                static fn (mixed $item): mixed => is_array($item) ? self::sort($item) : $item,
                $value,
            );
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sort($item);
            }
        }

        return $value;
    }
}
