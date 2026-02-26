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
   - sends chatbot `messages[]` to customer via `SendWhatsAppReplyJob` (text/product)
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
     - last few messages (from DB, no timestamp)
     - additional/dev info
   - recent chatbot sender name is resolved via `Message::getSenderNameAttribute()`.
9. `SendWhatsAppReplyJob` calls Meta Graph API and saves outbound message in DB (`messages`), including `integration_id` and forward linkage meta when applicable.

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
