# Telegram Bot

This folder is a separate Vercel project.

It connects to the PHP app through internal Telegram bridge endpoints for listing orders, updating statuses, and storing short-lived conversation state.

The bot entrypoint is:

- [`api/index.js`](/media/m7md/Programming/projects/LARA/telegram-bot/api/index.js)

## Commands

- `/start`
  - shows the available commands in Arabic
- `/help`
  - shows the available commands in Arabic
- `/chatid`
  - replies with the current Telegram chat id in English
- `/pending`
  - sends pending orders as separate messages with inline actions
- `/prepared`
  - sends preparing orders as separate messages with inline actions
- `/check`
  - shows the current order summary and latest pending orders in Arabic

## Interactive Actions

- Pending order card
  - `🧑‍🍳 تجهيز الطلب`
    - asks for confirmation, then moves the order to `preparing`
  - `❌ إلغاء الطلب`
    - asks for a cancellation reason and aborts if the reply is invalid
- Preparing order card
  - `✅ تم التسليم`
    - asks for confirmation, then asks for the delivery person name with a 5-minute timeout
  - `❌ إلغاء الطلب`
    - same cancellation flow as pending orders

The bot calls Telegram `setMyCommands` for the default scope and also syncs per-chat command lists based on each saved member's permissions.
On `GET /api`, the bot also checks `getWebhookInfo` and auto-updates the webhook to the current deployed `/api` URL when needed.

## Environment Variables

Set these in the Vercel project:

- `TELEGRAM_BOT_TOKEN`
  - your BotFather token
- `LARA_APP_URL` or `APP_URL`
  - the public URL of this PHP app so the bot can call `/api/telegram/check/`, `/api/telegram/orders/`, `/api/telegram/state/`, and `/api/telegram/member/`

## Deploy

1. Create a separate Vercel project.
2. Set the project root to `telegram-bot`.
3. Add the environment variables.
4. Deploy.
5. Set the webhook to the deployed endpoint.

Example:

```bash
curl -X POST "https://api.telegram.org/bot<TELEGRAM_BOT_TOKEN>/setWebhook" \
  -d "url=https://your-vercel-project.vercel.app/api"
```

For the current deployment, use one of these URLs:

```bash
curl -X POST "https://api.telegram.org/bot<TELEGRAM_BOT_TOKEN>/setWebhook" \
  -d "url=https://notify-admins.vercel.app/api"
```

After `vercel.json` is deployed, the project root also works:

```bash
curl -X POST "https://api.telegram.org/bot<TELEGRAM_BOT_TOKEN>/setWebhook" \
  -d "url=https://notify-admins.vercel.app/"
```

Verify what Telegram is currently using:

```bash
curl "https://api.telegram.org/bot<TELEGRAM_BOT_TOKEN>/getWebhookInfo"
```

## Notes

- `GET /api` returns a small health response.
- `GET /` is rewritten to `/api` by Vercel.
- `/chatid` works in private chats and groups.
- The returned id is the Telegram chat id for that conversation.
- Order-management commands are intended for chat IDs stored in the PHP admin Telegram settings.
- Each saved chat can independently control notifications, `/pending`, `/prepared`, `/check`, and `/help`.
