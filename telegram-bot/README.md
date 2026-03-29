# Telegram Bot

This folder is a separate Vercel project.

It is not connected to the PHP app. The current version only handles Telegram webhook updates and replies to `/chatid`.

The bot entrypoint is:

- [`api/index.js`](/media/m7md/Programming/projects/LARA/telegram-bot/api/index.js)

## Commands

- `/start`
  - shows the available commands
- `/help`
  - shows the available commands
- `/chatid`
  - replies with the current Telegram chat id
- `/check`
  - shows the current pending order summary and latest pending orders

The bot also calls Telegram `setMyCommands` with the default scope, so these commands are public and visible to anyone using the bot.
On `GET /api`, the bot also checks `getWebhookInfo` and auto-updates the webhook to the current deployed `/api` URL when needed.

## Environment Variables

Set these in the Vercel project:

- `TELEGRAM_BOT_TOKEN`
  - your BotFather token
- `LARA_APP_URL` or `APP_URL`
  - the public URL of this PHP app so the bot can call `/api/telegram/check/`

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
