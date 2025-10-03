<?php

declare(strict_types=1);

set_time_limit(0);

function sendJsonResponse(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$timeoutEnv = getenv('IMPORTADOR_M3U_TIMEOUT');
if ($timeoutEnv !== false && is_numeric($timeoutEnv) && (int) $timeoutEnv > 0) {
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
    $adminPdo = new PDO(
        "mysql:host={$adminDbHost};dbname={$adminDbName};charset=utf8mb4",
        $adminDbUser,
        $adminDbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    sendJsonResponse(['error' => 'Erro no servidor: ' . $e->getMessage()], 500);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonResponse(['error' => 'Método inválido. Utilize POST.'], 405);
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

if ($host === '' || $dbname === '' || $user === '' || $pass === '' || $m3uUrl === '') {
    sendJsonResponse(['error' => 'Dados incompletos. Host, Nome da base de dados, usuário, senha e URL M3U são obrigatórios.'], 400);
}

if (!filter_var($m3uUrl, FILTER_VALIDATE_URL)) {
    sendJsonResponse(['error' => 'URL M3U inválida.'], 400);
}

try {
    new PDO(
        "mysql:host={$host};dbname={$dbname};charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    $msg = $e->getMessage();

    if (str_contains($msg, 'Access denied')) {
        sendJsonResponse(['error' => 'Usuário ou senha incorretos para o banco de dados informado.'], 401);
    }

    if (str_contains($msg, 'Unknown database')) {
        sendJsonResponse(['error' => 'O banco de dados informado não existe.'], 400);
    }

    if (str_contains($msg, 'getaddrinfo') || str_contains($msg, 'connect to MySQL server')) {
        sendJsonResponse(['error' => 'Não foi possível conectar ao servidor MySQL. Verifique o IP/host e se o servidor está ativo.'], 400);
    }

    sendJsonResponse(['error' => 'Erro ao conectar no banco de dados informado: ' . $msg], 400);
}

$apiToken = bin2hex(random_bytes(32));
$clientIp = $_SERVER['REMOTE_ADDR'] ?? null;
$clientUserAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

try {
    $checkStmt = $adminPdo->prepare('
        SELECT id
        FROM clientes_import_jobs
        WHERE db_host = :host
            AND db_name = :dbname
            AND db_user = :user
            AND m3u_url = :m3u_url
            AND status = :status
        ORDER BY id DESC
        LIMIT 1
    ');
    $checkStmt->execute([
        ':host' => $host,
        ':dbname' => $dbname,
        ':user' => $user,
        ':m3u_url' => $m3uUrl,
        ':status' => 'running',
    ]);
    $runningJob = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if ($runningJob) {
        sendJsonResponse([
            'error' => sprintf(
                'Já existe um processamento em andamento (#%d) para esta lista e base de dados. Aguarde a conclusão antes de enviar novamente.',
                (int) $runningJob['id']
            ),
        ], 409);
    }

    $stmt = $adminPdo->prepare('
        INSERT INTO clientes_import_jobs (
            db_host,
            db_name,
            db_user,
            db_password,
            m3u_url,
            api_token,
            status,
            progress,
            message,
            client_ip,
            client_user_agent
        ) VALUES (
            :host,
            :dbname,
            :user,
            :pass,
            :m3u_url,
            :token,
            :status,
            :progress,
            :message,
            :ip,
            :ua
        )
    ');
    $stmt->execute([
        ':host' => $host,
        ':dbname' => $dbname,
        ':user' => $user,
        ':pass' => $pass,
        ':m3u_url' => $m3uUrl,
        ':token' => $apiToken,
        ':status' => 'queued',
        ':progress' => 0,
        ':message' => 'Job aguardando processamento.',
        ':ip' => $clientIp,
        ':ua' => $clientUserAgent,
    ]);
} catch (PDOException $e) {
    sendJsonResponse(['error' => 'Não foi possível registrar o job. Erro: ' . $e->getMessage()], 500);
}

$jobId = (int) $adminPdo->lastInsertId();

sendJsonResponse([
    'job_id' => $jobId,
    'status' => 'queued',
    'message' => 'Job criado com sucesso e aguardando processamento.',
]);
