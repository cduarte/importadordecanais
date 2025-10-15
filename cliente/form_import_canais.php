<?php

$importAction = 'canais-bridge';
$statusAction = 'canais-status-bridge';
$requestedAction = $_GET['action'] ?? '';

if ($requestedAction === $importAction) {
    $_GET['endpoint'] = 'canais';
    unset($_GET['action']);
    require __DIR__ . '/api_proxy.php';
    return;
}

if ($requestedAction === $statusAction) {
    $_GET['endpoint'] = 'canais_status';
    unset($_GET['action']);
    require __DIR__ . '/api_proxy.php';
    return;
}

require __DIR__ . '/includes/import_form_page.php';

renderImportFormPage([
    'current_nav_key' => 'canais',
    'action_route' => $importAction,
    'status_route' => $statusAction,
]);
