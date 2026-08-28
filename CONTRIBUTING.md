# Contributing to ++PHP

++PHP is at an early stage. Read the [MVP end-to-end plan](docs/phplus-mvp-end-to-end-plan.md) before making implementation changes, and target the `develop` branch.

- Keep each change focused on the current development stage.
- Follow the repository's module layout, declaration-directory conventions, and `<Verb><Object>Pass` naming.
- Update Pest tests and documentation whenever behavior changes.
- Do not claim or scaffold behavior that has not been implemented.
- Run `composer check` and `composer validate --strict` before submitting changes.
- Use clear conventional-style commit messages where appropriate, such as `feat(cli): ...`, `fix(config): ...`, or `docs: ...`.

Explain the need and tradeoff for any new dependency in the proposed change.
