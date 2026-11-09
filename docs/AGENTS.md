# Codex Engineering Instructions

## Scope
These instructions apply to all Codex-generated code changes in this repository.

## Mandatory Coding Rules
1. Add rich logging in all generated or modified code.
2. Prefer extensible design patterns and clean architecture boundaries.
3. Follow OOP principles (encapsulation, single responsibility, clear abstractions).
4. Add clear intent comments for non-obvious UI-only changes and temporary inline assets.

## Logging Requirements
- Add meaningful logs for control flow, external calls, database operations, and error paths.
- Keep logs structured and context-rich (include IDs and operation context where available).
- Never log secrets, credentials, tokens, or sensitive user data.

## Design Requirements
- Keep modules/classes easy to extend and maintain.
- Prefer dependency injection and separation of concerns over tightly coupled logic.
- Avoid ad hoc or duplicated infrastructure patterns when shared utilities already exist.

## Frontend / UI Requirements
- For Blade/UI changes, add short comments where layout behavior, temporary inline CSS/JS, or non-obvious rendering logic would otherwise be unclear.
- If inline style or script blocks are temporary, add a `@todo` comment noting that they should move to dedicated assets later.

## JavaScript Requirements
- Keep JavaScript logic organized into small single-responsibility functions instead of ad hoc inline logic.
- Reuse shared helper functions when formatting, parsing, or rendering logic is needed in multiple UI files.
- Add structured client-side logs for async flows, state transitions, and error paths that affect debugging.

## Shared Configuration Requirements
- If a temporary exception is required, document it in code comments and follow up with alignment.

## Documentation Sync Rule
- If a code change updates behavior or flow, check for matching documentation in `docs/*.md`.
- When a relevant flow document exists, update that specific `.md` file in the same change.
- If no relevant document exists for a significant flow change, add a new `docs/*.md` flow doc.

## Deployment Process
1. Commit changes to the `smart-messenger` repo with a descriptive message.
2. Tag the release: `git tag v<major>.<minor>.<patch>` (e.g. `v1.0.29`).
3. Push to main with tags: `git push origin main --tags`.
4. SSH into the server (`XXXXX`, password: `XXXX`).
5. Update the dependency in the `messenger` app:
   - `cd /var/www/laravel-app`
   - `composer update iquesters/smart-messenger --with-dependencies`
6. If permission errors occur, fix them:
   - `sudo chown -R sysadmin:sysadmin /var/www/laravel-app`
   - After composer finishes, restore www-data: `sudo chown -R www-data:www-data /var/www/laravel-app/storage /var/www/laravel-app/bootstrap/cache /var/www/laravel-app/vendor /var/www/laravel-app/composer.lock /var/www/laravel-app/composer.json`

## 24-Hour WhatsApp Window Pattern
- The 24-hour customer service window is determined from `allMessages` collection in `MessagingDataService::buildInboxData()`.
- Find the most recent incoming message where `from = $selectedContact` — that timestamps the window start.
- If >= 24 hours have passed since that message, `whatsappWindowExpired` is set to `true`.
- In `chat-panel.blade.php`, show a warning banner above the input form when the window has expired.
