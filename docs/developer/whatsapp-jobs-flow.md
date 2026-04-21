# WhatsApp Jobs Flow

## Scope

- This document describes the current WhatsApp async flow from webhook receipt to outbound reply persistence.
- It includes the current chatbot v3 callback flow and chatbot handover behavior.

## End-to-End Flow

1. `BaseWHController::handle()` receives the webhook request.
2. For WhatsApp POST requests, `WhatsAppWHController` uses async processing and dispatches `WhatsAppWHJob`.
3. `WhatsAppWHJob` classifies the payload:
   - `status_update` -> dispatch `StatusUpdateJob`
   - `new_message` -> validate channel and dispatch `NewMessageJob` for each inbound message
4. `NewMessageJob` runs:
   - saves the inbound message through `SaveMessageHelper`
   - creates or updates the contact
   - checks whether the message is an agent reply to a previously forwarded message
   - if it is an agent reply, dispatches `SendWhatsAppReplyJob` back to the original customer and stops
   - otherwise resolves workflow jobs from channel workflow metadata
   - if nothing is configured, falls back to `ForwardToChatbotJob`
5. `ForwardToChatbotJob` prepares the chatbot v3 request and calls:
   - `https://api-chatbot.iquesters.com/api/chat/v3`
6. If the chatbot API accepts the request, `ForwardToChatbotJob` does not dispatch `ProcessChatbotResponseJob` directly anymore.
   - chatbot outbound processing is now handled asynchronously outside this request path
   - Laravel receives that follow-up work later through `ChatbotV3OutboundJob`
7. `ChatbotV3OutboundJob` runs when chatbot-core/chatbot-util sends the outbound callback payload into Laravel.
   - it loads the original inbound `messages` row by `message_id`
   - it resolves the active chatbot integration from the inbound message's channel organisation
   - it dispatches `ProcessChatbotResponseJob`
8. `ProcessChatbotResponseJob` processes chatbot output:
   - converts chatbot `messages[]` into `SendWhatsAppReplyJob` instances
   - supports text replies
   - supports product replies, including media download and storage before send
   - extracts supported tool payload charts and turns them into outbound image replies
   - dispatches reply jobs as a `Bus::chain(...)` so message order is preserved
   - processes chatbot `actions[]` for handover
9. For chatbot handover actions, `ProcessChatbotResponseJob`:
   - resolves the target queue from `queues.uid = actions[].id`
   - reads `queues.name` as the job class suffix
   - currently allows only `ForwardToAgentJob`
   - extracts chatbot summary data from action payload
   - resolves the contact from the inbound message context
   - stores handover metadata on the inbound message:
     - `chatbot_handover_action_id`
     - `chatbot_handover_summary`
   - dispatches `ForwardToAgentJob`
10. `ForwardToAgentJob` resolves active support agent numbers for the channel.
    - for normal forwarding, it forwards the inbound content to active agents
    - for handover, it builds a structured handover text using chatbot summary data
    - if no active agent is found, it does not dispatch `SendWhatsAppReplyJob`
    - instead it sends a temporary Telegram fallback notification using the channel's active integration context
11. `SendWhatsAppReplyJob` sends the final outbound reply to Meta Graph API and then stores the outbound `messages` row.
    - it saves `integration_id`
    - it supports text and image payloads
    - it stores forward linkage meta when applicable
    - for image replies, it uploads stored local media to WhatsApp first and then sends the media message

## Current Chatbot Boundary

The important process change is this:

- `ForwardToChatbotJob` only submits the inbound message to chatbot v3.
- It no longer dispatches `ProcessChatbotResponseJob` directly.
- Reply delivery resumes later through `ChatbotV3OutboundJob`.

So the chatbot path is now split into two stages:

1. submit inbound message to chatbot
2. later receive chatbot outbound callback and continue local reply processing

## New Message Path

### Webhook classification

`WhatsAppWHJob` determines the payload type by reading:

- `entry.0.changes.0.value.statuses`
- `entry.0.changes.0.value.messages`

### Channel validation

For new messages it validates:

- `channel.uid`
- channel `status = active`
- channel meta `whatsapp_phone_number_id`

Only then does it dispatch `NewMessageJob`.

### Inbound save and workflow dispatch

`NewMessageJob` uses `SaveMessageHelper` to:

- prevent duplicate message insertion
- persist the inbound message
- download and store supported media
- create or update the contact

Then it:

- handles agent-reply routing if the inbound message references a previously forwarded message
- otherwise dispatches workflow jobs

## Chatbot Reply Path

### `ForwardToChatbotJob`

This job:

- builds the chatbot payload from the saved inbound `messages` row
- includes text content when the message is text
- includes media URL and metadata when the message has stored media
- resolves chatbot auth token from supported integration metadata
- posts to chatbot v3

After a successful API response it only logs acceptance.

It intentionally does not:

- poll for reply
- sleep for sequencing
- dispatch `ProcessChatbotResponseJob` inline

### `ChatbotV3OutboundJob`

This job is now the bridge back into Laravel reply processing.

It requires:

- `message_id`
- `chatbot_response`

It then:

- loads the original inbound message by `message_id`
- resolves the chatbot integration from the inbound message's organisation context
- dispatches `ProcessChatbotResponseJob`

### `ProcessChatbotResponseJob`

This job:

- iterates chatbot `messages[]`
- routes each message by type
- creates `SendWhatsAppReplyJob` instances for supported output
- chains those jobs with `Bus::chain(...)`
- processes handover actions separately in the same parent job

Supported output currently includes:

- text
- product
- chart-like tool payload images

## Handover Path

When chatbot returns `actions[]`, `ProcessChatbotResponseJob`:

- resolves the queue by `actions[].id`
- maps `queues.name` to a job class
- only accepts `ForwardToAgentJob` right now

For handover it also:

- extracts chatbot summary data
- persists summary metadata on the inbound message
- resolves the contact for the inbound message
- dispatches `ForwardToAgentJob` with handover context

`ForwardToAgentJob` then:

- resolves active support agent numbers through `AgentResolverService`
- builds either a regular forward payload or a chatbot handover payload
- dispatches `SendWhatsAppReplyJob` to each active agent

If no agent is active:

- it resolves the active integration from the channel
- reads `telegram_chat_id`
- sends a temporary Telegram fallback notification

## Reply Ordering Notes

- Ordered chatbot replies are enforced by Laravel job chaining.
- `ProcessChatbotResponseJob` creates all reply jobs first and dispatches them as a chain.
- Chatbot `actions[]` are processed in the same parent job after the reply chain is dispatched.
- Because of that, handover processing is not blocked until the reply chain finishes.

## Queue Resolution Notes

- `BaseJob` queue naming uses the short class name.
- For chatbot handover:
  - action id maps to `queues.uid`
  - queue name maps to the job class short name
  - currently expected handover job is `ForwardToAgentJob`

## Logging Notes

The main jobs in this flow use the shared logging style from foundation traits and base job helpers:

- `logMethodStart()`
- `logMethodEnd()`
- `logInfo()`
- `logDebug()`
- `logWarning()`
- `logError()`

Structured context is appended in log messages using the local `ctx(...)` helper pattern.

## Key Files

- `src/Http/Controllers/Webhook/BaseWHController.php`
- `src/Http/Controllers/Webhook/WhatsAppWHController.php`
- `src/Jobs/WhatsAppWHJob.php`
- `src/Jobs/MessageJobs/NewMessageJob.php`
- `src/Jobs/MessageJobs/SaveMessageHelper.php`
- `src/Jobs/MessageJobs/ForwardToChatbotJob.php`
- `src/Jobs/MessageJobs/ChatbotV3OutboundJob.php`
- `src/Jobs/MessageJobs/ProcessChatbotResponseJob.php`
- `src/Jobs/MessageJobs/ForwardToAgentJob.php`
- `src/Jobs/MessageJobs/SendWhatsAppReplyJob.php`
- `src/Jobs/MessageJobs/StatusUpdateJob.php`
