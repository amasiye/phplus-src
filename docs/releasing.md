# Releasing ++PHP

Every public version is immutable. A canonical tag has no `v` prefix and must never be moved or reused.

The versioned file in `docs/releases/` is published verbatim as the GitHub release body. Write it for users: explain benefits, installation, requirements, compatibility, limitations, and feedback paths. Keep planning stages, implementation milestones, and validation-process details in this maintainer guide and other engineering documentation.

Public release documentation describes shipped user-visible behavior, not the internal stage, prompt, agent, merge, or acceptance process that produced it. Stage terminology belongs in maintainer plans, decisions, RFCs, and release runbooks. README files, changelogs, release notes, getting-started and migration guides, security policies, package metadata, website copy, and marketplace copy must remain release-oriented.

## Stage 14A — Candidate preparation

1. Start from the latest clean `develop` and verify the baseline CI.
2. Confirm the intended version has no local/remote tag or GitHub release.
3. Update `Compiler::VERSION`, release metadata, schema `$id`, public documentation, and version-bearing fixtures.
4. Run `composer validate --strict`, the locked install, `composer check`, `composer verify:distribution`, the web-spike build, CLI smoke, identity gates, and `git diff --check`.
5. Build release assets twice with the same explicit 40-hex source commit and verify byte identity.
6. Merge the prepared changes through the normal branch policy. Preparation does not create a tag or release.

## Stage 14B — Public RC publication and field validation

1. Confirm `https://ppphplang.org` is publicly reachable and set the repository homepage where needed.
2. Enable GitHub private vulnerability reporting.
3. Register `atatusoft-ltd/ppphp-src` on Packagist and configure the supported GitHub-to-Packagist update path.
4. Merge the prepared commit to `main`; run the complete main-branch validation.
5. Create the exact `2026.3.1-rc-1` tag on that main commit and push it once.
6. Let the tag-driven release workflow verify main ancestry, rebuild assets, and create a GitHub prerelease with the exact tag and notes.
7. Confirm all release assets and `SHA256SUMS` are reachable and verify the immutable schema bytes and URL.
8. Perform a clean public Composer installation using the exact RC constraint, then initialize, check, build, lint, and execute a realistic external project.
9. Verify Packagist exposes the RC and verify marketplace or tooling links separately when they are actually published.
10. Triage RC feedback and decide whether a second RC is required.

## Stage 14C — Stable promotion

After all release blockers are resolved, prepare `2026.3.1` with matching Stable metadata and schema identity. Repeat the full gate, tag the exact main commit, publish a non-prerelease GitHub release, confirm default Composer installation resolves Stable, and complete field smoke. Advance the development identity only after publication. Never replace assets under an existing version.

## Release assets

The bounded asset set is `ppphp.schema.json`, `ppphp-release.json`, `RELEASE_NOTES.md`, `THIRD_PARTY_NOTICES.md`, and `SHA256SUMS`. Composer plus GitHub's immutable tagged source remains the distribution. There is no PHAR, installer, native launcher, standalone vendor archive, signing claim, or self-update client.
