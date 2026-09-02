<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Compiler\Output;

use Atatusoft\Ppphp\Compiler\Output\Interfaces\BuildFilesystem;
use Atatusoft\Ppphp\Support\Path;

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

    public function readFileBounded(string $path, int $maximumBytes): string
    {
        if ($maximumBytes < 0 || !$this->checkIsFile($path)) {
            throw new \RuntimeException(sprintf('File "%s" is not a readable regular file.', $path));
        }

        $size = @filesize($path);

        if (!is_int($size) || $size > $maximumBytes) {
            throw new \RuntimeException(sprintf('File "%s" exceeds its size limit.', $path));
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw new \RuntimeException(sprintf('File "%s" could not be opened for reading.', $path));
        }

        try {
            $stat = fstat($handle);
            $contents = stream_get_contents($handle, $maximumBytes + 1);

            if (!is_array($stat)
                || ($stat['mode'] & 0170000) !== 0100000
                || !is_string($contents)
                || strlen($contents) > $maximumBytes) {
                throw new \RuntimeException(sprintf('File "%s" could not be read safely.', $path));
            }

            return $contents;
        } finally {
            fclose($handle);
        }
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

    public function writeFileAtomically(string $path, string $contents, ?int $mode = null): void
    {
        $this->createDirectory(dirname($path));

        if (is_link($path) || (file_exists($path) && !is_file($path))) {
            throw new \RuntimeException(sprintf('File "%s" cannot be replaced safely.', $path));
        }

        $temporary = Path::join(dirname($path), '.' . basename($path) . '.tmp-' . bin2hex(random_bytes(12)));

        try {
            $this->writeFile($temporary, $contents, $mode ?? 0600);

            if (!@rename($temporary, $path)) {
                throw new \RuntimeException(sprintf('File "%s" could not be replaced atomically.', $path));
            }
        } finally {
            if ($this->checkExists($temporary)) {
                try {
                    $this->remove($temporary);
                } catch (\Throwable) {
                }
            }
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
        if (is_link($path) || (file_exists($path) && !is_dir($path))) {
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
        return $this->resolveEntries($root, true);
    }

    public function listEntries(string $root): array
    {
        return $this->resolveEntries($root, false);
    }

    /** @return list<string> */
    private function resolveEntries(string $root, bool $requireRegularFiles): array
    {
        if (!$this->checkIsDirectory($root)) {
            return [];
        }

        $files = [];
        $this->collectEntries($root, $root, $files, $requireRegularFiles);
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
    private function collectEntries(
        string $root,
        string $directory,
        array &$files,
        bool $requireRegularFiles,
    ): void
    {
        foreach (new \DirectoryIterator($directory) as $entry) {
            if ($entry->isDot()) {
                continue;
            }

            if ($entry->isLink()) {
                if ($requireRegularFiles) {
                    throw new \RuntimeException('The output tree contains a symbolic link.');
                }

                $files[] = Path::resolveRelativeTo($entry->getPathname(), $root);
                continue;
            }

            if ($entry->isDir()) {
                $this->collectEntries($root, $entry->getPathname(), $files, $requireRegularFiles);
            } elseif ($entry->isFile()) {
                $files[] = Path::resolveRelativeTo($entry->getPathname(), $root);
            } else {
                if ($requireRegularFiles) {
                    throw new \RuntimeException('The output tree contains an unsupported filesystem entry.');
                }

                $files[] = Path::resolveRelativeTo($entry->getPathname(), $root);
            }
        }
    }
}
