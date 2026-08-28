# Compiler Architecture

> **Status:** Project discovery, mixed source sets, and the token-aware extension frontend are implemented through Stage 4.

++PHP is organized as a staged source compiler whose eventual output is ordinary PHP:

```text
configuration and discovery
    -> PHP and ++PHP parsing
    -> semantic validation
    -> production lowering
    -> ordinary PHP
```

The current implementation discovers and selects the project before reading source contents. Ordinary `.php` files parse directly as PHP 8.4. `.ppp` files pass through extension tokenization, syntax indexing, normalization, and then the same PHP parser adapter.

## Project Loading

`ProjectConfigLoader` reads `phplus.json` from an explicit project root. Relative paths resolve from that root and are stored as normalized absolute paths. Unknown properties, invalid types, duplicate entries, unsupported target versions, missing source directories, unsafe traversal, and overlapping compiler-owned paths produce structured diagnostics.

`FileDiscovery` recursively indexes case-insensitive `.php` and `.ppp` extensions beneath every configured source root. It applies exclusions before selection, does not descend directory symlinks, rejects file symlinks whose target escapes the owning source root, and deduplicates files by physical identity. Overlapping source roots assign a file to the most-specific root deterministically. Discovery records metadata only; `SourceManager` reads contents after command selection.

`Project` owns the immutable configuration, deterministic `SourceSet`, Composer context, configured stubs, dependency graph, and shared source manager. The path-keyed dependency graph is cycle tolerant and provides the foundation for later semantic dependency edges. It does not choose build roots or perform tree-shaking.

## Command Selection

| Command | No path | Directory | File |
| --- | --- | --- | --- |
| `check` | Check all project `.php` and `.ppp` files | Check the recursive subtree | Check one `.php` or `.ppp` file |
| `build` | Check all files; emit all `.ppp` files | Check the subtree; emit its `.ppp` files | Check and emit one `.ppp` file |
| `dump:ast` | Invalid | Invalid | Dump one `.php` or `.ppp` AST |

An explicit `.php` file is not a build target. Focused commands ignore syntax failures in unselected project sources, while configured stubs remain global context. Paths outside source ownership and paths excluded by configuration are rejected.

## Composer and Stub Context

`ComposerResolver` reads project `composer.json` metadata without executing autoload files. It normalizes root `autoload` and `autoload-dev` PSR-4, classmap, and files entries; respects a custom `vendor-dir`; and reads installed-package autoload metadata from Composer's `installed.json`. Classmap directory expansion includes PHP files only. Malformed metadata produces `P6xxx` diagnostics.

`StubLoader` recursively discovers `.stub.php` files under configured stub roots without following directory symlinks. Stubs are parsed as ordinary PHP for every `check` and `build`, including focused commands. Stub metadata and Composer metadata provide analysis context but do not make dependency packages project-owned build inputs.

## Parsing and Emission

`PhplusParser` is a retained internal class name. It implements the two-layer frontend: `PhpToken::tokenize` supplies exact original tokens; the extension parser builds typed source-located nodes; a validated normalization plan masks extension-only syntax; and `PhpParserAdapter` parses the normalized PHP with the Composer-locked PHP-Parser API and explicit PHP 8.4 grammar.

`ParsedFile` retains the original source and token stream, extension syntax index, ordered normalization edits, normalized source, bidirectional source map, normalized PHP AST, and native parser tokens. Extension identities derive deterministically from kind and original half-open byte span.

Normalization is in-memory and length-preserving. Non-newline extension bytes become spaces, while a `when` expression becomes `null` plus padding. CRLF/LF bytes and line count are preserved. Nested edits are owned by the outer edit; accidental partial overlap is rejected. These placeholders are parser-only and are never production lowering.

PHP-Parser errors are collected and mapped through the source map to the original `.php`, `.ppp`, or `.stub.php` file. Valid extension syntax receives its feature-family inactive diagnostic instead of `P1001`. Malformed extension syntax uses `P1008` or `P1009` and takes precedence over inactive diagnostics.

Before any build output is written, every selected source and every configured stub is parsed. Output planning maps each selected `.ppp` file from its owning source-root-relative path to a `.php` path beneath the configured output root. Collisions are diagnosed before emission. A focused build is blocked only when its selected emission participates in a collision.

Emission is deterministic by output path and preserves source bytes exactly. Each file is written through a temporary file and rename. Stage 3 stops at the first write failure; outputs written earlier in the same build may remain.

## Source Model and Diagnostics

`SourceFile` keeps immutable contents and precomputed line starts. Positions use zero-based byte offsets with one-based lines and Unicode-code-point columns. CRLF is one logical line break. Spans are half-open ranges, may be empty, may end at the source length, and cannot cross source files.

Diagnostics carry a stable code, severity, title, message, optional primary and related source labels, optional help, and debug metadata. Console rendering includes source excerpts and underlines. JSON rendering uses a versioned envelope with exact offsets, lines, columns, and severity totals.

```text
P0xxx  configuration and project errors
P1xxx  lexing and syntax
P2xxx  bindings and strict types
P3xxx  generic types
P4xxx  checked errors
P5xxx  when expressions
P6xxx  PHP and Composer interoperability
P7xxx  emission and generated PHP
P9xxx  internal compiler errors
```

## Current Boundary

Stage 4 recognizes syntax only. Typed-local binding semantics remain Stage 5; strict type analysis and PHPStan project integration Stage 6; checked-error semantics Stage 7; generic and typed-array semantics Stage 8; `when` typing and lowering Stage 9; and release hardening, manifests, and production source maps Stage 10. Recognized syntax blocks builds until its activation stage. There is still no entry-point model, dependency-driven selection, production lowering, manifest, incremental build, or atomic whole-project replacement.
