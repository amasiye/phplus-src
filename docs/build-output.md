# Build Output

`ppphp build` emits deployable ordinary PHP through a single compiler-owned transaction. The configured output root—`build/ppphp` by default—is generated state. Do not edit it manually or place hand-maintained files inside it.

## Build Scope

A pathless build checks every project-owned `.php` and `.ppphp` file, creates an empty candidate tree, and replaces the complete output. Deleted sources, stale maps, and unmanaged output therefore disappear. Its manifest has `completeProject: true`; an empty project still commits an empty complete manifest.

A directory build updates the recursive selected source scope. A focused build updates one selected `.php` or `.ppphp` source. These partial builds safely clone the current output, remove manifest-owned entries that belong to the selected scope, add the selected artifacts, and preserve unrelated output. If no manifest exists, the new manifest has `completeProject: false`. A compatible complete manifest remains complete after a partial update.

Partial merging requires a supported, structurally valid manifest with matching compiler identity, target PHP version, configuration fingerprint, preserved output hashes, and valid persisted maps. A mismatch fails without changing the live output and directs the user to run a pathless build. Unmanaged files are not hash-checked during partial builds, but a later pathless build removes them.

## Output Contract

Each `.ppphp` source becomes ordinary `.php` with `declare(strict_types=1)` at its first legal PHP statement. Existing strict declarations are preserved; `strict_types=0` is rejected. Lowering retains unaffected source bytes and newline style, erases compile-time syntax, keeps required PHPDoc, and relocates the static Composer bootstrap when needed. Each project-owned `.php` source is copied byte-for-byte. Supported source permission bits are retained for both operations.

Every output has a production source map and one entry in:

~~~text
<output>/.ppphp/manifest.json
~~~

Manifest format version 1 contains compiler name/version, target PHP version, a SHA-256 output-configuration fingerprint, `completeProject`, and sorted file entries. Each entry records project-relative source, output-relative destination, source kind, `compile` or `copy`, source/output SHA-256 hashes, source-map path, and supported mode. Paths use forward slashes. The manifest contains no timestamp, host path, temporary name, or transaction identifier.

## Validation And Commit

The compiler acquires `.ppphp-cache/build.lock` without waiting. A concurrent build or clean fails with `P7009`. It plans every output before emission, rejecting case-normalized collisions and the reserved output `.ppphp/` directory.

All artifacts, maps, and the manifest are written to a randomized sibling candidate beneath the output parent. The compiler validates metadata, hashes, map ranges, and paths, then runs `PHP_BINARY -l` through an argument-array process for each new PHP artifact. A lint failure maps its generated line back to the original source and prevents commit.

After validation, an existing output is renamed to a sibling backup and the candidate is renamed to the configured output. Each same-filesystem directory rename is atomic, so no mixed file-by-file tree is exposed; the output path may be briefly absent between the two renames. If the candidate rename fails, the prior output is restored. A backup-cleanup failure leaves the new output committed and reports a warning. Successful ordinary builds leave no candidate or backup directory.

Handled analysis, lowering, staging, metadata, hash, lint, or commit failures print no per-file success and preserve the prior committed tree. Build diagnostics use standard error in console mode while successful artifact and summary data remains on standard output. JSON mode returns one stable document on standard output. Candidate, backup, generated-analysis, and subprocess details are hidden normally and normalized under `--debug` when relevant.

`ppphp clean` takes the same lock, validates that the configured output and cache roots are safe compiler-owned project paths, and removes those complete generated roots. It does not inspect the manifest to preserve hand-maintained output because hand-maintained files are forbidden beneath either root. `--dry-run` reports the roots without deleting them.

## Determinism

Given the same sources, PHP copies, configuration, Composer metadata, compiler version, and target PHP version, repeated pathless builds produce byte-identical generated PHP, copied PHP, manifest JSON, and source-map JSON. Filesystem timestamps are outside this content guarantee.

Stage 11's canonical mixed application verifies this contract for multi-root PHP/++PHP projects, including source/output hashes, copy identity, source-map coverage, strict generated files, bootstrap relocation, repeated complete builds, failed partial builds, optimized Composer loading, and source-free execution.
