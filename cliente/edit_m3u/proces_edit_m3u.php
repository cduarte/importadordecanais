<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

const EDIT_M3U_STORAGE_DIR = __DIR__ . '/storage';
const EDIT_M3U_UPLOAD_DIR = EDIT_M3U_STORAGE_DIR . '/uploads';
const EDIT_M3U_DB_PATH = EDIT_M3U_STORAGE_DIR . '/playlists.sqlite';
const EDIT_M3U_LOG_FILE = EDIT_M3U_STORAGE_DIR . '/logs/m3u_urls.log';
const EDIT_M3U_MAX_SIZE = 200 * 1024 * 1024; // 15 MB

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Método inválido. Utilize POST.');
    }

    $hasFile = isset($_FILES['playlist']) && is_array($_FILES['playlist']) &&
        (int) ($_FILES['playlist']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
    $rawUrl = isset($_POST['playlist_url']) ? trim((string) $_POST['playlist_url']) : '';

    if (!$hasFile && $rawUrl === '') {
        throw new RuntimeException('Envie um arquivo M3U ou informe a URL da playlist.');
    }

    ensureDirectory(EDIT_M3U_STORAGE_DIR);
    ensureDirectory(EDIT_M3U_UPLOAD_DIR);

    if ($rawUrl !== '') {
        logSubmittedUrl($rawUrl);
    }
    $pdo = getDatabaseConnection();

    if ($hasFile) {
        $payload = handleUploadedFile($pdo, $_FILES['playlist']);
    } else {
        $payload = handleRemoteFile($pdo, $rawUrl);
    }

    respond(['success' => true] + $payload);
} catch (Throwable $error) {
    respond([
        'success' => false,
        'error' => $error->getMessage(),
    ], 400);
}

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function ensureDirectory(string $directory): void
{
    if (is_dir($directory)) {
        return;
    }

    if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException(sprintf('Não foi possível criar o diretório "%s".', $directory));
    }
}

function getDatabaseConnection(): PDO
{
    $pdo = new PDO('sqlite:' . EDIT_M3U_DB_PATH, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS playlists (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            source_type TEXT NOT NULL,
            original_name TEXT NOT NULL,
            stored_name TEXT NOT NULL,
            stored_path TEXT NOT NULL,
            file_size INTEGER NOT NULL,
            mime_type TEXT,
            source_url TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );

    return $pdo;
}

function handleUploadedFile(PDO $pdo, array $file): array
{
    $tmpName = $file['tmp_name'] ?? null;
    if (!is_string($tmpName) || !is_uploaded_file($tmpName)) {
        throw new RuntimeException('Arquivo temporário inválido.');
    }

    $originalName = sanitizeOriginalName($file['name'] ?? 'playlist.m3u');
    $mimeType = is_string($file['type'] ?? null) ? $file['type'] : null;
    $content = file_get_contents($tmpName);
    if ($content === false) {
        throw new RuntimeException('Não foi possível ler o arquivo enviado.');
    }

    return persistPlaylist($pdo, $content, $originalName, 'file', $mimeType, null);
}

function handleRemoteFile(PDO $pdo, string $url): array
{
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        throw new RuntimeException('Informe uma URL válida para a playlist.');
    }

    $content = downloadUrl($url, $effectiveUrl, $mimeType);
    if ($content === null) {
        throw new RuntimeException('Não foi possível baixar a playlist informada.');
    }

    $nameFromUrl = basename(parse_url($effectiveUrl ?? $url, PHP_URL_PATH) ?? '') ?: 'playlist.m3u';
    $originalName = sanitizeOriginalName($nameFromUrl);

    return persistPlaylist($pdo, $content, $originalName, 'url', $mimeType, $effectiveUrl ?? $url);
}

function downloadUrl(string $url, ?string &$effectiveUrl = null, ?string &$mimeType = null): ?string
{
    $effectiveUrl = $url;
    $mimeType = null;

    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('Não foi possível inicializar o download da URL.');
        }

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_USERAGENT => 'EditM3U/1.0 (+https://example.local)',
        ]);

        $content = curl_exec($curl);
        if ($content === false) {
            $errorMessage = curl_error($curl);
            curl_close($curl);
            throw new RuntimeException('Falha ao baixar a playlist: ' . $errorMessage);
        }

        $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $effectiveUrl = curl_getinfo($curl, CURLINFO_EFFECTIVE_URL) ?: $url;
        $mimeType = curl_getinfo($curl, CURLINFO_CONTENT_TYPE) ?: null;
        curl_close($curl);

        if ($httpCode >= 400) {
            throw new RuntimeException(sprintf('Servidor respondeu com código HTTP %d ao baixar a playlist.', $httpCode));
        }

        return $content;
    }

    $context = stream_context_create([
        'http' => [
            'timeout' => 120,
            'header' => "User-Agent: EditM3U/1.0\r\n",
        ],
    ]);

    $content = @file_get_contents($url, false, $context);
    if ($content === false) {
        return null;
    }

    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $header) {
            if (stripos($header, 'content-type:') === 0) {
                $mimeType = trim(substr($header, strlen('content-type:')));
            } elseif (stripos($header, 'location:') === 0) {
                $effectiveUrl = trim(substr($header, strlen('location:')));
            }
        }
    }

    return $content;
}

function logSubmittedUrl(string $url): void
{
    if ($url === '') {
        return;
    }

    $logDirectory = dirname(EDIT_M3U_LOG_FILE);
    if (!is_dir($logDirectory) && !mkdir($logDirectory, 0775, true) && !is_dir($logDirectory)) {
        return;
    }

    $logEntry = sprintf('[%s] %s%s', date('Y-m-d H:i:s'), $url, PHP_EOL);
    @file_put_contents(EDIT_M3U_LOG_FILE, $logEntry, FILE_APPEND | LOCK_EX);
}

function persistPlaylist(
    PDO $pdo,
    string $content,
    string $originalName,
    string $sourceType,
    ?string $mimeType,
    ?string $sourceUrl
): array {
    if ($content === '') {
        throw new RuntimeException('A playlist está vazia.');
    }

    if (strlen($content) > EDIT_M3U_MAX_SIZE) {
        throw new RuntimeException('O arquivo M3U excede o tamanho máximo permitido de 15 MB.');
    }

    if (!mb_check_encoding($content, 'UTF-8')) {
        $encoding = mb_detect_encoding($content, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true);
        if ($encoding && $encoding !== 'UTF-8') {
            $content = mb_convert_encoding($content, 'UTF-8', $encoding);
        }
    }

    $storedName = generateStoredName($originalName);
    $storedPath = EDIT_M3U_UPLOAD_DIR . '/' . $storedName;

    if (file_put_contents($storedPath, $content, LOCK_EX) === false) {
        throw new RuntimeException('Não foi possível salvar o arquivo M3U.');
    }

    $relativePath = 'storage/uploads/' . $storedName;
    $size = strlen($content);

    $stmt = $pdo->prepare(
        'INSERT INTO playlists (
            source_type,
            original_name,
            stored_name,
            stored_path,
            file_size,
            mime_type,
            source_url
        ) VALUES (
            :source_type,
            :original_name,
            :stored_name,
            :stored_path,
            :file_size,
            :mime_type,
            :source_url
        )'
    );

    $stmt->execute([
        ':source_type' => $sourceType,
        ':original_name' => $originalName,
        ':stored_name' => $storedName,
        ':stored_path' => $relativePath,
        ':file_size' => $size,
        ':mime_type' => $mimeType,
        ':source_url' => $sourceUrl,
    ]);

    $id = (int) $pdo->lastInsertId();

    return [
        'id' => $id,
        'fileName' => $originalName,
        'storedName' => $storedName,
        'storedPath' => $relativePath,
        'sourceType' => $sourceType,
        'sourceUrl' => $sourceUrl,
        'size' => $size,
        'mimeType' => $mimeType,
        'content' => $content,
        'createdAt' => date('c'),
    ];
}

function sanitizeOriginalName(string $name): string
{
    $name = trim($name);
    $name = $name !== '' ? $name : 'playlist.m3u';
    return $name;
}

function generateStoredName(string $originalName): string
{
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($extension === '') {
        $extension = 'm3u';
    }

    $hash = bin2hex(random_bytes(8));
    return $hash . '-' . preg_replace('/[^a-z0-9_.-]/i', '_', pathinfo($originalName, PATHINFO_BASENAME) ?: 'playlist.' . $extension);
}
