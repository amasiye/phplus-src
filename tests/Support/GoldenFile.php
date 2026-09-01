<?php

declare(strict_types=1);

namespace Tests\Support;

final class GoldenFile
{
    public static function assertMatches(string $path, string $actual): void
    {
        if (getenv('UPDATE_GOLDENS') === '1') {
            if (getenv('CI') !== false && getenv('CI') !== '') {
                throw new \RuntimeException('Golden files cannot be updated in CI.');
            }

            if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0o755, true) && !is_dir(dirname($path))) {
                throw new \RuntimeException(sprintf('Could not create golden directory "%s".', dirname($path)));
            }

            if (file_put_contents($path, $actual) === false) {
                throw new \RuntimeException(sprintf('Could not update golden file "%s".', $path));
            }
        }

        if (!is_file($path)) {
            throw new \RuntimeException(sprintf('Golden file "%s" does not exist. Run with UPDATE_GOLDENS=1.', $path));
        }

        expect(file_get_contents($path))->toBe($actual);
    }
}
