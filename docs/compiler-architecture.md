# Compiler Architecture

> **Status:** Project foundations are implemented. Source parsing and compilation are not.

PHPlus is organized as a staged source compiler whose eventual output is ordinary PHP:

```text
configuration and discovery
    -> PHP and PHPlus parsing
    -> semantic validation
    -> production lowering
    -> ordinary PHP
```

Only the foundation used by those stages exists today.

## CLI and Project Configuration

The CLI registers `init`, `check`, `build`, `clean`, and `dump:ast`. `init` and `clean` are functional. The remaining commands validate the project and their inputs, then return diagnostic `P0010` because no compiler frontend exists yet.

`ProjectConfigLoader` reads `phplus.json` from an explicit project root. Relative configuration and project paths resolve from that root and are stored as normalized absolute paths. Unknown properties, invalid types, duplicate entries, unsupported target versions, missing source directories, unsafe traversal, and overlapping compiler-owned paths are rejected with structured diagnostics.

Cleanup validates every output and cache path before deletion. It cannot remove the project root, source roots, stubs, the configuration, or paths outside the project. Recursive deletion is performed in PHP without following directory symlinks.

The Composer binary resolves either the checkout autoloader or Composer's injected autoloader path, so the same entry point works from a checkout and from project-local or global Composer installations.

## Source Model

`SourceFile` keeps immutable contents and precomputed line starts. `SourceManager` loads or registers explicit files only; project discovery is not part of the current implementation.

Positions use:

```text
byte offset: zero-based
line:        one-based
column:      one-based Unicode code points
```

CRLF is one logical line break. Spans are half-open ranges (`[start, end)`), may be empty, and may end at the source length. Both endpoints must belong to the same source file.

## Diagnostics

Diagnostics carry a stable code, severity, title, message, optional primary and related source labels, optional help, and debug metadata. Console rendering includes source excerpts and underlines. JSON rendering uses a versioned envelope with exact offsets, lines, columns, and severity totals. Debug metadata is emitted only when `--debug` is requested.

Code families are reserved as follows:

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

The repository does not yet parse PHP or PHPlus, discover project source trees, run PHPStan against user projects, perform semantic analysis, lower language features, or emit PHP. The [MVP end-to-end plan](phplus-mvp-end-to-end-plan.md) defines those later stages.
