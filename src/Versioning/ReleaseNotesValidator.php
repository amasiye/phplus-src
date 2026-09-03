<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Versioning;

final readonly class ReleaseNotesValidator
{
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

        if (preg_match('/\b(?:is|published as|available as)\s+(?:the\s+|a\s+)?Stable release\b/i', $releaseNotes) === 1) {
            $failures[] = 'release notes incorrectly claim Stable release status';
        }

        return $failures;
    }
}
