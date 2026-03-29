<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/_auth.php';

admin_require_auth();

$repository = new TelegramNotificationRecipientRepository();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        admin_require_csrf();

        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'create_recipient') {
            $repository->createRecipient(
                (string) ($_POST['chat_id'] ?? ''),
                (string) ($_POST['label'] ?? '')
            );
            $_SESSION['admin_telegram_flash'] = [
                'type' => 'ok',
                'message' => 'Telegram recipient added.',
            ];
            admin_redirect('/admin/settings/telegram/');
        }

        if ($action === 'update_recipient') {
            $repository->updateRecipient(
                (int) ($_POST['id'] ?? 0),
                (string) ($_POST['chat_id'] ?? ''),
                (string) ($_POST['label'] ?? ''),
                isset($_POST['is_active']) && $_POST['is_active'] === '1'
            );
            $_SESSION['admin_telegram_flash'] = [
                'type' => 'ok',
                'message' => 'Telegram recipient updated.',
            ];
            admin_redirect('/admin/settings/telegram/');
        }

        if ($action === 'delete_recipient') {
            $repository->deleteRecipient((int) ($_POST['id'] ?? 0));
            $_SESSION['admin_telegram_flash'] = [
                'type' => 'ok',
                'message' => 'Telegram recipient removed.',
            ];
            admin_redirect('/admin/settings/telegram/');
        }

        throw new RuntimeException('Unknown Telegram recipient action.');
    } catch (Throwable $exception) {
        $_SESSION['admin_telegram_flash'] = [
            'type' => 'error',
            'message' => $exception->getMessage(),
        ];
        admin_redirect('/admin/settings/telegram/');
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
  <div class="orders-shell">
    <header class="orders-topbar">
      <div>
        <div class="auth-badge">LARA</div>
        <h1>Telegram Notifications</h1>
        <p>Add the chat IDs that should receive bot notifications. Use <code>/chatid</code> in Telegram, then paste the returned value here.</p>
      </div>

      <div class="dash-actions">
        <span class="dash-user"><?= e($username) ?></span>
        <a class="btn btn-ghost" href="/admin/dashboard/">Dashboard</a>
        <a class="btn btn-ghost" href="/admin/statistics/orders/">Orders</a>
        <a class="btn btn-ghost" href="/admin/logout/">Logout</a>
      </div>
    </header>

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
      <form class="telegram-add-form" method="post" action="/admin/settings/telegram/">
        <input type="hidden" name="csrf_token" value="<?= e(admin_csrf_token()) ?>">
        <input type="hidden" name="action" value="create_recipient">

        <label class="field">
          <span class="field-label">Label</span>
          <input class="field-input" type="text" name="label" placeholder="Owner, Kitchen, Main Group">
        </label>

        <label class="field">
          <span class="field-label">Chat ID</span>
          <input class="field-input" type="text" name="chat_id" placeholder="201508803316 or -1001234567890" required>
        </label>

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

            <form class="telegram-recipient-form" method="post" action="/admin/settings/telegram/">
              <input type="hidden" name="csrf_token" value="<?= e(admin_csrf_token()) ?>">
              <input type="hidden" name="action" value="update_recipient">
              <input type="hidden" name="id" value="<?= e((string) ($recipient['id'] ?? 0)) ?>">

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

              <div class="telegram-recipient-actions">
                <label class="check">
                  <input type="checkbox" name="is_active" value="1" <?= (($recipient['is_active'] ?? false) === true) ? 'checked' : '' ?>>
                  <span>Send notifications to this chat</span>
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

            <form method="post" action="/admin/settings/telegram/">
              <input type="hidden" name="csrf_token" value="<?= e(admin_csrf_token()) ?>">
              <input type="hidden" name="action" value="delete_recipient">
              <input type="hidden" name="id" value="<?= e((string) ($recipient['id'] ?? 0)) ?>">
              <button class="btn btn-danger" type="submit">Delete</button>
            </form>
          </article>
        <?php endforeach; ?>
      </section>
    <?php endif; ?>
  </div>
</body>
</html>
