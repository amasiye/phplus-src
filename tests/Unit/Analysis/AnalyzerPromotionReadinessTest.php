<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

test('the analyzer promotion report is deterministic evidence-backed and decision-neutral', function (): void {
    $root = dirname(__DIR__, 3);
    $tool = $root . '/tools/analyzer-promotion-readiness.php';
    $run = static function (string $format) use ($root, $tool): string {
        $process = new Process([PHP_BINARY, $tool, '--format=' . $format], $root);
        $process->mustRun();

        return $process->getOutput();
    };
    $json = $run('json');
    $report = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    $markdown = $run('markdown');
    $gateIds = array_column($report['gates'] ?? [], 'id');

    expect($run('json'))->toBe($json)
        ->and($json)->toEndWith("\n")
        ->and($json)->not->toEndWith("\n\n")
        ->and($run('markdown'))->toBe($markdown)
        ->and($report['technicalStatus'] ?? null)->toBe('pass')
        ->and($report['productDecision'] ?? null)->toBe('pending')
        ->and($report['nativeDefault'] ?? null)->toBe('PHPStan supplemental path')
        ->and($report['recommendedNextAction'] ?? null)->toContain('Explicit analyzer-default decision')
        ->and($gateIds)->not->toBeEmpty()
        ->and(array_unique($gateIds))->toBe($gateIds)
        ->and(array_filter(
            $report['gates'] ?? [],
            static fn (mixed $gate): bool => !is_array($gate)
                || ($gate['pass'] ?? null) !== true
                || !is_string($gate['evidence'] ?? null)
                || $gate['evidence'] === '',
        ))->toBe([])
        ->and($markdown)->toStartWith('# Analyzer Promotion Readiness')
        ->and($markdown)->toContain(
            'Technical gates: **Pass**',
            'Product decision: **Pending**',
            'Native default: **PHPStan supplemental path**',
            'PHPStan remains installed and required',
        );

    $source = (string) file_get_contents($tool);
    expect($source)->not->toMatch('/https?:\/\//i')
        ->and($source)->not->toContain('curl_', 'socket_', 'stream_socket_');
});
