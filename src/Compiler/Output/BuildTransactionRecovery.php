<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Compiler\Output;

use Amasiye\Ppphp\Compiler\Manifest\BuildManifest;
use Amasiye\Ppphp\Compiler\Manifest\BuildManifestCodec;
use Amasiye\Ppphp\Compiler\Output\Enumerations\BuildTransactionState;
use Amasiye\Ppphp\Compiler\Output\Interfaces\BuildFilesystem;
use Amasiye\Ppphp\Config\ProjectConfig;
use Amasiye\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Amasiye\Ppphp\Support\Path;
use Amasiye\Ppphp\Transpilation\SourceMapWriter;

final readonly class BuildTransactionRecovery
{
    public function __construct(
        private BuildFilesystem $filesystem = new NativeBuildFilesystem(),
        private BuildTransactionJournal $journal = new BuildTransactionJournal(),
        private BuildManifestCodec $manifests = new BuildManifestCodec(),
        private SourceMapWriter $sourceMaps = new SourceMapWriter(),
    ) {}

    public function recover(ProjectConfig $configuration): void
    {
        try {
            $transaction = $this->journal->load($configuration);

            if ($transaction === null) {
                return;
            }

            $output = $this->journal->absolute($configuration, $transaction->output);
            $stage = $this->journal->absolute($configuration, $transaction->stage);
            $backup = $this->journal->absolute($configuration, $transaction->backup);
            $candidateAtOutput = $this->treeMatches(
                $output,
                $transaction->candidateManifestIdentity,
                $transaction,
                'candidate',
                $transaction->state !== BuildTransactionState::Completed,
            );
            $candidateAtStage = $this->treeMatches(
                $stage,
                $transaction->candidateManifestIdentity,
                $transaction,
                'candidate',
            );
            $priorAtOutput = $this->previousTreeMatches($output, $transaction, false);
            $priorAtBackup = $this->previousTreeMatches($backup, $transaction, true);

            if ($candidateAtOutput) {
                $this->journal->removeMarker($output);
                $this->removeMarkedTreeIfOwned(
                    $stage,
                    $transaction,
                    'candidate',
                    $transaction->candidateManifestIdentity,
                );
                $this->removeMarkedTreeIfOwned(
                    $backup,
                    $transaction,
                    'previous-output',
                    $transaction->priorManifestIdentity,
                );

                $this->complete($configuration, $transaction);

                return;
            }

            if ($priorAtOutput && $transaction->state === BuildTransactionState::Prepared) {
                $this->journal->removeMarker($output);
                $this->removeMarkedTree($stage, $transaction, 'candidate', $transaction->candidateManifestIdentity);
                $this->complete($configuration, $transaction);

                return;
            }

            if ($priorAtBackup) {
                if ($this->filesystem->checkExists($output)) {
                    if (!$this->journal->markerMatches(
                        $output,
                        $transaction,
                        'candidate',
                        $transaction->candidateManifestIdentity,
                    )) {
                        throw new \RuntimeException('The live output is ambiguous and cannot be replaced during recovery.');
                    }

                    $this->filesystem->remove($output);
                }

                $this->filesystem->move($backup, $output);
                $this->journal->removeMarker($output);
                $this->removeMarkedTree($stage, $transaction, 'candidate', $transaction->candidateManifestIdentity);
                $this->complete($configuration, $transaction);

                return;
            }

            if ($transaction->priorManifestIdentity === null
                && $transaction->state === BuildTransactionState::Prepared
                && !$this->filesystem->checkExists($output)
                && !$this->filesystem->checkExists($backup)
                && $candidateAtStage) {
                $this->filesystem->remove($stage);
                $this->complete($configuration, $transaction);

                return;
            }

            throw new \RuntimeException('No output tree can be selected from valid transaction evidence.');
        } catch (BuildOutputException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new BuildOutputException(
                DiagnosticCode::BuildTransactionCouldNotBeRecovered,
                'The interrupted build transaction could not be recovered safely.',
                'Do not remove transaction paths until their manifests are inspected; run again with --debug for recovery details.',
                $exception,
            );
        }
    }

    private function complete(ProjectConfig $configuration, BuildTransaction $transaction): void
    {
        $this->journal->write($configuration, $transaction->withState(BuildTransactionState::Completed));
        $this->journal->remove($configuration);
    }

    private function removeMarkedTree(
        string $path,
        BuildTransaction $transaction,
        string $role,
        ?string $manifestIdentity,
    ): void {
        if (!$this->filesystem->checkExists($path)) {
            return;
        }

        if (!$this->journal->markerMatches($path, $transaction, $role, $manifestIdentity)) {
            throw new \RuntimeException('A transaction-owned tree does not carry its expected marker.');
        }

        $this->filesystem->remove($path);
    }

    private function removeMarkedTreeIfOwned(
        string $path,
        BuildTransaction $transaction,
        string $role,
        ?string $manifestIdentity,
    ): void {
        if (!$this->filesystem->checkExists($path)
            || !$this->journal->markerMatches($path, $transaction, $role, $manifestIdentity)) {
            return;
        }

        $this->filesystem->remove($path);
    }

    private function treeMatches(
        string $root,
        string $manifestIdentity,
        BuildTransaction $transaction,
        string $role,
        bool $requireMarker = true,
    ): bool {
        try {
            if (!$this->filesystem->checkIsDirectory($root)
                || ($requireMarker
                    && !$this->journal->markerMatches($root, $transaction, $role, $manifestIdentity))) {
                return false;
            }

            $manifestPath = Path::join($root, '.ppphp/manifest.json');

            if (!$this->filesystem->checkIsFile($manifestPath)) {
                return false;
            }

            $serialized = $this->filesystem->readFile($manifestPath);

            if (!hash_equals($manifestIdentity, 'sha256:' . hash('sha256', $serialized))) {
                return false;
            }

            $manifest = $this->manifests->parse($serialized);
            $this->validateTree($root, $manifest);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function previousTreeMatches(
        string $root,
        BuildTransaction $transaction,
        bool $requireMarker,
    ): bool {
        if ($transaction->priorManifestIdentity === null) {
            return $this->journal->markerMatches($root, $transaction, 'previous-output', null);
        }

        return $this->treeMatches(
            $root,
            $transaction->priorManifestIdentity,
            $transaction,
            'previous-output',
            $requireMarker,
        );
    }

    private function validateTree(string $root, BuildManifest $manifest): void
    {
        foreach ($manifest->files as $entry) {
            $output = Path::join($root, $entry->output);
            $map = Path::join($root, $entry->sourceMap);

            if (!Path::contains($root, $output)
                || !$this->filesystem->checkIsFile($output)
                || !$this->filesystem->checkIsFile($map)) {
                throw new \RuntimeException('A transaction tree is incomplete.');
            }

            $contents = $this->filesystem->readFile($output);

            if (!hash_equals($entry->outputHash, 'sha256:' . hash('sha256', $contents))) {
                throw new \RuntimeException('A transaction output hash does not match.');
            }

            $this->sourceMaps->parseAndValidate(
                $this->filesystem->readFile($map),
                $entry->source,
                $entry->output,
                $entry->sourceHash,
                $entry->outputHash,
                strlen($contents),
            );
        }
    }
}
