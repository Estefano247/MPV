<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$user = dashboard_guard_api();

ApiResponse::json([
    'username' => $user['username'],
    'role' => $user['role'],
]);
