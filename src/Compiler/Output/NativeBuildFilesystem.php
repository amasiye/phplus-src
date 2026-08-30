<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Compiler\Output;

use Amasiye\Ppphp\Compiler\Output\Interfaces\BuildFilesystem;
use Amasiye\Ppphp\Support\Path;

class NativeBuildFilesystem implements BuildFilesystem
{
    public function checkExists(string $path): bool
    {
        return file_exists($path) || is_link($path);
    }

    public function checkIsFile(string $path): bool
    {
        return is_file($path) && !is_link($path);
    }

    public function checkIsDirectory(string $path): bool
    {
        return is_dir($path) && !is_link($path);
    }

    public function checkIsLink(string $path): bool
    {
        return is_link($path);
    }

    public function createDirectory(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            return;
        }

        if ($this->checkExists($path) || (!@mkdir($path, 0777, true) && !is_dir($path))) {
            throw new \RuntimeException(sprintf('Directory "%s" could not be created.', $path));
        }
    }

    public function readFile(string $path): string
    {
        if (!$this->checkIsFile($path)) {
            throw new \RuntimeException(sprintf('File "%s" is not a readable regular file.', $path));
        }

        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new \RuntimeException(sprintf('File "%s" could not be read.', $path));
        }

        return $contents;
    }

    public function writeFile(string $path, string $contents, ?int $mode = null): void
    {
        $this->createDirectory(dirname($path));

        if (is_link($path)) {
            throw new \RuntimeException(sprintf('File "%s" cannot be written through a symbolic link.', $path));
        }

        $handle = @fopen($path, 'wb');

        if ($handle === false) {
            throw new \RuntimeException(sprintf('File "%s" could not be opened for writing.', $path));
        }

        try {
            $length = strlen($contents);
            $written = 0;

            while ($written < $length) {
                $bytes = fwrite($handle, substr($contents, $written));

                if ($bytes === false || $bytes === 0) {
                    throw new \RuntimeException(sprintf('File "%s" could not be written completely.', $path));
                }

                $written += $bytes;
            }

            if (!fflush($handle)) {
                throw new \RuntimeException(sprintf('File "%s" could not be flushed.', $path));
            }

            if (function_exists('fsync') && !fsync($handle)) {
                throw new \RuntimeException(sprintf('File "%s" could not be synchronized.', $path));
            }
        } finally {
            fclose($handle);
        }

        if ($mode !== null && !@chmod($path, $mode & 0777)) {
            throw new \RuntimeException(sprintf('File mode for "%s" could not be preserved.', $path));
        }
    }

    public function move(string $from, string $to): void
    {
        if (!@rename($from, $to)) {
            throw new \RuntimeException(sprintf('Path "%s" could not be renamed to "%s".', $from, $to));
        }
    }

    public function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            if (!@unlink($path)) {
                throw new \RuntimeException(sprintf('Path "%s" could not be unlinked.', $path));
            }

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        foreach (new \DirectoryIterator($path) as $entry) {
            if (!$entry->isDot()) {
                $this->remove($entry->getPathname());
            }
        }

        if (!@rmdir($path)) {
            throw new \RuntimeException(sprintf('Directory "%s" could not be removed.', $path));
        }
    }

    public function cloneTree(string $from, string $to): void
    {
        if ($this->checkIsLink($from) || !$this->checkIsDirectory($from)) {
            throw new \RuntimeException('The current output tree is not a safe regular directory.');
        }

        $this->createDirectory($to);
        $mode = @fileperms($from);

        if ($mode !== false && !@chmod($to, $mode & 0777)) {
            throw new \RuntimeException('An output directory mode could not be preserved.');
        }

        foreach (new \DirectoryIterator($from) as $entry) {
            if ($entry->isDot()) {
                continue;
            }

            if ($entry->isLink()) {
                throw new \RuntimeException('The current output tree contains a symbolic link.');
            }

            $source = $entry->getPathname();
            $target = Path::join($to, $entry->getFilename());

            if ($entry->isDir()) {
                $this->cloneTree($source, $target);
                continue;
            }

            if (!$entry->isFile()) {
                throw new \RuntimeException('The current output tree contains an unsupported filesystem entry.');
            }

            $fileMode = $entry->getPerms() & 0777;
            $this->writeFile($target, $this->readFile($source), $fileMode);
        }
    }

    public function listFiles(string $root): array
    {
        if (!$this->checkIsDirectory($root)) {
            return [];
        }

        $files = [];
        $this->collectFiles($root, $root, $files);
        sort($files, SORT_STRING);

        return $files;
    }

    public function pruneEmptyDirectories(string $root): void
    {
        if (!$this->checkIsDirectory($root)) {
            return;
        }

        foreach (new \DirectoryIterator($root) as $entry) {
            if ($entry->isDot() || !$entry->isDir() || $entry->isLink()) {
                continue;
            }

            $this->pruneEmptyDirectories($entry->getPathname());
        }

        $iterator = new \FilesystemIterator($root, \FilesystemIterator::SKIP_DOTS);

        if (!$iterator->valid()) {
            @rmdir($root);
        }
    }

    /** @param list<string> $files */
    private function collectFiles(string $root, string $directory, array &$files): void
    {
        foreach (new \DirectoryIterator($directory) as $entry) {
            if ($entry->isDot()) {
                continue;
            }

            if ($entry->isLink()) {
                throw new \RuntimeException('The output tree contains a symbolic link.');
            }

            if ($entry->isDir()) {
                $this->collectFiles($root, $entry->getPathname(), $files);
            } elseif ($entry->isFile()) {
                $files[] = Path::resolveRelativeTo($entry->getPathname(), $root);
            } else {
                throw new \RuntimeException('The output tree contains an unsupported filesystem entry.');
            }
        }
    }
}
