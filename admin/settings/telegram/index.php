<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/_auth.php';

admin_require_auth();

$repository = new TelegramNotificationRecipientRepository();
$embedded = ((string) ($_GET['embedded'] ?? $_POST['embedded'] ?? '') === '1');
$telegramIndexUrl = static function () use ($embedded): string {
    return '/admin/settings/telegram/' . ($embedded ? '?embedded=1' : '');
};

function telegram_recipient_permissions_from_input(array $source): array
{
    return [
        'can_receive_notifications' => isset($source['can_receive_notifications']) && (string) $source['can_receive_notifications'] === '1',
        'can_use_pending' => isset($source['can_use_pending']) && (string) $source['can_use_pending'] === '1',
        'can_use_prepared' => isset($source['can_use_prepared']) && (string) $source['can_use_prepared'] === '1',
        'can_use_check' => isset($source['can_use_check']) && (string) $source['can_use_check'] === '1',
        'can_use_help' => isset($source['can_use_help']) && (string) $source['can_use_help'] === '1',
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        admin_require_csrf();

        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'create_recipient') {
            $repository->createRecipient(
                (string) ($_POST['chat_id'] ?? ''),
                (string) ($_POST['label'] ?? ''),
                telegram_recipient_permissions_from_input($_POST)
            );
            $_SESSION['admin_telegram_flash'] = [
                'type' => 'ok',
                'message' => 'Telegram recipient added.',
            ];
            admin_redirect($telegramIndexUrl());
        }

        if ($action === 'update_recipient') {
            $repository->updateRecipient(
                (int) ($_POST['id'] ?? 0),
                (string) ($_POST['chat_id'] ?? ''),
                (string) ($_POST['label'] ?? ''),
                isset($_POST['is_active']) && $_POST['is_active'] === '1',
                telegram_recipient_permissions_from_input($_POST)
            );
            $_SESSION['admin_telegram_flash'] = [
                'type' => 'ok',
                'message' => 'Telegram recipient updated.',
            ];
            admin_redirect($telegramIndexUrl());
        }

        if ($action === 'delete_recipient') {
            $repository->deleteRecipient((int) ($_POST['id'] ?? 0));
            $_SESSION['admin_telegram_flash'] = [
                'type' => 'ok',
                'message' => 'Telegram recipient removed.',
            ];
            admin_redirect($telegramIndexUrl());
        }

        throw new RuntimeException('Unknown Telegram recipient action.');
    } catch (Throwable $exception) {
        $_SESSION['admin_telegram_flash'] = [
            'type' => 'error',
            'message' => $exception->getMessage(),
        ];
        admin_redirect($telegramIndexUrl());
    }
}

$flash = $_SESSION['admin_telegram_flash'] ?? null;
unset($_SESSION['admin_telegram_flash']);

$username = (string) ($_SESSION['admin_username'] ?? 'admin');
$recipients = $repository->listRecipients();
$activeCount = 0;

foreach ($recipients as $recipient) {
    if (($recipient['is_active'] ?? false) === true) {
        $activeCount += 1;
    }
}

$inactiveCount = count($recipients) - $activeCount;

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Telegram Notifications • LARA</title>
  <link rel="stylesheet" href="/admin/assets/admin.css">
</head>
<body class="admin-body">
  <div class="orders-shell<?= $embedded ? ' orders-shell-embedded' : '' ?>">
    <?php if ($embedded): ?>
      <header class="embedded-page-head">
        <div class="auth-badge">LARA</div>
        <h1>Telegram Notifications</h1>
        <p>Add the chat IDs that should receive bot notifications. Use <code>/chatid</code> in Telegram, then paste the returned value here.</p>
      </header>
    <?php else: ?>
      <header class="orders-topbar">
        <div>
          <div class="auth-badge">LARA</div>
          <h1>Telegram Notifications</h1>
          <p>Add the chat IDs that should receive bot notifications. Use <code>/chatid</code> in Telegram, then paste the returned value here.</p>
        </div>

        <div class="dash-actions dash-actions-shell">
          <nav class="dash-tablist" aria-label="Admin sections">
            <a class="dash-tab" href="/admin/dashboard/?view=menu" title="Menu" aria-label="Menu">
              <svg class="dash-tab-icon" viewBox="0 0 24 24" aria-hidden="true">
                <rect x="4" y="4" width="6" height="6" rx="1.5"></rect>
                <rect x="14" y="4" width="6" height="6" rx="1.5"></rect>
                <rect x="4" y="14" width="6" height="6" rx="1.5"></rect>
                <rect x="14" y="14" width="6" height="6" rx="1.5"></rect>
              </svg>
              <span class="dash-tab-label">Menu</span>
            </a>
            <a class="dash-tab" href="/admin/dashboard/?view=orders" title="Orders" aria-label="Orders">
              <svg class="dash-tab-icon" viewBox="0 0 24 24" aria-hidden="true">
                <path d="M7 3.75h8l4 4V20.25H5V5.75A2 2 0 0 1 7 3.75Z"></path>
                <path d="M15 3.75v4h4"></path>
                <path d="M9 11h6"></path>
                <path d="M9 15h6"></path>
              </svg>
              <span class="dash-tab-label">Orders</span>
            </a>
            <a class="dash-tab active" href="/admin/dashboard/?view=telegram" title="Telegram" aria-label="Telegram">
              <svg class="dash-tab-icon" viewBox="0 0 24 24" aria-hidden="true">
                <path d="M20.5 5.5 3.75 11.8l5.9 1.95 1.95 5.9L20.5 5.5Z"></path>
                <path d="m9.65 13.75 3.8-3.8"></path>
              </svg>
              <span class="dash-tab-label">Telegram</span>
            </a>
          </nav>

          <span class="dash-user-badge" title="Logged in as <?= e($username) ?>"><?= e(strtoupper(substr($username, 0, 1))) ?></span>

          <a class="dash-tab dash-tab-logout" href="/admin/logout/" title="Logout" aria-label="Logout">
            <svg class="dash-tab-icon" viewBox="0 0 24 24" aria-hidden="true">
              <path d="M10 4.75H6.75a2 2 0 0 0-2 2v10.5a2 2 0 0 0 2 2H10"></path>
              <path d="M13 8.25 17 12l-4 3.75"></path>
              <path d="M8.5 12H17"></path>
            </svg>
            <span class="dash-tab-label">Logout</span>
          </a>
        </div>
      </header>
    <?php endif; ?>

    <?php if (is_array($flash) && (($flash['message'] ?? '') !== '')): ?>
      <div class="alert <?= (($flash['type'] ?? 'error') === 'ok') ? '' : 'alert-error' ?>"><?= e((string) $flash['message']) ?></div>
    <?php endif; ?>

    <section class="summary-grid">
      <article class="summary-card">
        <span>Total Recipients</span>
        <strong><?= e((string) count($recipients)) ?></strong>
      </article>
      <article class="summary-card">
        <span>Active</span>
        <strong><?= e((string) $activeCount) ?></strong>
      </article>
      <article class="summary-card">
        <span>Inactive</span>
        <strong><?= e((string) $inactiveCount) ?></strong>
      </article>
      <article class="summary-card">
        <span>Telegram Tip</span>
        <strong>/chatid</strong>
      </article>
    </section>

    <section class="filters-card">
      <form class="telegram-add-form" method="post" action="<?= e($telegramIndexUrl()) ?>">
        <input type="hidden" name="csrf_token" value="<?= e(admin_csrf_token()) ?>">
        <input type="hidden" name="action" value="create_recipient">
        <?php if ($embedded): ?>
          <input type="hidden" name="embedded" value="1">
        <?php endif; ?>

        <label class="field">
          <span class="field-label">Label</span>
          <input class="field-input" type="text" name="label" placeholder="Owner, Kitchen, Main Group">
        </label>

        <label class="field">
          <span class="field-label">Chat ID</span>
          <input class="field-input" type="text" name="chat_id" placeholder="201508803316 or -1001234567890" required>
        </label>

        <div class="telegram-permissions-grid">
          <label class="check">
            <input type="checkbox" name="can_receive_notifications" value="1" checked>
            <span>New order notifications</span>
          </label>
          <label class="check">
            <input type="checkbox" name="can_use_pending" value="1" checked>
            <span>/pending</span>
          </label>
          <label class="check">
            <input type="checkbox" name="can_use_prepared" value="1" checked>
            <span>/prepared</span>
          </label>
          <label class="check">
            <input type="checkbox" name="can_use_check" value="1" checked>
            <span>/check</span>
          </label>
          <label class="check">
            <input type="checkbox" name="can_use_help" value="1" checked>
            <span>/help</span>
          </label>
        </div>

        <div class="filters-actions">
          <button class="btn btn-primary" type="submit">Add chat ID</button>
        </div>
      </form>

      <div class="telegram-help-note">
        <strong>How to get the chat ID</strong>
        <p>Start a chat with your bot, send <code>/chatid</code>, then copy the number exactly as returned. Group chat IDs usually start with <code>-100</code>.</p>
      </div>
    </section>

    <?php if ($recipients === []): ?>
      <section class="panel panel-empty">
        <div class="panel-body">
          <p>No Telegram recipients added yet.</p>
        </div>
      </section>
    <?php else: ?>
      <section class="telegram-recipient-list">
        <?php foreach ($recipients as $recipient): ?>
          <article class="order-card-admin telegram-recipient-card">
            <div class="order-card-head-row">
              <div>
                <div class="order-serial"><?= e(($recipient['label'] ?? '') !== '' ? (string) $recipient['label'] : 'Unlabeled recipient') ?></div>
                <div class="order-meta-line"><?= e((string) ($recipient['chat_id'] ?? '')) ?></div>
              </div>
              <span class="status-badge <?= (($recipient['is_active'] ?? false) === true) ? 'status-badge-received' : 'status-badge-cancelled' ?>">
                <?= (($recipient['is_active'] ?? false) === true) ? 'Active' : 'Inactive' ?>
              </span>
            </div>

            <form class="telegram-recipient-form" method="post" action="<?= e($telegramIndexUrl()) ?>">
              <input type="hidden" name="csrf_token" value="<?= e(admin_csrf_token()) ?>">
              <input type="hidden" name="action" value="update_recipient">
              <input type="hidden" name="id" value="<?= e((string) ($recipient['id'] ?? 0)) ?>">
              <?php if ($embedded): ?>
                <input type="hidden" name="embedded" value="1">
              <?php endif; ?>

              <div class="telegram-recipient-grid">
                <label class="field">
                  <span class="field-label">Label</span>
                  <input class="field-input" type="text" name="label" value="<?= e((string) ($recipient['label'] ?? '')) ?>" placeholder="Owner, Kitchen, Main Group">
                </label>

                <label class="field">
                  <span class="field-label">Chat ID</span>
                  <input class="field-input" type="text" name="chat_id" value="<?= e((string) ($recipient['chat_id'] ?? '')) ?>" required>
                </label>
              </div>

              <div class="telegram-permissions-grid">
                <label class="check">
                  <input type="checkbox" name="can_receive_notifications" value="1" <?= (($recipient['can_receive_notifications'] ?? false) === true) ? 'checked' : '' ?>>
                  <span>New order notifications</span>
                </label>
                <label class="check">
                  <input type="checkbox" name="can_use_pending" value="1" <?= (($recipient['can_use_pending'] ?? false) === true) ? 'checked' : '' ?>>
                  <span>/pending</span>
                </label>
                <label class="check">
                  <input type="checkbox" name="can_use_prepared" value="1" <?= (($recipient['can_use_prepared'] ?? false) === true) ? 'checked' : '' ?>>
                  <span>/prepared</span>
                </label>
                <label class="check">
                  <input type="checkbox" name="can_use_check" value="1" <?= (($recipient['can_use_check'] ?? false) === true) ? 'checked' : '' ?>>
                  <span>/check</span>
                </label>
                <label class="check">
                  <input type="checkbox" name="can_use_help" value="1" <?= (($recipient['can_use_help'] ?? false) === true) ? 'checked' : '' ?>>
                  <span>/help</span>
                </label>
              </div>

              <div class="telegram-recipient-actions">
                <label class="check">
                  <input type="checkbox" name="is_active" value="1" <?= (($recipient['is_active'] ?? false) === true) ? 'checked' : '' ?>>
                  <span>Enable this member</span>
                </label>

                <div class="telegram-recipient-buttons">
                  <button class="btn btn-primary" type="submit">Save</button>
                </div>
              </div>
            </form>

            <div class="telegram-recipient-meta">
              <span>Created: <?= e((string) ($recipient['created_at'] ?? '')) ?></span>
              <span>Updated: <?= e((string) ($recipient['updated_at'] ?? '')) ?></span>
            </div>

            <form method="post" action="<?= e($telegramIndexUrl()) ?>">
              <input type="hidden" name="csrf_token" value="<?= e(admin_csrf_token()) ?>">
              <input type="hidden" name="action" value="delete_recipient">
              <input type="hidden" name="id" value="<?= e((string) ($recipient['id'] ?? 0)) ?>">
              <?php if ($embedded): ?>
                <input type="hidden" name="embedded" value="1">
              <?php endif; ?>
              <button class="btn btn-danger" type="submit">Delete</button>
            </form>
          </article>
        <?php endforeach; ?>
      </section>
    <?php endif; ?>
  </div>
</body>
</html>
