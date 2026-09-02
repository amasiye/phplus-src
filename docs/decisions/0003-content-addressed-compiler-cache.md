# 0003: Content-Addressed Compiler Cache And Durable Output Recovery

- Status: Accepted
- Date: 2026-09-02

## Context

Repeated ++PHP checks and builds previously repeated parsing, semantic analysis, isolated workspace preparation, PHPStan, and lowering. The public quarterly CalVer version can remain unchanged across development commits, so it cannot uniquely identify cached compiler evidence. The removable cache root also could not safely own the operation lock, and two directory renames alone did not provide enough durable evidence to recover a process interruption.

## Decision

Use content-derived operation keys built from project-relative input snapshots and a separate path-independent `CompilerBuildIdentity`. Store records as versioned canonical JSON and large immutable values as SHA-256-addressed blobs; never persist PHP serialization or executable objects. Keep compiler-core, supplemental PHPStan, complete artifact, and localized artifact evidence distinct. Treat malformed or missing evidence as a safe miss and invalidate conservatively when dependency impact is uncertain.

Commit blobs before records, use same-directory atomic replacement, validate all hashes and paths on read, impose internal byte/count/depth limits, and prune only unreferenced inactive evidence. Coordinate shared checks and exclusive build/clean operations with the project-root `.ppphp-operation.lock`, which survives cache detachment.

Record build output transitions in a project-relative, atomically replaced journal. Candidate and previous-output trees carry transaction-bound markers. Recovery validates manifests, artifact hashes, and source maps, then deterministically rolls back a valid previous tree or rolls forward a valid committed candidate. Directory names alone never authorize deletion.

## Consequences

Exact warm operations can avoid compiler and backend work without changing normal output. Missing output can be reconstructed from complete cached artifacts but is linted before commit. Body-only edits can reuse unaffected artifacts; public declaration changes invalidate them conservatively. Cache deletion remains safe, corruption cannot fabricate success, and interrupted builds have deterministic next-operation recovery.

The design adds internal formats and maintenance limits, but not public cache controls. PHPStan remains required and normal operations still require supplemental evidence. The public `dev-2026.3.1` identity is unchanged.

## Alternatives Rejected

- Public version alone: development builds can differ without a CalVer change.
- PHP object serialization: unsafe, non-portable, and coupled to implementation layout.
- Timestamp or Git-based keys: path/environment dependent and unavailable in release archives.
- A lock inside `.ppphp-cache`: clean can detach the lock from active writers.
- Deleting stage/backup name patterns: names are not ownership evidence.
- Optimistic dependency invalidation: uncertain reuse can produce false success.
