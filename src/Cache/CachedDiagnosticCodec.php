<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Cache;

use Amasiye\Ppphp\Diagnostics\Diagnostic;
use Amasiye\Ppphp\Diagnostics\DiagnosticBag;
use Amasiye\Ppphp\Diagnostics\DiagnosticLabel;
use Amasiye\Ppphp\Diagnostics\Enumerations\DiagnosticCode;
use Amasiye\Ppphp\Diagnostics\Enumerations\DiagnosticOrigin;
use Amasiye\Ppphp\Project\Project;
use Amasiye\Ppphp\Source\Enumerations\FileKind;
use Amasiye\Ppphp\Source\SourceFile;
use Amasiye\Ppphp\Support\Path;

final readonly class CachedDiagnosticCodec
{
    /** @return list<array<string, mixed>>|null */
    public function encode(DiagnosticBag $diagnostics, Project $project): ?array
    {
        $encoded = [];

        foreach ($diagnostics as $diagnostic) {
            $primary = $this->encodeLabel($diagnostic->primary, $project);

            if ($diagnostic->primary !== null && $primary === null) {
                return null;
            }

            $related = [];

            foreach ($diagnostic->related as $label) {
                $encodedLabel = $this->encodeLabel($label, $project);

                if ($encodedLabel === null) {
                    return null;
                }

                $related[] = $encodedLabel;
            }

            $encoded[] = [
                'code' => $diagnostic->code->value,
                'help' => $diagnostic->help,
                'identity' => $diagnostic->identity,
                'message' => $diagnostic->message,
                'origin' => $diagnostic->origin->value,
                'primary' => $primary,
                'related' => $related,
            ];
        }

        return $encoded;
    }

    /** @param list<mixed> $records */
    public function decode(array $records, Project $project): DiagnosticBag
    {
        $diagnostics = new DiagnosticBag();

        foreach ($records as $record) {
            if (!is_array($record)
                || array_is_list($record)
                || array_keys($record) !== [
                    'code',
                    'help',
                    'identity',
                    'message',
                    'origin',
                    'primary',
                    'related',
                ]) {
                throw new \RuntimeException('A cached diagnostic is malformed.');
            }

            $code = is_string($record['code'] ?? null) ? DiagnosticCode::tryFrom($record['code']) : null;
            $origin = is_string($record['origin'] ?? null) ? DiagnosticOrigin::tryFrom($record['origin']) : null;
            $message = $record['message'] ?? null;
            $help = $record['help'] ?? null;
            $identity = $record['identity'] ?? null;

            if ($code === null
                || $origin === null
                || !is_string($message)
                || ($help !== null && !is_string($help))
                || ($identity !== null && !is_string($identity))) {
                throw new \RuntimeException('A cached diagnostic identity is invalid.');
            }

            $primary = ($record['primary'] ?? null) === null
                ? null
                : $this->decodeLabel($record['primary'], $project);
            $relatedRecords = $record['related'] ?? null;

            if (!is_array($relatedRecords) || !array_is_list($relatedRecords)) {
                throw new \RuntimeException('Cached diagnostic related labels are invalid.');
            }

            $related = [];

            foreach ($relatedRecords as $label) {
                $related[] = $this->decodeLabel($label, $project);
            }

            $diagnostics->add(new Diagnostic(
                $code,
                $message,
                $primary,
                $related,
                $help,
                origin: $origin,
                identity: $identity,
            ));
        }

        return $diagnostics;
    }

    /** @return array<string, mixed>|null */
    private function encodeLabel(?DiagnosticLabel $label, Project $project): ?array
    {
        if ($label === null) {
            return null;
        }

        $source = $label->span->sourceFile;
        $relative = Path::makeRelative($source->path, $project->configuration->projectRoot);

        if ($relative === null
            || $relative === '..'
            || str_starts_with($relative, '../')
            || !is_file($source->path)
            || is_link($source->path)
            || $label->span->start->offset < 0
            || $label->span->end->offset < $label->span->start->offset
            || $label->span->end->offset > $source->length) {
            return null;
        }

        return [
            'end' => $label->span->end->offset,
            'kind' => $source->kind->value,
            'message' => $label->message,
            'path' => str_replace('\\', '/', Path::normalize($relative)),
            'sha256' => 'sha256:' . hash('sha256', $source->contents),
            'start' => $label->span->start->offset,
        ];
    }

    private function decodeLabel(mixed $record, Project $project): DiagnosticLabel
    {
        if (!is_array($record)
            || array_is_list($record)
            || array_keys($record) !== ['end', 'kind', 'message', 'path', 'sha256', 'start']) {
            throw new \RuntimeException('A cached diagnostic label is malformed.');
        }

        $path = $record['path'] ?? null;
        $kind = is_string($record['kind'] ?? null) ? FileKind::tryFrom($record['kind']) : null;
        $hash = $record['sha256'] ?? null;
        $start = $record['start'] ?? null;
        $end = $record['end'] ?? null;
        $message = $record['message'] ?? null;

        if (!is_string($path)
            || Path::isAbsolute($path)
            || str_contains($path, "\0")
            || $kind === null
            || !is_string($hash)
            || preg_match('/^sha256:[a-f0-9]{64}$/D', $hash) !== 1
            || !is_int($start)
            || !is_int($end)
            || !is_string($message)) {
            throw new \RuntimeException('A cached diagnostic label identity is invalid.');
        }

        $absolute = Path::join($project->configuration->projectRoot, $path);

        if (!Path::contains($project->configuration->projectRoot, $absolute)
            || !is_file($absolute)
            || is_link($absolute)) {
            throw new \RuntimeException('A cached diagnostic source is unavailable.');
        }

        $source = $project->sourceManager->load($absolute, $kind);

        if (!hash_equals($hash, 'sha256:' . hash('sha256', $source->contents))
            || $start < 0
            || $end < $start
            || $end > $source->length) {
            throw new \RuntimeException('A cached diagnostic range cannot be rebound safely.');
        }

        return new DiagnosticLabel($source->createSpan($start, $end), $message);
    }
}
