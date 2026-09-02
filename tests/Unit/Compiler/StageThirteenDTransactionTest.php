<?php

declare(strict_types=1);

use Amasiye\Ppphp\Compiler\Compiler;
use Amasiye\Ppphp\Compiler\Output\BuildTransactionJournal;
use Amasiye\Ppphp\Compiler\Output\BuildTransactionRecovery;
use Amasiye\Ppphp\Compiler\Output\Enumerations\BuildTransactionState;
use Amasiye\Ppphp\Compiler\Output\NativeBuildFilesystem;
use Amasiye\Ppphp\Config\ProjectConfig;
use Amasiye\Ppphp\Config\ProjectConfigLoader;
use Amasiye\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Amasiye\Ppphp\Diagnostics\ConsoleRenderer;
use Amasiye\Ppphp\Project\Enumerations\SelectionMode;
use Amasiye\Ppphp\Project\Project;
use Amasiye\Ppphp\Project\ProjectLoader;
use Amasiye\Ppphp\Project\ProjectSelection;
use Amasiye\Ppphp\Project\ProjectSelector;
use Amasiye\Ppphp\Support\CanonicalJson;

/** @return array{ProjectConfig, Project, ProjectSelection} */
function loadStageThirteenDTransactionProject(string $root): array
{
    $configuration = (new ProjectConfigLoader())->load($root, null, true)->configuration;
    expect($configuration)->not->toBeNull();
    $project = (new ProjectLoader())->load($configuration)->project;
    expect($project)->not->toBeNull();
    $selection = (new ProjectSelector())->select($project, null, SelectionMode::Build)->selection;
    expect($selection)->not->toBeNull();

    return [$configuration, $project, $selection];
}

function stageThirteenDManifestIdentity(string $root): string
{
    $contents = file_get_contents($root . '/.ppphp/manifest.json');

    if (!is_string($contents)) {
        throw new RuntimeException('The transaction fixture manifest could not be read.');
    }

    return 'sha256:' . hash('sha256', $contents);
}

/** @return array{ProjectConfig, Project, ProjectSelection, string, string, string} */
function createStageThirteenDTransactionFixture(string $root): array
{
    $filesystem = new NativeBuildFilesystem();
    $filesystem->writeFile($root . '/src/Value.ppphp', "<?php\nfunction transactionValue(): int { return 1; }\n");
    [$configuration, $project, $selection] = loadStageThirteenDTransactionProject($root);
    $root = $configuration->projectRoot;
    $priorBuild = (new Compiler())->compile($project, $selection);
    expect($priorBuild->isSuccessful)->toBeTrue((new ConsoleRenderer())->render($priorBuild->diagnostics));

    $prior = $root . '/transaction-fixtures/prior';
    $filesystem->cloneTree($configuration->outputPath, $prior);
    $filesystem->writeFile($root . '/src/Value.ppphp', "<?php\nfunction transactionValue(): int { return 2; }\n");
    [, $candidateProject, $candidateSelection] = loadStageThirteenDTransactionProject($root);
    $candidateBuild = (new Compiler())->compile($candidateProject, $candidateSelection);
    expect($candidateBuild->isSuccessful)->toBeTrue((new ConsoleRenderer())->render($candidateBuild->diagnostics));

    $stage = $root . '/build/.ppphp-stage-transaction-fixture';
    $backup = $root . '/build/.ppphp-backup-transaction-fixture';
    $filesystem->cloneTree($configuration->outputPath, $stage);
    $filesystem->remove($configuration->outputPath);
    $filesystem->cloneTree($prior, $configuration->outputPath);

    return [$configuration, $candidateProject, $candidateSelection, $stage, $backup, $prior];
}

test('durable build transaction states recover deterministically', function (
    BuildTransactionState $state,
    string $expectedReturn,
): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    [$configuration, , , $stage, $backup] = createStageThirteenDTransactionFixture($root);
    $filesystem = new NativeBuildFilesystem();
    $journal = new BuildTransactionJournal($filesystem);
    $transaction = $journal->create(
        $configuration,
        $stage,
        $backup,
        stageThirteenDManifestIdentity($stage),
        stageThirteenDManifestIdentity($configuration->outputPath),
    );
    $journal->writeMarker($stage, $transaction, 'candidate', $transaction->candidateManifestIdentity);
    $journal->write($configuration, $transaction);

    if ($state !== BuildTransactionState::Prepared) {
        $journal->writeMarker(
            $configuration->outputPath,
            $transaction,
            'previous-output',
            $transaction->priorManifestIdentity,
        );
        $filesystem->move($configuration->outputPath, $backup);
        $transaction = $transaction->withState(BuildTransactionState::PreviousOutputBackedUp);
        $journal->write($configuration, $transaction);
    }

    if (in_array($state, [BuildTransactionState::CandidateCommitted, BuildTransactionState::Completed], true)) {
        $filesystem->move($stage, $configuration->outputPath);
        $transaction = $transaction->withState($state);
        $journal->write($configuration, $transaction);
    }

    $recovery = new BuildTransactionRecovery($filesystem, $journal);
    $recovery->recover($configuration);
    $recovery->recover($configuration);

    expect(file_get_contents($configuration->outputPath . '/Value.php'))->toContain($expectedReturn)
        ->and(file_exists($configuration->projectRoot . '/' . BuildTransactionJournal::JOURNAL_NAME))->toBeFalse()
        ->and(file_exists($stage))->toBeFalse()
        ->and(file_exists($backup))->toBeFalse()
        ->and(file_exists($configuration->outputPath . '/' . BuildTransactionJournal::MARKER_NAME))->toBeFalse();
})->with([
    'prepared rolls back' => [BuildTransactionState::Prepared, 'return 1;'],
    'previous output backed up rolls back' => [BuildTransactionState::PreviousOutputBackedUp, 'return 1;'],
    'candidate committed rolls forward' => [BuildTransactionState::CandidateCommitted, 'return 2;'],
    'completed finishes cleanup' => [BuildTransactionState::Completed, 'return 2;'],
]);

test('transaction recovery preserves unmarked orphan directories', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    [$configuration] = createStageThirteenDTransactionFixture($root);
    $orphanStage = $configuration->projectRoot . '/build/.ppphp-stage-unmarked-orphan';
    $orphanBackup = $configuration->projectRoot . '/build/.ppphp-backup-unmarked-orphan';
    $this->writeFile($orphanStage . '/keep.txt', 'stage');
    $this->writeFile($orphanBackup . '/keep.txt', 'backup');

    (new BuildTransactionRecovery())->recover($configuration);

    expect(file_get_contents($orphanStage . '/keep.txt'))->toBe('stage')
        ->and(file_get_contents($orphanBackup . '/keep.txt'))->toBe('backup');
});

test('a valid committed candidate survives ambiguous cleanup remnants', function (): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    [$configuration, , , $stage, $backup] = createStageThirteenDTransactionFixture($root);
    $filesystem = new NativeBuildFilesystem();
    $journal = new BuildTransactionJournal($filesystem);
    $transaction = $journal->create(
        $configuration,
        $stage,
        $backup,
        stageThirteenDManifestIdentity($stage),
        stageThirteenDManifestIdentity($configuration->outputPath),
    );
    $journal->writeMarker($stage, $transaction, 'candidate', $transaction->candidateManifestIdentity);
    $journal->write($configuration, $transaction);
    $journal->writeMarker(
        $configuration->outputPath,
        $transaction,
        'previous-output',
        $transaction->priorManifestIdentity,
    );
    $filesystem->move($configuration->outputPath, $backup);
    $filesystem->move($stage, $configuration->outputPath);
    $transaction = $transaction->withState(BuildTransactionState::CandidateCommitted);
    $journal->write($configuration, $transaction);
    $filesystem->remove($backup . '/' . BuildTransactionJournal::MARKER_NAME);
    $filesystem->writeFile($stage . '/keep.txt', 'unmarked stage remnant');

    $recovery = new BuildTransactionRecovery($filesystem, $journal);
    $recovery->recover($configuration);
    $recovery->recover($configuration);

    expect(file_get_contents($configuration->outputPath . '/Value.php'))->toContain('return 2;')
        ->and(file_exists($configuration->projectRoot . '/' . BuildTransactionJournal::JOURNAL_NAME))->toBeFalse()
        ->and(file_get_contents($backup . '/Value.php'))->toContain('return 1;')
        ->and(file_get_contents($stage . '/keep.txt'))->toBe('unmarked stage remnant')
        ->and(file_exists($configuration->outputPath . '/' . BuildTransactionJournal::MARKER_NAME))->toBeFalse();
});

test('invalid build transaction journals fail closed before output mutation', function (string $journalContents): void {
    $root = $this->createTemporaryDirectory();
    $this->writeConfiguration($root);
    [$configuration, $project, $selection, $stage, $backup] = createStageThirteenDTransactionFixture($root);
    $before = file_get_contents($configuration->outputPath . '/Value.php');
    $this->writeFile(
        $configuration->projectRoot . '/' . BuildTransactionJournal::JOURNAL_NAME,
        $journalContents,
    );

    $result = (new Compiler())->compile($project, $selection);

    expect($result->isSuccessful)->toBeFalse()
        ->and($result->diagnostics->errors[0]->code ?? null)->toBe(DiagnosticCode::BuildTransactionCouldNotBeRecovered)
        ->and(file_get_contents($configuration->outputPath . '/Value.php'))->toBe($before)
        ->and(file_exists($stage))->toBeTrue()
        ->and(file_exists($backup))->toBeFalse();
})->with([
    'malformed JSON' => '{',
    'path traversal' => CanonicalJson::encode([
        'backup' => '../outside',
        'candidateManifestIdentity' => 'sha256:' . str_repeat('a', 64),
        'formatVersion' => 1,
        'identity' => str_repeat('b', 48),
        'output' => 'build/ppphp',
        'priorManifestIdentity' => null,
        'stage' => 'build/.ppphp-stage-invalid',
        'state' => 'prepared',
    ]),
]);
