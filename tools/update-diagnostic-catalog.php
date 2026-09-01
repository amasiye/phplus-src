<?php

declare(strict_types=1);

use Amasiye\Ppphp\Diagnostics\DiagnosticCatalog;

require dirname(__DIR__) . '/vendor/autoload.php';

$path = dirname(__DIR__) . '/docs/diagnostics.md';
$contents = file_get_contents($path);

if ($contents === false) {
    throw new RuntimeException('Could not read docs/diagnostics.md.');
}

$lines = [
    '| Code | Family | Status | Severity | Title |',
    '| --- | --- | --- | --- | --- |',
];

foreach (DiagnosticCatalog::definitions() as $definition) {
    $lines[] = sprintf(
        '| `%s` | `%s` | `%s` | `%s` | %s |',
        $definition->code->value,
        $definition->family->value,
        $definition->status->value,
        $definition->severity->value,
        $definition->title,
    );
}

$replacement = "<!-- diagnostic-catalog:start -->\n"
    . implode("\n", $lines)
    . "\n<!-- diagnostic-catalog:end -->";
$updated = preg_replace(
    '/<!-- diagnostic-catalog:start -->.*<!-- diagnostic-catalog:end -->/s',
    $replacement,
    $contents,
    1,
    $count,
);

if (!is_string($updated) || $count !== 1) {
    throw new RuntimeException('Could not locate the diagnostic catalog markers.');
}

if (file_put_contents($path, $updated) === false) {
    throw new RuntimeException('Could not update docs/diagnostics.md.');
}
