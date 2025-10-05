<?php

declare(strict_types=1);

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

if (!function_exists('getStreamTypeByUrl')) {
    function getStreamTypeByUrl(string $url): array
    {
        if (stripos($url, '/movie/') !== false || stripos($url, '/series/') !== false) {
            return ['type' => 0, 'category_type' => ''];
        }

        return ['type' => 1, 'category_type' => 'live'];
    }
}

if (!function_exists('extractChannelEntries')) {
    /**
     * @return \Generator<int, array{url: string, tvg_logo: string, group_title: string, tvg_name: string}>
     */
    function extractChannelEntries(string $filePath): \Generator
    {
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new RuntimeException('Não foi possível ler o ficheiro M3U.');
        }

        $currentInfo = [
            'tvg_logo' => '',
            'group_title' => 'Canais',
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

                    $groupTitle = 'Canais';
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

                    $currentInfo['group_title'] = $groupTitle !== '' ? $groupTitle : 'Canais';
                    $currentInfo['tvg_name'] = $title !== '' ? $title : 'Sem Nome';
                    continue;
                }

                if (!filter_var($line, FILTER_VALIDATE_URL)) {
                    continue;
                }

                yield [
                    'url' => $line,
                    'tvg_logo' => $currentInfo['tvg_logo'] ?? '',
                    'group_title' => $currentInfo['group_title'] ?? 'Canais',
                    'tvg_name' => $currentInfo['tvg_name'] ?? 'Sem Nome',
                ];
            }
        } finally {
            fclose($handle);
        }
    }
}

if (!function_exists('buildProgressUpdate')) {
    function buildProgressUpdate(
        int $processedEntries,
        int $totalEntries,
        int $totalAdded,
        int $totalSkipped,
        int $totalErrors
    ): array {
        if (!defined('CHANNEL_PROGRESS_START')) {
            define('CHANNEL_PROGRESS_START', 10);
        }
        if (!defined('CHANNEL_PROGRESS_END')) {
            define('CHANNEL_PROGRESS_END', 95);
        }
        if (!defined('PROGRESS_MAX')) {
            define('PROGRESS_MAX', 99);
        }

        if ($totalEntries > 0) {
            $progress = CHANNEL_PROGRESS_START + (int) floor(($processedEntries / $totalEntries) * (CHANNEL_PROGRESS_END - CHANNEL_PROGRESS_START));
            $progress = min(PROGRESS_MAX, max(CHANNEL_PROGRESS_START, $progress));
        } else {
            $progress = PROGRESS_MAX;
        }

        $message = sprintf(
            'Processando canais (%s/%s)...',
            formatBrazilianNumber($processedEntries),
            formatBrazilianNumber($totalEntries)
        );

        return [
            'progress' => $progress,
            'message' => $message,
            'total_added' => $totalAdded,
            'total_skipped' => $totalSkipped,
            'total_errors' => $totalErrors,
        ];
    }
}
