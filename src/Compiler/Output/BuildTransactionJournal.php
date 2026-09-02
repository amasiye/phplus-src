<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Compiler\Output;

use Atatusoft\Ppphp\Compiler\Output\Enumerations\BuildTransactionState;
use Atatusoft\Ppphp\Compiler\Output\Interfaces\BuildFilesystem;
use Atatusoft\Ppphp\Config\ProjectConfig;
use Atatusoft\Ppphp\Support\CanonicalJson;
use Atatusoft\Ppphp\Support\Path;

final readonly class BuildTransactionJournal
{
    public const int MAXIMUM_BYTES = 16_384;
    public const string JOURNAL_NAME = '.ppphp-build-transaction.json';
    public const string MARKER_NAME = '.ppphp-transaction.json';

    public function __construct(private BuildFilesystem $filesystem = new NativeBuildFilesystem()) {}

    public function create(
        ProjectConfig $configuration,
        string $stage,
        string $backup,
        string $candidateManifestIdentity,
        ?string $priorManifestIdentity,
    ): BuildTransaction {
        return new BuildTransaction(
            bin2hex(random_bytes(24)),
            $this->relative($configuration, $configuration->outputPath),
            $this->relative($configuration, $stage),
            $this->relative($configuration, $backup),
            $candidateManifestIdentity,
            $priorManifestIdentity,
            BuildTransactionState::Prepared,
        );
    }

    public function load(ProjectConfig $configuration): ?BuildTransaction
    {
        $path = $this->path($configuration);

        if (!$this->filesystem->checkExists($path)) {
            return null;
        }

        if (!$this->filesystem->checkIsFile($path)) {
            throw new \RuntimeException('The build transaction journal is not a regular file.');
        }

        $contents = $this->filesystem->readFileBounded($path, self::MAXIMUM_BYTES);
        $data = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        $candidateManifestIdentity = is_array($data) ? ($data['candidateManifestIdentity'] ?? null) : null;
        $priorManifestIdentity = is_array($data) ? ($data['priorManifestIdentity'] ?? null) : null;
        $expected = [
            'backup',
            'candidateManifestIdentity',
            'formatVersion',
            'identity',
            'output',
            'priorManifestIdentity',
            'stage',
            'state',
        ];

        if (!is_array($data) || array_is_list($data) || array_keys($data) !== $expected
            || ($data['formatVersion'] ?? null) !== BuildTransaction::FORMAT_VERSION
            || !is_string($data['identity'] ?? null)
            || preg_match('/^[a-f0-9]{48}$/D', $data['identity']) !== 1
            || !is_string($candidateManifestIdentity)
            || !$this->isHash($candidateManifestIdentity)
            || ($priorManifestIdentity !== null
                && (!is_string($priorManifestIdentity) || !$this->isHash($priorManifestIdentity)))
            || !is_string($data['state'] ?? null)
            || ($state = BuildTransactionState::tryFrom($data['state'])) === null
            || CanonicalJson::encode($data) !== $contents) {
            throw new \RuntimeException('The build transaction journal is invalid.');
        }

        foreach (['output', 'stage', 'backup'] as $field) {
            if (!is_string($data[$field] ?? null)) {
                throw new \RuntimeException('A build transaction path is invalid.');
            }

            $this->absolute($configuration, $data[$field]);
        }

        if ($data['output'] !== $this->relative($configuration, $configuration->outputPath)
            || dirname($data['stage']) !== dirname($data['output'])
            || dirname($data['backup']) !== dirname($data['output'])
            || !str_starts_with(basename($data['stage']), '.ppphp-stage-')
            || !str_starts_with(basename($data['backup']), '.ppphp-backup-')) {
            throw new \RuntimeException('The build transaction paths do not match this project output.');
        }

        return new BuildTransaction(
            $data['identity'],
            $data['output'],
            $data['stage'],
            $data['backup'],
            $candidateManifestIdentity,
            $priorManifestIdentity,
            $state,
        );
    }

    public function write(ProjectConfig $configuration, BuildTransaction $transaction): void
    {
        $this->filesystem->writeFileAtomically(
            $this->path($configuration),
            CanonicalJson::encode([
                'backup' => $transaction->backup,
                'candidateManifestIdentity' => $transaction->candidateManifestIdentity,
                'formatVersion' => BuildTransaction::FORMAT_VERSION,
                'identity' => $transaction->identity,
                'output' => $transaction->output,
                'priorManifestIdentity' => $transaction->priorManifestIdentity,
                'stage' => $transaction->stage,
                'state' => $transaction->state->value,
            ]),
            0600,
        );
    }

    public function remove(ProjectConfig $configuration): void
    {
        $path = $this->path($configuration);

        if ($this->filesystem->checkExists($path)) {
            $this->filesystem->remove($path);
        }
    }

    public function writeMarker(
        string $root,
        BuildTransaction $transaction,
        string $role,
        ?string $manifestIdentity,
    ): void {
        if (!in_array($role, ['candidate', 'previous-output'], true)) {
            throw new \InvalidArgumentException('A build transaction marker role is invalid.');
        }

        $this->filesystem->writeFileAtomically(
            Path::join($root, self::MARKER_NAME),
            CanonicalJson::encode([
                'formatVersion' => BuildTransaction::FORMAT_VERSION,
                'identity' => $transaction->identity,
                'manifestIdentity' => $manifestIdentity,
                'output' => $transaction->output,
                'role' => $role,
            ]),
            0600,
        );
    }

    public function markerMatches(
        string $root,
        BuildTransaction $transaction,
        string $role,
        ?string $manifestIdentity,
    ): bool {
        $path = Path::join($root, self::MARKER_NAME);

        try {
            if (!$this->filesystem->checkIsFile($path)) {
                return false;
            }

            $contents = $this->filesystem->readFileBounded($path, self::MAXIMUM_BYTES);
            $data = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);

            return is_array($data)
                && !array_is_list($data)
                && array_keys($data) === ['formatVersion', 'identity', 'manifestIdentity', 'output', 'role']
                && ($data['formatVersion'] ?? null) === BuildTransaction::FORMAT_VERSION
                && ($data['identity'] ?? null) === $transaction->identity
                && ($data['manifestIdentity'] ?? null) === $manifestIdentity
                && ($data['output'] ?? null) === $transaction->output
                && ($data['role'] ?? null) === $role
                && CanonicalJson::encode($data) === $contents;
        } catch (\Throwable) {
            return false;
        }
    }

    public function removeMarker(string $root): void
    {
        $path = Path::join($root, self::MARKER_NAME);

        if ($this->filesystem->checkExists($path)) {
            $this->filesystem->remove($path);
        }
    }

    public function absolute(ProjectConfig $configuration, string $relative): string
    {
        $segments = explode('/', str_replace('\\', '/', $relative));
        $normalized = Path::normalize($relative);

        if ($normalized === '.' || Path::isAbsolute($relative) || in_array('..', $segments, true)
            || str_contains($relative, "\0")) {
            throw new \RuntimeException('A build transaction path is unsafe.');
        }

        $absolute = Path::join($configuration->projectRoot, $normalized);

        if (!Path::contains($configuration->projectRoot, $absolute)
            || Path::hasSymlinkAncestor($absolute, $configuration->projectRoot)) {
            throw new \RuntimeException('A build transaction path escapes the project root.');
        }

        return $absolute;
    }

    private function path(ProjectConfig $configuration): string
    {
        return Path::join($configuration->projectRoot, self::JOURNAL_NAME);
    }

    private function relative(ProjectConfig $configuration, string $path): string
    {
        if (!Path::contains($configuration->projectRoot, $path)) {
            throw new \RuntimeException('A build transaction path is outside the project root.');
        }

        return str_replace('\\', '/', Path::resolveRelativeTo($path, $configuration->projectRoot));
    }

    private function isHash(mixed $value): bool
    {
        return is_string($value) && preg_match('/^sha256:[a-f0-9]{64}$/D', $value) === 1;
    }
}
