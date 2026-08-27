# Compiler Architecture

> **Status:** Planned architecture. Stage 0 provides configuration and scaffolding only.

PHPlus will compile PHP-shaped `.phplus` source to ordinary PHP in a staged pipeline:

```text
configuration and discovery
    -> token-aware PHPlus extension parsing
    -> normalized PHP and PHP AST
    -> PHPlus semantic passes
    -> replaceable PHP analysis backend
    -> production lowering
    -> ordinary PHP, source maps, and manifest
```

The frontend will have two layers. PHPlus owns the added syntax—bindings, generics, `throws`, and `when`—including exact source spans. `nikic/php-parser` will own ordinary PHP parsing and printing. Regular expressions must not drive source transformation; the extension layer must understand tokens, nesting, comments, strings, interpolation, heredoc, and context.

PHPlus semantics and source mappings remain compiler-owned. PHPStan will initially provide replaceable whole-project PHP analysis, but it will not define the language. Production output must be deterministic, contain no PHPlus syntax, pass `php -l`, and require no compiler runtime.

See the [PHPlus MVP end-to-end plan](phplus-mvp-end-to-end-plan.md) for the authoritative pipeline, stage boundaries, and acceptance criteria.
