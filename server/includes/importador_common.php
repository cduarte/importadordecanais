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

if (!function_exists('sanitizeMessage')) {
    function sanitizeMessage(string $message): string
    {
        $trimmed = trim($message);
        if (function_exists('mb_substr')) {
            return mb_substr($trimmed, 0, 2000, 'UTF-8');
        }

        return substr($trimmed, 0, 2000);
    }
}

if (!function_exists('formatBrazilianNumber')) {
    function formatBrazilianNumber(int $value): string
    {
        return number_format($value, 0, ',', '.');
    }
}

if (!function_exists('logInfo')) {
    function logInfo(string $message): void
    {
        $line = '[' . date('c') . '] ' . $message;
        if (PHP_SAPI === 'cli' && defined('STDOUT')) {
            fwrite(STDOUT, $line . PHP_EOL);
        } else {
            error_log($line);
        }
    }
}

if (!function_exists('sendJsonResponse')) {
    function sendJsonResponse(array $payload, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('triggerBackgroundWorker')) {
    function triggerBackgroundWorker(string $workerScript, int $jobId): void
    {
        if ($jobId <= 0) {
            return;
        }

        $scriptPath = realpath(__DIR__ . '/' . ltrim($workerScript, '/'));
        if ($scriptPath === false) {
            return;
        }

        $phpBinary = PHP_BINARY !== '' ? PHP_BINARY : 'php';
        $phpBinaryBasename = basename($phpBinary);
        $shouldSwitchToCli = PHP_SAPI !== 'cli' || ($phpBinaryBasename !== '' && stripos($phpBinaryBasename, 'php-fpm') !== false);

        if ($shouldSwitchToCli) {
            $cliCandidates = [];
            $envCli = getenv('IMPORTADOR_PHP_CLI');
            if (is_string($envCli) && $envCli !== '') {
                $cliCandidates[] = $envCli;
            }

            if (defined('PHP_BINDIR')) {
                $cliCandidates[] = rtrim(PHP_BINDIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'php';
            }

            $cliCandidates[] = 'php';

            foreach ($cliCandidates as $candidate) {
                if ($candidate === '') {
                    continue;
                }

                if ($candidate === 'php') {
                    $phpBinary = $candidate;
                    break;
                }

                if (@is_executable($candidate)) {
                    $phpBinary = $candidate;
                    break;
                }
            }
        }

        $command = escapeshellarg($phpBinary) . ' ' . escapeshellarg($scriptPath) . ' ' . $jobId;

        if (stripos(PHP_OS, 'WIN') === 0) {
            if (function_exists('popen') && function_exists('pclose')) {
                @pclose(@popen('start /B ' . $command, 'r'));
            }
            return;
        }

        $backgroundCommand = $command . ' > /dev/null 2>&1 &';

        if (function_exists('exec')) {
            @exec($backgroundCommand);
            return;
        }

        if (function_exists('shell_exec')) {
            @shell_exec($backgroundCommand);
        }
    }
}

if (!function_exists('updateJob')) {
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
}

if (!function_exists('fetchJob')) {
    function fetchJob(PDO $adminPdo, int $jobId): ?array
    {
        $stmt = $adminPdo->prepare('SELECT * FROM clientes_import_jobs WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $jobId]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        return $job ?: null;
    }
}
