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

require_once __DIR__ . '/includes/channel_processing_helpers.php';

set_time_limit(0);

const SUPPORTED_TARGET_CONTAINERS = ['mp4', 'mkv', 'avi', 'mpg', 'flv', '3gp', 'm4v', 'wmv', 'mov', 'ts'];
const WATCH_REFRESH_INSERT_CHUNK_SIZE = 1000;
const STREAM_SOURCE_LOOKUP_CHUNK_SIZE = 1600;

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

if (!function_exists('importador_series_batch_size')) {
    function importador_series_batch_size(): int
    {
        static $batchSize;
        if ($batchSize === null) {
            $batchSize = importador_resolve_batch_size('IMPORTADOR_BATCH_SIZE_SERIES', 2000);
        }

        return $batchSize;
    }
}

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

function sanitizeMessage(string $message): string
{
    $trimmed = trim($message);
    if (function_exists('mb_substr')) {
        return mb_substr($trimmed, 0, 2000, 'UTF-8');
    }
    return substr($trimmed, 0, 2000);
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

function normalizaChave(string $valor): string
{
    $valor = trim($valor);
    return function_exists('mb_strtolower') ? mb_strtolower($valor, 'UTF-8') : strtolower($valor);
}

function isAdultCategory(string $name): bool
{
    return stripos($name, 'adulto') !== false
        || stripos($name, 'xxx') !== false
        || stripos($name, 'onlyfans') !== false;
}

function safePregReplace(string $pattern, string $replacement, string $subject): string
{
    $result = preg_replace($pattern, $replacement, $subject);
    if ($result === null) {
        return $subject;
    }
    return $result;
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
 * @return Generator<int, array{url: string, tvg_logo: string, group_title: string, episode: string}>
 */
function extractSeriesEntries(string $filePath): Generator
{
    $handle = fopen($filePath, 'r');
    if ($handle === false) {
        throw new RuntimeException('Não foi possível ler o ficheiro M3U.');
    }

    $currentInfo = [
        'tvg_logo' => '',
        'group_title' => 'Séries',
        'episode' => '',
    ];

    $normalizer = function_exists('importador_normalize_playlist_text') ? 'importador_normalize_playlist_text' : null;

    try {
        while (($line = fgets($handle)) !== false) {
            if (!is_string($line)) {
                continue;
            }

            if ($normalizer !== null) {
                $line = $normalizer($line);
            }

            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (stripos($line, '#EXTINF:') === 0) {
                preg_match('/tvg-logo="(.*?)"/i', $line, $logoMatch);
                $logo = $logoMatch[1] ?? '';
                $currentInfo['tvg_logo'] = $normalizer !== null ? $normalizer($logo) : $logo;

                $groupTitle = 'Séries';
                if (preg_match('/group-title="(.*?)"/i', $line, $groupMatch)) {
                    $groupTitle = trim($groupMatch[1]);
                }

                if ($normalizer !== null) {
                    $groupTitle = $normalizer($groupTitle);
                }

                $episodeFull = '';
                $pos = strpos($line, '",');
                if ($pos !== false) {
                    $episodeFull = trim(substr($line, $pos + 2));
                }
                if ($episodeFull === '') {
                    $parts = explode(',', $line, 2);
                    $episodeFull = trim($parts[1] ?? '');
                }

                if ($normalizer !== null) {
                    $episodeFull = $normalizer($episodeFull);
                }

                $currentInfo['group_title'] = $groupTitle !== '' ? $groupTitle : 'Séries';
                $currentInfo['episode'] = $episodeFull;
                continue;
            }

            if (!filter_var($line, FILTER_VALIDATE_URL)) {
                continue;
            }

            yield [
                'url' => $line,
                'tvg_logo' => $currentInfo['tvg_logo'] ?? '',
                'group_title' => $normalizer !== null
                    ? $normalizer($currentInfo['group_title'] ?? 'Séries')
                    : ($currentInfo['group_title'] ?? 'Séries'),
                'episode' => $normalizer !== null
                    ? $normalizer($currentInfo['episode'] ?? '')
                    : ($currentInfo['episode'] ?? ''),
            ];
        }
    } finally {
        fclose($handle);
    }
}

function parseSerieSeasonEpisode(string $rawName): ?array
{
    $name = trim($rawName);
    if ($name === '') {
        return null;
    }

    if (!preg_match('/^(.*)\sS(\d{1,2})\s*E(\d{1,3})\s*$/i', $name, $matches)) {
        return null;
    }

    $serieRaw = trim($matches[1]);

    $legendPattern = '/\s*(\(|\[)\s*(leg|l)\s*(\]|\))\s*/i';
    $serieNormalized = safePregReplace($legendPattern, ' [L] ', $serieRaw);
    $serieNormalized = safePregReplace('/\s+/', ' ', $serieNormalized);
    $serieNormalized = trim($serieNormalized);

    $hasLegend = stripos($serieNormalized, '[L]') !== false;
    if ($hasLegend) {
        $serieNormalized = safePregReplace('/\s*\[L\]\s*/i', ' ', $serieNormalized);
        $serieNormalized = safePregReplace('/\s+/', ' ', $serieNormalized);
        $serieNormalized = trim($serieNormalized);
    }

    $year = null;
    if (preg_match('/^(.*?)(?:\s*[-\x{2013}\x{2014}]?\s*[\(\[]\s*(\d{4})\s*[\)\]])\s*$/u', $serieNormalized, $yearMatches)) {
        $serieNormalized = trim($yearMatches[1]);
        $serieNormalized = safePregReplace('/[\s\x{2013}\x{2014}\-:]+$/u', '', $serieNormalized);
        $serieNormalized = trim($serieNormalized);

        $possibleYear = (int) $yearMatches[2];
        if ($possibleYear > 0) {
            $year = $possibleYear;
        }
    }

    $serieNormalized = safePregReplace('/\s+/', ' ', $serieNormalized);
    $serieNormalized = trim($serieNormalized);

    return [
        'serie' => $serieNormalized,
        'season' => (int) $matches[2],
        'episode' => (int) $matches[3],
        'full' => $name,
        'legendado' => $hasLegend,
        'year' => $year,
    ];
}

function formatBrazilianNumber(int $value): string
{
    return number_format($value, 0, ',', '.');
}

function insertWatchRefreshEntries(PDO $pdo, int $type, array $streamIds): void
{
    if (empty($streamIds)) {
        return;
    }

    $uniqueStreamIds = array_values(array_unique(array_map('intval', $streamIds)));
    if (empty($uniqueStreamIds)) {
        return;
    }

    $sqlPrefix = 'INSERT INTO watch_refresh (`type`, stream_id, status) VALUES ';

    foreach (array_chunk($uniqueStreamIds, WATCH_REFRESH_INSERT_CHUNK_SIZE) as $chunk) {
        if (empty($chunk)) {
            continue;
        }

        $placeholders = [];
        $params = [];
        foreach ($chunk as $streamId) {
            $placeholders[] = '(?, ?, ?)';
            $params[] = $type;
            $params[] = $streamId;
            $params[] = 0;
        }

        if (empty($placeholders)) {
            continue;
        }

        $stmt = $pdo->prepare($sqlPrefix . implode(', ', $placeholders));
        $stmt->execute($params);
    }
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
        'Processando séries e episódios (%s/%s)...',
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

function getCategoryId(PDO $pdo, string $name, array &$cache): int
{
    $name = trim($name) !== '' ? trim($name) : 'Séries';
    $key = normalizaChave('series|' . $name);

    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $stmt = $pdo->prepare('SELECT id FROM streams_categories WHERE category_type = :type AND category_name = :name LIMIT 1');
    $stmt->execute([
        ':type' => 'series',
        ':name' => $name,
    ]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        $cache[$key] = (int) $existing['id'];
        return $cache[$key];
    }

    $isAdult = isAdultCategory($name);

    $insert = $pdo->prepare('
        INSERT INTO streams_categories (category_type, category_name, parent_id, cat_order, is_adult)
        VALUES (:type, :name, 0, :cat_order, :is_adult)
    ');
    $insert->execute([
        ':type' => 'series',
        ':name' => $name,
        ':cat_order' => $isAdult ? 9999 : 99,
        ':is_adult' => $isAdult ? 1 : 0,
    ]);

    $id = (int) $pdo->lastInsertId();
    $cache[$key] = $id;
    return $id;
}

function getSeriesId(
    PDO $pdo,
    string $title,
    ?int $year,
    int $categoryId,
    ?string $cover,
    array &$cache,
    PDOStatement $insertStmt,
    bool &$wasCreated
): int {
    $wasCreated = false;
    $title = trim($title);
    $yearKey = ($year !== null && $year > 0) ? (string) $year : '';
    $key = normalizaChave($title . '|' . $yearKey);

    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $insertStmt->execute([
        ':title' => $title,
        ':category_id' => '[' . $categoryId . ']',
        ':cover' => $cover ?: null,
        ':cover_big' => $cover ?: null,
        ':year' => ($year !== null && $year > 0) ? $year : null,
    ]);

    $id = (int) $pdo->lastInsertId();
    $cache[$key] = $id;
    $wasCreated = true;
    return $id;
}

function ensureSeriesEpisodesCached(PDOStatement $stmt, int $seriesId, array &$episodeCache): void
{
    if (isset($episodeCache[$seriesId])) {
        return;
    }

    $episodeCache[$seriesId] = [];

    $stmt->execute([':series_id' => $seriesId]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $season = isset($row['season_num']) ? (int) $row['season_num'] : 0;
        $episode = isset($row['episode_num']) ? (int) $row['episode_num'] : 0;

        if ($season < 0 || $episode < 0) {
            continue;
        }

        if (!isset($episodeCache[$seriesId][$season])) {
            $episodeCache[$seriesId][$season] = [];
        }

        $episodeCache[$seriesId][$season][$episode] = true;
    }

    $stmt->closeCursor();
}

function markEpisodeInCache(array &$episodeCache, int $seriesId, int $season, int $episode): void
{
    if (!isset($episodeCache[$seriesId])) {
        $episodeCache[$seriesId] = [];
    }

    if (!isset($episodeCache[$seriesId][$season])) {
        $episodeCache[$seriesId][$season] = [];
    }

    $episodeCache[$seriesId][$season][$episode] = true;
}

function streamSourceExists(PDOStatement $stmt, array &$streamCache, string $streamSource): bool
{
    if (array_key_exists($streamSource, $streamCache)) {
        return $streamCache[$streamSource];
    }

    $stmt->execute([':source' => $streamSource]);
    $exists = $stmt->fetchColumn() !== false;
    $stmt->closeCursor();

    $streamCache[$streamSource] = $exists;

    return $exists;
}

function hydrateStreamCache(PDO $pdo, array &$pendingStreamSources, array &$streamCache): void
{
    if (empty($pendingStreamSources)) {
        return;
    }

    $pendingKeys = array_keys($pendingStreamSources);
    $pendingStreamSources = [];

    foreach (array_chunk($pendingKeys, STREAM_SOURCE_LOOKUP_CHUNK_SIZE) as $chunk) {
        if (empty($chunk)) {
            continue;
        }

        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $sql = 'SELECT stream_source FROM streams WHERE type = 5 AND stream_source IN (' . $placeholders . ')';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($chunk);

        while (($source = $stmt->fetch(PDO::FETCH_COLUMN)) !== false) {
            if (is_string($source) && $source !== '') {
                $streamCache[$source] = true;
            }
        }

        $stmt->closeCursor();

        foreach ($chunk as $sourceKey) {
            if (!isset($streamCache[$sourceKey])) {
                $streamCache[$sourceKey] = false;
            }
        }
    }
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
        'progress' => 1,
        'message' => 'Iniciando leitura da lista M3U...',
        'started_at' => date('Y-m-d H:i:s'),
    ]);

    $opts = stream_context_create([
        'socket' => ['bindto' => '0.0.0.0:0'],
        'http' => ['timeout' => $streamTimeout, 'follow_location' => 1, 'user_agent' => 'Importador-XUI/1.0'],
        'https' => ['timeout' => $streamTimeout, 'follow_location' => 1, 'user_agent' => 'Importador-XUI/1.0'],
    ]);

    $filename = 'series_' . time() . '_' . substr(md5($m3uUrl), 0, 8) . '.m3u';
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
        'message' => 'Lista M3U carregada temporariamente, sem armazenamento permanente. Conectando ao banco de destino...'
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
    $newSeriesStreamIds = [];
    $newEpisodeStreamIds = [];

    $categoryCache = [];
    $seriesCache = [];
    $episodeCache = [];
    $streamCache = [];
    $pendingStreamSources = [];

    foreach (extractSeriesEntries($fullPath) as $entry) {
        if (($entry['episode'] ?? '') === '') {
            continue;
        }

        $streamInfo = classifySeriesImportEntry($entry);
        if ($streamInfo === null) {
            continue;
        }

        $url = trim((string) ($entry['url'] ?? ''));
        if ($url === '') {
            continue;
        }

        $encoded = json_encode([$url], JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded) || $encoded === '') {
            continue;
        }

        $totalEntries++;

        if (!array_key_exists($encoded, $streamCache) && !isset($pendingStreamSources[$encoded])) {
            $pendingStreamSources[$encoded] = true;
            if (count($pendingStreamSources) >= STREAM_SOURCE_LOOKUP_CHUNK_SIZE) {
                hydrateStreamCache($pdo, $pendingStreamSources, $streamCache);
            }
        }
    }

    hydrateStreamCache($pdo, $pendingStreamSources, $streamCache);

    if ($totalEntries === 0) {
        updateJob($adminPdo, $jobId, ['progress' => 95, 'message' => 'Nenhum episódio válido encontrado. Finalizando...']);
    } else {
        updateJob($adminPdo, $jobId, ['progress' => 10, 'message' => 'Iniciando importação de ' . formatBrazilianNumber($totalEntries) . ' episódios...']);
    }

    unset($pendingStreamSources);

    $categoryStmt = $pdo->query('SELECT id, category_name FROM streams_categories WHERE category_type = "series"');
    while ($row = $categoryStmt->fetch(PDO::FETCH_ASSOC)) {
        $name = $row['category_name'] ?? '';
        $key = normalizaChave('series|' . $name);
        $categoryCache[$key] = (int) $row['id'];
    }

    $seriesStmt = $pdo->query('SELECT id, title, year FROM streams_series');
    while ($row = $seriesStmt->fetch(PDO::FETCH_ASSOC)) {
        $title = $row['title'] ?? '';
        $yearValue = isset($row['year']) ? (int) $row['year'] : null;
        if ($yearValue <= 0) {
            $yearValue = null;
        }
        $yearKey = $yearValue !== null ? (string) $yearValue : '';
        $key = normalizaChave($title . '|' . $yearKey);
        $seriesCache[$key] = (int) $row['id'];
    }

    $loadEpisodesStmt = $pdo->prepare('SELECT season_num, episode_num FROM streams_episodes WHERE series_id = :series_id');
    $checkStreamSourceStmt = $pdo->prepare('SELECT 1 FROM streams WHERE type = 5 AND stream_source = :source LIMIT 1');

    $insertSeriesStmt = $pdo->prepare('
        INSERT INTO streams_series (
            title, category_id, cover, cover_big, genre, plot, cast, rating, director,
            release_date, last_modified, tmdb_id, seasons, episode_run_time, backdrop_path,
            youtube_trailer, tmdb_language, year, plex_uuid, similar
        ) VALUES (
            :title, :category_id, :cover, :cover_big, NULL, NULL, NULL, NULL, NULL,
            NULL, NOW(), NULL, NULL, NULL, NULL,
            NULL, NULL, :year, NULL, NULL
        )
    ');

    $insertStreamStmt = $pdo->prepare('
        INSERT INTO streams (
            type, category_id, stream_display_name, stream_source, stream_icon,
            notes, enable_transcode, transcode_attributes, custom_ffmpeg,
            movie_properties, movie_subtitles, read_native, target_container,
            stream_all, remove_subtitles, `order`, gen_timestamps, direct_source,
            tv_archive_duration, tv_archive_server_id, tv_archive_pid,
            vframes_server_id, vframes_pid, movie_symlink, rtmp_output, allow_record,
            probesize_ondemand, llod, rating, fps_restart, fps_threshold, direct_proxy,
            added
        ) VALUES (
            5, :category_id, :name, :source, :icon,
            NULL, 0, NULL, NULL,
            NULL, NULL, 0, :target_container,
            0, 0, 0, 0, :direct_source,
            0, 0, 0,
            0, 0, 0, 0, 0,
            256000, 0, 0, 0, 90, 0,
            :added
        )
    ');

    $insertEpisodeStmt = $pdo->prepare('
        INSERT INTO streams_episodes (season_num, episode_num, series_id, stream_id)
        VALUES (:season, :episode, :series_id, :stream_id)
    ');

    $processedEntries = 0;
    $totalAdded = 0;
    $totalSkipped = 0;
    $totalErrors = 0;
    $lastCheckpointMarker = null;

    $progressItemEnv = getenv('IMPORTADOR_PROGRESS_UPDATE_ITEMS');
    if ($progressItemEnv !== false && is_numeric($progressItemEnv)) {
        $progressItemThreshold = max(0, (int) $progressItemEnv);
    } else {
        $progressItemThreshold = 250;
    }
    if ($progressItemThreshold <= 0) {
        $progressItemThreshold = null;
    }

    $progressTimeEnv = getenv('IMPORTADOR_PROGRESS_UPDATE_SECONDS');
    if ($progressTimeEnv !== false && is_numeric($progressTimeEnv)) {
        $progressTimeThreshold = max(0, (int) $progressTimeEnv);
    } else {
        $progressTimeThreshold = 30;
    }
    if ($progressTimeThreshold <= 0) {
        $progressTimeThreshold = null;
    }

    $lastProgressUpdateCount = 0;
    $lastProgressUpdateTime = microtime(true);
    $progressUpdateSentWithTotals = false;

    $inTransaction = false;
    $batchCount = 0;

    foreach (extractSeriesEntries($fullPath) as $entry) {
        if (($entry['episode'] ?? '') === '') {
            continue;
        }

        $streamInfo = classifySeriesImportEntry($entry);
        if ($streamInfo === null) {
            continue;
        }

        $url = trim($entry['url']);
        if ($url === '') {
            continue;
        }

        $streamSource = json_encode([$url], JSON_UNESCAPED_SLASHES);
        if (!is_string($streamSource) || $streamSource === '') {
            continue;
        }

        $processedEntries++;
        $episodeNameFull = trim($entry['episode']);

        try {
            if (!$inTransaction) {
                $pdo->beginTransaction();
                $inTransaction = true;
                $batchCount = 0;
            }

            $categoryId = getCategoryId($pdo, $entry['group_title'] ?? 'Séries', $categoryCache);

            $parsed = parseSerieSeasonEpisode($episodeNameFull);
            if ($parsed === null) {
                $totalSkipped++;
                continue;
            }

            $serieTitle = $parsed['serie'];
            if ($parsed['legendado']) {
                $serieTitle = trim($serieTitle . ' [L]');
            }

            $wasNewSeries = false;
            $seriesId = getSeriesId(
                $pdo,
                $serieTitle,
                $parsed['year'] ?? null,
                $categoryId,
                $entry['tvg_logo'] ?? null,
                $seriesCache,
                $insertSeriesStmt,
                $wasNewSeries
            );

            if ($wasNewSeries) {
                $newSeriesStreamIds[$seriesId] = true;
                if (!isset($episodeCache[$seriesId])) {
                    $episodeCache[$seriesId] = [];
                }
            }

            $seasonNumber = (int) $parsed['season'];
            $episodeNumber = (int) $parsed['episode'];

            ensureSeriesEpisodesCached($loadEpisodesStmt, $seriesId, $episodeCache);

            if (isset($episodeCache[$seriesId][$seasonNumber][$episodeNumber])) {
                $totalSkipped++;
                continue;
            }

            if (!array_key_exists($streamSource, $streamCache)) {
                $streamCache[$streamSource] = streamSourceExists($checkStreamSourceStmt, $streamCache, $streamSource);
            }

            if ($streamCache[$streamSource]) {
                $totalSkipped++;
                markEpisodeInCache($episodeCache, $seriesId, $seasonNumber, $episodeNumber);
                continue;
            }

            $episodeDisplay = preg_replace_callback(
                '/^(.*?)(\sS\d{1,2}\s*E\d{1,3})\s*$/i',
                static function (array $matches) use ($serieTitle): string {
                    return $serieTitle . $matches[2];
                },
                $parsed['full']
            );
            if ($episodeDisplay === null) {
                $episodeDisplay = $parsed['full'];
            }
            $episodeDisplay = trim($episodeDisplay);

            $targetContainer = determineTargetContainer($url);

            $insertStreamStmt->execute([
                ':category_id' => '[' . $categoryId . ']',
                ':name' => $episodeDisplay,
                ':source' => $streamSource,
                ':icon' => $entry['tvg_logo'] ?? '',
                ':target_container' => $targetContainer,
                ':direct_source' => $streamInfo['direct_source'],
                ':added' => time(),
            ]);

            $streamId = (int) $pdo->lastInsertId();
            $newEpisodeStreamIds[$streamId] = true;
            $streamCache[$streamSource] = true;

            $insertEpisodeStmt->execute([
                ':season' => $seasonNumber,
                ':episode' => $episodeNumber,
                ':series_id' => $seriesId,
                ':stream_id' => $streamId,
            ]);

            markEpisodeInCache($episodeCache, $seriesId, $seasonNumber, $episodeNumber);

            $totalAdded++;
            $batchCount++;
        } catch (Throwable $e) {
            $totalErrors++;
            logInfo('Erro ao processar episódio: ' . $e->getMessage());
            if ($inTransaction) {
                $pdo->rollBack();
                $inTransaction = false;
            }
            continue;
        } finally {
            if ($batchCount >= importador_series_batch_size() && $inTransaction) {
                $pdo->commit();
                $inTransaction = false;
                $batchCount = 0;
            }

            $checkpoint = createCheckpointMarker($processedEntries, $url);
            if ($checkpoint !== null) {
                $lastCheckpointMarker = $checkpoint;
            }

            $currentTime = microtime(true);
            $shouldDispatchProgress = false;
            $isFinalIteration = ($processedEntries === $totalEntries);

            if ($progressItemThreshold !== null
                && ($processedEntries - $lastProgressUpdateCount) >= $progressItemThreshold
            ) {
                $shouldDispatchProgress = true;
            }

            if (!$shouldDispatchProgress
                && $progressTimeThreshold !== null
                && ($currentTime - $lastProgressUpdateTime) >= $progressTimeThreshold
            ) {
                $shouldDispatchProgress = true;
            }

            if ($isFinalIteration) {
                $shouldDispatchProgress = true;
            }

            if ($shouldDispatchProgress) {
                $progressUpdate = buildProgressUpdate(
                    $processedEntries,
                    $totalEntries,
                    $totalAdded,
                    $totalSkipped,
                    $totalErrors,
                    $lastCheckpointMarker
                );
                updateJob($adminPdo, $jobId, $progressUpdate);
                $lastProgressUpdateCount = $processedEntries;
                $lastProgressUpdateTime = $currentTime;
                if ($isFinalIteration) {
                    $progressUpdateSentWithTotals = true;
                }
            }
        }
    }

    if (!$progressUpdateSentWithTotals) {
        $finalProgressUpdate = buildProgressUpdate(
            $processedEntries,
            $totalEntries,
            $totalAdded,
            $totalSkipped,
            $totalErrors,
            $lastCheckpointMarker
        );
        updateJob($adminPdo, $jobId, $finalProgressUpdate);
        $progressUpdateSentWithTotals = true;
    }

    if ($inTransaction && $pdo->inTransaction()) {
        $pdo->commit();
        $inTransaction = false;
    }

    $seriesIdsForRefresh = array_keys($newSeriesStreamIds);
    if (!empty($seriesIdsForRefresh)) {
        insertWatchRefreshEntries($pdo, 2, $seriesIdsForRefresh);
    }

    $episodeStreamIdsForRefresh = array_keys($newEpisodeStreamIds);
    if (!empty($episodeStreamIdsForRefresh)) {
        insertWatchRefreshEntries($pdo, 3, $episodeStreamIdsForRefresh);
    }

    $summaryLines = [];
    if ($totalEntries === 0) {
        $summaryLines[] = 'ℹ️ Nenhum episódio válido foi encontrado para processamento.';
    } else {
        if ($processedEntries >= $totalEntries) {
            $summaryLines[] = sprintf(
                '✅ Importação concluída. %s de %s episódios processados.',
                formatBrazilianNumber($processedEntries),
                formatBrazilianNumber($totalEntries)
            );
        } else {
            $summaryLines[] = sprintf(
                '⚠️ Importação concluída parcialmente. %s de %s episódios processados.',
                formatBrazilianNumber($processedEntries),
                formatBrazilianNumber($totalEntries)
            );
            if ($lastCheckpointMarker !== null) {
                $summaryLines[] = 'Último checkpoint: ' . $lastCheckpointMarker . '.';
            }
        }
    }

    $summaryLines[] = '➕ Episódios adicionados confirmados: ' . formatBrazilianNumber($totalAdded);
    $summaryLines[] = '⏭️ Episódios ignorados: ' . formatBrazilianNumber($totalSkipped);
    if ($totalErrors > 0) {
        $summaryLines[] = '❗ Ocorrências registradas durante a importação: ' . formatBrazilianNumber($totalErrors) . '.';
    } else {
        $summaryLines[] = '✅ Nenhum erro registrado durante a importação.';
    }

    $summary = implode("\n", $summaryLines) . "\n";

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
if ($job === null || ($job['job_type'] ?? null) !== 'series') {
    logInfo('Job de séries não encontrado: ' . $jobId);
    if (PHP_SAPI !== 'cli') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(404);
        echo json_encode(['error' => 'Job de séries não encontrado.']);
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
    $errorMessage = sanitizeMessage('❌ Erro ao processar séries: ' . $e->getMessage());
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

    logInfo('Erro ao executar job: ' . $e->getMessage());
    exit(1);
}
