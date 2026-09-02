<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis\Parity;

enum OracleDisagreement: string
{
    case CompilerGap = 'compilerGap';
    case BackendGap = 'backendGap';
    case LanguagePolicyDifference = 'languagePolicyDifference';
    case OptionalLint = 'optionalLint';
    case Supplemental = 'supplemental';
    case FixtureError = 'fixtureError';
}
