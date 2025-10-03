<?php

declare(strict_types=1);

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

$endpointKey = $_GET['endpoint'] ?? null;
if (!is_string($endpointKey) || $endpointKey === '') {
    respondJson(['error' => 'Endpoint inválido.'], 400);
}

$endpointMap = [
    'canais' => '/process_canais.php',
    'canais_status' => '/process_canais_status.php',
    'filmes' => '/process_filmes.php',
    'filmes_status' => '/process_filmes_status.php',
];

if (!array_key_exists($endpointKey, $endpointMap)) {
    respondJson(['error' => 'Endpoint desconhecido.'], 404);
}

$envBaseUrl = getenv('IMPORTADOR_API_BASE_URL') ?: ($_ENV['IMPORTADOR_API_BASE_URL'] ?? null);
$apiBaseUrl = null;

if (is_string($envBaseUrl) && $envBaseUrl !== '') {
    $apiBaseUrl = rtrim($envBaseUrl, '/');
}

if ($apiBaseUrl === null) {
    $host = $_SERVER['HTTP_HOST']
        ?? $_SERVER['SERVER_NAME']
        ?? 'localhost';

    $scheme = 'https';

    $forwardedProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? $_SERVER['HTTP_X_FORWARDED_SCHEME'] ?? null;
    if (is_string($forwardedProto) && $forwardedProto !== '') {
        $forwardedProto = strtolower(trim(explode(',', $forwardedProto)[0]));
        if ($forwardedProto === 'https') {
            $scheme = 'https';
        } elseif ($forwardedProto === 'http') {
            $scheme = 'http';
        }
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_SSL'])) {
        $forwardedSsl = strtolower((string) $_SERVER['HTTP_X_FORWARDED_SSL']);
        if ($forwardedSsl === 'on' || $forwardedSsl === '1') {
            $scheme = 'https';
        }
    } elseif (!empty($_SERVER['HTTP_CF_VISITOR'])) {
        $cfVisitorData = json_decode((string) $_SERVER['HTTP_CF_VISITOR'], true);
        if (is_array($cfVisitorData) && isset($cfVisitorData['scheme'])) {
            $schemeCandidate = strtolower((string) $cfVisitorData['scheme']);
            if (in_array($schemeCandidate, ['http', 'https'], true)) {
                $scheme = $schemeCandidate;
            }
        }
    } elseif (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        $scheme = 'https';
    } elseif (!empty($_SERVER['REQUEST_SCHEME'])) {
        $scheme = strtolower((string) $_SERVER['REQUEST_SCHEME']) === 'https' ? 'https' : 'http';
    } elseif (!empty($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 80) {
        $scheme = 'http';
    }

    $scriptPath = $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '';
    $scriptDir = str_replace('\\', '/', dirname($scriptPath));
    if ($scriptDir === '/' || $scriptDir === '\\' || $scriptDir === '.') {
        $scriptDir = '';
    } else {
        $scriptDir = rtrim($scriptDir, '/');
    }

    $parentDir = $scriptDir === '' ? '' : str_replace('\\', '/', dirname($scriptDir));
    if ($parentDir === '/' || $parentDir === '\\' || $parentDir === '.') {
        $parentDir = '';
    } else {
        $parentDir = rtrim($parentDir, '/');
    }

    $serverPath = ($parentDir === '' ? '' : $parentDir) . '/server';
    $serverPath = '/' . ltrim($serverPath, '/');

    $apiBaseUrl = sprintf('%s://%s%s', $scheme, $host, $serverPath);
    $apiBaseUrl = rtrim($apiBaseUrl, '/');
}

$targetUrl = $apiBaseUrl . $endpointMap[$endpointKey];

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
            if (is_array($fileInfo['name'])) {
                continue;
            }

            if ((int) ($fileInfo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                continue;
            }

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
