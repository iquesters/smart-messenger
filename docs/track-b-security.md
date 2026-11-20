# Track B — Channel scope and legacy command boundary

Channel management requires explicit tenant administration, validates both the existing resource and target organisation, binds multi-step drafts to actor/resource and rechecks permissions at final submit. Listings exclude foreign-owned/shared resources. Linked integration and workflow metadata cannot select another tenant’s resources.

The old raw /inbox/send and direct handover APIs lack the canonical Inbox ownership/concurrency contract; they are temporarily platform-only. Authorized operators continue using the existing scoped Util operator command APIs, which dispatch through the same Laravel handover mechanisms and WhatsApp sender. Direct handover audit attribution comes from the authenticated actor; caller-supplied mismatching agent_user_id is rejected. No sender, queue, handover-state implementation or feature flag is introduced. Laravel operator users need the scoped Inbox path during this transition; do not silently deploy this restriction without release review.

Requires reviewed Foundation/Organisation v2, PHP 8.3+ and compatible Laravel 12/Testbench 10. No host Composer lock is changed.

## Validation

49 selected tests pass (276 assertions), including actual channel/legacy-route denials, draft/link binding and 45 existing handover/AI-suppression/metadata contracts. The API → existing Laravel sender → Job projector test passes using a disposable shared SQLite database and Meta HTTP fake. Existing broad legacy tests have pre-existing migration-order/stale-signature issues; no production migration was rewritten to hide them.

## Review and release gates

This branch is for review only. [Architecture and API contract](https://github.com/iquesters/chatbot-util/blob/feature/knorai-rbac-account-management/docs/security/TRACK_B_SECURITY_FOUNDATION.md), [complete source inventory](https://github.com/iquesters/chatbot-util/blob/feature/knorai-rbac-account-management/docs/security/LARAVEL_MIGRATION_INVENTORY.md), and [test evidence](https://github.com/iquesters/chatbot-util/blob/feature/knorai-rbac-account-management/docs/security/TEST_EVIDENCE.md).

Track C (chatbot-util #137) owns additive schema/revision IDs. No new Alembic head, historical SQL edit, live migration, production role grant, merge or deployment is included. Coordinate schema verification, tenant assignments, all Util caller service credentials and the host dependency lock before release. Do not merge schema-dependent changes before the reviewed C contract is agreed. PHP remains transitional; final backend parity moves to Python.
