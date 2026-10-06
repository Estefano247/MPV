<?php

declare(strict_types=1);

// Es un include, no una página: solo existe para que los endpoints de esta
// carpeta compartan la carga. Pedirlo por URL no tiene que devolver nada.
if (PHP_SAPI !== 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/Api.php';
require_once __DIR__ . '/../../includes/dashboard.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/SubmissionRepositoryInterface.php';
require_once __DIR__ . '/../../includes/SubmissionRepository.php';
require_once __DIR__ . '/../../includes/AreaRepository.php';
require_once __DIR__ . '/../../includes/AcuseService.php';
require_once __DIR__ . '/../../includes/ObservacionRepository.php';
require_once __DIR__ . '/../../includes/SubsanacionService.php';
require_once __DIR__ . '/../../includes/S3Service.php';
require_once __DIR__ . '/../../includes/Setup.php';

Setup::ensureDatabase();