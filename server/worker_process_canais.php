<?php

declare(strict_types=1);

// --- Loader manual do .env ---
if (!function_exists('importador_load_env')) {
    function importador_load_env(): void
    {
        static $loaded = false;
        if ($loaded) {
            return;
        }
        $loaded = true;

        $paths = [
            __DIR__ . '/.env',
            dirname(__DIR__) . '/.env',
        ];

        $seen = [];
        foreach ($paths as $path) {
            if (!is_string($path) || $path === '' || !is_file($path)) {
                continue;
            }
            if (isset($seen[$path])) {
                continue;
            }
            $seen[$path] = true;

            $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines === false) {
                continue;
            }

            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') {
                    continue;
                }
                if (strpos($line, '=') === false) {
                    continue;
                }

                [$name, $value] = array_map('trim', explode('=', $line, 2));
                if ($name === '') {
                    continue;
                }

                putenv("{$name}={$value}");
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }
    }
}

importador_load_env();

set_time_limit(0);

const CHANNEL_PROGRESS_START = 10;
const CHANNEL_PROGRESS_END = 95;
const CHANNEL_PROGRESS_INIT = 1;
const CHANNEL_PROGRESS_DOWNLOAD = 5;
const PROGRESS_MAX = 99;
const CHANNEL_BATCH_UPDATE = 50;

require_once __DIR__ . '/includes/channel_processing_helpers.php';

$timeoutEnv = getenv('IMPORTADOR_M3U_TIMEOUT');
$streamTimeout = ($timeoutEnv !== false && is_numeric($timeoutEnv) && (int) $timeoutEnv > 0)
    ? (int) $timeoutEnv
    : 600;

ini_set('default_socket_timeout', (string) $streamTimeout);

if (PHP_SAPI === 'cli' && function_exists('pcntl_signal') && function_exists('pcntl_alarm')) {
    pcntl_signal(SIGALRM, static function (): void {
        throw new RuntimeException('Tempo limite do worker atingido.');
    });
    pcntl_alarm(max(60, min(3600, $streamTimeout * 2)));
}

function logInfo(string $message): void
{
    $line = '[' . date('c') . '] ' . $message;
    if (PHP_SAPI === 'cli' && defined('STDOUT')) {
        fwrite(STDOUT, $line . PHP_EOL);
    } else {
        error_log($line);
    }
}

function updateJob(PDO $adminPdo, int $jobId, array $fields): void
{
    if (empty($fields)) {
        return;
    }

    $allowed = [
        'status',
        'progress',
        'message',
        'm3u_file_path',
        'total_added',
        'total_skipped',
        'total_errors',
        'started_at',
        'finished_at',
    ];

    $setParts = [];
    $params = [':id' => $jobId];
    foreach ($fields as $column => $value) {
        if (!in_array($column, $allowed, true)) {
            continue;
        }
        if ($column === 'message' && is_string($value)) {
            $value = sanitizeMessage($value);
        }
        $placeholder = ':' . $column;
        $setParts[] = "`{$column}` = {$placeholder}";
        $params[$placeholder] = $value;
    }

    if (empty($setParts)) {
        return;
    }

    $setParts[] = '`updated_at` = NOW()';
    $sql = 'UPDATE clientes_import_jobs SET ' . implode(', ', $setParts) . ' WHERE id = :id LIMIT 1';

    $stmt = $adminPdo->prepare($sql);
    $stmt->execute($params);
}

function fetchJob(PDO $adminPdo, int $jobId): ?array
{
    $stmt = $adminPdo->prepare('SELECT * FROM clientes_import_jobs WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $jobId]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);
    return $job ?: null;
}

function getCategoryId(PDO $pdo, string $categoryName, string $categoryType): int
{
    static $cache = [];

    $categoryName = trim($categoryName) !== '' ? trim($categoryName) : 'Canais';
    $categoryType = trim($categoryType) !== '' ? trim($categoryType) : 'live';

    $cacheKey = strtolower($categoryType . '|' . $categoryName);
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $stmt = $pdo->prepare('SELECT id FROM streams_categories WHERE category_name = :name LIMIT 1');
    $stmt->execute([':name' => $categoryName]);
    $res = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($res) {
        $cache[$cacheKey] = (int) $res['id'];
        return (int) $res['id'];
    }

    $insert = $pdo->prepare('
        INSERT INTO streams_categories (category_type, category_name, parent_id, cat_order, is_adult)
        VALUES (:type, :name, 0, 99, 0)
    ');
    $insert->execute([
        ':type' => $categoryType,
        ':name' => $categoryName,
    ]);

    $lastId = (int) $pdo->lastInsertId();
    $cache[$cacheKey] = $lastId;

    return $lastId;
}

function processJob(PDO $adminPdo, array $job, int $streamTimeout): array
{
    $jobId = (int) $job['id'];
    $host = $job['db_host'];
    $dbname = $job['db_name'];
    $user = $job['db_user'];
    $pass = $job['db_password'];
    $m3uUrl = $job['m3u_url'];

    $uploadDir = __DIR__ . '/m3u_uploads/';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0777, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Não foi possível criar o diretório de uploads.');
    }

    updateJob($adminPdo, $jobId, [
        'status' => 'running',
        'progress' => CHANNEL_PROGRESS_INIT,
        'message' => 'Iniciando processamento da lista M3U...',
        'started_at' => date('Y-m-d H:i:s'),
    ]);

    $opts = stream_context_create([
        'socket' => ['bindto' => '0.0.0.0:0'],
        'http' => ['timeout' => $streamTimeout, 'follow_location' => 1, 'user_agent' => 'Importador-XUI/1.0'],
        'https' => ['timeout' => $streamTimeout, 'follow_location' => 1, 'user_agent' => 'Importador-XUI/1.0'],
    ]);

    $contents = @file_get_contents($m3uUrl, false, $opts);
    if ($contents === false) {
        throw new RuntimeException('Não foi possível acessar a lista M3U informada em tempo real.');
    }

    $filename = 'canais_' . time() . '_' . substr(md5($m3uUrl), 0, 8) . '.m3u';
    $fullPath = $uploadDir . $filename;
    if (file_put_contents($fullPath, $contents) === false) {
        throw new RuntimeException('Não foi possível preparar o processamento temporário da lista M3U.');
    }

    updateJob($adminPdo, $jobId, [
        'm3u_file_path' => $fullPath,
        'progress' => CHANNEL_PROGRESS_DOWNLOAD,
        'message' => 'Lista M3U verificada para processamento imediato, sem armazenamento permanente. Conectando ao banco de destino...'
    ]);

    try {
        $pdo = new PDO(
            "mysql:host={$host};dbname={$dbname};charset=utf8mb4",
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    } catch (PDOException $e) {
        throw new RuntimeException('Erro ao conectar no banco de dados de destino: ' . $e->getMessage());
    }

    // Mantém em memória as fontes já cadastradas para acelerar a verificação de duplicados.
    $existingSources = [];
    try {
        $sourcesStmt = $pdo->query('SELECT stream_source FROM streams WHERE type = 1');
        while (($row = $sourcesStmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            if (!isset($row['stream_source'])) {
                continue;
            }
            $existingSources[(string) $row['stream_source']] = true;
        }
        $sourcesStmt->closeCursor();
    } catch (PDOException $e) {
        throw new RuntimeException('Erro ao consultar fontes de streams existentes: ' . $e->getMessage());
    }

    $totalEntries = 0;
    foreach (extractChannelEntries($fullPath) as $entry) {
        $streamInfo = getStreamTypeByUrl($entry['url']);
        if ((int) $streamInfo['type'] !== 1) {
            continue;
        }
        $totalEntries++;
    }

    if ($totalEntries === 0) {
        updateJob($adminPdo, $jobId, ['progress' => CHANNEL_PROGRESS_END, 'message' => 'Nenhum canal válido encontrado. Finalizando...']);
    } else {
        updateJob($adminPdo, $jobId, ['progress' => CHANNEL_PROGRESS_START, 'message' => 'Iniciando importação de ' . formatBrazilianNumber($totalEntries) . ' canais...']);
    }

    $totalAdded = (int) ($job['total_added'] ?? 0);
    $totalSkipped = (int) ($job['total_skipped'] ?? 0);
    $totalErrors = (int) ($job['total_errors'] ?? 0);

    $processedEntries = 0;

    $insertStmt = $pdo->prepare('
        INSERT INTO streams (
            type, category_id, stream_display_name, stream_source, stream_icon,
            enable_transcode, read_native, direct_source, added
        ) VALUES (
            :type, :category_id, :name, :source, :icon,
            0, 0, :direct_source, :added
        )
    ');

    foreach (extractChannelEntries($fullPath) as $entry) {
        $streamInfo = getStreamTypeByUrl($entry['url']);
        if ((int) $streamInfo['type'] !== 1) {
            continue;
        }

        $processedEntries++;
        $categoryId = getCategoryId($pdo, $entry['group_title'] ?? 'Canais', $streamInfo['category_type']);
        $streamSource = json_encode([$entry['url']], JSON_UNESCAPED_SLASHES);
        $added = time();

        if (isset($existingSources[$streamSource])) {
            $totalSkipped++;
        } else {
            $displayName = $entry['tvg_name'] ?? 'Sem Nome';
            if (!is_string($displayName) || trim($displayName) === '') {
                $displayName = 'Sem Nome';
            }

            try {
                $insertStmt->execute([
                    ':type' => 1,
                    ':category_id' => $categoryId,
                    ':name' => $displayName,
                    ':source' => $streamSource,
                    ':icon' => $entry['tvg_logo'] ?? '',
                    ':direct_source' => 1,
                    ':added' => $added,
                ]);
                $existingSources[$streamSource] = true;
                $totalAdded++;
            } catch (PDOException $e) {
                $totalErrors++;
            }
        }

        if ($processedEntries % CHANNEL_BATCH_UPDATE === 0) {
            $update = buildProgressUpdate($processedEntries, $totalEntries, $totalAdded, $totalSkipped, $totalErrors);
            updateJob($adminPdo, $jobId, $update);
        }
    }

    $finalUpdate = buildProgressUpdate($processedEntries, $totalEntries, $totalAdded, $totalSkipped, $totalErrors);
    $finalUpdate['progress'] = CHANNEL_PROGRESS_END;
    $finalUpdate['message'] = $totalEntries === 0
        ? 'Nenhum canal válido encontrado. Finalizando...'
        : 'Finalizando importação de canais...';
    updateJob($adminPdo, $jobId, $finalUpdate);

    $summary = "Resultado:\n";
    $summary .= '✅ Canais adicionados: ' . formatBrazilianNumber($totalAdded) . "\n";
    $summary .= '⚠️ Canais ignorados (duplicados): ' . formatBrazilianNumber($totalSkipped) . "\n";
    if ($totalErrors > 0) {
        $summary .= "❗ Ocorrências registradas durante a importação.\n";
    }

    return [
        'message' => $summary,
        'totals' => [
            'added' => $totalAdded,
            'skipped' => $totalSkipped,
            'errors' => $totalErrors,
        ],
        'm3u_file_path' => $fullPath,
    ];
}

$adminDbHost = '127.0.0.1';
$adminDbName = 'joaopedro_xui';
$adminDbUser = 'joaopedro_user';
$adminDbPass = 'd@z[VGxj)~FNCft6';

try {
    $adminPdo = new PDO(
        "mysql:host={$adminDbHost};dbname={$adminDbName};charset=utf8mb4",
        $adminDbUser,
        $adminDbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    logInfo('Erro ao conectar no banco administrador: ' . $e->getMessage());
    exit(1);
}

$jobId = null;
if (PHP_SAPI === 'cli') {
    global $argv;
    $jobId = isset($argv[1]) ? (int) $argv[1] : null;
} else {
    $jobId = isset($_REQUEST['job_id']) ? (int) $_REQUEST['job_id'] : null;
}

if (!$jobId) {
    logInfo('job_id não informado.');
    if (PHP_SAPI !== 'cli') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(400);
        echo json_encode(['error' => 'job_id é obrigatório.']);
    }
    exit(1);
}

$job = fetchJob($adminPdo, $jobId);
if ($job === null || ($job['job_type'] ?? null) !== 'channels') {
    logInfo('Job de canais não encontrado: ' . $jobId);
    if (PHP_SAPI !== 'cli') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(404);
        echo json_encode(['error' => 'Job de canais não encontrado.']);
    }
    exit(1);
}

if ($job['status'] === 'running') {
    logInfo('Job já está em execução: ' . $jobId);
    exit(0);
}

logInfo('Iniciando processamento do job de canais ' . $jobId);

try {
    $result = processJob($adminPdo, $job, $streamTimeout);

    $totals = $result['totals'];
    updateJob($adminPdo, $jobId, [
        'status' => 'done',
        'progress' => 100,
        'message' => sanitizeMessage($result['message']),
        'total_added' => $totals['added'],
        'total_skipped' => $totals['skipped'],
        'total_errors' => $totals['errors'],
        'finished_at' => date('Y-m-d H:i:s'),
    ]);

    $stmt = $adminPdo->prepare('
        INSERT INTO clientes_import (
            db_host, db_name, db_user, db_password, m3u_url, m3u_file_path, api_token,
            last_import_status, last_import_message, last_import_at, import_count,
            client_ip, client_user_agent
        ) VALUES (
            :host, :dbname, :user, :pass, :m3u_url, :m3u_file, :token,
            :status, :msg, NOW(), :total, :ip, :ua
        )
    ');
    $stmt->execute([
        ':host' => $job['db_host'],
        ':dbname' => $job['db_name'],
        ':user' => $job['db_user'],
        ':pass' => $job['db_password'],
        ':m3u_url' => $job['m3u_url'],
        ':m3u_file' => $result['m3u_file_path'],
        ':token' => $job['api_token'],
        ':status' => 'sucesso',
        ':msg' => $result['message'],
        ':total' => $totals['added'],
        ':ip' => $job['client_ip'] ?? null,
        ':ua' => $job['client_user_agent'] ?? null,
    ]);

    logInfo('Job de canais concluído com sucesso.');
} catch (Throwable $e) {
    $errorMessage = sanitizeMessage('❌ Erro ao processar canais: ' . $e->getMessage());
    updateJob($adminPdo, $jobId, [
        'status' => 'failed',
        'message' => $errorMessage,
        'progress' => 100,
        'finished_at' => date('Y-m-d H:i:s'),
    ]);

    try {
        $stmt = $adminPdo->prepare('
            INSERT INTO clientes_import (
                db_host, db_name, db_user, db_password, m3u_url, m3u_file_path, api_token,
                last_import_status, last_import_message, client_ip, client_user_agent
            ) VALUES (
                :host, :dbname, :user, :pass, :m3u_url, :m3u_file, :token,
                :status, :msg, :ip, :ua
            )
        ');
        $stmt->execute([
            ':host' => $job['db_host'],
            ':dbname' => $job['db_name'],
            ':user' => $job['db_user'],
            ':pass' => $job['db_password'],
            ':m3u_url' => $job['m3u_url'],
            ':m3u_file' => $job['m3u_file_path'] ?? null,
            ':token' => $job['api_token'],
            ':status' => 'erro',
            ':msg' => $errorMessage,
            ':ip' => $job['client_ip'] ?? null,
            ':ua' => $job['client_user_agent'] ?? null,
        ]);
    } catch (PDOException $logException) {
        logInfo('Falha ao registrar erro no histórico de canais: ' . $logException->getMessage());
    }

    logInfo('Job de canais finalizado com erro: ' . $e->getMessage());
    exit(1);
}
