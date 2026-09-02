<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Diagnostics;

use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticStatus;

final class DiagnosticValidator
{
    public function validate(DiagnosticBag $diagnostics): DiagnosticBag
    {
        foreach ($diagnostics as $diagnostic) {
            $definition = DiagnosticCatalog::definition($diagnostic->code);

            if ($definition->status !== DiagnosticStatus::Active) {
                throw new \LogicException(sprintf(
                    'Diagnostic code %s is reserved and cannot be processed.',
                    $diagnostic->code->value,
                ));
            }

            if (
                $definition->title === ''
                || trim($diagnostic->message) === ''
                || $diagnostic->help === null
                || trim($diagnostic->help) === ''
            ) {
                throw new \LogicException(sprintf(
                    'Diagnostic code %s has incomplete user-facing metadata.',
                    $diagnostic->code->value,
                ));
            }
        }

        return $diagnostics;
    }
}
