#!/usr/bin/env php
<?php

declare(strict_types=1);

use Atatusoft\Ppphp\Cli\Application;
use Atatusoft\Ppphp\Compiler\Compiler;
use Atatusoft\Ppphp\Versioning\ReleaseMetadataLoader;
use Atatusoft\Ppphp\Versioning\ReleaseVersion;

require dirname(__DIR__) . '/vendor/autoload.php';

/** @return array{expected: ?string, tag: ?string} */
function versionVerificationOptions(array $arguments): array
{
    $options = ['expected' => null, 'tag' => null];

    for ($index = 0; $index < count($arguments); $index++) {
        $argument = $arguments[$index];

        if (!is_string($argument)) {
            throw new InvalidArgumentException('Version verifier options must be strings.');
        }

        $matched = false;

        foreach (array_keys($options) as $name) {
            $prefix = '--' . $name . '=';

            if (str_starts_with($argument, $prefix)) {
                $value = substr($argument, strlen($prefix));
                $matched = true;
            } elseif ($argument === '--' . $name && isset($arguments[$index + 1])) {
                $value = $arguments[++$index];
                $matched = true;
            } else {
                continue;
            }

            if (!is_string($value) || $value === '') {
                throw new InvalidArgumentException(sprintf('The --%s option must be a non-empty string.', $name));
            }

            if ($options[$name] !== null) {
                throw new InvalidArgumentException(sprintf('The --%s option may be supplied only once.', $name));
            }

            $options[$name] = $value;
            break;
        }

        if (!$matched) {
            throw new InvalidArgumentException(sprintf('Unknown version verifier option: %s', $argument));
        }
    }

    return $options;
}

function failVersionVerification(string $message, int $exitCode = 1): never
{
    fwrite(STDERR, 'Version verification failed: ' . $message . "\n");
    exit($exitCode);
}

function requireVersionVerification(bool $condition, string $message): void
{
    if (!$condition) {
        failVersionVerification($message);
    }
}

/** @return array<string, mixed> */
function readVersionJson(string $path): array
{
    $contents = file_get_contents($path);

    if ($contents === false) {
        failVersionVerification(sprintf('could not read %s.', $path));
    }

    $document = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

    if (!is_array($document)) {
        failVersionVerification(sprintf('%s is not a JSON object.', $path));
    }

    return $document;
}

try {
    $options = versionVerificationOptions(array_slice($argv, 1));
    $compilerVersion = ReleaseVersion::parse(Compiler::VERSION);
    $expected = $options['expected'] === null
        ? $compilerVersion
        : ReleaseVersion::parse($options['expected']);
    $tag = $options['tag'] === null
        ? null
        : ReleaseVersion::parse($options['tag']);
} catch (InvalidArgumentException $exception) {
    failVersionVerification($exception->getMessage(), 2);
}

requireVersionVerification($compilerVersion->canonical === Compiler::VERSION, 'Compiler::VERSION is not canonical.');
requireVersionVerification(Compiler::VERSION !== 'development', 'Compiler::VERSION still uses the retired placeholder.');
requireVersionVerification(Application::VERSION === Compiler::VERSION, 'Application::VERSION does not match Compiler::VERSION.');
requireVersionVerification($expected->canonical === Compiler::VERSION, 'the expected version does not match Compiler::VERSION.');

if ($tag !== null) {
    requireVersionVerification($tag->canonical === $expected->canonical, 'the Git tag does not exactly match the expected version.');
    requireVersionVerification($tag->channel === $expected->channel, 'the Git tag channel does not match the expected version channel.');
}

requireVersionVerification($compilerVersion->isPrerelease === !$compilerVersion->isStable, 'release classification is inconsistent.');

$releaseMetadata = (new ReleaseMetadataLoader(dirname(__DIR__)))->load();
requireVersionVerification($releaseMetadata !== null, 'committed release metadata is missing.');
requireVersionVerification($releaseMetadata->version->canonical === Compiler::VERSION, 'release metadata does not match Compiler::VERSION.');
requireVersionVerification($releaseMetadata->tag === Compiler::VERSION, 'release metadata tag does not match Compiler::VERSION.');

$root = dirname(__DIR__);
$fixtures = [
    $root . '/tests/Fixtures/Build/manifest.json' => ['compiler', 'version'],
    $root . '/tests/Fixtures/DependencyIndex/ppphp-dependencies/manifest.json' => ['producer', 'version'],
    $root . '/tests/Golden/Analysis/analyzer-parity.json' => ['compiler', 'version'],
];

foreach ($fixtures as $path => $keys) {
    $value = readVersionJson($path);

    foreach ($keys as $key) {
        $value = is_array($value) ? ($value[$key] ?? null) : null;
    }

    requireVersionVerification($value === Compiler::VERSION, sprintf('%s does not use Compiler::VERSION.', $path));
}

$guidePath = $root . '/docs/versioning.md';
$guide = file_get_contents($guidePath);
requireVersionVerification(is_string($guide), 'docs/versioning.md is missing or unreadable.');
requireVersionVerification(
    str_contains($guide, 'YYYY.Q.R')
        && str_contains($guide, 'YYYY.Q.R-rc-N')
        && str_contains($guide, 'dev-YYYY.Q.R'),
    'docs/versioning.md does not contain all three canonical version forms.',
);
requireVersionVerification(
    preg_match('/\b(?:major|minor|patch)\b/i', $guide) !== 1,
    'docs/versioning.md uses semantic-version field terminology for quarterly components.',
);

$template = readVersionJson($root . '/ppphp.json.dist');
requireVersionVerification(!array_key_exists('$schema', $template), 'the untagged development template must omit $schema.');
requireVersionVerification(is_file($root . '/resources/schema/ppphp.schema.json'), 'the bundled schema artifact is missing.');

fwrite(STDOUT, sprintf(
    "Verified ++PHP version %s (%s%s).\n",
    $compilerVersion->canonical,
    $compilerVersion->channel->value,
    $compilerVersion->isPrerelease ? ', prerelease' : '',
));
