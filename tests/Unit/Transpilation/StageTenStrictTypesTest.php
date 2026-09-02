<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Diagnostics\Diagnostic;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Atatusoft\Ppphp\Frontend\PpphpParser;
use Atatusoft\Ppphp\Project\ProjectParseResult;
use Atatusoft\Ppphp\Semantic\SemanticAnalyzer;
use Atatusoft\Ppphp\Source\Enumerations\FileKind;
use Atatusoft\Ppphp\Source\SourceFile;
use Atatusoft\Ppphp\Support\Path;
use Atatusoft\Ppphp\Transpilation\GeneratedPhp;
use Atatusoft\Ppphp\Transpilation\PhpLowerer;
use Symfony\Component\Process\Process;

/** @return array{Atatusoft\Ppphp\Project\ProjectParseResult, Atatusoft\Ppphp\Semantic\SemanticAnalysisResult} */
function analyzeStageTenStrictSource(string $contents): array
{
    $path = '/project/src/Strict.ppphp';
    $source = new SourceFile($path, 'src/Strict.ppphp', FileKind::Ppphp, $contents);
    $parse = (new PpphpParser())->parse($source);
    $key = Path::buildComparisonKey($path);
    $project = new ProjectParseResult(
        $parse->parsedFile === null ? [] : [$key => $parse->parsedFile],
        [$key => $source],
        $parse->diagnostics,
    );

    return [$project, (new SemanticAnalyzer())->analyze($project)];
}

function lowerStageTenStrictSource(string $contents): GeneratedPhp
{
    [$project, $analysis] = analyzeStageTenStrictSource($contents);
    $parsed = $project->findParsedFile('/project/src/Strict.ppphp');
    $model = $analysis->findModel('/project/src/Strict.ppphp');

    expect($parsed)->not->toBeNull()
        ->and($analysis->isSuccessful)->toBeTrue()
        ->and($model)->not->toBeNull();

    return (new PhpLowerer())->lower($parsed, $model);
}

test('strict types are inserted safely and source mapped across supported file prefixes', function (string $source, string $prefix): void {
    $generated = lowerStageTenStrictSource($source);
    $path = $this->createTemporaryDirectory() . '/Strict.php';
    $this->writeFile($path, $generated->contents);
    $lint = new Process([PHP_BINARY, '-l', $path]);
    $lint->run();
    $strictOffset = strpos($generated->contents, 'declare(strict_types=1);');

    expect($generated->contents)->toStartWith($prefix)
        ->and(substr_count($generated->contents, 'declare(strict_types=1);'))->toBe(1)
        ->and($strictOffset)->toBeInt()
        ->and($generated->sourceMap->resolveOriginalOffset($strictOffset))->toBeGreaterThanOrEqual(0)
        ->and($lint->isSuccessful())->toBeTrue();
})->with([
    'ordinary LF' => ["<?php\nfunction value(): int { return 1; }\n", "<?php\ndeclare(strict_types=1);\n"],
    'leading comment' => ["<?php\n// preserved\nfunction value(): int { return 1; }\n", "<?php\ndeclare(strict_types=1);\n// preserved"],
    'ordinary CRLF' => ["<?php\r\nfunction value(): int { return 1; }\r\n", "<?php\r\ndeclare(strict_types=1);\r\n"],
    'shebang' => ["#!/usr/bin/env php\n<?php\nfunction value(): int { return 1; }\n", "#!/usr/bin/env php\n<?php declare(strict_types=1); ?><?php"],
    'inline HTML' => ["before<?php function value(): int { return 1; }", "<?php declare(strict_types=1); ?>before<?php"],
]);

test('an existing strict declaration is preserved without duplication', function (): void {
    $source = "<?php\ndeclare(strict_types=1);\nfunction value(): int { return 1; }\n";
    $generated = lowerStageTenStrictSource($source);

    expect($generated->contents)->toBe($source)
        ->and(substr_count($generated->contents, 'declare(strict_types=1);'))->toBe(1);
});

test('strict types cannot be disabled in check or lowering', function (): void {
    [, $analysis] = analyzeStageTenStrictSource("<?php\ndeclare(strict_types=0);\nfunction value(): int { return 1; }\n");
    $codes = array_map(
        static fn (Diagnostic $diagnostic): DiagnosticCode => $diagnostic->code,
        iterator_to_array($analysis->diagnostics),
    );

    expect($analysis->isSuccessful)->toBeFalse()
        ->and($codes)->toContain(DiagnosticCode::StrictTypesCannotBeDisabled);
});
