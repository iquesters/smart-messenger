# Telegram Webhook Setup Guide

## New Code Files Created

### Controllers
**Path:** `smart-messenger/src/Http/Controllers/Webhook/TelegramWHController.php`
- Handles incoming webhook POST requests
- Validates `X-Telegram-Bot-Api-Secret-Token` header
- Extends `BaseWHController`
- Dispatches `TelegramWHJob` asynchronously

---

### Jobs
**Path:** `smart-messenger/src/Jobs/TelegramWHJob.php`
- Extends `WHJob`
- Determines webhook type (new_message / unknown)
- Resolves and validates channel
- Dispatches `NewTelegramMessageJob`

**Path:** `smart-messenger/src/Jobs/MessageJobs/NewTelegramMessageJob.php`
- Saves incoming message to `messages` table
- Handles contact creation via `ContactService`
- Dispatches `ForwardToChatbotJob`

**Path:** `smart-messenger/src/Jobs/MessageJobs/SendTelegramReplyJob.php`
- Sends reply back via Telegram Bot API
- Saves outbound message to `messages` table

---

## Route Added

**File:** `smart-messenger/routes/webhook.php`

Uncommented this line:
```php
Route::post('/webhook/telegram/{channelUid}', [TelegramWHController::class, 'handle']);
```

---
## WEBHOOK SETUP 

## Prerequisites
- Laravel project (messenger) running via XAMPP
- smart-messenger package installed and symlinked
- Telegram Bot created via BotFather
- PHP Artisan Tinker access

---

## Step 1 — Create Telegram Bot via BotFather

1. Open Telegram app
2. Search for `@BotFather`
3. Send `/newbot`
4. Get and Save:
   - **Bot Token:** 
   - **Bot Username:** 

---

## Step 2 — Insert Channel and Metas in DB via Tinker

Open tinker in the messenger project:
```bash
cd /path/to/messenger
php artisan tinker
```

**Generate secret token:**
```php
echo bin2hex(random_bytes(32));
// Save this output — this is your telegram_webhook_secret
```

**Create Telegram channel:**
```php
$channel = \DB::table('channels')->insertGetId([
    'uid'                 => \Illuminate\Support\Str::ulid()->toBase32(),
    'name'                => 'IquesterTele_Bot',
    'channel_provider_id' => 2, //channel_provider_id: 2 is the Telegram provider ID in the providers table
    'user_id'             => 1,
    'status'              => 'active',
    'created_at'          => now(),
    'updated_at'          => now(),
]);
echo $channel; // Note this ID
```

**Insert 3 channel metas** (replace values with your own):
```php
\DB::table('channel_metas')->insert([
    ['ref_parent' => $channel, 'meta_key' => 'telegram_bot_token',      'meta_value' => 'YOUR_BOT_TOKEN',      'status' => 'active', 'created_by' => 1, 'updated_by' => 1, 'created_at' => now(), 'updated_at' => now()],
    ['ref_parent' => $channel, 'meta_key' => 'telegram_bot_username',   'meta_value' => 'YOUR_BOT_USERNAME',   'status' => 'active', 'created_by' => 1, 'updated_by' => 1, 'created_at' => now(), 'updated_at' => now()],
    ['ref_parent' => $channel, 'meta_key' => 'telegram_webhook_secret', 'meta_value' => 'YOUR_SECRET_TOKEN',   'status' => 'active', 'created_by' => 1, 'updated_by' => 1, 'created_at' => now(), 'updated_at' => now()],
]);
```

**Get channel UID:**
```php
\DB::select("SELECT uid FROM channels WHERE id = $channel");
// Note this UID — needed for webhook URL
```

## ---WHAT WE DID IN TINKER IN DETAIL ---

## 1.-  Generate secret token: echo bin2hex(random_bytes(32));
// Output: <generated_secret_token>


## 2.-  Create Telegram channel:
```php    
$channel = \DB::table('channels')->insertGetId([

    'uid'                 => \Illuminate\Support\Str::ulid()->toBase32(),
    'name'                => 'IquesterTele_Bot',
    'channel_provider_id' => 2, //channel_provider_id: 2 is the Telegram provider ID in the providers table
    'user_id'             => 1,
    'status'              => 'active',
    'created_at'          => now(),
    'updated_at'          => now(),
]);
// Output: 2 (channel id)
```

## 3. Save 3 channel metas:

```php
\DB::table('channel_metas')->insert([
    ['ref_parent' => 2, 'meta_key' => 'telegram_bot_token',      'meta_value' => 'YOUR_BOT_TOKEN', 'status' => 'active', 'created_by' => 1, 'updated_by' => 1, 'created_at' => now(), 'updated_at' => now()],
    ['ref_parent' => 2, 'meta_key' => 'telegram_bot_username',   'meta_value' =>'YOUR_BOT_USERNAME',                          'status' => 'active', 'created_by' => 1, 'updated_by' => 1, 'created_at' => now(), 'updated_at' => now()],
    ['ref_parent' => 2, 'meta_key' => 'telegram_webhook_secret', 'meta_value' => 'YOUR_SECRET_TOKEN', 'status' => 'active', 'created_by' => 1, 'updated_by' => 1, 'created_at' => now(), 'updated_at' => now()],
]);
```

## 4. Verify data:

```php
\DB::select("SELECT * FROM channel_metas WHERE ref_parent = 2");
// Shows all 3 metas saved correctly
```


## 5. — Get channel UID for webhook URL:
```php
\DB::select("SELECT uid FROM channels WHERE id = 2");
// Output: 01KPBRE..........
```

## Step 3 — Install Cloudflare Tunnel

1. Download `cloudflared-windows-amd64.exe` from:
https://github.com/cloudflare/cloudflared/releases/download/2026.3.0/cloudflared-windows-amd64.exe
2. Rename it to `cloudflared.exe`
3. Move it to `C:\Windows\System32\`
4. Allow Windows Firewall when prompted

**Verify installation (in CMD):**

```cmd
C:\Windows\System32\cloudflared.exe --version
```

## Step 4 — Start Cloudflare Tunnel

Open CMD and run:
```cmd
C:\Windows\System32\cloudflared.exe tunnel --url http://localhost:80
```

Wait for output like:
Your quick Tunnel has been created! https://abc123.trycloudflare.com
(This will give our public HTTPS URL!)

**Note down this URL** 

## Step 5 — Register Webhook with Telegram

Paste this in your browser (replace values):

https://api.telegram.org/bot{BOT_TOKEN}/setWebhook?url={CLOUDFLARE_URL}/webhook/telegram/{CHANNEL_UID}&secret_token={WEBHOOK_SECRET}

**Expected response:**
```json
{"ok":true,"result":true,"description":"Webhook was set"}
```

---

## Step 6 — Start Queue Worker

Open a new terminal and run:
```bash
cd /path/to/messenger
php artisan queue:work
```

Leave this running — it processes incoming messages.

---

## Step 7 — Test from Telegram App

1. Open Telegram on your phone
2. Search `IquesterTele_Bot`
3. Send any message like "Hello!"

---

## Step 8 — Verify

**Check phpMyAdmin:**
- Go to `http://localhost/phpmyadmin`
- Open `iq_messenger` database
- Open `messages` table
- You should see new rows with your message content 

**Check Laravel logs:**
- Open `storage/logs/laravel.log`
- Search for your Telegram user ID
- You should see:
  - `Channel validated successfully`
  - `Telegram message saved successfully`
  - `Contact handled from webhook via service`

---

## Notes

- Cloudflare Tunnel URL changes every time you restart it
- Every time URL changes, repeat Step 5 to re-register webhook
- All 3 must be running for full flow to work:
  - XAMPP (Apache)
  - Cloudflare Tunnel (CMD)
  - `php artisan queue:work` (terminal)
- For production, deploy to a server with a permanent URL — no tunnel needed

## Postman Testing

**Method:** `POST`  
**URL:** `http://localhost/webhook/telegram/channeluid`

**Headers:**
| Key | Value |
|-----|-------|
| `Content-Type` | `application/json` |
| `X-Telegram-Bot-Api-Secret-Token` | `secret token` |

**Body:**
```json
{
    "update_id": 123456789,
    "message": {
        "message_id": 1,
        "from": {
            "id": 111111111,
            "first_name": "Test",
            "last_name": "User",
            "username": "testuser"
        },
        "chat": {
            "id": 111111111,
            "type": "private"
        },
        "date": 1713000000,
        "text": "Hello from Telegram!"
    }
}
```
