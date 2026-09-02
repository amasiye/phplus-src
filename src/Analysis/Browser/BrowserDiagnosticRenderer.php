<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis\Browser;

use Atatusoft\Ppphp\Diagnostics\DiagnosticBag;
use Atatusoft\Ppphp\Diagnostics\JsonRenderer;

final readonly class BrowserDiagnosticRenderer
{
    public function __construct(private JsonRenderer $renderer = new JsonRenderer()) {}

    /** @return array{version: int, diagnostics: list<mixed>, summary: array{errors: int, warnings: int, notes: int}} */
    public function render(DiagnosticBag $diagnostics): array
    {
        $payload = json_decode($this->renderer->render($diagnostics), true, flags: JSON_THROW_ON_ERROR);

        if (
            !is_array($payload)
            || !isset($payload['version'], $payload['diagnostics'], $payload['summary'])
            || !is_int($payload['version'])
            || !is_array($payload['diagnostics'])
            || !array_is_list($payload['diagnostics'])
            || !is_array($payload['summary'])
            || !isset(
                $payload['summary']['errors'],
                $payload['summary']['warnings'],
                $payload['summary']['notes'],
            )
            || !is_int($payload['summary']['errors'])
            || !is_int($payload['summary']['warnings'])
            || !is_int($payload['summary']['notes'])
        ) {
            throw new \LogicException('The compiler diagnostic renderer returned an invalid protocol payload.');
        }

        return [
            'version' => $payload['version'],
            'diagnostics' => $payload['diagnostics'],
            'summary' => [
                'errors' => $payload['summary']['errors'],
                'warnings' => $payload['summary']['warnings'],
                'notes' => $payload['summary']['notes'],
            ],
        ];
    }
}
