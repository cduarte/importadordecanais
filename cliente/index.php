<?php
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
