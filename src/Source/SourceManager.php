<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Source;

use Atatusoft\Ppphp\Source\Enumerations\FileKind;
use Atatusoft\Ppphp\Support\Path;

final class SourceManager
{
    /** @var array<string, SourceFile> */
    private array $sources = [];

    public function __construct(private readonly ?string $projectRoot = null)
    {
        if ($projectRoot !== null && !Path::isAbsolute($projectRoot)) {
            throw new \InvalidArgumentException('The source manager project root must be absolute.');
        }
    }

    public function load(string $path, ?FileKind $kind = null): SourceFile
    {
        $absolutePath = $this->resolveAbsolutePath($path);
        $key = Path::buildComparisonKey($absolutePath);

        if (isset($this->sources[$key])) {
            return $this->sources[$key];
        }

        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            throw new \RuntimeException(sprintf('Unable to read source file "%s".', $path));
        }

        $contents = file_get_contents($absolutePath);

        if ($contents === false) {
            throw new \RuntimeException(sprintf('Unable to read source file "%s".', $path));
        }

        return $this->register(new SourceFile(
            $absolutePath,
            $this->resolveDisplayPath($absolutePath),
            $kind ?? FileKind::resolveFromPath($absolutePath),
            $contents,
        ));
    }

    public function register(SourceFile $sourceFile): SourceFile
    {
        $key = Path::buildComparisonKey($sourceFile->path);

        return $this->sources[$key] ??= $sourceFile;
    }

    public function get(string $path): ?SourceFile
    {
        return $this->sources[Path::buildComparisonKey($this->resolveAbsolutePath($path))] ?? null;
    }

    public function resolvePosition(string $path, int $offset): Position
    {
        return $this->requireSource($path)->resolvePositionAt($offset);
    }

    public function createSpan(string $path, int $startOffset, int $endOffset): Span
    {
        return $this->requireSource($path)->createSpan($startOffset, $endOffset);
    }

    private function requireSource(string $path): SourceFile
    {
        $source = $this->get($path);

        if ($source === null) {
            throw new \OutOfBoundsException(sprintf('Source file "%s" is not registered.', $path));
        }

        return $source;
    }

    private function resolveAbsolutePath(string $path): string
    {
        if (Path::isAbsolute($path)) {
            return Path::normalize($path);
        }

        $base = $this->projectRoot ?? getcwd();

        if ($base === false) {
            throw new \RuntimeException('Unable to determine the current working directory.');
        }

        return Path::resolveAbsolute($path, Path::normalize($base));
    }

    private function resolveDisplayPath(string $path): string
    {
        if ($this->projectRoot === null) {
            return $path;
        }

        return Path::resolveRelativeTo($path, Path::normalize($this->projectRoot));
    }
}
