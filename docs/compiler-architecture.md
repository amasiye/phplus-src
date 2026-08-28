# Compiler Architecture

> **Status:** Project discovery, mixed source sets, and the ordinary PHP frontend are implemented.

PHPlus is organized as a staged source compiler whose eventual output is ordinary PHP:

```text
configuration and discovery
    -> PHP and PHPlus parsing
    -> semantic validation
    -> production lowering
    -> ordinary PHP
```

The current implementation covers the first two steps for ordinary PHP 8.4 syntax. It discovers a project before reading source contents, selects the command scope, parses all selected files and configured stubs, and then performs byte-preserving emission for selected `.phplus` files.

## Project Loading

`ProjectConfigLoader` reads `phplus.json` from an explicit project root. Relative paths resolve from that root and are stored as normalized absolute paths. Unknown properties, invalid types, duplicate entries, unsupported target versions, missing source directories, unsafe traversal, and overlapping compiler-owned paths produce structured diagnostics.

`FileDiscovery` recursively indexes case-insensitive `.php` and `.phplus` extensions beneath every configured source root. It applies exclusions before selection, does not descend directory symlinks, rejects file symlinks whose target escapes the owning source root, and deduplicates files by physical identity. Overlapping source roots assign a file to the most-specific root deterministically. Discovery records metadata only; `SourceManager` reads contents after command selection.

`Project` owns the immutable configuration, deterministic `SourceSet`, Composer context, configured stubs, dependency graph, and shared source manager. The path-keyed dependency graph is cycle tolerant and provides the foundation for later semantic dependency edges. It does not choose build roots or perform tree-shaking.

## Command Selection

| Command | No path | Directory | File |
| --- | --- | --- | --- |
| `check` | Check all project `.php` and `.phplus` files | Check the recursive subtree | Check one `.php` or `.phplus` file |
| `build` | Check all files; emit all `.phplus` files | Check the subtree; emit its `.phplus` files | Check and emit one `.phplus` file |
| `dump:ast` | Invalid | Invalid | Dump one `.php` or `.phplus` AST |

An explicit `.php` file is not a build target. Focused commands ignore syntax failures in unselected project sources, while configured stubs remain global context. Paths outside source ownership and paths excluded by configuration are rejected.

## Composer and Stub Context

`ComposerResolver` reads project `composer.json` metadata without executing autoload files. It normalizes root `autoload` and `autoload-dev` PSR-4, classmap, and files entries; respects a custom `vendor-dir`; and reads installed-package autoload metadata from Composer's `installed.json`. Classmap directory expansion includes PHP files only. Malformed metadata produces `P6xxx` diagnostics.

`StubLoader` recursively discovers `.stub.php` files under configured stub roots without following directory symlinks. Stubs are parsed as ordinary PHP for every `check` and `build`, including focused commands. Stub metadata and Composer metadata provide analysis context but do not make dependency packages project-owned build inputs.

## Parsing and Emission

`PhplusParser` implements the compiler parser contract through `PhpParserAdapter`, using the Composer-locked PHP-Parser API and an explicit PHP 8.4 grammar. A successful `ParsedFile` retains the immutable source, AST, original token stream, comments, and line, file-offset, and token-position attributes.

PHP-Parser errors are collected and mapped to `P1001` diagnostics against the original `.php`, `.phplus`, or `.stub.php` file. Parser offsets use the source model's half-open spans. Implementation details appear only with `--debug`.

Before any build output is written, every selected source and every configured stub is parsed. Output planning maps each selected `.phplus` file from its owning source-root-relative path to a `.php` path beneath the configured output root. Collisions are diagnosed before emission. A focused build is blocked only when its selected emission participates in a collision.

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

The frontend still accepts only ordinary PHP syntax. Stage 3 does not add an entry-point model, dependency-driven source selection, PHPStan execution against user projects, semantic analysis, PHPlus syntax, production lowering, source maps, manifests, incremental builds, or atomic whole-project replacement. The [MVP end-to-end plan](phplus-mvp-end-to-end-plan.md) defines those later stages.
