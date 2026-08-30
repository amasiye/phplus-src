# Mixed PHP and ++PHP Projects

> **Status:** Mixed-source discovery, strict project analysis, and complete compiled/copied build trees are implemented.

A project may contain both .php and .ppphp files beneath one or more configured source roots.

- Ordinary .php files retain native PHP behavior, are never rewritten in the source tree, and are copied byte-for-byte into selected build output.
- .php files participate in project syntax and type context, including native and PHPDoc declarations.
- .ppphp files use explicit local-binding and strict declaration rules and compile beneath the configured output directory.
- Configured .stub.php files are global syntax and type context.
- Composer source PSR-4, classmap, files, custom vendor paths, and installed-package metadata are recorded without treating dependencies as project-owned source.

ppphp check selects the complete project by default and accepts a project-owned file or subtree. ppphp build compiles selected .ppphp files and copies selected .php files. A focused .php build copies that one file. There is no configured entry point and no dependency-based tree-shaking.

Parsing, internal semantics, and backend analysis finish for the whole selection before a build writes output. An error in any selected source prevents all selected output writes. A focused valid file is not blocked by an unrelated invalid unselected source, while valid unselected declarations and checked-error contracts remain available as scan context.

Project symbols and the analysis backend resolve cross-file names, arguments, returns, members, properties, nullability, generic PHPDoc, checked errors, configured stubs, and Composer metadata. Ordinary PHP and configured stubs can supply generic templates and @throws contracts; native ++PHP syntax remains authoritative in .ppphp. ++PHP declaration completeness applies only to .ppphp; genuine PHP analysis findings may still be reported for selected .php files.

For runtime loading, `ppphp composer:configure` projects root application mappings from source roots to generated output and preserves the source forms under `extra.ppphp`. The compiler continues to analyze source; Composer loads generated PHP. Dependencies and `vendor/` stay at the project root. See [Composer runtime integration](composer-runtime.md).

Discovery applies exclusions, avoids directory-symlink traversal, deduplicates physical files, and assigns overlapping roots deterministically. Output collisions across compiled and copied sources are diagnosed before any selected output is written.
