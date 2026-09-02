<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Cache;

use Amasiye\Ppphp\Support\CanonicalJson;
use Amasiye\Ppphp\Support\Path;

final readonly class CacheStore
{
    public function __construct(
        private string $projectRoot,
        private string $cacheRoot,
        private CacheStatistics $statistics,
        private CacheLimits $limits = new CacheLimits(),
    ) {
        if (!Path::contains($projectRoot, $cacheRoot)
            || Path::buildComparisonKey($projectRoot) === Path::buildComparisonKey($cacheRoot)) {
            throw new \InvalidArgumentException('The compiler cache root must be project-contained.');
        }
    }

    /** @return array<string, mixed>|null */
    public function readRecord(string $domain, string $kind, CacheKey $key): ?array
    {
        $this->statistics->readAttempts++;
        $path = $this->recordPath($domain, $kind, $key);

        if (!is_file($path) || is_link($path)) {
            $this->statistics->misses++;

            return null;
        }

        try {
            $contents = $this->readBounded($path, $this->limits->maximumRecordBytes);
            $data = json_decode($contents, true, $this->limits->maximumJsonDepth, JSON_THROW_ON_ERROR);

            if (!is_array($data)
                || array_is_list($data)
                || array_keys($data) !== ['cacheKey', 'formatVersion', 'payload', 'recordKind']
                || ($data['formatVersion'] ?? null) !== CacheFormat::COMPILER
                || ($data['recordKind'] ?? null) !== $kind
                || ($data['cacheKey'] ?? null) !== $key->value
                || !is_array($data['payload'] ?? null)
                || CanonicalJson::encode($data) !== $contents) {
                throw new \RuntimeException('The cache operation record is inconsistent.');
            }

            $this->statistics->hits++;
            $this->statistics->bytesRead += strlen($contents);

            $payload = [];

            foreach ($data['payload'] as $name => $value) {
                if (!is_string($name)) {
                    throw new \RuntimeException('A cache payload property is invalid.');
                }

                $payload[$name] = $value;
            }

            return $payload;
        } catch (\Throwable) {
            $this->statistics->corruptEntries++;
            $this->statistics->misses++;
            $this->removeCorrupt($path);

            return null;
        }
    }

    /** @param array<string, mixed> $payload */
    public function writeRecord(string $domain, string $kind, CacheKey $key, array $payload): bool
    {
        $path = $this->recordPath($domain, $kind, $key);
        $contents = CanonicalJson::encode([
            'cacheKey' => $key->value,
            'formatVersion' => CacheFormat::COMPILER,
            'payload' => $payload,
            'recordKind' => $kind,
        ]);

        if (strlen($contents) > $this->limits->maximumRecordBytes
            || str_contains(str_replace('\\', '/', $contents), str_replace('\\', '/', $this->projectRoot))) {
            return false;
        }

        try {
            $this->atomicWrite($path, $contents, true);
            $this->statistics->bytesWritten += strlen($contents);
            $this->prune($path);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function writeBlob(string $contents): ?string
    {
        if (strlen($contents) > $this->limits->maximumBlobBytes) {
            return null;
        }

        $hash = hash('sha256', $contents);
        $path = $this->blobPath($hash);

        try {
            if (is_file($path) && !is_link($path)) {
                $existing = $this->readBounded($path, $this->limits->maximumBlobBytes);

                if (hash_equals($hash, hash('sha256', $existing))) {
                    return 'sha256:' . $hash;
                }

                $this->removeCorrupt($path);
            }

            $this->atomicWrite($path, $contents, false);
            $this->statistics->blobsWritten++;
            $this->statistics->bytesWritten += strlen($contents);

            return 'sha256:' . $hash;
        } catch (\Throwable) {
            return null;
        }
    }

    public function readBlob(string $identity): ?string
    {
        if (preg_match('/^sha256:([a-f0-9]{64})$/D', $identity, $matches) !== 1) {
            return null;
        }

        $path = $this->blobPath($matches[1]);

        if (!is_file($path) || is_link($path)) {
            return null;
        }

        try {
            $contents = $this->readBounded($path, $this->limits->maximumBlobBytes);

            if (!hash_equals($matches[1], hash('sha256', $contents))) {
                throw new \RuntimeException('The cache blob hash does not match its path.');
            }

            $this->statistics->blobsRead++;
            $this->statistics->bytesRead += strlen($contents);

            return $contents;
        } catch (\Throwable) {
            $this->statistics->corruptEntries++;
            $this->removeCorrupt($path);

            return null;
        }
    }

    public function invalidateRecord(string $domain, string $kind, CacheKey $key): void
    {
        $this->removeCorrupt($this->recordPath($domain, $kind, $key));
    }

    private function recordPath(string $domain, string $kind, CacheKey $key): string
    {
        if (!in_array($domain, ['compiler', 'supplemental'], true)
            || preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $kind) !== 1) {
            throw new \InvalidArgumentException('The cache record domain or kind is invalid.');
        }

        return Path::join(
            $this->cacheRoot,
            $domain,
            'v' . CacheFormat::COMPILER,
            $domain === 'compiler' ? 'operations' : 'results',
            $kind,
            substr($key->hex, 0, 2),
            $key->hex . '.json',
        );
    }

    private function blobPath(string $hash): string
    {
        return Path::join(
            $this->cacheRoot,
            'compiler',
            'v' . CacheFormat::COMPILER,
            'blobs',
            substr($hash, 0, 2),
            $hash . '.blob',
        );
    }

    private function readBounded(string $path, int $maximumBytes): string
    {
        $size = @filesize($path);

        if (!is_int($size) || $size > $maximumBytes) {
            throw new \RuntimeException('A cache file exceeds its size limit.');
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw new \RuntimeException('A cache file could not be opened.');
        }

        try {
            $stat = fstat($handle);

            if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0100000) {
                throw new \RuntimeException('A cache entry is not a regular file.');
            }

            $contents = stream_get_contents($handle, $maximumBytes + 1);

            if (!is_string($contents) || strlen($contents) > $maximumBytes) {
                throw new \RuntimeException('A cache file could not be read safely.');
            }

            return $contents;
        } finally {
            fclose($handle);
        }
    }

    private function atomicWrite(string $path, string $contents, bool $replace): void
    {
        $this->guardDestination($path);
        $this->createDirectory(dirname($path));
        $temporary = Path::join(dirname($path), '.' . basename($path) . '.tmp-' . bin2hex(random_bytes(12)));
        $handle = @fopen($temporary, 'x+b');

        if ($handle === false) {
            throw new \RuntimeException('A cache temporary file could not be created.');
        }

        @chmod($temporary, 0600);

        try {
            $length = strlen($contents);
            $written = 0;

            while ($written < $length) {
                $count = fwrite($handle, substr($contents, $written));

                if (!is_int($count) || $count < 1) {
                    throw new \RuntimeException('A cache temporary file could not be written completely.');
                }

                $written += $count;
            }

            if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
                throw new \RuntimeException('A cache temporary file could not be synchronized.');
            }
        } finally {
            fclose($handle);
        }

        if (filesize($temporary) !== strlen($contents)
            || !hash_equals(hash('sha256', $contents), (string) hash_file('sha256', $temporary))) {
            @unlink($temporary);
            throw new \RuntimeException('A cache temporary file failed validation.');
        }

        if (is_file($path) && !is_link($path) && !$replace) {
            @unlink($temporary);

            return;
        }

        if ((file_exists($path) || is_link($path)) && (!is_file($path) || is_link($path))) {
            @unlink($temporary);
            throw new \RuntimeException('A cache destination is not a regular file.');
        }

        if (!@rename($temporary, $path)) {
            @unlink($temporary);
            throw new \RuntimeException('A cache file could not be committed atomically.');
        }
    }

    private function createDirectory(string $directory): void
    {
        $this->guardDestination($directory);

        if (is_dir($directory) && !is_link($directory)) {
            return;
        }

        if (file_exists($directory) || is_link($directory)
            || (!@mkdir($directory, 0700, true) && !is_dir($directory))) {
            throw new \RuntimeException('A cache directory could not be created safely.');
        }

        @chmod($directory, 0700);
    }

    private function guardDestination(string $path): void
    {
        if (!Path::contains($this->cacheRoot, $path)
            || Path::hasSymlinkAncestor($path, $this->projectRoot)
            || is_link($this->cacheRoot)) {
            throw new \RuntimeException('A cache path is outside the validated cache root.');
        }
    }

    private function removeCorrupt(string $path): void
    {
        if (Path::contains($this->cacheRoot, $path) && is_file($path) && !is_link($path)) {
            @unlink($path);
            $this->statistics->invalidatedEntries++;
        }
    }

    private function prune(string $protectedRecord): void
    {
        /** @var list<array{kind: 'blob'|'record'|'temporary', mtime: int, path: string, size: int}> $files */
        $files = [];
        $bytes = 0;
        $blobCount = 0;
        $recordCount = 0;

        foreach (['compiler', 'supplemental'] as $domain) {
            $root = Path::join($this->cacheRoot, $domain, 'v' . CacheFormat::COMPILER);

            if (!is_dir($root) || is_link($root)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
                $root,
                \FilesystemIterator::SKIP_DOTS,
            ));

            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || !$file->isFile() || $file->isLink()) {
                    continue;
                }

                $path = str_replace('\\', '/', $file->getPathname());
                $kind = str_contains($file->getFilename(), '.tmp-')
                    ? 'temporary'
                    : (str_contains($path, '/blobs/') ? 'blob' : 'record');
                $size = max(0, $file->getSize());
                $bytes += $size;
                $blobCount += $kind === 'blob' ? 1 : 0;
                $recordCount += $kind === 'record' ? 1 : 0;
                $files[] = [
                    'kind' => $kind,
                    'mtime' => $file->getMTime(),
                    'path' => $file->getPathname(),
                    'size' => $size,
                ];
            }
        }

        foreach ($files as $file) {
            if ($file['kind'] === 'temporary'
                && time() - $file['mtime'] >= $this->limits->activeWriteGraceSeconds
                && @unlink($file['path'])) {
                $bytes -= $file['size'];
                $this->statistics->invalidatedEntries++;
            }
        }

        if ($bytes <= $this->limits->maximumCacheBytes
            && $recordCount <= $this->limits->maximumRecordCount
            && $blobCount <= $this->limits->maximumBlobCount) {
            return;
        }

        usort($files, static fn (array $left, array $right): int =>
            ($left['mtime'] <=> $right['mtime']) ?: ($left['path'] <=> $right['path']));

        $fileCount = count($files);

        foreach ($files as $file) {
            if ($bytes <= $this->limits->maximumCacheBytes
                && $recordCount <= $this->limits->maximumRecordCount
                && $blobCount <= $this->limits->maximumBlobCount) {
                break;
            }

            if ($file['kind'] === 'record'
                && Path::buildComparisonKey($file['path']) !== Path::buildComparisonKey($protectedRecord)
                && @unlink($file['path'])) {
                $bytes -= $file['size'];
                $fileCount--;
                $recordCount--;
                $this->statistics->invalidatedEntries++;
            }
        }

        $reachable = $this->reachableBlobs();

        foreach ($files as $file) {
            if ($file['kind'] !== 'blob'
                || time() - $file['mtime'] < $this->limits->activeWriteGraceSeconds
                || isset($reachable[basename($file['path'], '.blob')])) {
                continue;
            }

            if (@unlink($file['path'])) {
                $bytes -= $file['size'];
                $fileCount--;
                $blobCount--;
                $this->statistics->invalidatedEntries++;
            }

            if ($bytes <= $this->limits->maximumCacheBytes
                && $recordCount <= $this->limits->maximumRecordCount
                && $blobCount <= $this->limits->maximumBlobCount) {
                break;
            }
        }
    }

    /** @return array<string, true> */
    private function reachableBlobs(): array
    {
        $reachable = [];

        foreach (['compiler', 'supplemental'] as $domain) {
            $root = Path::join($this->cacheRoot, $domain, 'v' . CacheFormat::COMPILER);

            if (!is_dir($root) || is_link($root)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
                $root,
                \FilesystemIterator::SKIP_DOTS,
            ));

            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo
                    || !$file->isFile()
                    || $file->isLink()
                    || !str_ends_with($file->getFilename(), '.json')) {
                    continue;
                }

                try {
                    $this->collectBlobIdentities(
                        json_decode(
                            $this->readBounded($file->getPathname(), $this->limits->maximumRecordBytes),
                            true,
                            $this->limits->maximumJsonDepth,
                            JSON_THROW_ON_ERROR,
                        ),
                        $reachable,
                    );
                } catch (\Throwable) {
                }
            }
        }

        return $reachable;
    }

    /** @param array<string, true> $reachable */
    private function collectBlobIdentities(mixed $value, array &$reachable): void
    {
        if (is_string($value) && preg_match('/^sha256:([a-f0-9]{64})$/D', $value, $matches) === 1) {
            $reachable[$matches[1]] = true;

            return;
        }

        if (!is_array($value)) {
            return;
        }

        foreach ($value as $item) {
            $this->collectBlobIdentities($item, $reachable);
        }
    }
}
