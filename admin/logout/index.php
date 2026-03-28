<?php

declare(strict_types=1);

require_once __DIR__ . '/../_auth.php';

admin_logout();
admin_redirect('/admin/login/');

