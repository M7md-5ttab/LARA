'use strict';

const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');

const BUILD_VERSION = '2026-04-01-code-override-debug';
// Temporary debug overrides. Remove these after Vercel env vars are fixed.
const CONFIG_OVERRIDES = {
  TELEGRAM_BOT_TOKEN: '8717313831:AAHjMcIbJUKf_ycu1DwNf2MIWrGVQiMc3ig',
  LARA_APP_URL: 'https://darkgoldenrod-woodcock-943182.hostingersite.com',
};
const RTL = '\u200F';
const EMPTY_INLINE_KEYBOARD = { inline_keyboard: [] };
const STATE_CANCEL_REASON = 'awaiting_cancel_reason';
const STATE_DELIVERY_NAME = 'awaiting_delivery_name';
const DELIVERY_STATE_TTL_SECONDS = 5 * 60;
const CANCEL_STATE_TTL_SECONDS = 15 * 60;
const DEFAULT_PUBLIC_COMMANDS = [
  {
    command: 'start',
    description: 'ابدأ',
  },
  {
    command: 'chatid',
    description: 'Show your Telegram chat ID',
  },
];
const COMMAND_DEFINITIONS = {
  start: {
    command: 'start',
    description: 'ابدأ',
  },
  chatid: {
    command: 'chatid',
    description: 'Show your Telegram chat ID',
  },
  help: {
    command: 'help',
    description: 'عرض المساعدة',
  },
  pending: {
    command: 'pending',
    description: 'عرض الطلبات المعلقة',
  },
  prepared: {
    command: 'prepared',
    description: 'عرض الطلبات قيد التحضير',
  },
  check: {
    command: 'check',
    description: 'عرض ملخص الطلبات',
  },
};
const REQUIRED_WEBHOOK_UPDATES = ['message', 'edited_message', 'callback_query'];
const LOCAL_ENV = loadLocalEnvFiles();

let publicCommandsSyncPromise = null;
let publicCommandsSyncedAt = 0;
let webhookSyncPromise = null;
let webhookSyncedAt = 0;
let lastWebhookInfo = null;
const chatCommandsSyncCache = new Map();

function normalizeConfigScalar(value) {
  let normalized = String(value || '').trim();

  while (
    normalized.length >= 2
    && (
      (normalized.startsWith('"') && normalized.endsWith('"'))
      || (normalized.startsWith('\'') && normalized.endsWith('\''))
    )
  ) {
    normalized = normalized.slice(1, -1).trim();
  }

  return normalized;
}

function normalizeTelegramToken(value) {
  const normalized = normalizeConfigScalar(value);
  if (!normalized) {
    return '';
  }

  const embeddedMatch = normalized.match(/\d{6,}:[A-Za-z0-9_-]{20,}/);
  if (embeddedMatch && embeddedMatch[0]) {
    return embeddedMatch[0];
  }

  const urlMatch = normalized.match(/(?:https?:\/\/api\.telegram\.org\/)?bot([^/?#]+)(?:[/?#]|$)/i);
  if (urlMatch && urlMatch[1]) {
    return normalizeConfigScalar(urlMatch[1]);
  }

  if (/^bot\d+:/i.test(normalized)) {
    return normalized.slice(3).trim();
  }

  return normalized;
}

function parseEnvFile(content) {
  const values = {};
  const lines = String(content || '').split(/\r\n|\r|\n/);

  for (const line of lines) {
    const trimmed = line.trim();
    if (!trimmed || trimmed.startsWith('#')) {
      continue;
    }

    const match = trimmed.match(/^([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/);
    if (!match) {
      continue;
    }

    const key = match[1];
    values[key] = normalizeConfigScalar(match[2] || '');
  }

  return values;
}

function loadLocalEnvFiles() {
  const files = [
    path.resolve(__dirname, '../.env'),
    path.resolve(__dirname, '../../.env'),
  ];
  const values = {};

  for (const file of files) {
    if (!fs.existsSync(file)) {
      continue;
    }

    try {
      const parsed = parseEnvFile(fs.readFileSync(file, 'utf8'));
      for (const [key, value] of Object.entries(parsed)) {
        if (!(key in values)) {
          values[key] = value;
        }
      }
    } catch (error) {
      console.error('telegram.local env load error', file, error);
    }
  }

  return values;
}

function getConfigValue(keys) {
  for (const key of keys) {
    const overrideValue = normalizeConfigScalar(CONFIG_OVERRIDES[key] || '');
    if (overrideValue) {
      return {
        value: overrideValue,
        source: `code_override:${key}`,
      };
    }

    const processValue = normalizeConfigScalar(process.env[key] || '');
    if (processValue) {
      return {
        value: processValue,
        source: `process.env:${key}`,
      };
    }

    const localValue = normalizeConfigScalar(LOCAL_ENV[key] || '');
    if (localValue) {
      return {
        value: localValue,
        source: `local_env_file:${key}`,
      };
    }
  }

  return {
    value: '',
    source: 'missing',
  };
}

function getTelegramToken() {
  return normalizeTelegramToken(getConfigValue(['TELEGRAM_BOT_TOKEN', 'TELEGRAM_TOKEN']).value);
}

function getTelegramTokenSource() {
  return getConfigValue(['TELEGRAM_BOT_TOKEN', 'TELEGRAM_TOKEN']).source;
}

function getBridgeSecret() {
  const token = getTelegramToken();
  if (!token) {
    return '';
  }

  return crypto.createHash('sha256').update(`lara-telegram-bridge|${token}`).digest('hex');
}

function ensureConfigured() {
  if (!getTelegramToken()) {
    throw new Error('Missing env TELEGRAM_BOT_TOKEN or TELEGRAM_TOKEN');
  }
}

function getAppBaseUrl() {
  return getConfigValue(['LARA_APP_URL', 'APP_URL', 'SAKAN_APP_URL']).value.replace(/\/+$/, '');
}

function getAppBaseUrlSource() {
  return getConfigValue(['LARA_APP_URL', 'APP_URL', 'SAKAN_APP_URL']).source;
}

function escapeHtml(value) {
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function normalizeText(value) {
  return String(value || '').replace(/\s+/g, ' ').trim();
}

function normalizeCommand(text) {
  const first = normalizeText(text).split(/\s+/)[0] || '';
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

function rtlText(lines) {
  const source = Array.isArray(lines) ? lines : String(lines || '').split(/\r\n|\r|\n/);
  const output = [];

  for (const line of source) {
    const parts = String(line).split(/\r\n|\r|\n/);
    for (const part of parts) {
      output.push(`${RTL}${part}`);
    }
  }

  return output.join('\n');
}

function safeDisplay(value, fallback = 'غير متوفر') {
  const normalized = normalizeText(value);
  return normalized || fallback;
}

function formatMoneyAr(value) {
  const amount = Number(value || 0);
  const normalized = Number.isFinite(amount) ? amount : 0;
  return `${normalized.toFixed(2)} ج.م`;
}

function splitMultilineText(value) {
  const normalized = String(value || '').trim();
  if (!normalized) {
    return [];
  }

  return normalized
    .split(/\r\n|\r|\n/)
    .map((line) => line.trim())
    .filter(Boolean);
}

function createPermissionsObject(chatId, recipient = {}) {
  const normalizedChatId = String(chatId || recipient.chat_id || '').trim();

  return {
    chat_id: normalizedChatId,
    is_active: Boolean(recipient && recipient.is_active),
    can_receive_notifications: Boolean(recipient && recipient.can_receive_notifications),
    can_use_pending: Boolean(recipient && recipient.can_use_pending),
    can_use_prepared: Boolean(recipient && recipient.can_use_prepared),
    can_use_check: Boolean(recipient && recipient.can_use_check),
    can_use_help: Boolean(recipient && recipient.can_use_help),
    available_commands: Array.isArray(recipient && recipient.available_commands) ? recipient.available_commands : [],
  };
}

function buildCommandListForPermissions(permissions) {
  const commandKeys = ['start', 'chatid'];

  if (permissions && permissions.can_use_help) {
    commandKeys.push('help');
  }

  if (permissions && permissions.can_use_pending) {
    commandKeys.push('pending');
  }

  if (permissions && permissions.can_use_prepared) {
    commandKeys.push('prepared');
  }

  if (permissions && permissions.can_use_check) {
    commandKeys.push('check');
  }

  return commandKeys.map((key) => COMMAND_DEFINITIONS[key]).filter(Boolean);
}

function buildAssistanceMessage(chatId, permissions, includeAccessNotice = true) {
  const safeChatId = escapeHtml(chatId);
  const lines = [
    '<b>🤖 أوامر بوت الطلبات</b>',
    '',
    '• <code>/start</code> عرض الأوامر المتاحة لك',
    '• <code>/chatid</code> Show this chat ID',
  ];

  if (permissions && permissions.can_use_help) {
    lines.push('• <code>/help</code> عرض المساعدة');
  }

  if (permissions && permissions.can_use_pending) {
    lines.push('• <code>/pending</code> عرض الطلبات المعلقة');
  }

  if (permissions && permissions.can_use_prepared) {
    lines.push('• <code>/prepared</code> عرض الطلبات قيد التحضير');
  }

  if (permissions && permissions.can_use_check) {
    lines.push('• <code>/check</code> عرض ملخص سريع للطلبات');
  }

  lines.push('', `معرّف هذه المحادثة: <code>${safeChatId}</code>`);

  if (includeAccessNotice) {
    lines.push('');
    if (permissions && permissions.is_active) {
      lines.push('الأوامر الظاهرة هنا تعتمد على الصلاحيات المحددة لهذا العضو من لوحة الإدارة.');
    } else {
      lines.push('هذه المحادثة غير مفعلة للأوامر الإدارية حاليًا. المتاح الآن هو <code>/chatid</code> فقط.');
    }
  }

  return rtlText(lines);
}

function buildHelpMessage(chatId, permissions) {
  return buildAssistanceMessage(chatId, permissions, true);
}

function buildStartMessage(chatId, permissions) {
  return buildAssistanceMessage(chatId, permissions, true);
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

function buildUnauthorizedMessage() {
  return rtlText([
    '<b>⛔ غير مصرح</b>',
    '',
    'هذا الشات غير مفعّل لإدارة الطلبات.',
    'أضف الـ <code>chat_id</code> من لوحة الإدارة أولًا ثم أعد المحاولة.',
  ]);
}

function buildBridgeConfigErrorMessage() {
  return rtlText([
    '<b>⚠️ خطأ في الإعداد</b>',
    '',
    'رابط التطبيق أو إعداد الربط الداخلي للبوت غير صالح.',
    'تأكد أن رابط الـ API يعيد JSON مباشرة وليس صفحة HTML أو صفحة حماية JavaScript من الاستضافة.',
  ]);
}

function buildCommandErrorMessage(title, message) {
  return rtlText([
    `<b>⚠️ ${escapeHtml(title)}</b>`,
    '',
    escapeHtml(message || 'حدث خطأ غير متوقع.'),
  ]);
}

function buildOrdersHeaderMessage(status, count) {
  const isPending = status === 'pending';

  return rtlText([
    `<b>${isPending ? '📋 الطلبات المعلقة' : '📦 الطلبات قيد التحضير'}</b>`,
    '',
    `العدد الحالي: <b>${escapeHtml(String(count))}</b>`,
  ]);
}

function buildEmptyOrdersMessage(status) {
  const isPending = status === 'pending';

  return rtlText([
    `<b>${isPending ? '📭 لا توجد طلبات معلقة' : '📭 لا توجد طلبات قيد التحضير'}</b>`,
    '',
    isPending ? 'لا توجد طلبات معلقة حاليًا.' : 'لا توجد طلبات قيد التحضير حاليًا.',
  ]);
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
          text: '📂 فتح صفحة الطلبات',
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
    '<b>📊 ملخص الطلبات</b>',
    '',
    `المعلقة: <b>${escapeHtml(summary.pending_orders ?? 0)}</b>`,
    `قيد التحضير: <b>${escapeHtml(summary.preparing_orders ?? 0)}</b>`,
    `المقفلة: <b>${escapeHtml(summary.closed_orders ?? 0)}</b>`,
    `إجمالي الطلبات: <b>${escapeHtml(summary.total_orders ?? 0)}</b>`,
  ];

  if (pendingOrders.length > 0) {
    lines.push('', '<b>🟡 آخر الطلبات المعلقة</b>');

    for (const order of pendingOrders) {
      const serial = escapeHtml(order && order.serial ? order.serial : '000000');
      const customerName = escapeHtml(order && order.customer_name ? order.customer_name : 'غير معروف');
      const total = escapeHtml(order && order.total_amount_display_ar ? order.total_amount_display_ar : formatMoneyAr(order && order.total_amount));
      const orderedAt = escapeHtml(order && order.ordered_at_display_ar ? order.ordered_at_display_ar : safeDisplay(order && order.ordered_at));

      lines.push(`• <code>#${serial}</code> - ${customerName}`);
      lines.push(`  ${total} - ${orderedAt}`);
    }
  } else {
    lines.push('', 'لا توجد طلبات معلقة الآن.');
  }

  return rtlText(lines);
}

function buildOrderTitle(status) {
  switch (String(status || '')) {
    case 'pending':
      return '🟡 طلب معلق';
    case 'preparing':
      return '🟠 طلب قيد التحضير';
    case 'delivered':
      return '🟢 طلب تم تسليمه';
    case 'cancelled':
      return '🔴 طلب ملغي';
    default:
      return '🧾 طلب';
  }
}

function buildOrderMessage(order) {
  const serial = escapeHtml(order && order.serial ? order.serial : '000000');
  const customerName = escapeHtml(order && order.customer_name ? order.customer_name : 'غير معروف');
  const primaryPhone = escapeHtml(safeDisplay(order && order.phone_primary));
  const secondaryPhoneRaw = safeDisplay(order && order.phone_secondary);
  const secondaryPhone = secondaryPhoneRaw === 'غير متوفر'
    ? 'غير متوفر'
    : `<code>${escapeHtml(secondaryPhoneRaw)}</code>`;
  const total = escapeHtml(order && order.total_amount_display_ar ? order.total_amount_display_ar : formatMoneyAr(order && order.total_amount));
  const orderedAt = escapeHtml(safeDisplay(order && (order.ordered_at_display_ar || order.ordered_at)));
  const lines = [
    `<b>${buildOrderTitle(order && order.status)}</b>`,
    '',
    `<b>رقم الطلب:</b> <code>#${serial}</code>`,
    `<b>العميل:</b> ${customerName}`,
    `<b>الهاتف 1:</b> <code>${primaryPhone}</code>`,
    `<b>الهاتف 2:</b> ${secondaryPhone}`,
    `<b>الإجمالي:</b> <b>${total}</b>`,
    `<b>وقت الطلب:</b> ${orderedAt}`,
  ];

  if (order && order.status === 'preparing' && safeDisplay(order.preparing_at_display_ar || order.preparing_at) !== 'غير متوفر') {
    lines.push(`<b>بداية التحضير:</b> ${escapeHtml(safeDisplay(order.preparing_at_display_ar || order.preparing_at))}`);
  }

  if (order && order.status === 'delivered') {
    if (normalizeText(order.delivered_by)) {
      lines.push(`<b>اسم المندوب:</b> ${escapeHtml(normalizeText(order.delivered_by))}`);
    }

    if (safeDisplay(order.closed_at_display_ar || order.closed_at) !== 'غير متوفر') {
      lines.push(`<b>وقت التسليم:</b> ${escapeHtml(safeDisplay(order.closed_at_display_ar || order.closed_at))}`);
    }
  }

  if (order && order.status === 'cancelled') {
    if (normalizeText(order.cancel_reason)) {
      lines.push(`<b>سبب الإلغاء:</b> ${escapeHtml(normalizeText(order.cancel_reason))}`);
    }

    if (safeDisplay(order.closed_at_display_ar || order.closed_at) !== 'غير متوفر') {
      lines.push(`<b>وقت الإغلاق:</b> ${escapeHtml(safeDisplay(order.closed_at_display_ar || order.closed_at))}`);
    }
  }

  const addressLines = splitMultilineText(order && order.address);
  if (addressLines.length > 0) {
    lines.push('', '<b>📍 العنوان</b>');
    for (const line of addressLines) {
      lines.push(escapeHtml(line));
    }
  }

  const items = Array.isArray(order && order.items) ? order.items : [];
  if (items.length > 0) {
    lines.push('', '<b>🧾 الأصناف</b>');
    for (const item of items) {
      const name = escapeHtml(item && item.name ? item.name : 'صنف');
      const quantity = escapeHtml(String(item && item.quantity ? item.quantity : 0));
      const lineTotal = escapeHtml(item && item.line_total_display_ar ? item.line_total_display_ar : formatMoneyAr(item && item.line_total));

      lines.push(`• ${name} × ${quantity} - ${lineTotal}`);
    }
  }

  return rtlText(lines);
}

function buildOrderReplyMarkup(order) {
  const serial = String(order && order.serial ? order.serial : '').trim();
  if (!serial) {
    return null;
  }

  if (order.status === 'pending') {
    return {
      inline_keyboard: [
        [
          { text: '🧑‍🍳 تجهيز الطلب', callback_data: `p1:${serial}` },
          { text: '❌ إلغاء الطلب', callback_data: `c1:${serial}` },
        ],
      ],
    };
  }

  if (order.status === 'preparing') {
    return {
      inline_keyboard: [
        [
          { text: '✅ تم التسليم', callback_data: `d1:${serial}` },
          { text: '❌ إلغاء الطلب', callback_data: `c1:${serial}` },
        ],
      ],
    };
  }

  return null;
}

function buildPrepareConfirmMessage(serial) {
  return rtlText([
    '<b>🧑‍🍳 تأكيد بدء التحضير</b>',
    '',
    `هل تريد تحويل الطلب <code>#${escapeHtml(serial)}</code> إلى <b>قيد التحضير</b>؟`,
    'هذا يعني أن الطلب دخل مرحلة التجهيز داخل المطعم.',
  ]);
}

function buildDeliverConfirmMessage(serial) {
  return rtlText([
    '<b>✅ تأكيد التسليم</b>',
    '',
    `هل تؤكد أن الطلب <code>#${escapeHtml(serial)}</code> تم تسليمه للعميل وتم استلام المبلغ؟`,
    'بعد التأكيد سيُطلب منك إدخال اسم المندوب.',
  ]);
}

function buildPrepareConfirmReplyMarkup(serial, sourceMessageId) {
  return {
    inline_keyboard: [
      [
        { text: '✅ تأكيد التجهيز', callback_data: `p2:${serial}:${sourceMessageId}` },
        { text: '↩️ رجوع', callback_data: `px:${serial}` },
      ],
    ],
  };
}

function buildDeliverConfirmReplyMarkup(serial, sourceMessageId) {
  return {
    inline_keyboard: [
      [
        { text: '✅ تأكيد التسليم', callback_data: `d2:${serial}:${sourceMessageId}` },
        { text: '↩️ رجوع', callback_data: `dx:${serial}` },
      ],
    ],
  };
}

function buildCancelPromptMessage(serial) {
  return rtlText([
    '<b>📝 اكتب سبب الإلغاء</b>',
    '',
    `اكتب الآن سبب إلغاء الطلب <code>#${escapeHtml(serial)}</code> في رسالة واحدة.`,
    'يجب أن يكون السبب واضحًا وبحد أدنى 10 أحرف.',
    'إذا أرسلت أمرًا آخر سيتم إلغاء العملية الحالية.',
  ]);
}

function buildDeliveryPromptMessage(serial) {
  return rtlText([
    '<b>🚚 اكتب اسم المندوب</b>',
    '',
    `اكتب اسم الشخص الذي سلّم الطلب <code>#${escapeHtml(serial)}</code>.`,
    'يجب أن ترد على هذه الرسالة مباشرة خلال 5 دقائق.',
    'إذا كان الاسم أقل من 3 أحرف أو كان الرد غير صالح فسيتم إلغاء العملية.',
  ]);
}

function buildPreparationDoneMessage(order) {
  return rtlText([
    '<b>✅ تم تحديث الحالة</b>',
    '',
    `تم تحويل الطلب <code>#${escapeHtml(order && order.serial ? order.serial : '')}</code> إلى <b>قيد التحضير</b>.`,
  ]);
}

function buildCancelDoneMessage(order) {
  return rtlText([
    '<b>✅ تم إلغاء الطلب</b>',
    '',
    `تم إلغاء الطلب <code>#${escapeHtml(order && order.serial ? order.serial : '')}</code> بنجاح.`,
  ]);
}

function buildDeliveredDoneMessage(order) {
  return rtlText([
    '<b>✅ تم تأكيد التسليم</b>',
    '',
    `تم إغلاق الطلب <code>#${escapeHtml(order && order.serial ? order.serial : '')}</code> على أنه <b>تم التسليم</b>.`,
  ]);
}

function buildDismissedMessage() {
  return rtlText([
    '<b>↩️ تم التراجع</b>',
    '',
    'لم يتم تنفيذ أي تغيير على الطلب.',
  ]);
}

function buildStateExpiredMessage(stateKey, context = {}) {
  const serial = context && context.serial ? ` <code>#${escapeHtml(context.serial)}</code>` : '';

  if (stateKey === STATE_DELIVERY_NAME) {
    return rtlText([
      '<b>⌛ انتهت المهلة</b>',
      '',
      `تم إلغاء انتظار اسم المندوب للطلب${serial} بعد مرور 5 دقائق.`,
    ]);
  }

  return rtlText([
    '<b>⌛ انتهت المهلة</b>',
    '',
    `تم إلغاء انتظار سبب الإلغاء للطلب${serial}.`,
  ]);
}

function buildCancelAbortMessage(reason, context = {}) {
  const serial = context && context.serial ? ` <code>#${escapeHtml(context.serial)}</code>` : '';

  if (reason === 'command') {
    return rtlText([
      '<b>↩️ تم إلغاء العملية</b>',
      '',
      `تم إلغاء انتظار سبب إلغاء الطلب${serial} لأنك أرسلت أمرًا آخر.`,
    ]);
  }

  return rtlText([
    '<b>❌ تم إلغاء العملية</b>',
    '',
    `تم إلغاء انتظار سبب إلغاء الطلب${serial} لأن الرسالة فارغة أو أقل من 10 أحرف.`,
  ]);
}

function buildDeliveryAbortMessage(reason, context = {}) {
  const serial = context && context.serial ? ` <code>#${escapeHtml(context.serial)}</code>` : '';

  if (reason === 'command') {
    return rtlText([
      '<b>↩️ تم إلغاء العملية</b>',
      '',
      `تم إلغاء انتظار اسم المندوب للطلب${serial} لأنك أرسلت أمرًا آخر.`,
    ]);
  }

  if (reason === 'invalid_reply') {
    return rtlText([
      '<b>❌ تم إلغاء العملية</b>',
      '',
      `تم إلغاء انتظار اسم المندوب للطلب${serial} لأن الرد لم يكن على الرسالة المطلوبة.`,
    ]);
  }

  return rtlText([
    '<b>❌ تم إلغاء العملية</b>',
    '',
    `تم إلغاء انتظار اسم المندوب للطلب${serial} لأن الاسم غير صالح أو أقل من 3 أحرف.`,
  ]);
}

function buildStateWaitingMessage(stateKey, context = {}) {
  const serial = context && context.serial ? ` <code>#${escapeHtml(context.serial)}</code>` : '';

  if (stateKey === STATE_DELIVERY_NAME) {
    return rtlText([
      '<b>⏳ بانتظار الرد</b>',
      '',
      `تم تسجيل انتظار اسم المندوب للطلب${serial}.`,
      'أرسل الاسم ردًا على الرسالة الأخيرة خلال 5 دقائق.',
    ]);
  }

  return rtlText([
    '<b>⏳ بانتظار الرد</b>',
    '',
    `تم تسجيل انتظار سبب الإلغاء للطلب${serial}.`,
    'أرسل السبب في رسالة واحدة واضحة.',
  ]);
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

function webhookNeedsAllowedUpdatesRefresh(info) {
  const allowedUpdates = Array.isArray(info && info.allowed_updates) ? info.allowed_updates.map((item) => String(item || '')) : [];
  if (allowedUpdates.length === 0) {
    return false;
  }

  return REQUIRED_WEBHOOK_UPDATES.some((item) => !allowedUpdates.includes(item));
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

    if (
      expectedUrl
      && (
        String(info && info.url ? info.url : '') !== expectedUrl
        || webhookNeedsAllowedUpdatesRefresh(info)
      )
    ) {
      await telegramApi('setWebhook', {
        url: expectedUrl,
        allowed_updates: REQUIRED_WEBHOOK_UPDATES,
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

async function syncChatCommands(chatId, permissions, force = false) {
  const chatKey = String(chatId);
  const commands = buildCommandListForPermissions(permissions);
  const signature = commands.map((item) => `${item.command}:${item.description}`).join('|');
  const now = Date.now();
  const cache = chatCommandsSyncCache.get(chatKey);
  const maxAgeMs = 6 * 60 * 60 * 1000;

  if (!force && cache && cache.signature === signature && now - cache.syncedAt < maxAgeMs) {
    return;
  }

  await telegramApi('setMyCommands', {
    scope: {
      type: 'chat',
      chat_id: chatId,
    },
    commands,
  });

  chatCommandsSyncCache.set(chatKey, {
    signature,
    syncedAt: now,
  });
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
    commands: DEFAULT_PUBLIC_COMMANDS,
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

async function sendForceReplyMessage(chatId, html, placeholder, extra = {}) {
  return sendHtmlMessage(chatId, html, {
    reply_markup: {
      force_reply: true,
      selective: true,
      input_field_placeholder: placeholder,
    },
    ...extra,
  });
}

async function answerCallbackQuery(callbackQueryId, text = '', extra = {}) {
  const payload = {
    callback_query_id: callbackQueryId,
    ...extra,
  };

  if (text) {
    payload.text = text;
  }

  return telegramApi('answerCallbackQuery', payload);
}

async function editHtmlMessage(chatId, messageId, html, extra = {}) {
  return telegramApi('editMessageText', {
    chat_id: chatId,
    message_id: messageId,
    text: html,
    parse_mode: 'HTML',
    disable_web_page_preview: true,
    ...extra,
  });
}

async function safeEditHtmlMessage(chatId, messageId, html, extra = {}) {
  try {
    return await editHtmlMessage(chatId, messageId, html, extra);
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    if (message.includes('message is not modified')) {
      return null;
    }

    throw error;
  }
}

async function getJson(url, { method = 'GET', payload, headers = {} } = {}) {
  const options = {
    method,
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json, text/plain;q=0.9, */*;q=0.8',
      ...headers,
    },
  };

  if (payload !== undefined) {
    options.body = JSON.stringify(payload);
  }

  const response = await fetch(url, options);
  const text = await response.text();
  const contentType = String(response.headers.get('content-type') || '').toLowerCase();

  let data = null;
  try {
    data = text ? JSON.parse(text) : null;
  } catch {
    data = null;
  }

  if (contentType.includes('text/html') && /__test=|\/aes\.js|This site requires Javascript to work/i.test(text)) {
    const error = new Error('The configured app URL is behind a JavaScript browser challenge and is not usable as a JSON API endpoint.');
    error.httpStatus = response.status;
    error.responseData = data;
    error.responseText = text;
    error.isBridgeConfigError = true;
    throw error;
  }

  if (!response.ok || !data || data.ok !== true) {
    const textSnippet = String(text || '').replace(/\s+/g, ' ').trim().slice(0, 240);
    const description = data && data.error
      ? data.error
      : (textSnippet ? `Expected JSON response but received: ${textSnippet}` : `Request failed with HTTP ${response.status}`);
    const error = new Error(description);
    error.httpStatus = response.status;
    error.responseData = data;
    error.responseText = text;
    throw error;
  }

  return data;
}

function buildBridgeHeaders() {
  const secret = getBridgeSecret();
  if (!secret) {
    throw new Error('Telegram bridge secret is not configured.');
  }

  return {
    'X-Telegram-Bot-Bridge': secret,
  };
}

async function callBridge(path, payload) {
  const url = buildAppUrl(path);
  if (!url) {
    throw new Error('App URL is not configured for this bot.');
  }

  return getJson(url, {
    method: 'POST',
    payload,
    headers: buildBridgeHeaders(),
  });
}

async function getMemberPermissions(chatId) {
  return callBridge('/api/telegram/member/', {
    chat_id: String(chatId),
  });
}

async function loadMemberPermissions(chatId) {
  const data = await getMemberPermissions(chatId);
  return createPermissionsObject(chatId, data && data.recipient ? data.recipient : {});
}

async function listOrders(chatId, status) {
  return callBridge('/api/telegram/orders/', {
    action: 'list_orders',
    chat_id: String(chatId),
    status,
  });
}

async function markOrderPreparing(chatId, serial) {
  return callBridge('/api/telegram/orders/', {
    action: 'mark_preparing',
    chat_id: String(chatId),
    serial,
  });
}

async function cancelOrder(chatId, serial, reason) {
  return callBridge('/api/telegram/orders/', {
    action: 'cancel_order',
    chat_id: String(chatId),
    serial,
    reason,
  });
}

async function markOrderDelivered(chatId, serial, deliveredBy) {
  return callBridge('/api/telegram/orders/', {
    action: 'mark_delivered',
    chat_id: String(chatId),
    serial,
    delivered_by: deliveredBy,
  });
}

async function getConversationStateSnapshot(chatId) {
  return callBridge('/api/telegram/state/', {
    action: 'get_state',
    chat_id: String(chatId),
  });
}

async function setConversationState(chatId, stateKey, context = {}, expiresInSeconds = 0) {
  const payload = {
    action: 'set_state',
    chat_id: String(chatId),
    state_key: stateKey,
    context,
  };

  if (expiresInSeconds > 0) {
    payload.expires_in_seconds = expiresInSeconds;
  }

  return callBridge('/api/telegram/state/', payload);
}

async function clearConversationState(chatId) {
  return callBridge('/api/telegram/state/', {
    action: 'clear_state',
    chat_id: String(chatId),
  });
}

function buildOriginalOrderEditMarkup(order) {
  return buildOrderReplyMarkup(order) || EMPTY_INLINE_KEYBOARD;
}

async function refreshOrderCardMessage(chatId, messageId, order) {
  try {
    await safeEditHtmlMessage(chatId, messageId, buildOrderMessage(order), {
      reply_markup: buildOriginalOrderEditMarkup(order),
    });
  } catch (error) {
    const replyMarkup = buildOrderReplyMarkup(order);
    const extra = replyMarkup ? { reply_markup: replyMarkup } : {};
    await sendHtmlMessage(chatId, buildOrderMessage(order), extra);
  }
}

function isUnauthorizedError(error) {
  const message = error instanceof Error ? error.message : String(error);
  return message.includes('غير مصرح');
}

function isBridgeConfigError(error) {
  const message = error instanceof Error ? error.message : String(error);
  return Boolean(error && error.isBridgeConfigError)
    || message.includes('bridge')
    || message.includes('App URL is not configured');
}

function isCommandAllowed(permissions, command) {
  if (!permissions || !permissions.is_active) {
    return false;
  }

  if (command === 'pending') {
    return permissions.can_use_pending === true;
  }

  if (command === 'prepared') {
    return permissions.can_use_prepared === true;
  }

  if (command === 'check') {
    return permissions.can_use_check === true;
  }

  if (command === 'help') {
    return permissions.can_use_help === true;
  }

  return false;
}

function isValidCancellationReason(text) {
  const normalized = normalizeText(text);
  return normalized.length >= 10 && !normalized.startsWith('/');
}

function isValidDeliveryName(text) {
  const normalized = normalizeText(text);
  return normalized.length >= 3
    && normalized.length <= 80
    && !normalized.startsWith('/')
    && /[\p{L}]/u.test(normalized);
}

function isReplyToPrompt(message, promptMessageId) {
  return Boolean(
    message
    && message.reply_to_message
    && Number(message.reply_to_message.message_id || 0) === Number(promptMessageId || 0)
  );
}

async function handleCheckCommand(chatId, replyToMessageId, permissions) {
  if (!isCommandAllowed(permissions, 'check')) {
    await sendHtmlMessage(chatId, buildUnauthorizedMessage(), {
      reply_to_message_id: replyToMessageId,
    });
    return;
  }

  try {
    const data = await callBridge('/api/telegram/check/', {
      chat_id: String(chatId),
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
    const fallback = isBridgeConfigError(error)
      ? buildBridgeConfigErrorMessage()
      : buildCommandErrorMessage('تعذر جلب الملخص', error instanceof Error ? error.message : 'تعذر تحميل ملخص الطلبات الآن.');

    await sendHtmlMessage(
      chatId,
      fallback,
      {
        reply_to_message_id: replyToMessageId,
      }
    );
  }
}

async function handleOrdersCommand(chatId, replyToMessageId, status, permissions) {
  const requiredCommand = status === 'pending' ? 'pending' : 'prepared';
  if (!isCommandAllowed(permissions, requiredCommand)) {
    await sendHtmlMessage(chatId, buildUnauthorizedMessage(), {
      reply_to_message_id: replyToMessageId,
    });
    return;
  }

  try {
    const data = await listOrders(chatId, status);
    const orders = Array.isArray(data && data.orders) ? data.orders : [];

    if (orders.length === 0) {
      await sendHtmlMessage(chatId, buildEmptyOrdersMessage(status), {
        reply_to_message_id: replyToMessageId,
      });
      return;
    }

    await sendHtmlMessage(chatId, buildOrdersHeaderMessage(status, orders.length), {
      reply_to_message_id: replyToMessageId,
    });

    for (const order of orders) {
      const replyMarkup = buildOrderReplyMarkup(order);
      const extra = replyMarkup ? { reply_markup: replyMarkup } : {};
      await sendHtmlMessage(chatId, buildOrderMessage(order), extra);
    }
  } catch (error) {
    if (isUnauthorizedError(error)) {
      await sendHtmlMessage(chatId, buildUnauthorizedMessage(), {
        reply_to_message_id: replyToMessageId,
      });
      return;
    }

    const fallback = isBridgeConfigError(error)
      ? buildBridgeConfigErrorMessage()
      : buildCommandErrorMessage('تعذر تحميل الطلبات', error instanceof Error ? error.message : 'حدث خطأ غير متوقع.');

    await sendHtmlMessage(chatId, fallback, {
      reply_to_message_id: replyToMessageId,
    });
  }
}

function parseCallbackData(data) {
  const parts = String(data || '').split(':');

  return {
    action: parts[0] || '',
    serial: parts[1] || '',
    sourceMessageId: parts[2] ? Number(parts[2]) : 0,
  };
}

function inferOrderStatusFromCallbackMessage(callbackQuery) {
  const text = normalizeText(callbackQuery && callbackQuery.message ? callbackQuery.message.text : '');

  if (text.includes('طلب معلق')) {
    return 'pending';
  }

  if (text.includes('طلب قيد التحضير')) {
    return 'prepared';
  }

  return '';
}

async function handlePrepareRequestCallback(callbackQuery, parsed) {
  const chatId = callbackQuery.message && callbackQuery.message.chat && callbackQuery.message.chat.id;
  const sourceMessageId = callbackQuery.message && callbackQuery.message.message_id;
  if (!chatId || !sourceMessageId || !parsed.serial) {
    return;
  }

  await answerCallbackQuery(callbackQuery.id);

  await sendHtmlMessage(chatId, buildPrepareConfirmMessage(parsed.serial), {
    reply_to_message_id: sourceMessageId,
    reply_markup: buildPrepareConfirmReplyMarkup(parsed.serial, sourceMessageId),
  });
}

async function handlePrepareConfirmCallback(callbackQuery, parsed) {
  const chatId = callbackQuery.message && callbackQuery.message.chat && callbackQuery.message.chat.id;
  const confirmMessageId = callbackQuery.message && callbackQuery.message.message_id;
  const sourceMessageId = parsed.sourceMessageId;
  if (!chatId || !confirmMessageId || !sourceMessageId || !parsed.serial) {
    return;
  }

  await answerCallbackQuery(callbackQuery.id);

  try {
    const data = await markOrderPreparing(chatId, parsed.serial);
    const order = data && data.order ? data.order : null;
    if (!order) {
      throw new Error('لم يتم العثور على بيانات الطلب بعد التحديث.');
    }

    await refreshOrderCardMessage(chatId, sourceMessageId, order);
    await safeEditHtmlMessage(chatId, confirmMessageId, buildPreparationDoneMessage(order), {
      reply_markup: EMPTY_INLINE_KEYBOARD,
    });
  } catch (error) {
    const html = isUnauthorizedError(error)
      ? buildUnauthorizedMessage()
      : buildCommandErrorMessage('فشل التحديث', error instanceof Error ? error.message : 'تعذر تحديث حالة الطلب.');

    await safeEditHtmlMessage(chatId, confirmMessageId, html, {
      reply_markup: EMPTY_INLINE_KEYBOARD,
    });
  }
}

async function handleCancelRequestCallback(callbackQuery, parsed) {
  const chatId = callbackQuery.message && callbackQuery.message.chat && callbackQuery.message.chat.id;
  const sourceMessageId = callbackQuery.message && callbackQuery.message.message_id;
  if (!chatId || !sourceMessageId || !parsed.serial) {
    return;
  }

  await answerCallbackQuery(callbackQuery.id);

  try {
    const prompt = await sendForceReplyMessage(chatId, buildCancelPromptMessage(parsed.serial), 'سبب الإلغاء', {
      reply_to_message_id: sourceMessageId,
    });

    await setConversationState(
      chatId,
      STATE_CANCEL_REASON,
      {
        serial: parsed.serial,
        source_message_id: sourceMessageId,
        prompt_message_id: prompt && prompt.message_id ? Number(prompt.message_id) : 0,
      },
      CANCEL_STATE_TTL_SECONDS
    );
  } catch (error) {
    await sendHtmlMessage(chatId, buildCommandErrorMessage('تعذر بدء عملية الإلغاء', error instanceof Error ? error.message : 'حدث خطأ غير متوقع.'), {
      reply_to_message_id: sourceMessageId,
    });
  }
}

async function handleDeliverRequestCallback(callbackQuery, parsed) {
  const chatId = callbackQuery.message && callbackQuery.message.chat && callbackQuery.message.chat.id;
  const sourceMessageId = callbackQuery.message && callbackQuery.message.message_id;
  if (!chatId || !sourceMessageId || !parsed.serial) {
    return;
  }

  await answerCallbackQuery(callbackQuery.id);

  await sendHtmlMessage(chatId, buildDeliverConfirmMessage(parsed.serial), {
    reply_to_message_id: sourceMessageId,
    reply_markup: buildDeliverConfirmReplyMarkup(parsed.serial, sourceMessageId),
  });
}

async function handleDeliverConfirmCallback(callbackQuery, parsed) {
  const chatId = callbackQuery.message && callbackQuery.message.chat && callbackQuery.message.chat.id;
  const confirmMessageId = callbackQuery.message && callbackQuery.message.message_id;
  const sourceMessageId = parsed.sourceMessageId;
  if (!chatId || !confirmMessageId || !sourceMessageId || !parsed.serial) {
    return;
  }

  await answerCallbackQuery(callbackQuery.id);

  try {
    const prompt = await sendForceReplyMessage(chatId, buildDeliveryPromptMessage(parsed.serial), 'اسم المندوب', {
      reply_to_message_id: sourceMessageId,
    });

    await setConversationState(
      chatId,
      STATE_DELIVERY_NAME,
      {
        serial: parsed.serial,
        source_message_id: sourceMessageId,
        prompt_message_id: prompt && prompt.message_id ? Number(prompt.message_id) : 0,
      },
      DELIVERY_STATE_TTL_SECONDS
    );

    await safeEditHtmlMessage(chatId, confirmMessageId, buildStateWaitingMessage(STATE_DELIVERY_NAME, {
      serial: parsed.serial,
    }), {
      reply_markup: EMPTY_INLINE_KEYBOARD,
    });
  } catch (error) {
    await safeEditHtmlMessage(chatId, confirmMessageId, buildCommandErrorMessage('تعذر بدء عملية التسليم', error instanceof Error ? error.message : 'حدث خطأ غير متوقع.'), {
      reply_markup: EMPTY_INLINE_KEYBOARD,
    });
  }
}

async function handleDismissCallback(callbackQuery) {
  const chatId = callbackQuery.message && callbackQuery.message.chat && callbackQuery.message.chat.id;
  const messageId = callbackQuery.message && callbackQuery.message.message_id;
  if (!chatId || !messageId) {
    return;
  }

  await answerCallbackQuery(callbackQuery.id);
  await safeEditHtmlMessage(chatId, messageId, buildDismissedMessage(), {
    reply_markup: EMPTY_INLINE_KEYBOARD,
  });
}

async function handleCallbackQuery(callbackQuery) {
  const parsed = parseCallbackData(callbackQuery && callbackQuery.data);
  const chatId = callbackQuery && callbackQuery.message && callbackQuery.message.chat
    ? callbackQuery.message.chat.id
    : null;
  let permissions = createPermissionsObject(chatId || '', {});

  try {
    if (chatId) {
      try {
        permissions = await loadMemberPermissions(chatId);
        await syncChatCommands(chatId, permissions);
      } catch (permissionsError) {
        console.error('telegram.callback permissions sync error', permissionsError);
      }
    }

    if ((parsed.action === 'p1' || parsed.action === 'p2') && !isCommandAllowed(permissions, 'pending')) {
      await answerCallbackQuery(callbackQuery.id, 'هذا العضو غير مصرح له باستخدام /pending.', {
        show_alert: true,
      });
      return;
    }

    if ((parsed.action === 'd1' || parsed.action === 'd2') && !isCommandAllowed(permissions, 'prepared')) {
      await answerCallbackQuery(callbackQuery.id, 'هذا العضو غير مصرح له باستخدام /prepared.', {
        show_alert: true,
      });
      return;
    }

    if (parsed.action === 'c1') {
      const sourceStatus = inferOrderStatusFromCallbackMessage(callbackQuery);
      const requiredCommand = sourceStatus === 'pending' ? 'pending' : (sourceStatus === 'prepared' ? 'prepared' : '');
      if (requiredCommand && !isCommandAllowed(permissions, requiredCommand)) {
        await answerCallbackQuery(callbackQuery.id, `هذا العضو غير مصرح له باستخدام /${requiredCommand}.`, {
          show_alert: true,
        });
        return;
      }
    }

    if (parsed.action === 'p1') {
      await handlePrepareRequestCallback(callbackQuery, parsed);
      return;
    }

    if (parsed.action === 'p2') {
      await handlePrepareConfirmCallback(callbackQuery, parsed);
      return;
    }

    if (parsed.action === 'px') {
      await handleDismissCallback(callbackQuery);
      return;
    }

    if (parsed.action === 'c1') {
      await handleCancelRequestCallback(callbackQuery, parsed);
      return;
    }

    if (parsed.action === 'd1') {
      await handleDeliverRequestCallback(callbackQuery, parsed);
      return;
    }

    if (parsed.action === 'd2') {
      await handleDeliverConfirmCallback(callbackQuery, parsed);
      return;
    }

    if (parsed.action === 'dx') {
      await handleDismissCallback(callbackQuery);
      return;
    }

    await answerCallbackQuery(callbackQuery.id);
  } catch (error) {
    console.error('telegram.callback error', error);

    try {
      await answerCallbackQuery(callbackQuery.id, 'حدث خطأ أثناء تنفيذ الإجراء.', {
        show_alert: true,
      });
    } catch (callbackError) {
      console.error('telegram.callback answer error', callbackError);
    }
  }
}

async function handleCancelReasonState(chatId, message, text, command, state) {
  const context = state && typeof state.context === 'object' ? state.context : {};
  const messageId = message && message.message_id;

  if (command) {
    await clearConversationState(chatId);
    await sendHtmlMessage(chatId, buildCancelAbortMessage('command', context), {
      reply_to_message_id: messageId,
    });
    return 'continue';
  }

  if (!isValidCancellationReason(text)) {
    await clearConversationState(chatId);
    await sendHtmlMessage(chatId, buildCancelAbortMessage('invalid_text', context), {
      reply_to_message_id: messageId,
    });
    return 'handled';
  }

  await clearConversationState(chatId);

  try {
    const data = await cancelOrder(chatId, context.serial, normalizeText(text));
    const order = data && data.order ? data.order : null;
    if (!order) {
      throw new Error('لم يتم العثور على بيانات الطلب بعد الإلغاء.');
    }

    if (context.source_message_id) {
      await refreshOrderCardMessage(chatId, Number(context.source_message_id), order);
    }

    await sendHtmlMessage(chatId, buildCancelDoneMessage(order), {
      reply_to_message_id: messageId,
    });
  } catch (error) {
    const html = isUnauthorizedError(error)
      ? buildUnauthorizedMessage()
      : buildCommandErrorMessage('تعذر إلغاء الطلب', error instanceof Error ? error.message : 'حدث خطأ غير متوقع.');

    await sendHtmlMessage(chatId, html, {
      reply_to_message_id: messageId,
    });
  }

  return 'handled';
}

async function handleDeliveryNameState(chatId, message, text, command, state) {
  const context = state && typeof state.context === 'object' ? state.context : {};
  const messageId = message && message.message_id;

  if (command) {
    await clearConversationState(chatId);
    await sendHtmlMessage(chatId, buildDeliveryAbortMessage('command', context), {
      reply_to_message_id: messageId,
    });
    return 'continue';
  }

  if (!isReplyToPrompt(message, context.prompt_message_id)) {
    await clearConversationState(chatId);
    await sendHtmlMessage(chatId, buildDeliveryAbortMessage('invalid_reply', context), {
      reply_to_message_id: messageId,
    });
    return 'handled';
  }

  if (!isValidDeliveryName(text)) {
    await clearConversationState(chatId);
    await sendHtmlMessage(chatId, buildDeliveryAbortMessage('invalid_name', context), {
      reply_to_message_id: messageId,
    });
    return 'handled';
  }

  await clearConversationState(chatId);

  try {
    const data = await markOrderDelivered(chatId, context.serial, normalizeText(text));
    const order = data && data.order ? data.order : null;
    if (!order) {
      throw new Error('لم يتم العثور على بيانات الطلب بعد التسليم.');
    }

    if (context.source_message_id) {
      await refreshOrderCardMessage(chatId, Number(context.source_message_id), order);
    }

    await sendHtmlMessage(chatId, buildDeliveredDoneMessage(order), {
      reply_to_message_id: messageId,
    });
  } catch (error) {
    const html = isUnauthorizedError(error)
      ? buildUnauthorizedMessage()
      : buildCommandErrorMessage('تعذر تأكيد التسليم', error instanceof Error ? error.message : 'حدث خطأ غير متوقع.');

    await sendHtmlMessage(chatId, html, {
      reply_to_message_id: messageId,
    });
  }

  return 'handled';
}

async function maybeHandleConversationState(chatId, message, text, command) {
  let snapshot = null;

  try {
    snapshot = await getConversationStateSnapshot(chatId);
  } catch (error) {
    console.error('telegram.state fetch error', error);
    return 'continue';
  }

  if (!snapshot) {
    return 'continue';
  }

  const messageId = message && message.message_id;

  if (snapshot.expired) {
    await sendHtmlMessage(chatId, buildStateExpiredMessage(snapshot.expired_state_key, snapshot.expired_context || {}), {
      reply_to_message_id: messageId,
    });

    return command ? 'continue' : 'handled';
  }

  const state = snapshot.state;
  if (!state || !state.state_key) {
    return 'continue';
  }

  if (state.state_key === STATE_CANCEL_REASON) {
    return handleCancelReasonState(chatId, message, text, command, state);
  }

  if (state.state_key === STATE_DELIVERY_NAME) {
    return handleDeliveryNameState(chatId, message, text, command, state);
  }

  return 'continue';
}

async function handleTelegramMessage(message) {
  if (!message || !message.chat || !message.chat.id) {
    return;
  }

  const chatId = message.chat.id;
  const messageId = message.message_id;
  const rawText = typeof message.text === 'string' ? message.text : '';
  const text = normalizeText(rawText);
  const command = normalizeCommand(text);

  const stateOutcome = await maybeHandleConversationState(chatId, message, text, command);
  if (stateOutcome === 'handled') {
    return;
  }

  if (command === 'chatid') {
    await sendHtmlMessage(chatId, buildChatIdMessage(chatId), {
      reply_to_message_id: messageId,
    });
    return;
  }

  let permissions = createPermissionsObject(chatId, {});
  let permissionsError = null;

  try {
    permissions = await loadMemberPermissions(chatId);
    await syncChatCommands(chatId, permissions);
  } catch (error) {
    permissionsError = error;
    console.error('telegram.permissions error', error);
  }

  if (command === 'pending') {
    if (permissionsError) {
      const html = isBridgeConfigError(permissionsError)
        ? buildBridgeConfigErrorMessage()
        : buildCommandErrorMessage('تعذر تحميل الصلاحيات', permissionsError instanceof Error ? permissionsError.message : 'حدث خطأ غير متوقع.');

      await sendHtmlMessage(chatId, html, {
        reply_to_message_id: messageId,
      });
      return;
    }

    await handleOrdersCommand(chatId, messageId, 'pending', permissions);
    return;
  }

  if (command === 'prepared') {
    if (permissionsError) {
      const html = isBridgeConfigError(permissionsError)
        ? buildBridgeConfigErrorMessage()
        : buildCommandErrorMessage('تعذر تحميل الصلاحيات', permissionsError instanceof Error ? permissionsError.message : 'حدث خطأ غير متوقع.');

      await sendHtmlMessage(chatId, html, {
        reply_to_message_id: messageId,
      });
      return;
    }

    await handleOrdersCommand(chatId, messageId, 'preparing', permissions);
    return;
  }

  if (command === 'check') {
    if (permissionsError) {
      const html = isBridgeConfigError(permissionsError)
        ? buildBridgeConfigErrorMessage()
        : buildCommandErrorMessage('تعذر تحميل الصلاحيات', permissionsError instanceof Error ? permissionsError.message : 'حدث خطأ غير متوقع.');

      await sendHtmlMessage(chatId, html, {
        reply_to_message_id: messageId,
      });
      return;
    }

    await handleCheckCommand(chatId, messageId, permissions);
    return;
  }

  if (command === 'start') {
    await sendHtmlMessage(chatId, buildStartMessage(chatId, permissions), {
      reply_to_message_id: messageId,
    });
    return;
  }

  if (command === 'help') {
    if (permissionsError) {
      const html = isBridgeConfigError(permissionsError)
        ? buildBridgeConfigErrorMessage()
        : buildCommandErrorMessage('تعذر تحميل الصلاحيات', permissionsError instanceof Error ? permissionsError.message : 'حدث خطأ غير متوقع.');

      await sendHtmlMessage(chatId, html, {
        reply_to_message_id: messageId,
      });
      return;
    }

    if (!isCommandAllowed(permissions, 'help')) {
      await sendHtmlMessage(chatId, buildUnauthorizedMessage(), {
        reply_to_message_id: messageId,
      });
      return;
    }

    await sendHtmlMessage(chatId, buildHelpMessage(chatId, permissions), {
      reply_to_message_id: messageId,
    });
    return;
  }

  if (!text) {
    return;
  }

  await sendHtmlMessage(chatId, buildStartMessage(chatId, permissions), {
    reply_to_message_id: messageId,
  });
}

async function handleTelegramUpdate(update) {
  if (update && update.callback_query) {
    await handleCallbackQuery(update.callback_query);
    return;
  }

  const message = update && (update.message || update.edited_message);
  if (!message) {
    return;
  }

  await handleTelegramMessage(message);
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
        app_base_url: getAppBaseUrl(),
        bridge_secret: Boolean(getBridgeSecret()),
        missing: [
          ...(!getTelegramToken() ? ['TELEGRAM_BOT_TOKEN|TELEGRAM_TOKEN'] : []),
          ...(!getAppBaseUrl() ? ['LARA_APP_URL|APP_URL|SAKAN_APP_URL'] : []),
        ],
      },
      public_commands: DEFAULT_PUBLIC_COMMANDS.map((item) => `/${item.command}`),
      token_source: getTelegramTokenSource(),
      app_url_source: getAppBaseUrlSource(),
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
    } catch (error) {
      console.error('telegram.update commands sync error', error);
    }

    try {
      await syncWebhook(req);
    } catch (error) {
      console.error('telegram.update webhook sync error', error);
    }

    try {
      await handleTelegramUpdate(body);
    } catch (error) {
      console.error('telegram.update handle error', error);
    }

    return json(res, 200, { ok: true });
  }

  return json(res, 400, {
    ok: false,
    error: 'Unsupported payload',
  });
};
