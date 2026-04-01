# Telegram Bot Setup Guide

This folder is a separate Vercel project for the LARA Telegram bot.

The bot does not work by itself. It talks to the main PHP app through internal bridge endpoints:

- `/api/telegram/check/`
- `/api/telegram/orders/`
- `/api/telegram/state/`
- `/api/telegram/member/`

The Vercel bot and the PHP app must be configured together.

The bot entrypoint is:

- [`api/index.js`](/media/m7md/Programming/projects/LARA/telegram-bot/api/index.js)

`vercel.json` rewrites `/` to `/api`, so either URL can be used after deployment:

- `https://your-project.vercel.app/`
- `https://your-project.vercel.app/api`

## What The Bot Does

Supported commands:

- `/start`
  - shows the available commands in Arabic
- `/help`
  - shows the available commands in Arabic
- `/chatid`
  - replies with the current Telegram chat ID in English
- `/pending`
  - sends pending orders as separate messages with inline actions
- `/prepared`
  - sends preparing orders as separate messages with inline actions
- `/check`
  - shows the order summary and latest pending orders in Arabic

Interactive order actions:

- Pending order card
  - `🧑‍🍳 تجهيز الطلب`
    - confirms and moves the order to `preparing`
  - `❌ إلغاء الطلب`
    - asks for a cancellation reason
- Preparing order card
  - `✅ تم التسليم`
    - confirms, then asks for the delivery name
  - `❌ إلغاء الطلب`
    - asks for a cancellation reason

The bot also:

- syncs Telegram commands with `setMyCommands`
- syncs per-chat commands based on each member's permissions
- verifies webhook requests using Telegram's `secret_token`
- auto-syncs the webhook when you open `GET /api`

## Architecture

There are two runtime environments:

1. The main PHP app
   - serves the website and admin panel
   - stores Telegram recipient permissions in MySQL
   - validates bridge requests from the bot
   - can send new-order notifications to Telegram

2. The Vercel bot project in [`telegram-bot`](/media/m7md/Programming/projects/LARA/telegram-bot)
   - receives Telegram webhook updates
   - calls the PHP app bridge endpoints
   - formats Telegram messages and interactive actions

Important: both sides must use the same Telegram bot token. If the token differs, bridge authentication will fail.

## Before You Start

You need:

- a public HTTPS URL for the main PHP app
- a Telegram account
- a Vercel account
- a deployed database-backed PHP app
- working admin access to `/admin/`

Recommended first:

1. Deploy the PHP app.
2. Set the PHP app `.env` values.
3. Run database migrations.
4. Create the Telegram bot in BotFather.
5. Deploy this `telegram-bot` folder to Vercel.
6. Authorize chat IDs from the admin panel.

## Step 1: Create A Bot In BotFather

Open Telegram and chat with [@BotFather](https://t.me/BotFather).

Create a new bot:

1. Send `/newbot`
2. Enter the display name you want
3. Enter a unique username ending with `bot`
   - example: `lara_orders_bot`
4. BotFather will return a token that looks like this:

```text
123456789:AAExampleTokenValueHere
```

Save that token securely. This is your main secret.

Optional BotFather steps:

- `/setdescription`
- `/setabouttext`
- `/setuserpic`
- `/setcommands`
  - optional, because this bot already syncs commands automatically

If this token was ever exposed, revoke it immediately with `/revoke` in BotFather and update both deployments.

## Step 2: Configure The Main PHP App

In the main project `.env`, make sure these values are set.

The file template already exists at:

- [`.env.example`](/media/m7md/Programming/projects/LARA/.env.example)

Minimum PHP-side env values relevant to Telegram:

```dotenv
APP_URL=https://your-php-app.example.com
TELEGRAM_BOT_TOKEN=123456789:AAExampleTokenValueHere
WHATSAPP_ORDER_PHONE=201234567890
```

Notes:

- `APP_URL` should be the public base URL of the PHP app.
- `TELEGRAM_BOT_TOKEN` can be replaced by `TELEGRAM_TOKEN`, but use `TELEGRAM_BOT_TOKEN` unless you need compatibility with older env naming.
- `WHATSAPP_ORDER_PHONE` is needed for the order checkout flow, not specifically for Telegram, but customers will hit it after order submission.

Run migrations after configuring the PHP app:

```bash
php database/migrate.php
```

Then confirm these endpoints are reachable from the public internet:

- `https://your-php-app.example.com/api/telegram/check/`
- `https://your-php-app.example.com/api/telegram/orders/`
- `https://your-php-app.example.com/api/telegram/state/`
- `https://your-php-app.example.com/api/telegram/member/`

They are internal endpoints. They should not be used directly in a browser, but the bot must be able to reach them.

## Step 3: Deploy The Bot To Vercel

This bot is intended to be deployed as a separate Vercel project with `telegram-bot` as the root directory.

### Option A: Deploy From The Vercel Dashboard

1. Push your repo to GitHub/GitLab/Bitbucket.
2. In Vercel, click `Add New Project`.
3. Import the repository.
4. Set the project root directory to:

```text
telegram-bot
```

5. Keep the framework as the default plain Node/serverless setup.
6. Add the environment variables described below.
7. Deploy.

### Option B: Deploy With The Vercel CLI

From the repo root:

```bash
cd telegram-bot
vercel
```

For production:

```bash
cd telegram-bot
vercel --prod
```

## Step 4: Add The Vercel Environment Variables

Set these in the Vercel project.

### Required

```dotenv
TELEGRAM_BOT_TOKEN=123456789:AAExampleTokenValueHere
LARA_APP_URL=https://your-php-app.example.com
```

You may use `APP_URL` instead of `LARA_APP_URL`, but `LARA_APP_URL` is clearer for this bot deployment.
Paste both values as raw text in Vercel. Do not wrap them in `'quotes'` or `"quotes"`.

### Optional

```dotenv
TELEGRAM_WEBHOOK_SECRET=your-random-secret-or-leave-empty
TELEGRAM_WEBHOOK_URL=https://your-bot-domain.example.com/api
BOT_WEBHOOK_URL=https://your-bot-domain.example.com/api
```

What each variable does:

- `TELEGRAM_BOT_TOKEN`
  - required
  - the BotFather token
  - must match the token configured in the PHP app
- `LARA_APP_URL`
  - required unless you use `APP_URL`
  - must point to the main PHP app, not the Vercel bot
- `APP_URL`
  - fallback alias for `LARA_APP_URL`
- `TELEGRAM_WEBHOOK_SECRET`
  - optional
  - explicit secret used to verify Telegram webhook requests to Vercel
  - if omitted, the bot derives a stable secret from the bot token
- `TELEGRAM_WEBHOOK_URL`
  - optional
  - forces the exact webhook URL if Vercel URL detection is not what you want
- `BOT_WEBHOOK_URL`
  - optional legacy alias for the same purpose

You do not need to set:

- `VERCEL_URL`
- `VERCEL_PROJECT_PRODUCTION_URL`

Those are provided by Vercel automatically when relevant.

## Step 5: Understand The Secrets

There are two important secrets in this setup.

### 1. Telegram Bot Token

This is the main secret returned by BotFather.

It is used for:

- sending messages to Telegram
- reading bot identity with `getMe`
- deriving the internal bridge secret between the Vercel bot and the PHP app
- deriving the webhook secret if `TELEGRAM_WEBHOOK_SECRET` is not explicitly set

This token must be the same in:

- the PHP app `.env`
- the Vercel bot environment

### 2. Telegram Webhook Secret

This is the secret Telegram sends back in the `X-Telegram-Bot-Api-Secret-Token` header.

The bot verifies it before accepting webhook updates.

You have two choices:

- set `TELEGRAM_WEBHOOK_SECRET` explicitly in Vercel
- leave it empty and let the bot derive it automatically from the bot token

If you want to generate your own explicit secret, use a long random value.

Example:

```text
a8f2c0d9b7417a5f7dc13c0494d91f7f0f9bd0d4a9c44ac1a3245d9272db8f10
```

If you want to derive the same value the code derives automatically, you can do it with Node:

```bash
node -e "const crypto=require('crypto'); const token=process.argv[1]; console.log(crypto.createHash('sha256').update('lara-telegram-webhook|' + token).digest('hex'))" "123456789:AAExampleTokenValueHere"
```

There is no separate bridge secret env variable. The bridge secret is derived automatically from the bot token on both sides.

## Step 6: Set Up The Webhook

There are two supported ways.

### Recommended: Let The Bot Auto-Sync It

After deployment, open:

```text
https://your-project.vercel.app/api
```

The `GET /api` handler will:

- return a health payload
- call Telegram `getWebhookInfo`
- set or update the webhook if needed
- set or update allowed updates
- apply the webhook secret token
- sync public commands

If you use a custom production domain, open the final production URL instead of the preview URL.

Important: do not use a preview deployment URL for webhook sync unless you intentionally want Telegram to send updates there. If needed, set `TELEGRAM_WEBHOOK_URL` to the production `/api` URL so the webhook stays pinned to production.

### Manual: Call Telegram `setWebhook` Yourself

If you want to manage it manually, call:

```bash
curl -X POST "https://api.telegram.org/bot<TELEGRAM_BOT_TOKEN>/setWebhook" \
  -H "Content-Type: application/json" \
  -d '{
    "url": "https://your-project.vercel.app/api",
    "secret_token": "<TELEGRAM_WEBHOOK_SECRET>",
    "allowed_updates": ["message", "edited_message", "callback_query"]
  }'
```

If you did not set `TELEGRAM_WEBHOOK_SECRET` explicitly, use the derived value instead.

Check the result:

```bash
curl "https://api.telegram.org/bot<TELEGRAM_BOT_TOKEN>/getWebhookInfo"
```

Expected:

- `url` matches your Vercel bot URL
- `allowed_updates` includes `message`, `edited_message`, and `callback_query`
- there is no webhook error

## Step 7: Authorize The Chats In The Admin Panel

Deploying the bot is not enough. The chats that should manage orders must be explicitly authorized in the PHP admin panel.

### Get A Chat ID

1. Open a private chat or group with the bot.
2. Send:

```text
/chatid
```

3. Copy the returned chat ID.

Examples:

- private chat: `123456789`
- group or supergroup: `-1001234567890`

### Save The Chat In The Admin Panel

Open:

- `https://your-php-app.example.com/admin/settings/telegram/`

Add the chat ID and choose permissions:

- `New order notifications`
  - allows passive notifications when new orders are submitted
- `/pending`
  - allows viewing and moving pending orders
- `/prepared`
  - allows viewing and delivering preparing orders
- `/check`
  - allows viewing order summary counts
- `/help`
  - allows using `/help`

The chat must also remain active.

Without this step:

- `/start` and `/chatid` can still work
- order-management commands will be blocked

## Step 8: Test The Full Flow

### Bot Health

Open:

```text
https://your-project.vercel.app/api
```

You should see JSON with fields similar to:

- `configured.telegram_token`
- `configured.app_url`
- `configured.bridge_secret`
- `configured.webhook_secret`
- `webhook`
- `bot`

The important values should all be `true` or populated correctly.

### Telegram Commands

In Telegram:

1. Send `/start`
2. Send `/chatid`
3. If the chat was authorized in admin, send `/help`
4. If the chat has permission, send `/check`
5. If there are pending orders, send `/pending`

### Notification Test

1. Submit a new order through the main app.
2. Confirm that authorized chats with `New order notifications` receive the message.
3. Use Telegram actions to move the order through:
   - pending
   - preparing
   - delivered or cancelled

## Environment Variable Reference

### Main PHP App

Put these in the PHP app `.env`.

| Variable | Required | Purpose |
| --- | --- | --- |
| `APP_URL` | Yes | Public base URL for links generated by the PHP app |
| `TELEGRAM_BOT_TOKEN` or `TELEGRAM_TOKEN` | Yes for Telegram features | Must match the Vercel bot token |
| `WHATSAPP_ORDER_PHONE` | Yes for order checkout | Customer redirect after order submission |
| `ADMIN_USERNAME` | Yes | Admin login |
| `ADMIN_PASSWORD_HASH` or `ADMIN_PASSWORD` | Yes | Admin login password |
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` | Yes | Database connection |

### Vercel Bot Project

Set these in the Vercel environment.

| Variable | Required | Purpose |
| --- | --- | --- |
| `TELEGRAM_BOT_TOKEN` | Yes | BotFather token |
| `LARA_APP_URL` or `APP_URL` | Yes | Public URL of the PHP app |
| `TELEGRAM_WEBHOOK_SECRET` | No | Explicit webhook secret token |
| `TELEGRAM_WEBHOOK_URL` | No | Force exact webhook URL |
| `BOT_WEBHOOK_URL` | No | Legacy alias for webhook URL override |

## Security Notes

- Never commit the real Telegram token.
- Never commit the real webhook secret.
- Rotate the token immediately if it is leaked.
- Keep the PHP app and Vercel bot token values in sync.
- Telegram requires a public HTTPS webhook URL.
- Do not point `LARA_APP_URL` to the Vercel bot. It must point to the PHP app.

## Troubleshooting

### The bot replies with bridge/config errors

Check:

- `LARA_APP_URL` or `APP_URL` in Vercel points to the PHP app
- the PHP app is publicly reachable
- the PHP app has the same `TELEGRAM_BOT_TOKEN`

### Telegram says the webhook is set, but the bot does not respond

Check:

- `https://your-project.vercel.app/api` returns healthy JSON
- `getWebhookInfo` shows the right URL
- the webhook secret matches what the Vercel app expects

### `/pending`, `/prepared`, `/check`, or `/help` say unauthorized

Check:

- the chat ID was added in `/admin/settings/telegram/`
- the recipient is active
- the correct permission checkbox is enabled
- `GET /api` on the bot shows the same `configured.app_base_url` as the PHP app where you saved the chat ID
- the bot is not pointing at a different deployment or database than the admin dashboard you used

### New order notifications do not arrive

Check:

- the chat is saved in Telegram settings
- `New order notifications` is enabled
- the PHP app `.env` contains the same bot token

### `configured.app_url` is false on `GET /api`

Set one of:

- `LARA_APP_URL`
- `APP_URL`

in the Vercel project.

### The webhook URL is wrong

Set:

- `TELEGRAM_WEBHOOK_URL`

to the exact deployed `/api` URL and redeploy or open `GET /api` again.

## Operational Checklist

Use this after deployment:

1. Bot created in BotFather
2. Token copied securely
3. PHP app `.env` updated with the token
4. PHP app deployed on HTTPS
5. Database migrations run
6. Vercel project created with root `telegram-bot`
7. Vercel env variables added
8. Vercel deployment completed
9. `GET /api` opened once after deployment
10. `getWebhookInfo` verified
11. Chat ID added in admin Telegram settings
12. `/chatid`, `/start`, `/check`, `/pending` tested
13. New order notification tested
