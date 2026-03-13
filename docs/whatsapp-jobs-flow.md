# WhatsApp Jobs Flow

## Scope
- This document describes the WhatsApp-only async flow from webhook controller receive to reply delivery.
- It includes the current chatbot handover behavior (`actions[].id` -> queue -> `ForwardToAgentJob`).

## End-to-End Flow
1. `BaseWHController::handle()` receives webhook.
2. For POST, it dispatches `WhatsAppWHJob` asynchronously and returns HTTP `200 {"status":"ok"}` immediately.
3. `WhatsAppWHJob` classifies webhook:
   - `status_update` -> dispatch `StatusUpdateJob`
   - `new_message` -> validate channel + phone_number_id, then dispatch `NewMessageJob` per message
4. `NewMessageJob` runs:
   - saves inbound message via `SaveMessageHelper`
   - creates/updates contact
   - checks if inbound is an agent reply to previously forwarded message (`context.id` + `forwarded_from`)
   - if agent reply, routes back to original customer via `SendWhatsAppReplyJob` and stops
   - else resolves workflow jobs and dispatches them (fallback default: `ForwardToChatbotJob`)
5. `ForwardToChatbotJob` builds chatbot payload and calls chatbot API (`/api/chat/v1`).
6. On successful chatbot API response, it dispatches `ProcessChatbotResponseJob`.
7. `ProcessChatbotResponseJob`:
   - converts chatbot `messages[]` into `SendWhatsAppReplyJob` instances (text/product)
   - dispatches them as a `Bus::chain(...)` so replies are queued and delivered in the same order as the chatbot response
   - no longer uses `dispatchSync()` or `usleep()` for reply sequencing
   - handles chatbot `actions[]` for handover:
     - finds `queues` by `queues.uid = actions[].id`
     - uses `queues.name` as job class suffix
     - currently allows only `ForwardToAgentJob` for handover path
     - extracts `summary` (`turns`, `ai_summary`)
     - resolves contact from channel organisations + customer identifier
     - persists handover metas on inbound message:
       - `chatbot_handover_action_id`
       - `chatbot_handover_summary`
     - dispatches `ForwardToAgentJob` with handover context
8. `ForwardToAgentJob` sends to active support agents:
   - regular forwarding for normal inbound message
   - handover template for chatbot fallback with:
     - handover reason
     - suggested action
     - contact line
     - last few messages
     - additional/dev info
   - if no active agent is found:
     - it does not dispatch `SendWhatsAppReplyJob`
     - it resolves the active WooCommerce integration from the channel organisation
     - it reads `telegram_chat_id` from `integration_metas`
     - it sends a temporary Telegram fallback notification through `https://api-util.iquesters.com/telegram/send`
     - Telegram message content is:
       - `No active agent found.` + handover text when handover context exists
       - `No active agent found.` + normal fallback text built from the forward payload for non-handover flow
   - recent chatbot sender name is resolved from the latest chatbot-originated message for the same contact/channel.
9. `SendWhatsAppReplyJob` calls Meta Graph API and saves outbound message in DB (`messages`), including `integration_id` and forward linkage meta when applicable.

## Reply Ordering Notes
- Ordered chatbot replies are enforced by Laravel job chaining, not by in-process sleeps.
- Each chained `SendWhatsAppReplyJob` runs through the normal queue worker lifecycle when the active queue connection is an async driver such as `database`.
- `ProcessChatbotResponseJob` currently queues the reply chain first, then processes chatbot `actions[]` in the same parent job. That means handover actions are not delayed until the reply chain finishes.
- Because of that ordering, logs can show `ForwardToAgentJob` finding no active agents and still show a later `SendWhatsAppReplyJob`; in that case the WhatsApp reply was queued by `ProcessChatbotResponseJob`, not by the no-agent branch in `ForwardToAgentJob`.

## Queue Resolution Notes
- Base job queue naming uses short class name (`BaseJob` constructor), so queue names map to job class names.
- For handover actions:
  - action id = queue uid (`queues.uid`)
  - queue name = job class short name (for now expected `ForwardToAgentJob`)

## Logging Standard (Current)
- `ProcessChatbotResponseJob` and `ForwardToAgentJob` use `Iquesters\Foundation\System\Traits\Loggable`.
- Method-level logs use:
  - `logMethodStart()`
  - `logMethodEnd()`
- Step logs use:
  - `logInfo()`, `logDebug()`, `logWarning()`, `logError()`
- Structured context is appended as JSON text in log message (`| context={...}`).

## Key Files
- `src/Http/Controllers/Webhook/BaseWHController.php`
- `src/Http/Controllers/Webhook/WhatsAppWHController.php`
- `src/Jobs/WhatsAppWHJob.php`
- `src/Jobs/MessageJobs/NewMessageJob.php`
- `src/Jobs/MessageJobs/SaveMessageHelper.php`
- `src/Jobs/MessageJobs/ForwardToChatbotJob.php`
- `src/Jobs/MessageJobs/ProcessChatbotResponseJob.php`
- `src/Jobs/MessageJobs/ForwardToAgentJob.php`
- `src/Jobs/MessageJobs/SendWhatsAppReplyJob.php`
