<?php

declare(strict_types=1);

/**
 * Importador API Proxy
 *
 * NOTA IMPORTANTE:
 * - A pasta /server NÃO existe localmente neste servidor.
 * - Toda chamada deve ser redirecionada para a API remota,
 *   definida em IMPORTADOR_API_BASE_URL no arquivo .env.
 */

//
// --- Loader manual do .env ---
//
$envFile = __DIR__ . '/.env';
if (is_file($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (strpos($line, '=') !== false) {
            [$name, $value] = array_map('trim', explode('=', $line, 2));
            if ($name !== '') {
                putenv("$name=$value");
                $_ENV[$name] = $value;
            }
        }
    }
}

//
// --- Função de resposta JSON ---
//
function respondJson(array $payload, int $statusCode = 200): void
{
    if (!headers_sent()) {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, max-age=0');
        header('Access-Control-Allow-Origin: *');
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

//
// --- Verifica endpoint ---
//
$endpointKey = $_GET['endpoint'] ?? null;
if (!is_string($endpointKey) || $endpointKey === '') {
    respondJson(['error' => 'Endpoint inválido.'], 400);
}

$endpointMap = [
    'canais'        => 'process_canais.php',
    'canais_status' => 'process_canais_status.php',
    'filmes'        => 'process_filmes.php',
    'filmes_status' => 'process_filmes_status.php',
];

if (!array_key_exists($endpointKey, $endpointMap)) {
    respondJson(['error' => 'Endpoint desconhecido.'], 404);
}

//
// --- URL base da API ---
//
$apiBaseUrl = getenv('IMPORTADOR_API_BASE_URL') ?: ($_ENV['IMPORTADOR_API_BASE_URL'] ?? null);
if (!is_string($apiBaseUrl) || $apiBaseUrl === '') {
    respondJson(['error' => 'IMPORTADOR_API_BASE_URL não configurada no .env'], 500);
}
$apiBaseUrl = rtrim($apiBaseUrl, '/');

//
// --- URL alvo ---
//
$endpointPath = $endpointMap[$endpointKey];
$targetUrl = $apiBaseUrl . '/' . ltrim($endpointPath, '/');

//
// --- Configuração do cURL ---
//
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if (!in_array($method, ['GET', 'POST'], true)) {
    respondJson(['error' => 'Método não suportado.'], 405);
}

$queryParams = $_GET;
unset($queryParams['endpoint']);

$curl = curl_init();
if ($curl === false) {
    respondJson(['error' => 'Falha ao iniciar o proxy de requisição.'], 500);
}

curl_setopt($curl, CURLOPT_URL, $targetUrl);
curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($curl, CURLOPT_TIMEOUT, 600);
curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 30);

$headers = ['User-Agent: ImportadorClienteProxy/1.0'];
if (!empty($_SERVER['HTTP_ACCEPT'])) {
    $headers[] = 'Accept: ' . $_SERVER['HTTP_ACCEPT'];
}

if ($method === 'GET') {
    if (!empty($queryParams)) {
        $separator = strpos($targetUrl, '?') !== false ? '&' : '?';
        $targetUrl .= $separator . http_build_query($queryParams);
        curl_setopt($curl, CURLOPT_URL, $targetUrl);
    }
    curl_setopt($curl, CURLOPT_HTTPGET, true);
} else {
    curl_setopt($curl, CURLOPT_POST, true);
    $postFields = $_POST;

    if (!empty($_FILES)) {
        foreach ($_FILES as $field => $fileInfo) {
            if (is_array($fileInfo['name'])) continue;
            if ((int)($fileInfo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;

            $postFields[$field] = new CURLFile(
                $fileInfo['tmp_name'],
                $fileInfo['type'] ?? 'application/octet-stream',
                $fileInfo['name'] ?? $field
            );
        }
    }

    curl_setopt($curl, CURLOPT_POSTFIELDS, $postFields);
}

if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
    $headers[] = 'Authorization: ' . $_SERVER['HTTP_AUTHORIZATION'];
}

curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

//
// --- Executa requisição ---
//
$responseBody = curl_exec($curl);

if ($responseBody === false) {
    curl_close($curl);
    respondJson(['error' => 'Não foi possível contactar o serviço de importação.'], 502);
}

$httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
$contentType = curl_getinfo($curl, CURLINFO_CONTENT_TYPE) ?: 'application/json; charset=utf-8';

curl_close($curl);

if (!headers_sent()) {
    header('Content-Type: ' . $contentType);
    header('Cache-Control: no-store, max-age=0');
    header('Access-Control-Allow-Origin: *');
}

if ($httpCode === 0) {
    $httpCode = 502;
}

http_response_code($httpCode);

echo $responseBody;
exit;
