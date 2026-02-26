# Codex Engineering Instructions

## Scope
These instructions apply to all Codex-generated code changes in this repository.

## Mandatory Coding Rules
1. Add rich logging in all generated or modified code.
2. Prefer extensible design patterns and clean architecture boundaries.
3. Follow OOP principles (encapsulation, single responsibility, clear abstractions).

## Logging Requirements
- Add meaningful logs for control flow, external calls, database operations, and error paths.
- Keep logs structured and context-rich (include IDs and operation context where available).
- Never log secrets, credentials, tokens, or sensitive user data.

## Design Requirements
- Keep modules/classes easy to extend and maintain.
- Prefer dependency injection and separation of concerns over tightly coupled logic.
- Avoid ad hoc or duplicated infrastructure patterns when shared utilities already exist.

## Shared Configuration Requirements
- If a temporary exception is required, document it in code comments and follow up with alignment.

## Documentation Sync Rule
- If a code change updates behavior or flow, check for matching documentation in `docs/*.md`.
- When a relevant flow document exists, update that specific `.md` file in the same change.
- If no relevant document exists for a significant flow change, add a new `docs/*.md` flow doc.

