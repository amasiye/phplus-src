# Compiler Architecture

> **Status:** Project foundations and the ordinary PHP single-file frontend are implemented.

PHPlus is organized as a staged source compiler whose eventual output is ordinary PHP:

```text
configuration and discovery
    -> PHP and PHPlus parsing
    -> semantic validation
    -> production lowering
    -> ordinary PHP
```

The current implementation covers configuration, explicit source loading, ordinary PHP parsing, and a source-preserving single-file build.

## CLI and Project Configuration

The CLI registers `init`, `check`, `build`, `clean`, and `dump:ast`. `check`, `build`, and `dump:ast` require one explicit `.phplus` file inside a configured source root. They do not scan directories or discover project source trees.

`ProjectConfigLoader` reads `phplus.json` from an explicit project root. Relative configuration and project paths resolve from that root and are stored as normalized absolute paths. Unknown properties, invalid types, duplicate entries, unsupported target versions, missing source directories, unsafe traversal, and overlapping compiler-owned paths are rejected with structured diagnostics.

Cleanup validates every output and cache path before deletion. It cannot remove the project root, source roots, stubs, the configuration, or paths outside the project. Recursive deletion is performed in PHP without following directory symlinks.

The Composer binary resolves either the checkout autoloader or Composer's injected autoloader path, so the same entry point works from a checkout and from project-local or global Composer installations.

## Ordinary PHP Frontend

`PhplusParser` implements the compiler parser contract and delegates the currently supported grammar to `PhpParserAdapter`. The adapter uses the Composer-locked PHP-Parser API with an explicit PHP 8.4 target. A successful `ParsedFile` retains the immutable `SourceFile`, ordinary PHP AST, original token stream, comments, and line, file-offset, and token-position attributes.

PHP-Parser errors are collected and mapped to `P1001` diagnostics against the original `.phplus` file. Parser offsets are converted into the source model's half-open spans. Normal diagnostics do not expose parser internals; implementation details are available only with `--debug`.

`check` parses without emitting output. `build` parses first, then writes the original source bytes to the corresponding `.php` path below the configured output root. Relative paths below the most-specific matching source root are preserved. Parsing completes before any write, and a temporary-file-and-rename operation protects an existing output from failed writes.

`dump:ast` uses PHP-Parser's deterministic node dumper and includes comments and source-position metadata. JSON mode returns a small versioned wrapper with the project-relative file path and AST text.

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

The frontend accepts only ordinary PHP syntax in `.phplus` files. It does not yet recognize PHPlus-specific syntax, discover project source trees, inspect ordinary `.php` project files, run PHPStan against user projects, perform semantic analysis, lower language features, or pretty-print generated PHP. The [MVP end-to-end plan](phplus-mvp-end-to-end-plan.md) defines those later capabilities.
