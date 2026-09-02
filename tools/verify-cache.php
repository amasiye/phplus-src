#!/usr/bin/env php
<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Cache\CacheKey;
use Atatusoft\Ppphp\Cache\CacheLimits;
use Atatusoft\Ppphp\Cache\CacheStatistics;
use Atatusoft\Ppphp\Cache\CacheStore;
use Atatusoft\Ppphp\Cache\CompilerBuildIdentity;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = sys_get_temp_dir() . '/ppphp-cache-verification-' . bin2hex(random_bytes(12));
$cache = $root . '/.ppphp-cache';

try {
    if (!mkdir($root, 0700, true) && !is_dir($root)) {
        throw new RuntimeException('The cache verification root could not be created.');
    }

    $statistics = new CacheStatistics();
    $store = new CacheStore($root, $cache, $statistics, new CacheLimits(
        maximumRecordBytes: 4_096,
        maximumBlobBytes: 4_096,
        maximumCacheBytes: 16_384,
        maximumRecordCount: 4,
        maximumBlobCount: 4,
        activeWriteGraceSeconds: 0,
    ));
    $key = CacheKey::create('verification', ['source' => 'sha256:' . str_repeat('a', 64)]);
    $blob = $store->writeBlob('verified artifact');

    if ($blob === null
        || !$store->writeRecord('compiler', 'verification', $key, ['artifactBlob' => $blob])
        || $store->readRecord('compiler', 'verification', $key) !== ['artifactBlob' => $blob]
        || $store->readBlob($blob) !== 'verified artifact') {
        throw new RuntimeException('The cache could not round-trip verified evidence.');
    }

    $recordPath = sprintf(
        '%s/compiler/v1/operations/verification/%s/%s.json',
        $cache,
        substr($key->hex, 0, 2),
        $key->hex,
    );
    $record = file_get_contents($recordPath);

    if (!is_string($record)
        || str_contains($record, $root)
        || str_contains($record, 'PASSWORD=')
        || str_contains($record, 'TOKEN=')) {
        throw new RuntimeException('The cache record contains non-portable or secret material.');
    }

    $blobPath = sprintf(
        '%s/compiler/v1/blobs/%s/%s.blob',
        $cache,
        substr($blob, 7, 2),
        substr($blob, 7),
    );

    if (file_put_contents($blobPath, 'corrupt') === false || $store->readBlob($blob) !== null) {
        throw new RuntimeException('Corrupt cache evidence was not converted into a safe miss.');
    }

    if (preg_match('/^sha256:[a-f0-9]{64}$/D', (new CompilerBuildIdentity())->calculate()) !== 1
        || $statistics->corruptEntries < 1
        || $statistics->invalidatedEntries < 1) {
        throw new RuntimeException('The cache identity or corruption statistics are invalid.');
    }

    fwrite(STDOUT, "Verified compiler cache: canonical record, content-addressed blob, safe corruption miss\n");
} finally {
    removeCacheVerificationPath($root);
}

function removeCacheVerificationPath(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }

    if (!is_dir($path)) {
        return;
    }

    foreach (new DirectoryIterator($path) as $entry) {
        if (!$entry->isDot()) {
            removeCacheVerificationPath($entry->getPathname());
        }
    }

    @rmdir($path);
}
