<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function createTemporaryDirectory(): string
    {
        $path = sys_get_temp_dir() . '/ppphp-test-' . bin2hex(random_bytes(8));

        if (!mkdir($path, 0777, true) && !is_dir($path)) {
            throw new \RuntimeException(sprintf('Unable to create temporary directory "%s".', $path));
        }

        $this->temporaryDirectories[] = $path;

        return $path;
    }

    protected function createDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0777, true) && !is_dir($path)) {
            throw new \RuntimeException(sprintf('Unable to create directory "%s".', $path));
        }
    }

    protected function writeFile(string $path, string $contents): void
    {
        $this->createDirectory(dirname($path));

        if (file_put_contents($path, $contents) === false) {
            throw new \RuntimeException(sprintf('Unable to write file "%s".', $path));
        }
    }

    /** @param array<string, mixed> $overrides */
    protected function writeConfiguration(string $projectRoot, array $overrides = []): string
    {
        $configuration = array_replace([
            'source' => ['src'],
            'output' => 'build/ppphp',
            'cache' => '.ppphp-cache',
            'targetPhpVersion' => '8.4',
            'stubs' => ['stubs'],
            'exclude' => ['vendor', 'build', '.ppphp-cache'],
        ], $overrides);
        $path = $projectRoot . '/ppphp.json';

        if (!array_key_exists('stubs', $overrides)) {
            $this->createDirectory($projectRoot . '/stubs');
        }

        $this->writeFile(
            $path,
            json_encode($configuration, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        );

        return $path;
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->temporaryDirectories) as $path) {
            $this->removePath($path);
        }

        parent::tearDown();
    }

    private function removePath(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        foreach (new \DirectoryIterator($path) as $entry) {
            if (!$entry->isDot()) {
                $this->removePath($entry->getPathname());
            }
        }

        rmdir($path);
    }
}
