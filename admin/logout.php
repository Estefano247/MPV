<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';

dashboard_log_audit('logout');
dashboard_clear_session_cookie();
header('Location: login.php');
exit;