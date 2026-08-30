# Command-Line Interface

The `ppphp` executable is available through `vendor/bin/ppphp` for a project installation and as `ppphp` for a Composer global installation.

## Commands

| Command | Purpose |
| --- | --- |
| `ppphp init` | Create `ppphp.json` and the configured compiler-owned directories. Existing files are preserved unless `--force` is supplied. |
| `ppphp check [path]` | Check all project sources, one source subtree, or one project-owned `.php` or `.ppphp` file. |
| `ppphp build [path]` | Check the selected source set and atomically commit its mixed PHP output. |
| `ppphp clean [--dry-run]` | Remove only validated compiler-owned output and cache paths. |
| `ppphp composer:configure [--dry-run]` | Preview or write Composer runtime mappings that target generated output. |
| `ppphp dump:ast <path>` | Write one source file's syntax tree to standard output. |
| `ppphp editor:definition` | Serve the bounded editor definition protocol over standard input/output. |
| `ppphp editor:semantic-tokens` | Serve the bounded semantic-token protocol over standard input/output. |

Run `ppphp list`, `ppphp --help`, or `ppphp <command> --help` for the installed command surface.

## Project options

Human-facing project commands accept:

- `--working-directory=<path>` to select the project root;
- `--config=<path>` to select a configuration path relative to that root;
- `--format=console|json` to select diagnostic output;
- `--debug` to include normalized implementation details;
- `--ansi` or `--no-ansi` to control console decoration; and
- `--no-interaction` for automation and closed-standard-input environments.

Commands do not prompt. Omitting a source path selects the complete project for `check` and `build`. A directory selects its recursive project-owned sources. A file selects that source while valid unselected sources continue to provide semantic context.

## Output contract

Console diagnostics use standard error when a separate error channel is available. Success messages, build summaries, AST data, and editor responses use standard output. JSON mode writes one document to standard output with no diagnostic text on standard error. See [diagnostics](diagnostics.md) for ordering, color precedence, source ranges, debug behavior, and the code catalog.
