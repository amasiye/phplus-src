<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Versioning;

final readonly class ReleaseNotesValidator
{
    /** @var list<string> */
    private const array INTERNAL_TERMS = [
        'Stage ',
        'MVP',
        'post-MVP',
        'compiler-owned',
        'compilerCore',
        'PHPStan',
        'parity',
        'promotion-readiness',
        'completion gate',
    ];

    /** @return list<string> */
    public function validate(string $releaseNotes, string $version, string $targetPhpVersion): array
    {
        $failures = [];
        $requiredText = [
            $version => 'release notes do not state the compiler version',
            sprintf(
                'composer require --dev atatusoft-ltd/ppphp-src:%s',
                $version,
            ) => 'release notes do not show the exact RC installation command',
            'ordinary PHP ' . $targetPhpVersion => 'release notes do not explain the generated runtime output',
        ];

        foreach ($requiredText as $text => $message) {
            if (!str_contains($releaseNotes, $text)) {
                $failures[] = $message;
            }
        }

        foreach (self::INTERNAL_TERMS as $internalTerm) {
            if (stripos($releaseNotes, $internalTerm) !== false) {
                $failures[] = sprintf('release notes contain internal implementation language: %s', $internalTerm);
            }
        }

        return $failures;
    }
}
