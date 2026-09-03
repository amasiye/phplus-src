#!/usr/bin/env php
<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Compiler\Compiler;
use Atatusoft\Ppphp\Support\Path;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = Path::normalize(dirname(__DIR__));
$failures = [];
$required = [
    'README.md',
    'CHANGELOG.md',
    'SECURITY.md',
    'THIRD_PARTY_NOTICES.md',
    'docs/getting-started.md',
    'docs/migrating-from-php.md',
    'docs/releases/README.md',
    'docs/releases/2026.3.1-rc-1.md',
    'docs/releasing.md',
    'docs/decisions/0004-mvp-native-analysis-retains-phpstan.md',
];

foreach ($required as $relativePath) {
    if (!is_file(Path::join($root, $relativePath))) {
        $failures[] = sprintf('required document "%s" is missing', $relativePath);
    }
}

$documents = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        static function (SplFileInfo $entry): bool {
            return !in_array($entry->getFilename(), ['.git', 'vendor', 'node_modules', 'dist', '.ppphp-cache', 'build'], true);
        },
    ),
);

foreach ($iterator as $entry) {
    if (!$entry instanceof SplFileInfo || !$entry->isFile() || strtolower($entry->getExtension()) !== 'md') {
        continue;
    }

    $path = Path::normalize($entry->getPathname());
    $contents = file_get_contents($path);

    if (!is_string($contents)) {
        $failures[] = sprintf('Markdown document "%s" is unreadable', Path::resolveRelativeTo($path, $root));
        continue;
    }

    $documents[$path] = $contents;
}

ksort($documents, SORT_STRING);

foreach ($documents as $path => $contents) {
    $relativePath = Path::resolveRelativeTo($path, $root);
    $linkText = preg_replace('/```.*?```|~~~.*?~~~/s', '', $contents) ?? $contents;

    if (preg_match_all('/!?\[[^\]]*\]\(([^)]+)\)/', $linkText, $matches) !== false) {
        foreach ($matches[1] as $target) {
            $target = trim((string) $target, " <>\t\n\r\0\x0B");

            if ($target === '' || $target[0] === '#' || preg_match('/\A[a-z][a-z0-9+.-]*:/i', $target) === 1) {
                continue;
            }

            $target = rawurldecode(explode('#', $target, 2)[0]);
            $resolved = Path::join(dirname($path), $target);

            if (!Path::contains($root, $resolved) || (!is_file($resolved) && !is_dir($resolved))) {
                $failures[] = sprintf('%s contains an unresolved repository link: %s', $relativePath, $target);
            }
        }
    }
}

$readme = $documents[Path::join($root, 'README.md')] ?? '';
$releaseNotes = $documents[Path::join($root, 'docs/releases/2026.3.1-rc-1.md')] ?? '';
$changelog = $documents[Path::join($root, 'CHANGELOG.md')] ?? '';
$plan = $documents[Path::join($root, 'docs/ppphp-mvp-end-to-end-plan.md')] ?? '';
$decision = $documents[Path::join($root, 'docs/decisions/0004-mvp-native-analysis-retains-phpstan.md')] ?? '';

$expectations = [
    [str_contains($readme, Compiler::VERSION), 'README does not state the compiler version'],
    [str_contains($readme, '37-capability'), 'README does not state the exact 37-capability catalog'],
    [str_contains($readme, 'Atatusoft\\Ppphp'), 'README does not state the canonical PHP namespace'],
    [str_contains($readme, 'composer require --dev atatusoft-ltd/ppphp-src:2026.3.1-rc-1'), 'README does not show the exact RC installation command'],
    [str_contains($releaseNotes, Compiler::VERSION), 'release notes do not state the compiler version'],
    [str_contains($releaseNotes, 'composer require --dev atatusoft-ltd/ppphp-src:2026.3.1-rc-1'), 'release notes do not show the exact RC installation command'],
    [str_contains($releaseNotes, 'ordinary PHP 8.4'), 'release notes do not explain the generated runtime output'],
    [str_contains($changelog, Compiler::VERSION), 'changelog does not contain the prepared RC'],
    [str_contains($plan, 'Stage 14A') && str_contains($plan, 'Stage 14B') && str_contains($plan, 'Stage 14C'), 'MVP plan does not preserve the Stage 14 release split'],
    [str_contains($plan, 'Stage 15') && str_contains($plan, 'post-MVP'), 'MVP plan does not classify Stage 15 as post-MVP'],
    [str_contains($decision, 'PHPStan') && stripos($decision, 'retain') !== false, 'analyzer decision does not record the retained PHPStan backend'],
];

foreach ($expectations as [$condition, $message]) {
    if (!$condition) {
        $failures[] = $message;
    }
}

foreach (['Stage ', 'MVP', 'post-MVP', 'compiler-owned', 'compilerCore', 'PHPStan', 'parity', 'promotion-readiness', 'completion gate'] as $internalTerm) {
    if (stripos($releaseNotes, $internalTerm) !== false) {
        $failures[] = sprintf('release notes contain internal implementation language: %s', $internalTerm);
    }
}

$retiredIdentity = 'ph' . 'plus';
$retiredNamespace = 'Ama' . 'siye\\Ppphp';
$retiredProduct = 'Do' . 'ria';

foreach ($documents as $path => $contents) {
    if (
        stripos($contents, $retiredIdentity) !== false
        || stripos($contents, $retiredProduct) !== false
        || str_contains($contents, $retiredNamespace)
        || preg_match('/\.ppp\b/i', $contents) === 1
    ) {
        $failures[] = sprintf('%s contains retired public identity', Path::resolveRelativeTo($path, $root));
    }
}

if ($failures !== []) {
    foreach (array_values(array_unique($failures)) as $failure) {
        fwrite(STDERR, 'Documentation verification failed: ' . $failure . ".\n");
    }

    exit(1);
}

fwrite(STDOUT, sprintf("Verified offline documentation for ++PHP %s.\n", Compiler::VERSION));
