# Editor Protocol

The compiler owns semantic editor queries so every editor observes the same ++PHP project model. Editor adapters must not infer symbol identity from text.

## Definition Request

`ppphp editor:definition --working-directory <project> --format=json` reads one bounded JSON request from standard input. Version 1 accepts the current unsaved contents of one project-owned `.ppphp` document and a UTF-8 byte offset:

~~~json
{
  "version": 1,
  "document": {
    "path": "/project/src/index.ppphp",
    "contents": "<?php\nPerson $person = new Person();\n"
  },
  "position": {
    "offset": 6
  }
}
~~~

Documents are limited to two mebibytes, paths to 4096 bytes, and offsets to the inclusive range from zero through the document byte length. The path must resolve to a source owned by the loaded project. The compiler substitutes the supplied contents for that document while it parses the project; it never writes the buffer to disk.

## Definition Response

The command returns a versioned envelope. A resolved symbol contains a stable project identity, semantic kind, declaration range, and precise name-selection range:

~~~json
{
  "version": 1,
  "definition": {
    "symbolId": "type:my\\app\\person",
    "kind": "class",
    "location": {
      "file": "src/Person.ppphp",
      "range": {
        "start": { "offset": 25, "line": 5, "column": 1 },
        "end": { "offset": 84, "line": 8, "column": 2 }
      },
      "selectionRange": {
        "start": { "offset": 31, "line": 5, "column": 7 },
        "end": { "offset": 37, "line": 5, "column": 13 }
      }
    }
  },
  "error": null
}
~~~

Offsets are zero-based UTF-8 bytes and ranges are half-open. Lines and columns follow the compiler source model: one-based lines and Unicode-code-point columns. Editor clients should use offsets to convert into their protocol's coordinate system.

No match is a successful response with `definition: null`. Invalid requests, projects, or document ownership return a non-zero status and a structured `error`. Unknown protocol versions fail closed.

## Resolution Semantics

Definition resolution uses the compiler's complete project symbol table, resolved namespace imports, declared types, local bindings, and inheritance graph. Version 1 resolves:

- classes, interfaces, traits, enums, and ++PHP source type references;
- namespaced, aliased, and imported functions;
- methods and properties through classes, parents, interfaces, and traits;
- `$this`, typed locals, parameters, and closure parameters;
- chained method returns and property types, including applied generic substitutions such as `Box<Person>::getValue(): T`; and
- local and parameter reads back to their declarations.

Symbol IDs are case-normalized and stable for project declarations: `type:<fqn>`, `function:<fqn>`, `method:<owner>::<name>`, and `property:<owner>::$<name>`. Local and parameter identities additionally include their owning source or callable.

The query performs no lowering, PHPStan execution, cache mutation, or output writes. Recoverable syntax errors in unrelated files do not disable navigation. An incomplete target that cannot produce an AST returns no definition rather than guessing.
