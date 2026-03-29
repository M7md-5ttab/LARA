'use strict';

const BUILD_VERSION = '2026-03-29-chatid-html-v5';
const TELEGRAM_TOKEN_OVERRIDE = '8717313831:AAHjMcIbJUKf_ycu1DwNf2MIWrGVQiMc3ig';
const LARA_APP_URL_OVERRIDE = 'https://zanier-semilegislative-makeda.ngrok-free.dev';
const PUBLIC_COMMANDS = [
  {
    command: 'start',
    description: 'Show help and your chat ID',
  },
  {
    command: 'help',
    description: 'Show help and your chat ID',
  },
  {
    command: 'chatid',
    description: 'Show your Telegram chat ID',
  },
  {
    command: 'check',
    description: 'Show pending order summary',
  },
];

let publicCommandsSyncPromise = null;
let publicCommandsSyncedAt = 0;
let webhookSyncPromise = null;
let webhookSyncedAt = 0;
let lastWebhookInfo = null;

function getTelegramToken() {
  // Temporary override while the Vercel env value is being corrected.
  return String(TELEGRAM_TOKEN_OVERRIDE || process.env.TELEGRAM_BOT_TOKEN || process.env.TELEGRAM_TOKEN || '').trim();
}

function ensureConfigured() {
  if (!getTelegramToken()) {
    throw new Error('Missing env TELEGRAM_BOT_TOKEN or TELEGRAM_TOKEN');
  }
}

function getAppBaseUrl() {
  return String(
    LARA_APP_URL_OVERRIDE
      || process.env.LARA_APP_URL
      || process.env.APP_URL
      || process.env.SAKAN_APP_URL
      || ''
  ).trim().replace(/\/+$/, '');
}

function escapeHtml(value) {
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function normalizeCommand(text) {
  const first = String(text || '').trim().split(/\s+/)[0] || '';
  if (!first.startsWith('/')) {
    return '';
  }

  return first.slice(1).split('@')[0].toLowerCase();
}

function buildAppUrl(path) {
  const baseUrl = getAppBaseUrl();
  if (!baseUrl) {
    return '';
  }

  return new URL(String(path || ''), `${baseUrl}/`).toString();
}

function isAllowedInlineKeyboardUrl(value) {
  const url = String(value || '').trim();
  if (!url) {
    return false;
  }

  try {
    const parsed = new URL(url);
    return parsed.protocol === 'http:' || parsed.protocol === 'https:';
  } catch {
    return false;
  }
}

function buildHelpMessage(chatId) {
  const safeChatId = escapeHtml(chatId);

  return [
    '<b>Telegram Chat ID Bot</b>',
    '',
    `Chat ID: <code>${safeChatId}</code>`,
    '',
    '<b>Commands</b>',
    '• /start - Show help and your chat ID',
    '• /help - Show help and your chat ID',
    '• /chatid - Show this chat ID',
    '• /check - Show pending order summary',
  ].join('\n');
}

function buildChatIdMessage(chatId) {
  const safeChatId = escapeHtml(chatId);

  return [
    '<b>Your Telegram Chat ID</b>',
    '',
    `<code>${safeChatId}</code>`,
    '',
    'Use this value anywhere you need your Telegram <code>chat_id</code>.',
  ].join('\n');
}

async function parseJsonBody(req) {
  if (req.body && typeof req.body === 'object') {
    return req.body;
  }

  if (typeof req.body === 'string' && req.body.trim() !== '') {
    return JSON.parse(req.body);
  }

  const chunks = [];
  for await (const chunk of req) {
    chunks.push(Buffer.isBuffer(chunk) ? chunk : Buffer.from(chunk));
  }

  const raw = Buffer.concat(chunks).toString('utf8').trim();
  if (!raw) {
    return null;
  }

  return JSON.parse(raw);
}

function json(res, status, payload) {
  res.statusCode = status;
  res.setHeader('Content-Type', 'application/json; charset=UTF-8');
  res.end(JSON.stringify(payload));
}

function getHeaderValue(headers, name) {
  const value = headers ? headers[name] : '';
  if (Array.isArray(value)) {
    return String(value[0] || '').trim();
  }

  return String(value || '').trim();
}

function getRequestOrigin(req) {
  const forwardedProto = getHeaderValue(req.headers, 'x-forwarded-proto');
  const forwardedHost = getHeaderValue(req.headers, 'x-forwarded-host');
  const host = forwardedHost || getHeaderValue(req.headers, 'host');
  if (!host) {
    return '';
  }

  const proto = (forwardedProto.split(',')[0] || 'https').trim() || 'https';
  return `${proto}://${host}`;
}

function getExpectedWebhookUrl(req) {
  const origin = getRequestOrigin(req);
  if (!origin) {
    return '';
  }

  return new URL('/api', origin).toString();
}

async function telegramApi(method, payload) {
  ensureConfigured();

  const token = getTelegramToken();
  const response = await fetch(`https://api.telegram.org/bot${token}/${method}`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
    },
    body: JSON.stringify(payload),
  });

  const data = await response.json();
  if (!response.ok || !data || data.ok !== true) {
    const description = data && data.description ? data.description : `Telegram API ${method} failed.`;
    throw new Error(description);
  }

  return data.result;
}

async function getBotInfo() {
  const data = await telegramApi('getMe', {});

  return {
    id: data && data.id ? data.id : null,
    is_bot: Boolean(data && data.is_bot),
    username: data && data.username ? String(data.username) : '',
    can_join_groups: Boolean(data && data.can_join_groups),
    can_read_all_group_messages: Boolean(data && data.can_read_all_group_messages),
    supports_inline_queries: Boolean(data && data.supports_inline_queries),
  };
}

function normalizeWebhookInfo(info, expectedUrl, changed) {
  const out = info && typeof info === 'object' ? info : {};

  return {
    expected_url: expectedUrl,
    url: out.url ? String(out.url) : '',
    matches_expected: Boolean(expectedUrl) && String(out.url || '') === expectedUrl,
    changed,
    pending_update_count: Number(out.pending_update_count || 0),
    last_error_date: out.last_error_date ? new Date(Number(out.last_error_date) * 1000).toISOString() : null,
    last_error_message: out.last_error_message ? String(out.last_error_message) : '',
    max_connections: out.max_connections ? Number(out.max_connections) : null,
    allowed_updates: Array.isArray(out.allowed_updates) ? out.allowed_updates : [],
  };
}

async function syncWebhook(req, force = false) {
  const now = Date.now();
  const maxAgeMs = 15 * 60 * 1000;
  if (!force && webhookSyncedAt && now - webhookSyncedAt < maxAgeMs) {
    return lastWebhookInfo;
  }

  if (webhookSyncPromise) {
    return webhookSyncPromise;
  }

  webhookSyncPromise = (async () => {
    const expectedUrl = getExpectedWebhookUrl(req);
    let info = await telegramApi('getWebhookInfo', {});
    let changed = false;

    if (expectedUrl && String(info && info.url ? info.url : '') !== expectedUrl) {
      await telegramApi('setWebhook', {
        url: expectedUrl,
        allowed_updates: ['message', 'edited_message'],
      });
      changed = true;
      info = await telegramApi('getWebhookInfo', {});
    }

    webhookSyncedAt = Date.now();
    lastWebhookInfo = normalizeWebhookInfo(info, expectedUrl, changed);
    return lastWebhookInfo;
  })().finally(() => {
    webhookSyncPromise = null;
  });

  return webhookSyncPromise;
}

async function syncPublicCommands(force = false) {
  const now = Date.now();
  const maxAgeMs = 6 * 60 * 60 * 1000;
  if (!force && publicCommandsSyncedAt && now - publicCommandsSyncedAt < maxAgeMs) {
    return;
  }

  if (publicCommandsSyncPromise) {
    return publicCommandsSyncPromise;
  }

  publicCommandsSyncPromise = telegramApi('setMyCommands', {
    scope: {
      type: 'default',
    },
    commands: PUBLIC_COMMANDS,
  })
    .then(() => {
      publicCommandsSyncedAt = Date.now();
    })
    .finally(() => {
      publicCommandsSyncPromise = null;
    });

  return publicCommandsSyncPromise;
}

async function sendHtmlMessage(chatId, html, extra = {}) {
  return telegramApi('sendMessage', {
    chat_id: chatId,
    text: html,
    parse_mode: 'HTML',
    disable_web_page_preview: true,
    ...extra,
  });
}

async function getJson(url, { method = 'GET', payload } = {}) {
  const options = {
    method,
    headers: {
      'Content-Type': 'application/json',
    },
  };

  if (payload !== undefined) {
    options.body = JSON.stringify(payload);
  }

  const response = await fetch(url, options);
  const text = await response.text();

  let data = null;
  try {
    data = text ? JSON.parse(text) : null;
  } catch {
    data = null;
  }

  if (!response.ok || !data || data.ok !== true) {
    const description = data && data.error ? data.error : `Request failed with HTTP ${response.status}`;
    throw new Error(description);
  }

  return data;
}

function buildCheckReplyMarkup(summary) {
  const adminOrdersUrl = summary && summary.admin_orders_url ? String(summary.admin_orders_url) : '';
  if (!isAllowedInlineKeyboardUrl(adminOrdersUrl)) {
    return null;
  }

  return {
    inline_keyboard: [
      [
        {
          text: 'Open Orders',
          url: adminOrdersUrl,
        },
      ],
    ],
  };
}

function buildCheckMessage(data) {
  const summary = data && typeof data.summary === 'object' ? data.summary : {};
  const pendingOrders = Array.isArray(data && data.pending_orders) ? data.pending_orders : [];

  const lines = [
    '<b>Order Check</b>',
    '',
    `Pending: <b>${escapeHtml(summary.pending_orders ?? 0)}</b>`,
    `Preparing: <b>${escapeHtml(summary.preparing_orders ?? 0)}</b>`,
    `Closed: <b>${escapeHtml(summary.closed_orders ?? 0)}</b>`,
    `Total: <b>${escapeHtml(summary.total_orders ?? 0)}</b>`,
  ];

  if (pendingOrders.length > 0) {
    lines.push('', '<b>Latest Pending Orders</b>');

    for (const order of pendingOrders) {
      const serial = escapeHtml(order && order.serial ? order.serial : '000000');
      const customerName = escapeHtml(order && order.customer_name ? order.customer_name : 'Unknown customer');
      const total = escapeHtml(order && order.total_amount_display ? order.total_amount_display : 'N/A');
      const orderedAt = escapeHtml(order && order.ordered_at_display ? order.ordered_at_display : 'N/A');

      lines.push(`• #${serial} - ${customerName}`);
      lines.push(`  ${total} - ${orderedAt}`);
    }
  } else {
    lines.push('', 'No pending orders right now.');
  }

  return lines.join('\n');
}

async function handleCheckCommand(chatId, replyToMessageId) {
  const checkUrl = buildAppUrl('/api/telegram/check/');
  if (!checkUrl) {
    await sendHtmlMessage(chatId, '<b>/check</b>\n\nApp URL is not configured for this bot.', {
      reply_to_message_id: replyToMessageId,
    });
    return;
  }

  try {
    const data = await getJson(checkUrl, {
      method: 'POST',
      payload: {
        chat_id: String(chatId),
      },
    });

    const extra = {
      reply_to_message_id: replyToMessageId,
    };

    const replyMarkup = buildCheckReplyMarkup(data.summary);
    if (replyMarkup) {
      extra.reply_markup = replyMarkup;
    }

    await sendHtmlMessage(chatId, buildCheckMessage(data), extra);
  } catch (error) {
    await sendHtmlMessage(
      chatId,
      `<b>/check</b>\n\n${escapeHtml(error instanceof Error ? error.message : 'Unable to load pending orders right now.')}`,
      {
        reply_to_message_id: replyToMessageId,
      }
    );
  }
}

async function handleTelegramUpdate(update) {
  const message = update && (update.message || update.edited_message);
  if (!message) {
    return;
  }

  const chatId = message.chat && message.chat.id;
  const messageId = message.message_id;
  const text = String(message.text || '').trim();
  if (!chatId || !text) {
    return;
  }

  const command = normalizeCommand(text);

  if (command === 'chatid') {
    await sendHtmlMessage(chatId, buildChatIdMessage(chatId), {
      reply_to_message_id: messageId,
    });
    return;
  }

  if (command === 'check') {
    await handleCheckCommand(chatId, messageId);
    return;
  }

  if (command === 'start' || command === 'help') {
    await sendHtmlMessage(chatId, buildHelpMessage(chatId), {
      reply_to_message_id: messageId,
    });
    return;
  }

  await sendHtmlMessage(chatId, buildHelpMessage(chatId), {
    reply_to_message_id: messageId,
  });
}

module.exports = async (req, res) => {
  if (req.method === 'GET') {
    let bot = null;
    let webhook = null;
    let botError = '';
    let webhookError = '';
    let commandsError = '';

    try {
      await syncPublicCommands();
    } catch (error) {
      console.error('telegram.commands sync error', error);
      commandsError = error instanceof Error ? error.message : String(error);
    }

    try {
      webhook = await syncWebhook(req);
    } catch (error) {
      console.error('telegram.webhook sync error', error);
      webhookError = error instanceof Error ? error.message : String(error);
    }

    try {
      bot = await getBotInfo();
    } catch (error) {
      console.error('telegram.bot info error', error);
      botError = error instanceof Error ? error.message : String(error);
    }

    return json(res, 200, {
      ok: true,
      service: 'telegram-chatid-bot',
      version: BUILD_VERSION,
      configured: {
        telegram_token: Boolean(getTelegramToken()),
        app_url: Boolean(getAppBaseUrl()),
      },
      public_commands: PUBLIC_COMMANDS.map((item) => `/${item.command}`),
      token_source: TELEGRAM_TOKEN_OVERRIDE ? 'code_override' : 'env',
      app_url_source: LARA_APP_URL_OVERRIDE ? 'code_override' : 'env',
      bot,
      bot_error: botError,
      webhook,
      webhook_error: webhookError,
      commands_error: commandsError,
    });
  }

  if (req.method !== 'POST') {
    return json(res, 405, {
      ok: false,
      error: 'Method Not Allowed',
    });
  }

  let body = null;

  try {
    body = await parseJsonBody(req);
  } catch (error) {
    return json(res, 400, {
      ok: false,
      error: 'Invalid JSON body',
    });
  }

  if (!body) {
    return json(res, 400, {
      ok: false,
      error: 'Invalid JSON body',
    });
  }

  if (typeof body.update_id === 'number') {
    try {
      await syncPublicCommands();
      await handleTelegramUpdate(body);
    } catch (error) {
      console.error('telegram.update error', error);
    }

    return json(res, 200, { ok: true });
  }

  return json(res, 400, {
    ok: false,
    error: 'Unsupported payload',
  });
};
