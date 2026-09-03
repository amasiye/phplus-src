<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Versioning;

final readonly class ReleaseNotesValidator
{
    private const string STABLE_STATUS_CLAIM_PATTERN = '/
        \b(?:(?:this|the)\s+(?:candidate|version|release)\s+(?:is|became|becomes|has\s+become)|this\s+is)
        \s+(?:now\s+)?(?:a\s+|the\s+)?Stable(?:\s+release)?\b
        |
        \bStable(?:\s+release)?\s+(?:is|was|became|becomes|has\s+become)
        \s+(?:now\s+)?(?:available|published|released)\b
        |
        \b(?:published|released|available)\s+as\s+(?:a\s+|the\s+)?Stable(?:\s+release)?\b
    /ix';

    /** @return list<string> */
    public function validate(string $releaseNotes, string $version, string $targetPhpVersion): array
    {
        $failures = (new DocumentationPolicy())->validatePublic(
            'docs/releases/' . $version . '.md',
            $releaseNotes,
        );
        $requiredText = [
            $version => 'release notes do not state the compiler version',
            sprintf(
                'composer require --dev atatusoft-ltd/ppphp-src:%s',
                $version,
            ) => 'release notes do not show the exact RC installation command',
            'ordinary PHP ' . $targetPhpVersion => 'release notes do not explain the generated runtime output',
            '## Requirements' => 'release notes do not contain a requirements section',
            'PHP `^8.4`' => 'release notes do not state the compiler PHP requirement',
            'Composer 2' => 'release notes do not state the Composer requirement',
            '512 MiB' => 'release notes do not state the compiler memory requirement',
            '## Major Features' => 'release notes do not describe the major user-visible features',
            '## Known Limitations' => 'release notes do not contain a known-limitations section',
            'Atatusoft\\Ppphp' => 'release notes do not state the canonical compiler namespace',
        ];

        foreach ($requiredText as $text => $message) {
            if (!str_contains($releaseNotes, $text)) {
                $failures[] = $message;
            }
        }

        if (stripos($releaseNotes, 'release candidate') === false) {
            $failures[] = 'release notes do not identify the release as a release candidate';
        }

        if (preg_match(self::STABLE_STATUS_CLAIM_PATTERN, $releaseNotes) === 1) {
            $failures[] = 'release notes incorrectly claim Stable release status';
        }

        return $failures;
    }
}
