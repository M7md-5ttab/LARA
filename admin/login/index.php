<?php

declare(strict_types=1);

require_once __DIR__ . '/../_auth.php';

if (admin_is_authenticated()) {
    admin_redirect('/admin/dashboard/');
}

$error = '';
$hasConfig = (Env::get('ADMIN_USERNAME') ?? '') !== '' && ((Env::get('ADMIN_PASSWORD_HASH') ?? '') !== '' || (Env::get('ADMIN_PASSWORD') ?? '') !== '');
$next = $_GET['next'] ?? '/admin/dashboard/';
$next = is_string($next) && str_starts_with($next, '/admin/') ? $next : '/admin/dashboard/';

if (!$hasConfig && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $error = 'Admin credentials are not configured. Create a `.env` file based on `.env.example`.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_can_attempt_login()) {
        $error = 'Too many attempts. Please wait a moment and try again.';
    } else {
        $providedCsrf = (string) ($_POST['csrf_token'] ?? '');
        if ($providedCsrf === '' || !hash_equals(admin_csrf_token(), $providedCsrf)) {
            $error = 'Invalid request. Please refresh and try again.';
        } else {
            $username = trim((string) ($_POST['username'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');

            if (!$hasConfig) {
                $error = 'Admin credentials are not configured. Create a `.env` file based on `.env.example`.';
            } else {
                $validUser = hash_equals(admin_env_username(), $username);
                $validPass = admin_verify_password($password);

                if ($validUser && $validPass) {
                    session_regenerate_id(true);
                    $_SESSION['admin_authenticated'] = true;
                    $_SESSION['admin_username'] = $username;
                    $_SESSION['admin_ua_hash'] = hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
                    $_SESSION['admin_logged_in_at'] = time();
                    admin_reset_login_throttle();
                    admin_redirect($next);
                }

                admin_register_failed_login();
                usleep(250000);
                $error = 'Invalid username or password.';
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Admin Login • LARA</title>
  <link rel="stylesheet" href="<?= e(HttpCache::versionedAssetUrl('admin/assets/admin.css')) ?>" />
</head>
<body class="admin-body">
  <main class="auth-shell">
    <section class="auth-card" aria-label="Admin login">
      <header class="auth-header">
        <div class="auth-badge">LARA</div>
        <h1>Admin Dashboard</h1>
        <p>Sign in to manage menu categories and items.</p>
      </header>

      <?php if ($error !== ''): ?>
        <div class="alert alert-error" role="alert"><?= e($error) ?></div>
      <?php endif; ?>

      <form method="post" class="auth-form" autocomplete="off" novalidate>
        <input type="hidden" name="csrf_token" value="<?= e(admin_csrf_token()) ?>" />
        <input type="hidden" name="next" value="<?= e($next) ?>" />

        <label class="field">
          <span class="field-label">Username</span>
          <input class="field-input" name="username" type="text" inputmode="text" required <?= $hasConfig ? '' : 'disabled' ?> />
        </label>

        <label class="field">
          <span class="field-label">Password</span>
          <input class="field-input" name="password" type="password" required <?= $hasConfig ? '' : 'disabled' ?> />
        </label>

        <button class="btn btn-primary" type="submit" <?= $hasConfig ? '' : 'disabled' ?>>Login</button>
      </form>

      <footer class="auth-footer">
        <small>Protected area • <?= e(date('Y')) ?></small>
      </footer>
    </section>
  </main>
</body>
</html>
