<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Process;

final readonly class ProcessPolicy
{
    public const int FORMAT_VERSION = 1;
    public const int MAXIMUM_STDOUT_BYTES = 16_777_216;
    public const int MAXIMUM_STDERR_BYTES = 1_048_576;

    /** @return array<string, string|false> */
    public function environment(): array
    {
        $current = getenv();
        $environment = [];

        foreach ([...array_keys($current), ...array_keys($_SERVER), ...array_keys($_ENV)] as $name) {
            if (is_string($name) && $name !== '' && !str_contains($name, '=')) {
                $environment[$name] = false;
            }
        }

        foreach (['PATH', 'SystemRoot', 'WINDIR', 'TEMP', 'TMP', 'TMPDIR', 'LANG', 'LC_ALL', 'LC_CTYPE'] as $name) {
            $value = getenv($name);

            if (is_string($value) && $value !== '') {
                $environment[$name] = $value;
            }
        }

        ksort($environment, SORT_STRING);

        return $environment;
    }
}
