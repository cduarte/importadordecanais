<?php

declare(strict_types=1);

set_time_limit(0);

const SUPPORTED_TARGET_CONTAINERS = ['mp4', 'mkv', 'avi', 'mpg', 'flv', '3gp', 'm4v', 'wmv', 'mov', 'ts'];
const BATCH_SIZE = 200;

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

    $message = "Processando filmes ({$processedEntries}/{$totalEntries})...";
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

    $contents = @file_get_contents($m3uUrl, false, $opts);
    if ($contents === false) {
        throw new RuntimeException('Erro ao baixar a lista M3U informada.');
    }

    $filename = 'm3u_' . time() . '_' . substr(md5($m3uUrl), 0, 8) . '.m3u';
    $fullPath = $uploadDir . $filename;
    if (file_put_contents($fullPath, $contents) === false) {
        throw new RuntimeException('Erro ao gravar a lista M3U no servidor.');
    }

    updateJob($adminPdo, $jobId, [
        'm3u_file_path' => $fullPath,
        'progress' => 5,
        'message' => 'Lista M3U verfificada. Conectando ao banco de destino...'
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
        updateJob($adminPdo, $jobId, ['progress' => 10, 'message' => "Iniciando importação de {$totalEntries} itens..."]);
    }

    $confirmedAdded = isset($job['total_added']) ? (int) $job['total_added'] : 0;
    $confirmedSkipped = isset($job['total_skipped']) ? (int) $job['total_skipped'] : 0;
    $confirmedErrors = isset($job['total_errors']) ? (int) $job['total_errors'] : 0;
    $totalAdded = $confirmedAdded;
    $totalSkipped = $confirmedSkipped;
    $totalErrors = $confirmedErrors;
    $lastPersistedCheckpoint = null;

    $hashLookupStmt = $adminPdo->prepare('
        SELECT stream_id
        FROM clientes_import_stream_hashes
        WHERE db_host = :host AND db_name = :name AND stream_source_hash = :hash
        LIMIT 1
    ');
    $hashDeleteStmt = $adminPdo->prepare('
        DELETE FROM clientes_import_stream_hashes
        WHERE db_host = :host AND db_name = :name AND stream_source_hash = :hash
        LIMIT 1
    ');
    $hashRegisterStmt = $adminPdo->prepare('
        INSERT INTO clientes_import_stream_hashes (db_host, db_name, stream_id, stream_source_hash)
        VALUES (:host, :name, :stream_id, :hash)
        ON DUPLICATE KEY UPDATE
            stream_id = VALUES(stream_id),
            updated_at = CURRENT_TIMESTAMP
    ');
    $streamExistsStmt = $pdo->prepare('SELECT 1 FROM streams WHERE id = :id LIMIT 1');
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
    $deleteStreamStmt = $pdo->prepare('DELETE FROM streams WHERE id = :id LIMIT 1');

    try {
        $inTransaction = false;
        $batchInsertions = 0;
        $lastCheckpointMarker = null;
        $latestProgressUpdate = null;

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
            $streamSourceHash = hash('sha256', $streamSource);
            $added = time();

            $hashParams = [
                ':host' => $host,
                ':name' => $dbname,
                ':hash' => $streamSourceHash,
            ];

            try {
                $hashLookupStmt->execute($hashParams);
                $existingHash = $hashLookupStmt->fetch(PDO::FETCH_ASSOC);
                $hashLookupStmt->closeCursor();
            } catch (PDOException $e) {
                throw new RuntimeException('Erro ao verificar duplicata: ' . $e->getMessage());
            }

            $shouldSkip = false;
            if ($existingHash !== false && $existingHash !== null) {
                $shouldSkip = true;
                $existingStreamId = $existingHash['stream_id'] !== null ? (int) $existingHash['stream_id'] : null;

                if ($existingStreamId !== null && $existingStreamId > 0) {
                    try {
                        $streamExistsStmt->execute([':id' => $existingStreamId]);
                        $streamStillExists = (bool) $streamExistsStmt->fetchColumn();
                        $streamExistsStmt->closeCursor();
                    } catch (PDOException $existsException) {
                        throw new RuntimeException('Erro ao validar stream existente: ' . $existsException->getMessage());
                    }

                    if (!$streamStillExists) {
                        try {
                            $hashDeleteStmt->execute($hashParams);
                        } catch (PDOException $deleteException) {
                            throw new RuntimeException('Erro ao limpar hash obsoleto: ' . $deleteException->getMessage());
                        }
                        $shouldSkip = false;
                    }
                }
            }

            if ($shouldSkip) {
                $totalSkipped++;
                $processedEntries++;
                $lastCheckpointMarker = createCheckpointMarker($processedEntries, $url);
                $latestProgressUpdate = buildProgressUpdate(
                    $processedEntries,
                    $totalEntries,
                    $totalAdded,
                    $totalSkipped,
                    $totalErrors,
                    $lastCheckpointMarker
                );
                if (!$inTransaction && $latestProgressUpdate !== null) {
                    updateJob($adminPdo, $jobId, $latestProgressUpdate);
                    $confirmedAdded = $latestProgressUpdate['total_added'];
                    $confirmedSkipped = $latestProgressUpdate['total_skipped'];
                    $confirmedErrors = $latestProgressUpdate['total_errors'];
                    if ($lastCheckpointMarker !== null) {
                        $lastPersistedCheckpoint = $lastCheckpointMarker;
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

                try {
                    $hashRegisterStmt->execute([
                        ':host' => $host,
                        ':name' => $dbname,
                        ':stream_id' => $streamId,
                        ':hash' => $streamSourceHash,
                    ]);
                } catch (PDOException $hashException) {
                    $deleteStreamStmt->execute([':id' => $streamId]);
                    throw new RuntimeException('Erro ao registrar hash do stream: ' . $hashException->getMessage());
                }

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
            $lastCheckpointMarker = createCheckpointMarker($processedEntries, $url);
            $latestProgressUpdate = buildProgressUpdate(
                $processedEntries,
                $totalEntries,
                $totalAdded,
                $totalSkipped,
                $totalErrors,
                $lastCheckpointMarker
            );

            if ($batchInsertions >= BATCH_SIZE) {
                if ($pdo->inTransaction()) {
                    $pdo->commit();
                }
                $inTransaction = false;
                $batchInsertions = 0;

                if ($latestProgressUpdate !== null) {
                    updateJob($adminPdo, $jobId, $latestProgressUpdate);
                    $confirmedAdded = $latestProgressUpdate['total_added'];
                    $confirmedSkipped = $latestProgressUpdate['total_skipped'];
                    $confirmedErrors = $latestProgressUpdate['total_errors'];
                    if ($lastCheckpointMarker !== null) {
                        $lastPersistedCheckpoint = $lastCheckpointMarker;
                    }
                }
            }
        }
        if ($inTransaction && $pdo->inTransaction()) {
            $pdo->commit();
            if ($latestProgressUpdate !== null) {
                updateJob($adminPdo, $jobId, $latestProgressUpdate);
                $confirmedAdded = $latestProgressUpdate['total_added'];
                $confirmedSkipped = $latestProgressUpdate['total_skipped'];
                $confirmedErrors = $latestProgressUpdate['total_errors'];
                if ($lastCheckpointMarker !== null) {
                    $lastPersistedCheckpoint = $lastCheckpointMarker;
                }
            }
        } elseif ($latestProgressUpdate !== null) {
            updateJob($adminPdo, $jobId, $latestProgressUpdate);
            $confirmedAdded = $latestProgressUpdate['total_added'];
            $confirmedSkipped = $latestProgressUpdate['total_skipped'];
            $confirmedErrors = $latestProgressUpdate['total_errors'];
            if ($lastCheckpointMarker !== null) {
                $lastPersistedCheckpoint = $lastCheckpointMarker;
            }
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $confirmedProcessed = $confirmedAdded + $confirmedSkipped + $confirmedErrors;
        $partialDetails = [];
        if ($confirmedProcessed > 0) {
            if ($totalEntries > 0) {
                $partialDetails[] = "Importação interrompida após confirmar {$confirmedProcessed} de {$totalEntries} itens.";
            } else {
                $partialDetails[] = "Importação interrompida após confirmar {$confirmedProcessed} itens.";
            }
            $partialDetails[] = "Adicionados: {$confirmedAdded}, ignorados: {$confirmedSkipped}.";
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
            $statusLine = "✅ Importação concluída com sucesso. {$confirmedProcessed} de {$totalEntries} itens confirmados.";
        } else {
            $statusLine = "⚠️ Importação concluída parcialmente. {$confirmedProcessed} de {$totalEntries} itens confirmados.";
            if ($lastPersistedCheckpoint !== null) {
                $statusLine .= ' Último checkpoint: ' . $lastPersistedCheckpoint . '.';
            }
        }
        $summaryLines[] = $statusLine;
    }

    $summaryLines[] = "➕ Filmes adicionados confirmados: {$confirmedAdded}";
    $summaryLines[] = "⏭️ Filmes ignorados (duplicados) confirmados: {$confirmedSkipped}";
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
