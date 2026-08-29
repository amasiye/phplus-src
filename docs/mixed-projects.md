# Mixed PHP and ++PHP Projects

> **Status:** Mixed-source discovery, selection, Stage 5 local-binding analysis, and complete compiled/copied build trees are implemented.

A project may contain both .php and .ppp files beneath one or more configured source roots.

- Ordinary .php files retain native PHP behavior, are never rewritten in the source tree, and are copied byte-for-byte into selected build output.
- .php files participate in project syntax context.
- .ppp files use the Stage 5 explicit local-binding rules and may be emitted beneath the configured output directory.
- Configured .stub.php files are global syntax context.
- Composer PSR-4, classmap, files, custom vendor paths, and installed-package metadata are recorded without treating dependencies as project-owned source.

ppphp check selects the complete project by default and accepts a project-owned file or subtree. ppphp build compiles selected .ppp files and copies selected .php files. A focused .php build copies that one file. There is no configured entry point and no dependency-based tree-shaking.

Parsing and semantic analysis finish for the whole selection before a build writes output. A semantic error in any selected .ppp file prevents all selected output writes. A focused valid file is not blocked by an unrelated unselected source error.

Stage 5 indexes unambiguous parsed function and method signatures only for readonly by-reference call safety. Complete cross-file name, argument, return, member, and PHPDoc analysis remains Stage 6 work.

Discovery applies exclusions, avoids directory-symlink traversal, deduplicates physical files, and assigns overlapping roots deterministically. Output collisions across compiled and copied sources are diagnosed before any selected output is written.
