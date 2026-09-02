# Quarterly CalVer And Release Channels

++PHP uses quarterly calendar versions. The current compiler identity is
`dev-2026.3.1`. The version is emitted unchanged by the CLI, manifests,
configuration fingerprints, browser analysis, analyzer parity reports, and
portable dependency metadata.

This public release identity is intentionally separate from
`CompilerBuildIdentity`, a path-independent SHA-256 over executable compiler
inputs, locked dependencies, code-driving resources, and persisted-format
constants. Development checkouts can therefore invalidate cache records, build
manifests, and portable-index producer evidence when implementation changes
while keeping the approved public `dev-2026.3.1` identity. Git state,
documentation, tests, timestamps, host paths, and environment variables are not
build-identity inputs.

## Canonical Forms

| Channel | Form | Example |
| --- | --- | --- |
| Stable | `YYYY.Q.R` | `2026.3.1` |
| Release Candidate | `YYYY.Q.R-rc-N` | `2026.3.1-rc-2` |
| Development | `dev-YYYY.Q.R` | `dev-2026.3.1` |

`YYYY` is the four-digit calendar year, `Q` is a quarter from 1 through 4,
and `R` is the positive release increment within that quarter. `N` is the
positive candidate increment for one exact release core. Neither increment
uses leading zeroes. Channel markers are lowercase, and canonical versions and
tags never have a `v` prefix.

Development and Release Candidate are separate channels. Development is not an
alias for Release Candidate, and forms such as `YYYY.Q.R-dev` or
`dev-YYYY.Q.R-rc-N` are invalid.

Version syntax identifies immutable releases; it does not define compatibility
or breaking-change policy. Those policies require separate decisions.

## Selection

Release selection defaults to Stable. With neither an exact version nor an
explicit channel, only Stable releases are considered and the numerically
newest Stable release is selected.

Release Candidate and Development selection is always explicit, either by
selecting `rc` or `dev` or by requesting one exact canonical version. Supplying
both a channel and an exact version requires them to match. An empty, unknown,
or unavailable channel fails; selection never falls back across channels.

Versions compare numerically by year, quarter, and release increment only when
the caller explicitly requests core comparison. Ordering within Stable and
Development uses that core. Release Candidate ordering uses the core and then
the candidate increment. There is no implicit global ordering among
Development, Release Candidate, and Stable.

## Release Lifecycle

The release increment starts at 1 for each new quarter. A candidate increment
belongs to one exact release core and starts at 1 for a new core. A release may
be abandoned, and a Stable release does not require a preceding Release
Candidate.

Every published version is immutable. Tags match the canonical version exactly:

```text
dev-2026.3.1
2026.3.1-rc-1
2026.3.1
```

GitHub classifies Stable releases as non-prereleases. Release Candidate and
Development releases are prereleases there, while retaining their distinct
++PHP channels.

## Composer Distribution

Stable is the default Composer acquisition channel. Once the public package is
available, the ordinary installation command is:

```bash
composer require --dev atatusoft-ltd/ppphp-src
```

`dev-2026.3.1` is an immutable ++PHP Development-channel release identity.
Composer's `dev-develop` is a rolling branch identity. They are not
interchangeable. Exact Composer commands for immutable Release Candidate or
Development releases are documented only after validation against supported
Composer and real package metadata.

## Schemas And Network Behavior

Every published release carries `ppphp.schema.json` under its exact release tag.
For example, the current Development identity would use this URL only when that
exact release has been published:

```text
https://github.com/atatusoft-ltd/ppphp-src/releases/download/dev-2026.3.1/ppphp.schema.json
```

Stable and Release Candidate schema assets use their exact canonical version in
the same location. Mutable branch, `latest`, and unversioned schema URLs are not
valid release identities. An untagged development checkout continues to omit
the instance-level `$schema` property from `ppphp init` output.

Ordinary compiler commands never check for updates or fetch a release catalog or
schema. Stages 13A–13D are complete and Stage 14 publication is next; no
installer or self-update command exists today.

## Verification

Run `composer verify:version` to validate the current source identity, metadata,
fixtures, and documentation without network access. Release automation may also
validate an exact expected version and tag:

```bash
php tools/verify-version.php --expected=dev-2026.3.1 --tag=dev-2026.3.1
```
