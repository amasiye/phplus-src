# Security Policy

## Supported releases

Security support begins when the first public release is published. During Stage 14A, `2026.3.1-rc-1` is prepared but not published. After publication, the current Stable line and explicitly supported RCs will receive security fixes; superseded prereleases may require upgrading.

## Threat model

The compiler treats project and dependency source as untrusted data. It must not execute that source during analysis. Security-sensitive categories include project or dependency code execution, path traversal, unsafe symlink traversal, output or cache escape, build-transaction corruption, portable-index trust bypass, secret leakage, release artifact tampering, and subprocess argument or environment injection.

## Reporting privately

Use [GitHub private vulnerability reporting](https://github.com/atatusoft-ltd/ppphp-src/security/advisories/new) as the preferred channel. Do not publish unpatched vulnerability details in a public issue. Enabling private vulnerability reporting is a manual Stage 14B repository prerequisite and must be confirmed before publication.

Include the affected compiler version and platform, impact, minimal reproduction, relevant configuration, expected and observed behavior, and any proposed mitigation. Remove tokens, credentials, personal data, and other secrets from reproductions.

Maintainers will acknowledge reports, validate impact, coordinate a fix and disclosure window, and credit reporters when requested and appropriate. Timelines depend on severity and reproduction quality; no fixed response-time guarantee is implied.
