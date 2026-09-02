<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Interop\Composer;

use Amasiye\Ppphp\Analysis\Declaration\DeclarationOrigin;
use Amasiye\Ppphp\Analysis\Declaration\DeclarationReferenceCollector;
use Amasiye\Ppphp\Diagnostics\Diagnostic;
use Amasiye\Ppphp\Diagnostics\DiagnosticBag;
use Amasiye\Ppphp\Diagnostics\DiagnosticLabel;
use Amasiye\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Amasiye\Ppphp\Frontend\Enumerations\ParseMode;
use Amasiye\Ppphp\Frontend\ParsedFile;
use Amasiye\Ppphp\Frontend\PpphpParser;
use Amasiye\Ppphp\Project\ProjectParseResult;
use Amasiye\Ppphp\Source\Enumerations\FileKind;
use Amasiye\Ppphp\Source\SourceFile;
use Amasiye\Ppphp\Support\Path;

/** Loads Composer declarations as data; it never includes or executes package code. */
final class ComposerDependencyDeclarationLoader
{
    private const int MAXIMUM_FILES = 2_048;

    private const int MAXIMUM_BYTES = 16_777_216;

    private const int MAXIMUM_DISCOVERY_ENTRIES = 8_192;

    public function __construct(
        private readonly PpphpParser $parser = new PpphpParser(),
        private readonly DeclarationReferenceCollector $references = new DeclarationReferenceCollector(),
    ) {}

    /** @param iterable<ParsedFile> $projectFiles */
    public function load(ComposerProject $project, iterable $projectFiles): ProjectParseResult
    {
        $diagnostics = new DiagnosticBag();
        $parsedFiles = [];
        $sourceFiles = [];
        $pending = [];
        $owners = [];
        $loaded = [];
        $visitedDirectories = [];
        $discoveryEntries = 0;
        $bytes = 0;
        $projectFiles = is_array($projectFiles) ? $projectFiles : iterator_to_array($projectFiles);

        foreach ($project->dependencies as $package) {
            foreach ([...$package->autoload->files, ...$package->autoload->classmap] as $path) {
                $this->enqueue($pending, $owners, $path, $package);

                if (count($pending) > self::MAXIMUM_FILES) {
                    $this->addLimitDiagnostic($diagnostics);

                    return new ProjectParseResult([], [], $diagnostics);
                }
            }
        }

        while (true) {
            while ($pending !== []) {
                $path = array_shift($pending);

                $key = Path::buildComparisonKey($path);

                if (is_dir($path) && !is_link($path)) {
                    if (isset($visitedDirectories[$key])) {
                        continue;
                    }

                    $visitedDirectories[$key] = true;
                    $entries = $this->directoryEntries($path);

                    if ($entries === null) {
                        $diagnostics->add(new Diagnostic(
                            DiagnosticCode::ComposerDependencySourceNotReadable,
                            sprintf('Composer dependency classmap directory "%s" could not be read.', $this->displayPath($owners[$key], $path)),
                            help: 'Restore the installed package or regenerate Composer installed metadata.',
                        ));
                        continue;
                    }

                    foreach ($entries as $entry) {
                        if (++$discoveryEntries > self::MAXIMUM_DISCOVERY_ENTRIES) {
                            $this->addLimitDiagnostic($diagnostics);

                            return new ProjectParseResult([], [], $diagnostics);
                        }

                        if ((is_dir($entry) && !is_link($entry))
                            || (is_file($entry) && str_ends_with(strtolower($entry), '.php'))) {
                            $this->enqueue($pending, $owners, $entry, $owners[$key]);
                        }
                    }

                    continue;
                }

                if (isset($loaded[$key])) {
                    continue;
                }

                if (count($loaded) >= self::MAXIMUM_FILES) {
                    $this->addLimitDiagnostic($diagnostics);

                    return new ProjectParseResult([], [], $diagnostics);
                }

                $loaded[$key] = true;
                $package = $owners[$key];
                $source = is_file($path) ? @file_get_contents($path) : false;

                if (!is_string($source)) {
                    $diagnostics->add(new Diagnostic(
                        DiagnosticCode::ComposerDependencySourceNotReadable,
                        sprintf('Composer dependency source "%s" could not be read.', $this->displayPath($package, $path)),
                        help: 'Restore the installed package or regenerate Composer installed metadata.',
                    ));
                    continue;
                }

                $bytes += strlen($source);

                if ($bytes > self::MAXIMUM_BYTES) {
                    $this->addLimitDiagnostic($diagnostics);

                    return new ProjectParseResult([], [], $diagnostics);
                }

                $sourceFile = new SourceFile(
                    $path,
                    $this->displayPath($package, $path),
                    FileKind::Php,
                    $source,
                    DeclarationOrigin::ComposerDependency,
                );
                $result = $this->parser->parse($sourceFile, ParseMode::Php);

                if ($result->parsedFile === null || $result->diagnostics->hasErrors) {
                    $first = $result->diagnostics->errors[0] ?? null;
                    $diagnostics->add(new Diagnostic(
                        DiagnosticCode::ComposerDependencyDeclarationInvalid,
                        sprintf('Composer dependency source "%s" could not provide portable declarations.', $sourceFile->displayPath),
                        $first?->primary === null
                            ? null
                            : new DiagnosticLabel($first->primary->span, 'The dependency declaration is invalid here.'),
                        help: 'Install a dependency version compatible with the configured PHP target.',
                    ));
                    continue;
                }

                $parsedFiles[$key] = $result->parsedFile;
                $sourceFiles[$key] = $sourceFile;
            }

            if ($diagnostics->hasErrors) {
                return new ProjectParseResult([], [], $diagnostics);
            }

            $declarations = $this->references->collectDeclarations($parsedFiles);
            $referenced = $this->references->collect([
                ...array_values($projectFiles),
                ...array_values($parsedFiles),
            ]);
            $added = false;

            foreach ($referenced['classes'] as $class) {
                if ($this->containsName($declarations['classes'], $class)) {
                    continue;
                }

                $candidate = $this->resolvePsr4($project->dependencies, $class);

                if ($candidate !== null && !isset($loaded[Path::buildComparisonKey($candidate[0])])) {
                    $this->enqueue($pending, $owners, $candidate[0], $candidate[1]);
                    $added = true;
                }
            }

            if (!$added) {
                break;
            }
        }

        $prefixes = [];

        foreach ($project->dependencies as $package) {
            foreach (array_keys($package->autoload->psr4) as $prefix) {
                $prefixes[$prefix] = true;
            }
        }

        $prefixes = array_keys($prefixes);
        sort($prefixes, SORT_STRING);

        return new ProjectParseResult($parsedFiles, $sourceFiles, $diagnostics, $prefixes);
    }

    /**
     * @param list<string> $pending
     * @param array<string, ComposerPackage> $owners
     */
    private function enqueue(array &$pending, array &$owners, string $path, ComposerPackage $package): void
    {
        $path = Path::normalize($path);
        $key = Path::buildComparisonKey($path);

        if (!isset($owners[$key])) {
            $owners[$key] = $package;
            $pending[] = $path;
        }
    }

    /**
     * @param list<ComposerPackage> $packages
     * @return array{string, ComposerPackage}|null
     */
    private function resolvePsr4(array $packages, string $class): ?array
    {
        $candidates = [];
        $order = 0;

        foreach ($packages as $package) {
            foreach ($package->autoload->psr4 as $prefix => $directories) {
                if ($prefix !== '' && !str_starts_with($class, $prefix)) {
                    continue;
                }

                $relative = substr($class, strlen($prefix));

                foreach ($directories as $directory) {
                    $candidates[] = [
                        'path' => Path::join($directory, str_replace('\\', '/', $relative) . '.php'),
                        'package' => $package,
                        'prefixLength' => strlen($prefix),
                        'order' => $order++,
                    ];
                }
            }
        }

        usort($candidates, static fn (array $left, array $right): int =>
            $right['prefixLength'] <=> $left['prefixLength'] ?: $left['order'] <=> $right['order']);

        foreach ($candidates as $candidate) {
            if (is_file($candidate['path'])) {
                return [$candidate['path'], $candidate['package']];
            }
        }

        return null;
    }

    /** @param list<string> $names */
    private function containsName(array $names, string $candidate): bool
    {
        $candidate = strtolower(ltrim($candidate, '\\'));

        foreach ($names as $name) {
            if (strtolower(ltrim($name, '\\')) === $candidate) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string>|null */
    private function directoryEntries(string $directory): ?array
    {
        $entries = [];

        try {
            foreach (new \DirectoryIterator($directory) as $entry) {
                if (!$entry->isDot()) {
                    $entries[] = Path::normalize($entry->getPathname());
                }
            }
        } catch (\UnexpectedValueException) {
            return null;
        }

        sort($entries, SORT_STRING);

        return $entries;
    }

    private function addLimitDiagnostic(DiagnosticBag $diagnostics): void
    {
        $diagnostics->add(new Diagnostic(
            DiagnosticCode::ComposerDependencyIndexLimitExceeded,
            'The portable Composer dependency declaration context exceeds its resource limit.',
            help: 'Reduce the dependency declaration surface or supply narrower Composer autoload metadata.',
        ));
    }

    private function displayPath(ComposerPackage $package, string $path): string
    {
        $relative = Path::makeRelative($path, $package->installPath);

        return sprintf(
            '<Composer %s>/%s',
            $package->name,
            $relative ?? basename($path),
        );
    }
}
