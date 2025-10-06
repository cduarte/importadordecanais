<?php

declare(strict_types=1);

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

const SUPPORTED_TARGET_CONTAINERS = ['mp4', 'mkv', 'avi', 'mpg', 'flv', '3gp', 'm4v', 'wmv', 'mov', 'ts'];
const WATCH_REFRESH_INSERT_CHUNK_SIZE = 500;
const DEFAULT_PROGRESS_UPDATE_ITEM_THRESHOLD = 200;
const DEFAULT_PROGRESS_UPDATE_MIN_INTERVAL_SECONDS = 10;

if (!function_exists('importador_resolve_batch_size')) {
    function importador_resolve_batch_size(string $envKey, int $default): int
    {
        $value = getenv($envKey);
        if ($value === false) {
            return max(1, $default);
        }

        if (is_numeric($value)) {
            $intValue = (int) $value;
            return $intValue > 0 ? $intValue : max(1, $default);
        }

        return max(1, $default);
    }
}

if (!function_exists('importador_filmes_batch_size')) {
    function importador_filmes_batch_size(): int
    {
        static $batchSize;
        if ($batchSize === null) {
            $batchSize = importador_resolve_batch_size('IMPORTADOR_BATCH_SIZE_FILMES', 1000);
        }

        return $batchSize;
    }
}

$timeoutEnv = getenv('IMPORTADOR_M3U_TIMEOUT');
$streamTimeout = ($timeoutEnv !== false && is_numeric($timeoutEnv) && (int) $timeoutEnv > 0)
    ? (int) $timeoutEnv
    : 600;

ini_set('default_socket_timeout', (string) $streamTimeout);

function safePregReplace($pattern, $replacement, $subject): string
{
    $result = preg_replace($pattern, $replacement, $subject);

    if ($result === null) {
        return is_string($subject) ? $subject : (string) $subject;
    }

    return is_string($result) ? $result : (string) $result;
}

function formatBrazilianNumber(int $value): string
{
    return number_format($value, 0, ',', '.');
}

function insertWatchRefreshEntries(PDO $pdo, array $streamIds): void
{
    if (empty($streamIds)) {
        return;
    }

    $chunkSize = WATCH_REFRESH_INSERT_CHUNK_SIZE;
    $sqlPrefix = 'INSERT INTO watch_refresh (`type`, stream_id, status) VALUES ';

    foreach (array_chunk($streamIds, $chunkSize) as $chunk) {
        $placeholders = [];
        $params = [];

        foreach ($chunk as $streamId) {
            $placeholders[] = '(?, ?, ?)';
            $params[] = 1;
            $params[] = $streamId;
            $params[] = 0;
        }

        $stmt = $pdo->prepare($sqlPrefix . implode(', ', $placeholders));
        try {
            $stmt->execute($params);
        } catch (PDOException $e) {
            throw new RuntimeException('Erro ao registrar dados de atualização de watch: ' . $e->getMessage(), 0, $e);
        }
    }
}

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

function getStreamTypeByUrl(string $url): ?array
{
    if (stripos($url, '/movie/') !== false) {
        return ['type' => 2, 'category_type' => 'movie', 'direct_source' => 1];
    }
    return null;
}

function parseMovieTitle(string $rawName): array
{
    $name = trim($rawName);

    if ($name === '') {
        return [
            'title' => '',
            'legendado' => false,
            'year' => null,
        ];
    }

    $normalized = safePregReplace('/(?:\s*[-\x{2013}\x{2014}]?\s*)?(?:\(|\[)?\s*(legendado|leg)\b\s*(?:\]|\))?/iu', ' [L] ', $name);
    $legendPattern = '/\s*(\(|\[)\s*(leg|l)\s*(\]|\))\s*/i';
    $normalized = safePregReplace($legendPattern, ' [L] ', $normalized);
    $normalized = safePregReplace('/\s+/', ' ', $normalized);
    $normalized = trim($normalized);

    $hasLegend = stripos($normalized, '[L]') !== false;
    if ($hasLegend) {
        $normalized = safePregReplace('/\s*\[L\]\s*/i', ' ', $normalized);
        $normalized = safePregReplace('/\s+/', ' ', $normalized);
        $normalized = trim($normalized);
    }

    $year = null;
    $yearPatterns = [
        '/^(?P<title>.*)(?:\s*[-\x{2013}\x{2014}:]?\s*(?:[\(\[]\s*(?P<year>(?:19|20)\d{2})\s*[\)\]]|(?P<year_alt>(?:19|20)\d{2}))(?:\s*(?P<suffix>(?:\s*(?:\(|\[)[^\)\]]*(?:\)|\]))*))?)\s*$/u',
        '/^(?P<title>.*\S)(?:\s*[-\x{2013}\x{2014}:]?\s*(?P<year>(?:19|20)\d{2}))\s*$/u',
        '/^(?P<year>(?:19|20)\d{2})\s*$/u',
    ];

    foreach ($yearPatterns as $pattern) {
        if (!preg_match($pattern, $normalized, $matches)) {
            continue;
        }

        $yearValue = null;
        if (!empty($matches['year'])) {
            $yearValue = (int) $matches['year'];
        } elseif (!empty($matches['year_alt'])) {
            $yearValue = (int) $matches['year_alt'];
        }

        if ($yearValue === null || $yearValue <= 0) {
            continue;
        }

        $titlePart = $matches['title'] ?? '';
        $suffixPart = $matches['suffix'] ?? '';

        if ($titlePart !== '') {
            $titlePart = rtrim($titlePart);
            $titlePart = safePregReplace('/[\s\x{2013}\x{2014}\-:]+$/u', '', $titlePart);
            $titlePart = trim($titlePart);
        }

        $combinedTitle = $titlePart;
        if ($suffixPart !== '') {
            $suffixPart = trim($suffixPart);
            if ($suffixPart !== '') {
                $combinedTitle = $combinedTitle !== '' ? ($combinedTitle . ' ' . $suffixPart) : $suffixPart;
            }
        }

        $normalized = $combinedTitle;
        $year = $yearValue;
        break;
    }

    $normalized = safePregReplace('/\s+/', ' ', $normalized);
    $normalized = trim($normalized);

    return [
        'title' => $normalized,
        'legendado' => $hasLegend,
        'year' => $year,
    ];
}

function gerarChaveCategoria(string $nome, string $tipo): string
{
    $chave = trim($tipo) . '|' . trim($nome);
    return function_exists('mb_strtolower') ? mb_strtolower($chave, 'UTF-8') : strtolower($chave);
}

function isAdultCategory(string $categoryName): bool
{
    return stripos($categoryName, 'adulto') !== false || stripos($categoryName, 'xxx') !== false;
}

function getCategoryId(PDO $pdo, string $categoryName, string $categoryType): int
{
    static $cache = [];
    $categoryName = trim($categoryName) !== '' ? trim($categoryName) : 'Filmes';
    $cacheKey = gerarChaveCategoria($categoryName, $categoryType);

    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $stmt = $pdo->prepare('SELECT id FROM streams_categories WHERE category_name = :name AND category_type = :type LIMIT 1');
    $stmt->execute([':name' => $categoryName, ':type' => $categoryType]);
    $res = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($res) {
        $cache[$cacheKey] = (int) $res['id'];
        return (int) $res['id'];
    }

    $isAdult = isAdultCategory($categoryName);
    $insert = $pdo->prepare('
        INSERT INTO streams_categories (category_type, category_name, parent_id, cat_order, is_adult)
        VALUES (:type, :name, 0, :cat_order, :is_adult)
    ');
    $insert->execute([
        ':type' => $categoryType,
        ':name' => $categoryName,
        ':cat_order' => $isAdult ? 9999 : 99,
        ':is_adult' => $isAdult ? 1 : 0,
    ]);

    $lastId = (int) $pdo->lastInsertId();
    $cache[$cacheKey] = $lastId;

    return $lastId;
}

function determineTargetContainer(string $url): string
{
    $path = parse_url($url, PHP_URL_PATH);
    $extension = is_string($path) ? strtolower((string) pathinfo($path, PATHINFO_EXTENSION)) : '';
    if ($extension && in_array($extension, SUPPORTED_TARGET_CONTAINERS, true)) {
        return $extension;
    }
    return 'mp4';
}

/**
 * @return \Generator<int, array{url: string, tvg_logo: string, group_title: string, tvg_name: string}>
 */
function extractEntries(string $filePath): \Generator
{
    $handle = fopen($filePath, 'r');
    if ($handle === false) {
        throw new RuntimeException('Não foi possível ler o ficheiro M3U.');
    }

    $currentInfo = [
        'tvg_logo' => '',
        'group_title' => 'Filmes',
        'tvg_name' => '',
    ];

    try {
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (stripos($line, '#EXTINF:') === 0) {
                preg_match('/tvg-logo="(.*?)"/', $line, $logoMatch);
                $currentInfo['tvg_logo'] = $logoMatch[1] ?? '';

                $groupTitle = 'Filmes';
                if (preg_match('/group-title="(.*?)"/', $line, $groupMatch)) {
                    $groupTitle = trim($groupMatch[1]);
                }

                $title = '';
                $pos = strpos($line, '",');
                if ($pos !== false) {
                    $title = trim(substr($line, $pos + 2));
                }
                if ($title === '') {
                    $parts = explode(',', $line, 2);
                    $title = trim($parts[1] ?? '');
                }

                $currentInfo['group_title'] = $groupTitle ?: 'Filmes';
                $currentInfo['tvg_name'] = $title;
                continue;
            }

            if (!filter_var($line, FILTER_VALIDATE_URL)) {
                continue;
            }

            yield [
                'url' => $line,
                'tvg_logo' => $currentInfo['tvg_logo'] ?? '',
                'group_title' => $currentInfo['group_title'] ?? 'Filmes',
                'tvg_name' => $currentInfo['tvg_name'] ?? '',
            ];
        }
    } finally {
        fclose($handle);
    }
}

function sanitizeMessage(string $message): string
{
    $trimmed = trim($message);
    if (function_exists('mb_substr')) {
        return mb_substr($trimmed, 0, 2000, 'UTF-8');
    }
    return substr($trimmed, 0, 2000);
}

function createCheckpointMarker(int $processedEntries, string $url): ?string
{
    $normalizedUrl = trim($url);
    if ($processedEntries <= 0 || $normalizedUrl === '') {
        return null;
    }

    $hash = hash('sha256', $normalizedUrl);
    return $processedEntries . ':' . substr($hash, 0, 12);
}

function buildProgressUpdate(
    int $processedEntries,
    int $totalEntries,
    int $totalAdded,
    int $totalSkipped,
    int $totalErrors,
    ?string $checkpointMarker
): array {
    if ($totalEntries > 0) {
        $progress = 10 + (int) floor(($processedEntries / $totalEntries) * 85);
        if ($progress > 99) {
            $progress = 99;
        }
    } else {
        $progress = 99;
    }

    $message = sprintf(
        'Processando filmes (%s/%s)...',
        formatBrazilianNumber($processedEntries),
        formatBrazilianNumber($totalEntries)
    );
    if ($checkpointMarker !== null) {
        $message .= ' Último marcador: ' . $checkpointMarker;
    }

    return [
        'progress' => $progress,
        'message' => $message,
        'total_added' => $totalAdded,
        'total_skipped' => $totalSkipped,
        'total_errors' => $totalErrors,
    ];
}

function persistProgressUpdate(
    PDO $adminPdo,
    int $jobId,
    ?array $latestProgressUpdate,
    int &$confirmedAdded,
    int &$confirmedSkipped,
    int &$confirmedErrors,
    ?string $lastCheckpointMarker,
    ?string &$lastPersistedCheckpoint
): bool {
    if ($latestProgressUpdate === null) {
        return false;
    }

    updateJob($adminPdo, $jobId, $latestProgressUpdate);

    if (isset($latestProgressUpdate['total_added'])) {
        $confirmedAdded = (int) $latestProgressUpdate['total_added'];
    }
    if (isset($latestProgressUpdate['total_skipped'])) {
        $confirmedSkipped = (int) $latestProgressUpdate['total_skipped'];
    }
    if (isset($latestProgressUpdate['total_errors'])) {
        $confirmedErrors = (int) $latestProgressUpdate['total_errors'];
    }

    if ($lastCheckpointMarker !== null) {
        $lastPersistedCheckpoint = $lastCheckpointMarker;
    }

    return true;
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

    updateJob($adminPdo, $jobId, ['status' => 'running', 'progress' => 1, 'message' => 'Iniciando lista M3U...', 'started_at' => date('Y-m-d H:i:s')]);

    $opts = stream_context_create([
        'socket' => [
            'bindto' => '0.0.0.0:0', // força IPv4
        ],

        'http' => ['timeout' => $streamTimeout, 'follow_location' => 1, 'user_agent' => 'Importador-XUI/1.0'],
        'https' => ['timeout' => $streamTimeout, 'follow_location' => 1, 'user_agent' => 'Importador-XUI/1.0'],
    ]);

    $filename = 'filmes_' . time() . '_' . substr(md5($m3uUrl), 0, 8) . '.m3u';
    $fullPath = $uploadDir . $filename;
    $readStream = @fopen($m3uUrl, 'rb', false, $opts);
    if ($readStream === false) {
        throw new RuntimeException('Não foi possível acessar a lista M3U informada em tempo real.');
    }

    $writeStream = @fopen($fullPath, 'wb');
    if ($writeStream === false) {
        fclose($readStream);
        throw new RuntimeException('Não foi possível preparar o processamento temporário da lista M3U.');
    }

    $bytesCopied = @stream_copy_to_stream($readStream, $writeStream);
    fclose($writeStream);
    fclose($readStream);

    if ($bytesCopied === false) {
        @unlink($fullPath);
        throw new RuntimeException('Não foi possível preparar o processamento temporário da lista M3U.');
    }

    $fileSize = @filesize($fullPath);
    if ($fileSize === false || $fileSize === 0) {
        @unlink($fullPath);
        throw new RuntimeException('Não foi possível preparar o processamento temporário da lista M3U.');
    }

    updateJob($adminPdo, $jobId, [
        'm3u_file_path' => $fullPath,
        'progress' => 5,
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

    updateJob($adminPdo, $jobId, [
        'progress' => 7,
        'message' => 'Banco de destino conectado. Analisando a lista M3U antes da importação...'
    ]);

    $totalEntries = 0;
    foreach (extractEntries($fullPath) as $entry) {
        $streamInfo = getStreamTypeByUrl($entry['url']);
        if ($streamInfo === null || (int) $streamInfo['type'] !== 2) {
            continue;
        }
        $totalEntries++;
    }
    $processedEntries = 0;

    if ($totalEntries === 0) {
        updateJob($adminPdo, $jobId, ['progress' => 95, 'message' => 'Nenhum item válido encontrado. Finalizando...']);
    } else {
        updateJob($adminPdo, $jobId, ['progress' => 10, 'message' => 'Iniciando importação de ' . formatBrazilianNumber($totalEntries) . ' itens...']);
    }

    $confirmedAdded = isset($job['total_added']) ? (int) $job['total_added'] : 0;
    $confirmedSkipped = isset($job['total_skipped']) ? (int) $job['total_skipped'] : 0;
    $confirmedErrors = isset($job['total_errors']) ? (int) $job['total_errors'] : 0;
    $totalAdded = $confirmedAdded;
    $totalSkipped = $confirmedSkipped;
    $totalErrors = $confirmedErrors;
    $lastPersistedCheckpoint = null;

    $progressThresholdEnv = getenv('IMPORTADOR_PROGRESS_UPDATE_ITEM_THRESHOLD');
    $progressUpdateItemThreshold = ($progressThresholdEnv !== false && is_numeric($progressThresholdEnv) && (int) $progressThresholdEnv > 0)
        ? (int) $progressThresholdEnv
        : DEFAULT_PROGRESS_UPDATE_ITEM_THRESHOLD;

    $progressIntervalEnv = getenv('IMPORTADOR_PROGRESS_UPDATE_MIN_INTERVAL');
    $progressUpdateMinInterval = ($progressIntervalEnv !== false && is_numeric($progressIntervalEnv) && (int) $progressIntervalEnv > 0)
        ? (int) $progressIntervalEnv
        : DEFAULT_PROGRESS_UPDATE_MIN_INTERVAL_SECONDS;

    $itemsSinceLastProgressUpdate = 0;
    $lastProgressUpdateTime = microtime(true);

    // Cache em memória das fontes já existentes para evitar consultas repetidas durante a importação.
    $streamCache = [];
    try {
        $streamStmt = $pdo->query('SELECT stream_source FROM streams WHERE type = 2');
        while ($row = $streamStmt->fetch(PDO::FETCH_ASSOC)) {
            $source = $row['stream_source'] ?? '';
            if (is_string($source) && $source !== '') {
                $streamCache[$source] = true;
            }
        }
    } catch (PDOException $e) {
        throw new RuntimeException('Erro ao carregar streams existentes: ' . $e->getMessage(), 0, $e);
    }
    $insertStmt = $pdo->prepare('
        INSERT INTO streams (
            type, category_id, stream_display_name, stream_source, stream_icon, year,
            enable_transcode, read_native, direct_source, added, stream_all,
            remove_subtitles, `order`, gen_timestamps, tv_archive_duration, target_container,
            tv_archive_server_id, tv_archive_pid, vframes_server_id, vframes_pid,
            movie_symlink, rtmp_output, allow_record, probesize_ondemand, llod,
            rating, fps_restart, fps_threshold, direct_proxy, external_push, auto_restart
        ) VALUES (
            :type, :category_id, :name, :source, :icon, :year,
            0, 0, :direct_source, :added, 0,
            0, 0, 0, 0, :target_container,
            0, 0, 0, 0,
            0, 0, 0, 256000, 0,
            0, 0, 90, 0, "{}", ""
        )
    ');

    try {
        $inTransaction = false;
        $batchInsertions = 0;
        $lastCheckpointMarker = null;
        $latestProgressUpdate = null;
        $newStreamIds = [];

        foreach (extractEntries($fullPath) as $entry) {
            $streamInfo = getStreamTypeByUrl($entry['url']);
            if ($streamInfo === null || (int) $streamInfo['type'] !== 2) {
                continue;
            }

            $url = trim($entry['url']);

            $categoryType = $streamInfo['category_type'];
            $directSource = $streamInfo['direct_source'];
            $categoryId = getCategoryId($pdo, $entry['group_title'] ?? 'Filmes', $categoryType);
            $streamSource = json_encode([$url], JSON_UNESCAPED_SLASHES);
            $added = time();
            if (isset($streamCache[$streamSource])) {
                $totalSkipped++;
                $processedEntries++;
                $itemsSinceLastProgressUpdate++;
                $lastCheckpointMarker = createCheckpointMarker($processedEntries, $url);
                $latestProgressUpdate = buildProgressUpdate(
                    $processedEntries,
                    $totalEntries,
                    $totalAdded,
                    $totalSkipped,
                    $totalErrors,
                    $lastCheckpointMarker
                );
                $shouldFlushProgress = $itemsSinceLastProgressUpdate >= $progressUpdateItemThreshold;
                if (!$shouldFlushProgress && $progressUpdateMinInterval > 0) {
                    $shouldFlushProgress = (microtime(true) - $lastProgressUpdateTime) >= $progressUpdateMinInterval;
                }
                if (!$inTransaction && $shouldFlushProgress) {
                    if (persistProgressUpdate(
                        $adminPdo,
                        $jobId,
                        $latestProgressUpdate,
                        $confirmedAdded,
                        $confirmedSkipped,
                        $confirmedErrors,
                        $lastCheckpointMarker,
                        $lastPersistedCheckpoint
                    )) {
                        $itemsSinceLastProgressUpdate = 0;
                        $lastProgressUpdateTime = microtime(true);
                    }
                }
                continue;
            }

            $sourceName = $entry['tvg_name'] ?? '';
            if (!is_string($sourceName)) {
                $sourceName = '';
            }

            $parsedTitle = parseMovieTitle($sourceName);
            $parsedTitleTitle = $parsedTitle['title'];
            if (!is_string($parsedTitleTitle)) {
                $parsedTitleTitle = '';
            }

            $movieTitle = $parsedTitleTitle !== '' ? $parsedTitleTitle : ($sourceName !== '' ? $sourceName : 'Sem Nome');
            $hasLegend = $parsedTitle['legendado'];
            $movieYear = $parsedTitle['year'];

            $displayName = $movieTitle !== '' ? $movieTitle : 'Sem Nome';
            if (!is_string($displayName) || $displayName === '') {
                $displayName = 'Sem Nome';
            }
            if ($hasLegend) {
                $displayName = trim($displayName) . ' [L]';
            }
            $displayName = trim($displayName);
            $displayName = safePregReplace('/\s+/', ' ', $displayName);

            $targetContainer = determineTargetContainer($url);

            if (!$inTransaction) {
                $pdo->beginTransaction();
                $inTransaction = true;
                $batchInsertions = 0;
            }

            try {
                $insertStmt->execute([
                    ':type' => $streamInfo['type'],
                    ':category_id' => '[' . $categoryId . ']',
                    ':name' => $displayName,
                    ':source' => $streamSource,
                    ':icon' => $entry['tvg_logo'] ?? '',
                    ':year' => $movieYear !== null ? $movieYear : null,
                    ':direct_source' => $directSource,
                    ':added' => $added,
                    ':target_container' => $targetContainer,
                ]);

                $streamId = (int) $pdo->lastInsertId();
                if ($streamId <= 0) {
                    throw new RuntimeException('Falha ao obter o ID do stream recém inserido.');
                }

                $newStreamIds[] = $streamId;
                $streamCache[$streamSource] = true;

                $totalAdded++;
            } catch (PDOException $e) {
                $errorMessage = $e->getMessage();
                if (str_contains($errorMessage, 'Base table or view not found')) {
                    throw new RuntimeException("A tabela 'streams' não existe no banco de dados.");
                }
                if (str_contains($errorMessage, 'Unknown column')) {
                    throw new RuntimeException("A tabela 'streams' existe, mas colunas necessárias não foram encontradas.");
                }
                throw new RuntimeException('Erro ao inserir stream: ' . $errorMessage);
            }

            $processedEntries++;
            $batchInsertions++;
            $itemsSinceLastProgressUpdate++;
            $lastCheckpointMarker = createCheckpointMarker($processedEntries, $url);
            $latestProgressUpdate = buildProgressUpdate(
                $processedEntries,
                $totalEntries,
                $totalAdded,
                $totalSkipped,
                $totalErrors,
                $lastCheckpointMarker
            );

            if ($batchInsertions >= importador_filmes_batch_size()) {
                if ($pdo->inTransaction()) {
                    $pdo->commit();
                }
                $inTransaction = false;
                $batchInsertions = 0;

                if (persistProgressUpdate(
                    $adminPdo,
                    $jobId,
                    $latestProgressUpdate,
                    $confirmedAdded,
                    $confirmedSkipped,
                    $confirmedErrors,
                    $lastCheckpointMarker,
                    $lastPersistedCheckpoint
                )) {
                    $itemsSinceLastProgressUpdate = 0;
                    $lastProgressUpdateTime = microtime(true);
                }
            }
        }
        if ($inTransaction && $pdo->inTransaction()) {
            $pdo->commit();
            if (persistProgressUpdate(
                $adminPdo,
                $jobId,
                $latestProgressUpdate,
                $confirmedAdded,
                $confirmedSkipped,
                $confirmedErrors,
                $lastCheckpointMarker,
                $lastPersistedCheckpoint
            )) {
                $itemsSinceLastProgressUpdate = 0;
                $lastProgressUpdateTime = microtime(true);
            }
        } elseif (persistProgressUpdate(
            $adminPdo,
            $jobId,
            $latestProgressUpdate,
            $confirmedAdded,
            $confirmedSkipped,
            $confirmedErrors,
            $lastCheckpointMarker,
            $lastPersistedCheckpoint
        )) {
            $itemsSinceLastProgressUpdate = 0;
            $lastProgressUpdateTime = microtime(true);
        }

        if (!empty($newStreamIds)) {
            insertWatchRefreshEntries($pdo, $newStreamIds);
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $confirmedProcessed = $confirmedAdded + $confirmedSkipped + $confirmedErrors;
        $partialDetails = [];
        if ($confirmedProcessed > 0) {
            if ($totalEntries > 0) {
                $partialDetails[] = 'Importação interrompida após confirmar ' . formatBrazilianNumber($confirmedProcessed) . ' de ' . formatBrazilianNumber($totalEntries) . ' itens.';
            } else {
                $partialDetails[] = 'Importação interrompida após confirmar ' . formatBrazilianNumber($confirmedProcessed) . ' itens.';
            }
            $partialDetails[] = 'Adicionados: ' . formatBrazilianNumber($confirmedAdded) . ', ignorados: ' . formatBrazilianNumber($confirmedSkipped) . '.';
        }
        if ($lastPersistedCheckpoint !== null) {
            $partialDetails[] = 'Último checkpoint: ' . $lastPersistedCheckpoint . '.';
        }
        $partialDetails[] = 'Motivo: ' . $e->getMessage();

        throw new RuntimeException(implode(' ', $partialDetails), 0, $e);
    }

    $confirmedProcessed = $confirmedAdded + $confirmedSkipped + $confirmedErrors;
    $summaryLines = [];
    $summaryLines[] = 'Resumo da importação de filmes:';

    if ($totalEntries === 0) {
        $summaryLines[] = 'ℹ️ Nenhum item válido foi encontrado para processamento.';
    } else {
        if ($confirmedProcessed >= $totalEntries) {
            $statusLine = '✅ Importação concluída com sucesso. ' . formatBrazilianNumber($confirmedProcessed) . ' de ' . formatBrazilianNumber($totalEntries) . ' itens confirmados.';
        } else {
            $statusLine = '⚠️ Importação concluída parcialmente. ' . formatBrazilianNumber($confirmedProcessed) . ' de ' . formatBrazilianNumber($totalEntries) . ' itens confirmados.';
            if ($lastPersistedCheckpoint !== null) {
                $statusLine .= ' Último checkpoint: ' . $lastPersistedCheckpoint . '.';
            }
        }
        $summaryLines[] = $statusLine;
    }

    $summaryLines[] = '➕ Filmes adicionados confirmados: ' . formatBrazilianNumber($confirmedAdded);
    $summaryLines[] = '⏭️ Filmes ignorados (duplicados) confirmados: ' . formatBrazilianNumber($confirmedSkipped);
    if ($confirmedErrors > 0) {
        $summaryLines[] = "❗ Ocorrências registradas durante a importação.";
    }

    $summary = implode("\n", $summaryLines) . "\n";

    return [
        'message' => $summary,
        'totals' => [
            'added' => $confirmedAdded,
            'skipped' => $confirmedSkipped,
            'errors' => $confirmedErrors,
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
if ($job === null || ($job['job_type'] ?? null) !== 'movies') {
    logInfo('Job de filmes não encontrado: ' . $jobId);
    if (PHP_SAPI !== 'cli') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(404);
        echo json_encode(['error' => 'Job de filmes não encontrado.']);
    }
    exit(1);
}

if ($job['status'] === 'running') {
    logInfo('Job já está em execução: ' . $jobId);
    exit(0);
}

logInfo('Iniciando processamento do job ' . $jobId);

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

    logInfo('Job concluído com sucesso.');
} catch (Throwable $e) {
    $errorMessage = sanitizeMessage('❌ Erro ao processar filmes: ' . $e->getMessage());
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
        logInfo('Falha ao registrar erro no histórico: ' . $logException->getMessage());
    }

    logInfo('Job finalizado com erro: ' . $e->getMessage());
    exit(1);
}
