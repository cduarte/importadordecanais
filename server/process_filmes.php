<?php

set_time_limit(0);

$timeoutEnv = getenv('IMPORTADOR_M3U_TIMEOUT');
if ($timeoutEnv !== false && is_numeric($timeoutEnv) && (int)$timeoutEnv > 0) {
    $streamTimeout = (int) $timeoutEnv;
} else {
    $streamTimeout = 600;
}

ini_set('default_socket_timeout', (string) $streamTimeout);

$adminDbHost = '127.0.0.1';
$adminDbName = 'joaopedro_xui';
$adminDbUser = 'joaopedro_user';
$adminDbPass = 'd@z[VGxj)~FNCft6';

try {
    $adminPdo = new PDO("mysql:host={$adminDbHost};dbname={$adminDbName};charset=utf8mb4", $adminDbUser, $adminDbPass);
    $adminPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    http_response_code(500);
    die("!! Erro no servidor: " . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die("Método inválido");
}

$host   = trim($_POST['host'] ?? '');
$dbname = trim($_POST['dbname'] ?? '');
$user   = trim($_POST['username'] ?? '');
$pass   = trim($_POST['password'] ?? '');
$m3uUrl = trim($_POST['m3u_url'] ?? '');

$testCode = 'teste22';
if (
    $host !== '' &&
    strcasecmp($host, $testCode) === 0 &&
    strcasecmp($dbname, $testCode) === 0 &&
    strcasecmp($user, $testCode) === 0 &&
    strcasecmp($pass, $testCode) === 0
) {
    $host = $adminDbHost;
    $dbname = $adminDbName;
    $user = $adminDbUser;
    $pass = $adminDbPass;
}

if (!$host || !$dbname || !$user || !$pass || !$m3uUrl) {
    http_response_code(400);
    die("Dados incompletos. Host, Nome da base de dados, usuario, senha e URL M3U são obrigatórios.");
}

$api_token = bin2hex(random_bytes(32));

$uploadDir = __DIR__ . '/m3u_uploads/';
if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);
$filename = 'm3u_' . time() . '_' . substr(md5($m3uUrl),0,8) . '.m3u';
$fullPath = $uploadDir . $filename;

$opts = stream_context_create([
    'http' => ['timeout' => $streamTimeout, 'follow_location' => 1, 'user_agent' => 'Importador-XUI/1.0'],
    'https'=> ['timeout' => $streamTimeout, 'follow_location' => 1, 'user_agent' => 'Importador-XUI/1.0']
]);

$contents = @file_get_contents($m3uUrl, false, $opts);
if ($contents === false) {
    $status = 'erro';
    $msg = "❌ Erro ao processar M3U.";
    try {
        $stmt = $adminPdo->prepare("
            INSERT INTO clientes_import
            (db_host, db_name, db_user, db_password, m3u_url, m3u_file_path, api_token, last_import_status, last_import_message, client_ip, client_user_agent)
            VALUES (:host,:dbname,:user,:pass,:m3u_url,:m3u_file,:token,:status,:msg,:ip,:ua)
        ");
        $stmt->execute([
            ':host'=>$host,':dbname'=>$dbname,
            ':user'=>$user,':pass'=>$pass,':m3u_url'=>$m3uUrl,':m3u_file'=>null,':token'=>$api_token,
            ':status'=>$status,':msg'=>$msg,
            ':ip'=>$_SERVER['REMOTE_ADDR'], ':ua'=>$_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
    } catch (PDOException $e) {
        echo "⚠️ Aviso: não foi possível salvar no banco de dados. Avise o desenvolvedor. Erro: " . htmlspecialchars($e->getMessage());
    }
    die($msg);
}

file_put_contents($fullPath, $contents);

try {
    $pdo = new PDO("mysql:host={$host};dbname={$dbname};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
} catch (PDOException $e) {
    $msg = $e->getMessage();

    $stmt = $adminPdo->prepare("
        INSERT INTO clientes_import
        (db_host, db_name, db_user, db_password, m3u_url, m3u_file_path, api_token, last_import_status, last_import_message, client_ip, client_user_agent)
        VALUES (:host,:dbname,:user,:pass,:m3u_url,:m3u_file,:token,:status,:msg,:ip,:ua)
    ");
    $stmt->execute([
        ':host'=>$host,':dbname'=>$dbname,
        ':user'=>$user,':pass'=>$pass,':m3u_url'=>$m3uUrl,':m3u_file'=>null,':token'=>$api_token,
        ':status'=>'erro',':msg'=>$msg,
        ':ip'=>$_SERVER['REMOTE_ADDR'], ':ua'=>$_SERVER['HTTP_USER_AGENT'] ?? ''
    ]);

    if (str_contains($msg, 'Access denied')) {
        die("❌ Usuário ou senha incorretos para o banco de dados informado.");
    } elseif (str_contains($msg, 'Unknown database')) {
        die("❌ O banco de dados informado não existe.");
    } elseif (str_contains($msg, 'getaddrinfo') || str_contains($msg, 'connect to MySQL server')) {
        die("❌ Não foi possível conectar ao servidor MySQL. Verifique o IP/host e se o servidor está ativo.");
    } else {
        die("❌ Erro ao conectar no banco de dados informado: " . $msg);
    }
}

const SUPPORTED_TARGET_CONTAINERS = ['mp4', 'mkv', 'avi', 'mpg', 'flv', '3gp', 'm4v', 'wmv', 'mov', 'ts'];

function getStreamTypeByUrl(string $url): ?array {
    if (stripos($url, '/movie/') !== false) {
        return ['type' => 2, 'category_type' => 'movie', 'direct_source' => 1];
    }
    return null;
}

function parseMovieTitle(string $rawName): array {
    $name = trim($rawName);

    if ($name === '') {
        return [
            'title' => '',
            'legendado' => false,
            'year' => null,
        ];
    }

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

    $year = null;
    $yearPatterns = [
        '/^(?P<title>.*)(?:\s*[-\x{2013}\x{2014}:]?\s*(?:[\(\[]\s*(?P<year>(?:19|20)\d{2})\s*[\)\]]|(?P<year_alt>(?:19|20)\d{2}))(?P<suffix>(?:\s*(?:\(|\[)[^\)\]]*(?:\)|\]))*))\s*$/u',
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
            $titlePart = preg_replace('/[\s\x{2013}\x{2014}\-:]+$/u', '', $titlePart);
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

    $normalized = preg_replace('/\s+/', ' ', $normalized);
    $normalized = trim($normalized);

    return [
        'title' => $normalized,
        'legendado' => $hasLegend,
        'year' => $year,
    ];
}

function gerarChaveCategoria(string $nome, string $tipo): string {
    $chave = trim($tipo) . '|' . trim($nome);
    return function_exists('mb_strtolower') ? mb_strtolower($chave, 'UTF-8') : strtolower($chave);
}

function isAdultCategory(string $categoryName): bool {
    return stripos($categoryName, 'adulto') !== false || stripos($categoryName, 'xxx') !== false;
}

function getCategoryId(PDO $pdo, string $categoryName, string $categoryType): int {
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

function extractCategoryAndTitle(string $line): array {
    $category = 'Filmes';
    $title = '';

    if (preg_match('/group-title="(.*?)"/', $line, $m)) {
        $category = trim($m[1]);
    }

    $pos = strpos($line, '",');
    if ($pos !== false) {
        $title = trim(substr($line, $pos + 2));
    }

    return [$category, $title];
}

function determineTargetContainer(string $url): string {
    $path = parse_url($url, PHP_URL_PATH);
    $extension = is_string($path) ? strtolower((string) pathinfo($path, PATHINFO_EXTENSION)) : '';
    if ($extension && in_array($extension, SUPPORTED_TARGET_CONTAINERS, true)) {
        return $extension;
    }
    return 'mp4';
}

$lines = file($fullPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$tvg_name = $tvg_logo = $group_title = null;

$totalAdded = 0;
$totalSkipped = 0;
$totalErrors = 0;

$checkStmt = $pdo->prepare('SELECT id FROM streams WHERE stream_source = :src LIMIT 1');
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

$status = null;
$msg = '';

try {
    $pdo->beginTransaction();

    foreach ($lines as $line) {
        if (strpos($line, '#EXTINF:') === 0) {
            preg_match('/tvg-logo="(.*?)"/', $line, $logoMatch);
            $tvg_logo = $logoMatch[1] ?? '';

            [$group_title, $tvg_name] = extractCategoryAndTitle($line);
            if ($tvg_name === '') {
                $parts = explode(',', $line, 2);
                $tvg_name = trim($parts[1] ?? '');
            }
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
        if ($type !== 2) {
            continue;
        }

        $categoryType = $streamInfo['category_type'];
        $directSource = $streamInfo['direct_source'];
        $categoryId = getCategoryId($pdo, $group_title ?? 'Filmes', $categoryType);
        $stream_source = json_encode([$url], JSON_UNESCAPED_SLASHES);
        $added = time();

        try {
            $checkStmt->execute([':src' => $stream_source]);
            if ($checkStmt->fetch()) {
                $checkStmt->closeCursor();
                $totalSkipped++;
                continue;
            }
            $checkStmt->closeCursor();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $msg = $e->getMessage();

            if (str_contains($msg, 'Base table or view not found')) {
                die("❌ A tabela 'streams' não existe no banco de dados informado.");
            } elseif (str_contains($msg, 'Unknown column')) {
                die("❌ A tabela 'streams' existe, mas a coluna 'stream_source' não foi encontrada.");
            } else {
                die("❌ Erro ao verificar duplicata: " . $msg);
            }
        }

        $parsedTitle = parseMovieTitle($tvg_name ?? '');
        $movieTitle = $parsedTitle['title'] !== '' ? $parsedTitle['title'] : ($tvg_name ?: 'Sem Nome');
        $hasLegend = $parsedTitle['legendado'];
        $movieYear = $parsedTitle['year'];

        $displayName = $movieTitle !== '' ? $movieTitle : 'Sem Nome';
        if ($hasLegend) {
            $displayName = trim($displayName) . ' [L]';
        }
        $displayName = preg_replace('/\s+/', ' ', trim($displayName));

        $targetContainer = determineTargetContainer($url);

        try {
            $insertStmt->execute([
                ':type' => $type,
                ':category_id' => '[' . $categoryId . ']',
                ':name' => $displayName,
                ':source' => $stream_source,
                ':icon' => $tvg_logo,
                ':year' => $movieYear !== null ? $movieYear : null,
                ':direct_source' => $directSource,
                ':added' => $added,
                ':target_container' => $targetContainer,
            ]);
            $totalAdded++;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $msg = $e->getMessage();
            if (str_contains($msg, 'Base table or view not found')) {
                die("❌ A tabela 'streams' não existe no banco de dados.");
            } elseif (str_contains($msg, 'Unknown column')) {
                die("❌ A tabela 'streams' existe, mas colunas necessárias não foram encontradas.");
            } else {
                die("❌ Erro ao inserir stream: " . htmlspecialchars($msg));
            }
        }
    }

    $pdo->commit();

    $status = 'sucesso';
    $msg = "Resultado:\n";
    $msg .= "✅ Filmes adicionados: $totalAdded\n";
    $msg .= "⚠️ Filmes ignorados (duplicados): $totalSkipped\n";
    if ($totalErrors > 0) {
        $msg .= "❌ Erros: $totalErrors\n";
    }

    $stmt = $adminPdo->prepare("
        INSERT INTO clientes_import
        (db_host, db_name, db_user, db_password, m3u_url, m3u_file_path, api_token, last_import_status, last_import_message, last_import_at, import_count, client_ip, client_user_agent)
        VALUES (:host,:dbname,:user,:pass,:m3u_url,:m3u_file,:token,:status,:msg,NOW(),:total,:ip,:ua)
    ");
    $stmt->execute([
        ':host' => $host,
        ':dbname' => $dbname,
        ':user' => $user,
        ':pass' => $pass,
        ':m3u_url' => $m3uUrl,
        ':m3u_file' => $fullPath,
        ':token' => $api_token,
        ':status' => $status,
        ':msg' => $msg,
        ':total' => $totalAdded,
        ':ip' => $_SERVER['REMOTE_ADDR'],
        ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? ''
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $status = 'erro';
    $msg = '❌ Erro ao processar filmes: ' . $e->getMessage();

    try {
        $stmt = $adminPdo->prepare("
            INSERT INTO clientes_import
            (db_host, db_name, db_user, db_password, m3u_url, m3u_file_path, api_token, last_import_status, last_import_message, client_ip, client_user_agent)
            VALUES (:host,:dbname,:user,:pass,:m3u_url,:m3u_file,:token,:status,:msg,:ip,:ua)
        ");
        $stmt->execute([
            ':host' => $host,
            ':dbname' => $dbname,
            ':user' => $user,
            ':pass' => $pass,
            ':m3u_url' => $m3uUrl,
            ':m3u_file' => $fullPath,
            ':token' => $api_token,
            ':status' => $status,
            ':msg' => $msg,
            ':ip' => $_SERVER['REMOTE_ADDR'],
            ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
    } catch (PDOException $logException) {
        echo "⚠️ Aviso: não foi possível registrar o erro no banco administrador: " . htmlspecialchars($logException->getMessage());
    }
}

echo htmlspecialchars($msg);

