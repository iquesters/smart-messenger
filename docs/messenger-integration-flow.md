# Facebook Messenger Webhook Integration 

## Overview

Facebook Messenger integration follows the same architecture as WhatsApp and Telegram.
When a user sends a message to your Facebook Page via Messenger, Facebook sends a webhook
to your server. Your application receives it, saves it, forwards to the chatbot, and replies back.

```
User sends message on Messenger
        ↓
Facebook sends POST to your webhook URL
        ↓
MessengerWHController handles request (GET for verification, POST returns 200 and dispatches job)
        ↓
MessengerWHJob classifies the update
        ↓
NewMessengerMessageJob saves message to DB
        ↓
ForwardToChatbotJob (same as WhatsApp and Telegram — no changes needed)
        ↓
ProcessChatbotResponseJob (add messenger case)
        ↓
SendMessengerReplyJob sends reply via Graph API
        ↓
User receives reply on Messenger
```

---

## New Code Files to Create

### Controllers
**Path:** `smart-messenger/src/Http/Controllers/Webhook/MessengerWHController.php`
- Handles GET verification (same pattern as WhatsApp)
- Dispatches `MessengerWHJob` asynchronously on POST
- Extends `BaseWHController`

### Jobs
**Path:** `smart-messenger/src/Jobs/MessengerWHJob.php`
- Extends `WHJob`
- Classifies update type (new_message / delivery / read)
- Resolves and validates channel
- Dispatches `NewMessengerMessageJob`

**Path:** `smart-messenger/src/Jobs/MessageJobs/NewMessengerMessageJob.php`
- Saves incoming message to `messages` table via `SaveMessageHelper`
- Handles contact creation via `ContactService`
- Dispatches `ForwardToChatbotJob`

**Path:** `smart-messenger/src/Jobs/MessageJobs/SendMessengerReplyJob.php`
- Sends reply back via Facebook Graph API
- Saves outbound message to `messages` table

---

## Prerequisites

- Facebook Developer Account
- A Facebook Page (business page — not a personal profile)
- Meta App created on Facebook Developer Portal
- Page Access Token

---

## Step 1 — Create Facebook App

1. Go to https://developers.facebook.com
2. Click **My Apps → Create App**
3. Select **Business** as app type
4. Fill in app name and contact email
5. Click **Create App**

---

## Step 2 — Add Messenger and Get Page Access Token

1. In your app dashboard → click **Add Product**
2. Find **Messenger** → click **Set Up**
3. Under **Access Tokens** → select your Facebook Page
4. Click **Generate Token** → copy the **Page Access Token**
5. Save this token — it is used to send messages via API

> **Note:** While the Facebook App is in development mode, the Send API can only send messages to developers and app testers. To send to all users the app needs to go through Meta App Review and get Advanced Access.

---

## Step 3 — Generate Verify Token

Open tinker in the messenger project:

```bash
cd /path/to/messenger
php artisan tinker
```

Generate verify token:

```php
echo bin2hex(random_bytes(32));
// Save this output — this is your messenger_verify_token
```

---

## Step 4 — Insert Channel and Metas in DB via Tinker

First check the Messenger provider ID:

```php
DB::table('channel_providers')->get();
// Note the id of the Messenger provider
```

**Create Messenger channel:**

```php
$channel = \DB::table('channels')->insertGetId([
    'uid'                 => \Illuminate\Support\Str::ulid()->toBase32(),
    'name'                => 'My Facebook Page',
    'channel_provider_id' => 3, // Replace with actual Messenger provider ID from above
    'user_id'             => 1,
    'status'              => 'active',
    'created_at'          => now(),
    'updated_at'          => now(),
]);
echo $channel; // Note this ID
```

**Insert 3 channel metas:**

```php
\DB::table('channel_metas')->insert([
    ['ref_parent' => $channel, 'meta_key' => 'messenger_page_id',           'meta_value' => 'YOUR_PAGE_ID',           'status' => 'active', 'created_by' => 1, 'updated_by' => 1, 'created_at' => now(), 'updated_at' => now()],
    ['ref_parent' => $channel, 'meta_key' => 'messenger_page_access_token', 'meta_value' => 'YOUR_PAGE_ACCESS_TOKEN', 'status' => 'active', 'created_by' => 1, 'updated_by' => 1, 'created_at' => now(), 'updated_at' => now()],
    ['ref_parent' => $channel, 'meta_key' => 'messenger_verify_token',      'meta_value' => 'YOUR_VERIFY_TOKEN',      'status' => 'active', 'created_by' => 1, 'updated_by' => 1, 'created_at' => now(), 'updated_at' => now()],
]);
```

**Get channel UID for webhook URL:**

```php
\DB::select("SELECT uid FROM channels WHERE id = $channel");
// Note this UID — needed for webhook URL
```

---

## Step 5 — Start Cloudflare Tunnel (for local testing)

Since Facebook requires a public HTTPS URL for the webhook callback, use Cloudflare tunnel for local testing — same as Telegram:

```cmd
C:\Windows\System32\cloudflared.exe tunnel --url http://localhost:80
```

Wait for output like:

```
Your quick Tunnel has been created! https://abc123.trycloudflare.com
```

Note this URL — you will need it in Step 6.

> **Note:** Every time the Cloudflare URL changes, you must update the webhook callback URL in Facebook Developer Console.

---

## Step 6 — Register Webhook with Facebook

1. In your Meta App → Messenger Settings → **Webhooks → Setup Webhooks**
2. Enter:
   - **Callback URL:** `https://abc123.trycloudflare.com/webhook/messenger/{channelUid}`
   - **Verify Token:** the token you generated in Step 3
3. Click **Verify and Save**
4. After verification → click **Add Subscriptions** and select at minimum:
   - `messages`
   - `messaging_postbacks`
5. Click **Add or Remove Pages** → select your Facebook Page

Facebook will send a GET request to verify — your `MessengerWHController` handles this automatically.

---

## Step 7 — How Incoming Message Looks

When a user sends a message, Facebook POSTs this to your webhook:

```json
{
  "object": "page",
  "entry": [
    {
      "id": "PAGE_ID",
      "time": 1234567890,
      "messaging": [
        {
          "sender":    { "id": "USER_PSID" },
          "recipient": { "id": "PAGE_ID" },
          "timestamp": 1234567890,
          "message": {
            "mid":  "mid.abc123xyz",
            "text": "Hello!"
          }
        }
      ]
    }
  ]
}
```

Key fields:
- `entry.0.messaging.0.sender.id` → PSID — unique user identifier (stored as `from` in DB)
- `entry.0.messaging.0.recipient.id` → Page ID (stored as `to` in DB)
- `entry.0.messaging.0.message.text` → message text
- `entry.0.messaging.0.message.mid` → message ID (already globally unique — no combining needed like Telegram)

---

## Step 8 — How the Code Handles It

### MessengerWHController
Facebook uses GET verification like WhatsApp — not secret header like Telegram:

```php
class MessengerWHController extends BaseWHController
{
    protected ?bool $async = true;

    protected function getJobClass(): string
    {
        return MessengerWHJob::class;
    }

    protected function handleVerification(Request $request, string $channelUid): mixed
    {
        if ($request->input('hub.mode') !== 'subscribe') {
            return response('Invalid hub mode', 403);
        }

        $verifyToken = $request->input('hub.verify_token');
        $challenge   = $request->input('hub.challenge');

        $channel = Channel::where('uid', $channelUid)
            ->where('status', 'active')->first();

        if (!$channel) {
            return response('Invalid channel', 403);
        }

        $meta = $channel->metas()
            ->where('meta_key', 'messenger_verify_token')
            ->where('meta_value', $verifyToken)
            ->first();

        if (!$meta) {
            return response('Invalid verify token', 403);
        }

        return response($challenge, 200)->header('Content-Type', 'text/plain');
    }
}
```

### SaveMessageHelper — Add Messenger Platform

`detectPlatform()` auto-detects by message structure:

```php
private function detectPlatform(): string
{
    if (isset($this->message['message_id'])) return 'telegram';
    if (isset($this->message['mid']))        return 'messenger'; // new
    return 'whatsapp';
}
```

Messenger specific fields in `process()` switch case:

```php
case 'messenger':
    $messageId   = $this->message['mid'] ?? null;        // already globally unique
    $from        = $this->message['sender']['id'] ?? null; // PSID
    $to          = $this->message['recipient']['id'] ?? null; // Page ID
    $contactName = null;                                  // no name in basic payload
    $messageType = isset($this->message['attachments']) ? 'attachment' : 'text';
    $timestamp   = isset($this->message['timestamp'])
                    ? now()->setTimestamp($this->message['timestamp'] / 1000)
                    : now();
    break;
```

### SendMessengerReplyJob — Send via Graph API

Send API endpoint can use either {PAGE_ID}/messages or me/messages:

```php
$response = Http::withToken($channel->getMeta('messenger_page_access_token'))
    ->post('https://graph.facebook.com/v25.0/' . $channel->getMeta('messenger_page_id') . '/messages', [
        'recipient'      => ['id' => $chatId],
        'messaging_type' => 'RESPONSE',
        'message'        => ['text' => $text],
    ]);
```

### ProcessChatbotResponseJob — Add Messenger Case

```php
return match ($this->getProvider()) {
    'telegram'  => new SendTelegramReplyJob($this->inboundMessage, $payload),
    'messenger' => new SendMessengerReplyJob($this->inboundMessage, $payload),
    default     => new SendWhatsAppReplyJob($this->inboundMessage, $payload),
};
```

---

## Step 9 — Route Added

```php
Route::any('/webhook/messenger/{channelUid}', [MessengerWHController::class, 'handle']);
```

---

## Step 10 — UI Changes

Add Messenger form following same pattern as Telegram and WhatsApp:

- `messenger-form.blade.php` — Step 2 fields: Page ID, Page Access Token, Verify Token
- `messenger-show.blade.php` — Show page with webhook URL, verify token, and copy buttons
- Add `@case('messenger')` in `MessagingProfileController` match cases for `create()`, `edit()`, and `show()`

---

## Step 11 — Start Queue Worker

```bash
cd /path/to/messenger
php artisan queue:work 
```

---

## Step 12 — Test

**Send a message:**
1. Go to your Facebook Page
2. Click **Send Message**
3. Send any message like "Hello!"

**Verify in phpMyAdmin:**
- Open `iq_messenger` database
- Open `messages` table
- You should see a new row with:
  - `from` = USER_PSID
  - `to` = PAGE_ID
  - `message_type` = text
  - `content` = your message
  - `status` = received 

**Verify in Laravel logs:**

```
storage/logs/laravel.log
```

Look for:
- `Channel validated successfully`
- `Message saved successfully`
- `Contact handled from webhook`

**Test reply via Tinker:**

```php
$message = \Iquesters\SmartMessenger\Models\Message::where('channel_id', YOUR_CHANNEL_ID)
    ->where('status', 'received')->latest()->first();

\Iquesters\SmartMessenger\Jobs\MessageJobs\SendMessengerReplyJob::dispatch(
    $message,
    ['type' => 'text', 'text' => 'Hello from SmartMessenger!']
);
```

---

## Notes

- For local testing we use Cloudflare tunnel (same as Telegram) — every time the URL changes update the webhook callback URL in Facebook Developer Console
- Facebook verifies the webhook via GET request first — make sure your server is running before registering the webhook
- Page Access Token does not expire if generated correctly as a long-lived token
- PSID is unique per user per page — same user messaging two different pages gets two different PSIDs
- `message.mid` is already globally unique — no need to combine with sender ID like Telegram
- Unlike Telegram and WhatsApp, Messenger requires `messaging_type: RESPONSE` in the Send API payload

---

## Official Documentation

- Messenger Platform Overview: https://developers.facebook.com/docs/messenger-platform
- Quick Start Guide: https://developers.facebook.com/docs/messenger-platform/getting-started/quick-start
- Webhook Setup: https://developers.facebook.com/docs/messenger-platform/webhooks
- Webhook Events Reference: https://developers.facebook.com/docs/messenger-platform/reference/webhook-events
- Send API Reference: https://developers.facebook.com/docs/messenger-platform/reference/send-api
- Page Access Token: https://developers.facebook.com/docs/pages/access-tokens
- Long-lived Token Guide: https://developers.facebook.com/docs/facebook-login/guides/access-tokens/get-long-lived
