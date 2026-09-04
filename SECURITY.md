# Security Policy

## Supported releases

No ++PHP version has been published yet, so there is currently no publicly supported release. After `2026.3.1-rc-2` is published, the current Stable line and explicitly supported release candidates will receive security fixes. Superseded prereleases may require upgrading.

## Threat model

The compiler treats project and dependency source as untrusted data. It must not execute that source during analysis. Security-sensitive categories include project or dependency code execution, path traversal, unsafe symlink traversal, output or cache escape, build-transaction corruption, portable-index trust bypass, secret leakage, release artifact tampering, and subprocess argument or environment injection.

## Reporting privately

Use [GitHub private vulnerability reporting](https://github.com/atatusoft-ltd/ppphp-src/security/advisories/new) as the preferred channel when it is available. Do not publish unpatched vulnerability details in a public issue. If the private reporting form is unavailable, contact a repository maintainer without disclosing the issue publicly.

Include the affected compiler version and platform, impact, minimal reproduction, relevant configuration, expected and observed behavior, and any proposed mitigation. Remove tokens, credentials, personal data, and other secrets from reproductions.

Maintainers will acknowledge reports, validate impact, coordinate a fix and disclosure window, and credit reporters when requested and appropriate. Timelines depend on severity and reproduction quality; no fixed response-time guarantee is implied.
