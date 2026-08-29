<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Diagnostics;

use Amasiye\Ppphp\Diagnostics\Interfaces\DiagnosticRenderer;

final class JsonRenderer implements DiagnosticRenderer
{
    public function render(DiagnosticBag $diagnostics, bool $includeDebug = false): string
    {
        $items = [];

        foreach ($diagnostics as $diagnostic) {
            $item = [
                'code' => $diagnostic->code->value,
                'severity' => $diagnostic->severity->value,
                'title' => $diagnostic->title,
                'message' => $diagnostic->message,
                'location' => $diagnostic->primary === null
                    ? null
                    : $this->createLocation($diagnostic->primary),
                'related' => array_map(
                    fn (DiagnosticLabel $label): array => [
                        'message' => $label->message,
                        'location' => $this->createLocation($label),
                    ],
                    $diagnostic->related,
                ),
                'help' => $diagnostic->help,
            ];

            if ($includeDebug && $diagnostic->debug !== []) {
                $item['debug'] = $diagnostic->debug;
            }

            $items[] = $item;
        }

        return json_encode([
            'version' => 1,
            'diagnostics' => $items,
            'summary' => [
                'errors' => count($diagnostics->errors),
                'warnings' => count($diagnostics->warnings),
                'notes' => count($diagnostics->notes),
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }

    /** @return array{file: string, range: array{start: array{offset: int, line: int, column: int}, end: array{offset: int, line: int, column: int}}, label: string} */
    private function createLocation(DiagnosticLabel $label): array
    {
        $span = $label->span;

        return [
            'file' => $span->sourceFile->displayPath,
            'range' => [
                'start' => [
                    'offset' => $span->start->offset,
                    'line' => $span->start->line,
                    'column' => $span->start->column,
                ],
                'end' => [
                    'offset' => $span->end->offset,
                    'line' => $span->end->line,
                    'column' => $span->end->column,
                ],
            ],
            'label' => $label->message,
        ];
    }
}
