<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

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
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao conectar ao banco administrador: ' . $e->getMessage()]);
    exit;
}

$jobIdParam = $_GET['job_id'] ?? $_POST['job_id'] ?? null;
$jobId = is_numeric($jobIdParam) ? (int) $jobIdParam : null;

if (!$jobId) {
    http_response_code(400);
    echo json_encode(['error' => 'Parâmetro job_id é obrigatório.']);
    exit;
}

$stmt = $adminPdo->prepare('SELECT * FROM clientes_import_jobs WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $jobId]);
$job = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$job) {
    http_response_code(404);
    echo json_encode(['error' => 'Job não encontrado.']);
    exit;
}

$response = [
    'job_id' => (int) $job['id'],
    'status' => $job['status'],
    'progress' => (int) $job['progress'],
    'message' => $job['message'] ?? '',
    'totals' => [
        'added' => $job['total_added'] !== null ? (int) $job['total_added'] : null,
        'skipped' => $job['total_skipped'] !== null ? (int) $job['total_skipped'] : null,
        'errors' => $job['total_errors'] !== null ? (int) $job['total_errors'] : null,
    ],
    'timestamps' => [
        'created_at' => $job['created_at'] ?? null,
        'updated_at' => $job['updated_at'] ?? null,
        'started_at' => $job['started_at'] ?? null,
        'finished_at' => $job['finished_at'] ?? null,
    ],
];

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
