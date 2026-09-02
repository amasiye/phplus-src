<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Support;

final class Utf8
{
    public static function sanitize(string $value): string
    {
        if (preg_match('//u', $value) === 1) {
            return $value;
        }

        $result = '';
        $length = strlen($value);

        for ($offset = 0; $offset < $length;) {
            $first = ord($value[$offset]);

            if ($first <= 0x7f) {
                $result .= $value[$offset++];
                continue;
            }

            $width = match (true) {
                $first >= 0xc2 && $first <= 0xdf => 2,
                $first >= 0xe0 && $first <= 0xef => 3,
                $first >= 0xf0 && $first <= 0xf4 => 4,
                default => 0,
            };

            if ($width > 0 && $offset + $width <= $length) {
                $sequence = substr($value, $offset, $width);

                if (preg_match('//u', $sequence) === 1) {
                    $result .= $sequence;
                    $offset += $width;
                    continue;
                }
            }

            $result .= "\xef\xbf\xbd";
            $offset++;
        }

        return $result;
    }
}
