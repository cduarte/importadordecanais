<?php
require __DIR__ . '/../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->load();

// --------------------------------------------------
// CONFIGURAÇÕES
// --------------------------------------------------
$host = $_ENV['DB_HOST'];
$dbname = $_ENV['DB_NAME'];
$username = $_ENV['DB_USER'];
$password = $_ENV['DB_PASS'];

const TAMANHO_DO_LOTE = 2000;
const SUPPORTED_TARGET_CONTAINERS = ['mp4', 'mkv', 'avi', 'mpg', 'flv', '3gp', 'm4v', 'wmv', 'mov', 'ts'];
$m3uFile = "../files/lista_series.m3u"; // caminho do arquivo M3U de séries

// cria pasta logs se não existir
$logsDir = __DIR__ . "/logs";
if (!is_dir($logsDir)) {
    mkdir($logsDir, 0777, true);
}

$logIgnorados = $logsDir . "/ignorados.log";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    die('❌ Erro ao conectar no banco: ' . $e->getMessage());
}

if (!file_exists($m3uFile)) {
    die('❌ Arquivo M3U não encontrado!');
}

// limpa o log de ignorados no início
file_put_contents($logIgnorados, "");

// --------------------------------------------------
// HELPERS
// --------------------------------------------------
function normalizaChave(string $s): string {
    $s = trim($s);
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}

/**
 * Função para validar URL de série
 */
function getStreamTypeByUrl(string $url): ?array {
    if (stripos($url, "/series/") !== false) {
        return [
            'type'          => 5, // ATENÇÃO! Não está sendo usado no código esses parâmetros retornados!
            'category_type' => 'series',
            'direct_source' => 1
        ];
    }
    return null;
}

/**
 * Extrai categoria e episódio completo a partir da linha #EXTINF
 */
function extractCategoryAndEpisode(string $line): array {
    $category = 'Séries';
    $episodeFull = '';

    if (preg_match('/group-title="(.*?)"/', $line, $m)) {
        $category = trim($m[1]);
    }

    // pega tudo depois de '",' (fecha aspas do group-title + vírgula)
    $pos = strpos($line, '",');
    if ($pos !== false) {
        $episodeFull = trim(substr($line, $pos + 2));
    }

    return [$category, $episodeFull];
}

function isAdultCategory(string $categoryName): bool {
    return stripos($categoryName, 'adulto') !== false
        || stripos($categoryName, 'xxx') !== false
        || stripos($categoryName, 'onlyfans') !== false;
}

/**
 * Extrai série, temporada e episódio do nome completo
 * Ex.: "Fantasmas (2024) S01 E01"
 */
function parseSerieSeasonEpisode(string $rawName): ?array {
    $name = trim($rawName);
    if (!preg_match('/^(.*)\sS(\d{1,2})\s*E(\d{1,3})\s*$/i', $name, $m)) {
        return null;
    }

    $serieRaw = trim($m[1]);

    // Normaliza indicações de legendado para um único [L]
    $legendPattern = '/\s*(\(|\[)\s*(leg|l)\s*(\]|\))\s*/i';
    $serieNormalized = preg_replace($legendPattern, ' [L] ', $serieRaw);
    $serieNormalized = preg_replace('/\s+/', ' ', $serieNormalized);
    $serieNormalized = trim($serieNormalized);

    $hasLegend = stripos($serieNormalized, '[L]') !== false;
    if ($hasLegend) {
        $serieNormalized = preg_replace('/\s*\[L\]\s*/i', ' ', $serieNormalized);
        $serieNormalized = preg_replace('/\s+/', ' ', $serieNormalized);
        $serieNormalized = trim($serieNormalized);
    }

    // Extrai ano (ex.: "Título - (2024)") e remove do título
    $year = null;
    if (preg_match('/^(.*?)(?:\s*[-\x{2013}\x{2014}]?\s*[\(\[]\s*(\d{4})\s*[\)\]])\s*$/u', $serieNormalized, $yearMatches)) {
        $serieNormalized = trim($yearMatches[1]);
        $serieNormalized = preg_replace('/[\s\x{2013}\x{2014}\-:]+$/u', '', $serieNormalized);
        $serieNormalized = trim($serieNormalized);

        $possibleYear = (int)$yearMatches[2];
        if ($possibleYear > 0) {
            $year = $possibleYear;
        }
    }

    $serieNormalized = preg_replace('/\s+/', ' ', $serieNormalized);
    $serieNormalized = trim($serieNormalized);

    return [
        'serie'    => $serieNormalized,
        'season'   => (int)$m[2],
        'episode'  => (int)$m[3],
        'full'     => $name,
        'legendado' => $hasLegend,
        'year'     => $year,
    ];
}

function getCategoryId(PDO $pdo, string $name, array &$categoryCache, PDOStatement $insertCategoryStmt): int {
    $name = trim($name);
    if ($name === '') $name = 'Outros';
    $key = normalizaChave('series|' . $name);

    if (isset($categoryCache[$key])) return $categoryCache[$key];

    $isAdult = isAdultCategory($name);

    $insertCategoryStmt->execute([
        ':type' => 'series',
        ':name' => $name,
        ':cat_order' => $isAdult ? 9999 : 99,
        ':is_adult' => $isAdult ? 1 : 0,
    ]);
    $id = (int)$pdo->lastInsertId();
    $categoryCache[$key] = $id;
    return $id;
}

function getSeriesId(PDO $pdo, string $title, ?int $year, int $categoryId, ?string $cover, array &$seriesCache, PDOStatement $insertSeriesStmt): int {
    $title = trim($title);
    $normalizedYear = ($year !== null && $year > 0) ? $year : null;
    $yearKey = $normalizedYear !== null ? (string)$normalizedYear : '';
    $key   = normalizaChave($title . '|' . $yearKey);

    if (isset($seriesCache[$key])) return $seriesCache[$key];

    $insertSeriesStmt->execute([
        ':title'       => $title,
        ':category_id' => '[' . $categoryId . ']',
        ':cover'       => $cover ?: NULL,
        ':cover_big'   => $cover ?: NULL,
        ':year'        => $normalizedYear,
    ]);
    $id = (int)$pdo->lastInsertId();
    $seriesCache[$key] = $id;
    return $id;
}

// --------------------------------------------------
// PRÉ-CARGA DE CACHES
// --------------------------------------------------
$categoryCache = [];
$seriesCache   = [];
$streamCache   = [];
$episodeCache  = [];

// Categorias existentes
$q = $pdo->query('SELECT id, category_name, category_type FROM streams_categories');
while ($r = $q->fetch()) {
    $key = normalizaChave(($r['category_type'] ?? '') . '|' . ($r['category_name'] ?? ''));
    $categoryCache[$key] = (int)$r['id'];
}

// Séries existentes
$q = $pdo->query('SELECT id, title, year FROM streams_series');
while ($r = $q->fetch()) {
    $title = $r['title'] ?? '';
    $yearValue = isset($r['year']) ? (int)$r['year'] : null;
    if ($yearValue <= 0) {
        $yearValue = null;
    }
    $yearKey = $yearValue !== null ? (string)$yearValue : '';
    $key = normalizaChave($title . '|' . $yearKey);
    $seriesCache[$key] = (int)$r['id'];
}

// Streams existentes
$q = $pdo->query('SELECT stream_source FROM streams WHERE type = 5');
while ($r = $q->fetch()) {
    if (!empty($r['stream_source'])) {
        $streamCache[$r['stream_source']] = true;
    }
}

// Episodes existentes
$q = $pdo->query('SELECT series_id, season_num, episode_num FROM streams_episodes');
while ($r = $q->fetch()) {
    $k = $r['series_id'] . ':' . ((int)$r['season_num']) . ':' . ((int)$r['episode_num']);
    $episodeCache[$k] = true;
}

// --------------------------------------------------
// PREPARED STATEMENTS
// --------------------------------------------------
$insertCategoryStmt = $pdo->prepare('
    INSERT INTO streams_categories (category_type, category_name, parent_id, cat_order, is_adult)
    VALUES (:type, :name, 0, :cat_order, :is_adult)
');

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

// --------------------------------------------------
// PROCESSAMENTO DO M3U
// --------------------------------------------------
$lines = file($m3uFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

$tvg_logo = null;
$totalStreamsInseridos = 0;
$totalEpisodiosInseridos = 0;
$totalIgnorados = 0;
$inseridosNoLote = 0;

try {
    $pdo->beginTransaction();

    foreach ($lines as $line) {
        if (strpos($line, '#EXTINF:') === 0) {
            preg_match('/tvg-logo="(.*?)"/', $line, $logoMatch);
            $tvg_logo = $logoMatch[1] ?? '';

            [$group_title, $episodeNameFull] = extractCategoryAndEpisode($line);
            continue;
        }

        if (!filter_var($line, FILTER_VALIDATE_URL)) {
            continue;
        }

        $url = trim($line);

        // garante que só processa URLs válidas de série
        $streamInfo = getStreamTypeByUrl($url);
        if ($streamInfo === null) {
            continue;
        }

        if (empty($episodeNameFull)) continue;

        $categoryId = getCategoryId($pdo, $group_title, $categoryCache, $insertCategoryStmt);

        $parsed = parseSerieSeasonEpisode($episodeNameFull);
        if ($parsed === null) {
            echo "⚠️ Ignorado (não casou regex): $episodeNameFull\n";
            file_put_contents($logIgnorados, $episodeNameFull . PHP_EOL, FILE_APPEND);
            $totalIgnorados++;
            continue;
        }

        $serieTitleBase = $parsed['serie'];
        $seasonNum      = $parsed['season'];
        $episodeNum     = $parsed['episode'];
        $serieYear      = $parsed['year'] ?? null;
        $hasLegendado   = $parsed['legendado'] ?? false;

        $serieTitle = $serieTitleBase;
        if ($hasLegendado) {
            $serieTitle = trim($serieTitle . ' [L]');
        }

        $episodeNameFull = preg_replace_callback(
            '/^(.*?)(\sS\d{1,2}\s*E\d{1,3})\s*$/i',
            static function (array $matches) use ($serieTitle) {
                return $serieTitle . $matches[2];
            },
            $episodeNameFull
        );
        $episodeNameFull = trim($episodeNameFull);

        $seriesId = getSeriesId($pdo, $serieTitle, $serieYear, $categoryId, $tvg_logo, $seriesCache, $insertSeriesStmt);

        $stream_source = json_encode([$url]);
        if (isset($streamCache[$stream_source])) {
            continue;
        }
        $dupEpisodeKey = $seriesId . ':' . $seasonNum . ':' . $episodeNum;
        if (isset($episodeCache[$dupEpisodeKey])) {
            continue;
        }

        $path = parse_url($url, PHP_URL_PATH);
        $extension = is_string($path) ? strtolower((string)pathinfo($path, PATHINFO_EXTENSION)) : '';
        $targetContainer = in_array($extension, SUPPORTED_TARGET_CONTAINERS, true) ? $extension : 'mp4';

        $insertStreamStmt->execute([
            ':category_id' => '[' . $categoryId . ']',
            ':name'        => $episodeNameFull,   // <-- episódio completo
            ':source'      => $stream_source,
            ':icon'        => $tvg_logo ?: '',
            ':target_container' => $targetContainer,
            ':direct_source' => $streamInfo['direct_source'],
            ':added'       => time(),
        ]);
        $streamId = (int)$pdo->lastInsertId();
        $streamCache[$stream_source] = true;
        $totalStreamsInseridos++;

        $insertEpisodeStmt->execute([
            ':season'    => $seasonNum,
            ':episode'   => $episodeNum,
            ':series_id' => $seriesId,
            ':stream_id' => $streamId,
        ]);
        $episodeCache[$dupEpisodeKey] = true;
        $totalEpisodiosInseridos++;
        $inseridosNoLote++;

        // commit em lote
        if ($inseridosNoLote >= TAMANHO_DO_LOTE) {
            $pdo->commit();
            echo "➡️  Lote de " . TAMANHO_DO_LOTE . " commitado. Totais: streams={$totalStreamsInseridos}, episódios={$totalEpisodiosInseridos}, ignorados={$totalIgnorados}\n";
            $pdo->beginTransaction();
            $inseridosNoLote = 0;
        }

        if ($totalEpisodiosInseridos > 0 && $totalEpisodiosInseridos % 5000 === 0) {
            echo "������ Progresso: {$totalStreamsInseridos} streams, {$totalEpisodiosInseridos} episódios, ignorados={$totalIgnorados}\n";
        }
    }

    if ($pdo->inTransaction()) {
        $pdo->commit();
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    die('❌ Erro durante a importação: ' . $e->getMessage());
}

echo "✅ Concluído! Streams inseridos: {$totalStreamsInseridos} | Episódios inseridos: {$totalEpisodiosInseridos} | Ignorados: {$totalIgnorados}\n";
echo "������ Episódios ignorados foram salvos em: $logIgnorados\n";
