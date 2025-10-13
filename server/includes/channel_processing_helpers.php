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

if (!function_exists('streamHelperLowercase')) {
    function streamHelperLowercase(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }
}

if (!function_exists('streamHelperNormalizeText')) {
    function streamHelperNormalizeText(string $value): string
    {
        $normalized = streamHelperLowercase(trim($value));

        if ($normalized === '') {
            return '';
        }

        $map = [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
            'é' => 'e', 'ê' => 'e', 'è' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n', 'ý' => 'y', 'ß' => 'ss',
        ];

        $normalized = strtr($normalized, $map);
        $normalized = preg_replace('/[\x{0300}-\x{036f}]/u', '', $normalized) ?? $normalized;

        return $normalized;
    }
}

if (!function_exists('streamHelperTokenize')) {
    /**
     * @return array<int, string>
     */
    function streamHelperTokenize(string $value): array
    {
        if ($value === '') {
            return [];
        }

        $tokens = preg_split('/[\s\|\/>»:\-]+/u', $value) ?: [];
        $filtered = [];
        foreach ($tokens as $token) {
            $token = trim($token);
            if ($token !== '') {
                $filtered[] = $token;
            }
        }

        return $filtered;
    }
}

if (!function_exists('importador_normalize_playlist_text')) {
    function importador_normalize_playlist_text(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        $value = (string) $value;
        if ($value === '') {
            return '';
        }

        $hasIconv = function_exists('iconv');
        $needsValidation = function_exists('mb_check_encoding');
        $hasConvert = function_exists('mb_convert_encoding');

        $looksMisencoded = strpos($value, 'Ã') !== false || strpos($value, 'Â') !== false;

        if ($looksMisencoded) {
            if ($hasConvert) {
                $converted = @mb_convert_encoding($value, 'ISO-8859-1', 'UTF-8');
                if (is_string($converted) && $converted !== '') {
                    $value = $converted;
                    $looksMisencoded = false;
                }
            } elseif ($hasIconv) {
                $converted = @iconv('UTF-8', 'ISO-8859-1//IGNORE', $value);
                if ($converted !== false && $converted !== '') {
                    $value = $converted;
                    $looksMisencoded = false;
                }
            } elseif (function_exists('utf8_decode')) {
                $value = utf8_decode($value);
                $looksMisencoded = false;
            }
        }

        if ($needsValidation && mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        $encoding = false;
        if (function_exists('mb_detect_encoding')) {
            $encoding = mb_detect_encoding($value, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true);
        }

        if ($encoding === false) {
            $encoding = 'ISO-8859-1';
        }

        if ($hasConvert) {
            $converted = @mb_convert_encoding($value, 'UTF-8', $encoding);
            if (is_string($converted) && $converted !== '') {
                $value = $converted;
            }
        } elseif ($hasIconv) {
            $converted = @iconv($encoding, 'UTF-8//IGNORE', $value);
            if ($converted !== false) {
                $value = $converted;
            }
        }

        if ($needsValidation && !mb_check_encoding($value, 'UTF-8') && $hasIconv) {
            $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
            if ($converted !== false) {
                $value = $converted;
            }
        }

        return $value;
    }
}

if (!function_exists('getStreamTypeByUrl')) {
    function getStreamTypeByUrl(string $url, ?string $title = null, ?string $groupTitle = null): array
    {
        $rawUrl = trim($url);
        if ($rawUrl === '') {
            return ['type' => 0, 'category_type' => ''];
        }

        $title = $title ?? '';
        $groupTitle = $groupTitle ?? '';

        $cleanUrl = $rawUrl;
        $hashPos = strpos($cleanUrl, '#');
        if ($hashPos !== false) {
            $cleanUrl = substr($cleanUrl, 0, $hashPos);
        }
        $queryPos = strpos($cleanUrl, '?');
        if ($queryPos !== false) {
            $cleanUrl = substr($cleanUrl, 0, $queryPos);
        }

        $path = parse_url($cleanUrl, PHP_URL_PATH);
        $pathLower = is_string($path) ? streamHelperLowercase($path) : '';
        $extension = '';
        if (is_string($path)) {
            $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        }

        $liveExtensions = ['m3u8', 'ts'];
        $vodExtensions = ['mp4', 'mkv', 'avi', 'mov', 'mpg', 'mpeg', 'wmv', 'flv'];

        // Deterministic rules by URL path.
        if ($pathLower !== '') {
            if (strpos($pathLower, '/live/') !== false) {
                return ['type' => 1, 'category_type' => 'live'];
            }

            if (preg_match('#/(series|serie)/#', $pathLower) === 1) {
                return ['type' => 0, 'category_type' => 'series'];
            }

            if (preg_match('#/(movie|vod)/#', $pathLower) === 1) {
                return ['type' => 0, 'category_type' => 'movie'];
            }
        }

        $scores = [
            'live' => 0,
            'movie' => 0,
            'series' => 0,
        ];

        $normalizedTitle = streamHelperNormalizeText($title);
        $normalizedGroup = streamHelperNormalizeText($groupTitle);

        $sxxExxDetected = false;
        if ($normalizedTitle !== '') {
            if (preg_match('/\bS(\d{1,2})E(\d{1,3})\b/i', $title)) {
                $scores['series'] += 8;
                $sxxExxDetected = true;
            }

            if (preg_match('/\b(?:temporada|epis[oó]dio|cap[íi]tulo)\s*\d+/iu', $title)) {
                $scores['series'] += 3;
            }

            if (preg_match('/\bT\s*(\d{1,2})\b/iu', $title) || preg_match('/\bT(\d{1,2})\b/iu', $title)) {
                $scores['series'] += 2;
            }

            if (!$sxxExxDetected && preg_match('/\((19|20)\d{2}\)/', $title)) {
                $scores['movie'] += 2;
            }
        }

        $groupTokens = streamHelperTokenize($normalizedGroup);
        $titleTokens = streamHelperTokenize($normalizedTitle);

        $seriesLabels = [
            'serie',
            'series',
            'novela',
            'novelas',
            'temporada',
            'temporadas',
            'episodio',
            'episodios',
            'capitulo',
            'capitulos',
        ];
        $movieLabels = ['filme', 'filmes', 'movie', 'movies', 'cinema', 'vod'];
        $liveStrongLabels = ['canal', 'canais', 'news', 'sport', 'sports', 'esporte', 'esportes', '24h'];
        $liveWeakLabels = ['hd', 'fhd', 'uhd', '4k'];

        foreach ($groupTokens as $token) {
            if (in_array($token, $seriesLabels, true)) {
                $scores['series'] += 6;
            }

            if (in_array($token, $movieLabels, true)) {
                $scores['movie'] += 6;
            }

            if (in_array($token, $liveStrongLabels, true)) {
                $scores['live'] += 4;
            }

            if (in_array($token, $liveWeakLabels, true)) {
                $scores['live'] += 2;
            }
        }

        if ($normalizedGroup !== '') {
            if (strpos($normalizedGroup, '24h') !== false || strpos($normalizedGroup, '24 horas') !== false) {
                $scores['live'] += 4;
            }
        }

        foreach ($titleTokens as $token) {
            if (in_array($token, $liveStrongLabels, true)) {
                $scores['live'] += 2;
            }

            if (in_array($token, $liveWeakLabels, true)) {
                $scores['live'] += 1;
            }
        }

        if ($normalizedTitle !== '') {
            if (strpos($normalizedTitle, '24h') !== false || strpos($normalizedTitle, '24 horas') !== false) {
                $scores['live'] += 2;
            }
        }

        if ($extension !== '') {
            if (in_array($extension, $liveExtensions, true)) {
                $scores['live'] += 2;
            } elseif (in_array($extension, $vodExtensions, true)) {
                $scores['movie'] += 2;
                $scores['series'] += 1;
            }
        }

        $maxScore = max($scores);
        $topCategories = [];
        foreach ($scores as $category => $score) {
            if ($score === $maxScore) {
                $topCategories[] = $category;
            }
        }

        if ($maxScore <= 0) {
            return ['type' => 1, 'category_type' => 'live'];
        }

        if (count($topCategories) === 1) {
            return mapStreamClassificationToResult($topCategories[0]);
        }

        if ($sxxExxDetected && in_array('series', $topCategories, true)) {
            return mapStreamClassificationToResult('series');
        }

        if (in_array('live', $topCategories, true) && in_array($extension, $liveExtensions, true)) {
            return mapStreamClassificationToResult('live');
        }

        if (in_array('movie', $topCategories, true) && in_array($extension, $vodExtensions, true)) {
            return mapStreamClassificationToResult('movie');
        }

        if (in_array('series', $topCategories, true)) {
            return mapStreamClassificationToResult('series');
        }

        if (in_array('movie', $topCategories, true)) {
            return mapStreamClassificationToResult('movie');
        }

        return mapStreamClassificationToResult('live');
    }
}

if (!function_exists('mapStreamClassificationToResult')) {
    function mapStreamClassificationToResult(string $classification): array
    {
        switch ($classification) {
            case 'series':
                return ['type' => 0, 'category_type' => 'series'];
            case 'movie':
                return ['type' => 0, 'category_type' => 'movie'];
            case 'live':
            default:
                return ['type' => 1, 'category_type' => 'live'];
        }
    }
}

if (!function_exists('classifyMovieImportEntry')) {
    /**
     * @param array{url?: mixed, tvg_name?: mixed, group_title?: mixed}|array<string, mixed> $entry
     */
    function classifyMovieImportEntry(array $entry): ?array
    {
        $url = isset($entry['url']) ? trim((string) $entry['url']) : '';
        if ($url === '') {
            return null;
        }

        $title = null;
        if (isset($entry['tvg_name'])) {
            $title = (string) $entry['tvg_name'];
        } elseif (isset($entry['title'])) {
            $title = (string) $entry['title'];
        }

        $groupTitle = isset($entry['group_title']) ? (string) $entry['group_title'] : null;

        $classification = getStreamTypeByUrl($url, $title, $groupTitle);
        if (($classification['category_type'] ?? '') !== 'movie') {
            return null;
        }

        return [
            'type' => 2,
            'category_type' => 'movie',
            'direct_source' => 1,
        ];
    }
}

if (!function_exists('classifySeriesImportEntry')) {
    /**
     * @param array{url?: mixed, episode?: mixed, tvg_name?: mixed, group_title?: mixed}|array<string, mixed> $entry
     */
    function classifySeriesImportEntry(array $entry): ?array
    {
        $url = isset($entry['url']) ? trim((string) $entry['url']) : '';
        if ($url === '') {
            return null;
        }

        $title = null;
        if (isset($entry['episode'])) {
            $title = (string) $entry['episode'];
        } elseif (isset($entry['tvg_name'])) {
            $title = (string) $entry['tvg_name'];
        }

        $groupTitle = isset($entry['group_title']) ? (string) $entry['group_title'] : null;

        $classification = getStreamTypeByUrl($url, $title, $groupTitle);
        if (($classification['category_type'] ?? '') !== 'series') {
            return null;
        }

        return [
            'type' => 5,
            'category_type' => 'series',
            'direct_source' => 1,
        ];
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

        $normalizer = 'importador_normalize_playlist_text';

        try {
            while (($line = fgets($handle)) !== false) {
                if (!is_string($line)) {
                    continue;
                }

                $line = $normalizer($line);
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                if (stripos($line, '#EXTINF:') === 0) {
                    preg_match('/tvg-logo="(.*?)"/', $line, $logoMatch);
                    $logo = $logoMatch[1] ?? '';
                    $currentInfo['tvg_logo'] = $normalizer($logo);

                    $groupTitle = 'Canais';
                    if (preg_match('/group-title="(.*?)"/', $line, $groupMatch)) {
                        $groupTitle = trim($groupMatch[1]);
                    }

                    $groupTitle = $normalizer($groupTitle);

                    $title = '';
                    $pos = strpos($line, '",');
                    if ($pos !== false) {
                        $title = trim(substr($line, $pos + 2));
                    }
                    if ($title === '') {
                        $parts = explode(',', $line, 2);
                        $title = trim($parts[1] ?? '');
                    }

                    $title = $normalizer($title);

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
                    'group_title' => $normalizer($currentInfo['group_title'] ?? 'Canais'),
                    'tvg_name' => $normalizer($currentInfo['tvg_name'] ?? 'Sem Nome'),
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
