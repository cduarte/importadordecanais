<?php

$action = $_GET['action'] ?? '';
if ($action !== '') {
    $actionRoutes = [
        'canais-bridge' => 'form_import_canais.php',
        'canais-status-bridge' => 'form_import_canais.php',
        'filmes-bridge' => 'form_import_filmes.php',
        'filmes-status-bridge' => 'form_import_filmes.php',
        'series-bridge' => 'form_import_series.php',
        'series-status-bridge' => 'form_import_series.php',
    ];

    if (isset($actionRoutes[$action])) {
        require __DIR__ . '/' . $actionRoutes[$action];
        return;
    }
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$normalized = rtrim($path, '/') ?: '/';

switch ($normalized) {
    case '/':
        require __DIR__ . '/form_import_canais.php';
        break;
    case '/filmes':
        require __DIR__ . '/form_import_filmes.php';
        break;
    case '/series':
        require __DIR__ . '/form_import_series.php';
        break;
    default:
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Pagina nao encontrada';
        break;
}
