<?php
require __DIR__ . '/../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->load();

// --------------------------------------------------
// CONFIGURAÇÕES DO BANCO DE DADOS
// --------------------------------------------------
$host = $_ENV['DB_HOST'];
$dbname = $_ENV['DB_NAME'];
$username = $_ENV['DB_USER'];
$password = $_ENV['DB_PASS'];

// Quantidade de streams por transação para agilizar o processo
const TAMANHO_DO_LOTE = 1000;
const SUPPORTED_TARGET_CONTAINERS = ['mp4', 'mkv', 'avi', 'mpg', 'flv', '3gp', 'm4v', 'wmv', 'mov', 'ts'];
$m3uFile = "../files/lista_movies.m3u"; // Caminho do arquivo M3U

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die('❌ Erro ao conectar no banco: ' . $e->getMessage());
}

// --------------------------------------------------
// FUNÇÃO PARA DEFINIR O TIPO COM BASE NA URL
// --------------------------------------------------
function getStreamTypeByUrl(string $url): ?array
{
    if (stripos($url, '/movie/') !== false) {
        return ['type' => 2, 'category_type' => 'movie', 'direct_source' => 1];
    }

    return null; // Ignora séries e outros
}

// --------------------------------------------------
// PARSE DO TÍTULO PARA EXTRAIR ANO E LEGENDADO
// --------------------------------------------------
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

    // Normaliza indicações de legendado para um único [L]
    $normalized = preg_replace('/(?:\s*[-\x{2013}\x{2014}]?\s*)?(?:\(|\[)?\s*(legendado|leg)\b\s*(?:\]|\))?/i', ' [L] ', $name);
    $legendPattern = '/\s*(\(|\[)\s*(leg|l)\s*(\]|\))\s*/i';
    $normalized = preg_replace($legendPattern, ' [L] ', $normalized);
    $normalized = preg_replace('/\s+/', ' ', $normalized);
    $normalized = trim($normalized);

    $hasLegend = stripos($normalized, '[L]') !== false;
    if ($hasLegend) {
        $normalized = preg_replace('/\s*\[L\]\s*/i', ' ', $normalized);
        $normalized = preg_replace('/\s+/', ' ', $normalized);
        $normalized = trim($normalized);
    }

    // Extrai ano (ex.: "Título - (2024)") e remove do título, preservando sufixos como (4K)
    $year = null;
    $yearPatterns = [
        '/^(?P<title>.*)(?:\s*[-\x{2013}\x{2014}:]?\s*(?:[\(\[]\s*(?P<year>(?:19|20)\d{2})\s*[\)\]]|(?P<year_alt>(?:19|20)\d{2})))(?P<suffix>(?:\s*(?:\(|\[)[^\)\]]*(?:\)|\]))*)\s*$/u',
        '/^(?P<title>.*\S)(?:\s*[-\x{2013}\x{2014}:]?\s*(?P<year>(?:19|20)\d{2}))\s*$/u',
        '/^(?P<year>(?:19|20)\d{2})\s*$/u',
    ];

    foreach ($yearPatterns as $pattern) {
        if (!preg_match($pattern, $normalized, $matches)) {
            continue;
        }

        $yearValue = null;
        if (isset($matches['year']) && $matches['year'] !== '') {
            $yearValue = (int) $matches['year'];
        } elseif (isset($matches['year_alt']) && $matches['year_alt'] !== '') {
            $yearValue = (int) $matches['year_alt'];
        }

        if ($yearValue === null || $yearValue <= 0) {
            continue;
        }

        $titlePart = $matches['title'] ?? '';
        $suffixPart = $matches['suffix'] ?? '';

        if ($titlePart !== '') {
            $titlePart = rtrim($titlePart);
            $titlePart = preg_replace('/[\s\x{2013}\x{2014}\-:]+$/u', '', $titlePart);
            $titlePart = trim($titlePart);
        }

        $combinedTitle = $titlePart;
        if ($suffixPart !== '') {
            $suffixPart = trim($suffixPart);
            if ($suffixPart !== '') {
                $combinedTitle = $combinedTitle !== ''
                    ? ($combinedTitle . ' ' . $suffixPart)
                    : $suffixPart;
            }
        }

        $normalized = $combinedTitle;
        $year = $yearValue;
        break;
    }

    $normalized = preg_replace('/\s+/', ' ', $normalized);
    $normalized = trim($normalized);

    return [
        'title' => $normalized,
        'legendado' => $hasLegend,
        'year' => $year,
    ];
}

// --------------------------------------------------
// ROTINAS DE CACHE E CATEGORIA
// --------------------------------------------------
function gerarChaveCategoria(string $nome, string $tipo): string
{
    $chave = trim($tipo) . '|' . trim($nome);

    return function_exists('mb_strtolower')
        ? mb_strtolower($chave, 'UTF-8')
        : strtolower($chave);
}

function isAdultCategory(string $categoryName): bool
{
    return stripos($categoryName, 'adulto') !== false
        || stripos($categoryName, 'xxx') !== false;
}

function getCategoryId(PDO $pdo, string $categoryName, string $categoryType, array &$categoryCache, \PDOStatement $insertCategoryStmt): int
{
    $categoryName = trim($categoryName);
    $cacheKey = gerarChaveCategoria($categoryName, $categoryType);

    if (isset($categoryCache[$cacheKey])) {
        return $categoryCache[$cacheKey];
    }

    $isAdult = isAdultCategory($categoryName);

    $insertCategoryStmt->execute([
        ':type' => $categoryType,
        ':name' => $categoryName,
        ':cat_order' => $isAdult ? 9999 : 99,
        ':is_adult' => $isAdult ? 1 : 0,
    ]);

    $categoryId = (int) $pdo->lastInsertId();
    $categoryCache[$cacheKey] = $categoryId;

    return $categoryId;
}

/**
 * Extrai categoria e titulo do filme a partir da linha #EXTINF
 */
function extractCategoryAndTitle(string $line): array {
    $category = 'Filmes';
    $title2 = '';

    if (preg_match('/group-title="(.*?)"/', $line, $m)) {
        $category = trim($m[1]);
    }

    // pega tudo depois de '",' (fecha aspas do group-title + vírgula)
    $pos = strpos($line, '",');
    if ($pos !== false) {
        $title2 = trim(substr($line, $pos + 2));
    }

    return [$category, $title2];
}

// --------------------------------------------------
// IMPORTAÇÃO DO ARQUIVO M3U
// --------------------------------------------------
if (!file_exists($m3uFile)) {
    die('❌ Arquivo M3U não encontrado!');
}

$lines = file($m3uFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

// Pré-carrega categorias já existentes para evitar SELECTs repetidos
$categoryCache = [];
$categoryQuery = $pdo->query('SELECT id, category_name, category_type FROM streams_categories');
while ($row = $categoryQuery->fetch()) {
    $cacheKey = gerarChaveCategoria($row['category_name'], $row['category_type'] ?? '');
    $categoryCache[$cacheKey] = (int) $row['id'];
}

// Pré-carrega streams existentes para evitar duplicidades
$streamCache = [];
$streamQuery = $pdo->query('SELECT stream_source FROM streams');
while ($row = $streamQuery->fetch()) {
    $streamCache[$row['stream_source']] = true;
}

$tvg_name = $tvg_logo = $group_title = null;
$totalInseridos = 0;
$inseridosNoLote = 0;

$insertCategoryStmt = $pdo->prepare('
    INSERT INTO streams_categories
    (category_type, category_name, parent_id, cat_order, is_adult)
    VALUES (:type, :name, 0, :cat_order, :is_adult)
');

$insertStreamStmt = $pdo->prepare('
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
    $pdo->beginTransaction();

    foreach ($lines as $line) {
        /*if (strpos($line, '#EXTINF:') === 0) {
            preg_match('/tvg-name="(.*?)"/', $line, $nameMatch);
            preg_match('/tvg-logo="(.*?)"/', $line, $logoMatch);
            preg_match('/group-title="(.*?)"/', $line, $groupMatch);

            $tvg_name = $nameMatch[1] ?? 'Sem Nome';
            $tvg_logo = $logoMatch[1] ?? '';
            $group_title = $groupMatch[1] ?? 'Outros';

            $parts = explode(',', $line, 2);
            $tvg_name = trim($parts[1] ?? $tvg_name);

            continue;
        }*/
        
        if (strpos($line, '#EXTINF:') === 0) {
            preg_match('/tvg-logo="(.*?)"/', $line, $logoMatch);
            $tvg_logo = $logoMatch[1] ?? '';

            [$group_title, $tvg_name] = extractCategoryAndTitle($line);
            continue;
        }

        if (!filter_var($line, FILTER_VALIDATE_URL)) {
            continue;
        }

        $url = trim($line);
        $streamInfo = getStreamTypeByUrl($url);

        if ($streamInfo === null) {
            continue;
        }

        $type = $streamInfo['type'];
        $categoryType = $streamInfo['category_type'];
        $directSource = $streamInfo['direct_source'];
        $group_title = trim($group_title ?? '');

        if ($group_title === '') {
            continue;
        }

        $categoryId = getCategoryId($pdo, $group_title, $categoryType, $categoryCache, $insertCategoryStmt);

        $rawTitle = $tvg_name ?? 'Sem Nome';
        $parsedTitle = parseMovieTitle($rawTitle);
        $movieTitle = $parsedTitle['title'] !== '' ? $parsedTitle['title'] : trim($rawTitle);
        $movieYear = $parsedTitle['year'];
        $hasLegendado = $parsedTitle['legendado'];

        $streamDisplayName = $movieTitle !== '' ? $movieTitle : 'Sem Nome';
        if ($hasLegendado) {
            $streamDisplayName = trim($streamDisplayName) . ' [L]';
        }
        $streamDisplayName = preg_replace('/\s+/', ' ', trim($streamDisplayName));

        $stream_source = json_encode([$url]);
        $added = time();

        if (isset($streamCache[$stream_source])) {
            continue;
        }

        $path = parse_url($url, PHP_URL_PATH);
        $extension = is_string($path) ? strtolower((string)pathinfo($path, PATHINFO_EXTENSION)) : '';
        $targetContainer = in_array($extension, SUPPORTED_TARGET_CONTAINERS, true) ? $extension : 'mp4';

        $insertStreamStmt->execute([
            ':type' => $type,
            ':category_id' => '[' . $categoryId . ']',
            ':name' => $streamDisplayName,
            ':source' => $stream_source,
            ':icon' => $tvg_logo,
            ':direct_source' => $directSource,
            ':target_container' => $targetContainer,
            ':added' => $added,
            ':year' => $movieYear !== null ? $movieYear : null,
        ]);

        $streamCache[$stream_source] = true;
        $totalInseridos++;
        $inseridosNoLote++;

        if ($inseridosNoLote >= TAMANHO_DO_LOTE) {
            $pdo->commit();
            $pdo->beginTransaction();
            $inseridosNoLote = 0;
        }

        if ($totalInseridos > 0 && $totalInseridos % 500 === 0) {
            echo "➡️  $totalInseridos streams adicionados até agora...\n";
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

echo "✅ Importação concluída com sucesso! Foram adicionados $totalInseridos streams.\n";
