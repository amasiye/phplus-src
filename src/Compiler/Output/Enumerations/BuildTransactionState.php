<?php

declare(strict_types=1);

namespace Amasiye\Ppphp\Compiler\Output\Enumerations;

enum BuildTransactionState: string
{
    case Prepared = 'prepared';
    case PreviousOutputBackedUp = 'previous-output-backed-up';
    case CandidateCommitted = 'candidate-committed';
    case Completed = 'completed';
}
