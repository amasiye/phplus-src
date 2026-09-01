#!/usr/bin/env php
<?php

declare(strict_types=1);

use Amasiye\Ppphp\Analysis\Parity\AnalyzerParityRunner;

require dirname(__DIR__) . '/vendor/autoload.php';

$format = 'markdown';
$outputPath = null;

for ($index = 1; $index < $argc; $index++) {
    $argument = $argv[$index];

    if (str_starts_with($argument, '--format=')) {
        $format = substr($argument, strlen('--format='));
    } elseif ($argument === '--format' && isset($argv[$index + 1])) {
        $format = $argv[++$index];
    } elseif (str_starts_with($argument, '--output=')) {
        $outputPath = substr($argument, strlen('--output='));
    } elseif ($argument === '--output' && isset($argv[$index + 1])) {
        $outputPath = $argv[++$index];
    } else {
        fwrite(STDERR, sprintf("Unknown analyzer parity option: %s\n", $argument));
        exit(2);
    }
}

if (!in_array($format, ['json', 'markdown'], true)) {
    fwrite(STDERR, "Analyzer parity format must be json or markdown.\n");
    exit(2);
}

$root = dirname(__DIR__);
$report = (new AnalyzerParityRunner())->run($root . '/tests/Fixtures/AnalyzerParity/scenarios.php');
$json = $report->toJson();
$rendered = $format === 'json' ? $json : $report->toMarkdown();
$goldenPath = $root . '/tests/Golden/Analysis/analyzer-parity.json';

if (getenv('UPDATE_ANALYZER_PARITY') === '1') {
    $directory = dirname($goldenPath);

    if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
        fwrite(STDERR, "Could not create the analyzer parity golden directory.\n");
        exit(1);
    }

    if (file_put_contents($goldenPath, $json) === false) {
        fwrite(STDERR, "Could not update the analyzer parity golden report.\n");
        exit(1);
    }
}

if ($outputPath !== null) {
    $directory = dirname($outputPath);

    if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
        fwrite(STDERR, "Could not create the analyzer parity output directory.\n");
        exit(1);
    }

    if (file_put_contents($outputPath, $rendered) === false) {
        fwrite(STDERR, "Could not write the analyzer parity report.\n");
        exit(1);
    }
} else {
    fwrite(STDOUT, $rendered);
}

$golden = is_file($goldenPath) ? file_get_contents($goldenPath) : false;

if ($golden !== $json) {
    fwrite(STDERR, "Analyzer parity differs from the committed golden. Review the report, then set UPDATE_ANALYZER_PARITY=1 to accept intentional changes.\n");
    exit(1);
}

if ($report->hasUnexpectedResults()) {
    fwrite(STDERR, "Analyzer parity contains unexpected fixture results.\n");
    exit(1);
}
