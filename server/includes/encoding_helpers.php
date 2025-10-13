<?php

declare(strict_types=1);

if (!function_exists('importador_configure_utf8_environment')) {
    function importador_configure_utf8_environment(): void
    {
        static $configured = false;
        if ($configured) {
            return;
        }
        $configured = true;

        ini_set('default_charset', 'UTF-8');

        if (function_exists('mb_internal_encoding')) {
            mb_internal_encoding('UTF-8');
        }

        if (function_exists('mb_http_output')) {
            mb_http_output('UTF-8');
        }
    }
}

if (!function_exists('importador_configure_pdo_utf8')) {
    function importador_configure_pdo_utf8(PDO $pdo): void
    {
        try {
            $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
        } catch (Throwable $exception) {
            // Ignore failures so we do not break execution when the command is not supported.
        }
    }
}
