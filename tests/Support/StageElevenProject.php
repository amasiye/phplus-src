<?php

declare(strict_types=1);

namespace Tests\Support;

use Atatusoft\Ppphp\Cli\Application;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Console\Tester\ApplicationTester;

final class StageElevenProject
{
    /** @param array<string, mixed> $input */
    public static function runCommand(array $input): ApplicationTester
    {
        $application = new Application();
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);
        $tester->run(['--no-ansi' => true, ...$input]);

        return $tester;
    }

    public static function copyTree(string $source, string $target): void
    {
        if (!is_dir($target) && !mkdir($target, 0777, true) && !is_dir($target)) {
            throw new RuntimeException('Unable to create the Stage 11 test project.');
        }

        foreach (new \DirectoryIterator($source) as $entry) {
            if ($entry->isDot() || in_array($entry->getFilename(), ['vendor', 'build', '.ppphp-cache'], true)) {
                continue;
            }

            $destination = $target . '/' . $entry->getFilename();

            if ($entry->isDir() && !$entry->isLink()) {
                self::copyTree($entry->getPathname(), $destination);
            } elseif (!copy($entry->getPathname(), $destination)) {
                throw new RuntimeException('Unable to copy the Stage 11 test project.');
            } else {
                chmod($destination, $entry->getPerms() & 0777);
            }
        }
    }

    /** @return array<string, string> */
    public static function captureTree(string $root): array
    {
        $files = [];

        if (!is_dir($root)) {
            return $files;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->isLink()) {
                continue;
            }

            $relative = ltrim(substr($file->getPathname(), strlen($root)), '/');
            $contents = file_get_contents($file->getPathname());
            $files[str_replace('\\', '/', $relative)] = $contents === false ? '' : $contents;
        }

        ksort($files, SORT_STRING);

        return $files;
    }
}
